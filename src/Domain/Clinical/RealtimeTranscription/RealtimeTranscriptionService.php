<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Serviço de Gerenciamento da Transcrição em Tempo Real (NI2-820).
 * Controla ciclo de vida da sessão (start, pause, resume, mode switch, complete),
 * ingestão sequencial com idempotência e recuperação após refresh.
 */
class RealtimeTranscriptionService
{
    private PDO $db;
    private TranscriptionConsentService $consentService;
    private AuditLogger $auditLogger;

    public function __construct(
        ?PDO $db = null,
        ?TranscriptionConsentService $consentService = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->consentService = $consentService ?? new TranscriptionConsentService($this->db);
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Retorna a sessão ativa (ou mais recente) da consulta.
     */
    public function getActiveSession(int $appointmentId): ?array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM consultation_transcription_sessions
            WHERE clinic_id = :clinic_id AND appointment_id = :appointment_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
        ]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Inicia uma sessão de transcrição em tempo real.
     * Exige consentimento ativo válido registrado antes da captação.
     */
    public function startSession(int $appointmentId, int $patientId, int $professionalId, string $mode = 'live'): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        if (!in_array($mode, ['live', 'discrete'], true)) {
            $mode = 'live';
        }

        // Validação LGPD obrigatória: não inicia sem consentimento afirmativo
        if (!$this->consentService->isConsentActive($appointmentId, $patientId)) {
            throw new DomainException("Consentimento prévio não concedido ou revogado. É vedada a captação sem autorização registrada.");
        }

        // Verifica se já existe sessão em andamento para esta consulta
        $existing = $this->getActiveSession($appointmentId);
        if ($existing && in_array($existing['status'], ['connected', 'capturing', 'paused'], true)) {
            // Retorna a sessão existente para permitir continuidade / reconexão
            return $existing;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $sessionUuid = 'ts_' . bin2hex(random_bytes(16));

        $stmt = $this->db->prepare("
            INSERT INTO consultation_transcription_sessions
                (session_uuid, clinic_id, appointment_id, patient_id, professional_id, mode, status, started_at_utc, created_at, updated_at)
            VALUES
                (:uuid, :clinic_id, :appointment_id, :patient_id, :professional_id, :mode, 'capturing', :started_at, :created_at, :updated_at)
        ");
        $stmt->execute([
            'uuid' => $sessionUuid,
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'mode' => $mode,
            'started_at' => $nowUtc,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $sessionId = (int)$this->db->lastInsertId();

        $this->auditLogger->log('AI_TRANSCRIPTION_SESSION_STARTED', 'appointment', (string)$appointmentId, [
            'session_id' => $sessionId,
            'mode' => $mode,
            'patient_id' => $patientId,
        ]);

        return [
            'id' => $sessionId,
            'session_uuid' => $sessionUuid,
            'clinic_id' => $clinicId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'mode' => $mode,
            'status' => 'capturing',
            'started_at_utc' => $nowUtc,
        ];
    }

    /**
     * Alterna dinamicamente entre modo ao vivo ('live') e modo discreto ('discrete') sem reiniciar a transmissão.
     */
    public function switchMode(int $sessionId, string $newMode): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        if (!in_array($newMode, ['live', 'discrete'], true)) {
            throw new InvalidArgumentException("Modo de transcrição inválido: {$newMode}");
        }

        $session = $this->requireSession($sessionId, $clinicId);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            UPDATE consultation_transcription_sessions
            SET mode = :mode, updated_at = :updated_at
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'mode' => $newMode,
            'updated_at' => $nowUtc,
            'id' => $sessionId,
            'clinic_id' => $clinicId,
        ]);

        $session['mode'] = $newMode;
        $session['updated_at'] = $nowUtc;
        return $session;
    }

