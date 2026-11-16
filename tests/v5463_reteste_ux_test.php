<?php
/**
 * v54.63: correções do reteste UX da v54.62 (R-001..R-010 e parciais UX-006/009/013/014/016/017).
 * Banco SQLite novo, dados só de TESTE. Cobre positivo, negativo, limite e regressão do que mudou no servidor.
 * O comportamento de tela (eco decimal, aviso junto da refeição, gaveta com Esc, painel de fechamento)
 * é comprovado pelo E2E de navegador descrito no relatório do reteste.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5463-reteste.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\AuthUser;
use ClyoNutri\Core\Release\BuildIdentity;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Food\FoodSearchIndex;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanPrintService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Nutrition\QuantityInput;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$clinic = Fx::clinic($pdo, 'Clínica TESTE Reteste');
$user = Fx::user($pdo, $clinic, 'Dra. Helena Prado TESTE', 'owner', '98765', 'CRN-3');
$patient = Fx::patient($pdo, $clinic, 'Mariana Souza Lima TESTE');
AuthContext::set(new AuthUser(id: (int)$user['user_id'], uuid: 'u-' . (int)$user['user_id'], name: 'Dra. Helena Prado TESTE',
    email: 'helena@teste.invalid', userRole: 'nutritionist', authVersion: 0, clinicId: $clinic, memberRole: 'owner', clinicName: 'Clínica TESTE Reteste'));
$_SESSION['user_id'] = (int)$user['user_id'];
$_SESSION['clinic_id'] = $clinic;

echo "=== R-001: quantidade decimal pt-BR ===\n";
foreach (['1,5' => 1.5, '1.5' => 1.5, '0,25' => 0.25, '100' => 100.0, '1.000,5' => 1000.5, ' 2 ' => 2.0, '.5' => 0.5] as $raw => $exp) {
    $v = QuantityInput::parse($raw);
    T::ok($v !== null && abs($v - $exp) < 1e-9, "parse('{$raw}') = {$exp}");
}
foreach (['abc', '', '1,5,5', '-1', '1e3', '1/2'] as $raw) {
    T::ok(QuantityInput::parse($raw) === null, "parse('{$raw}') é recusado (null)");
}
T::ok(MealPlanService::normalizeAmount('1,5') === 1.5, 'normalizeAmount("1,5") = 1.5 (antes virava 1)');
T::ok(MealPlanService::normalizeAmount('abc') === -1.0, 'normalizeAmount("abc") continua inválido');
T::ok(QuantityInput::format(1000.0) === '1000' && QuantityInput::format(1.5) === '1,5' && QuantityInput::format(0.25) === '0,25',
    'format sem separador de milhar (evita reabrir "1.000" = 1)');

$foodSvc = new FoodCatalogService($pdo);
$foodSvc->seedTacoReference();
(new \ClyoNutri\Domain\Food\TacoCatalogImporter($pdo))->run();
HouseholdMeasureConverter::ensureCommonMeasures($pdo);

$plans = new MealPlanService($pdo);
$created = $plans->createPlan($patient, (int)$user['professional_id'], 'Plano TESTE reteste', null);
$planId = (int)($created['plan_id'] ?? $created['id']);
$mealId = $plans->addMeal($planId, 'Lanche', '15:00');
$banana = (int)$pdo->query("SELECT id FROM foods WHERE name = 'Banana prata crua' LIMIT 1")->fetchColumn();
T::ok($banana > 0, 'catálogo TESTE tem Banana prata crua');
$itemId = $plans->addItem($mealId, $banana, MealPlanService::normalizeAmount('1,5'), 'unidade');
$row = $pdo->query('SELECT amount, weight_grams FROM meal_plan_items WHERE id = ' . (int)$itemId)->fetch();
T::ok(abs((float)$row['amount'] - 1.5) < 1e-9, 'item gravado com 1,5 unidade (não 15)');

T::ok(QuantityInput::plausibilityWarning('Banana prata crua', 1.5, 'unidade', 150.0, 147.0) === null, 'porção comum não gera aviso');
$warn = QuantityInput::plausibilityWarning('Banana prata crua', 15, 'unidade', 1500.0, 1470.0);
T::ok($warn !== null && str_contains($warn, '15 unidades') && str_contains($warn, '1.500 g'), 'porção de 1,5 kg gera aviso de conferência: ' . (string)$warn);
T::ok(QuantityInput::plausibilityWarning('Azeite', 10, 'colher de sopa', 80.0, 1001.0) !== null, 'acima de 1.000 kcal também gera aviso (limite)');

echo "=== UX-013: plural das medidas ===\n";
T::ok(QuantityInput::unitLabel(2, 'unidade') === 'unidades' && QuantityInput::unitLabel(4, 'colher de sopa') === 'colheres de sopa', '2 unidades, 4 colheres de sopa');
T::ok(QuantityInput::unitLabel(1.5, 'xícara') === 'xícara' && QuantityInput::unitLabel(1, 'fatia') === 'fatia', 'abaixo de 2 fica no singular');
T::ok(QuantityInput::unitLabel(300, 'g') === 'g' && QuantityInput::unitLabel(2, 'fatia média (30 g)') === 'fatia média (30 g)', 'gramas e medida com adjetivo ficam como estão');
$print = new ReflectionMethod(MealPlanPrintService::class, 'quantity');
$printSvc = (new ReflectionClass(MealPlanPrintService::class))->newInstanceWithoutConstructor();
T::ok($print->invoke($printSvc, 2, 'unidade', 100.0, false) === '2 unidades (~100g)', 'impressão: "2 unidades (~100g)"');

echo "=== R-004: catálogo sem cópia do TACO 410 e medidas com origem ===\n";
$copies = (int)$pdo->query("SELECT COUNT(*) FROM foods WHERE name = 'Frango, peito, sem pele, grelhado' AND taco_code IS NULL AND COALESCE(is_current, 1) = 1")->fetchColumn();
T::ok($copies === 0, 'ensureCommonMeasures não cria mais a cópia interna "Frango, peito, sem pele, grelhado"');
$res = $foodSvc->paginate(['q' => 'frango grelhado', 'per_page' => 5])['foods'];
T::ok(($res[0]['name'] ?? '') === 'Peito de frango grelhado', '"frango grelhado" traz o TACO 410 em 1º (' . ($res[0]['name'] ?? '') . ')');
T::ok(!str_contains(mb_strtolower($res[0]['name'] ?? '', 'UTF-8'), 'coração'), 'coração não lidera a busca');
$names = array_column($res, 'name');
T::ok(count($names) === count(array_unique(array_map(static fn($n) => mb_strtolower($n, 'UTF-8'), $names))), 'resultado sem nomes repetidos');
T::ok(HouseholdMeasureConverter::inferCommonMeasuresForNormalizedName(' arroz integral cru ') === [], 'arroz cru não recebe concha/escumadeira');
T::ok(HouseholdMeasureConverter::inferCommonMeasuresForNormalizedName(' feijao preto cru ') === [], 'feijão cru não recebe concha');
T::ok(HouseholdMeasureConverter::inferCommonMeasuresForNormalizedName(' arroz branco cozido ') !== [], 'arroz cozido continua com medidas (regressão UX-001)');
$tagged = (int)$pdo->query("SELECT COUNT(*) FROM food_household_measures WHERE source = " . $pdo->quote(HouseholdMeasureConverter::ESTIMATED_SOURCE))->fetchColumn();
$curated = (int)$pdo->query("SELECT COUNT(*) FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE f.name = 'Azeite de oliva extravirgem' AND m.measure_name = 'colher de sopa' AND m.grams_equivalent = 13 AND m.source IS NULL")->fetchColumn();
T::ok($tagged > 250, "medidas inferidas registram a origem \"" . HouseholdMeasureConverter::ESTIMATED_SOURCE . "\" ({$tagged})");
T::ok($curated === 1, 'medida curada do catálogo de referência (azeite 13 g) não é rotulada como estimativa');
T::ok(HouseholdMeasureConverter::originLabel('global', HouseholdMeasureConverter::ESTIMATED_SOURCE) === 'estimativa da base geral, confira o peso', 'tela mostra que a medida é estimativa');
T::ok(HouseholdMeasureConverter::originLabel('global') === 'medida da base geral do sistema', 'medida global sem fonte mantém o rótulo antigo');

// Migration 95 sobre um banco que já tinha a cópia (homologação da v54.62).
$pdo->exec("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom)
    VALUES (NULL, 'Frango, peito, sem pele, grelhado', 'Carnes e derivados', 'TACO', 159, 32, 0, 2.5, 0, 100, 'g', 0)");
$copyId = (int)$pdo->lastInsertId();
$fakeOld = (int)$pdo->query("SELECT m.id FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE f.name = 'Banana prata crua' AND m.measure_name = 'unidade' LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE food_household_measures SET source = NULL, created_at = '2026-11-15 12:00:00' WHERE id = {$fakeOld}"); // linha da v54.62 sem origem
$rawRice = (int)$pdo->query("SELECT id FROM foods WHERE name LIKE 'Arroz%cru%' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent, source, created_at) VALUES (?, 'concha', 100, ?, '2026-11-15 10:00:00')")
    ->execute([$rawRice, HouseholdMeasureConverter::ESTIMATED_SOURCE]);
$item2 = $plans->addItem($mealId, $copyId, 100, 'g');
// Em produção as medidas curadas do catálogo de referência são de antes de 15/11/2026.
$pdo->exec("UPDATE food_household_measures SET created_at = '2026-10-01 00:00:00' WHERE source IS NULL AND id <> {$fakeOld}");
require_once dirname(__DIR__) . '/database/migrations/2026_11_15_000095_reteste_ux_catalog_cleanup.php';
(new RetesteUxCatalogCleanup())->up($pdo);
(new RetesteUxCatalogCleanup())->up($pdo); // idempotente
T::ok((int)$pdo->query('SELECT is_current FROM foods WHERE id = ' . $copyId)->fetchColumn() === 0, 'migration 95 tira a cópia da busca (is_current = 0)');
T::ok((int)$pdo->query('SELECT COUNT(*) FROM meal_plan_items WHERE id = ' . (int)$item2 . ' AND food_id = ' . $copyId)->fetchColumn() === 1, 'item que já usava a cópia continua íntegro');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM food_household_measures WHERE food_id = {$rawRice} AND measure_name = 'concha'")->fetchColumn() === 0, 'migration 95 remove concha estimada de arroz cru');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM foods WHERE taco_code = 410 AND COALESCE(is_current,1) = 1")->fetchColumn() === 1, 'TACO 410 continua ativo');
T::ok((string)$pdo->query("SELECT source FROM food_household_measures WHERE id = {$fakeOld}")->fetchColumn() === HouseholdMeasureConverter::ESTIMATED_SOURCE, 'migration 95 rotula a medida inferida que a v54.62 gravou sem origem');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE f.name = 'Azeite de oliva extravirgem' AND m.grams_equivalent = 13 AND m.source IS NULL")->fetchColumn() === 1, 'migration 95 não rotula medida curada');

echo "=== R-006: objetivo exige ajuste no sentido certo ===\n";
$energy = new EnergyTargetService($pdo);
$base = ['weight_kg' => '72,4', 'height_cm' => '170', 'age_years' => 36, 'sex' => 'female', 'formula' => 'mifflin_st_jeor',
    'activity_factor' => '1,55', 'adjust_mode' => 'kcal', 'macro_mode' => 'percent', 'protein' => 20, 'carbohydrate' => 50, 'lipid' => 30];
T::throws(fn() => $energy->save($planId, $base + ['goal_type' => 'loss', 'adjust_value' => 0]), 'redução com ajuste 0 é recusada', 'valor negativo');
T::throws(fn() => $energy->save($planId, $base + ['goal_type' => 'loss', 'adjust_value' => '200']), 'redução com ajuste positivo é recusada', 'valor negativo');
T::throws(fn() => $energy->save($planId, $base + ['goal_type' => 'gain', 'adjust_value' => '0']), 'ganho com ajuste 0 é recusado', 'valor positivo');
$ok = $energy->save($planId, $base + ['goal_type' => 'loss', 'adjust_value' => '-500']);
T::ok(abs((float)$ok['vet_kcal'] - ((float)$ok['get_kcal'] - 500)) < 1, 'redução com -500 kcal grava VET = GET - 500');
$keep = $energy->save($planId, $base + ['goal_type' => 'maintenance', 'adjust_value' => '0']);
T::ok(abs((float)$keep['vet_kcal'] - (float)$keep['get_kcal']) < 0.01, 'manutenção com ajuste 0 continua aceita (regressão)');
$pct = $energy->save($planId, $base + ['goal_type' => 'loss', 'adjust_mode' => 'percent', 'adjust_value' => '-20,0']);
T::ok((float)$pct['vet_kcal'] < (float)$pct['get_kcal'], 'redução em % com vírgula ("-20,0") é aceita');

echo "=== R-002: identidade da versão ===\n";
T::ok(BuildIdentity::PACKAGE_VERSION === 'v54.63' && BuildIdentity::UI_VERSION === 'v54.63', 'BuildIdentity v54.63');
$info = json_decode((string)file_get_contents(dirname(__DIR__) . '/RELEASE_INFO.json'), true);
T::ok(is_array($info) && ($info['version'] ?? '') === BuildIdentity::PACKAGE_VERSION, 'RELEASE_INFO.json igual ao BuildIdentity');

echo "=== Telas (renderização do servidor) ===\n";
$closing = (string)file_get_contents(dirname(__DIR__) . '/views/consultation/_closing_panel.php');
T::ok(str_contains($closing, '!$existingFollowUp && $followUpSuggest[\'adjusted\']'), 'R-003: aviso "caiu indisponível" só aparece sem retorno marcado');
$room = (string)file_get_contents(dirname(__DIR__) . '/views/consultation/room.php');
T::ok(str_contains($room, "ev.key === 'Escape'") && str_contains($room, 'consultation-history-title'), 'R-008: gaveta fecha com Esc e recebe foco no título');
T::ok(str_contains($room, 'data-testid="consult-quick-anthro"'), 'UX-016: peso/cintura rápidos recolhidos sob a Avaliação');
$css = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/operational-ui.css');
T::ok(!str_contains($css, '.btn,button:not(') && str_contains($css, 'button:not(.btn)'), 'R-009: o mínimo de 24 px não anula mais os 44 px do .btn');
$dash = (string)file_get_contents(dirname(__DIR__) . '/src/Application/Controllers/DashboardController.php');
T::ok(substr_count($dash, 'data-decimal-field="quantity"') >= 5 && !preg_match('/name="amount" type="number"[^>]*(item-amount|meal-food-amount|sub-amount|dc22|dc25)/', $dash),
    'R-001: campos de quantidade do plano aceitam vírgula (sem type=number)');
T::ok(!str_contains($dash, 'refeição(ões)'), 'UX-013: sem "refeição(ões)"');

T::done();
