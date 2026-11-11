<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** ET06: the existing notification queue is the transactional outbox; no SMTP in a booking transaction. */
final class AppointmentConfirmationPublisher
{
    public function __construct(private PDO $db) {}

    public function publish(int $clinicId, int $appointmentId): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('A confirmação deve ser registrada na transação da consulta.');
        }
        $a = $this->snapshot($clinicId, $appointmentId);
        if (!$a || !in_array($a['status'], ['scheduled', 'confirmed'], true)) {
            throw new \InvalidArgumentException('Consulta indisponível para confirmação.');
        }
        $service = new NotificationService($this->db);
        if (!$service->available()) {
            throw new \RuntimeException('Fila de notificações indisponível. Execute as migrações antes de agendar.');
        }
        $date = new DateTimeImmutable($a['scheduled_at'], new DateTimeZone(self::timezone()));
        $key = self::key($a);
        $out = $service->publish($clinicId, 'appointment.' . ($a['status'] === 'confirmed' ? 'confirmed' : 'scheduled'),
            'patient', (int)$a['patient_id'], $key, '/paciente/app', [
                'date' => $date->format('d/m/Y'), 'time' => $date->format('H:i'),
                'timezone' => self::timezone(),
                'modality' => \ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType($a['type']) ? 'online' : 'presencial',
            ]);
        return $out + ['dedupe_key' => $key];
    }

    /** Re-read clinic, recipient, status, schedule, modality and transition before external delivery. */
    public function staleReason(array $delivery, ?int $now = null): ?string
    {
        if (!preg_match('/^et06\.(\d+)\.[a-f0-9]{64}$/D', (string)($delivery['dedupe_key'] ?? ''), $m)) {
            return 'appointment_snapshot_missing';
        }
        $a = $this->snapshot((int)$delivery['clinic_id'], (int)$m[1]);
        if (!$a || (int)$a['patient_id'] !== (int)$delivery['recipient_id'] || $delivery['recipient_type'] !== 'patient') {
            return 'appointment_unavailable';
        }
        if (!in_array($a['status'], ['scheduled', 'confirmed'], true) || self::key($a) !== $delivery['dedupe_key']) {
            return 'appointment_changed';
        }
        $date = new DateTimeImmutable($a['scheduled_at'], new DateTimeZone(self::timezone()));
        return $date->getTimestamp() <= ($now ?? time()) ? 'appointment_expired' : null;
    }

    private function snapshot(int $clinicId, int $appointmentId): ?array
    {
        $st = $this->db->prepare('SELECT a.*, (SELECT MAX(h.id) FROM appointment_status_history h WHERE h.appointment_id = a.id AND h.clinic_id = a.clinic_id AND (h.from_status IS NULL OR h.from_status <> h.to_status)) AS transition_id FROM appointments a WHERE a.id = ? AND a.clinic_id = ?');
        $st->execute([$appointmentId, $clinicId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function key(array $a): string
    {
        // No personal contact or clinical data is persisted in the deduplication key.
        $state = [];
        foreach (['clinic_id', 'patient_id', 'professional_id', 'scheduled_at', 'duration_minutes', 'type', 'status', 'transition_id'] as $field) {
            $state[] = (string)($a[$field] ?? '');
        }
        $state[] = self::timezone();
        return 'et06.' . $a['id'] . '.' . hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public static function timezone(): string
    {
        return (string)(getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? date_default_timezone_get() ?: 'America/Sao_Paulo'));
    }
}
