<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use ClyoNutri\Domain\Telehealth\Providers\DailyVideoProvider;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Serviço de Domínio para Gestão do Consultório Online / Teleconsulta.
 * Implementa a jornada clínica de teleconsulta em conformidade com as resoluções
 * do CFN (Conselho Federal de Nutricionistas), LGPD e normas de segurança da informação.
 */
class TeleconsultationService
{
    private PDO $db;
    private VideoConferenceProviderInterface $videoProvider;
    private ClinicalRecordService $clinicalRecordService;

    public function __construct(
        ?PDO $db = null,
        ?VideoConferenceProviderInterface $videoProvider = null,
        ?ClinicalRecordService $clinicalRecordService = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->videoProvider = $videoProvider ?? new DailyVideoProvider();
        $this->clinicalRecordService = $clinicalRecordService ?? new ClinicalRecordService($this->db);
    }

    /**
     * Agenda e provisiona uma sessão de teleconsulta para um agendamento existente.
     * Vínculo estrito com paciente, profissional e tenant.
     */
    public function scheduleSession(int $appointmentId, ?int $clinicId = null): array
    {
        $tenantClinicId = $clinicId ?? TenantContext::getClinicId();
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $tenantClinicId, $this->db);

        // 1. Obter detalhes da consulta
        $stmtApp = $this->db->prepare("
            SELECT a.id, a.clinic_id, a.patient_id, a.professional_id, a.scheduled_at, a.status,
                   p.name AS patient_name, u.name AS professional_name, pr.crn AS council_number
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id
            INNER JOIN professionals pr ON pr.id = a.professional_id
            INNER JOIN users u ON u.id = pr.user_id
            WHERE a.id = :app_id AND a.clinic_id = :clinic_id
        ");
        $stmtApp->execute(['app_id' => $appointmentId, 'clinic_id' => $tenantClinicId]);
        $appointment = $stmtApp->fetch();

        if (!$appointment) {
            throw new InvalidArgumentException("Agendamento {$appointmentId} não encontrado no tenant {$tenantClinicId}.");
        }

        // 2. Verificar se já existe sessão ativa para este agendamento (impede duplicação)
        $stmtExisting = $this->db->prepare("
            SELECT id, status, patient_token_hash FROM teleconsultation_sessions 
            WHERE appointment_id = :app_id AND clinic_id = :clinic_id
        ");
        $stmtExisting->execute(['app_id' => $appointmentId, 'clinic_id' => $tenantClinicId]);
        $existing = $stmtExisting->fetch();

        if ($existing) {
            throw new RuntimeException("Já existe uma sessão de teleconsulta criada para o agendamento #{$appointmentId}.");
        }

        // 3. Gerar tokens seguros de acesso
        $rawPatientToken = bin2hex(random_bytes(32));
        $patientTokenHash = hash('sha256', $rawPatientToken);

        $rawProfessionalToken = bin2hex(random_bytes(32));
        $professionalTokenHash = hash('sha256', $rawProfessionalToken);

        $roomName = "clyo-tele-{$tenantClinicId}-{$appointmentId}-" . bin2hex(random_bytes(4));
        $externalRoomId = null;
        $externalRoomUrl = null;
        $mediaProviderStatus = 'unconfigured';

        // 4. Tentar provisionar sala no provedor de vídeo real
        try {
            $roomData = $this->videoProvider->createRoom($roomName, 7200);
            $externalRoomId = $roomData['room_id'];
            $externalRoomUrl = $roomData['room_url'];
            $mediaProviderStatus = 'active';
        } catch (ExternalMediaProviderBlockedException $e) {
            // Documentar formalmente o estado BLOCKED do provedor sem usar URLs falsas
            $mediaProviderStatus = 'BLOCKED: ' . $e->getMessage();
        }

        // 5. Persistir sessão no banco de dados de produção
        $insertStmt = $this->db->prepare("
            INSERT INTO teleconsultation_sessions (
                clinic_id, appointment_id, patient_id, professional_id, provider,
                external_room_id, external_room_url, patient_token_hash, professional_token_hash,
                status, created_at, updated_at
            ) VALUES (
                :clinic_id, :appointment_id, :patient_id, :professional_id, :provider,
                :external_room_id, :external_room_url, :patient_token_hash, :professional_token_hash,
                'scheduled', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $insertStmt->execute([
            'clinic_id' => $tenantClinicId,
            'appointment_id' => $appointmentId,
            'patient_id' => (int)$appointment['patient_id'],
            'professional_id' => (int)$appointment['professional_id'],
            'provider' => $this->videoProvider->getProviderName(),
            'external_room_id' => $externalRoomId,
            'external_room_url' => $externalRoomUrl,
            'patient_token_hash' => $patientTokenHash,
            'professional_token_hash' => $professionalTokenHash,
        ]);

        $sessionId = (int)$this->db->lastInsertId();

        // 6. Registrar trilha de auditoria
        $this->recordEvent($sessionId, $tenantClinicId, 'session_created', 'professional', (int)$appointment['professional_id'], [
            'appointment_id' => $appointmentId,
            'media_provider_status' => $mediaProviderStatus,
        ]);

        return [
            'session_id' => $sessionId,
            'appointment_id' => $appointmentId,
            'patient_id' => (int)$appointment['patient_id'],
            'professional_id' => (int)$appointment['professional_id'],
            'status' => 'scheduled',
            'raw_patient_token' => $rawPatientToken,
            'patient_invite_url' => "/teleconsulta/sala-espera/{$rawPatientToken}",
            'media_provider_status' => $mediaProviderStatus,
            'external_room_url' => $externalRoomUrl,
        ];
    }

    /**
     * Valida o token e retorna a sessão para o paciente.
     */
    public function getSessionByPatientToken(string $rawToken): array
    {
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->db->prepare("
            SELECT s.*, p.name AS patient_name, u.name AS professional_name, pr.crn AS council_number,
                   a.scheduled_at, c.name AS clinic_name
            FROM teleconsultation_sessions s
            INNER JOIN patients p ON p.id = s.patient_id
            INNER JOIN professionals pr ON pr.id = s.professional_id
            INNER JOIN users u ON u.id = pr.user_id
            INNER JOIN appointments a ON a.id = s.appointment_id
            INNER JOIN clinics c ON c.id = s.clinic_id
            WHERE s.patient_token_hash = :hash
        ");
        $stmt->execute(['hash' => $tokenHash]);
        $session = $stmt->fetch();

        if (!$session) {
            throw new TenantSecurityException("Convite de teleconsulta inválido, inexistente ou expirado.");
        }

        if (in_array($session['status'], ['completed', 'cancelled', 'rejected'], true)) {
            throw new TenantSecurityException("Esta sessão de teleconsulta foi encerrada ou cancelada (Status: {$session['status']}).");
        }

        return $session;
    }

    /**
     * Retorna a sessão pelo ID garantindo isolamento estrito de tenant.
     */
    public function getSessionById(int $sessionId, ?int $clinicId = null): array
    {
        $tenantClinicId = $clinicId ?? TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT s.*, p.name AS patient_name, p.email AS patient_email, 
                   u.name AS professional_name, pr.crn AS council_number,
                   a.scheduled_at, a.notes AS appointment_notes
            FROM teleconsultation_sessions s
            INNER JOIN patients p ON p.id = s.patient_id
            INNER JOIN professionals pr ON pr.id = s.professional_id
            INNER JOIN users u ON u.id = pr.user_id
            INNER JOIN appointments a ON a.id = s.appointment_id
            WHERE s.id = :id AND s.clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $tenantClinicId]);
        $session = $stmt->fetch();

        if (!$session) {
            throw new TenantSecurityException("Sessão de teleconsulta #{$sessionId} não encontrada no tenant.");
        }

        return $session;
    }

    /**
     * Registra a realização do teste pré-consulta de dispositivos de mídia (câmera/microfone).
     */
    public function submitDeviceCheck(string $rawToken, array $metrics): array
    {
        $session = $this->getSessionByPatientToken($rawToken);

        $cameraOk = !empty($metrics['camera_ok']);
        $micOk = !empty($metrics['microphone_ok']);

        if (!$cameraOk || !$micOk) {
            throw new InvalidArgumentException("O teste de dispositivo requer confirmação operacional de câmera e microfone.");
        }

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET device_check_passed = 1,
                status = CASE WHEN status = 'scheduled' THEN 'device_checked' ELSE status END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(['id' => $session['id']]);

        $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'device_checked', 'patient', (int)$session['patient_id'], [
            'camera_ok' => $cameraOk,
            'microphone_ok' => $micOk,
            'browser' => $metrics['browser'] ?? 'unknown',
        ]);

        return ['success' => true, 'device_check_passed' => 1];
    }

    /**
     * Registra o consentimento informado da teleconsulta (LGPD e Resoluções CFN).
     */
    public function acceptConsent(string $rawToken, string $termVersion, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $session = $this->getSessionByPatientToken($rawToken);

        if (empty(trim($termVersion))) {
            throw new InvalidArgumentException("Versão do termo de consentimento é obrigatória.");
        }

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET consent_term_version = :version,
                consent_accepted_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(['version' => $termVersion, 'id' => $session['id']]);

        // Registrar também na tabela geral de consentimentos LGPD se disponível
        try {
            $stmtLgpd = $this->db->prepare("
                INSERT INTO patient_consents (patient_id, consent_type, agreed, agreed_at, ip_address, user_agent)
                VALUES (:patient_id, 'telehealth', 1, CURRENT_TIMESTAMP, :ip, :ua)
            ");
            $stmtLgpd->execute([
                'patient_id' => $session['patient_id'],
                'ip' => $ipAddress,
                'ua' => $userAgent,
            ]);
        } catch (\Throwable $e) {
            // Continua mesmo se a tabela de consentimentos genérica tiver campos divergentes
        }

        $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'consent_signed', 'patient', (int)$session['patient_id'], [
            'term_version' => $termVersion,
            'ip' => $ipAddress,
        ]);

        return ['success' => true, 'term_version' => $termVersion];
    }

    /**
     * Paciente ingressa na sala de espera virtual (lobby).
     */
    public function enterWaitingRoom(string $rawToken): array
    {
        $session = $this->getSessionByPatientToken($rawToken);

        if ((int)$session['device_check_passed'] !== 1) {
            throw new RuntimeException("É obrigatório concluir o teste de áudio e vídeo antes de entrar na sala de espera.");
        }

        if (empty($session['consent_accepted_at'])) {
            throw new RuntimeException("É obrigatório aceitar o termo de consentimento antes de ingressar no consultório.");
        }

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET status = 'waiting_room',
                waiting_room_entered_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status IN ('scheduled', 'device_checked', 'waiting_room')
        ");
        $stmt->execute(['id' => $session['id']]);

        $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'waiting_room_entered', 'patient', (int)$session['patient_id'], [
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        return [
            'session_id' => $session['id'],
            'status' => 'waiting_room',
            'patient_name' => $session['patient_name'],
            'professional_name' => $session['professional_name'],
        ];
    }

    /**
     * Consulta o status da sala de espera pelo paciente.
     */
    public function checkWaitingRoomStatus(string $rawToken): array
    {
        $session = $this->getSessionByPatientToken($rawToken);

        return [
            'session_id' => (int)$session['id'],
            'status' => $session['status'],
            'is_admitted' => in_array($session['status'], ['admitted', 'in_progress'], true),
            'external_room_url' => $session['external_room_url'],
            'media_provider' => $session['provider'],
        ];
    }

    /**
     * Profissional admite o paciente da sala de espera para a consulta ativa.
     */
    public function admitPatient(int $sessionId, int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $session = $this->getSessionById($sessionId, $clinicId);

        if ((int)$session['professional_id'] !== $professionalId) {
            throw new TenantSecurityException("Apenas o nutricionista responsável agendado pode admitir o paciente nesta sessão.");
        }

        if ($session['status'] === 'completed' || $session['status'] === 'cancelled') {
            throw new RuntimeException("Não é possível admitir paciente em sessão com status {$session['status']}.");
        }

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET status = 'admitted',
                admitted_at = CURRENT_TIMESTAMP,
                started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $clinicId]);

        $this->recordEvent($sessionId, $clinicId, 'patient_admitted', 'professional', $professionalId, [
            'patient_id' => $session['patient_id'],
        ]);

        return [
            'session_id' => $sessionId,
            'status' => 'admitted',
            'admitted_at' => date('Y-m-d H:i:s'),
            'external_room_url' => $session['external_room_url'],
        ];
    }

