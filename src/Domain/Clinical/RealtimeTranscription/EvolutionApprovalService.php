<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Appointment\ConsultationDraftService;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Serviço de Revisão Humana e Aprovação de Minuta de Evolução (NI2-820).
 * Garante que nenhuma minuta gerada por IA seja integrada ao prontuário
 * sem revisão, edição e aprovação explícita pelo nutricionista responsável.
 */
class EvolutionApprovalService
{
    private PDO $db;
    private ConsultationDraftService $draftService;
    private AuditLogger $auditLogger;

    public function __construct(
        ?PDO $db = null,
        ?ConsultationDraftService $draftService = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->draftService = $draftService ?? new ConsultationDraftService($this->db);
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Cria ou atualiza a minuta da sessão.
     */
    public function createOrUpdateDraft(int $sessionId, array $fields): array
    {
        $clinicId = (int)TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT appointment_id, patient_id, professional_id
            FROM consultation_transcription_sessions
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $clinicId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException("Sessão #{$sessionId} não encontrada para a clínica autenticada.");
        }

        $appointmentId = (int)$session['appointment_id'];
        $patientId = (int)$session['patient_id'];
        $professionalId = (int)$session['professional_id'];

        $nowUtc = gmdate('Y-m-d H:i:s');
        $fullText = trim((string)($fields['full_evolution_text'] ?? ''));
        if ($fullText === '') {
            throw new InvalidArgumentException("O texto completo da evolução não pode ser vazio.");
        }

        // Verifica se já existe rascunho de evolução para esta sessão
        $existingStmt = $this->db->prepare("
            SELECT id FROM consultation_evolution_drafts
            WHERE session_id = :session_id AND clinic_id = :clinic_id
        ");
        $existingStmt->execute(['session_id' => $sessionId, 'clinic_id' => $clinicId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $draftId = (int)$existing['id'];
            $update = $this->db->prepare("
                UPDATE consultation_evolution_drafts SET
                    chief_complaint = :complaint,
                    nutritional_goals = :goals,
                    dietary_routine = :routine,
                    reported_anthropometry = :anthro,
                    clinical_symptoms = :symptoms,
                    adherence_notes = :adherence,
                    agreed_conduct = :conduct,
                    next_appointment_notes = :return_notes,
                    full_evolution_text = :full_text,
                    approval_status = 'draft',
                    updated_at = :updated_at
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $update->execute([
                'complaint' => $fields['chief_complaint'] ?? null,
                'goals' => $fields['nutritional_goals'] ?? null,
                'routine' => $fields['dietary_routine'] ?? null,
                'anthro' => $fields['reported_anthropometry'] ?? null,
                'symptoms' => $fields['clinical_symptoms'] ?? null,
                'adherence' => $fields['adherence_notes'] ?? null,
                'conduct' => $fields['agreed_conduct'] ?? null,
                'return_notes' => $fields['next_appointment_notes'] ?? null,
                'full_text' => $fullText,
                'updated_at' => $nowUtc,
                'id' => $draftId,
                'clinic_id' => $clinicId,
            ]);
        } else {
            $insert = $this->db->prepare("
                INSERT INTO consultation_evolution_drafts (
                    session_id, clinic_id, appointment_id, patient_id, professional_id,
                    chief_complaint, nutritional_goals, dietary_routine, reported_anthropometry,
                    clinical_symptoms, adherence_notes, agreed_conduct, next_appointment_notes,
                    full_evolution_text, approval_status, created_at, updated_at
                ) VALUES (
                    :session_id, :clinic_id, :appointment_id, :patient_id, :professional_id,
                    :complaint, :goals, :routine, :anthro,
                    :symptoms, :adherence, :conduct, :return_notes,
                    :full_text, 'draft', :created_at, :updated_at
                )
            ");
            $insert->execute([
                'session_id' => $sessionId,
                'clinic_id' => $clinicId,
                'appointment_id' => $appointmentId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'complaint' => $fields['chief_complaint'] ?? null,
                'goals' => $fields['nutritional_goals'] ?? null,
                'routine' => $fields['dietary_routine'] ?? null,
                'anthro' => $fields['reported_anthropometry'] ?? null,
                'symptoms' => $fields['clinical_symptoms'] ?? null,
                'adherence' => $fields['adherence_notes'] ?? null,
                'conduct' => $fields['agreed_conduct'] ?? null,
                'return_notes' => $fields['next_appointment_notes'] ?? null,
                'full_text' => $fullText,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);
            $draftId = (int)$this->db->lastInsertId();
        }

        return $this->getDraftById($draftId);
    }

    /**
     * Retorna o rascunho de evolução da consulta.
     */
    public function getDraftByAppointment(int $appointmentId): ?array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM consultation_evolution_drafts
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

    public function getDraftById(int $draftId): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM consultation_evolution_drafts
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $draftId, 'clinic_id' => $clinicId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            throw new DomainException("Minuta de evolução #{$draftId} não encontrada para a clínica autenticada.");
        }
        return $draft;
    }

    /**
     * Aprovação humana explícita: integra o texto revisado ao prontuário da consulta.
     * Persiste em consultation_note_drafts.evolution_summary e marca aprovação formal.
     */
    public function approveAndIntegrate(int $draftId, int $userId, ?string $editedFullText = null): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($userId, $clinicId, $this->db);

        $draft = $this->getDraftById($draftId);
        $appointmentId = (int)$draft['appointment_id'];
        $patientId = (int)$draft['patient_id'];

        $finalText = $editedFullText !== null && trim($editedFullText) !== ''
            ? trim($editedFullText)
            : (string)$draft['full_evolution_text'];

        if (trim($finalText) === '') {
            throw new InvalidArgumentException("A evolução clínica revisada não pode ser vazia.");
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        // 1. Atualiza a minuta como aprovada pelo profissional
        $stmt = $this->db->prepare("
            UPDATE consultation_evolution_drafts SET
                full_evolution_text = :text,
                approval_status = 'approved',
                approved_by = :approved_by,
                approved_at_utc = :approved_at,
                updated_at = :updated_at
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'text' => $finalText,
            'approved_by' => $userId,
            'approved_at' => $nowUtc,
            'updated_at' => $nowUtc,
            'id' => $draftId,
            'clinic_id' => $clinicId,
        ]);

        // 2. Integra diretamente ao rascunho oficial da consulta no prontuário
        $curDraft = $this->draftService->get($appointmentId, $userId, $clinicId);
        $curVer = (int)($curDraft['version'] ?? 0);
        $this->draftService->save($appointmentId, $userId, $clinicId, $curVer, [
            'evolution_summary' => $finalText,
            'weight_kg' => (string)($curDraft['weight_kg'] ?? ''),
            'waist_cm' => (string)($curDraft['waist_cm'] ?? ''),
        ]);

        // 3. Auditoria do ato de aprovação clínica
        $this->auditLogger->log('AI_EVOLUTION_APPROVED_AND_INTEGRATED', 'appointment', (string)$appointmentId, [
            'draft_id' => $draftId,
            'approved_by' => $userId,
            'patient_id' => $patientId,
            'character_count' => mb_strlen($finalText, 'UTF-8'),
        ]);

        return [
            'status' => 'approved',
            'draft_id' => $draftId,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'approved_by' => $userId,
            'approved_at_utc' => $nowUtc,
            'integrated_text' => $finalText,
        ];
    }
}
