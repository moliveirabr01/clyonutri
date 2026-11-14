<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;

/**
 * Gerenciamento de consentimento para gravação e transcrição inteligente de consultas (LGPD / NI2-820).
 * Garante que nenhum áudio ou streaming seja iniciado sem registro afirmativo prévio.
 */
class TranscriptionConsentService
{
    private PDO $db;
    private AuditLogger $auditLogger;

    public function __construct(?PDO $db = null, ?AuditLogger $auditLogger = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Consulta o consentimento ativo para o atendimento.
     */
    public function getConsent(int $appointmentId, int $patientId): ?array
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT * FROM consultation_ai_consents
            WHERE clinic_id = :clinic_id
              AND appointment_id = :appointment_id
              AND patient_id = :patient_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
        ]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Verifica se o consentimento está ativo (concedido e não revogado).
     */
    public function isConsentActive(int $appointmentId, int $patientId): bool
    {
        $consent = $this->getConsent($appointmentId, $patientId);
        return $consent !== null && ($consent['status'] ?? '') === 'granted';
    }

    /**
     * Registra o consentimento afirmativo do paciente para a consulta.
     */
    public function grantConsent(int $appointmentId, int $patientId, int $userId, string $version = 'v1.0'): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($userId, $clinicId, $this->db);

        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO consultation_ai_consents 
                (clinic_id, appointment_id, patient_id, user_id, status, terms_version, consented_at_utc, created_at)
            VALUES 
                (:clinic_id, :appointment_id, :patient_id, :user_id, 'granted', :terms_version, :consented_at, :created_at)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'user_id' => $userId,
            'terms_version' => $version,
            'consented_at' => $nowUtc,
            'created_at' => $nowUtc,
        ]);

        $consentId = (int)$this->db->lastInsertId();

        $this->auditLogger->log('AI_TRANSCRIPTION_CONSENT_GRANTED', 'appointment', (string)$appointmentId, [
            'patient_id' => $patientId,
            'user_id' => $userId,
            'consent_id' => $consentId,
            'version' => $version,
        ]);

        return [
            'id' => $consentId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'status' => 'granted',
            'terms_version' => $version,
            'consented_at_utc' => $nowUtc,
        ];
    }

    /**
     * Revoga o consentimento. Sessões ativas devem ser interrompidas imediatamente.
     */
    public function revokeConsent(int $appointmentId, int $patientId, int $userId): bool
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($userId, $clinicId, $this->db);

        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            UPDATE consultation_ai_consents
            SET status = 'revoked',
                revoked_at_utc = :revoked_at
            WHERE clinic_id = :clinic_id
              AND appointment_id = :appointment_id
              AND patient_id = :patient_id
              AND status = 'granted'
        ");
        $stmt->execute([
            'revoked_at' => $nowUtc,
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
        ]);

        $revoked = $stmt->rowCount() > 0;

        if ($revoked) {
            $this->auditLogger->log('AI_TRANSCRIPTION_CONSENT_REVOKED', 'appointment', (string)$appointmentId, [
                'patient_id' => $patientId,
                'user_id' => $userId,
            ]);
        }

        return $revoked;
    }
}
