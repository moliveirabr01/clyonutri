<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use ClyoNutri\Core\Database\Connection;
use PDO;

/**
 * NI2-95: lembrete de consulta para a paciente, criado pelo cron ~24 h antes.
 *
 * - Só consultas agendadas ou confirmadas, de pacientes ativas, que começam entre 1 h e LEAD_HOURS a partir de agora.
 * - A chave inclui o horário: se a consulta for remarcada, sai um lembrete novo; o antigo não se repete.
 * - O texto leva só data e hora (sem profissional, local ou motivo). A entrega segue as preferências da paciente.
 */
final class AppointmentReminderScheduler
{
    public const DEFAULT_LEAD_HOURS = 24;
    private const MIN_LEAD_MINUTES = 60;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?NotificationService $notifications = null, private ?\Closure $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public static function leadHoursFromEnv(): int
    {
        $v = (int)(getenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS') ?: ($_ENV['NOTIFICATION_APPOINTMENT_REMINDER_HOURS'] ?? self::DEFAULT_LEAD_HOURS));
        return max(2, min(72, $v ?: self::DEFAULT_LEAD_HOURS));
    }

    /** @return array{created: int, duplicates: int} */
    public function schedule(?int $leadHours = null): array
    {
        $service = $this->notifications ?? new NotificationService($this->db, null, $this->clock);
        $report = ['created' => 0, 'duplicates' => 0];
        if (!$service->available()) {
            return $report + ['status' => 'NOT_MIGRATED'];
        }
        $now = $this->clock ? (int)($this->clock)() : time();
        $st = $this->db->prepare("SELECT a.id, a.clinic_id, a.patient_id, a.scheduled_at
            FROM appointments a JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
            WHERE a.status IN ('scheduled', 'confirmed') AND p.status = 'active'
              AND a.scheduled_at > ? AND a.scheduled_at <= ?
            ORDER BY a.scheduled_at LIMIT 500");
        $st->execute([date('Y-m-d H:i:s', $now + self::MIN_LEAD_MINUTES * 60), date('Y-m-d H:i:s', $now + ($leadHours ?? self::leadHoursFromEnv()) * 3600)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $at = strtotime((string)$a['scheduled_at']);
            $out = $service->publish((int)$a['clinic_id'], 'appointment.reminder', 'patient', (int)$a['patient_id'],
                'appointment.reminder:' . $a['id'] . ':' . date('YmdHi', $at), '/', ['date' => date('d/m', $at), 'time' => date('H:i', $at)]);
            $report[$out['duplicate'] ? 'duplicates' : 'created']++;
        }
        return $report + ['status' => 'OK'];
    }
}
