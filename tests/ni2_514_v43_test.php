<?php

declare(strict_types=1);

/**
 * Suíte Automatizada — NI2-514 v43 (NI2-519 e NI2-520):
 * - Procedência e versionamento de alimentos (FoodSourceImporter + UsdaFdcImporter)
 * - Regras de equivalência da clínica com fonte obrigatória, revisão por CRN e desempate por revisão/macros (M4)
 * - Governança de modelos: visibilidade pessoal/clínica, aprovação por gestor, controle de autoria em archive/setDefault (A3)
 * - Plano qualitativo: edição de orientação de refeição e is_ready_to_publish (A2)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodEquivalenceService;
use ClyoNutri\Domain\Food\FoodSourceImporter;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\MealPlanTemplateService;

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

echo "=== NI2-514 v43 — Equivalência Clínica, Fontes Externas, Governança de Modelos e Plano Qualitativo ===\n";

$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (1, 'c-v43-1', 'Clínica v43', 'active')");
$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status, is_verified) VALUES
    (10, 'u-v43-10', 'Dra. Gestora', 'gestora@v43.test', 'x', 'nutritionist', 'active', 1),
    (11, 'u-v43-11', 'Dr. Membro', 'membro@v43.test', 'x', 'nutritionist', 'active', 1)");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES
    (1, 10, 'owner', 'active'),
    (1, 11, 'nutritionist', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES
    (100, 10, '10101', 'CRN-3', 'Clínica'),
    (101, 11, '20202', 'CRN-3', 'Esportiva')");
$db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, birth_date, gender, status) VALUES
    (1000, 1, 'p-v43-1000', 'Paciente v43', 'p@v43.test', '1992-01-10', 'Feminino', 'active')");
$db->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies)
    VALUES (1, 1000, 100, 1, '')");

$db->exec("INSERT INTO foods (id, clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, source, taco_code, is_current, allergens) VALUES
    (701, NULL, 'Arroz, tipo 1, cozido', 'Cereais e derivados', 128, 2.5, 28.1, 0.2, 1.6, 'TACO', 2, 1, 'none'),
    (702, NULL, 'Macarrão, trigo, cozido', 'Cereais e derivados', 126, 3.4, 26.4, 0.4, 1.5, 'TACO', 3, 1, 'gluten')");

TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Gestora']);

// 1. FoodSourceImporter: importação sem sobrescrever e superseding de versão anterior
$imp = new FoodSourceImporter($db);
$r1 = $imp->run('USDA FDC', 'Foundation Foods 2024-10', [
    ['source_code' => '10001', 'name' => 'Oats, raw', 'category' => 'Cereais e derivados', 'energy_kcal' => 379, 'protein_g' => 13.2, 'lipid_g' => 6.5, 'carbohydrate_g' => 67.7, 'fiber_g' => 10.1, 'nutrient_flags' => null],
], true);
check($r1['new'] === 1, 'Importa versão inicial de alimento externo com procedência');

$r2 = $imp->run('USDA FDC', 'Foundation Foods 2025-04', [
    ['source_code' => '10001', 'name' => 'Oats, raw', 'category' => 'Cereais e derivados', 'energy_kcal' => 381, 'protein_g' => 13.5, 'lipid_g' => 6.5, 'carbohydrate_g' => 67.7, 'fiber_g' => 10.1, 'nutrient_flags' => null],
], true);
$currCount = (int)$db->query("SELECT COUNT(*) FROM foods WHERE source = 'USDA FDC' AND source_code = '10001' AND is_current = 1")->fetchColumn();
$oldCount = (int)$db->query("SELECT COUNT(*) FROM foods WHERE source = 'USDA FDC' AND source_code = '10001' AND is_current = 0")->fetchColumn();
check($r2['new_version'] === 1 && $currCount === 1 && $oldCount === 1, 'Nova versão de fonte externa preserva linha antiga (is_current=0) e marca a nova como is_current=1');

// 2. FoodEquivalenceService + M4 (desempate por revisão e tolerância de macros)
$eqSvc = new FoodEquivalenceService($db);
$rIdPendente = $eqSvc->create([
    'name' => 'Cereais A (pendente, macro 25%)',
    'food_groups' => ['Cereais e derivados'],
    'energy_tolerance_pct' => '10',
    'macro_tolerance_pct' => '25',
    'source_note' => 'Protocolo interno v1 da clínica',
], 10);
$rIdRevisada = $eqSvc->create([
    'name' => 'Cereais Z (revisada, macro 20%)',
    'food_groups' => ['Cereais e derivados'],
    'energy_tolerance_pct' => '10',
    'macro_tolerance_pct' => '20',
    'source_note' => 'Protocolo interno v2 revisado pelo CRN',
], 10);
$eqSvc->markReviewed($rIdRevisada, 10);

$chosenRule = $eqSvc->ruleForGroup('Cereais e derivados');
check($chosenRule !== null && (int)$chosenRule['id'] === $rIdRevisada, 'M4: ruleForGroup prioriza regra revisada e mais restritiva em macros quando energia empata');

$verdict = $eqSvc->evaluate(
    ['category' => 'Cereais e derivados', 'energy_kcal' => 128, 'protein_g' => 2.5, 'carbohydrate_g' => 28.1, 'lipid_g' => 0.2],
    ['category' => 'Cereais e derivados', 'energy_kcal' => 126, 'protein_g' => 3.4, 'carbohydrate_g' => 26.4, 'lipid_g' => 0.4]
);
check($verdict['verdict'] === 'equivalente' && $verdict['review_pending'] === false, 'Avalia troca dentro da regra revisada como equivalente');

// 3. A2: Plano qualitativo — editar orientação de refeição padrão e is_ready_to_publish
$planSvc = new MealPlanService($db);
$qualPlan = $planSvc->createPlan(1000, 100, 'Plano Qualitativo Educação Nutricional', null, MealPlanService::DEFAULT_MEALS);
$qualPlanId = (int)$qualPlan['plan_id'];
$planSvc->setPlanMethod($qualPlanId, 'qualitativo');

// Sem orientação escrita nem itens: is_ready_to_publish = false
$prevBefore = $planSvc->getPlanPreview($qualPlanId);
check($prevBefore['is_ready_to_publish'] === false, 'Plano qualitativo sem itens e sem orientações escritas nas refeições ainda não está pronto para publicar');

// A2: editar a primeira refeição padrão adicionando orientação escrita
$firstMealId = (int)$prevBefore['plan']['meals'][0]['id'];
$planSvc->updateMeal($firstMealId, 'Café da manhã', '07:30', '1 fonte de proteína + 1 fruta in natura + café sem açúcar', $qualPlanId);

$prevAfter = $planSvc->getPlanPreview($qualPlanId);
check($prevAfter['is_ready_to_publish'] === true, 'A2: Plano qualitativo com orientação escrita na refeição fica pronto para publicar (is_ready_to_publish = true)');
check($planSvc->publishPlan($qualPlanId) === true, 'Publica plano qualitativo com orientação escrita nas refeições');

// 4. Governança de Modelos + A3 (controle de autoria/gestor em archive e setClinicDefault)
$planForTmpl = $planSvc->createPlan(1000, 100, 'Plano Modelo Equipe', null, [
    ['name' => 'Almoço', 'time_of_day' => '12:00', 'items' => [['food_id' => 701, 'amount' => 100, 'measure_unit' => 'g']]],
]);
(new EnergyTargetService($db))->save((int)$planForTmpl['plan_id'], [
    'formula' => 'mifflin_st_jeor', 'sex' => 'female', 'age_years' => '30', 'weight_kg' => '60', 'height_cm' => '165',
    'activity_factor' => '1.40', 'goal_type' => 'maintenance', 'adjust_mode' => 'kcal', 'adjust_value' => '0',
    'macro_mode' => 'percent', 'protein' => '20', 'carbohydrate' => '50', 'lipid' => '30',
]);
$tmplSvc = new MealPlanTemplateService($db);
$tmpl = $tmplSvc->saveFromPlan((int)$planForTmpl['plan_id'], 10, 'Modelo Oficial da Clínica', 'Adulto', null, false, 'clinica', true);
$tmplSvc->setApproval($tmpl['id'], 10, true);

// A3: nutricionista comum (user 11, não gestor e não autor) não pode arquivar nem trocar o padrão de modelo de outro/aprovado
TenantContext::set(1, ['id' => 11, 'role' => 'nutritionist', 'name' => 'Dr. Membro']);
$archiveBlocked = false;
try {
    $tmplSvc->archive($tmpl['id'], 11, false);
} catch (\DomainException $e) {
    $archiveBlocked = true;
}
check($archiveBlocked, 'A3: Nutricionista que não é autor nem gestor não pode arquivar modelo aprovado da clínica');

$defaultBlocked = false;
try {
    $tmplSvc->setClinicDefault($tmpl['id'], true, 11, false);
} catch (\DomainException $e) {
    $defaultBlocked = true;
}
check($defaultBlocked, 'A3: Nutricionista que não é autor nem gestor não pode alterar o padrão da clínica de modelo de outro autor');

// Gestora (user 10) pode definir como padrão e arquivar
TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Gestora']);
$tmplSvc->setClinicDefault($tmpl['id'], true, 10, true);
$foundTmpl = $tmplSvc->find($tmpl['id']);
check((int)$foundTmpl['is_clinic_default'] === 1, 'Gestora da clínica define modelo aprovado como padrão da clínica');

echo "\nResultado NI2-514 v43: {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
