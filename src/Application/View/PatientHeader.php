<?php

declare(strict_types=1);

namespace ClyoNutri\Application\View;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;
use PDO;

/**
 * NI2-622 / NI2-661: cabeçalho do paciente com a ação principal e as abas clínicas, o mesmo no perfil e nas
 * páginas internas (prontuário, anamnese, evolução, exames). Busca sozinho o que faltar, sempre pela clínica atual.
 *
 * Abas: resumo | atendimento | plano | anamnese | evolucao | exames | documentos
 *
 * NI2-692 / CR-NI2-002 (v54.45): falha da agenda NÃO pode ocultar alergias/contexto clínico.
 * ClinicalContext é lido antes; careStepForPatient fica em try próprio com state=unavailable.
 */
final class PatientHeader
{
    /** @var null|callable(int):array Hook de teste (NI2-692) — força falha/retorno da agenda sem mock de produção. */
    public static $careStepResolver = null;

    public static function render(PDO $db, int $patientId, string $activeTab, ?array $p = null, ?array $careStep = null, ?array $clinicalSummary = null, ?array $activePlan = null, bool $activePlanKnown = false, string $mode = 'full'): string
    {
        $clinicId = (int)TenantContext::getClinicId();
        $bufferLevel=ob_get_level();
        try {
            if (true) { // Always re-read tenant-scoped identity, including supplied context.
                $st = $db->prepare('SELECT * FROM patients WHERE id = ? AND clinic_id = ?');
                $st->execute([$patientId, $clinicId]);
                $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($p === null) {
                    return ''; // paciente de outra clínica ou inexistente: nada é exibido
                }
            }
            // NI2-692: contexto clínico (alergias) ANTES da agenda — falha da agenda não pode apagar o selo.
            $context=ClinicalContext::read($db,$patientId);
            $clinicalSummary=$context['summary'];

            $selectedAttendance=AttendanceContext::selected($patientId);
            if ($selectedAttendance) $careStep=['state'=>$selectedAttendance['status']==='in_progress'?'continue':'selected','appointment'=>$selectedAttendance];
            if ($careStep === null) {
                try {
                    if (self::$careStepResolver !== null) {
                        $careStep = (self::$careStepResolver)($patientId);
                    } else {
                        $careStep = (new \ClyoNutri\Domain\Appointment\AppointmentService())->careStepForPatient($patientId);
                    }
                } catch (\Throwable $e) {
                    \ClyoNutri\Core\Observability\StructuredLogger::warning('CARE_STEP_UNAVAILABLE', ['patient_id' => (int)$patientId, 'exception' => $e]);
                    // Sem inventar horários livres: só sinaliza indisponibilidade temporária.
                    $careStep = ['state' => 'unavailable', 'appointment' => null];
                }
            }

            if ($activePlan === null && !$activePlanKnown) {
                $st = $db->prepare("SELECT id, status FROM meal_plans WHERE patient_id = ? AND clinic_id = ? ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id DESC LIMIT 1");
                $st->execute([$patientId, $clinicId]);
                $activePlan = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $pendingExamCount = 0;
            try {
                $pe = $db->prepare("SELECT COUNT(*) FROM exam_requests WHERE clinic_id = ? AND patient_id = ? AND status IN ('draft','review')");
                $pe->execute([$clinicId, $patientId]);
                $pendingExamCount = (int)$pe->fetchColumn();
            } catch (\Throwable $e) {
                $pendingExamCount = 0; // tabela de exames ausente (migração 64)
            }
            // NI2-623: etapas do atendimento em andamento; NI2-629: última avaliação para o contexto clínico
            $careFlow = null;
            if (($careStep['state'] ?? '') === 'continue' && !empty($careStep['appointment'])) {
                try {
                    $careFlow = (new \ClyoNutri\Domain\Appointment\CareWorkflow($db))->forAppointment($clinicId, $patientId, $careStep['appointment']);
                } catch (\Throwable $e) {
                    \ClyoNutri\Core\Observability\StructuredLogger::warning('CARE_PROGRESS_UNAVAILABLE', ['patient_id' => (int)$patientId, 'exception' => $e]);
                    // Failure in optional progress must not hide patient safety context.
                }
            }
            $csrfToken = Csrf::token();
            if ($mode === 'context') { // NI2-623/629: só etapas e contexto (ex.: a própria tela da consulta)
                $pid = $patientId;
                ob_start();
                echo '<div class="patient-context-only" data-testid="patient-context-only">';
                require __DIR__ . '/../../../views/patients/_allergy_badge.php';
                echo '<details class="ux52-context-tools"><summary>Contexto clínico e etapas do atendimento</summary>';
                if (!empty($careFlow)) {
                    require __DIR__ . '/../../../views/patients/_care_workflow.php';
                }
                echo ClinicalContext::renderData($db,$context);
                echo '</details>';

                echo '</div>';
                return (string)ob_get_clean();
            }
            ob_start();
            require __DIR__ . '/../../../views/patients/_allergy_badge.php';
            $profileBadgeHtml = (string)ob_get_clean();
            ob_start();
            require __DIR__ . '/../../../views/patients/_profile_header.php';
            echo '<div class="ux52-clinical-tools">';
            if (in_array($careStep['state'] ?? '', ['continue','selected'], true) && !empty($careStep['appointment'])) {
                echo '<a class="btn btn-secondary btn-sm" data-testid="attendance-hub" href="/consulta/'.(int)$careStep['appointment']['id'].'">Voltar ao atendimento #'.(int)$careStep['appointment']['id'].'</a>';
            }
            echo '<details class="ux52-context-tools"><summary>Contexto clínico e etapas</summary>';
            if (!empty($careFlow)) {
                require __DIR__ . '/../../../views/patients/_care_workflow.php';
                // The visible return link above is unique; secondary tools are inside the disclosure.
                ob_start(); require __DIR__ . '/../../../views/patients/_attendance_mode_bar.php';
                echo str_replace('data-testid="attendance-hub"', 'data-testid="attendance-hub-secondary"', (string)ob_get_clean());
            }
            echo ClinicalContext::renderData($db,$context);
            echo '</details></div>';

            return (string)ob_get_clean();
        } catch (\DomainException|\ClyoNutri\Core\Security\TenantSecurityException $e) {
            while(ob_get_level()>$bufferLevel)ob_end_clean();return '';
        } catch (\Throwable $e) {
            while(ob_get_level()>$bufferLevel)ob_end_clean();
            \ClyoNutri\Core\Observability\StructuredLogger::warning('PATIENT_CONTEXT_UNAVAILABLE', ['patient_id' => (int)$patientId, 'exception' => $e]);
            return '<p class="op-message op-message--warning" role="status">Contexto clínico indisponível. Confira o prontuário antes de concluir.</p>';
        }
    }
}
