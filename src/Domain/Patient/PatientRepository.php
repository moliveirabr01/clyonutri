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
        $choice = $data['consent_choice'] ?? 'not_collected';
        if (!in_array($choice, ['not_collected','granted','declined'], true)) throw new \InvalidArgumentException('Selecione uma situação válida de consentimento.');
        $clinicId = TenantContext::getClinicId();
        $uuid = bin2hex(random_bytes(16));

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
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

        // A legacy flag cannot establish explicit consent.
        if (($data['consent_choice'] ?? '') === 'granted') {
            (new \ClyoNutri\Domain\Privacy\ConsentManagementService($this->db))->recordConsent(
                $id, 'clinical_care', (int)($data['consent_term_id'] ?? 0), 'professional_attestation');
        } elseif (($data['consent_choice'] ?? '') === 'declined') {
            $term = $this->db->prepare("SELECT id FROM consent_terms WHERE id = ? AND purpose_code = 'clinical_care' AND is_active = 1");
            $term->execute([(int)($data['consent_term_id'] ?? 0)]);
            if (!$term->fetchColumn()) throw new \InvalidArgumentException('O termo foi alterado. Confira a versão vigente.');
            $this->db->prepare("INSERT INTO patient_consents(clinic_id, patient_id, consent_type, granted, term_id, source_channel) VALUES(?,?,'clinical_care',0,?,'professional_attestation')")
                ->execute([$clinicId, $id, (int)$data['consent_term_id']]);
            $this->db->prepare("INSERT INTO audit_logs(clinic_id,user_id,action,resource,resource_id,details) VALUES(?,?,'CONSENT_DECLINED','patient_consents',?,?)")
                ->execute([$clinicId, TenantContext::getUserId(), (string)$this->db->lastInsertId(), json_encode(['term_id'=>(int)$data['consent_term_id'], 'channel'=>'professional_attestation'])]);
        }

        $patient = $this->findById($id);
        if ($ownsTransaction) $this->db->commit();
        return $patient;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        if (!$this->findById($id)) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE patients SET name = :name, email = :email,
            phone = :phone, birth_date = :birth_date, gender = :gender, notes = :notes
            WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute([
            'id' => $id, 'clinic_id' => TenantContext::getClinicId(),
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
            'birth_date' => $data['birth_date'], 'gender' => $data['gender'], 'notes' => $data['notes'],
        ]);
        return true;
    }

    public function inactivate(int $id): bool
    {
        $clinicId = TenantContext::getClinicId();
        if (!$this->findById($id)) {
            return false;
        }
        $ownsTx = !$this->db->inTransaction();
        if ($ownsTx) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("UPDATE patients SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :clinic_id");
            $stmt->execute(['id' => $id, 'clinic_id' => $clinicId]);

            // Revogar atomicamente tokens móveis e de acesso do paciente (PX-24 / NI2-317)
            $delTok = $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type = 'patient' AND tokenable_id = :id");
            $delTok->execute(['id' => $id]);

            if ($ownsTx) {
                $this->db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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

    public function search(int $clinicId, string $query): array
    {
        $clean = trim($query);
        if ($clean === '') {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM patients 
            WHERE clinic_id = :clinic_id 
              AND (name LIKE :q OR email LIKE :q OR phone LIKE :q)
            ORDER BY name ASC
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'q' => '%' . $clean . '%',
        ]);
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

        // Prontuário / Registros Clínicos com nome do profissional
        $crStmt = $this->db->prepare("
            SELECT r.*, u.name as professional_name
            FROM clinical_records r
            JOIN professionals p ON p.id = r.professional_id
            JOIN users u ON u.id = p.user_id
            WHERE r.clinic_id = :clinic_id AND r.patient_id = :patient_id
            ORDER BY r.version DESC
        ");
        $crStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $id]);
        $clinicalRecords = array_map(function ($row) {
            $row['content'] = json_decode($row['content_json'] ?? '{}', true);
            return $row;
        }, $crStmt->fetchAll());

        return [
            'patient' => $patient,
            'anamneses' => $anamneses,
            'assessments' => $assessments,
            'meal_plans' => $mealPlans,
            'clinical_records' => $clinicalRecords,
        ];
    }
}
