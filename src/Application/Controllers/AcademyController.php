<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\Academy\AcademyArticleService;
use ClyoNutri\Domain\Academy\AcademyEvidenceDeskService;
use ClyoNutri\Domain\Academy\AcademyFavoriteService;
use ClyoNutri\Domain\Academy\AcademyHandoutService;
use ClyoNutri\Domain\Materials\MaterialService;
use ClyoNutri\Domain\Academy\AcademyPublishingService;
use PDO;

/**
 * Clyo Academy (NI2-663) — lado das clínicas: biblioteca, fichas publicadas, coleção pessoal e publicações.
 *
 * v54.22 (decisão do Maicon em 03/11/2026): nesta fase a clínica só consome. Qualquer pessoa da clínica pesquisa,
 * lê, abre o link oficial e favorita; não cria, não envia, não edita e não publica nada. As rotas antigas de escrita
 * respondem 403 (authoringDenied) e ficam registradas na auditoria. Só aparece o que a equipe da Clyo Nutri publicou.
 *
 * Revisão de 03/11/2026 (code review do commit fae6581):
 *  - tudo atrás da chave global feature_flag_academy (nasce 0): com ela desligada, 404 e sem item de menu;
 *  - nenhum conteúdo é criado pelo código (sem semente);
 *  - a revisão editorial saiu daqui: é feita só no console da plataforma (/admin/academy);
 *  - publicação não publicada só abre para a clínica de origem;
 *  - redirecionamentos só para caminhos internos; CSRF conferido pelo Router em todo POST.
 *
 * v54.23: lâminas para pacientes (PDF de uma página) entram na mesma biblioteca e na mesma busca, com o filtro "Tipo"
 * (Tudo, Artigos, Lâminas). A clínica abre, baixa e favorita lâmina publicada; o arquivo sai por este controlador,
 * nunca pela pasta pública. Cadastrar, trocar arquivo e publicar é só no console da equipe.
 */
class AcademyController
{
    public const FLAG = 'feature_flag_academy';

