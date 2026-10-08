<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anamnesis;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class AnamnesisService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function saveAnamnesis(int $patientId, int $professionalId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        \ClyoNutri\Core\Security\TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        // Obter versão mais recente para incrementar
        $vStmt = $this->db->prepare("
            SELECT COALESCE(MAX(version), 0) + 1 FROM anamneses
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ");
        $vStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $nextVersion = (int)$vStmt->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO anamneses (
                clinic_id, patient_id, professional_id, version,
                habits, allergies, food_preferences, clinical_history,
                water_intake_ml, routine
            ) VALUES (
                :clinic_id, :patient_id, :professional_id, :version,
                :habits, :allergies, :food_preferences, :clinical_history,
                :water_intake_ml, :routine
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'version' => $nextVersion,
            'habits' => $data['habits'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'food_preferences' => $data['food_preferences'] ?? null,
            'clinical_history' => $data['clinical_history'] ?? null,
            'water_intake_ml' => isset($data['water_intake_ml']) ? (int)$data['water_intake_ml'] : null,
            'routine' => $data['routine'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'version' => $nextVersion,
            'patient_id' => $patientId,
        ];
    }
}
