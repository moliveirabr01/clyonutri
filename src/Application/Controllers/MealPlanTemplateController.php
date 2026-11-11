<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\MealPlan\MealPlanTemplateService;
use PDO;

/**
 * Modelos de plano da clínica (NI2-514 fase 2, NI2-519).
 * Rotas: GET /modelos, POST /planos/{id}/salvar-como-modelo, POST /modelos/{id}/usar, POST /modelos/{id}/arquivar,
 *        POST /modelos/{id}/padrao, POST /modelos/{id}/aprovacao e POST /modelos/{id}/visibilidade (v43).
 *        CSRF é validado pelo Router. Mesma regra de quem prescreve dos planos; aprovar é só de dono ou administrador.
 */
class MealPlanTemplateController
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

    /** NI2-519 (v43): dono ou administrador ativo da clínica (aprova modelos e mexe em modelos de outros). */
    private function isManager(): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM clinic_members WHERE clinic_id = ? AND user_id = ? AND status = 'active' AND role IN ('owner', 'admin')");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        return (bool)$st->fetchColumn();
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Você não tem permissão para usar modelos de plano. Apenas nutricionistas com CRN cadastrado na clínica podem prescrever.';
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }

    public function index(): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        $svc = new MealPlanTemplateService($this->db);
        $showArchived = ($_GET['arquivados'] ?? '') === '1';
        $templates = $svc->listForClinic($showArchived);
        $st = $this->db->prepare("SELECT id, name FROM patients WHERE clinic_id = :c AND status = 'active' ORDER BY name ASC");
        $st->execute(['c' => TenantContext::getClinicId()]);
        $patients = $st->fetchAll(PDO::FETCH_ASSOC);

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        $title = 'Modelos de plano';
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Profissional';
        $csrfToken = Csrf::token();
        $isManager = $this->isManager();
        $hasV43 = $svc->hasV43();
        $currentUserId = (int)TenantContext::getUserId();

        ob_start();
        require __DIR__ . '/../../../views/meal_plans/templates.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function saveFromPlan(array $params): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        try {
            $r = (new MealPlanTemplateService($this->db))->saveFromPlan(
                $planId,
                (int)TenantContext::getUserId(),
                (string)($_POST['template_title'] ?? ''),
                (string)($_POST['template_category'] ?? ''),
                isset($_POST['template_description']) ? (string)$_POST['template_description'] : null,
                !empty($_POST['include_notes']),
                (string)($_POST['visibility'] ?? 'clinica'),
                !empty($_POST['include_target'])
            );
            $_SESSION['feedback_message'] = "Modelo '{$r['title']}' salvo com {$r['meals']} " . ($r['meals'] === 1 ? 'refeição' : 'refeições')
                . " e {$r['items']} " . ($r['items'] === 1 ? 'alimento' : 'alimentos') . '.'
                . (($r['visibility'] ?? 'clinica') === 'pessoal' ? ' Ele é pessoal: só você vê.' : ' A equipe da clínica já pode usar.')
                . (!empty($r['target_included']) ? ' A meta foi guardada só como referência (kcal e macros), sem dados do paciente.' : ' Ele não guarda paciente nem meta de energia individual.');
        } catch (TenantSecurityException) {
            http_response_code(403);
            echo 'Acesso ao plano não autorizado.';
            return;
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 save template failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível salvar o modelo. O plano não foi alterado. Tente novamente.';
        }
        $this->redirect("/planos/{$planId}");
    }

    public function usePlan(array $params): void
    {
        $professionalId = $this->prescriberProfessionalId();
        if ($professionalId === null) {
            $this->deny();
            return;
        }
        $templateId = (int)($params['id'] ?? 0);
        $patientId = (int)($_POST['patient_id'] ?? 0);
        try {
            $r = (new MealPlanTemplateService($this->db))->createPlanFromTemplate(
                $templateId,
                $professionalId,
                $patientId > 0 ? $patientId : null,
                isset($_POST['title']) ? (string)$_POST['title'] : null
            );
            $_SESSION['feedback_message'] = self::usedMessage($r);
            $this->redirect('/planos/' . (int)$r['plan_id']);
        } catch (TenantSecurityException) {
            http_response_code(403);
            echo 'Acesso não autorizado.';
            return;
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 use template failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível criar o plano a partir do modelo. Nada foi criado. Tente novamente.';
        }
        $this->redirect('/modelos');
    }

    /** Mensagem depois de criar um plano a partir de modelo (também usada pelo "Novo plano"). */
    public static function usedMessage(array $r): string
    {
        $msg = 'Plano criado a partir do modelo. Confira as quantidades para este paciente; o modelo não foi alterado.';
        if (!empty($r['skipped'])) {
            $names = array_slice(array_unique($r['skipped']), 0, 5);
            $msg .= ' Ficaram de fora ' . count($r['skipped']) . ' ' . (count($r['skipped']) === 1 ? 'alimento que não está mais' : 'alimentos que não estão mais')
                . ' no catálogo: ' . implode(', ', $names) . (count($r['skipped']) > 5 ? ' e outros' : '') . '.';
        }
        return $msg;
    }

    public function archive(array $params): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        try {
            (new MealPlanTemplateService($this->db))->archive((int)($params['id'] ?? 0), $this->userId(), $this->isManager());
            $_SESSION['feedback_message'] = 'Modelo arquivado. Os planos criados a partir dele continuam como estão.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 archive template failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível arquivar o modelo. Tente novamente.';
        }
        $this->redirect('/modelos');
    }

    public function setDefault(array $params): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        $on = ($_POST['is_default'] ?? '1') === '1';
        try {
            (new MealPlanTemplateService($this->db))->setClinicDefault((int)($params['id'] ?? 0), $on, $this->userId(), $this->isManager());
            $_SESSION['feedback_message'] = $on
                ? 'Modelo marcado como padrão da clínica. Ele já vem escolhido ao criar um plano novo.'
                : 'Este modelo deixou de ser o padrão da clínica.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 default template failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível alterar o padrão da clínica. Tente novamente.';
        }
        $this->redirect('/modelos');
    }

    /** NI2-519 (v43): dono ou administrador marca o modelo como aprovado pela clínica (ou retira). */
    public function setApproval(array $params): void
    {
        if ($this->prescriberProfessionalId() === null && !$this->isManager()) {
            $this->deny();
            return;
        }
        if (!$this->isManager()) {
            http_response_code(403);
            echo 'Só o dono ou um administrador da clínica aprova modelos.';
            return;
        }
        $on = ($_POST['approved'] ?? '1') === '1';
        try {
            (new MealPlanTemplateService($this->db))->setApproval((int)($params['id'] ?? 0), (int)TenantContext::getUserId(), $on);
            $_SESSION['feedback_message'] = $on ? 'Modelo aprovado pela clínica. Ele aparece primeiro na lista da equipe.' : 'Aprovação retirada. O modelo continua disponível para a equipe.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 approve template failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível alterar a aprovação. Tente novamente.';
        }
        $this->redirect('/modelos');
    }

    /** NI2-519 (v43): pessoal ou da clínica. O autor muda o próprio; dono ou administrador muda qualquer um da clínica. */
    public function setVisibility(array $params): void
    {
        if ($this->prescriberProfessionalId() === null) {
            $this->deny();
            return;
        }
        $vis = (string)($_POST['visibility'] ?? '');
        try {
            (new MealPlanTemplateService($this->db))->setVisibility((int)($params['id'] ?? 0), $vis, (int)TenantContext::getUserId(), $this->isManager());
            $_SESSION['feedback_message'] = $vis === 'pessoal' ? 'Modelo agora é pessoal: só o autor vê. A aprovação e o padrão da clínica foram retirados.' : 'Modelo liberado para a equipe da clínica.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 template visibility failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível alterar quem vê o modelo. Tente novamente.';
        }
        $this->redirect('/modelos');
    }
}
