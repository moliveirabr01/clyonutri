<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Privacy;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;

class ConsentManagementService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Cadastra ou versiona um termo legal de consentimento ou política de privacidade.
     */
    public function createTerm(string $version, string $title, string $purposeCode, string $termText): array
    {
        if (empty(trim($version)) || empty(trim($title)) || empty(trim($purposeCode))) {
            throw new InvalidArgumentException("Versão, título e código de finalidade são obrigatórios.");
        }

        $stmt = $this->db->prepare("
            INSERT INTO consent_terms (version, title, purpose_code, term_text, is_active)
            VALUES (:version, :title, :purpose, :text, 1)
        ");
        $stmt->execute([
            'version' => trim($version),
            'title' => trim($title),
            'purpose' => trim($purposeCode),
            'text' => trim($termText),
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'version' => $version,
            'purpose_code' => $purposeCode,
            'title' => $title,
        ];
    }

    /**
     * Registra o aceite do consentimento de forma verificável e auditada.
     * Proibido fabricar consentimentos sem canal de origem e proveniência comprovada.
     */
    public function recordConsent(
        int $patientId,
        string $purposeCode,
        int $termId,
        string $sourceChannel = 'web',
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // Validar que o termo existe e está ativo
        $tStmt = $this->db->prepare("SELECT id, purpose_code, version FROM consent_terms WHERE id = :id AND is_active = 1");
        $tStmt->execute(['id' => $termId]);
        $term = $tStmt->fetch();

        if (!$term) {
            throw new InvalidArgumentException("Termo de consentimento ID {$termId} inexistente ou inativo.");
        }

        if ($term['purpose_code'] !== $purposeCode) {
            throw new InvalidArgumentException("Finalidade do termo incompatível com o código requisitado.");
        }

        // Validação anti-fabricação: canais de importação em massa não podem forjar aceites como válidos
        if ($sourceChannel === 'csv_import' || $sourceChannel === 'bulk_unverified') {
            throw new InvalidArgumentException("Violação de Governança LGPD: importações legadas ou em massa não podem fabricar consentimentos sem manifestação expressa do titular.");
        }

        $now = date('Y-m-d H:i:s');

        // Revogar qualquer aceite ativo anterior para a mesma finalidade antes de registrar nova versão
        $revPrev = $this->db->prepare("
            UPDATE patient_consents
            SET granted = 0, revoked_at = :now, revocation_reason = 'Superado por nova versão do termo'
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND consent_type = :purpose AND granted = 1
        ");
        $revPrev->execute([
            'now' => $now,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'purpose' => $purposeCode,
        ]);

        // Registrar novo consentimento
        $stmt = $this->db->prepare("
            INSERT INTO patient_consents (
                clinic_id, patient_id, consent_type, granted,
                ip_address, granted_at, term_id, source_channel, user_agent
            ) VALUES (
                :clinic_id, :patient_id, :purpose, 1,
                :ip, :granted_at, :term_id, :channel, :agent
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'purpose' => $purposeCode,
            'ip' => $ipAddress,
            'granted_at' => $now,
            'term_id' => $termId,
            'channel' => $sourceChannel,
            'agent' => $userAgent,
        ]);

        $id = (int)$this->db->lastInsertId();

        // Trilha de auditoria
        $aStmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
            VALUES (:clinic_id, 'CONSENT_GRANTED', 'patient_consents', :res_id, :details)
        ");
        $aStmt->execute([
            'clinic_id' => $clinicId,
            'res_id' => (string)$id,
            'details' => json_encode([
                'patient_id' => $patientId,
                'purpose_code' => $purposeCode,
                'term_version' => $term['version'],
                'channel' => $sourceChannel,
                'timestamp' => $now,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'id' => $id,
            'patient_id' => $patientId,
            'purpose_code' => $purposeCode,
            'term_version' => $term['version'],
            'granted' => true,
            'granted_at' => $now,
        ];
    }

    /**
     * Revoga formalmente o consentimento de uma finalidade com justificativa.
     */
    public function revokeConsent(int $patientId, string $purposeCode, string $reason): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $now = date('Y-m-d H:i:s');
        $trimmedReason = trim($reason);

        $stmt = $this->db->prepare("
            UPDATE patient_consents
            SET granted = 0, revoked_at = :now, revocation_reason = :reason
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND consent_type = :purpose AND granted = 1
        ");
        $stmt->execute([
            'now' => $now,
            'reason' => $trimmedReason,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'purpose' => $purposeCode,
        ]);

        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $aStmt = $this->db->prepare("
                INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
                VALUES (:clinic_id, 'CONSENT_REVOKED', 'patient_consents', :res_id, :details)
            ");
            $aStmt->execute([
                'clinic_id' => $clinicId,
                'res_id' => (string)$patientId,
                'details' => json_encode([
                    'patient_id' => $patientId,
                    'purpose_code' => $purposeCode,
                    'reason' => $trimmedReason,
                    'timestamp' => $now,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $affected > 0;
    }

    /**
     * Verifica se o paciente possui consentimento ativo para uma finalidade.
     */
    public function hasActiveConsent(int $patientId, string $purposeCode): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM patient_consents
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND consent_type = :purpose AND granted = 1
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'purpose' => $purposeCode,
        ]);

        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Retorna o histórico cronológico de consentimentos e revogações do paciente.
     */
    public function getConsentAuditTrail(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT c.*, t.version as term_version, t.title as term_title
            FROM patient_consents c
            LEFT JOIN consent_terms t ON t.id = c.term_id
            WHERE c.clinic_id = :clinic_id AND c.patient_id = :patient_id
            ORDER BY c.granted_at DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);

        return $stmt->fetchAll();
    }
}