    private PDO $db;
    private AcademyArticleService $articles;
    private AcademyEvidenceDeskService $evidence;
    private AcademyPublishingService $publishing;
    private AcademyFavoriteService $favorites;
    private AcademyHandoutService $handouts;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->articles = new AcademyArticleService($this->db);
        $this->evidence = new AcademyEvidenceDeskService($this->db);
        $this->publishing = new AcademyPublishingService($this->db);
        $this->favorites = new AcademyFavoriteService($this->db);
        $this->handouts = new AcademyHandoutService($this->db);
    }

    public static function enabled(?PDO $db = null): bool
    {
        try {
            $db = $db ?? Connection::getInstance();
            return (string)PlatformSettings::get(self::FLAG, '0', $db) === '1' && AcademyArticleService::ready($db);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public const TYPES = ['all' => 'Tudo', 'artigos' => 'Artigos', 'laminas' => 'Lâminas para pacientes'];

    /** GET /academy — biblioteca com busca única: artigos e lâminas (filtro "tipo"). */
    public function index(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $type = is_string($_GET['tipo'] ?? null) && isset(self::TYPES[$_GET['tipo']]) ? $_GET['tipo'] : 'all';
        $handoutsOn = AcademyHandoutService::ready($this->db);
        if (!$handoutsOn && $type === 'laminas') {
            $type = 'all';
        }
        $filters = [
            'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
            'tipo' => $type,
            'study_design' => is_string($_GET['study_design'] ?? null) ? $_GET['study_design'] : 'all',
            'access_type' => is_string($_GET['access_type'] ?? null) ? $_GET['access_type'] : 'all',
            'condition_tag' => is_string($_GET['condition_tag'] ?? null) ? $_GET['condition_tag'] : 'all',
            'tag' => is_string($_GET['tag'] ?? null) ? $_GET['tag'] : 'all',
            'year_min' => !empty($_GET['year_min']) ? (int)$_GET['year_min'] : null,
            'year_max' => !empty($_GET['year_max']) ? (int)$_GET['year_max'] : null,
            'limit' => 20, 'offset' => ($page - 1) * 20,
        ];
        // filtros que só fazem sentido para artigo escondem o bloco de lâminas em "Tudo"
        $articleOnly = $filters['study_design'] !== 'all' || $filters['access_type'] !== 'all' || $filters['condition_tag'] !== 'all';
        $results = $type === 'laminas' ? ['total' => 0, 'items' => []] : $this->articles->search($filters);
        $handouts = ['total' => 0, 'items' => []];
        if ($handoutsOn && ($type === 'laminas' || ($type === 'all' && !$articleOnly && $page === 1))) {
            $handouts = $this->handouts->search(['q' => $filters['q'], 'tag' => $filters['tag'],
                'limit' => $type === 'laminas' ? 20 : 6, 'offset' => $type === 'laminas' ? ($page - 1) * 20 : 0]);
        }
        $this->render('index', 'Clyo Academy — Biblioteca', [
            'results' => $results, 'handouts' => $handouts, 'filters' => $filters, 'page' => $page, 'handoutsOn' => $handoutsOn,
            'handoutTags' => $handoutsOn ? $this->handouts->publishedTags() : [],
            'favoriteIds' => array_column($this->favorites->listFavorites($clinicId, $userId), 'id'),
            'favoriteHandoutIds' => $handoutsOn ? array_map('intval', array_column($this->handouts->listFavorites($clinicId, $userId), 'id')) : [],
            'subTab' => $type === 'laminas' ? 'handouts' : 'library',
        ]);
    }

    /** GET /academy/laminas — atalho da aba "Lâminas para pacientes" */
    public function handoutList(array $params = []): void
    {
        $_GET['tipo'] = 'laminas';
        $this->index($params);
    }

    /** GET /academy/laminas/{id} — página da lâmina publicada (prévia, abrir e baixar o PDF, favoritar). */
    public function handoutDetail(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $h = AcademyHandoutService::ready($this->db) ? $this->handouts->getPublished((int)($params['id'] ?? 0)) : null;
        if (!$h) {
            $this->notFound('handouts');
            return;
        }
        $this->render('handout_detail', (string)$h['title'] . ' — Clyo Academy', [
            'handout' => $h, 'isFavorite' => $this->handouts->isFavorite($clinicId, $userId, (int)$h['id']), 'subTab' => 'handouts',
        ]);
    }

    /** GET /academy/laminas/{id}/arquivo (?baixar=1) e /academy/laminas/{id}/previa — só lâmina publicada; fora da pasta pública. */
    public function handoutFile(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        $h = AcademyHandoutService::ready($this->db) ? $this->handouts->getPublished((int)($params['id'] ?? 0)) : null;
        $kind = ($params['kind'] ?? 'pdf') === 'preview' ? 'preview' : 'pdf';
        if (!$h || ($kind === 'preview' && empty($h['preview_storage_name']))) {
            http_response_code(404);
            echo 'Página não encontrada.';
            return;
        }
        try {
            $f = $this->handouts->fileOf($h, $kind);
        } catch (\Throwable $e) {
            http_response_code(503);
            echo 'Arquivo indisponível no momento. Avise o suporte da Clyo Nutri.';
            return;
        }
        $download = $kind === 'pdf' && !empty($_GET['baixar']);
        if ($kind === 'pdf') {
            [$clinicId, $userId] = $this->who();
            try {
                (new AuditLogger($this->db))->log($download ? 'ACADEMY_HANDOUT_DOWNLOADED' : 'ACADEMY_HANDOUT_OPENED', 'academy_handouts', (string)(int)$h['id'],
                    ['clinic_id' => $clinicId, 'user_id' => $userId]);
            } catch (\Throwable $e) {
                // a entrega não depende da auditoria
            }
        }
        MaterialService::send($f, $download, true);
    }

    /** POST /academy/laminas/{id}/favoritar */
    public function toggleHandoutFavorite(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $id = (int)($params['id'] ?? 0);
        $collection = is_string($_POST['collection_name'] ?? null) ? $_POST['collection_name'] : 'Geral';
        try {
            if (!AcademyHandoutService::ready($this->db)) {
                throw new \DomainException('Lâmina não encontrada.');
            }
            $saved = $this->handouts->toggleFavorite($clinicId, $userId, $id, $collection);
            $this->flash('success', $saved ? 'Lâmina salva na sua coleção.' : 'Lâmina removida da sua coleção.');
        } catch (\DomainException $e) {
            $this->flash('error', $e->getMessage());
        }
        $back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
        $this->redirect(self::safeBack($back, '/academy/laminas/' . $id));
    }

    /** GET /academy/artigo/{id} */
    public function articleDetail(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $id = (int)($params['id'] ?? 0);
        $article = $this->articles->getPublishedById($id);
        if (!$article) {
            $this->notFound('library');
            return;
        }
        $this->render('article_detail', (string)$article['title'] . ' — Clyo Academy', [
            'article' => $article, 'evidenceSheet' => $this->evidence->getPublishedByArticleId($id), 'citations' => $this->articles->formatCitations($article),
            'isFavorite' => $this->favorites->isFavorite($clinicId, $userId, $id), 'collections' => $this->favorites->getUserCollections($clinicId, $userId),
            'subTab' => 'library',
        ]);
    }

    /** POST /academy/artigo/{id}/favoritar */
    public function toggleFavorite(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $id = (int)($params['id'] ?? 0);
        $collection = is_string($_POST['collection_name'] ?? null) ? $_POST['collection_name'] : 'Geral';
        try {
            $saved = $this->favorites->toggleFavorite($clinicId, $userId, $id, $collection);
            $this->flash('success', $saved ? 'Estudo salvo na sua coleção.' : 'Estudo removido da sua coleção.');
        } catch (\DomainException $e) {
            $this->flash('error', $e->getMessage());
        }
        $back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
        $this->redirect(self::safeBack($back, '/academy/artigo/' . $id));
    }

    /** GET /academy/evidencias — artigos com ficha publicada */
    public function evidenceDesk(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $this->render('evidence_desk', 'Clyo Academy — Fichas de leitura crítica', [
            'results' => $this->articles->search(['has_evidence_sheet' => true, 'limit' => 50]),
            'favoriteIds' => array_column($this->favorites->listFavorites($clinicId, $userId), 'id'), 'subTab' => 'evidence',
        ]);
    }

    /** GET /academy/colecao */
    public function myCollection(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        $sel = is_string($_GET['collection'] ?? null) ? $_GET['collection'] : null;
        $on = AcademyHandoutService::ready($this->db);
        $collections = $this->favorites->getUserCollections($clinicId, $userId);
        if ($on) {
            $collections = array_values(array_unique(array_merge($collections, $this->handouts->collections($clinicId, $userId))));
            sort($collections);
        }
        $this->render('my_collection', 'Clyo Academy — Minha coleção', [
            'favorites' => $this->favorites->listFavorites($clinicId, $userId, $sel), 'collections' => $collections,
            'handoutFavorites' => $on ? $this->handouts->listFavorites($clinicId, $userId, $sel) : [],
            'selectedCollection' => $sel, 'subTab' => 'collection',
        ]);
    }

    /** GET /academy/publicacoes */
    public function publications(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        $this->render('publications', 'Clyo Academy — Publicações', [
            'published' => $this->publishing->listPublished(30), 'subTab' => 'publications',
        ]);
    }

    /**
     * GET /academy/publicacoes/nova, GET /academy/publicacoes/{id}/editar, POST /academy/publicacoes e POST /academy/publicacoes/{id}.
     * Rotas antigas de escrita da clínica: recusadas no servidor (403) e auditadas. Nada é gravado.
     */
    public function authoringDenied(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        [$clinicId, $userId] = $this->who();
        try {
            $table = str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/academy/laminas') || !empty($params['handout']) ? 'academy_handouts' : 'academy_publications';
            (new AuditLogger($this->db))->log('ACADEMY_AUTHORING_DENIED', $table, isset($params['id']) ? (string)(int)$params['id'] : null, [
                'clinic_id' => $clinicId, 'user_id' => $userId, 'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            ]);
        } catch (\Throwable $e) {
            // a recusa não depende da auditoria
        }
        http_response_code(403);
        $this->render('read_only', 'Somente leitura — Clyo Academy', ['subTab' => 'publications']);
    }

    /** GET /academy/publicacoes/{id} */
    public function publicationDetail(array $params = []): void
    {
        if (!$this->on()) {
            return;
        }
        $pub = $this->publishing->getPublished((int)($params['id'] ?? 0));
        if (!$pub) {
            $this->notFound('publications');
            return;
        }
        $this->render('publication_detail', (string)$pub['title'] . ' — Clyo Academy', [
            'pub' => $pub, 'subTab' => 'publications',
        ]);
    }

    /** GET /api/v1/academy/search */
    public function searchApi(array $params = []): void
    {
        if (!self::enabled($this->db)) {
            $this->json(['error' => 'Não encontrado.'], 404);
            return;
        }
        $r = $this->articles->search(['q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 'limit' => 15]);
        $r['items'] = array_map(static fn($a) => array_intersect_key($a, array_flip(['id', 'title', 'authors', 'journal', 'publication_year', 'study_design', 'doi', 'pmid', 'url', 'retraction_status', 'has_evidence_sheet'])), $r['items']);
        // v54.23: lâminas publicadas vêm junto, em lista separada (sem nome de arquivo interno nem hash)
        $h = ['total' => 0, 'items' => []];
        if (AcademyHandoutService::ready($this->db)) {
            $h = $this->handouts->search(['q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 'limit' => 15]);
            $h['items'] = array_map(static fn($x) => ['id' => (int)$x['id'], 'type' => 'lamina', 'slug' => $x['slug'], 'title' => $x['title'], 'summary' => $x['summary'],
                'tags' => AcademyHandoutService::splitTags((string)$x['tags']), 'source_citation' => $x['source_citation'], 'url' => '/academy/laminas/' . (int)$x['id'],
                'pdf_url' => '/academy/laminas/' . (int)$x['id'] . '/arquivo', 'has_preview' => !empty($x['preview_storage_name'])], $h['items']);
        }
        $r['handouts'] = $h;
        $this->json($r);
    }

    /** GET /api/v1/academy/protocols/{code} */
    public function protocolEvidenceApi(array $params = []): void
    {
        if (!self::enabled($this->db)) {
            $this->json(['error' => 'Não encontrado.'], 404);
            return;
        }
        $code = (string)($params['code'] ?? '');
        $articles = $this->articles->getArticlesForProtocol($code);
        $this->json(['protocol_code' => $code, 'count' => count($articles), 'articles' => $articles]);
    }

    /** Caminho de volta só se for interno da Academy (evita redirecionamento aberto). */
    public static function safeBack(string $back, string $default): string
    {
        return preg_match('#^/academy(?:/[A-Za-z0-9/_\-]*)?(?:\?[A-Za-z0-9=&%_.+\-]*)?$#', $back) ? $back : $default;
    }

    private function on(): bool
    {
        if (self::enabled($this->db)) {
            return true;
        }
        http_response_code(404);
        echo 'Página não encontrada.';
        return false;
    }

    /** @return array{0:int,1:int} */
    private function who(): array
    {
        return [(int)TenantContext::getClinicId(), (int)TenantContext::getUserId()];
    }

    private function notFound(string $tab): void
    {
        http_response_code(404);
        $this->render('not_found', 'Não encontrado — Clyo Academy', ['subTab' => $tab]);
    }

    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['academy_flash'] = ['type' => $type, 'message' => $message];
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
        $flash = $_SESSION['academy_flash'] ?? null;
        unset($_SESSION['academy_flash']);
        $csrfToken = Csrf::token();
        $handoutsTab = AcademyHandoutService::ready($this->db);
        extract($vars, EXTR_SKIP);
        $activeNav = 'academy';
        ob_start();
        require __DIR__ . '/../../../views/academy/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
