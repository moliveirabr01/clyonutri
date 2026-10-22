<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * NI2-95: avisos do check-in diário (NI2-96), criados pelo cron (a cada minuto). Sem gancho na rota do check-in:
 * o cron olha a tabela checkins, então funciona com ou sem a tela do NI2-96 e não muda o envio do check-in.
 *
 * Paciente — lembrete (opt-in):
 * - Só para quem ligou o lembrete no app (notification_checkin_settings.enabled = 1; sem linha = desligado).
 * - Sai na hora escolhida (HH:MM, padrão 20:00) se ela ainda não fez o check-in do dia; depois de
 *   REMINDER_WINDOW_HOURS sem o cron rodar, o dia passa (não lembra às 23h de algo marcado para as 8h).
 * - Um por paciente por dia (dedupe checkin.reminder:{paciente}:{AAAAMMDD}); fez o check-in antes da entrega,
 *   a entrega é pulada (checkin_done). No horário de silêncio é pulado, não fica para o dia seguinte.
 * - Só existe onde há check-in diário no app (coluna checkins.updated_at, da migration 49 do NI2-96).
 *
 * Profissional — check-in recebido:
 * - Um aviso em /avisos por check-in (dedupe checkin.submitted:{id}); corrigir o check-in no mesmo dia não gera outro.
 * - Vai para quem tem vínculo ativo com a paciente (patient_professional_links); sem vínculo, para quem fez o
 *   plano alimentar publicado mais recente.
 * - E-mail: ligado por padrão (o profissional desliga em Configurações > Seus avisos) e no máximo um por dia;
 *   os outros check-ins do dia ficam só na caixa de avisos (daily_email_limit).
 * - Texto neutro: sem nome, notas, adesão, energia ou fome. O link abre o Perfil 360 da paciente.
 */
final class CheckinNotificationScheduler
{
    public const DEFAULT_TIME = '20:00';
    public const REMINDER_WINDOW_HOURS = 3;
    private const SCAN_LIMIT = 2000;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?NotificationService $notifications = null, private ?\Closure $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** O app tem check-in diário (NI2-96) e a migration 52 foi aplicada. */
    public static function reminderAvailable(PDO $db): bool
    {
        return SchemaInfo::hasTable($db, 'checkins') && SchemaInfo::hasColumn($db, 'checkins', 'updated_at')
            && SchemaInfo::hasTable($db, 'notification_checkin_settings');
    }

