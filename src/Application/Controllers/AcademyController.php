<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Academy\AcademyArticleService;
use ClyoNutri\Domain\Academy\AcademyEvidenceDeskService;
use ClyoNutri\Domain\Academy\AcademyFavoriteService;
use ClyoNutri\Domain\Academy\AcademyPublishingService;
use PDO;

/**
 * Controller do Clyo Academy (NI2-663).
 *
 * Módulos integrados:
 * - Academy Library (Biblioteca de literatura científica, PubMed, metadados, citações e proveniência)
 * - Evidence Desk (Fichas críticas metodológicas PICO e certeza da evidência por curador)
 * - Academy Publishing (Publicações técnicas, revisões práticas e condutas clínicas por nutricionistas)
 * - Minha Coleção (Coleção pessoal, estudos salvos e anotações clínicas)
 */
class AcademyController
{
    private PDO $db;
    private AcademyArticleService $articleService;
    private AcademyEvidenceDeskService $evidenceService;
    private AcademyPublishingService $publishingService;
    private AcademyFavoriteService $favoriteService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->articleService = new AcademyArticleService($this->db);
        $this->evidenceService = new AcademyEvidenceDeskService($this->db);
        $this->publishingService = new AcademyPublishingService($this->db);
        $this->favoriteService = new AcademyFavoriteService($this->db);

