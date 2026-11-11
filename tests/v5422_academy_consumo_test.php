<?php
/**
 * v54.22 Clyo Academy só para consumo: a clínica pesquisa, lê e favorita; publicar, editar, curar e arquivar é da equipe
 * Clyo Nutri no console. Dados sintéticos de TESTE (DOIs 10.99999, prefixo inexistente). Duas clínicas. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5422.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\AcademyController as AC;
use ClyoNutri\Core\Security\PlatformPolicy;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\Academy\AcademyArticleService;
use ClyoNutri\Domain\Academy\AcademyFavoriteService;
use ClyoNutri\Domain\Academy\AcademyPublishingService as P;

$root = dirname(__DIR__);
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
$call = static function (callable $fn, array $params = [], string $method = 'GET', array $get = [], array $post = []): array {
    $_SERVER['REQUEST_METHOD'] = $method; $_GET = $get; $_POST = $post;
    http_response_code(200);
    ob_start();
    try { $fn($params); } finally { $out = (string)ob_get_clean(); }
    return [(int)http_response_code(), $out];
};
$art = fn(string $n, array $o = []) => $o + ['title' => "Estudo TESTE $n", 'authors' => 'Silva, Ana; Souza, Bruno', 'journal' => 'Revista TESTE', 'publication_year' => '2024',
    'doi' => "10.99999/consumo.$n", 'pmid' => '', 'url' => "https://doi.org/10.99999/consumo.$n", 'study_design' => 'rct', 'access_type' => 'publisher_link',
    'source' => 'crossref', 'retrieved_at' => date('Y-m-d'), 'condition_tags' => 'teste'];
$audits = static fn(string $action): int => (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = " . $pdo->quote($action))->fetchColumn();

fwrite(STDOUT, "== migração 84\n");
$cols = array_column($pdo->query('PRAGMA table_info(academy_articles)')->fetchAll(PDO::FETCH_ASSOC), 'name');
T::ok(in_array('status', $cols, true), 'academy_articles ganhou a coluna status');
T::ok(AC::enabled($pdo) && (string)PlatformSettings::get('feature_flag_academy', '0', $pdo) === '1', 'chave feature_flag_academy ligada');
require_once $root . '/database/migrations/2026_11_03_000084_academy_platform_curation.php';
(new AcademyPlatformCuration())->up($pdo); (new AcademyPlatformCuration())->up($pdo);
T::ok((int)$pdo->query("SELECT COUNT(*) FROM platform_settings WHERE setting_key = 'feature_flag_academy'")->fetchColumn() === 1, 'migração idempotente (rodada de novo, uma linha só na chave)');
$legacy = new PDO('sqlite::memory:'); $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec("CREATE TABLE academy_articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(500) NOT NULL)");
$legacy->exec("CREATE TABLE platform_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key VARCHAR(100) UNIQUE, setting_value TEXT, description TEXT, created_at DATETIME, updated_at DATETIME)");
$legacy->exec("INSERT INTO academy_articles (title) VALUES ('Artigo TESTE já existente')");
$legacy->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('feature_flag_academy', '0')");
(new AcademyPlatformCuration())->up($legacy);
T::ok($legacy->query('SELECT status FROM academy_articles')->fetchColumn() === 'published', 'artigo que já existia continua na biblioteca (published)');
T::ok($legacy->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'feature_flag_academy'")->fetchColumn() === '1', 'chave desligada da v54.20/21 passa a 1');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM academy_articles")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM academy_publications")->fetchColumn() === 0, 'sem conteúdo semeado');

$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$nA = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner'); $nA2 = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist'); $nB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
$team1 = Fx::user($pdo, $cA, 'Equipe TESTE 1', 'owner', null, null, 'superadmin'); $team2 = Fx::user($pdo, $cA, 'Equipe TESTE 2', 'owner', null, null, 'superadmin');

fwrite(STDOUT, "== equipe Clyo Nutri: cadastra, publica, edita e arquiva\n");
$as = new AcademyArticleService($pdo);
$a1 = $as->register($art('1'), $team1['user_id']); $a2 = $as->register($art('2'), $team1['user_id']); $a3 = $as->register($art('3'), $team1['user_id']);
T::ok($as->getById($a1)['status'] === 'draft', 'artigo cadastrado entra como rascunho');
T::ok($as->setStatus($a1, 'published') === 'draft' && $as->getById($a1)['status'] === 'published', 'publicar devolve a situação anterior');
$as->setStatus($a2, 'published');
T::throws(fn() => $as->setStatus($a1, 'published'), 'publicar de novo o que já está publicado: recusado');
T::throws(fn() => $as->setStatus($a1, 'qualquer'), 'situação fora da lista: recusada');
$as->update($a2, $art('2', ['title' => 'Estudo TESTE 2 corrigido']));
T::ok($as->getById($a2)['title'] === 'Estudo TESTE 2 corrigido', 'equipe corrige os dados do artigo');
T::throws(fn() => $as->update($a2, $art('2', ['doi' => '10.99999/consumo.1', 'url' => 'https://doi.org/10.99999/consumo.1'])), 'correção com DOI de outro artigo: recusada', 'já está no acervo');
$ps = new P($pdo);
$txt = ['author_name' => 'Autora TESTE', 'author_crn' => 'CRN-3 12345', 'title' => 'Síntese TESTE', 'category' => 'practical_review', 'summary' => 'Resumo TESTE',
    'body_markdown' => 'Texto TESTE.', 'references_text' => 'Referência TESTE. doi:10.99999/consumo.1', 'conflict_of_interest_disclosure' => 'Sem conflitos (TESTE).'];
$pub = $ps->saveTeamDraft($team1['user_id'], $txt); $ps->submitTeam($pub);
T::throws(fn() => $ps->review($pub, $team1['user_id'], 'approve', null), 'quatro olhos: quem escreveu não aprova', 'própria');
$ps->review($pub, $team2['user_id'], 'approve', null);
$draftPub = $ps->saveTeamDraft($team1['user_id'], ['title' => 'Rascunho TESTE'] + $txt);
T::ok($ps->getById($pub)['status'] === 'published' && (int)$ps->getById($pub)['clinic_id'] === P::PLATFORM_CLINIC, 'publicação da equipe aprovada por outra pessoa');
T::ok(PlatformPolicy::can('superadmin', 'settings.manage'), 'superadmin pode curar (settings.manage)');
foreach (['admin_operacional', 'support_agent', 'finance_admin', 'security_auditor', 'nutritionist', 'owner'] as $r) {
    T::ok(!PlatformPolicy::can($r, 'settings.manage'), "papel $r não cura nem publica");
}
$adm = file_get_contents($root . '/src/Application/Controllers/AdminController.php');
preg_match('/function academyAction\(.*?\n    }\n/s', $adm, $m); $body = $m[0] ?? '';
T::ok(str_contains($body, "getAuthorizedUser('settings.manage')") && str_contains($body, 'Csrf::validate'), 'ações do console exigem settings.manage e CSRF');
foreach (['ACADEMY_ARTICLE_REGISTERED', 'ACADEMY_ARTICLE_UPDATED', 'ACADEMY_ARTICLE_PUBLISHED', 'ACADEMY_ARTICLE_ARCHIVED', 'ACADEMY_PUBLICATION_DRAFTED', 'ACADEMY_PUBLICATION_UPDATED', 'ACADEMY_PUBLICATION_SUBMITTED', 'ACADEMY_PUBLICATION_ARCHIVED'] as $ev) {
    T::ok(str_contains($body, "'$ev'") || str_contains($body, $ev), "console audita $ev");
}

fwrite(STDOUT, "== clínica: só publicado\n");
Fx::act($nA);
$ac = new AC($pdo);
T::ok($as->search(['q' => 'TESTE'])['total'] === 2, 'busca devolve só os 2 publicados (rascunho fora)');
[$code, $html] = $call([$ac, 'index'], [], 'GET', ['q' => 'TESTE']);
T::ok($code === 200 && str_contains($html, 'Estudo TESTE 1') && str_contains($html, 'Estudo TESTE 2 corrigido') && !str_contains($html, 'Estudo TESTE 3'), 'biblioteca pesquisa e lista só publicados');
[$code, $html] = $call([$ac, 'articleDetail'], ['id' => $a1]);
T::ok($code === 200 && str_contains($html, 'Estudo TESTE 1'), 'clínica lê artigo publicado');
[$code] = $call([$ac, 'articleDetail'], ['id' => $a3]);
T::ok($code === 404, 'artigo em rascunho: 404 para a clínica');
[$code] = $call([$ac, 'publicationDetail'], ['id' => $pub]);
T::ok($code === 200, 'clínica lê publicação publicada');
[$code] = $call([$ac, 'publicationDetail'], ['id' => $draftPub]);
T::ok($code === 404, 'publicação em rascunho: 404 para a clínica');
[$code, $html] = $call([$ac, 'publications']);
T::ok(str_contains($html, 'Síntese TESTE') && !str_contains($html, 'Rascunho TESTE'), 'lista de publicações só com publicadas');

fwrite(STDOUT, "== clínica: favoritar\n");
[$code] = $call([$ac, 'toggleFavorite'], ['id' => $a1], 'POST', [], ['collection_name' => 'Pasta TESTE', 'back' => '/academy']);
$fv = new AcademyFavoriteService($pdo);
T::ok($fv->isFavorite($cA, $nA['user_id'], $a1), 'clínica favorita artigo publicado');
T::ok($fv->getUserCollections($cA, $nA['user_id']) === ['Pasta TESTE'] || in_array('Pasta TESTE', $fv->getUserCollections($cA, $nA['user_id']), true), 'pasta escolhida ao favoritar');
T::throws(fn() => $fv->toggleFavorite($cA, $nA['user_id'], $a3), 'favoritar rascunho: recusado');
T::ok(!$fv->isFavorite($cA, $nA2['user_id'], $a1) && !$fv->isFavorite($cB, $nB['user_id'], $a1) && !$fv->isFavorite($cB, $nA['user_id'], $a1), 'favorito isolado por usuário e por clínica');
$fv->toggleFavorite($cB, $nB['user_id'], $a2);
T::ok(count($fv->listFavorites($cA, $nA['user_id'])) === 1 && count($fv->listFavorites($cB, $nB['user_id'])) === 1 && $fv->listFavorites($cB, $nB['user_id'])[0]['id'] == $a2, 'cada um vê só a própria coleção');
[$code, $html] = $call([$ac, 'myCollection']);
T::ok(str_contains($html, 'Estudo TESTE 1') && !str_contains($html, 'Estudo TESTE 2 corrigido'), 'página da coleção mostra só os favoritos de quem está logado');
$as->setStatus($a1, 'archived');
T::ok(count($fv->listFavorites($cA, $nA['user_id'])) === 0 && $as->getPublishedById($a1) === null, 'artigo arquivado some da coleção e da leitura');
$as->setStatus($a1, 'published');
T::ok(count($fv->listFavorites($cA, $nA['user_id'])) === 1, 'publicado de novo, volta para a coleção');
$call([$ac, 'toggleFavorite'], ['id' => $a1], 'POST', [], ['back' => '/academy']);
T::ok(!$fv->isFavorite($cA, $nA['user_id'], $a1), 'clínica desfavorita');

fwrite(STDOUT, "== clínica: não escreve\n");
$before = (int)$pdo->query('SELECT COUNT(*) FROM academy_publications')->fetchColumn();
$denied = $audits('ACADEMY_AUTHORING_DENIED');
foreach ([['GET', []], ['POST', []], ['GET', ['id' => $pub]], ['POST', ['id' => $pub]]] as [$meth, $prm]) {
    [$code, $html] = $call([$ac, 'authoringDenied'], $prm, $meth, [], $txt);
    T::ok($code === 403 && str_contains($html, 'data-testid="academy-read-only"'), "$meth " . ($prm ? 'com id' : 'sem id') . ': 403 e aviso de somente leitura');
}
T::ok((int)$pdo->query('SELECT COUNT(*) FROM academy_publications')->fetchColumn() === $before && $ps->getById($pub)['title'] === 'Síntese TESTE', 'nada gravado nem alterado');
T::ok($audits('ACADEMY_AUTHORING_DENIED') === $denied + 4, 'cada tentativa fica na auditoria');
$methods = array_map(fn($m) => $m->getName(), (new ReflectionClass(AC::class))->getMethods(ReflectionMethod::IS_PUBLIC));
T::ok(!array_intersect($methods, ['newPublication', 'savePublication', 'editPublication', 'register', 'publish']), 'controlador da clínica não tem ação de escrita');
$routes = file_get_contents($root . '/public/index.php');
preg_match_all('#\$router->post\(\'(/academy[^\']*)\',\s*\$requireAuth\(\[\$academyCtrl,\s*\'(\w+)\'\]\)#', $routes, $rm, PREG_SET_ORDER);
$bad = array_filter($rm, fn($r) => !in_array($r[2], ['toggleFavorite', 'toggleHandoutFavorite', 'authoringDenied'], true));
T::ok(count($rm) >= 3 && !$bad, 'todo POST de /academy é favoritar ou recusa (' . count($rm) . ' rotas)');
T::ok(!is_file($root . '/views/academy/new_publication.php'), 'formulário de nova publicação da clínica removido');
$views = '';
foreach (glob($root . '/views/academy/*.php') as $f) { $views .= file_get_contents($f); }
T::ok(!preg_match('#action="/academy/publicacoes#', $views) && !str_contains($views, 'Nova publicação') && !str_contains($views, 'Nova Publicação') && !str_contains($views, '/editar'), 'telas da clínica sem botão ou formulário de escrita');

fwrite(STDOUT, "== chave desligada\n");
$pdo->exec("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'feature_flag_academy'"); PlatformSettings::clearCache();
[$code] = $call([$ac, 'authoringDenied'], [], 'POST');
[$code2] = $call([$ac, 'index']);
T::ok($code === 404 && $code2 === 404, 'com a chave desligada, tudo responde 404');
$pdo->exec("UPDATE platform_settings SET setting_value = '1' WHERE setting_key = 'feature_flag_academy'"); PlatformSettings::clearCache();

fwrite(STDOUT, "== textos\n");
$all = $views;
foreach (['views/admin/academy.php', 'views/admin/academy_artigo.php', 'views/admin/academy_publicacao.php'] as $f) { $all .= file_get_contents($root . '/' . $f); }
T::ok(!preg_match('/NI2-\d+/', strip_tags(preg_replace('/<\?php.*?\?>/s', '', $all))), 'nenhum código NI2 visível nas telas da Academy');
T::ok(!preg_match('/\son[a-z]+\s*=\s*"/i', $all), 'sem manipulador inline (CSP)');
T::ok(stripos($all, 'revisão de advogado') === false && stripos($all, 'revisao de advogado') === false, 'sem menção a revisão de advogado');
T::done();
