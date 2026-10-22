<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * NI2-95: com quanta antecedência sai o lembrete de consulta (1 a 72 horas).
 *
 * Vale, nesta ordem: o valor do profissional da consulta → o da clínica → NOTIFICATION_APPOINTMENT_REMINDER_HOURS
 * do .env → 24 h. A clínica fica em professional_id = 0. Apagar o valor (null) volta a herdar.
 */
final class ReminderSettingsService
{
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 72;
    public const DEFAULT_HOURS = 24;

    private PDO $db;
    /** @var array<string, array{hours: int, source: string}> */
    private array $cache = [];

    public function __construct(?PDO $db = null, private ?\Closure $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'notification_reminder_settings');
    }

    /** Padrão do servidor: .env limitado a 1–72; vazio ou inválido = 24. */
    public static function envDefault(): int
    {
        $raw = getenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['NOTIFICATION_APPOINTMENT_REMINDER_HOURS'] ?? '';
        }
        $raw = trim((string)$raw);
        if ($raw === '' || !ctype_digit($raw) || (int)$raw === 0) {
            return self::DEFAULT_HOURS;
        }
        return max(self::MIN_HOURS, min(self::MAX_HOURS, (int)$raw));
    }

    /** @return array{hours: int, source: string} source: professional|clinic|default */
    public function effective(int $clinicId, int $professionalId = 0): array
    {
        $key = $clinicId . ':' . $professionalId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $v = $this->values($clinicId, $professionalId);
        $res = $v['professional'] !== null ? ['hours' => $v['professional'], 'source' => 'professional']
            : ($v['clinic'] !== null ? ['hours' => $v['clinic'], 'source' => 'clinic'] : ['hours' => self::envDefault(), 'source' => 'default']);
        return $this->cache[$key] = $res;
    }

    /** @return array{clinic: ?int, professional: ?int, default: int} */
    public function values(int $clinicId, int $professionalId = 0): array
    {
        $out = ['clinic' => null, 'professional' => null, 'default' => self::envDefault()];
        if (!$this->available()) {
            return $out;
        }
        $st = $this->db->prepare('SELECT professional_id, lead_hours FROM notification_reminder_settings WHERE clinic_id = ? AND professional_id IN (0, ?)');
        $st->execute([$clinicId, $professionalId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $h = max(self::MIN_HOURS, min(self::MAX_HOURS, (int)$r['lead_hours']));
            if ((int)$r['professional_id'] === 0) {
                $out['clinic'] = $h;
            }
            if ($professionalId > 0 && (int)$r['professional_id'] === $professionalId) {
                $out['professional'] = $h;
            }
        }
        return $out;
    }

    /** Maior antecedência em uso (para a janela do cron). */
    public function maxLeadHours(): int
    {
        $max = self::envDefault();
        if ($this->available()) {
            $max = max($max, (int)$this->db->query('SELECT MAX(lead_hours) FROM notification_reminder_settings')->fetchColumn());
        }
        return max(self::MIN_HOURS, min(self::MAX_HOURS, $max));
    }

    /**
     * Grava (ou apaga, com null) a antecedência da clínica (professionalId = 0) ou de um profissional.
     * @return array{before: ?int, after: ?int}
     */
    public function save(int $clinicId, int $professionalId, mixed $hours, ?int $userId): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('O ajuste do lembrete ainda não está disponível. Tente mais tarde.');
        }
        if ($hours === '' || $hours === null) {
            $hours = null;
        } elseif (is_int($hours) || (is_string($hours) && ctype_digit(trim($hours)))) {
            $hours = (int)$hours;
            if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
                throw new \InvalidArgumentException('Escolha entre 1 e 72 horas antes da consulta.');
            }
        } else {
            throw new \InvalidArgumentException('Informe a antecedência em horas inteiras, de 1 a 72.');
        }
        $v = $this->values($clinicId, $professionalId);
        $before = $professionalId === 0 ? $v['clinic'] : $v['professional'];
        $now = date('Y-m-d H:i:s', $this->clock ? (int)($this->clock)() : time());
        if ($hours === null) {
            $this->db->prepare('DELETE FROM notification_reminder_settings WHERE clinic_id = ? AND professional_id = ?')->execute([$clinicId, $professionalId]);
        } else {
            $upd = $this->db->prepare('UPDATE notification_reminder_settings SET lead_hours = ?, updated_by = ?, updated_at = ? WHERE clinic_id = ? AND professional_id = ?');
            $upd->execute([$hours, $userId, $now, $clinicId, $professionalId]);
            if ($before === null) {
                try {
                    $this->db->prepare('INSERT INTO notification_reminder_settings (clinic_id, professional_id, lead_hours, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$clinicId, $professionalId, $hours, $userId, $now]);
                } catch (\PDOException $e) {
                    // Duas abas salvando juntas: o índice único segura; a outra gravação já criou a linha.
                    $upd->execute([$hours, $userId, $now, $clinicId, $professionalId]);
                }
            }
        }
        $this->cache = [];
        return ['before' => $before, 'after' => $hours];
    }
}
