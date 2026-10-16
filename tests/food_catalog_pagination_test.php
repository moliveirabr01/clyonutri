<?php

declare(strict_types=1);

/**
 * Paginação da Base de Alimentos: serviço (dataset ~594) + HTTP real.
 * Isolamento de clínica, clamp de page/per_page, filtros, busca + query string.
 */
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\CatalogSeeder;
use ClyoNutri\Domain\Food\FoodCatalogService;

echo "Paginação Base de Alimentos\n";
$fx = aud_fixture();
$db = $fx['db'];

(new CatalogSeeder($db))->run();
$db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, is_custom) VALUES (?,?,?,?,10,1,1,1,1)")
    ->execute([$fx['clinicA'], 'Alimento Particular Alfa', 'Personalizado', 'CUSTOM']);
$db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, is_custom) VALUES (?,?,?,?,10,1,1,1,1)")
    ->execute([$fx['clinicB'], 'Segredo Particular Beta', 'Personalizado', 'CUSTOM']);

TenantContext::set($fx['clinicA'], ['id' => $fx['userA'], 'name' => 'Dra. Alfa', 'clinic_id' => $fx['clinicA']]);
$svc = new FoodCatalogService($db);

$globals = (int)$db->query('SELECT COUNT(*) FROM foods WHERE clinic_id IS NULL')->fetchColumn();
$ownStmt = $db->prepare('SELECT COUNT(*) FROM foods WHERE clinic_id = ?');
$ownStmt->execute([$fx['clinicA']]);
$ownClinic = (int)$ownStmt->fetchColumn();
$expectedTotal = $globals + $ownClinic;
aud_check($globals >= 594, "dataset tipo TACO: {$globals} globais (≥594)");
aud_check($ownClinic >= 2, "clínica A tem ≥2 alimentos próprios (fixture + inserido) ({$ownClinic})");

$page1 = $svc->paginate(['page' => 1, 'per_page' => 50]);
aud_check($page1['total'] === $expectedTotal, "total = globais + própria clínica ({$page1['total']})");
aud_check(count($page1['foods']) === 50, 'página 1 com per_page=50 entrega 50 linhas');
aud_check($page1['from'] === 1 && $page1['to'] === 50, 'Mostrando 1–50');
$pages = $page1['pages'];
aud_check($pages === (int)ceil($expectedTotal / 50), "pages = ceil(total/50) = {$pages}");

$last = $svc->paginate(['page' => $pages, 'per_page' => 50]);
aud_check($last['page'] === $pages, 'última página acessível');
aud_check(count($last['foods']) === ($expectedTotal - 50 * ($pages - 1)), 'última página com resto correto');
aud_check($last['to'] === $expectedTotal && $last['from'] === ($expectedTotal - count($last['foods']) + 1), 'from/to da última página');

$clamped = $svc->paginate(['page' => 9999, 'per_page' => 50]);
aud_check($clamped['page'] === $pages, 'page fora do intervalo é limitada à última');

$badPer = $svc->paginate(['page' => 1, 'per_page' => 7]);
aud_check($badPer['per_page'] === 50, 'per_page inválido cai no padrão 50');

$only25 = $svc->paginate(['page' => 1, 'per_page' => 25]);
aud_check($only25['per_page'] === 25 && count($only25['foods']) === 25, 'per_page=25 permitido');
$only100 = $svc->paginate(['page' => 1, 'per_page' => 100]);
aud_check($only100['per_page'] === 100 && count($only100['foods']) === 100, 'per_page=100 permitido');

$cat = 'Cereais e derivados';
$byCat = $svc->paginate(['category' => $cat, 'per_page' => 100]);
aud_check($byCat['total'] > 0 && $byCat['total'] < $expectedTotal, "filtro por grupo TACO '{$cat}' reduz o total ({$byCat['total']})");
foreach ($byCat['foods'] as $f) {
    if (($f['category'] ?? '') !== $cat) {
        aud_check(false, 'filtro category vazou outra categoria');
        break;
    }
}
aud_check(true, 'todas as linhas do filtro category batem com o grupo');

$tacoOnly = $svc->paginate(['source' => 'TACO', 'per_page' => 100]);
aud_check($tacoOnly['total'] === $globals, 'fonte TACO = só globais');
$clinicOnly = $svc->paginate(['source' => 'clinic', 'per_page' => 50]);
$clinicNames = array_column($clinicOnly['foods'], 'name');
aud_check($clinicOnly['total'] === $ownClinic && in_array('Alimento Particular Alfa', $clinicNames, true) && !in_array('Segredo Particular Beta', $clinicNames, true), 'fonte Minha clínica = só da própria');

$search = $svc->paginate(['q' => 'feijão', 'per_page' => 25]);
aud_check($search['total'] >= 10, "busca 'feijão' encontra vários ({$search['total']})");
aud_check($search['pages'] >= 1, 'busca + paginação calcula pages');
$accent = $svc->paginate(['q' => 'Acai']);
aud_check($accent['total'] >= 1, "busca sem acento encontra açaí ({$accent['total']})");

