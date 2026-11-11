<?php
/**
 * v54.23 Lâminas para pacientes na Clyo Academy. A equipe Clyo Nutri sobe, publica e arquiva no console; a clínica pesquisa,
 * filtra, vê a prévia, abre ou baixa o PDF e favorita. Dados sintéticos de TESTE. Duas clínicas. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5423.sqlite');
$hdir = sys_get_temp_dir() . '/clyonutri-v5423-laminas-' . getmypid();
putenv('ACADEMY_HANDOUTS_DIR=' . $hdir);
putenv('EXAM_FILES_ANTIVIRUS=off');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\AcademyController as AC;
use ClyoNutri\Domain\Academy\AcademyHandoutService as H;

$root = dirname(__DIR__);
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = []; }
$call = static function (callable $fn, array $params = [], string $method = 'GET', array $get = [], array $post = []): array {
    $_SERVER['REQUEST_METHOD'] = $method; $_GET = $get; $_POST = $post;
    http_response_code(200);
    ob_start();
    try { $fn($params); } finally { $out = (string)ob_get_clean(); }
    return [(int)http_response_code(), $out];
};
$audits = static fn(string $action): int => (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = " . $pdo->quote($action))->fetchColumn();
$tmp = sys_get_temp_dir() . '/clyonutri-v5423-src-' . getmypid();
@mkdir($tmp, 0700, true);
$pdfBytes = fn(string $t) => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n% $t\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$mk = function (string $name, string $bytes) use ($tmp): array { file_put_contents("$tmp/$name", $bytes); return ['tmp_name' => "$tmp/$name", 'name' => $name, 'error' => UPLOAD_ERR_OK]; };
$png = function (string $name) use ($tmp): array {
    $im = imagecreatetruecolor(60, 85); imagefill($im, 0, 0, imagecolorallocate($im, 240, 248, 240)); imagepng($im, "$tmp/$name"); imagedestroy($im);
    return ['tmp_name' => "$tmp/$name", 'name' => $name, 'error' => UPLOAD_ERR_OK];
};
$meta = fn(string $n, array $o = []) => $o + ['title' => "Lâmina TESTE $n", 'slug' => "lamina-teste-$n", 'summary' => "Resumo TESTE $n sobre comida de verdade.",
    'tags' => "teste, guia alimentar, tema-$n", 'source_citation' => 'BRASIL. Ministério da Saúde. Guia alimentar para a população brasileira. 2. ed. 2014 (TESTE).'];

fwrite(STDOUT, "== migração 85\n");
T::ok(H::ready($pdo), 'tabelas academy_handouts e academy_handout_favorites existem');
require_once $root . '/database/migrations/2026_11_03_000085_create_academy_handouts.php';
(new CreateAcademyHandouts())->up($pdo); (new CreateAcademyHandouts())->up($pdo);
T::ok(H::ready($pdo), 'migração idempotente (rodada mais duas vezes)');
T::ok((int)$pdo->query('SELECT COUNT(*) FROM academy_handouts')->fetchColumn() === 0, 'sem lâmina semeada');
$mig = file_get_contents($root . '/database/migrations/2026_11_03_000085_create_academy_handouts.php');
T::ok(stripos($mig, 'FOREIGN KEY') === false && stripos($mig, 'REFERENCES') === false, 'sem chave estrangeira');

$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$nA = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner'); $nA2 = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist'); $nB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
$team = Fx::user($pdo, $cA, 'Equipe TESTE', 'owner', null, null, 'superadmin');

fwrite(STDOUT, "== equipe: sobe, valida, publica\n");
$svc = new H($pdo);
T::ok(str_starts_with($svc->storageDir(), $hdir) && !str_contains(realpath($svc->storageDir()) ?: $svc->storageDir(), '/public'), 'armazenamento fora de public/');
$h1 = $svc->create($meta('1'), $mk('l1.pdf', $pdfBytes('um')), $png('l1.png'), $team['user_id']);
$h2 = $svc->create($meta('2', ['tags' => 'teste, hidratação']), $mk('l2.pdf', $pdfBytes('dois')), null, $team['user_id']);
$h3 = $svc->create($meta('3', ['title' => 'Rascunho TESTE invisível']), $mk('l3.pdf', $pdfBytes('tres')), null, $team['user_id']);
$r1 = $svc->getById($h1);
T::ok($r1['status'] === 'draft' && (int)$r1['created_by_user_id'] === $team['user_id'], 'lâmina entra como rascunho, com autor');
T::ok(is_file($hdir . '/' . $r1['storage_name']) && is_file($hdir . '/' . $r1['preview_storage_name']) && $r1['sha256'] === hash('sha256', $pdfBytes('um')), 'PDF e prévia gravados com nome aleatório e sha256');
T::ok(!preg_match('/l1|lamina/', (string)$r1['storage_name']), 'nome no disco não repete o nome enviado');
T::throws(fn() => $svc->create($meta('1'), $mk('x.pdf', $pdfBytes('x')), null, $team['user_id']), 'slug repetido: recusado', 'Já existe');
T::throws(fn() => $svc->create($meta('4'), $mk('x.pdf', 'texto qualquer, não é PDF'), null, $team['user_id']), 'arquivo que não é PDF: recusado');
T::throws(fn() => $svc->create($meta('4'), $png('falso.pdf'), null, $team['user_id']), 'imagem com extensão .pdf: recusada');
T::throws(fn() => $svc->create($meta('4'), $mk('x.pdf', $pdfBytes('x') . "/JavaScript (app.alert(1))\n/OpenAction 1 0 R\n"), null, $team['user_id']), 'PDF com JavaScript: recusado');
$big = $mk('grande.pdf', "%PDF-1.4\n" . str_repeat('A', H::MAX_PDF_BYTES) . "\n%%EOF\n");
T::throws(fn() => $svc->create($meta('4'), $big, null, $team['user_id']), 'PDF acima de 10 MB: recusado', '10 MB');
T::throws(fn() => $svc->create($meta('4'), $mk('x.pdf', $pdfBytes('x')), $mk('p.png', $pdfBytes('p')), $team['user_id']), 'prévia que não é imagem: recusada');
T::throws(fn() => $svc->create($meta('4', ['source_citation' => '']), $mk('x.pdf', $pdfBytes('x')), null, $team['user_id']), 'sem fonte: recusado', 'fonte');
T::throws(fn() => $svc->create($meta('4', ['slug' => 'Com Espaço']), $mk('x.pdf', $pdfBytes('x')), null, $team['user_id']), 'slug fora do padrão: recusado');
$msgs = '';
foreach ([fn() => $svc->create($meta('4'), $mk('x.pdf', 'nada'), null, 1)] as $f) { try { $f(); } catch (\Throwable $e) { $msgs .= $e->getMessage(); } }
T::ok(stripos($msgs, 'laudo') === false, 'mensagem de erro fala de lâmina, não de laudo (' . $msgs . ')');
T::ok((int)$pdo->query('SELECT COUNT(*) FROM academy_handouts')->fetchColumn() === 3 && count(glob($hdir . '/*')) === 4, 'recusas não deixam linha nem arquivo para trás');
T::ok($svc->setStatus($h1, 'published', $team['user_id']) === 'draft' && $svc->getById($h1)['status'] === 'published' && $svc->getById($h1)['published_at'] !== null, 'equipe publica (data de publicação gravada)');
$svc->setStatus($h2, 'published', $team['user_id']);
T::throws(fn() => $svc->setStatus($h1, 'published', $team['user_id']), 'publicar o que já está publicado: recusado');
T::throws(fn() => $svc->setStatus($h2, 'archived', $team['user_id'], 'x'), 'arquivar sem motivo: recusado');
$svc->update($h2, $meta('2', ['title' => 'Lâmina TESTE 2 revisada', 'tags' => 'teste, hidratação']), null, null, $team['user_id']);
T::ok($svc->getById($h2)['title'] === 'Lâmina TESTE 2 revisada', 'equipe corrige os dados');
$oldStore = $svc->getById($h2)['storage_name'];
$svc->update($h2, $meta('2', ['title' => 'Lâmina TESTE 2 revisada', 'tags' => 'teste, hidratação']), $mk('l2b.pdf', $pdfBytes('dois b')), null, $team['user_id']);
T::ok(!is_file($hdir . '/' . $oldStore) && $svc->getById($h2)['sha256'] === hash('sha256', $pdfBytes('dois b')), 'troca de PDF apaga o arquivo antigo');

fwrite(STDOUT, "== console: rotas e auditoria\n");
$adm = file_get_contents($root . '/src/Application/Controllers/AdminController.php');
preg_match('/function academyAction\(.*?\n    }\n/s', $adm, $m); $body = $m[0] ?? '';
T::ok(str_contains($body, "getAuthorizedUser('settings.manage')") && str_contains($body, 'Csrf::validate'), 'ações do console exigem settings.manage e CSRF');
foreach (['ACADEMY_HANDOUT_UPLOADED', 'ACADEMY_HANDOUT_UPDATED', 'ACADEMY_HANDOUT_PUBLISHED', 'ACADEMY_HANDOUT_ARCHIVED'] as $ev) {
    T::ok(str_contains($body, $ev), "console audita $ev");
}
preg_match('/function academyHandoutFile\(.*?\n    }\n/s', $adm, $m2);
T::ok(str_contains($m2[0] ?? '', "getAuthorizedUser('settings.read')"), 'arquivo de rascunho só pelo console (settings.read)');
$form = file_get_contents($root . '/views/admin/academy_lamina.php');
T::ok(str_contains($form, 'enctype="multipart/form-data"') && str_contains($form, 'accept="application/pdf') && str_contains($form, 'name="_csrf_token"'), 'formulário do console com upload multipart e CSRF');

fwrite(STDOUT, "== clínica: busca, filtro, leitura\n");
Fx::act($nA);
$ac = new AC($pdo);
$s = $svc->search(['q' => 'TESTE']);
T::ok($s['total'] === 2 && !in_array($h3, array_map('intval', array_column($s['items'], 'id')), true), 'busca devolve só as 2 publicadas');
T::ok($svc->search(['q' => 'hidratação'])['total'] === 1 && $svc->search(['q' => 'comida verdade'])['total'] === 2 && $svc->search(['q' => 'Ministério'])['total'] === 2, 'busca por etiqueta, palavras do resumo e fonte');
T::ok($svc->search(['tag' => 'hidratação'])['total'] === 1, 'filtro por tema');
[$code, $html] = $call([$ac, 'index'], [], 'GET', ['q' => 'TESTE']);
T::ok($code === 200 && str_contains($html, 'data-testid="academy-handout-results"') && str_contains($html, 'Lâmina TESTE 1') && !str_contains($html, 'Rascunho TESTE invisível'), 'busca da Academy em "Tudo" mostra lâminas publicadas');
[$code, $html] = $call([$ac, 'index'], [], 'GET', ['tipo' => 'laminas']);
T::ok($code === 200 && str_contains($html, 'data-testid="academy-type-filter"') && str_contains($html, 'value="laminas" selected') && substr_count($html, 'data-testid="academy-handout-card"') === 2 && !str_contains($html, 'estudos científicos indexados'), 'filtro Tipo = Lâminas lista só lâminas');
[$code, $html] = $call([$ac, 'index'], [], 'GET', ['tipo' => 'artigos', 'q' => 'TESTE']);
T::ok(!str_contains($html, 'academy-handout-results'), 'filtro Tipo = Artigos esconde as lâminas');
[$code, $html] = $call([$ac, 'index'], [], 'GET', ['tipo' => 'laminas', 'q' => 'nada-existe-xyz']);
T::ok(str_contains($html, 'Nenhuma lâmina encontrada'), 'busca sem resultado mostra aviso');
[$code, $html] = $call([$ac, 'handoutList']);
T::ok($code === 200 && str_contains($html, 'data-testid="academy-tab-handouts"') && str_contains($html, 'aria-current="page"'), 'aba Lâminas para pacientes');
[$code, $html] = $call([$ac, 'handoutDetail'], ['id' => $h1]);
T::ok($code === 200 && str_contains($html, 'academy-handout-detail') && str_contains($html, "/academy/laminas/$h1/previa") && str_contains($html, "/academy/laminas/$h1/arquivo?baixar=1"), 'página da lâmina com prévia, abrir e baixar');
[$code] = $call([$ac, 'handoutDetail'], ['id' => $h3]);
T::ok($code === 404, 'rascunho: 404 na página');
[$code] = $call([$ac, 'handoutFile'], ['id' => $h3]);
T::ok($code === 404, 'rascunho: 404 no arquivo');
[$code, $out] = $call([$ac, 'handoutFile'], ['id' => $h1]);
T::ok($code === 200 && $out === $pdfBytes('um'), 'clínica abre o PDF publicado (conteúdo confere)');
[$code, $out] = $call([$ac, 'handoutFile'], ['id' => $h1], 'GET', ['baixar' => '1']);
T::ok($code === 200 && $out === $pdfBytes('um') && $audits('ACADEMY_HANDOUT_DOWNLOADED') === 1 && $audits('ACADEMY_HANDOUT_OPENED') === 1, 'download funciona e abrir/baixar ficam na auditoria');
[$code, $out] = $call([$ac, 'handoutFile'], ['id' => $h1, 'kind' => 'preview']);
T::ok($code === 200 && str_starts_with($out, "\x89PNG"), 'prévia PNG servida');
[$code] = $call([$ac, 'handoutFile'], ['id' => $h2, 'kind' => 'preview']);
T::ok($code === 404, 'sem prévia: 404');
$api = static function () use ($call, $ac): array { [$c, $o] = $call([$ac, 'searchApi'], [], 'GET', ['q' => 'TESTE']); return json_decode($o, true) ?: []; };
$j = $api();
T::ok(($j['handouts']['total'] ?? 0) === 2 && ($j['handouts']['items'][0]['type'] ?? '') === 'lamina' && str_contains((string)($j['handouts']['items'][0]['pdf_url'] ?? ''), '/arquivo'), 'API de busca da Academy devolve lâminas');
// arquivo alterado no disco: não entrega
$r2 = $svc->getById($h2); file_put_contents($hdir . '/' . $r2['storage_name'], 'adulterado');
[$code] = $call([$ac, 'handoutFile'], ['id' => $h2]);
T::ok($code === 503, 'arquivo alterado no disco: 503, não entrega');
file_put_contents($hdir . '/' . $r2['storage_name'], $pdfBytes('dois b'));

fwrite(STDOUT, "== clínica: favoritar e isolamento\n");
$call([$ac, 'toggleHandoutFavorite'], ['id' => $h1], 'POST', [], ['back' => '/academy/laminas']);
T::ok($svc->isFavorite($cA, $nA['user_id'], $h1), 'clínica favorita lâmina publicada');
T::throws(fn() => $svc->toggleFavorite($cA, $nA['user_id'], $h3), 'favoritar rascunho: recusado');
$call([$ac, 'toggleHandoutFavorite'], ['id' => $h3], 'POST', [], []);
T::ok(!$svc->isFavorite($cA, $nA['user_id'], $h3), 'rota de favoritar não grava rascunho');
T::ok(!$svc->isFavorite($cA, $nA2['user_id'], $h1) && !$svc->isFavorite($cB, $nB['user_id'], $h1) && !$svc->isFavorite($cB, $nA['user_id'], $h1), 'favorito isolado por usuário e por clínica');
$svc->toggleFavorite($cB, $nB['user_id'], $h2);
T::ok(count($svc->listFavorites($cA, $nA['user_id'])) === 1 && (int)$svc->listFavorites($cB, $nB['user_id'])[0]['id'] === $h2, 'cada um vê só a própria coleção');
[$code, $html] = $call([$ac, 'myCollection']);
T::ok(str_contains($html, 'data-testid="academy-collection-handouts"') && str_contains($html, 'Lâmina TESTE 1') && !str_contains($html, 'Lâmina TESTE 2 revisada'), 'Minha coleção mostra a lâmina de quem está logado');
$svc->setStatus($h1, 'archived', $team['user_id'], 'Arquivada no TESTE');
T::ok(count($svc->listFavorites($cA, $nA['user_id'])) === 0 && $svc->getPublished($h1) === null, 'lâmina arquivada some da coleção e da leitura');
[$code] = $call([$ac, 'handoutFile'], ['id' => $h1]);
T::ok($code === 404, 'arquivada: PDF 404');
$svc->setStatus($h1, 'published', $team['user_id']);
T::ok(count($svc->listFavorites($cA, $nA['user_id'])) === 1, 'publicada de novo, volta para a coleção');

fwrite(STDOUT, "== clínica: não escreve\n");
$before = (int)$pdo->query('SELECT COUNT(*) FROM academy_handouts')->fetchColumn();
$denied = $audits('ACADEMY_AUTHORING_DENIED');
foreach ([['GET', '/academy/laminas/nova', []], ['POST', '/academy/laminas', []], ['GET', "/academy/laminas/$h1/editar", ['id' => $h1]], ['POST', "/academy/laminas/$h1", ['id' => $h1]]] as [$meth, $uri, $prm]) {
    $_SERVER['REQUEST_URI'] = $uri;
    [$code, $html] = $call([$ac, 'authoringDenied'], $prm, $meth, [], $meta('9'));
    T::ok($code === 403 && str_contains($html, 'data-testid="academy-read-only"'), "$meth $uri: 403");
}
T::ok((int)$pdo->query('SELECT COUNT(*) FROM academy_handouts')->fetchColumn() === $before && $audits('ACADEMY_AUTHORING_DENIED') === $denied + 4, 'nada gravado e cada tentativa auditada');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'ACADEMY_AUTHORING_DENIED' AND resource = 'academy_handouts'")->fetchColumn() === 4, 'auditoria aponta para academy_handouts');
$routes = file_get_contents($root . '/public/index.php');
preg_match_all('#\$router->post\(\'(/academy/laminas[^\']*)\',\s*\$requireAuth\(\[\$academyCtrl,\s*\'(\w+)\'\]\)#', $routes, $rm, PREG_SET_ORDER);
$bad = array_filter($rm, fn($r) => !in_array($r[2], ['toggleHandoutFavorite', 'authoringDenied'], true));
T::ok(count($rm) === 3 && !$bad, 'todo POST de /academy/laminas é favoritar ou recusa');
$views = '';
foreach (glob($root . '/views/academy/*.php') as $f) { $views .= file_get_contents($f); }
T::ok(!str_contains($views, 'type="file"') && !str_contains($views, '/admin/academy') && !str_contains($views, 'Nova lâmina'), 'telas da clínica sem upload nem link para o console');
T::ok(!str_contains(file_get_contents($root . '/src/Domain/Materials/MaterialService.php'), 'academy_handouts') && !str_contains(file_get_contents($root . '/views/academy/handout_detail.php'), '/materiais'), 'lâminas não entram no envio de materiais da v54.21');

fwrite(STDOUT, "== cabeçalhos do arquivo (código)\n");
$send = file_get_contents($root . '/src/Domain/Materials/MaterialService.php');
T::ok(str_contains($send, "X-Content-Type-Options: nosniff") && str_contains($send, "'attachment' : 'inline'"), 'envio com nosniff e inline/attachment');
T::ok(str_contains(file_get_contents($root . '/src/Application/Controllers/AcademyController.php'), 'MaterialService::send($f, $download, true)'), 'controlador usa o envio autenticado');

fwrite(STDOUT, "== importação\n");
$imp = $tmp . '/importar'; @mkdir($imp, 0700, true);
file_put_contents("$imp/a.pdf", $pdfBytes('imp a')); $png('x.png'); copy("$tmp/x.png", "$imp/a.png");
file_put_contents("$imp/lamina-teste-imp-b.pdf", $pdfBytes('imp b'));
file_put_contents("$imp/metadados.json", json_encode([
    ['type' => 'lamina', 'title' => 'Importada TESTE A', 'slug' => 'importada-teste-a', 'summary' => 'Resumo TESTE', 'tags' => ['teste', 'importação'],
        'source' => ['citation' => 'BRASIL. Ministério da Saúde. Guia TESTE. 2014.', 'pages' => 'p. 1'], 'files' => ['pdf' => 'a.pdf', 'preview' => 'a.png']],
    ['type' => 'lamina', 'title' => 'Importada TESTE B', 'slug' => 'lamina-teste-imp-b', 'source' => 'Fonte TESTE B, 2014.'],
    ['type' => 'artigo', 'title' => 'Ignorar'],
], JSON_UNESCAPED_UNICODE));
$env = 'DB_CONNECTION=sqlite DB_DATABASE=' . escapeshellarg(getenv('DB_DATABASE')) . ' TEST_DB_DATABASE=' . escapeshellarg(getenv('DB_DATABASE')) . ' ACADEMY_HANDOUTS_DIR=' . escapeshellarg($hdir) . ' EXAM_FILES_ANTIVIRUS=off APP_ENV=testing';
$cmd = "cd " . escapeshellarg($root) . " && $env php bin/importar_laminas_academy.php " . escapeshellarg($imp);
exec($cmd . ' 2>&1', $o0, $rc0);
T::ok($rc0 === 0 && (int)$pdo->query("SELECT COUNT(*) FROM academy_handouts WHERE slug LIKE 'importada%' OR slug = 'lamina-teste-imp-b'")->fetchColumn() === 0, 'sem --gravar só confere, não grava');
exec($cmd . ' --gravar 2>&1', $o1, $rc1);
$rows = $pdo->query("SELECT slug, status, preview_storage_name, source_citation FROM academy_handouts WHERE slug IN ('importada-teste-a', 'lamina-teste-imp-b') ORDER BY slug")->fetchAll(PDO::FETCH_ASSOC);
T::ok($rc1 === 0 && count($rows) === 2 && $rows[0]['status'] === 'draft' && $rows[1]['status'] === 'draft', 'importa como rascunho (' . implode(' | ', $o1) . ')');
T::ok($rows[0]['preview_storage_name'] !== null && str_contains((string)$rows[0]['source_citation'], 'p. 1'), 'prévia e páginas da fonte importadas');
exec($cmd . ' --gravar 2>&1', $o2, $rc2);
T::ok($rc2 === 0 && (int)$pdo->query("SELECT COUNT(*) FROM academy_handouts WHERE slug IN ('importada-teste-a', 'lamina-teste-imp-b')")->fetchColumn() === 2 && str_contains(implode("\n", $o2), '"ja_existiam":2'), 'rodar de novo não duplica (idempotente pelo slug)');
exec("cd " . escapeshellarg($root) . " && " . str_replace('APP_ENV=testing', 'APP_ENV=production', $env) . " php bin/importar_laminas_academy.php " . escapeshellarg($imp) . ' --gravar 2>&1', $o3, $rc3);
T::ok($rc3 === 3, 'com APP_ENV=production o comando se recusa');
T::ok(!is_dir($root . '/database/seeds') || !preg_grep('/lamina|handout/i', scandir($root . '/database/seeds')), 'sem seed de lâminas');

fwrite(STDOUT, "== textos\n");
$all = $views . file_get_contents($root . '/views/admin/academy.php') . file_get_contents($root . '/views/admin/academy_lamina.php') . file_get_contents($root . '/views/layouts/professional-shell.php');
T::ok(!preg_match('/NI2-\d+/', strip_tags(preg_replace('/<\?php.*?\?>/s', '', $all))), 'nenhum código NI2 visível');
T::ok(!preg_match('/\son[a-z]+\s*=\s*"/i', $all) && !preg_match('#<script(?![^>]*src=)#i', $views), 'sem manipulador inline nem script embutido (CSP)');
T::ok(stripos($all, 'revisão de advogado') === false, 'sem menção a revisão de advogado');
T::ok(str_contains(file_get_contents($root . '/views/layouts/professional-shell.php'), 'data-testid="header-academy-search"'), 'busca da Academy no topo do consultório');

exec('rm -rf ' . escapeshellarg($hdir) . ' ' . escapeshellarg($tmp));
T::done();
