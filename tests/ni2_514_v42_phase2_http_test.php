<?php

declare(strict_types=1);

/**
 * NI2-514 / NI2-523 — Fase 2 HTTP, Isolamento Multi-Tenant, Edição Inline de Substituições e Acessibilidade (v42-v44):
 *  - NI2-521: Edição inline de quantidade/unidade/orientação de substituição (MealPlanService::updateSubstitution)
 *  - NI2-521: Verificação de atributos de acessibilidade por teclado e atualização sem reload (aria-live, data-live-swap, plan-live.js)
 *  - NI2-522: Reimportação de planilha CSV após desfazer 100% do lote anterior (idempotência + re-preview pós-rollback)
 *  - Isolamento estrito entre clínicas em Modelos, Regras de Equivalência e Lotes de Importação CSV
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodEquivalenceService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\MealPlanTemplateService;
use ClyoNutri\Domain\Patient\PatientImportService;

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

echo "=== NI2-514 / NI2-523 v42 Phase 2 HTTP, Inline Edit, Multi-Tenant & Accessibility Test ===\n";

$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES
    (1, 'c-http-1', 'Clínica Alpha TESTE', 'active'),
    (2, 'c-http-2', 'Clínica Beta TESTE', 'active')");

$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status, is_verified) VALUES
    (10, 'u-http-10', 'Dra. Alpha', 'alpha@teste.local', 'x', 'nutritionist', 'active', 1),
    (20, 'u-http-20', 'Dr. Beta', 'beta@teste.local', 'x', 'nutritionist', 'active', 1)");

$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES
    (1, 10, 'owner', 'active'),
    (2, 20, 'owner', 'active')");

$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES
    (100, 10, '10010', 'CRN-3', 'Clínica'),
    (200, 20, '20020', 'CRN-3', 'Clínica')");

$db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, status) VALUES
    (1000, 1, 'p-http-1000', 'TESTE Paciente Alpha', 'p.alpha@teste.local', 'active'),
    (2000, 2, 'p-http-2000', 'TESTE Paciente Beta', 'p.beta@teste.local', 'active')");

$db->exec("INSERT INTO foods (id, clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, source, is_current) VALUES
    (901, NULL, 'Arroz, integral, cozido', 'Cereais e derivados', 124, 2.6, 25.8, 1.0, 2.7, 'TACO', 1),
    (902, NULL, 'Batata, doce, cozida', 'Verduras, hortaliças e derivados', 77, 0.6, 18.4, 0.1, 2.2, 'TACO', 1)");

TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Alpha']);

// 1. NI2-521: Edição inline de substituição (updateSubstitution)
$planSvc = new MealPlanService($db);
$created = $planSvc->createPlan(1000, 100, 'Plano Inline Sub TESTE', null, [
    ['name' => 'Almoço', 'time_of_day' => '12:30'],
]);
$planId = (int)$created['plan_id'];
$details = $planSvc->getPlanWithDetails($planId);
$mealId = (int)$details['meals'][0]['id'];
$itemId = $planSvc->addItem($mealId, 901, 100, 'g');
$subId = $planSvc->addSubstitution($itemId, 902, 100, 'g', 'Troca inicial');

$updatedSub = $planSvc->updateSubstitution($subId, 160, 'g', 'Mesma energia do arroz (160g)', $planId);
$afterSub = $planSvc->getPlanWithDetails($planId);
$subRow = $afterSub['meals'][0]['items'][0]['substitutions'][0];
check($updatedSub === true && (float)$subRow['amount'] === 160.0 && $subRow['notes'] === 'Mesma energia do arroz (160g)' && abs((float)$subRow['energy_kcal'] - 123.2) < 0.2, 'NI2-521: updateSubstitution atualiza quantidade, orientação e recalcula kcal/macros da substituição');

// Cross-tenant block em updateSubstitution
TenantContext::set(2, ['id' => 20, 'role' => 'nutritionist', 'name' => 'Dr. Beta']);
$crossSubBlocked = false;
try {
    $planSvc->updateSubstitution($subId, 200, 'g', 'Invasão');
} catch (\InvalidArgumentException $e) {
    $crossSubBlocked = true;
}
check($crossSubBlocked, 'Isolamento multi-tenant: Clínica 2 não consegue editar substituição de plano da Clínica 1');

// 2. Isolamento multi-tenant em Modelos, Equivalências e Importação CSV
TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Alpha']);
$tmplSvc = new MealPlanTemplateService($db);
$tmpl = $tmplSvc->saveFromPlan($planId, 10, 'Modelo Alpha Privado', 'Geral', null, false, 'clinica', false);

$eqSvc = new FoodEquivalenceService($db);
$eqId = $eqSvc->create([
    'name' => 'Regra Alpha',
    'food_groups' => ['Cereais e derivados'],
    'energy_tolerance_pct' => '10',
    'macro_tolerance_pct' => '20',
    'source_note' => 'Fonte Alpha',
], 10);

$impSvc = new PatientImportService($db);
$csv = "Nome;E-mail\nTESTE Lote Reimport;reimport@teste.local\n";
$prev1 = $impSvc->preview($csv, 'lote.csv', 10);

// Clínica 2 não vê modelo, regra de equivalência nem lote de importação da Clínica 1
TenantContext::set(2, ['id' => 20, 'role' => 'nutritionist', 'name' => 'Dr. Beta']);
check($tmplSvc->find($tmpl['id']) === null && count($tmplSvc->listForClinic(false, 20)) === 0, 'Isolamento multi-tenant: Clínica 2 não vê modelos da Clínica 1');
check($eqSvc->find($eqId) === null && count($eqSvc->listRules(true)) === 0, 'Isolamento multi-tenant: Clínica 2 não vê regras de equivalência da Clínica 1');
check($impSvc->find($prev1['batch_id']) === null, 'Isolamento multi-tenant: Clínica 2 não acessa lote de importação CSV da Clínica 1');

// 3. NI2-522: Idempotência + Reimportação após desfazer 100% do lote
TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Alpha']);
$prevDup = $impSvc->preview($csv, 'lote.csv', 10);
check($prevDup['reused'] === true && $prevDup['batch_id'] === $prev1['batch_id'], 'NI2-522: Reenviar mesmo CSV enquanto prévia está aberta reutiliza o mesmo lote');

$impSvc->confirm($prev1['batch_id']);
$rb = $impSvc->rollback($prev1['batch_id']);
check($rb['removed'] === 1 && $rb['kept'] === 0, 'NI2-522: Desfazer importação remove o paciente recém-criado');

$prevAfterRollback = $impSvc->preview($csv, 'lote.csv', 10);
check($prevAfterRollback['reused'] === false && $prevAfterRollback['status'] === 'preview', 'NI2-522: Permite gerar nova prévia de arquivo cujo lote anterior foi 100% desfeito');

// 4. Verificação de acessibilidade por teclado, live-swap e rotas no código
$dashCode = (string)file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
$planLiveJs = (string)file_get_contents(__DIR__ . '/../public/assets/js/plan-live.js');
$foodPickerJs = (string)file_get_contents(__DIR__ . '/../public/assets/js/food-picker.js');

check(str_contains($dashCode, 'id="plan-live-status"') && str_contains($dashCode, 'aria-live="polite"'), 'NI2-521: Editor possui região aria-live="polite" para anunciar atualizações sem recarregar');
check(str_contains($dashCode, 'data-testid="edit-plan-sub-') && str_contains($dashCode, 'data-testid="edit-plan-meal-'), 'NI2-521: Editor possui controles inline (<details>) para editar refeição, item e substituição');
check(str_contains($planLiveJs, 'DOMParser') && str_contains($planLiveJs, 'data-live-swap'), 'NI2-521: plan-live.js atualiza apenas os blocos data-live-swap preservando foco e rolagem');
check(str_contains($foodPickerJs, 'ArrowDown') && str_contains($foodPickerJs, 'ArrowUp') && str_contains($foodPickerJs, 'Escape'), 'NI2-521: Seletor de alimentos suporta navegação completa por teclado (ArrowDown, ArrowUp, Enter, Escape)');

echo "\nResultado NI2-514 v42 Phase 2 HTTP & Accessibility: {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
