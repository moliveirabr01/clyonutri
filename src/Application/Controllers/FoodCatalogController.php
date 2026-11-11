<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Food\FoodCatalogService;
use PDO;

/** v54.41: Base de Alimentos > Adicionar alimento (alimento próprio da clínica). */
final class FoodCatalogController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** Mesmo perfil que já cuida da base (regras de equivalência): nutricionista ativa da clínica. */
    public static function canManage(PDO $db): bool
    {
        if (!TenantContext::hasClinic()) {
            return false;
        }
        $st = $db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.id = ? AND cm.clinic_id = ?
            AND u.role = 'nutritionist' AND u.status = 'active' AND cm.status = 'active' AND cm.role IN ('owner', 'admin', 'nutritionist')");
        $st->execute([TenantContext::getUserId(), TenantContext::getClinicId()]);
        return (bool)$st->fetchColumn();
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Apenas nutricionistas da clínica podem adicionar alimentos.';
    }

    public function createForm(): void
    {
        if (!self::canManage($this->db)) {
            $this->deny();
            return;
        }
        $form = $_SESSION['food_form'] ?? [];
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['food_form'], $_SESSION['error_message']);
        $categories = (new FoodCatalogService($this->db))->listCategories();
        $units = FoodCatalogService::CUSTOM_UNITS;
        $title = 'Adicionar alimento';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Profissional';
        $csrfToken = Csrf::token();
        ob_start();
        require __DIR__ . '/../../../views/foods/create.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function create(): void
    {
        if (!self::canManage($this->db)) {
            $this->deny();
            return;
        }
        $keys = ['name', 'category', 'serving_size_g', 'serving_unit', 'energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g', 'allergens'];
        $in = [];
        foreach ($keys as $k) {
            $in[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        }
        try {
            $food = (new FoodCatalogService($this->db))->createClinicFood($in);
            $_SESSION['feedback_message'] = "Alimento \"{$food['name']}\" adicionado à base da clínica. Ele já aparece na busca e nos planos alimentares.";
            header('Location: /alimentos?source=clinic&q=' . rawurlencode($food['name']) . '&novo=' . $food['id']);
        } catch (\InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $_SESSION['food_form'] = $in;
            header('Location: /alimentos/novo');
        } catch (\Throwable $e) {
            error_log('v54.41 food create failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível salvar o alimento. Tente novamente.';
            $_SESSION['food_form'] = $in;
            header('Location: /alimentos/novo');
        }
        http_response_code(302);
    }
}
