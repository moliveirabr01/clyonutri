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
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $idempotencyKey = trim((string)($data['idempotency_key'] ?? '')) ?: null;
        if ($idempotencyKey !== null && strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('Chave de idempotência inválida.');
        }
        if ($idempotencyKey !== null) {
            $chkStmt = $this->db->prepare("
                SELECT id, meal_name, patient_id, consumed_at, description, photo_path
                FROM food_diary_entries
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND idempotency_key = :key
                LIMIT 1
            ");
            $chkStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'key' => $idempotencyKey,
            ]);
            $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'id' => (int)$existing['id'],
                    'meal_name' => $existing['meal_name'],
                    'patient_id' => (int)$existing['patient_id'],
                    'consumed_at' => $existing['consumed_at'],
                    'description' => $existing['description'],
                    'idempotent_replay' => true,
                ];
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO food_diary_entries (
                clinic_id, patient_id, meal_name, consumed_at, photo_path,
                description, hunger_level, satiety_level, symptoms, idempotency_key
            ) VALUES (
                :clinic_id, :patient_id, :meal_name, :consumed_at, :photo_path,
                :description, :hunger_level, :satiety_level, :symptoms, :idempotency_key
            )
        ");

        try {
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
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (\PDOException $e) {
            // The unique index arbitrates concurrent requests; replay only our exact key.
            if ($idempotencyKey !== null) {
                $chkStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId, 'key' => $idempotencyKey]);
                if ($chkStmt->fetch(PDO::FETCH_ASSOC)) {
                    return $this->logMeal($patientId, $data);
                }
            }
            throw $e;
        }

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'meal_name' => $data['meal_name'],
            'patient_id' => $patientId,
            'consumed_at' => $data['consumed_at'] ?? date('Y-m-d H:i:s'),
            'description' => $data['description'] ?? null,
            'idempotent_replay' => false,
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

        $stmt->execute([
            'feedback' => trim($feedback),
            'id' => $entryId,
            'clinic_id' => $clinicId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Linha do tempo de refeições do paciente
     */
    public function getPatientTimeline(int $patientId, ?string $fromDate = null): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
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

    /**
     * Linha do tempo de diários da clínica com dados do paciente para o nutricionista
     */
    public function getClinicDiaries(int $clinicId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT f.*, p.name AS patient_name, p.email AS patient_email
            FROM food_diary_entries f
            JOIN patients p ON p.id = f.patient_id
            WHERE f.clinic_id = :clinic_id
            ORDER BY f.consumed_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':clinic_id', $clinicId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Total de registros no diário pendentes de feedback do nutricionista
     */
    public function countPendingDiaries(int $clinicId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM food_diary_entries
            WHERE clinic_id = :clinic_id
              AND (feedback_from_professional IS NULL OR feedback_from_professional = '')
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $row = $stmt->fetch();

        return (int)($row['total'] ?? 0);
    }
}
