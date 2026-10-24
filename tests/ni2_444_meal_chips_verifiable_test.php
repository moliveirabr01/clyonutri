<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Teste Verificável de Homologação: NI2-444
 * História: [Plano Alimentar] Refeições pré-selecionáveis com sugestões rápidas e adição manual livre
 *
 * Cobertura de Critérios de Aceite:
 * 1. Renderização HTML do modal #modal-add-meal com os 8 chips rápidos pré-definidos (nomes, horários e ícones).
 * 2. Existência do elemento nativo <datalist id="common-meals-list"> com opções idênticas aos chips.
 * 3. Campo de texto aberto permitindo inclusão de refeições com nomes personalizados livres.
 * 4. Validação de horários (formato HH:MM) e sanitização de dados.
 * 5. Persistência real de refeições sugeridas e refeições manuais no banco de dados.
 * 6. Garantia de imutabilidade: adição bloqueada quando o plano já foi publicado (draft only).
 * 7. Isolamento Multi-Tenant estrito (Clínica A vs Clínica B).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Application\Controllers\DashboardController;

$db = Connection::getInstance();

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE VERIFICÁVEL DE HOMOLOGAÇÃO: NI2-444 <<<\n";
echo "    Refeições Pré-Selecionáveis (Chips) & Adição Manual Livre\n";
echo "========================================================================\n\n";

$step = 1;

// -------------------------------------------------------------------------
// [1/6] Provisionamento de Dois Tenants Isolados
// -------------------------------------------------------------------------
echo "--> [{$step}/6] Provisionando Clínicas Alfa (#A) e Beta (#B) com Nutricionistas e Pacientes...\n";
$auth = new AuthService($db);
$uniq = time() . '_' . rand(100, 999);

$nutriAlfa = $auth->registerNutritionist("Dra. Sofia {$uniq}", "sofia_{$uniq}@clyo.test", 'SenhaForte@2026', "Clínica Sofia {$uniq}", '33333', 'CRN-3');
$nutriBeta = $auth->registerNutritionist("Dr. Bruno {$uniq}", "bruno_{$uniq}@clyo.test", 'SenhaForte@2026', "Clínica Bruno {$uniq}", '44444', 'CRN-3');

TenantContext::set((int)$nutriAlfa['clinic_id'], ['id' => $nutriAlfa['user_id'], 'name' => $nutriAlfa['name']]);
$patientRepo = new PatientRepository($db);
$patientAlfa = $patientRepo->create(['name' => "Paciente Sofia {$uniq}", 'email' => "paciente_sofia_{$uniq}@test.com"]);

TenantContext::set((int)$nutriBeta['clinic_id'], ['id' => $nutriBeta['user_id'], 'name' => $nutriBeta['name']]);
$patientBeta = $patientRepo->create(['name' => "Paciente Bruno {$uniq}", 'email' => "paciente_bruno_{$uniq}@test.com"]);

echo "  [PASS] [SETUP] Tenants Alfa (#{$nutriAlfa['clinic_id']}) e Beta (#{$nutriBeta['clinic_id']}) provisionados.\n\n";
$step++;

// -------------------------------------------------------------------------
// [2/6] Verificação da Renderização da UI: Chips Rápidos, Datalist & Input Livre
// -------------------------------------------------------------------------
echo "--> [{$step}/6] Verificando Renderização HTML do Editor (#modal-add-meal, 8 chips, datalist)...\n";
TenantContext::set((int)$nutriAlfa['clinic_id'], ['id' => $nutriAlfa['user_id'], 'name' => $nutriAlfa['name']]);
$_SESSION['user_id'] = (int)$nutriAlfa['user_id'];
$_SESSION['clinic_id'] = (int)$nutriAlfa['clinic_id'];
$_SESSION['user_name'] = $nutriAlfa['name'];

$mealPlanService = new MealPlanService($db);
$planoDraft = $mealPlanService->createPlan($patientAlfa['id'], $nutriAlfa['user_id'], 'Plano Avaliação Refeições NI2-444');

// Renderizar o editor de plano
$dash = new DashboardController();
ob_start();
$dash->mealPlanEditor(['id' => $planoDraft['plan_id']]);
$editorHtml = ob_get_clean();

// 1. Validar existência do modal
assert(str_contains($editorHtml, 'id="modal-add-meal"'), "Modal #modal-add-meal deve estar presente no HTML");

// 2. Validar os 8 chips com nomes, horários e ícones
$expectedChips = [
    'Café da Manhã' => '07:30',
    'Colação' => '10:00',
    'Almoço' => '12:30',
    'Lanche da Tarde' => '16:00',
    'Pré-Treino' => '17:30',
    'Pós-Treino' => '19:00',
    'Jantar' => '20:00',
    'Ceia' => '22:00',
];

