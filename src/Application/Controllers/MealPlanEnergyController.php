<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use PDO;

/**
 * Meta de energia do plano (TMB/GET/VET) e atribuição de paciente a plano sem paciente (NI2-514).
 * POST /planos/{id}/energia e POST /planos/{id}/paciente. CSRF é validado pelo Router.
 */
class MealPlanEnergyController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** Mesma regra de quem prescreve que o DashboardController usa para alterar planos (nutricionista com CRN). */
    private function prescriberProfessionalId(): ?int
    {
        $st = $this->db->prepare("SELECT pr.id FROM clinic_members cm JOIN users u ON u.id = cm.user_id
            JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active'
            AND cm.role IN ('owner', 'admin', 'nutritionist')
            AND u.status = 'active' AND u.role = 'nutritionist'
            AND pr.crn IS NOT NULL AND TRIM(pr.crn) <> ''
            ORDER BY pr.id LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Você não tem permissão para editar prescrições. Apenas nutricionistas com CRN cadastrado na clínica podem prescrever.';
    }

    public function saveEnergy(array $params): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        try {
            $target = (new EnergyTargetService($this->db))->save($planId, $_POST);
            $_SESSION['feedback_message'] = 'Meta gravada no plano: VET de ' . number_format((float)$target['vet_kcal'], 0, ',', '.') . ' kcal.'
                . (!empty($target['warnings']) ? ' Há avisos para conferir abaixo.' : '');
        } catch (TenantSecurityException) {
            http_response_code(403);
            echo 'Acesso ao plano não autorizado.';
            return;
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            // Mantém o que foi digitado para o profissional corrigir sem refazer tudo.
            $keep = ['formula', 'sex', 'age_years', 'weight_kg', 'height_cm', 'ffm_kg', 'activity_factor', 'goal_type', 'goal_note',
                'adjust_mode', 'adjust_value', 'macro_mode', 'protein', 'carbohydrate', 'lipid'];
            $old = [];
            foreach ($keep as $k) {
                if (isset($_POST[$k]) && is_string($_POST[$k])) {
                    $old[$k] = mb_substr($_POST[$k], 0, 255);
                }
            }
            $_SESSION['energy_form_old'] = $old;
        } catch (\Throwable $e) {
            error_log('NI2-514 energy save failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível gravar a meta. Nenhuma alteração foi feita. Tente novamente.';
        }
        header(\ClyoNutri\Application\View\AttendanceContext::location("Location: /planos/{$planId}#energia"));
        exit;
    }

    public function assignPatient(array $params): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $patientId = (int)($_POST['patient_id'] ?? 0);
        try {
            (new MealPlanService($this->db))->assignPatient($planId, $patientId);
            $_SESSION['feedback_message'] = 'Paciente atribuído. A avaliação de alergias agora usa a anamnese dele; confira antes de publicar.';
        } catch (TenantSecurityException) {
            http_response_code(403);
            echo 'Acesso não autorizado.';
            return;
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-514 assign patient failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível atribuir o paciente. Tente novamente.';
        }
        header(\ClyoNutri\Application\View\AttendanceContext::location("Location: /planos/{$planId}"));
        exit;
    }
}
