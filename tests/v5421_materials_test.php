<?php
/**
 * v54.21 Materiais educativos (biblioteca da clínica e envio para pacientes, estilo WebDiet).
 * Arquivos gerados no teste (PDF mínimo, PNG 1x1); duas clínicas; dados só de TESTE. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5421.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\MaterialController as MC;
use ClyoNutri\Domain\Materials\MaterialService as M;
use ClyoNutri\Domain\Privacy\LgpdService;
use ClyoNutri\Core\Auth\TenantContext;

$dir = sys_get_temp_dir() . '/clyonutri-materials-' . getmypid();
$tmp = sys_get_temp_dir() . '/clyonutri-materials-in-' . getmypid();
@mkdir($tmp, 0700, true);
$mk = function (string $name, string $bytes) use ($tmp): array {
    $p = $tmp . '/' . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return ['name' => $name, 'tmp_name' => $p, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
};
$pdf = fn(string $txt = 'TESTE') => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n% $txt\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

echo "== migração 83\n";
$t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'educational_material%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
T::ok($t === ['educational_material_shares', 'educational_materials'], 'duas tabelas criadas');
require_once dirname(__DIR__) . '/database/migrations/2026_11_03_000083_create_educational_materials.php';
(new CreateEducationalMaterials())->up($pdo);
T::ok(true, 'migração idempotente');

$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$nA = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner'); $nB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
$rec = Fx::user($pdo, $cA, 'Recepção TESTE', 'receptionist', null);
$p1 = Fx::patient($pdo, $cA, 'Paciente TESTE Um'); $p2 = Fx::patient($pdo, $cA, 'Paciente TESTE Dois'); $pB = Fx::patient($pdo, $cB, 'Paciente TESTE B');
$svc = new M($pdo, $dir);

echo "== upload\n";
T::throws(fn() => $svc->upload($cA, $rec['user_id'], $mk('a.pdf', $pdf()), 'Guia TESTE', '', 'orientacao'), 'recepção não sobe material', 'titular');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], $mk('a.pdf', $pdf()), '', '', 'orientacao'), 'sem título: recusado', 'título');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], $mk('a.pdf', $pdf()), 'Guia TESTE', '', 'xyz'), 'categoria fora da lista: recusada', 'categoria');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], $mk('falso.pdf', '<html><script>alert(1)</script></html>'), 'Falso TESTE', '', 'outros'), 'HTML com extensão .pdf: recusado pelo conteúdo', 'Formato');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], $mk('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'), 'SVG TESTE', '', 'outros'), 'SVG recusado', 'Formato');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], ['name' => 'g.pdf', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE], 'Grande TESTE', '', 'outros'), 'acima do limite do servidor: mensagem de 10 MB', '10 MB');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], ['size' => M::MAX_BYTES + 1] + $mk('g.pdf', $pdf()), 'Grande TESTE', '', 'outros'), 'acima de 10 MB: recusado', '10 MB');
$m1 = $svc->upload($cA, $nA['user_id'], $mk('Guia "alimentar"\r\n.pdf', $pdf('um')), 'Guia alimentar TESTE', 'Leia com calma.', 'orientacao');
$m2 = $svc->upload($cA, $nA['user_id'], $mk('lista.png', $png), 'Lista de compras TESTE 100%', '', 'lista_compras');
$row = $svc->get($cA, $m1);
T::ok($row['mime_type'] === 'application/pdf' && strlen($row['sha256']) === 64 && !str_contains($row['file_name'], '"') && !str_contains($row['file_name'], "\n"), 'PDF gravado com hash; nome original sem aspas nem quebra de linha');
T::ok(is_file("$dir/$cA/" . $row['storage_name']) && preg_match('/^[a-f0-9]{32}\.pdf$/', $row['storage_name']) === 1, 'arquivo fora da pasta pública, com nome aleatório');
T::throws(fn() => $svc->upload($cA, $nA['user_id'], $mk('copia.pdf', $pdf('um')), 'Cópia TESTE', '', 'outros'), 'mesmo arquivo de novo: avisa que já está na biblioteca', 'já está na biblioteca');
T::ok($svc->upload($cB, $nB['user_id'], $mk('a.pdf', $pdf('um')), 'Guia TESTE B', '', 'orientacao') > 0, 'outra clínica pode subir o mesmo arquivo');

echo "== biblioteca\n";
T::ok(count($svc->list($cA)) === 2 && count($svc->list($cB)) === 1, 'cada clínica vê só os seus');
T::ok(count($svc->list($cA, '100%')) === 1 && count($svc->list($cA, '_')) === 0 && count($svc->list($cA, '', 'lista_compras')) === 1, 'busca escapa % e _; filtro por categoria');
T::throws(fn() => $svc->get($cB, $m1), 'clínica B não abre material da A', 'não encontrado');
T::throws(fn() => $svc->clinicFile($cB, $m1), 'clínica B não baixa arquivo da A', 'não encontrado');
$svc->update($cA, $nA['user_id'], $m1, 'Guia alimentar TESTE v2', '', 'orientacao');
T::ok($svc->get($cA, $m1)['title'] === 'Guia alimentar TESTE v2' && $svc->get($cA, $m1)['description'] === null, 'edição de título e descrição');
T::throws(fn() => $svc->update($cB, $nB['user_id'], $m1, 'x', '', 'outros'), 'clínica B não edita', 'não encontrado');

echo "== envio\n";
T::throws(fn() => $svc->share($cA, $nA['user_id'], $m1, []), 'envio sem paciente: recusado', 'pelo menos um');
T::throws(fn() => $svc->share($cA, $nA['user_id'], $m1, [$p1, $pB]), 'paciente de outra clínica: recusado');
T::ok((int)$pdo->query('SELECT COUNT(*) FROM educational_material_shares')->fetchColumn() === 0, 'falha no meio: nada gravado (transação)');
T::throws(fn() => $svc->share($cA, $rec['user_id'], $m1, [$p1]), 'recepção não envia', 'titular');
T::ok($svc->share($cA, $nA['user_id'], $m1, [$p1, $p2, $p1, '0', 'x'], ' Leia antes da consulta. ') === 2, 'envia para 2 (repetidos e lixo ignorados)');
T::ok($svc->share($cA, $nA['user_id'], $m1, [$p1]) === 0, 'reenviar para quem já tem: idempotente');
T::ok($svc->share($cA, $nA['user_id'], $m2, [$p1]) === 1 && (int)$svc->list($cA, 'Guia')[0]['shared_count'] === 2, 'contagem de envios na biblioteca');
$l1 = $svc->patientList($cA, $p1);
T::ok(count($l1) === 2 && $l1[0]['category_label'] !== '' && in_array('Leia antes da consulta.', array_column($l1, 'note'), true), 'paciente 1 vê os dois, com recado');
T::ok($svc->patientList($cB, $p1) === [] && $svc->patientList($cA, $pB) === [], 'nada aparece fora da própria clínica');
T::throws(fn() => $svc->patientFile($cA, $p2, $m2), 'paciente 2 não baixa o que não recebeu', 'não encontrado');
T::throws(fn() => $svc->patientFile($cB, $pB, $m1), 'paciente de outra clínica não baixa', 'não encontrado');
$f = $svc->patientFile($cA, $p1, $m1);
T::ok($f['mime_type'] === 'application/pdf' && is_file($f['path']), 'paciente 1 baixa o PDF');
$sh = $svc->shares($cA, $m1);
$s1 = array_values(array_filter($sh, fn($s) => (int)$s['patient_id'] === $p1))[0];
T::ok($s1['first_opened_at'] !== null && array_values(array_filter($sh, fn($s) => (int)$s['patient_id'] === $p2))[0]['first_opened_at'] === null, 'primeira abertura registrada só para quem abriu');

echo "== revogar e arquivar\n";
T::throws(fn() => $svc->revoke($cB, $nB['user_id'], (int)$s1['id']), 'clínica B não revoga envio da A', 'não encontrado');
$svc->revoke($cA, $nA['user_id'], (int)$s1['id']);
$svc->revoke($cA, $nA['user_id'], (int)$s1['id']);
T::ok(count($svc->patientList($cA, $p1)) === 1, 'revogado some da área do paciente (revogar duas vezes não quebra)');
T::throws(fn() => $svc->patientFile($cA, $p1, $m1), 'revogado não baixa mais', 'não encontrado');
T::ok($svc->share($cA, $nA['user_id'], $m1, [$p1]) === 1 && count($svc->patientList($cA, $p1)) === 2, 'reenviar depois de revogar devolve o acesso');
$svc->setArchived($cA, $nA['user_id'], $m1, true);
T::ok(count($svc->list($cA)) === 1 && count($svc->list($cA, '', '', true)) === 1 && count($svc->patientList($cA, $p2)) === 0, 'arquivado sai da biblioteca e da área do paciente');
T::throws(fn() => $svc->share($cA, $nA['user_id'], $m1, [$p2]), 'arquivado não pode ser enviado', 'arquivado');
T::ok(count($svc->shares($cA, $m1)) === 2, 'histórico de envios fica');
$svc->setArchived($cA, $nA['user_id'], $m1, false);
T::ok(count($svc->patientList($cA, $p2)) === 1, 'reativar devolve para quem tinha recebido');

echo "== integridade\n";
file_put_contents("$dir/$cA/" . $svc->get($cA, $m2)['storage_name'], 'adulterado');
T::throws(fn() => $svc->patientFile($cA, $p1, $m2), 'arquivo alterado no disco: não é entregue', 'alterado');
T::ok(str_contains(file_get_contents(dirname(__DIR__) . '/src/Domain/Materials/MaterialService.php'), "basename((string)\$m['storage_name'])"), 'caminho montado com basename (sem ../)');

echo "== rotas e telas\n";
T::ok(MC::safeBack('/materiais?para=3', '/x') === '/materiais?para=3' && MC::safeBack('https://evil.example', '/m') === '/m' && MC::safeBack('//evil.example/materiais', '/m') === '/m' && MC::safeBack('/admin', '/m') === '/m', 'volta só para /materiais ou /pacientes');
$idx = file_get_contents(dirname(__DIR__) . '/public/index.php');
$pos = fn(string $r) => strpos($idx, $r);
T::ok($pos("'/materiais/envios/{sid}/revogar'") < $pos("'/materiais/{id}/{acao}'") && $pos("'/materiais/{id}/enviar'") < $pos("'/materiais/{id}/{acao}'") && $pos("'/materiais/{id}/arquivo'") < $pos("\$router->get('/materiais/{id}'"), 'rotas específicas antes das genéricas');
T::ok(str_contains($idx, "'/paciente/materiais'") && str_contains($idx, "'/api/v1/patient/materials/{id}'"), 'área do paciente: página e API');
$views = '';
foreach (glob(dirname(__DIR__) . '/views/materials/*.php') as $v) { $views .= file_get_contents($v); }
T::ok(!preg_match('/\son[a-z]+\s*=/i', $views) && substr_count($views, 'name="_csrf_token"') >= 6, 'telas sem handlers inline e com CSRF em todos os formulários');
T::ok((str_contains(file_get_contents(dirname(__DIR__) . '/views/layouts/professional-shell.php'), 'href="/materiais"') || str_contains((string)@file_get_contents(dirname(__DIR__) . '/views/layouts/_professional_navigation.php'), "'/materiais'")) /* v54.58: menu em _professional_navigation.php */ && str_contains(file_get_contents(dirname(__DIR__) . '/views/patient/app.php'), '/paciente/materiais'), 'menu da clínica e link na área do paciente');

echo "== LGPD\n";
T::ok(array_key_exists('educational_material_shares', LgpdService::EXPORTED_TABLES), 'envios no inventário de exportação');
TenantContext::set($cA, ['id' => $nA['user_id'], 'role' => 'nutritionist']);
$lg = new LgpdService($pdo);
$ex = $lg->exportPatientData($p2);
T::ok(count($ex['educational_material_shares'] ?? []) === 1 && ($ex['educational_materials_received'][0]['title'] ?? '') === 'Guia alimentar TESTE v2', 'exportação traz o envio com o título do material');
$lg->anonymizePatient($p2);
T::ok($svc->patientList($cA, $p2) === [] && $pdo->query("SELECT COUNT(*) FROM educational_material_shares WHERE patient_id = $p2 AND (note IS NOT NULL OR revoked_at IS NULL)")->fetchColumn() == 0, 'anonimizar revoga os envios e apaga o recado');

foreach (glob("$dir/*/*") ?: [] as $x) { @unlink($x); }
foreach (glob("$dir/*") ?: [] as $x) { @rmdir($x); }
@rmdir($dir);
foreach (glob("$tmp/*") ?: [] as $x) { @unlink($x); }
@rmdir($tmp);
T::done();
