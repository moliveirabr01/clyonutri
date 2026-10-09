<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Privacy;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class LgpdService
{
    private PDO $db;
    private AuditLogger $auditLogger;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->auditLogger = new AuditLogger($this->db);
    }

    /**
     * Exporta todos os dados do titular em JSON estruturado (Portabilidade de dados)
     */
    public function exportPatientData(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("SELECT * FROM patients WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $patient = $stmt->fetch();

        if (!$patient) {
            return null;
        }

        // Anamneses
        $aStmt = $this->db->prepare("SELECT * FROM anamneses WHERE patient_id = :id AND clinic_id = :clinic_id");
        $aStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $anamneses = $aStmt->fetchAll();

        // Antropometrias
        $antStmt = $this->db->prepare("SELECT * FROM anthropometric_assessments WHERE patient_id = :id AND clinic_id = :clinic_id");
        $antStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $assessments = $antStmt->fetchAll();

        // Consentimentos
        $cStmt = $this->db->prepare("SELECT * FROM patient_consents WHERE patient_id = :id AND clinic_id = :clinic_id");
        $cStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);
        $consents = $cStmt->fetchAll();

        // Registrar auditoria de exportação
        $this->auditLogger->log('LGPD_DATA_EXPORT', 'patients', (string)$patientId, ['patient_name' => $patient['name']]);

        return [
            'exported_at' => date('c'),
            'patient' => $patient,
            'consents' => $consents,
            'anamneses' => $anamneses,
            'assessments' => $assessments,
        ];
    }

    /**
     * Anonimiza os dados do paciente mediante solicitação de exclusão (Direito ao Esquecimento LGPD)
     * Preserva dados clínicos descaracterizados para fins legais e estatísticos se necessário
     */
    public function anonymizePatient(int $patientId): bool
    {
        $clinicId = TenantContext::getClinicId();

        $manageTx = !$this->db->inTransaction();
        if ($manageTx) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("
                UPDATE patients
                SET name = 'PACIENTE ANONIMIZADO',
                    email = NULL,
                    phone = NULL,
                    notes = 'Dados anonimizados conforme Art. 18 da LGPD.',
                    status = 'anonymized',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $stmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);

            // Revogar atomicamente tokens móveis e de acesso do paciente (PX-24 / NI2-317)
            $delTok = $this->db->prepare("
                DELETE FROM personal_access_tokens
                WHERE tokenable_type = 'patient' AND tokenable_id = :id
            ");
            $delTok->execute(['id' => $patientId]);

            // Registrar revogação de consentimentos
            $revStmt = $this->db->prepare("
                UPDATE patient_consents
                SET granted = 0
                WHERE patient_id = :id AND clinic_id = :clinic_id
            ");
            $revStmt->execute(['id' => $patientId, 'clinic_id' => $clinicId]);

            $this->auditLogger->log('LGPD_ANONYMIZATION', 'patients', (string)$patientId);

            if ($manageTx) {
                $this->db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
