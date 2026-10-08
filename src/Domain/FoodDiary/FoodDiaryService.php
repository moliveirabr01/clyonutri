<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\FoodDiary;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class FoodDiaryService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Registro de refeição pelo paciente
     */
    public function logMeal(int $patientId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO food_diary_entries (
                clinic_id, patient_id, meal_name, consumed_at, photo_path,
                description, hunger_level, satiety_level, symptoms
            ) VALUES (
                :clinic_id, :patient_id, :meal_name, :consumed_at, :photo_path,
                :description, :hunger_level, :satiety_level, :symptoms
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'meal_name' => trim($data['meal_name']),
            'consumed_at' => $data['consumed_at'] ?? date('Y-m-d H:i:s'),
            'photo_path' => $data['photo_path'] ?? null,
            'description' => $data['description'] ?? null,
            'hunger_level' => isset($data['hunger_level']) ? (int)$data['hunger_level'] : null,
            'satiety_level' => isset($data['satiety_level']) ? (int)$data['satiety_level'] : null,
            'symptoms' => $data['symptoms'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'meal_name' => $data['meal_name'],
            'patient_id' => $patientId,
            'consumed_at' => $data['consumed_at'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Feedback clínico do nutricionista na refeição
     */
    public function addProfessionalFeedback(int $entryId, string $feedback): bool
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            UPDATE food_diary_entries
            SET feedback_from_professional = :feedback, reviewed_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");

        return $stmt->execute([
            'feedback' => trim($feedback),
            'id' => $entryId,
            'clinic_id' => $clinicId,
        ]);
    }

    /**
     * Linha do tempo de refeições do paciente
     */
    public function getPatientTimeline(int $patientId, ?string $fromDate = null): array
    {
        $clinicId = TenantContext::getClinicId();

        $sql = "
            SELECT * FROM food_diary_entries
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ";
        $params = ['patient_id' => $patientId, 'clinic_id' => $clinicId];

        if ($fromDate) {
            $sql .= " AND consumed_at >= :from_date";
            $params['from_date'] = $fromDate;
        }

        $sql .= " ORDER BY consumed_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
