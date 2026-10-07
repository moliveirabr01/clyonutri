<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Engagement;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class EngagementService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function logHydration(int $patientId, int $amountMl): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO hydration_logs (clinic_id, patient_id, amount_ml, logged_at)
            VALUES (:clinic_id, :patient_id, :amount_ml, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'amount_ml' => $amountMl,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'amount_ml' => $amountMl,
        ];
    }

    public function recordCheckin(int $patientId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO checkins (clinic_id, patient_id, energy_level, hunger_level, meal_adherence_pct, notes, checkin_date)
            VALUES (:clinic_id, :patient_id, :energy, :hunger, :adherence, :notes, :date)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'energy' => $data['energy_level'] ?? null,
            'hunger' => $data['hunger_level'] ?? null,
            'adherence' => $data['meal_adherence_pct'] ?? null,
            'notes' => $data['notes'] ?? null,
            'date' => $data['checkin_date'] ?? date('Y-m-d'),
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'patient_id' => $patientId,
            'meal_adherence_pct' => $data['meal_adherence_pct'] ?? null,
        ];
    }

    public function getPatientAdherenceSummary(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();

        // Total de água registrada hoje
        $hStmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND date(logged_at) = date('now')
        ");
        $hStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $waterToday = (int)$hStmt->fetchColumn();

        // Média de adesão dos últimos check-ins
        $cStmt = $this->db->prepare("
            SELECT AVG(meal_adherence_pct) FROM checkins
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ");
        $cStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $avgAdherence = round((float)$cStmt->fetchColumn(), 1);

        return [
            'patient_id' => $patientId,
            'water_intake_today_ml' => $waterToday,
            'average_adherence_pct' => $avgAdherence,
        ];
    }
}