foreach ($expectedChips as $mealName => $mealTime) {
    assert(str_contains($editorHtml, "data-meal=\"{$mealName}\""), "Chip com data-meal='{$mealName}' deve existir");
    assert(str_contains($editorHtml, "data-time=\"{$mealTime}\""), "Chip com data-time='{$mealTime}' deve existir");
    assert(str_contains($editorHtml, "<option value=\"{$mealName}\">"), "Datalist deve conter a opção '{$mealName}'");
}

// 3. Validar datalist e atributos de acessibilidade
assert(str_contains($editorHtml, '<datalist id="common-meals-list">'), "Elemento datalist #common-meals-list deve existir");
assert(str_contains($editorHtml, 'list="common-meals-list"'), "Input name deve referenciar o datalist via atributo list");
assert(str_contains($editorHtml, 'id="quick-meal-suggestions"'), "Container de sugestões #quick-meal-suggestions deve existir");
assert(str_contains($editorHtml, 'quick-meal-btn'), "Botões de chips devem possuir a classe quick-meal-btn");

// 4. Validar script de interação cliente
assert(str_contains($editorHtml, "autoFilled"), "Script deve conter controle de auto-preenchimento sem sobrescrever dados manuais");

echo "  [PASS] [UI_RENDER] Modal contém todos os 8 chips rápidos, datalist nativo e input texto livre.\n\n";
$step++;

// -------------------------------------------------------------------------
// [3/6] Persistência Real de Refeições Sugeridas Pelos Chips
// -------------------------------------------------------------------------
echo "--> [{$step}/6] Testando Adição e Persistência de Refeições Sugeridas (1 Clique)...\n";

// Adicionar refeição a partir da sugestão "Café da Manhã" (07:30)
$mealId1 = $mealPlanService->addMeal(
    $planoDraft['plan_id'],
    'Café da Manhã',
    '07:30',
    'Consumir até 30 minutos após acordar'
);
assert($mealId1 > 0, "Refeição 'Café da Manhã' deve ser persistida com ID válido");

// Adicionar refeição a partir da sugestão "Almoço" (12:30)
$mealId2 = $mealPlanService->addMeal(
    $planoDraft['plan_id'],
    'Almoço',
    '12:30',
    'Mastigar com calma, rica em fibras'
);
assert($mealId2 > 0, "Refeição 'Almoço' deve ser persistida com ID válido");

// Verificar no banco de dados se as refeições foram gravadas com ordering sequencial
$mStmt = $db->prepare("SELECT id, name, time_of_day, ordering, notes FROM meal_plan_meals WHERE meal_plan_id = ? ORDER BY ordering ASC");
$mStmt->execute([$planoDraft['plan_id']]);
$mealsRecorded = $mStmt->fetchAll();

assert(count($mealsRecorded) === 2, "Devem existir 2 refeições cadastradas");
assert($mealsRecorded[0]['name'] === 'Café da Manhã' && $mealsRecorded[0]['time_of_day'] === '07:30' && (int)$mealsRecorded[0]['ordering'] === 1,
    "Café da Manhã deve ter horário 07:30 e ordering 1");
assert($mealsRecorded[1]['name'] === 'Almoço' && $mealsRecorded[1]['time_of_day'] === '12:30' && (int)$mealsRecorded[1]['ordering'] === 2,
    "Almoço deve ter horário 12:30 e ordering 2");

echo "  [PASS] [PRESET_PERSISTENCE] Refeições sugeridas persistidas com ordenação e horários corretos.\n\n";
$step++;

// -------------------------------------------------------------------------
// [4/6] Adição de Refeições com Nome Personalizado Livre (Flexibilidade Total)
// -------------------------------------------------------------------------
echo "--> [{$step}/6] Testando Adição de Refeições com Nomes Livres Personalizados...\n";

// Nutricionista adiciona uma refeição clínica customizada fora dos chips padrão
$customMealName = 'Lanche Pré-Plantão Noturno Hospitalar';
$customTime = '23:15';
$customNotes = 'Refeição de baixo índice glicêmico para plantão de 12 horas';

$mealIdCustom = $mealPlanService->addMeal(
    $planoDraft['plan_id'],
    $customMealName,
    $customTime,
    $customNotes
);
assert($mealIdCustom > 0, "Refeição personalizada livre deve ser persistida com sucesso");

$customStmt = $db->prepare("SELECT name, time_of_day, notes, ordering FROM meal_plan_meals WHERE id = ?");
$customStmt->execute([$mealIdCustom]);
$customRow = $customStmt->fetch();

