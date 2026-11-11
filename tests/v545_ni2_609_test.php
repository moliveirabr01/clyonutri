<?php
/** NI2-609 (UX-R3-08): "unidade"/"fatia" só com medida cadastrada; peso e fonte vêm da medida. Dados TESTE. Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v545-609.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\UnitCheckView;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter as HMC;

$c = Fx::clinic($pdo, 'Clínica TESTE');
$o = Fx::user($pdo, $c, 'Nutri TESTE', 'owner');
$p = Fx::patient($pdo, $c, 'Paciente TESTE');
Fx::act($o);
$ins = $pdo->prepare("INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom, is_current) VALUES (?, 'TESTE', 'TACO', 300, 8, 58.6, 3.1, 1, ?, ?, 0, 1)");
$ins->execute(['Pão, trigo, francês', 100, 'g']); $bread = (int)$pdo->lastInsertId();
$ins->execute(['Arroz TESTE', 100, 'g']); $rice = (int)$pdo->lastInsertId();
$ins->execute(['Biscoito TESTE', 6, 'unidade']); $cookie = (int)$pdo->lastInsertId();

echo "== Seletor\n";
$opts = UnitCheckView::unitOptions();
T::ok(!str_contains($opts, 'value="unidade"') && !str_contains($opts, 'value="fatia"'), 'lista geral não oferece unidade nem fatia');
T::ok(str_contains($opts, 'value="g"') && str_contains($opts, 'value="ml"'), 'lista geral oferece g e ml');
T::ok(str_contains(UnitCheckView::block('x'), 'data-unit-measures'), 'bloco tem espaço para as medidas do alimento');

echo "== Conversor\n";
$conv = new HMC($pdo);
T::ok(HMC::isGenericCountUnit('Unidades') && HMC::isGenericCountUnit('fatia média') && HMC::isGenericCountUnit('porção') && !HMC::isGenericCountUnit('colher de sopa'), 'unidades de contagem reconhecidas');
T::throws(fn() => $conv->assertCountUnitHasMeasure('unidade', $bread), 'pão sem medida: unidade recusada', 'Medidas caseiras');
T::throws(fn() => $conv->assertCountUnitHasMeasure('fatia', $rice), 'arroz sem medida: fatia recusada');
$ok = true; try { $conv->assertCountUnitHasMeasure('colher de sopa', $rice); $conv->assertCountUnitHasMeasure('g', $bread); $conv->assertCountUnitHasMeasure('unidade', $cookie); } catch (\Throwable $e) { $ok = false; echo $e->getMessage(), "\n"; }
T::ok($ok, 'colher (padrão com confirmação), gramas e porção do próprio alimento continuam aceitos');

echo "== Plano\n";
$ms = new \ClyoNutri\Domain\MealPlan\MealPlanService($pdo);
$plan = $ms->createPlan($p, $o['professional_id'], 'TESTE Plano 609');
$planId = (int)($plan['plan_id'] ?? $plan['id']);
$meal = $ms->addMeal($planId, 'Café da Manhã', '07:30');
T::throws(fn() => $ms->addItem($meal, $bread, 1, 'unidade', null, true), 'item: 1 unidade de pão sem medida recusado mesmo confirmado', 'não tem a medida');
$itemG = $ms->addItem($meal, $bread, 50, 'g');
T::ok($itemG > 0, 'item em gramas aceito');
T::throws(fn() => $ms->addSubstitution($itemG, $bread, 2, 'fatia', null, true), 'substituição: fatia sem medida recusada');

// medida da clínica com fonte
(new \ClyoNutri\Domain\Nutrition\ClinicHouseholdMeasureService($pdo))->upsert($bread, 'unidade', '50', 'Tabela de medidas caseiras TESTE (Pinheiro)', 'p. 99');
$conv2 = new HMC($pdo);
$ms2 = new \ClyoNutri\Domain\MealPlan\MealPlanService($pdo);
$itemU = $ms2->addItem($meal, $bread, 1, 'unidade');
$row = $pdo->query('SELECT weight_grams, weight_is_estimated FROM meal_plan_items WHERE id = ' . $itemU)->fetch();
T::ok(abs((float)$row['weight_grams'] - 50) < 0.01 && (int)$row['weight_is_estimated'] === 0, '1 unidade de pão = 50 g da medida da clínica, sem estimativa');
$d = $conv2->convertDetailed(1, 'unidade', $bread);
T::ok(str_contains($d['source_label'] ?? '', 'fonte: Tabela de medidas caseiras TESTE'), 'conversão mostra a fonte da medida');
$m = $conv2->exactMeasuresForFood($bread);
T::ok(count($m) === 1 && str_contains($m[0]['source_label'], 'medida da clínica'), 'medidas do alimento trazem a origem');

echo "== Item antigo (legado)\n";
$pdo->prepare("INSERT INTO meal_plan_items (meal_id, food_id, amount, measure_unit, ordering, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, weight_grams, weight_is_estimated) VALUES (?, ?, 1, 'unidade', 9, 300, 8, 58.6, 3.1, 1, 100, 1)")->execute([$meal, $rice]);
$legacy = (int)$pdo->lastInsertId();
$item = $pdo->query('SELECT * FROM meal_plan_items WHERE id = ' . $legacy)->fetch();
$rev = (new ReflectionMethod(\ClyoNutri\Domain\MealPlan\MealPlanService::class, 'itemRevision'));
$rev->setAccessible(true);
$ok = true; try { $ms2->updateItem($legacy, 2, 'unidade', null, $rev->invoke(null, $item), $planId, true); } catch (\Throwable $e) { $ok = false; echo $e->getMessage(), "\n"; }
T::ok($ok, 'item antigo em unidade pode mudar a quantidade sem trocar a unidade');
$item = $pdo->query('SELECT * FROM meal_plan_items WHERE id = ' . $legacy)->fetch();
T::throws(fn() => $ms2->updateItem($legacy, 2, 'fatia', null, $rev->invoke(null, $item), $planId, true), 'trocar para fatia sem medida é recusado');

T::done();
