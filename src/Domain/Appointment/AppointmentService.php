<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class AppointmentService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function schedule(int $patientId, int $professionalId, string $scheduledAt, string $type = 'first_visit', int $duration = 60, ?string $notes = null): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, notes)
            VALUES (:clinic_id, :patient_id, :professional_id, :scheduled_at, :duration, 'scheduled', :type, :notes)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'scheduled_at' => $scheduledAt,
            'duration' => $duration,
            'type' => $type,
            'notes' => $notes,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'patient_id' => $patientId,
            'scheduled_at' => $scheduledAt,
            'status' => 'scheduled',
            'type' => $type,
        ];
    }

    public function updateStatus(int $appointmentId, string $newStatus): bool
    {
        $clinicId = TenantContext::getClinicId();
        $allowed = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException("Status de consulta inválido: {$newStatus}");
        }

        $stmt = $this->db->prepare("
            UPDATE appointments
            SET status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");

        return $stmt->execute([
            'status' => $newStatus,
            'id' => $appointmentId,
            'clinic_id' => $clinicId,
        ]);
    }

    public function listByDay(string $date): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT a.*, p.name AS patient_name, p.phone AS patient_phone
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
              AND date(a.scheduled_at) = date(:date)
            ORDER BY a.scheduled_at ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'date' => $date]);
        return $stmt->fetchAll();
    }
}