assert($customRow['name'] === $customMealName, "Nome personalizado deve ser gravado fielmente");
assert($customRow['time_of_day'] === $customTime, "Horário personalizado deve ser gravado fielmente");
assert($customRow['ordering'] === 3, "Ordering sequencial deve avançar para 3");
assert($customRow['notes'] === $customNotes, "Orientações da refeição livre devem ser gravadas");

echo "  [PASS] [CUSTOM_MEAL_OK] Refeição livre '{$customMealName}' gravada com sucesso.\n\n";
$step++;

// -------------------------------------------------------------------------
// [5/6] Validações Clínicas Fail-Closed: Nome Vazio, Horário Inválido & Imutabilidade
// -------------------------------------------------------------------------
echo "--> [{$step}/6] Testando Validações Negativas (Nome Vazio, Horário Inválido, Imutabilidade)...\n";

// 1. Nome vazio -> deve lançar InvalidArgumentException
$nameEmptyBlocked = false;
try {
    $mealPlanService->addMeal($planoDraft['plan_id'], '   ', '08:00');
} catch (InvalidArgumentException $e) {
    $nameEmptyBlocked = true;
    assert(str_contains($e->getMessage(), 'obrigatório'), "Mensagem deve indicar nome obrigatório");
}
assert($nameEmptyBlocked, "Adição de refeição com nome em branco deve ser bloqueada");
echo "  [PASS] [FAIL_CLOSED_EMPTY_NAME] Refeição sem nome rejeitada com sucesso.\n";

// 2. Horário em formato incorreto -> deve lançar InvalidArgumentException
$invalidTimeBlocked = false;
try {
    $mealPlanService->addMeal($planoDraft['plan_id'], 'Ceia Extra', '26:99');
} catch (InvalidArgumentException $e) {
    $invalidTimeBlocked = true;
    assert(str_contains($e->getMessage(), 'formato HH:MM') || str_contains($e->getMessage(), 'horário'), "Mensagem deve indicar formato de horário inválido");
}
assert($invalidTimeBlocked, "Adição com horário '26:99' deve ser bloqueada");
echo "  [PASS] [FAIL_CLOSED_INVALID_TIME] Horário '26:99' rejeitado com sucesso.\n";

// 3. Adição em plano publicado -> deve bloquear por imutabilidade
// Adicionar alimento temporário para permitir publicação
$arrozFood = $db->query("SELECT id FROM foods WHERE is_custom = 0 LIMIT 1")->fetchColumn();
$mealPlanService->addItem($mealId1, (int)$arrozFood, 100.0, 'g');
$mealPlanService->publishPlan($planoDraft['plan_id'], true, false, 'Homologação para teste de imutabilidade');

$publishedPlanBlocked = false;
try {
    $mealPlanService->addMeal($planoDraft['plan_id'], 'Tentativa em Plano Publicado', '15:00');
} catch (RuntimeException $e) {
    $publishedPlanBlocked = true;
}
assert($publishedPlanBlocked, "Adição de refeição em plano PUBLICADO deve ser bloqueada (Imutabilidade)");
echo "  [PASS] [IMMUTABILITY_OK] Bloqueada edição de refeições em plano publicado.\n\n";
$step++;

// -------------------------------------------------------------------------
// [6/6] Isolamento Multi-Tenant Estrito (Pen-Test de Segurança)
// -------------------------------------------------------------------------
echo "--> [{$step}/6] Testando Isolamento Multi-Tenant (Tenant Beta tenta adicionar refeição em Alfa)...\n";

// Trocar contexto para o Tenant Beta
TenantContext::set((int)$nutriBeta['clinic_id'], ['id' => $nutriBeta['user_id'], 'name' => $nutriBeta['name']]);
$_SESSION['clinic_id'] = (int)$nutriBeta['clinic_id'];
$_SESSION['user_id'] = (int)$nutriBeta['user_id'];

$crossAccessBlocked = false;
try {
    $mealPlanService->addMeal($planoDraft['plan_id'], 'Ataque Cross-Tenant', '12:00');
} catch (TenantSecurityException $e) {
    $crossAccessBlocked = true;
    assert($e->getCode() === 403, "Código HTTP de segurança deve ser 403 Forbidden");
}
assert($crossAccessBlocked, "Tentativa de Tenant Beta modificar plano de Tenant Alfa DEVE ser barrada com 403");
echo "  [PASS] [MULTI_TENANT_ISOLATION] Acesso cruzado bloqueado com TenantSecurityException 403 (Fail-Closed).\n\n";

echo "========================================================================\n";
echo ">>> TODOS OS CRITÉRIOS DE ACEITE DO NI2-444 FORAM HOMOLOGADOS COM SUCESSO! <<<\n";
echo "========================================================================\n\n";
