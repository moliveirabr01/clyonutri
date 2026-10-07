<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Patient;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class PatientRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function create(array $data): array
    {
        $clinicId = TenantContext::getClinicId();
        $uuid = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare("
            INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, gender, status, notes)
            VALUES (:clinic_id, :uuid, :name, :email, :phone, :birth_date, :gender, :status, :notes)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'uuid' => $uuid,
            'name' => trim($data['name']),
            'email' => isset($data['email']) ? trim($data['email']) : null,
            'phone' => $data['phone'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();

        // Registrar consentimento LGPD inicial se fornecido
        if (!empty($data['lgpd_consent'])) {
            $cStmt = $this->db->prepare("
                INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted)
                VALUES (:clinic_id, :patient_id, 'dados_clinicos_nutricao', 1)
            ");
            $cStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $id]);
        }

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM patients WHERE id = :id AND clinic_id = :clinic_id LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'clinic_id' => $clinicId]);
        $patient = $stmt->fetch();
        return $patient ?: null;
    }

    public function listAll(): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM patients WHERE clinic_id = :clinic_id ORDER BY name ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll();
    }

    public function getProfile360(int $id): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $patient = $this->findById($id);
        if (!$patient) {
            return null;
        }

        // Anamneses
        $aStmt = $this->db->prepare("
            SELECT * FROM anamneses WHERE patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY version DESC
        ");
        $aStmt->execute(['patient_id' => $id, 'clinic_id' => $clinicId]);
        $anamneses = $aStmt->fetchAll();

        // Antropometrias
        $antStmt = $this->db->prepare("
            SELECT * FROM anthropometric_assessments WHERE patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY assessed_at DESC
        ");
        $antStmt->execute(['patient_id' => $id, 'clinic_id' => $clinicId]);
        $assessments = $antStmt->fetchAll();

        // Planos Alimentares
        $pStmt = $this->db->prepare("
            SELECT * FROM meal_plans WHERE patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY id DESC
        ");
        $pStmt->execute(['patient_id' => $id, 'clinic_id' => $clinicId]);
        $mealPlans = $pStmt->fetchAll();

        return [
            'patient' => $patient,
            'anamneses' => $anamneses,
            'assessments' => $assessments,
            'meal_plans' => $mealPlans,
        ];
    }
}
