<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Nutrition\ClinicHouseholdMeasureService;
use PDO;

/**
 * Medidas caseiras da clínica (NI2-514, fase 1): cadastro com fonte, importação por CSV e cobertura.
 * Rotas: GET /medidas-caseiras, POST /medidas-caseiras, POST /medidas-caseiras/{id}/remover,
 *        POST /medidas-caseiras/importar, GET /medidas-caseiras/modelo.csv. CSRF validado pelo Router.
 */
class ClinicMeasuresController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** Nutricionista ativo da clínica (dono, admin ou nutricionista), a mesma regra do catálogo de alimentos. */
    private function allowed(): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id = u.id
            WHERE u.id = ? AND cm.clinic_id = ? AND u.role = 'nutritionist' AND u.status = 'active' AND cm.status = 'active'
            AND cm.role IN ('owner', 'admin', 'nutritionist')");
        $st->execute([TenantContext::getUserId(), TenantContext::getClinicId()]);
        return (bool)$st->fetchColumn();
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Você não tem permissão para gerenciar as medidas caseiras da clínica. Apenas nutricionistas da clínica podem.';
    }

    public function index(): void
    {
        if (!$this->allowed()) {
            $this->deny();
            return;
        }
        $svc = new ClinicHouseholdMeasureService($this->db);
        $q = trim((string)($_GET['q'] ?? ''));
        $foodId = isset($_GET['food']) && ctype_digit((string)$_GET['food']) ? (int)$_GET['food'] : null;
        $selected = null;
        $globalMeasures = [];
        $clinicOfFood = [];
        if ($foodId !== null) {
            $st = $this->db->prepare('SELECT id, name, taco_code FROM foods WHERE id = ? AND (clinic_id IS NULL OR clinic_id = ?)');
            $st->execute([$foodId, TenantContext::getClinicId()]);
            $selected = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($selected) {
                $g = $this->db->prepare('SELECT measure_name, grams_equivalent FROM food_household_measures WHERE food_id = ?'
                    . (\ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService::ready($this->db) ? " AND (review_status IS NULL OR review_status <> 'removed')" : '') // NI2-886
                    . ' ORDER BY grams_equivalent');
                $g->execute([$foodId]);
                $globalMeasures = $g->fetchAll(PDO::FETCH_ASSOC);
                $clinicOfFood = $svc->list($foodId);
            }
        }
        $foundFoods = $q !== '' ? $svc->searchFoods($q) : [];
        $listQ = trim((string)($_GET['lq'] ?? ''));
        $allMeasures = $svc->list(null, $listQ, 100, 0);
        $coverage = $svc->coverage();
        $importReport = $_SESSION['measures_import_report'] ?? null;
        $old = $_SESSION['measures_form_old'] ?? [];
        unset($_SESSION['measures_import_report'], $_SESSION['measures_form_old']);

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        $title = 'Medidas caseiras da clínica';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Profissional';
        $csrfToken = Csrf::token();
        // NI2-891: estado da base Clyo Nutri revisada (NI2-886) para o texto do topo.
        $measureBase = ['pending' => 0, 'approved' => 0, 'last_at' => null];
        try {
            $mdb = \ClyoNutri\Core\Database\Connection::getInstance();
            if (\ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService::ready($mdb)) {
                $st = (new \ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService($mdb))->stats();
                $measureBase = ['pending' => $st['pending'], 'approved' => $st['approved'],
                    'last_at' => $mdb->query("SELECT MAX(reviewed_at) FROM food_household_measures WHERE review_status IN ('approved','removed')")->fetchColumn() ?: null,
                    'rt' => \ClyoNutri\Domain\Catalog\CatalogTechnicalReviewer::load($mdb)->name]; // v54.77: RT nomeado
            }
        } catch (\Throwable) { /* sem a migração 99 o texto neutro abaixo vale */ }

        ob_start();
        require __DIR__ . '/../../../views/meal_plans/clinic_measures.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function save(): void
    {
        if (!$this->allowed()) {
            $this->deny();
            return;
        }
        $foodId = (int)($_POST['food_id'] ?? 0);
        try {
            $r = (new ClinicHouseholdMeasureService($this->db))->upsert(
                $foodId,
                (string)($_POST['measure_name'] ?? ''),
                $_POST['grams'] ?? '',
                (string)($_POST['source'] ?? ''),
                isset($_POST['source_ref']) ? (string)$_POST['source_ref'] : null
            );
            $_SESSION['feedback_message'] = $r['created'] ? 'Medida cadastrada para a clínica.' : 'Medida atualizada.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $_SESSION['measures_form_old'] = [
                'measure_name' => mb_substr((string)($_POST['measure_name'] ?? ''), 0, 100),
                'grams' => mb_substr((string)($_POST['grams'] ?? ''), 0, 20),
                'source' => mb_substr((string)($_POST['source'] ?? ''), 0, 255),
                'source_ref' => mb_substr((string)($_POST['source_ref'] ?? ''), 0, 255),
            ];
        } catch (\Throwable $e) {
            error_log('NI2-514 clinic measure save failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível gravar a medida. Nada foi alterado. Tente novamente.';
        }
        header('Location: /medidas-caseiras?food=' . $foodId);
        exit;
    }

    public function remove(array $params): void
    {
        if (!$this->allowed()) {
            $this->deny();
            return;
        }
        $back = '/medidas-caseiras';
        try {
            (new ClinicHouseholdMeasureService($this->db))->delete((int)($params['id'] ?? 0));
            $_SESSION['feedback_message'] = 'Medida removida. Planos já emitidos não mudam. Em itens novos, colheres e xícara voltam ao peso padrão com confirmação; unidade e fatia só voltam a ser aceitas com uma medida cadastrada.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-514 clinic measure delete failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível remover a medida. Tente novamente.';
        }
        header('Location: ' . $back);
        exit;
    }

    public function import(): void
    {
        if (!$this->allowed()) {
            $this->deny();
            return;
        }
        $source = trim((string)($_POST['default_source'] ?? ''));
        $content = '';
        $file = $_FILES['csv_file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$file['tmp_name'])) {
            if ((int)$file['size'] > ClinicHouseholdMeasureService::MAX_BYTES) {
                $_SESSION['error_message'] = 'O arquivo passa de 1 MB.';
                header('Location: /medidas-caseiras#importar');
                exit;
            }
            $content = (string)file_get_contents((string)$file['tmp_name']);
        } else {
            $content = (string)($_POST['csv_text'] ?? '');
        }
        try {
            if (mb_strlen($source) < 3) {
                throw new \InvalidArgumentException('Informe a fonte dos valores do arquivo (usada nas linhas sem a coluna «fonte»).');
            }
            $report = (new ClinicHouseholdMeasureService($this->db))->importCsv($content, mb_substr($source, 0, 255));
            if ($report['ok']) {
                $_SESSION['feedback_message'] = "Importação concluída: {$report['created']} medidas novas e {$report['updated']} atualizadas.";
            } else {
                $_SESSION['error_message'] = 'A importação não foi feita. Veja os motivos abaixo.';
                $_SESSION['measures_import_report'] = $report['errors'];
            }
        } catch (\InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-514 clinic measures import failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível importar. Nada foi gravado. Tente novamente.';
        }
        header('Location: /medidas-caseiras#importar');
        exit;
    }

    public function template(): void
    {
        if (!$this->allowed()) {
            $this->deny();
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modelo-medidas-caseiras.csv"');
        echo ClinicHouseholdMeasureService::csvTemplate();
    }
}
