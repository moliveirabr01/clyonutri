<?php
/**
 * v54.76 — NI2-886: revisão profissional das medidas caseiras estimadas.
 * Serviço real + conversor real + plano real (MealPlanService) em SQLite isolado. Dados só de TESTE.
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-ni2-886.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService as Svc;

$db = \ClyoNutri\Core\Database\Connection::getInstance();
$EST = HouseholdMeasureConverter::ESTIMATED_SOURCE;
T::ok(Svc::ready($db), 'migração 99 cria as colunas da revisão');
// idempotência: rodar a migração de novo não falha
require_once dirname(__DIR__) . '/database/migrations/2026_11_17_000099_ni2_886_household_measure_review.php';
$again = true;
try { (new Ni2HouseholdMeasureReview())->up($db); } catch (\Throwable $e) { $again = false; }
T::ok($again, 'migração 99 é idempotente');

$clinic = Fx::clinic($db, 'Clínica TESTE 886');
$nutri = Fx::user($db, $clinic, 'Nutri Revisora TESTE', 'owner');
Fx::act($nutri);
$foods = [];
foreach ([['Banana TESTE 886', 'Frutas TESTE', 98], ['Arroz TESTE 886', 'Cereais TESTE', 128], ['Frango TESTE 886', 'Carnes TESTE', 159], ['Maçã TESTE 886', 'Frutas TESTE', 56], ['Pão TESTE 886', 'Cereais TESTE', 300]] as [$n, $c, $kcal]) {
    $db->prepare('INSERT INTO foods (name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g) VALUES (?, ?, ?, 1, 20, 1, 1)')->execute([$n, $c, $kcal]);
    $foods[$n] = (int)$db->lastInsertId();
}
$m = [];
foreach ([['Banana TESTE 886', 'unidade', 100], ['Arroz TESTE 886', 'colher de sopa', 25], ['Frango TESTE 886', 'filé', 100], ['Maçã TESTE 886', 'unidade', 130], ['Pão TESTE 886', 'fatia', 25], ['Pão TESTE 886', 'unidade', 50]] as [$f, $mn, $g]) {
    $db->prepare('INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent, source) VALUES (?, ?, ?, ?)')->execute([$foods[$f], $mn, $g, $EST]);
    $m[$f . '|' . $mn] = (int)$db->lastInsertId();
}
$svc = new Svc($db);

echo "== 1. Levantamento\n";
$st = $svc->stats();
T::ok($st['pending'] >= 6, "contagem de estimadas a revisar ({$st['pending']})");
$grp = array_column($st['by_group'], 'pendentes', 'grupo');
T::ok(($grp['Frutas TESTE'] ?? 0) === 2 && ($grp['Cereais TESTE'] ?? 0) === 3, 'contagem por grupo');

echo "== 2. Plano publicado antes da revisão (usa a banana estimada)\n";
$pid = Fx::patient($db, $clinic, 'Paciente TESTE 886');
$plans = new MealPlanService($db);
$plan = $plans->createPlan($pid, $nutri['user_id'], 'Plano TESTE 886');
$meal = $plans->addMeal((int)$plan['plan_id'], 'Café', '08:00');
$itemId = $plans->addItem($meal, $foods['Banana TESTE 886'], 2, 'unidade', null, true);
$blocked = false;
try { $plans->publishPlan((int)$plan['plan_id'], true, true, 'Conferido TESTE.', true, 'Adequação TESTE.', false); } catch (\InvalidArgumentException|\DomainException) { $blocked = true; }
T::ok($blocked, 'medida não revisada: publicar sem confirmar continua recusado (fica fora do PDF/app)');
$pub = $plans->publishPlan((int)$plan['plan_id'], true, true, 'Conferido TESTE.', true, 'Adequação TESTE.', true);
T::ok(!empty($pub['version']), 'com a confirmação do profissional o plano publica');
$snap = static function () use ($db, $plan, $itemId): array {
    $v = $db->query('SELECT payload_json, daily_energy_kcal FROM meal_plan_versions WHERE meal_plan_id = ' . (int)$plan['plan_id'] . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $i = $db->query('SELECT amount, measure_unit, energy_kcal, protein_g, carbohydrate_g FROM meal_plan_items WHERE id = ' . (int)$itemId)->fetch(PDO::FETCH_ASSOC);
    return ['v' => $v, 'i' => $i];
};
$before = $snap();
$usage = array_column($svc->list(['status' => 'pending', 'q' => 'TESTE 886']), 'usos', 'id');
T::ok((int)($usage[$m['Banana TESTE 886|unidade']] ?? 0) === 1, 'uso em planos contado (banana/unidade = 1)');
$first = $svc->list(['status' => 'pending', 'q' => 'TESTE 886', 'order' => 'uso'])[0] ?? [];
T::ok((int)($first['id'] ?? 0) === $m['Banana TESTE 886|unidade'], '"mais usadas" primeiro');

echo "== 3. Aprovar, corrigir, remover (com auditoria)\n";
T::throws(fn() => $svc->approve($m['Arroz TESTE 886|colher de sopa'], $nutri['user_id'], 'Nutri Revisora TESTE', ''), 'aprovar sem fonte é recusado', 'fonte');
T::throws(fn() => $svc->approve($m['Arroz TESTE 886|colher de sopa'], $nutri['user_id'], 'Nutri Revisora TESTE', $EST), 'fonte = a própria estimativa é recusada');
T::throws(fn() => $svc->approve($m['Arroz TESTE 886|colher de sopa'], $nutri['user_id'], 'Nutri Revisora TESTE', 'Pinheiro 5ª ed.', 0.0), 'peso corrigido 0 é recusado');
$svc->approve($m['Arroz TESTE 886|colher de sopa'], $nutri['user_id'], 'Nutri Revisora TESTE', 'Pinheiro, medidas caseiras, 5ª ed.');
$r = $svc->find($m['Arroz TESTE 886|colher de sopa']);
T::ok($r['review_status'] === 'approved' && $r['reviewer_name'] === 'Nutri Revisora TESTE' && $r['source'] === 'Pinheiro, medidas caseiras, 5ª ed.' && $r['reviewed_at'] !== null && (float)$r['grams_equivalent'] === 25.0, 'aprovada grava revisor, data e fonte; peso mantido');
$res = $svc->approve($m['Banana TESTE 886|unidade'], $nutri['user_id'], 'Nutri Revisora TESTE', 'Pesagem própria TESTE', 86);
$r = $svc->find($m['Banana TESTE 886|unidade']);
T::ok((float)$r['grams_equivalent'] === 86.0 && (float)$r['grams_before_review'] === 100.0, 'corrigir e aprovar: 100 g → 86 g, peso anterior guardado');
$aud = $db->query("SELECT action, user_id, details FROM audit_logs WHERE resource = 'food_household_measure' AND resource_id = '" . $m['Banana TESTE 886|unidade'] . "'")->fetch(PDO::FETCH_ASSOC);
$d = json_decode((string)($aud['details'] ?? ''), true);
T::ok(($aud['action'] ?? '') === 'HOUSEHOLD_MEASURE_CORRECTED' && (int)$aud['user_id'] === $nutri['user_id'] && ($d['gramas_antes'] ?? 0) == 100 && ($d['gramas_depois'] ?? 0) == 86 && ($d['fonte_antes'] ?? '') === $EST, 'audit_logs com antes/depois, fonte e revisor');
T::throws(fn() => $svc->approve($m['Banana TESTE 886|unidade'], $nutri['user_id'], 'Outra', 'x fonte'), 'medida já revisada não é revisada de novo', 'já foi revisada');
T::throws(fn() => $svc->remove($m['Frango TESTE 886|filé'], $nutri['user_id'], 'Nutri Revisora TESTE', ''), 'remover sem motivo é recusado');
$svc->remove($m['Frango TESTE 886|filé'], $nutri['user_id'], 'Nutri Revisora TESTE', 'medida não usual TESTE');
T::ok($svc->find($m['Frango TESTE 886|filé'])['review_status'] === 'removed', 'removida fica registrada (não apagada)');

echo "== 4. Plano publicado não muda\n";
$after = $snap();
T::ok($after['v']['payload_json'] === $before['v']['payload_json'] && (string)$after['v']['daily_energy_kcal'] === (string)$before['v']['daily_energy_kcal'], 'versão publicada idêntica (payload e kcal)');
T::ok($after['i'] == $before['i'], 'item do plano com os mesmos valores (quantidade, medida, kcal, macros)');

echo "== 5. O que o editor mostra\n";
$conv = new HouseholdMeasureConverter($db);
$opts = $conv->exactMeasuresForFood($foods['Banana TESTE 886']);
$ban = array_values(array_filter($opts, static fn($o) => mb_strtolower($o['name']) === 'unidade'))[0] ?? [];
T::ok(abs((float)($ban['grams'] ?? 0) - 86.0) < 0.001 && str_contains((string)($ban['source_label'] ?? ''), 'Pesagem própria TESTE') && str_contains((string)$ban['source_label'], 'revisada por Nutri Revisora TESTE') && !str_contains((string)$ban['source_label'], 'estimativa'), 'aprovada: peso novo, fonte e revisor, sem "estimativa"');
$fr = $conv->exactMeasuresForFood($foods['Frango TESTE 886']);
T::ok(!in_array('filé', array_map(static fn($o) => mb_strtolower($o['name']), $fr), true), 'removida não é mais oferecida');
$mac = $conv->exactMeasuresForFood($foods['Maçã TESTE 886']);
$mu = array_values(array_filter($mac, static fn($o) => mb_strtolower($o['name']) === 'unidade'))[0] ?? [];
T::ok(str_contains((string)($mu['source_label'] ?? ''), 'estimativa'), 'não revisada continua com o selo de estimativa no editor');

echo "== 6. Lote\n";
T::throws(fn() => $svc->approveBatch([], $nutri['user_id'], 'N', 'fonte x'), 'lote vazio é recusado');
$b = $svc->approveBatch([$m['Pão TESTE 886|fatia'], $m['Banana TESTE 886|unidade']], $nutri['user_id'], 'Nutri Revisora TESTE', 'Pinheiro 5ª ed. TESTE');
T::ok($b['approved'] === 1 && count($b['errors']) === 1, 'lote aprova a pendente e informa a já revisada');

echo "== 7. CSV: exportação e importação (linha inválida rejeita só a linha)\n";
$csv = $svc->exportCsv(['status' => 'pending', 'q' => 'TESTE 886']);
T::ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'medida_id;alimento_id;alimento;grupo;medida;gramas_atuais') && str_contains($csv, 'Maçã TESTE 886'), 'CSV com cabeçalho, BOM e acentos');
$hdr = implode(';', Svc::CSV_HEADER);
$line = static fn(int $id, int $fid, string $acao, string $g, string $fonte) => "{$id};{$fid};x;x;x;0;x;0;estimativa;{$acao};{$g};{$fonte}";
$imp = $hdr . "\n"
    . $line($m['Maçã TESTE 886|unidade'], $foods['Maçã TESTE 886'], 'corrigir', '0', 'Pesagem TESTE') . "\n"      // inválida: 0 g
    . $line($m['Pão TESTE 886|unidade'], $foods['Maçã TESTE 886'], 'aprovar', '', 'Pinheiro') . "\n"              // inválida: alimento não confere
    . $line($m['Pão TESTE 886|unidade'], $foods['Pão TESTE 886'], 'corrigir', '55,5', 'Pesagem própria TESTE') . "\n" // válida
    . $line($m['Maçã TESTE 886|unidade'], $foods['Maçã TESTE 886'], '', '', '') . "\n"                           // sem ação
    . $line(999999, 1, 'aprovar', '', 'x fonte') . "\n";                                                         // inválida: não existe
$dry = $svc->importCsv($imp, $nutri['user_id'], 'Nutri Revisora TESTE', true);
T::ok($dry['applied'] === 1 && count($dry['rejected']) === 3 && $svc->find($m['Pão TESTE 886|unidade'])['review_status'] === null, 'simulação: 1 válida, 3 rejeitadas, nada gravado');
$rr = $svc->importCsv($imp, $nutri['user_id'], 'Nutri Revisora TESTE');
T::ok($rr['applied'] === 1 && $rr['ignored'] === 1 && array_column($rr['rejected'], 'linha') === [2, 3, 6], 'importação: só as linhas inválidas (2, 3, 6) rejeitadas, com motivo');
$pu = $svc->find($m['Pão TESTE 886|unidade']);
T::ok($pu['review_status'] === 'approved' && abs((float)$pu['grams_equivalent'] - 55.5) < 0.001, 'linha válida aplicada (pão 50 → 55,5 g)');
T::ok($svc->find($m['Maçã TESTE 886|unidade'])['review_status'] === null, 'linha rejeitada não alterou a maçã');
T::throws(fn() => $svc->importCsv("a;b\n1;2", 1, 'x'), 'CSV sem as colunas exigidas é recusado', 'Cabeçalho');

echo "== 8. SQL para produção (MySQL 5.7)\n";
$sql = $svc->productionSql();
T::ok(substr_count($sql, 'UPDATE food_household_measures SET') === 5 && substr_count($sql, 'INSERT INTO audit_logs') === 5, 'SQL com 1 UPDATE + 1 auditoria por medida revisada (5)');
T::ok(str_contains($sql, "source LIKE '%revisar%'") && str_contains($sql, 'grams_before_review = grams_equivalent, grams_equivalent = 86.00'), 'SQL só altera medida ainda pendente e guarda o peso anterior');
T::ok(!preg_match('/UPDATE food_household_measures m JOIN/', $sql), 'UPDATE de tabela única (ordem das atribuições garantida no MySQL)');

T::done();
