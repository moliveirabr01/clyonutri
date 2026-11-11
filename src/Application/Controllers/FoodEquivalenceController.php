<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Food\FoodCategory;
use ClyoNutri\Domain\Food\FoodEquivalenceService;
use PDO;

/**
 * Regras de equivalência da clínica (NI2-519 / NI2-520, v43).
 * Rotas: GET /alimentos/equivalencias, POST /alimentos/equivalencias, POST /alimentos/equivalencias/{id},
 *        POST /alimentos/equivalencias/{id}/revisar, POST /alimentos/equivalencias/{id}/arquivar,
 *        GET /planos/{planId}/itens/{itemId}/equivalentes (JSON para o editor de plano).
 * Quem edita: equipe com acesso ao catálogo (owner, admin ou nutricionista ativo). Quem marca como revisada:
 * nutricionista com CRN cadastrado. Tudo filtrado pela clínica da sessão; CSRF validado pelo Router.
 */
class FoodEquivalenceController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    private function hasCatalogAccess(): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.id = ? AND cm.clinic_id = ?
            AND u.role = 'nutritionist' AND u.status = 'active' AND cm.status = 'active' AND cm.role IN ('owner', 'admin', 'nutritionist')");
        $st->execute([TenantContext::getUserId(), TenantContext::getClinicId()]);
        return (bool)$st->fetchColumn();
    }

    private function hasCrn(): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM clinic_members cm JOIN users u ON u.id = cm.user_id JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' AND cm.role IN ('owner', 'admin', 'nutritionist')
            AND u.status = 'active' AND u.role = 'nutritionist' AND pr.crn IS NOT NULL AND TRIM(pr.crn) <> '' LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        return (bool)$st->fetchColumn();
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Apenas nutricionistas da clínica podem ver e editar as regras de equivalência.';
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }

    public function index(): void
    {
        if (!$this->hasCatalogAccess()) {
            $this->deny();
            return;
        }
        $svc = new FoodEquivalenceService($this->db);
        $available = $svc->available();
        $showArchived = ($_GET['arquivadas'] ?? '') === '1';
        $rules = $svc->listRules($showArchived);
        $editId = (int)($_GET['editar'] ?? 0);
        $editing = $editId > 0 ? $svc->find($editId) : null;
        $groups = FoodCategory::TACO_GROUPS;
        $canReview = $this->hasCrn();

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        $old = $_SESSION['equivalence_form'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message'], $_SESSION['equivalence_form']);

        $title = 'Regras de equivalência';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Profissional';
        $csrfToken = Csrf::token();

        ob_start();
        require __DIR__ . '/../../../views/foods/equivalence_rules.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private static function form(): array
    {
        return [
            'name' => (string)($_POST['name'] ?? ''),
            'food_groups' => is_array($_POST['food_groups'] ?? null) ? $_POST['food_groups'] : [],
            'energy_tolerance_pct' => (string)($_POST['energy_tolerance_pct'] ?? ''),
            'macro_tolerance_pct' => (string)($_POST['macro_tolerance_pct'] ?? ''),
            'source_note' => (string)($_POST['source_note'] ?? ''),
        ];
    }

    public function create(): void
    {
        if (!$this->hasCatalogAccess()) {
            $this->deny();
            return;
        }
        $in = self::form();
        try {
            (new FoodEquivalenceService($this->db))->create($in, (int)TenantContext::getUserId());
            $_SESSION['feedback_message'] = 'Regra criada como pendente de revisão. Uma nutricionista com CRN precisa revisar antes de considerar a regra validada.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $_SESSION['equivalence_form'] = $in;
        } catch (\Throwable $e) {
            error_log('NI2-519 equivalence create failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível salvar a regra. Tente novamente.';
            $_SESSION['equivalence_form'] = $in;
        }
        $this->redirect('/alimentos/equivalencias');
    }

    public function update(array $params): void
    {
        if (!$this->hasCatalogAccess()) {
            $this->deny();
            return;
        }
        $id = (int)($params['id'] ?? 0);
        $in = self::form();
        try {
            (new FoodEquivalenceService($this->db))->update($id, $in);
            $_SESSION['feedback_message'] = 'Regra atualizada. Ela voltou para pendente de revisão.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $_SESSION['equivalence_form'] = $in;
            $this->redirect('/alimentos/equivalencias?editar=' . $id);
        } catch (\Throwable $e) {
            error_log('NI2-519 equivalence update failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível salvar a regra. Tente novamente.';
        }
        $this->redirect('/alimentos/equivalencias');
    }

    public function review(array $params): void
    {
        if (!$this->hasCatalogAccess()) {
            $this->deny();
            return;
        }
        if (!$this->hasCrn()) {
            http_response_code(403);
            echo 'Só nutricionista com CRN cadastrado pode marcar a regra como revisada.';
            return;
        }
        try {
            (new FoodEquivalenceService($this->db))->markReviewed((int)($params['id'] ?? 0), (int)TenantContext::getUserId());
            $_SESSION['feedback_message'] = 'Regra marcada como revisada por você.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 equivalence review failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível marcar a revisão. Tente novamente.';
        }
        $this->redirect('/alimentos/equivalencias');
    }

    public function archive(array $params): void
    {
        if (!$this->hasCatalogAccess()) {
            $this->deny();
            return;
        }
        try {
            (new FoodEquivalenceService($this->db))->archive((int)($params['id'] ?? 0));
            $_SESSION['feedback_message'] = 'Regra arquivada. As substituições já gravadas nos planos continuam iguais; só o veredito deixa de usar esta regra.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-519 equivalence archive failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível arquivar a regra. Tente novamente.';
        }
        $this->redirect('/alimentos/equivalencias');
    }

    /** GET /planos/{planId}/itens/{itemId}/equivalentes: sugestões de troca para um item do plano (JSON). */
    public function suggestions(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->hasCatalogAccess()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso não autorizado.']);
            return;
        }
        $planId = (int)($params['planId'] ?? 0);
        $itemId = (int)($params['itemId'] ?? 0);
        $cid = (int)TenantContext::getClinicId();
        try {
            TenantGuard::assertMealPlanBelongsToClinic($planId, $cid, $this->db);
        } catch (TenantSecurityException) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso ao plano não autorizado.']);
            return;
        }
        $st = $this->db->prepare('SELECT i.food_id, i.energy_kcal, i.protein_g, i.carbohydrate_g, i.lipid_g, f.category
            FROM meal_plan_items i JOIN meal_plan_meals m ON m.id = i.meal_id JOIN meal_plans p ON p.id = m.meal_plan_id
            JOIN foods f ON f.id = i.food_id
            WHERE i.id = ? AND p.id = ? AND p.clinic_id = ?');
        $st->execute([$itemId, $planId, $cid]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item não encontrado neste plano.']);
            return;
        }
        try {
            $r = (new FoodEquivalenceService($this->db))->suggestions($item, 8);
        } catch (\Throwable $e) {
            error_log('NI2-519 equivalence suggestions failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Não foi possível calcular as trocas agora.']);
            return;
        }
        $rule = $r['rule'] === null ? null : [
            'id' => $r['rule']['id'], 'name' => $r['rule']['name'], 'food_groups' => $r['rule']['food_groups'],
            'energy_tolerance_pct' => $r['rule']['energy_tolerance_pct'], 'macro_tolerance_pct' => $r['rule']['macro_tolerance_pct'],
            'source_note' => $r['rule']['source_note'], 'review_status' => $r['rule']['review_status'],
        ];
        echo json_encode(['rule' => $rule, 'reason' => $r['reason'], 'item_group' => FoodCategory::canonical($item['category']),
            'suggestions' => $r['suggestions']], JSON_UNESCAPED_UNICODE);
    }
}