    /**
     * Profissional rejeita ou recusa a admissão do paciente.
     */
    public function rejectPatient(int $sessionId, int $professionalId, string $reason): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $session = $this->getSessionById($sessionId, $clinicId);

        if ((int)$session['professional_id'] !== $professionalId) {
            throw new TenantSecurityException("Apenas o profissional responsável pode rejeitar a admissão.");
        }

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET status = 'rejected',
                ended_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $clinicId]);

        $this->recordEvent($sessionId, $clinicId, 'patient_rejected', 'professional', $professionalId, [
            'reason' => $reason,
        ]);

        return [
            'session_id' => $sessionId,
            'status' => 'rejected',
            'reason' => $reason,
        ];
    }

    /**
     * Registra evolução clínica no prontuário eletrônico durante a teleconsulta.
     * Encadeamento imutável e criptográfico com ClinicalRecordService.
     * Notas clínicas NUNCA são compartilhadas com o cliente de vídeo ou o paciente.
     */
    public function recordClinicalEvolution(int $sessionId, int $professionalId, string $title, array $evolutionData): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $session = $this->getSessionById($sessionId, $clinicId);

        if ((int)$session['professional_id'] !== $professionalId) {
            throw new TenantSecurityException("Apenas o profissional responsável pode lavrar registros no prontuário desta sessão.");
        }

        // Salvar via ClinicalRecordService oficial append-only
        $evolutionData['teleconsultation_session_id'] = $sessionId;
        $evolutionData['appointment_id'] = (int)$session['appointment_id'];
        $evolutionData['recorded_during_telehealth'] = true;

        $record = $this->clinicalRecordService->appendRecord(
            (int)$session['patient_id'],
            $professionalId,
            $title,
            $evolutionData
        );

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET clinical_record_id = :record_id,
                status = 'in_progress',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'record_id' => $record['id'],
            'id' => $sessionId,
            'clinic_id' => $clinicId,
        ]);

        $this->recordEvent($sessionId, $clinicId, 'clinical_note_appended', 'professional', $professionalId, [
            'clinical_record_id' => $record['id'],
            'record_hash' => $record['record_hash'],
        ]);

        return $record;
    }

    /**
     * Encerra a teleconsulta, atualiza prontuário e agenda, e invalida a sala.
     */
    public function endTeleconsultation(int $sessionId, int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $session = $this->getSessionById($sessionId, $clinicId);

        if ((int)$session['professional_id'] !== $professionalId) {
            throw new TenantSecurityException("Apenas o profissional responsável pode finalizar esta teleconsulta.");
        }

        $now = date('Y-m-d H:i:s');

        // Atualizar teleconsultation_sessions
        $stmt = $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET status = 'completed',
                ended_at = :ended_at,
                updated_at = :ended_at
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['ended_at' => $now, 'id' => $sessionId, 'clinic_id' => $clinicId]);

        // Atualizar o status da consulta em appointments
        $stmtApp = $this->db->prepare("
            UPDATE appointments
            SET status = 'completed',
                updated_at = :ended_at
            WHERE id = :app_id AND clinic_id = :clinic_id
        ");
        $stmtApp->execute(['ended_at' => $now, 'app_id' => $session['appointment_id'], 'clinic_id' => $clinicId]);

        // Fechar sala no provedor de vídeo se aplicável
        if (!empty($session['external_room_id'])) {
            $this->videoProvider->deleteRoom($session['external_room_id']);
        }

        $this->recordEvent($sessionId, $clinicId, 'call_ended', 'professional', $professionalId, [
            'ended_at' => $now,
        ]);

        return [
            'session_id' => $sessionId,
            'status' => 'completed',
            'ended_at' => $now,
        ];
    }

    /**
     * Lista eventos de auditoria da sessão para verificação e governança clínica.
     */
    public function getSessionEvents(int $sessionId, ?int $clinicId = null): array
    {
        $tenantClinicId = $clinicId ?? TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT * FROM teleconsultation_events
            WHERE session_id = :id AND clinic_id = :clinic_id
            ORDER BY id ASC
        ");
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $tenantClinicId]);
        return $stmt->fetchAll();
    }

    private function recordEvent(
        int $sessionId,
        int $clinicId,
        string $eventType,
        string $actorType,
        ?int $actorId,
        array $details = []
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO teleconsultation_events (
                    clinic_id, session_id, event_type, actor_type, actor_id, details_json, created_at
                ) VALUES (
                    :clinic_id, :session_id, :event_type, :actor_type, :actor_id, :details, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                'clinic_id' => $clinicId,
                'session_id' => $sessionId,
                'event_type' => $eventType,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Falha não bloqueante de log
        }
    }
}