        // Garante que o acervo inicial exista
        $this->articleService->seedIfEmpty();
        $this->evidenceService->seedIfEmpty();
    }

    /** GET /academy - Biblioteca Científica */
    public function index(array $params = []): void
    {
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();

        $filters = [
            'q' => $_GET['q'] ?? '',
            'study_design' => $_GET['study_design'] ?? 'all',
            'access_type' => $_GET['access_type'] ?? 'all',
            'condition_tag' => $_GET['condition_tag'] ?? 'all',
            'year_min' => !empty($_GET['year_min']) ? (int)$_GET['year_min'] : null,
            'year_max' => !empty($_GET['year_max']) ? (int)$_GET['year_max'] : null,
            'limit' => 20,
            'offset' => isset($_GET['page']) && is_numeric($_GET['page']) ? (max(1, (int)$_GET['page']) - 1) * 20 : 0,
        ];

        $results = $this->articleService->search($filters);
        $favoriteIds = array_column($this->favoriteService->listFavorites($clinicId, $userId), 'id');

        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('index', 'Clyo Academy — Biblioteca Científica', [
            'results' => $results,
            'filters' => $filters,
            'favoriteIds' => $favoriteIds,
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'library',
        ]);
    }

    /** GET /academy/artigo/{id} - Detalhe do Artigo e Citações */
    public function articleDetail(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $article = $this->articleService->getById($id);
        if (!$article) {
            http_response_code(404);
            $this->render('not_found', 'Artigo Não Encontrado', ['subTab' => 'library']);
            return;
        }

        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();

        $evidenceSheet = $this->evidenceService->getByArticleId($id);
        $citations = $this->articleService->formatCitations($article);
        $isFavorite = $this->favoriteService->isFavorite($clinicId, $userId, $id);
        $collections = $this->favoriteService->getUserCollections($clinicId, $userId);

        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('article_detail', htmlspecialchars($article['title']) . ' — Clyo Academy', [
            'article' => $article,
            'evidenceSheet' => $evidenceSheet,
            'citations' => $citations,
            'isFavorite' => $isFavorite,
            'collections' => $collections,
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'library',
        ]);
    }

    /** POST /academy/artigo/{id}/favoritar - Alterna favorito */
    public function toggleFavorite(array $params = []): void
    {
        Csrf::validate($_POST['_csrf_token'] ?? '');
        $id = (int)($params['id'] ?? 0);
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $collection = trim((string)($_POST['collection_name'] ?? 'Geral'));

        $saved = $this->favoriteService->toggleFavorite($clinicId, $userId, $id, $collection !== '' ? $collection : 'Geral');

        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['saved' => $saved, 'article_id' => $id]);
            return;
        }

        $this->flash('success', $saved ? 'Estudo adicionado à sua coleção pessoal!' : 'Estudo removido da sua coleção.');
        $this->redirect($_SERVER['HTTP_REFERER'] ?? '/academy/artigo/' . $id);
    }

    /** GET /academy/evidencias - Evidence Desk */
    public function evidenceDesk(array $params = []): void
    {
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();

        $results = $this->articleService->search(['has_evidence_sheet' => true, 'limit' => 50]);
        $favoriteIds = array_column($this->favoriteService->listFavorites($clinicId, $userId), 'id');

        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('evidence_desk', 'Evidence Desk — Fichas Críticas de Evidências', [
            'results' => $results,
            'favoriteIds' => $favoriteIds,
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'evidence',
        ]);
    }

    /** GET /academy/colecao - Minha Coleção Pessoal */
    public function myCollection(array $params = []): void
    {
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();

        $selectedCollection = $_GET['collection'] ?? null;
        $favorites = $this->favoriteService->listFavorites($clinicId, $userId, $selectedCollection);
        $collections = $this->favoriteService->getUserCollections($clinicId, $userId);

        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('my_collection', 'Minha Coleção — Clyo Academy', [
            'favorites' => $favorites,
            'collections' => $collections,
            'selectedCollection' => $selectedCollection,
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'collection',
        ]);
    }

    /** GET /academy/publicacoes - Publicações Profissionais */
    public function publications(array $params = []): void
    {
        $clinicId = TenantContext::getClinicId();

        $published = $this->publishingService->listPublished(30);
        $myDrafts = $this->publishingService->listByClinic($clinicId);

        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('publications', 'Clyo Academy — Publicações & Sínteses Técnicas', [
            'published' => $published,
            'myDrafts' => $myDrafts,
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'publications',
        ]);
    }

    /** GET /academy/publicacoes/nova - Criar Nova Publicação */
    public function newPublication(array $params = []): void
    {
        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('new_publication', 'Nova Publicação Técnica — Clyo Academy', [
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'publications',
        ]);
    }

    /** POST /academy/publicacoes - Gravar Publicação */
    public function savePublication(array $params = []): void
    {
        Csrf::validate($_POST['_csrf_token'] ?? '');
        $clinicId = TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();

        $action = $_POST['action'] ?? 'save_draft';
        $title = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'practical_review'));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $body = trim((string)($_POST['body_markdown'] ?? ''));
        $references = trim((string)($_POST['references_text'] ?? ''));
        $conflict = trim((string)($_POST['conflict_of_interest_disclosure'] ?? ''));

        // Validação anti-PHI (dados sensíveis de pacientes)
        if (preg_match('/\b(cpf|rg|prontu[aá]rio\s*n[oº]?\s*\d+|paciente\s+[A-Z][a-z]+\s+[A-Z][a-z]+)\b/i', $body . ' ' . $summary)) {
            $this->flash('error', 'Aviso de Privacidade: O texto contém dados que podem identificar pacientes (PHI). Remova identificadores antes de salvar.');
            $this->redirect('/academy/publicacoes/nova');
            return;
        }

        try {
            $authorName = $_SESSION['user_name'] ?? 'Nutricionista';
            $authorCrn = $_SESSION['user_crn'] ?? null;

            $pubId = $this->publishingService->createDraft([
                'clinic_id' => $clinicId,
                'author_user_id' => $userId,
                'author_name' => $authorName,
                'author_crn' => $authorCrn,
                'title' => $title,
                'category' => $category,
                'summary' => $summary,
                'body_markdown' => $body,
                'references_text' => $references,
                'conflict_of_interest_disclosure' => $conflict,
            ]);

            if ($action === 'submit') {
                $this->publishingService->submit($pubId, $clinicId);
                $this->flash('success', 'Publicação submetida com sucesso para o comitê editorial da Clyo Academy!');
            } else {
                $this->flash('success', 'Rascunho salvo com sucesso na Clyo Academy.');
            }

            $this->redirect('/academy/publicacoes/' . $pubId);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/academy/publicacoes/nova');
        }
    }

    /** GET /academy/publicacoes/{id} - Visualizar Publicação */
    public function publicationDetail(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $pub = $this->publishingService->getById($id);
        if (!$pub) {
            http_response_code(404);
            $this->render('not_found', 'Publicação Não Encontrada', ['subTab' => 'publications']);
            return;
        }

        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();

        $this->render('publication_detail', htmlspecialchars($pub['title']) . ' — Clyo Academy', [
            'pub' => $pub,
            'flash' => $flash,
            'csrfToken' => $csrfToken,
            'subTab' => 'publications',
        ]);
    }

    /** POST /academy/publicacoes/{id}/status - Revisão Editorial */
    public function updatePublicationStatus(array $params = []): void
    {
        Csrf::validate($_POST['_csrf_token'] ?? '');
        $id = (int)($params['id'] ?? 0);
        $userId = (int)TenantContext::getUserId();
        $action = $_POST['review_action'] ?? 'approve';
        $notes = trim((string)($_POST['editorial_notes'] ?? ''));

        try {
            $this->publishingService->review($id, $userId, $action, $notes !== '' ? $notes : null);
            $this->flash('success', $action === 'approve' ? 'Publicação aprovada e publicada no acervo da Clyo Academy!' : 'Publicação devolvida com parecer de revisão.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect('/academy/publicacoes/' . $id);
    }

    /** GET /api/v1/academy/search - API JSON */
    public function searchApi(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q = $_GET['q'] ?? '';
        $results = $this->articleService->search(['q' => $q, 'limit' => 15]);
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    }

    /** GET /api/v1/academy/protocols/{code} - API JSON de Evidências por Protocolo */
    public function protocolEvidenceApi(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $code = (string)($params['code'] ?? '');
        $articles = $this->articleService->getArticlesForProtocol($code);
        echo json_encode([
            'protocol_code' => $code,
            'count' => count($articles),
            'articles' => $articles,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['academy_flash'] = ['type' => $type, 'message' => $message];
        }
    }

    private function takeFlash(): ?array
    {
        $f = $_SESSION['academy_flash'] ?? null;
        unset($_SESSION['academy_flash']);
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
        $activeNav = 'academy';
        ob_start();
        require __DIR__ . '/../../../views/academy/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