// Ordenação estável name, id
$ordered = true;
$prev = null;
for ($p = 1; $p <= min(3, $pages); $p++) {
    $chunk = $svc->paginate(['page' => $p, 'per_page' => 50]);
    foreach ($chunk['foods'] as $f) {
        $key = mb_strtolower($f['name']) . "\0" . $f['id'];
        if ($prev !== null && $key < $prev) {
            $ordered = false;
            break 2;
        }
        $prev = $key;
    }
}
aud_check($ordered, 'ordenação estável por name, id');

// Isolamento: Segredo Beta nunca aparece
$leak = false;
for ($p = 1; $p <= $pages; $p++) {
    foreach ($svc->paginate(['page' => $p, 'per_page' => 50])['foods'] as $f) {
        if (($f['name'] ?? '') === 'Segredo Particular Beta' || (int)($f['clinic_id'] ?? 0) === $fx['clinicB']) {
            $leak = true;
            break 2;
        }
    }
}
aud_check(!$leak, 'alimento privado de outra clínica nunca aparece');

// search() legado intacto
$legacy = $svc->search('arroz');
aud_check(count($legacy) >= 1 && count($legacy) <= 50, 'search() legado continua ≤50 e encontra resultados');

$cats = $svc->listCategories();
aud_check(count($cats) >= 15 && in_array('Cereais e derivados', $cats, true), 'listCategories traz grupos TACO');

// ---------------------------------------------------------------- HTTP
$port = 18100 + random_int(0, 800);
$srv = aud_http_server($fx['path'], $port);
$base = $srv['base'];
$jar = tempnam(sys_get_temp_dir(), 'pagjar');
[, , $html] = aud_http('GET', "{$base}/login", null, [], $jar);
preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m);
[$ls] = aud_http('POST', "{$base}/login", http_build_query(['_csrf_token' => $m[1] ?? '', 'email' => 'alfa@aud.test', 'password' => 'SenhaAlfa@2026']), [], $jar);
aud_check($ls === 302, 'HTTP: login do profissional Alfa');

[$st, , $body] = aud_http('GET', "{$base}/alimentos", null, [], $jar);
aud_check($st === 200, 'HTTP: GET /alimentos = 200');
aud_check(preg_match('/Mostrando 1\x{2013}50 de ' . $expectedTotal . '/u', $body) === 1, 'HTTP: texto Mostrando 1–50 de N');
aud_check(str_contains($body, 'aria-label="Paginação da base de alimentos"'), 'HTTP: nav com aria-label');
aud_check(str_contains($body, 'aria-current="page"'), 'HTTP: página atual com aria-current');
aud_check(substr_count($body, '<tr>') >= 51, 'HTTP: cabeçalho + ~50 linhas na tabela'); // thead tr + 50
aud_check(!str_contains($body, 'Segredo Particular Beta'), 'HTTP: sem vazamento do alimento da clínica B');
aud_check(str_contains($body, 'name="category"') && str_contains($body, 'name="source"') && str_contains($body, 'name="per_page"'), 'HTTP: filtros category/source/per_page');

[$st2, , $body2] = aud_http('GET', "{$base}/alimentos?page={$pages}&per_page=50", null, [], $jar);
aud_check($st2 === 200 && (str_contains($body2, "Página {$pages} de {$pages}") || str_contains($body2, "Página {$pages} de")), 'HTTP: última página');
aud_check(str_contains($body2, 'Primeira') && str_contains($body2, 'Anterior'), 'HTTP: links Primeira/Anterior na última');

[$st3, , $body3] = aud_http('GET', "{$base}/alimentos?q=feij%C3%A3o&per_page=25&page=1", null, [], $jar);
aud_check($st3 === 200 && str_contains($body3, 'name="q"') && str_contains($body3, 'feijão'), 'HTTP: busca preservada no formulário');
aud_check(str_contains($body3, 'per_page') && (str_contains($body3, 'value="25" selected') || str_contains($body3, "value=\"25\" selected")), 'HTTP: per_page=25 preservado');

[$st4, , $body4] = aud_http('GET', "{$base}/alimentos?page=9999&per_page=50", null, [], $jar);
aud_check($st4 === 200 && str_contains($body4, "Página {$pages} de {$pages}"), 'HTTP: page=9999 limitada');

[$st5, , $body5] = aud_http('GET', "{$base}/alimentos?source=clinic", null, [], $jar);
aud_check($st5 === 200 && str_contains($body5, 'Alimento Particular Alfa') && !str_contains($body5, 'Segredo Particular Beta'), 'HTTP: filtro Minha clínica');
aud_check(preg_match('/Mostrando 1\x{2013}' . $ownClinic . ' de ' . $ownClinic . '/u', $body5) === 1, 'HTTP: Mostrando só os da clínica');

// CSP: sem handlers inline na página
aud_check(!preg_match('/\s(on\w+|href\s*=\s*["\']javascript:)/i', $body), 'HTTP: sem onclick/javascript: (CSP)');

echo "\nPaginação Base de Alimentos: OK\n";
exit(0);