    /** @return array{available: bool, enabled: bool, time: string} */
    public static function settings(PDO $db, int $clinicId, int $patientId): array
    {
        $out = ['available' => self::reminderAvailable($db), 'enabled' => false, 'time' => self::DEFAULT_TIME];
        if (!SchemaInfo::hasTable($db, 'notification_checkin_settings')) {
            return $out;
        }
        $st = $db->prepare('SELECT enabled, remind_time FROM notification_checkin_settings WHERE clinic_id = ? AND patient_id = ?');
        $st->execute([$clinicId, $patientId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['enabled'] = (bool)$row['enabled'];
            $out['time'] = (string)$row['remind_time'];
        }
        return $out;
    }

    /**
     * Grava dentro da transação de NotificationService::savePreferences (já validado lá).
     * @param array{enabled: bool, time: string} $s
     */
    public static function saveSettings(PDO $db, int $clinicId, int $patientId, array $s, string $now): void
    {
        $upd = $db->prepare('UPDATE notification_checkin_settings SET enabled = ?, remind_time = ?, updated_at = ? WHERE clinic_id = ? AND patient_id = ?');
        $upd->execute([$s['enabled'] ? 1 : 0, $s['time'], $now, $clinicId, $patientId]);
        $exists = $db->prepare('SELECT 1 FROM notification_checkin_settings WHERE clinic_id = ? AND patient_id = ?');
        $exists->execute([$clinicId, $patientId]);
        if (!$exists->fetchColumn()) {
            $db->prepare('INSERT INTO notification_checkin_settings (clinic_id, patient_id, enabled, remind_time, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$clinicId, $patientId, $s['enabled'] ? 1 : 0, $s['time'], $now, $now]);
        }
    }

    /** A paciente já fez o check-in deste dia (AAAA-MM-DD)? */
    public static function checkinDone(PDO $db, int $clinicId, int $patientId, string $day): bool
    {
        if (!SchemaInfo::hasTable($db, 'checkins')) {
            return false;
        }
        $st = $db->prepare('SELECT 1 FROM checkins WHERE clinic_id = ? AND patient_id = ? AND checkin_date = ? LIMIT 1');
        $st->execute([$clinicId, $patientId, $day]);
        return (bool)$st->fetchColumn();
    }

    public function schedule(): array
    {
        $service = $this->notifications ?? new NotificationService($this->db, null, $this->clock);
        if (!$service->available() || !SchemaInfo::hasTable($this->db, 'checkins')) {
            return ['status' => 'NOT_MIGRATED'];
        }
        return ['reminders' => $this->reminders($service), 'submitted' => $this->submitted($service), 'status' => 'OK'];
    }

    /** @return array{created: int, duplicates: int, already_done: int} */
    private function reminders(NotificationService $service): array
    {
        $report = ['created' => 0, 'duplicates' => 0, 'already_done' => 0];
        if (!self::reminderAvailable($this->db)) {
            return $report;
        }
        $now = $this->ts();
        $today = date('Y-m-d', $now);
        $hhmm = date('H:i', $now);
        $st = $this->db->query("SELECT s.clinic_id, s.patient_id, s.remind_time FROM notification_checkin_settings s
            JOIN patients p ON p.id = s.patient_id AND p.clinic_id = s.clinic_id
            WHERE s.enabled = 1 AND p.status = 'active' ORDER BY s.id LIMIT " . self::SCAN_LIMIT);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $at = strtotime($today . ' ' . $r['remind_time'] . ':00');
            if ($at === false || $hhmm < (string)$r['remind_time'] || $now > $at + self::REMINDER_WINDOW_HOURS * 3600) {
                continue;
            }
            $clinicId = (int)$r['clinic_id'];
            $patientId = (int)$r['patient_id'];
            if (self::checkinDone($this->db, $clinicId, $patientId, $today)) {
                $report['already_done']++;
                continue;
            }
            $out = $service->publish($clinicId, 'checkin.reminder', 'patient', $patientId, 'checkin.reminder:' . $patientId . ':' . date('Ymd', $now), '/');
            $report[$out['duplicate'] ? 'duplicates' : 'created']++;
        }
        return $report;
    }

    /** @return array{created: int, duplicates: int, no_professional: int} */
    private function submitted(NotificationService $service): array
    {
        $report = ['created' => 0, 'duplicates' => 0, 'no_professional' => 0];
        $now = $this->ts();
        // checkin_date (fuso da aplicação) em vez de created_at: no SQLite o padrão do created_at é UTC.
        $st = $this->db->prepare("SELECT c.id, c.clinic_id, c.patient_id, c.checkin_date FROM checkins c
            JOIN patients p ON p.id = c.patient_id AND p.clinic_id = c.clinic_id
            WHERE p.status = 'active' AND c.checkin_date >= ? AND c.checkin_date <= ? ORDER BY c.id LIMIT " . self::SCAN_LIMIT);
        $st->execute([date('Y-m-d', $now - 86400), date('Y-m-d', $now)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $clinicId = (int)$c['clinic_id'];
            $patientId = (int)$c['patient_id'];
            $pros = $this->professionalsFor($clinicId, $patientId);
            if ($pros === []) {
                $report['no_professional']++;
                continue;
            }
            foreach ($pros as $proId) {
                $key = 'checkin.submitted:' . (int)$c['id'];
                $options = $this->emailedToday($service, $clinicId, $proId, $key) ? ['email_skip_reason' => 'daily_email_limit'] : [];
                $out = $service->publish($clinicId, 'checkin.submitted', 'professional', $proId, $key, '/pacientes/' . $patientId, [], $options);
                $report[$out['duplicate'] ? 'duplicates' : 'created']++;
            }
        }
        return $report;
    }

    /** @return list<int> */
    private function professionalsFor(int $clinicId, int $patientId): array
    {
        if (SchemaInfo::hasTable($this->db, 'patient_professional_links')) {
            $st = $this->db->prepare("SELECT DISTINCT professional_id FROM patient_professional_links WHERE clinic_id = ? AND patient_id = ? AND status = 'active' ORDER BY professional_id");
            $st->execute([$clinicId, $patientId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                return $ids;
            }
        }
        $st = $this->db->prepare("SELECT professional_id FROM meal_plans WHERE clinic_id = ? AND patient_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1");
        $st->execute([$clinicId, $patientId]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? [$id] : [];
    }

    /** Já saiu (ou está para sair) e-mail de check-in hoje para este profissional? Se sim, este fica só na caixa. */
    private function emailedToday(NotificationService $service, int $clinicId, int $proId, string $key): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM notification_events e JOIN notification_deliveries d ON d.event_id = e.id AND d.channel = 'email'
            WHERE e.clinic_id = ? AND e.recipient_type = 'professional' AND e.recipient_id = ? AND e.event_type = 'checkin.submitted'
              AND e.dedupe_key <> ? AND e.created_at >= ? AND d.status IN ('queued', 'sending', 'sent', 'logged') LIMIT 1");
        $st->execute([$clinicId, $proId, $key, date('Y-m-d 00:00:00', $this->ts())]);
        return (bool)$st->fetchColumn();
    }

    private function ts(): int
    {
        return $this->clock ? (int)($this->clock)() : time();
    }
}
