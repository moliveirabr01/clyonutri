<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\LaboratoryExam\ExamCatalogService;
use ClyoNutri\Domain\LaboratoryExam\ExamRegulatoryPolicy;
use ClyoNutri\Domain\LaboratoryExam\ProfessionalEligibilityValidator;
use PDO;

/**
 * Catálogo de exames e política de solicitação (v47, NI2-530 e NI2-532).
 *  - GET  /exames/catalogo                                   catálogo, favoritos, exames da clínica e validação
 *  - POST /exames/catalogo/favoritos/{examId}                marca/desmarca favorito (só atalho, nada vem marcado no pedido)
 *  - POST /exames/catalogo/personalizados                    propõe exame da clínica (fica aguardando validação)
 *  - POST /exames/catalogo/personalizados/{examId}/validar   owner/admin aprova ou recusa (com motivo)
 *  - GET  /exames/politica                                   versões da política (somente leitura)
 * Todas as rotas passam por $requireAuth. A clínica vem sempre da sessão. Ações vão para exam_audit_logs.
 */
class ExamCatalogController
{
    private PDO $db;
    private ExamCatalogService $catalog;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->catalog = new ExamCatalogService($this->db);
    }

    /** GET /exames/catalogo */
    public function index(array $params = [], array $old = [], ?string $error = null, int $status = 200): void
    {
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $eligibilityError = $this->eligibilityError($userId, $clinicId);
        $catalog = $this->catalog->search('', null, true, 500, $clinicId);
        // NI2-891: justificativa "sem código" da revisão do RT; só lê a coluna se a migração 100 já rodou.
        $review = new \ClyoNutri\Domain\LaboratoryExam\ExamCatalogReviewService($this->db);
        if ($review->ready()) {
            $notes = array_column($review->rows(), 'standard_code_note', 'id');
            foreach ($catalog as &$row) { $row['standard_code_note'] = $notes[(int)$row['id']] ?? null; }
            unset($row);
        }
        $favoriteIds = $this->catalog->favoriteIds($clinicId, $userId);
        $custom = $this->catalog->listCustomExams($clinicId);
        $isManager = ExamCatalogService::isClinicManager($this->db, $userId, $clinicId);
        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();
        http_response_code($status);
        $this->noStore();
        $this->render('catalog', 'Catálogo de exames laboratoriais',
            compact('catalog', 'favoriteIds', 'custom', 'isManager', 'flash', 'csrfToken', 'old', 'error', 'eligibilityError'));
    }

    /** POST /exames/catalogo/favoritos/{examId} */
    public function toggleFavorite(array $params): void
    {
        if (!$this->csrfOk()) {
            return;
        }
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $examId = (int)($params['examId'] ?? 0);
        try {
            ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
            $on = $this->catalog->toggleFavorite($clinicId, $userId, $examId);
            $this->audit($clinicId, $userId, $on ? 'EXAM_FAVORITE_ADDED' : 'EXAM_FAVORITE_REMOVED', $examId);
            $this->flash('success', $on ? 'Exame marcado como favorito. Ele aparece primeiro no editor, sem vir marcado.' : 'Exame retirado dos favoritos.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->userMessage($e));
        }
        $this->redirect('/exames/catalogo#exame-' . $examId);
    }

    /** POST /exames/catalogo/personalizados */
    public function submitCustom(array $params = []): void
    {
        if (!$this->csrfOk()) {
            return;
        }
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $input = [];
        foreach (['canonical_name', 'category', 'sample_type', 'fasting_hours', 'nutritional_indication', 'synonyms', 'standard_code_system', 'standard_code'] as $k) {
            $input[$k] = is_scalar($_POST[$k] ?? null) ? (string)$_POST[$k] : '';
        }
        $input['declaration'] = (string)($_POST['declaration'] ?? '') === '1';
        try {
            ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
            $exam = $this->catalog->submitCustomExam($clinicId, $userId, $input);
            $this->audit($clinicId, $userId, 'EXAM_CUSTOM_SUBMITTED', (int)$exam['id']);
            $this->flash('success', 'Exame enviado para validação. Ele só entra em solicitações depois que a responsável pela clínica aprovar.');
            $this->redirect('/exames/catalogo#personalizados');
        } catch (\Throwable $e) {
            $this->index([], $input, $this->userMessage($e), 422);
        }
    }

    /** POST /exames/catalogo/personalizados/{examId}/validar */
    public function validateCustom(array $params): void
    {
        if (!$this->csrfOk()) {
            return;
        }
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $examId = (int)($params['examId'] ?? 0);
        $approve = (string)($_POST['decision'] ?? '') === 'approve';
        try {
            $this->catalog->validateCustomExam($clinicId, $userId, $examId, $approve, is_scalar($_POST['validation_note'] ?? null) ? (string)$_POST['validation_note'] : '');
            $this->audit($clinicId, $userId, $approve ? 'EXAM_CUSTOM_APPROVED' : 'EXAM_CUSTOM_REJECTED', $examId);
            $this->flash('success', $approve ? 'Exame aprovado: já pode entrar em solicitações desta clínica.' : 'Exame recusado. O motivo fica registrado.');
        } catch (\Throwable $e) {
            $this->flash('error', $this->userMessage($e));
        }
        $this->redirect('/exames/catalogo#personalizados');
    }

    /** GET /exames/politica */
    public function policy(array $params = []): void
    {
        $clinicId = TenantContext::getClinicId();
        $versions = ExamRegulatoryPolicy::listVersions($this->db, $clinicId);
        $this->noStore();
        $this->render('policy', 'Política de solicitação de exames', compact('versions'));
    }

    // ---------------------------------------------------------------- internos

    private function eligibilityError(int $userId, int $clinicId): ?string
    {
        try {
            ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
            return null;
        } catch (\Throwable $e) {
            return $this->userMessage($e);
        }
    }

    private function audit(int $clinicId, int $userId, string $action, int $examId): void
    {
        $this->db->prepare("INSERT INTO exam_audit_logs (clinic_id, user_id, action, entity_type, entity_id, ip_address, details, created_at)
            VALUES (?, ?, ?, 'exam_catalog', ?, ?, NULL, CURRENT_TIMESTAMP)")
            ->execute([$clinicId, $userId, $action, $examId, $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    private function csrfOk(): bool
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Sua sessão de formulário expirou. Recarregue a página e tente de novo.';
            return false;
        }
        return true;
    }

    private function userMessage(\Throwable $e): string
    {
        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException) {
            return $e->getMessage();
        }
        error_log('EXAM_CATALOG_ERROR ' . get_class($e) . ': ' . $e->getMessage());
        return 'Não foi possível concluir agora. Tente de novo em instantes.';
    }

    private function noStore(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }
    }

    private function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['exam_flash'] = ['type' => $type, 'message' => $message];
        }
    }

    private function takeFlash(): ?array
    {
        $f = $_SESSION['exam_flash'] ?? null;
        unset($_SESSION['exam_flash']);
        return is_array($f) ? $f : null;
    }

    private function redirect(string $to): void
    {
        if (!headers_sent()) {
            header('Location: ' . $to, true, 303);
        }
        if (!defined('CLYONUTRI_TESTING')) {
            exit;
        }
    }

    private function render(string $view, string $title, array $vars): void
    {
        extract($vars, EXTR_SKIP);
        $activeNav = 'exames';
        ob_start();
        require __DIR__ . '/../../../views/exams/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
