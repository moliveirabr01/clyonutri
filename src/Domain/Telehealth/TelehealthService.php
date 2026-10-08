<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class TelehealthService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function generateSession(int $appointmentId, string $provider = 'clyo_meet'): array
    {
        $clinicId = TenantContext::getClinicId();
        $roomCode = 'clyo-' . bin2hex(random_bytes(6));
        $meetingUrl = "https://meet.clyonutri.com.br/room/{$roomCode}";

        $stmt = $this->db->prepare("
            INSERT INTO telehealth_sessions (clinic_id, appointment_id, meeting_url, provider, status)
            VALUES (:clinic_id, :appointment_id, :meeting_url, :provider, 'ready')
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
            'meeting_url' => $meetingUrl,
            'provider' => $provider,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'appointment_id' => $appointmentId,
            'meeting_url' => $meetingUrl,
            'provider' => $provider,
        ];
    }

    public function getSessionByAppointment(int $appointmentId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM telehealth_sessions WHERE appointment_id = :app_id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['app_id' => $appointmentId, 'clinic_id' => $clinicId]);
        $session = $stmt->fetch();
        return $session ?: null;
    }
}
