<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class AnthropometryService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function recordAssessment(int $patientId, int $professionalId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();

        $weight = (float)$data['weight_kg'];
        $height = (float)$data['height_cm'];

        // Cálculo do IMC (peso / altura_em_metros^2)
        $heightM = $height / 100.0;
        $bmi = round($weight / ($heightM * $heightM), 2);

        // Cálculo do RCQ (Relação Cintura-Quadril) se ambos informados
        $whr = null;
        if (!empty($data['waist_cm']) && !empty($data['hip_cm']) && (float)$data['hip_cm'] > 0) {
            $whr = round((float)$data['waist_cm'] / (float)$data['hip_cm'], 2);
        }

        $stmt = $this->db->prepare("
            INSERT INTO anthropometric_assessments (
                clinic_id, patient_id, professional_id,
                weight_kg, height_cm, bmi, waist_cm, hip_cm, whr,
                body_fat_pct, muscle_mass_pct, notes, assessed_at
            ) VALUES (
                :clinic_id, :patient_id, :professional_id,
                :weight_kg, :height_cm, :bmi, :waist_cm, :hip_cm, :whr,
                :body_fat_pct, :muscle_mass_pct, :notes, :assessed_at
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => $bmi,
            'waist_cm' => $data['waist_cm'] ?? null,
            'hip_cm' => $data['hip_cm'] ?? null,
            'whr' => $whr,
            'body_fat_pct' => $data['body_fat_pct'] ?? null,
            'muscle_mass_pct' => $data['muscle_mass_pct'] ?? null,
            'notes' => $data['notes'] ?? null,
            'assessed_at' => $data['assessed_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'bmi' => $bmi,
            'whr' => $whr,
            'weight_kg' => $weight,
            'height_cm' => $height,
        ];
    }

    public function getEvolution(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT id, weight_kg, bmi, body_fat_pct, muscle_mass_pct, assessed_at
            FROM anthropometric_assessments
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
            ORDER BY assessed_at ASC
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        return $stmt->fetchAll();
    }
}