    /**
     * Pausa a captação. O áudio deixa de ser transmitido e gravado.
     */
    public function pauseSession(int $sessionId): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $session = $this->requireSession($sessionId, $clinicId);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            UPDATE consultation_transcription_sessions
            SET status = 'paused', paused_at_utc = :paused_at, updated_at = :updated_at
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'paused_at' => $nowUtc,
            'updated_at' => $nowUtc,
            'id' => $sessionId,
            'clinic_id' => $clinicId,
        ]);

        $this->auditLogger->log('AI_TRANSCRIPTION_SESSION_PAUSED', 'appointment', (string)$session['appointment_id'], [
            'session_id' => $sessionId,
        ]);

        $session['status'] = 'paused';
        $session['paused_at_utc'] = $nowUtc;
        return $session;
    }

    /**
     * Retoma a captação após pausa.
     */
    public function resumeSession(int $sessionId): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $session = $this->requireSession($sessionId, $clinicId);

        // Revalida consentimento ativo antes de retomar
        if (!$this->consentService->isConsentActive((int)$session['appointment_id'], (int)$session['patient_id'])) {
            throw new DomainException("Consentimento revogado. Não é possível retomar a transcrição.");
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            UPDATE consultation_transcription_sessions
            SET status = 'capturing', resumed_at_utc = :resumed_at, updated_at = :updated_at
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'resumed_at' => $nowUtc,
            'updated_at' => $nowUtc,
            'id' => $sessionId,
            'clinic_id' => $clinicId,
        ]);

        $this->auditLogger->log('AI_TRANSCRIPTION_SESSION_RESUMED', 'appointment', (string)$session['appointment_id'], [
            'session_id' => $sessionId,
        ]);

        $session['status'] = 'capturing';
        $session['resumed_at_utc'] = $nowUtc;
        return $session;
    }

    /**
     * Ingestão de segmento textual incremental de fala.
     * Suporta idempotência através de sequence_number único por sessão.
     */
    public function ingestSegment(
        int $sessionId,
        int $sequenceNumber,
        string $text,
        string $speaker = 'speaker_uncertain',
        bool $isFinal = true,
        ?float $confidence = null,
        ?float $start = null,
        ?float $end = null
    ): array {
        $clinicId = (int)TenantContext::getClinicId();
        $session = $this->requireSession($sessionId, $clinicId);

        if ($session['status'] === 'paused') {
            throw new DomainException("Sessão em pausa. Novos segmentos não podem ser gravados sem retomada explícita.");
        }
        if ($session['status'] === 'completed') {
            throw new DomainException("Sessão já finalizada.");
        }

        $cleanText = trim($text);
        if ($cleanText === '') {
            return ['status' => 'ignored', 'reason' => 'empty'];
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        // Idempotência: verifica se o segmento já foi inserido
        $checkStmt = $this->db->prepare("
            SELECT id FROM consultation_transcription_segments
            WHERE session_id = :session_id AND sequence_number = :seq
        ");
        $checkStmt->execute(['session_id' => $sessionId, 'seq' => $sequenceNumber]);
        if ($checkStmt->fetch()) {
            return ['status' => 'duplicate', 'sequence_number' => $sequenceNumber];
        }

        $stmt = $this->db->prepare("
            INSERT INTO consultation_transcription_segments
                (session_id, clinic_id, sequence_number, speaker, text, is_final, confidence, start_seconds, end_seconds, created_at)
            VALUES
                (:session_id, :clinic_id, :seq, :speaker, :text, :is_final, :conf, :start, :end, :created_at)
        ");
        $stmt->execute([
            'session_id' => $sessionId,
            'clinic_id' => $clinicId,
            'seq' => $sequenceNumber,
            'speaker' => $speaker,
            'text' => $cleanText,
            'is_final' => $isFinal ? 1 : 0,
            'conf' => $confidence,
            'start' => $start,
            'end' => $end,
            'created_at' => $nowUtc,
        ]);

        return [
            'status' => 'ingested',
            'id' => (int)$this->db->lastInsertId(),
            'sequence_number' => $sequenceNumber,
            'text' => $cleanText,
            'speaker' => $speaker,
        ];
    }

    /**
     * Encerra a sessão de transcrição em tempo real.
     */
    public function completeSession(int $sessionId): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $session = $this->requireSession($sessionId, $clinicId);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $startTs = strtotime($session['started_at_utc']);
        $completedTs = strtotime($nowUtc);
        $durationSeconds = max(0, $completedTs - $startTs);

        $stmt = $this->db->prepare("
            UPDATE consultation_transcription_sessions
            SET status = 'completed', completed_at_utc = :completed_at, duration_seconds = :duration, updated_at = :updated_at
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'completed_at' => $nowUtc,
            'duration' => $durationSeconds,
            'updated_at' => $nowUtc,
            'id' => $sessionId,
            'clinic_id' => $clinicId,
        ]);

        $this->auditLogger->log('AI_TRANSCRIPTION_SESSION_COMPLETED', 'appointment', (string)$session['appointment_id'], [
            'session_id' => $sessionId,
            'duration_seconds' => $durationSeconds,
        ]);

        $session['status'] = 'completed';
        $session['completed_at_utc'] = $nowUtc;
        $session['duration_seconds'] = $durationSeconds;
        return $session;
    }

    /**
     * Recupera os segmentos gravados (para sincronização em tempo real e refresh de página).
     */
    public function getSegments(int $sessionId, int $afterSequence = 0): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $this->requireSession($sessionId, $clinicId);

        $stmt = $this->db->prepare("
            SELECT sequence_number, speaker, text, is_final, confidence, created_at
            FROM consultation_transcription_segments
            WHERE session_id = :session_id AND sequence_number > :after_seq
            ORDER BY sequence_number ASC
        ");
        $stmt->execute([
            'session_id' => $sessionId,
            'after_seq' => $afterSequence,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna todo o texto acumulado em ordem cronológica dos segmentos.
     */
    public function getAllSegmentsText(int $sessionId): string
    {
        $segments = $this->getSegments($sessionId, 0);
        $lines = [];
        foreach ($segments as $s) {
            $text = trim((string)$s['text']);
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        return implode(' ', $lines);
    }

    private function requireSession(int $sessionId, int $clinicId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM consultation_transcription_sessions
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $clinicId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException("Sessão de transcrição #{$sessionId} não encontrada ou não pertence à clínica autenticada.");
        }
        return $session;
    }
}
