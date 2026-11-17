<?php
/**
 * v54.77 — decisão 1 do Maicon (17/11/2026): o nutricionista responsável técnico (RT) que revisa as medidas caseiras
 * (NI2-886) e o catálogo de exames (NI2-891) é Raphael Lombardi. Antes o papel ficava com quem estivesse logado no
 * console (superadmin). Agora: RT nomeado em platform_settings (migração 102), CRN obrigatório para gravar revisão
 * (sem valor inventado), revisor gravado = "Nome — CRN" e quem registrou fica em reviewed_by/auditoria.
 * SQLite isolado, dados só de TESTE (o CRN usado aqui é fictício de teste).
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5477-rt.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Catalog\CatalogTechnicalReviewer as RT;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService as Svc;

$db = \ClyoNutri\Core\Database\Connection::getInstance();
$root = dirname(__DIR__);
$TEST_CRN = 'CRN-9 99999'; // fictício, só deste teste

echo "== 1. Migração 102: RT nomeado, sem CRN inventado\n";
$rows = $db->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'catalog_rt_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
T::ok(($rows['catalog_rt_name'] ?? null) === 'Raphael Lombardi', 'migração 102 grava o nome do RT: Raphael Lombardi');
T::ok(($rows['catalog_rt_crn'] ?? 'x') === '' && ($rows['catalog_rt_email'] ?? 'x') === '', 'CRN e e-mail do RT ficam em branco (sem valor inventado)');
$rt = RT::load($db);
T::ok($rt->hasName() && !$rt->hasCrn() && !$rt->isReady(), 'sem CRN o RT não está pronto para revisar');
T::ok(in_array('CRN do RT (ex.: CRN-3 12345)', $rt->missing(), true), 'a falta do CRN é dita em português');
T::throws(static fn() => $rt->assertReady(), 'gravar revisão sem CRN é recusado', 'Revisão bloqueada: falta CRN do RT');
$sql = (string)file_get_contents($root . '/database/manual/v54_77_rt_catalogos_mysql57.sql');
$code = preg_replace('/^\s*--.*$/m', '', $sql);
T::ok(substr_count($code, 'WHERE NOT EXISTS') === 4, 'SQL manual 102: 3 configurações + registro da migração, todos com WHERE NOT EXISTS');
T::ok(!preg_match("/'CRN-\d/", $code), 'SQL manual 102 não traz número de CRN');

echo "== 2. CRN: normalização e validação\n";
T::ok(RT::normalizeCrn('crn3 12345') === 'CRN-3 12345', '"crn3 12345" vira "CRN-3 12345"');
T::ok(RT::normalizeCrn('CRN-10/1234') === 'CRN-10 1234', '"CRN-10/1234" vira "CRN-10 1234"');
T::ok(RT::normalizeCrn('CRN-3 12345/P') === 'CRN-3 12345/P', 'CRN provisório (/P) aceito');
T::ok(RT::normalizeCrn('12345') === '' && RT::normalizeCrn('CRN') === '' && RT::normalizeCrn('Raphael') === '', 'texto que não é CRN é recusado');
T::throws(static fn() => RT::save($db, 'Raphael Lombardi', '12345', '', 1), 'salvar CRN sem região/formato é recusado', 'CRN no formato');
T::throws(static fn() => RT::save($db, 'Ra', '', '', 1), 'nome curto é recusado', 'nome completo');
T::throws(static fn() => RT::save($db, 'Raphael Lombardi', '', 'nao-e-email', 1), 'e-mail inválido é recusado', 'E-mail');

echo "== 3. Salvar RT (com auditoria) e rótulo do revisor\n";
$clinic = Fx::clinic($db, 'Clínica TESTE v5477');
$op = Fx::user($db, $clinic, 'Operadora TESTE v5477', 'owner', null);
$ch = RT::save($db, ' Raphael   Lombardi ', 'crn9 99999', '', $op['user_id']);
T::ok(isset($ch['catalog_rt_crn']) && !isset($ch['catalog_rt_name']), 'só o CRN mudou (nome normalizado igual)');
$rt = RT::load($db);
T::ok($rt->isReady() && $rt->label() === 'Raphael Lombardi — ' . $TEST_CRN, 'rótulo do revisor: "Raphael Lombardi — CRN-9 99999"');
$a = $db->query("SELECT user_id, details FROM audit_logs WHERE action = 'CATALOG_RT_UPDATED' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
T::ok($a && (int)$a['user_id'] === $op['user_id'] && str_contains((string)$a['details'], $TEST_CRN), 'auditoria CATALOG_RT_UPDATED com quem mudou e o antes/depois');
T::ok(RT::save($db, 'Raphael Lombardi', $TEST_CRN, '', $op['user_id']) === [], 'salvar igual não gera mudança nem auditoria');
require_once $root . '/database/migrations/2026_11_17_000102_v5477_catalog_rt_reviewer.php';
(new V5477CatalogRtReviewer())->up($db);
T::ok(RT::load($db)->crn === $TEST_CRN, 'migração 102 rodada de novo não apaga o CRN preenchido');

echo "== 4. Medida caseira revisada em nome do RT, com quem registrou\n";
$db->prepare('INSERT INTO foods (name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g) VALUES (?, ?, 90, 1, 20, 1, 1)')->execute(['Banana TESTE v5477', 'Frutas TESTE']);
$food = (int)$db->lastInsertId();
$db->prepare('INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent, source) VALUES (?, ?, ?, ?)')->execute([$food, 'unidade', 100, HouseholdMeasureConverter::ESTIMATED_SOURCE]);
$mid = (int)$db->lastInsertId();
$svc = new Svc($db);
$svc->approve($mid, $op['user_id'], $rt->label(), 'Pinheiro, Tabela de medidas caseiras, 5ª ed. (TESTE)', 110.0);
$row = $svc->list(['status' => 'approved', 'q' => 'Banana TESTE v5477'])[0] ?? [];
T::ok(($row['reviewer_name'] ?? '') === 'Raphael Lombardi — ' . $TEST_CRN, 'revisor gravado = RT com CRN');
T::ok((int)($row['reviewed_by'] ?? 0) === $op['user_id'] && ($row['registered_by_name'] ?? '') === 'Operadora TESTE v5477', 'quem registrou fica em reviewed_by e aparece na lista');
T::ok(!$rt->isReviewerEmail($row['registered_by_email'] ?? null), 'sem e-mail do RT configurado, a operadora não é tratada como o RT');
$conv = (new HouseholdMeasureConverter($db))->exactMeasuresForFood($food);
$src = implode(' | ', array_map(static fn($m) => (string)($m['source_label'] ?? ''), $conv));
T::ok(str_contains($src, 'revisada por Raphael Lombardi — ' . $TEST_CRN), 'o editor de plano mostra "revisada por Raphael Lombardi — CRN" (' . $src . ')');
T::ok(str_contains($svc->productionSql(), "reviewer_name = 'Raphael Lombardi — {$TEST_CRN}'"), 'o SQL de produção leva o RT como revisor');

echo "== 5. Entradas web e terminal exigem o RT\n";
$ctrl = (string)file_get_contents($root . '/src/Application/Controllers/AdminController.php');
T::ok(str_contains($ctrl, '$name = $rt->label();') && str_contains($ctrl, "['aprovar', 'corrigir', 'remover', 'lote', 'importar']") && str_contains($ctrl, '$rt->assertReady();'), 'console: aprovar/corrigir/remover/lote/importar gravam o RT e exigem CRN');
// Terminal do catálogo de exames, processo real (herda o SQLite de teste).
$csv = sys_get_temp_dir() . '/v5477-exames-' . getmypid() . '.csv';
$code0 = (string)$db->query('SELECT code FROM exam_catalog WHERE clinic_id IS NULL AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
file_put_contents($csv, "codigo_interno;sistema;codigo_padrao;justificativa_sem_codigo\n{$code0};;;Sem código TUSS para este exame (TESTE)\n");
$cli = static function (string $args) use ($root): array { exec('cd ' . escapeshellarg($root) . ' && php bin/exam_catalog_review.php ' . $args . ' 2>&1', $out, $rc); return [$rc, implode("\n", $out)]; };
$db->prepare("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'catalog_rt_crn'")->execute();
[$rc, $out] = $cli('--import ' . escapeshellarg($csv) . ' --source "Fonte TESTE v5477"');
T::ok($rc !== 0 && str_contains($out, 'Revisão bloqueada: falta CRN do RT'), 'terminal: importar sem CRN do RT é recusado');
$db->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'catalog_rt_crn'")->execute([$TEST_CRN]);
[$rc, $out] = $cli('--import ' . escapeshellarg($csv) . ' --source "Fonte TESTE v5477" --reviewer "Ana Outra — CRN-1 111"');
T::ok($rc !== 0 && str_contains($out, 'O RT configurado é Raphael Lombardi'), 'terminal: revisor diferente do RT é recusado');
[$rc, $out] = $cli('--import ' . escapeshellarg($csv) . ' --source "Fonte TESTE v5477"');
$by = (string)$db->query('SELECT clinical_reviewed_by FROM exam_catalog WHERE code = ' . $db->quote($code0) . ' AND clinic_id IS NULL')->fetchColumn();
T::ok($rc === 0 && $by === 'Raphael Lombardi — ' . $TEST_CRN, 'terminal: exame revisado fica em nome do RT (' . $by . ')');
[$rc, $out] = $cli('');
T::ok(str_contains($out, 'Responsável técnico (RT): Raphael Lombardi — ' . $TEST_CRN), 'terminal --count mostra o RT');
@unlink($csv);

T::done();
