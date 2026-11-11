<?php

declare(strict_types=1);

/**
 * Suíte Automatizada — NI2-514 Fase 1 (v41):
 * - NI2-516: Cálculo de TMB, GET, VET e metas de macronutrientes (EnergyCalculator + EnergyTargetService)
 * - NI2-517: Medidas caseiras da clínica com fonte obrigatória, CSV e precedência sobre medidas globais
 * - NI2-518: Plano sem paciente (patient_id NULL), atribuição tardia de paciente ativo, bloqueio de paciente inativo (M1)
 *            e suporte à Lista de Compras em rascunho sem paciente (A1)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Nutrition\ClinicHouseholdMeasureService;
use ClyoNutri\Domain\Nutrition\EnergyCalculator;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\ShoppingList\ShoppingListService;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);
(new Migrator($db, __DIR__ . '/../database/migrations'))->run();

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=== NI2-514 Fase 1 (P0) — TMB/GET/VET, Medidas Caseiras da Clínica e Plano Sem Paciente ===\n";

// Seed clínicas, profissionais, pacientes e alimentos
$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (1, 'c-f1-1', 'Clínica Fase 1 A', 'active')");
$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (2, 'c-f1-2', 'Clínica Fase 1 B', 'active')");

$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status, is_verified) VALUES
    (10, 'u-f1-10', 'Dra. Helena', 'helena@f1.test', 'x', 'nutritionist', 'active', 1),
    (20, 'u-f1-20', 'Dr. Marcos', 'marcos@f1b.test', 'x', 'nutritionist', 'active', 1)");

$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES
    (1, 10, 'owner', 'active'),
    (2, 20, 'owner', 'active')");

$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES
    (100, 10, '11111', 'CRN-3', 'Nutrição Clínica'),
    (200, 20, '22222', 'CRN-3', 'Nutrição Clínica')");

$db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, birth_date, gender, status) VALUES
    (1000, 1, 'p-f1-1000', 'Ana Ativa', 'ana@paciente.test', '1990-05-15', 'Feminino', 'active'),
    (1001, 1, 'p-f1-1001', 'Carlos Arquivado', 'carlos@paciente.test', '1985-03-10', 'Masculino', 'archived'),
    (2000, 2, 'p-f1-2000', 'Beatriz Outra Clínica', 'bia@paciente.test', '1992-07-20', 'Feminino', 'active')");

$db->exec("INSERT INTO foods (id, clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, source, taco_code, is_current)
    VALUES (501, NULL, 'Arroz, integral, cozido', 'Cereais e derivados', 124, 2.6, 25.8, 1.0, 2.7, 'TACO', 1, 1)");
$db->exec("INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent)
    VALUES (501, 'colher de sopa', 25.0)");

TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Helena']);

// 1. EnergyCalculator — equações de Mifflin-St Jeor, Harris-Benedict 1984 e Cunningham 1980
$mifflinF = EnergyCalculator::compute([
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'age_years' => 30,
    'weight_kg' => 65.0,
    'height_cm' => 165.0,
    'activity_factor' => 1.55,
    'goal_type' => 'loss',
    'adjust_mode' => 'kcal',
    'adjust_value' => -400,
]);
// 10*65 + 6.25*165 - 5*30 - 161 = 650 + 1031.25 - 150 - 161 = 1370.25 -> 1370.3
check(abs($mifflinF['tmb_kcal'] - 1370.3) < 0.05 && $mifflinF['get_kcal'] > 2100 && $mifflinF['vet_kcal'] < $mifflinF['get_kcal'], 'Mifflin-St Jeor feminino calcula TMB (1370,3 kcal), GET e VET');

$mifflinM = EnergyCalculator::compute([
    'formula' => 'mifflin_st_jeor',
    'sex' => 'male',
    'age_years' => 30,
    'weight_kg' => 80.0,
    'height_cm' => 180.0,
    'activity_factor' => 1.40,
]);
// 10*80 + 6.25*180 - 5*30 + 5 = 800 + 1125 - 150 + 5 = 1780.0
check(abs($mifflinM['tmb_kcal'] - 1780.0) < 0.05, 'Mifflin-St Jeor masculino calcula TMB exata (1780,0 kcal)');

$cunningham = EnergyCalculator::compute([
    'formula' => 'cunningham',
    'weight_kg' => 70.0,
    'ffm_kg' => 52.0,
    'activity_factor' => 1.75,
]);
// 500 + 22*52 = 1644.0
check(abs($cunningham['tmb_kcal'] - 1644.0) < 0.05, 'Cunningham calcula TMB por massa livre de gordura (1644,0 kcal)');

$macros = EnergyCalculator::macros($mifflinF['vet_kcal'], 'percent', [
    'protein' => 25,
    'carbohydrate' => 45,
    'lipid' => 30,
], 65.0);
check($macros['protein_g'] > 0 && abs($macros['protein_pct'] + $macros['carbohydrate_pct'] + $macros['lipid_pct'] - 100.0) < 0.5, 'EnergyCalculator::macros converte percentuais do VET em gramas e g/kg');

// 2. Plano sem paciente (NI2-518) + Lista de compras sem paciente (A1)
$planSvc = new MealPlanService($db);
$created = $planSvc->createPlan(null, 100, 'Plano Inicial Sem Paciente', 'Orientação geral', MealPlanService::DEFAULT_MEALS);
$planId = (int)$created['plan_id'];
check($planId > 0, 'Cria plano em rascunho sem paciente (patient_id NULL) com 6 refeições padrão');

$previewNoPatient = $planSvc->getPlanPreview($planId);
check($previewNoPatient['is_ready_to_publish'] === false && !empty($previewNoPatient['allergen_safety']['patient_pending']), 'Plano sem paciente marca avaliação de alergias como pendente e bloqueia publicação');

$meals = $previewNoPatient['plan']['meals'];
check(count($meals) === 6, 'Plano nasce com as 6 refeições padrão');
$firstMealId = (int)$meals[0]['id'];
$itemId = $planSvc->addItem($firstMealId, 501, 4, 'colher de sopa');
check($itemId > 0, 'Adiciona item em medida caseira ao plano sem paciente');

// A1: Lista de compras de rascunho sem paciente não pode dar 404 nem 403
$shoppingList = (new ShoppingListService($db))->generateForPlan($planId, 7, ['require_published' => false]);
check($shoppingList['patient_id'] === null && $shoppingList['patient_name'] === 'Paciente a definir' && count($shoppingList['items']) === 1, 'A1: Lista de compras funciona em rascunho sem paciente (patient_id NULL)');

// Publicação sem paciente deve falhar com InvalidArgumentException
$pubBlocked = false;
try {
    $planSvc->publishPlan($planId);
} catch (\InvalidArgumentException $e) {
    $pubBlocked = str_contains($e->getMessage(), 'Atribua um paciente');
}
check($pubBlocked, 'Publicação de plano sem paciente é bloqueada com mensagem clara');

// 3. EnergyTargetService no plano sem paciente e migração ao atribuir paciente
$energySvc = new EnergyTargetService($db);
$savedTarget = $energySvc->save($planId, [
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'age_years' => '35',
    'weight_kg' => '64',
    'height_cm' => '162',
    'activity_factor' => '1.40',
    'goal_type' => 'maintenance',
    'adjust_mode' => 'kcal',
    'adjust_value' => '0',
    'macro_mode' => 'percent',
    'protein' => '20',
    'carbohydrate' => '50',
    'lipid' => '30',
]);
$rawTargetBefore = $db->query("SELECT patient_id FROM meal_plan_energy_targets WHERE meal_plan_id = {$planId}")->fetchColumn();
check((float)$savedTarget['vet_kcal'] > 1500 && $rawTargetBefore === null, 'Grava meta energética em plano sem paciente');

// M1: atribuir paciente arquivado/inativo deve ser rejeitado
$inactiveRejected = false;
try {
    $planSvc->assignPatient($planId, 1001);
} catch (\InvalidArgumentException $e) {
    $inactiveRejected = str_contains($e->getMessage(), 'ativo');
}
check($inactiveRejected, 'M1: assignPatient rejeita paciente arquivado/inativo');

// Atribuir paciente ativo da clínica
$planSvc->assignPatient($planId, 1000);
$afterAssign = $planSvc->getPlanWithDetails($planId);
$rawTargetAfter = (int)$db->query("SELECT patient_id FROM meal_plan_energy_targets WHERE meal_plan_id = {$planId}")->fetchColumn();
check((int)$afterAssign['patient_id'] === 1000 && $rawTargetAfter === 1000, 'assignPatient vincula paciente ao plano e atualiza meal_plan_energy_targets');

// 4. Medidas caseiras da clínica (NI2-517) + isolamento multi-tenant
$cmSvc = new ClinicHouseholdMeasureService($db);
$resMeasure = $cmSvc->upsert(501, 'colher de sopa', 32, 'Pesagem na cozinha experimental da clínica', 'Protocolo interno 01');
check($resMeasure['created'] === true && $resMeasure['id'] > 0, 'Cadastra medida caseira da clínica com fonte obrigatória');

$conv = new HouseholdMeasureConverter($db);
$convC1 = $conv->convertDetailed(2, 'colher de sopa', 501, null, false);
check((float)$convC1['grams'] === 64.0 && $convC1['origin'] === 'clinic', 'Medida da clínica (32 g) tem precedência sobre a medida global (25 g) na Clínica 1');

// Na Clínica 2, a medida da Clínica 1 não vaza (usa a global de 25 g)
TenantContext::set(2, ['id' => 20, 'role' => 'nutritionist', 'name' => 'Dr. Marcos']);
$convC2 = (new HouseholdMeasureConverter($db))->convertDetailed(2, 'colher de sopa', 501, null, false);
check((float)$convC2['grams'] === 50.0 && $convC2['origin'] === 'global', 'Isolamento multi-tenant: Clínica 2 não vê medida da Clínica 1 e usa a global (25 g)');

// Importação CSV de medidas da clínica
TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Helena']);
$csvMeasures = "taco_code;medida;gramas;fonte\n1; escumadeira ; 85 ; Tabela IBGE 2011\n";
$impReport = $cmSvc->importCsv($csvMeasures, 'Tabela IBGE');
check($impReport['ok'] === true && $impReport['created'] === 1, 'Importa medidas caseiras da clínica via CSV com validação transacional');

echo "\nResultado NI2-514 Fase 1: {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
