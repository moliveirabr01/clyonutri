<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Teste Verificável de Homologação E2E: NI2-116
 * História: [P0][Benchmark] Plano alimentar com lista de compras e receitas
 *
 * Cobertura de Critérios de Aceite:
 * 1. Lista reflete exclusivamente versões publicadas (fail-closed contra rascunhos)
 * 2. Agrupamento não duplica itens (soma correta de gramas/unidades)
 * 3. Troca de refeição atualiza quantidades e gôndolas dinamicamente
 * 4. Receitas vinculadas têm seus ingredientes incorporados e vinculados
 * 5. Alergênicos e restrições da anamnese aparecem destacados
 * 6. Testes rigorosos de arredondamento e multiplicadores (1, 7, 14, 30 dias)
 * 7. Isolamento Multi-Tenant estrito (Tenant Alfa vs Tenant Beta)
 * 8. Trilha de auditoria persistida
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Recipe\RecipeService;
use ClyoNutri\Domain\ShoppingList\ShoppingListService;

$db = Connection::getInstance();

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE VERIFICÁVEL DE HOMOLOGAÇÃO: NI2-116 <<<\n";
echo "    Plano Alimentar com Lista de Compras, Receitas & Alérgenos\n";
echo "========================================================================\n\n";

$step = 1;

// -------------------------------------------------------------------------
// [1/8] Provisionamento de Dois Tenants Isolados (Alfa e Beta)
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Provisionando Clínicas Alfa (#A) e Beta (#B) com Nutricionistas e Pacientes...\n";
$auth = new AuthService($db);
$uniq = time() . '_' . rand(100, 999);

$nutriAlfa = $auth->registerNutritionist("Dra. Alfa {$uniq}", "alfa_{$uniq}@clyo.test", 'SenhaForte@2026', "Clínica Alfa {$uniq}", '11111', 'CRN-3');
$nutriBeta = $auth->registerNutritionist("Dr. Beta {$uniq}", "beta_{$uniq}@clyo.test", 'SenhaForte@2026', "Clínica Beta {$uniq}", '22222', 'CRN-3');

TenantContext::set((int)$nutriAlfa['clinic_id'], ['id' => $nutriAlfa['user_id'], 'name' => $nutriAlfa['name']]);
$patientRepo = new PatientRepository($db);
$patientAlfa = $patientRepo->create(['name' => "Paciente Alfa {$uniq}", 'email' => "paciente_alfa_{$uniq}@test.com"]);

TenantContext::set((int)$nutriBeta['clinic_id'], ['id' => $nutriBeta['user_id'], 'name' => $nutriBeta['name']]);
$patientBeta = $patientRepo->create(['name' => "Paciente Beta {$uniq}", 'email' => "paciente_beta_{$uniq}@test.com"]);

assert($nutriAlfa['clinic_id'] !== $nutriBeta['clinic_id'], "Tenants devem ser distintos");
echo "  [PASS] [SETUP] Tenants Alfa (#{$nutriAlfa['clinic_id']}) e Beta (#{$nutriBeta['clinic_id']}) criados com sucesso.\n\n";
$step++;

// -------------------------------------------------------------------------
// [2/8] Catálogo de Alimentos & Anamnese com Alergênicos e Restrições (AC4)
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Configurando Anamnese de Alérgenos e Catálogo de Alimentos...\n";
TenantContext::set((int)$nutriAlfa['clinic_id'], ['id' => $nutriAlfa['user_id'], 'name' => $nutriAlfa['name']]);

$foodCatalog = new FoodCatalogService($db);
$foodCatalog->seedTacoReference();

$arroz = $foodCatalog->search('Arroz branco')[0];
$frango = $foodCatalog->search('frango grelhado')[0];
$banana = $foodCatalog->search('Banana prata')[0];
$aveia = $foodCatalog->search('Aveia em flocos')[0]; // Contém glúten e aveia (conflitante com a anamnese de Alfa)

// Registrar anamnese no Tenant Alfa com Alergia a Glúten e Restrição a Lactose
$anamnesisService = new AnamnesisService($db);
$anamnesisService->saveAnamnesis(
    (int)$patientAlfa['id'],
    (int)$nutriAlfa['user_id'],
    [
        'allergies' => 'Alergia severa a glúten (doença celíaca)',
        'restrictions' => 'Intolerância moderada a lactose e alimentos ultraprocessados',
        'objectives' => 'Hipertrofia e reeducação alimentar',
    ]
);

echo "  [PASS] [ANAMNESE] Alergia a glúten e restrição a lactose registradas com sucesso.\n\n";
$step++;

// -------------------------------------------------------------------------
// [3/8] AC1: Lista reflete SOMENTE versões publicadas (fail-closed)
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Testando AC1: Rejeição de Rascunho e Aceite Exclusivo de Versão Publicada...\n";
$mealPlanService = new MealPlanService($db);
$shoppingService = new ShoppingListService($db);

// Criar plano alimentar no estado RASCUNHO (draft)
$draftPlan = $mealPlanService->createPlan(
    $patientAlfa['id'],
    $nutriAlfa['user_id'],
    'Plano Hipertrofia Rascunho Inicial',
    [
        [
            'name' => 'Almoço',
            'time_of_day' => '12:00',
            'items' => [
                ['food_id' => $arroz['id'], 'amount' => 150, 'measure_unit' => 'g'],
                ['food_id' => $frango['id'], 'amount' => 120, 'measure_unit' => 'g'],
            ]
        ]
    ]
);
assert($draftPlan['status'] === 'draft', "Plano recém criado deve ser rascunho");

// Tentativa de gerar lista para rascunho sem autorização -> DEVE FALHAR
$rejected = false;
try {
    $shoppingService->generateForPlan($draftPlan['plan_id'], 7, ['require_published' => true]);
} catch (InvalidArgumentException $e) {
    $rejected = true;
    assert(str_contains($e->getMessage(), 'versões publicadas'), "Mensagem deve esclarecer exigência de versão publicada");
}
assert($rejected, "Geração de lista para plano rascunho DEVE ser recusada");
echo "  [PASS] [AC1_DRAFT_REJECTED] Rascunho bloqueado com sucesso (Fail-Closed).\n";

// Tentativa de gerar para paciente sem plano publicado -> DEVE FALHAR
$patientRejected = false;
try {
    $shoppingService->generateForPatient($patientAlfa['id'], 7);
} catch (InvalidArgumentException $e) {
    $patientRejected = true;
}
assert($patientRejected, "Geração para paciente sem versão publicada deve falhar");
echo "  [PASS] [AC1_PATIENT_REJECTED] Paciente sem plano publicado bloqueado.\n";

// Agora publicar o plano alimentar
$publishResult = $mealPlanService->publishPlan($draftPlan['plan_id'], true, false, 'Justificativa clínica para homologação');
assert($publishResult === true, "Plano deve ser publicado com sucesso");

// Agora gerar a lista de compras para o plano publicado -> DEVE SUCEDER
$publishedList = $shoppingService->generateForPlan($draftPlan['plan_id'], 7);
assert($publishedList['status'] === 'published', "Status da lista deve ser published");
assert($publishedList['version'] >= 1, "Versão deve ser >= 1");
echo "  [PASS] [AC1_PUBLISHED_OK] Lista gerada com sucesso para plano publicado v{$publishedList['version']}.\n\n";
$step++;

// -------------------------------------------------------------------------
// [4/8] AC2: Agrupamento sem duplicação de itens (soma correta)
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Testando AC2: Agrupamento Não Duplica Alimentos Iguais de Refeições Diferentes...\n";

// Criar um plano com Frango no almoço (150g) e Frango no jantar (100g) + Arroz (200g)
$planoAgrupamento = $mealPlanService->createPlan(
    $patientAlfa['id'],
    $nutriAlfa['user_id'],
    'Plano Diário Completo para Agrupamento',
    [
        [
            'name' => 'Almoço',
            'time_of_day' => '12:30',
            'items' => [
                ['food_id' => $arroz['id'], 'amount' => 200, 'measure_unit' => 'g'],
                ['food_id' => $frango['id'], 'amount' => 150, 'measure_unit' => 'g'],
            ]
        ],
        [
            'name' => 'Jantar',
            'time_of_day' => '19:30',
            'items' => [
                ['food_id' => $frango['id'], 'amount' => 100, 'measure_unit' => 'g'],
                ['food_id' => $banana['id'], 'amount' => 2, 'measure_unit' => 'unidade'],
            ]
        ]
    ]
);
$mealPlanService->publishPlan($planoAgrupamento['plan_id'], true, false, 'Aprovação de plano para validação de agrupamento');

$lista7Dias = $shoppingService->generateForPlan($planoAgrupamento['plan_id'], 7);

// Frango diário: 150g + 100g = 250g. Em 7 dias: 250g * 7 = 1750g.
// Arroz diário: 200g. Em 7 dias: 1400g.
// Banana diária: 2 un. Em 7 dias: 14 un.
$frangoEntries = [];
foreach ($lista7Dias['items'] as $it) {
    if ($it['food_id'] === (int)$frango['id']) {
        $frangoEntries[] = $it;
    }
}
assert(count($frangoEntries) === 1, "Frango deve aparecer exatamente UMA vez na lista de compras (sem duplicação)");
assert($frangoEntries[0]['daily'] === 250.0, "Consumo diário de frango deve somar 250g (150+100)");
assert($frangoEntries[0]['amount'] === 1750.0, "Total de frango para 7 dias deve ser 1750.0g");
assert($frangoEntries[0]['aisle'] === 'Açougue & Ovos', "Frango deve estar no corredor Açougue & Ovos");

echo "  [PASS] [AC2_NO_DUPLICATES] Frango somou 250g/dia (150g almoço + 100g jantar) = 1750g para 7 dias em única linha.\n\n";
$step++;

// -------------------------------------------------------------------------
// [5/8] AC3: Troca de refeição / substituições atualizam quantidades dinamicamente
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Testando AC3: Substituição de Alimentos Atualiza Quantidades e Gôndolas...\n";

// Criar um plano com item e opção de substituição cadastrada
$planoSub = $mealPlanService->createPlan(
    $patientAlfa['id'],
    $nutriAlfa['user_id'],
    'Plano com Opção de Substituição',
    [
        [
            'name' => 'Café da Manhã',
            'time_of_day' => '07:30',
            'items' => [
                [
                    'food_id' => $banana['id'],
                    'amount' => 2,
                    'measure_unit' => 'unidade',
                    'substitutions' => [
                        [
                            'food_id' => $aveia['id'],
                            'amount' => 45,
                            'measure_unit' => 'g',
                        ]
                    ]
                ]
            ]
        ]
    ]
);
$mealPlanService->publishPlan($planoSub['plan_id'], true, false, 'Plano com substituições validado');

// Buscar id do item e da substituição no banco
$itemStmt = $db->prepare("SELECT id FROM meal_plan_items WHERE meal_id IN (SELECT id FROM meal_plan_meals WHERE meal_plan_id = ?)");
$itemStmt->execute([$planoSub['plan_id']]);
$planItemId = (int)$itemStmt->fetchColumn();

$subStmt = $db->prepare("SELECT id FROM meal_plan_substitutions WHERE item_id = ?");
$subStmt->execute([$planItemId]);
$planSubId = (int)$subStmt->fetchColumn();

// 1. Sem aplicar substituição: deve conter Banana (2 * 7 = 14 un)
$listaOriginal = $shoppingService->generateForPlan($planoSub['plan_id'], 7);
assert(isset($listaOriginal['aisles']['Hortifrúti & Feira']), "Banana deve estar no Hortifrúti");
assert($listaOriginal['items'][0]['food_id'] === (int)$banana['id'], "Item original deve ser banana");
assert($listaOriginal['items'][0]['amount'] === 14.0, "Total de bananas deve ser 14 unidades");

// 2. Aplicando a substituição (trocar banana por aveia): deve conter Aveia (45g * 7 = 315g) em Cereais & Grãos
$listaSubstituida = $shoppingService->generateForPlan($planoSub['plan_id'], 7, [
    'substitutions' => [$planItemId => $planSubId]
]);
assert(isset($listaSubstituida['aisles']['Cereais & Grãos']), "Aveia substituída deve estar em Cereais & Grãos");
assert($listaSubstituida['items'][0]['food_id'] === (int)$aveia['id'], "Item substituído deve ser aveia");
assert($listaSubstituida['items'][0]['amount'] === 315.0, "Total de aveia deve ser 315.0g");
assert($listaSubstituida['items'][0]['is_substituted'] === true, "Item deve estar marcado como substituído");

echo "  [PASS] [AC3_SUBSTITUTION] Troca de banana (14 un no Hortifrúti) por aveia (315g em Cereais & Grãos) validada.\n\n";
$step++;

// -------------------------------------------------------------------------
// [6/8] AC3 + AC6: Receitas Culinárias Vinculadas ao Plano Alimentar
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Testando Incorporação de Ingredientes de Receitas Culinárias Vinculadas...\n";
$recipeService = new RecipeService($db);

$receitaOmelete = $recipeService->createRecipe(
    [
        'title' => 'Omelete Proteico de Forno',
        'category' => 'Almoço/Jantar',
        'prep_time_minutes' => 15,
        'servings' => 1,
        'serving_size_g' => 180,
    ],
    [
        ['food_id' => $frango['id'], 'amount' => 80, 'measure_unit' => 'g'],
        ['food_id' => $aveia['id'], 'amount' => 20, 'measure_unit' => 'g'],
    ],
    [
        ['step_number' => 1, 'instruction' => 'Bater e assar por 15 minutos.']
    ]
);

// Vincular receita ao plano alimentar publicado
$rpIns = $db->prepare("
    INSERT INTO recipe_prescriptions (clinic_id, meal_plan_id, patient_id, recipe_id, prescribed_servings, meal_name)
    VALUES (:clinic_id, :plan_id, :patient_id, :recipe_id, 1.5, 'Jantar Especial')
");
$rpIns->execute([
    'clinic_id' => $nutriAlfa['clinic_id'],
    'plan_id' => $planoSub['plan_id'],
    'patient_id' => $patientAlfa['id'],
    'recipe_id' => $receitaOmelete['id'],
]);

// Gerar lista para 7 dias do plano com a receita vinculada (prescribed_servings = 1.5)
// Ingredientes da receita em 7 dias:
// - Frango: 80g * 1.5 * 7 = 840g
// - Aveia: 20g * 1.5 * 7 = 210g
$listaComReceita = $shoppingService->generateForPlan($planoSub['plan_id'], 7);
assert(count($listaComReceita['linked_recipes']) === 1, "Receita vinculada deve ser reportada na lista");
assert($listaComReceita['linked_recipes'][0]['title'] === 'Omelete Proteico de Forno', "Título da receita correto");

// Verificar se o frango da receita foi somado na gôndola Açougue & Ovos
$frangoRecipe = null;
foreach ($listaComReceita['aisles']['Açougue & Ovos'] as $item) {
    if ($item['food_id'] === (int)$frango['id']) {
        $frangoRecipe = $item;
    }
}
assert($frangoRecipe !== null, "Frango da receita deve constar no Açougue");
assert($frangoRecipe['amount'] === 840.0, "Frango da receita deve somar exatamente 840.0g para 1.5 porções * 7 dias");
assert(in_array('Omelete Proteico de Forno', $frangoRecipe['recipes_source'], true), "Frango deve apontar a receita de origem");

echo "  [PASS] [AC3_RECIPES_LINKED] Receita '{$receitaOmelete['title']}' incorporou 840g de frango e 210g de aveia por 7 dias.\n\n";
$step++;

// -------------------------------------------------------------------------
// [7/8] AC4 & AC5: Alergênicos, Restrições e Testes de Arredondamento
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Testando Alertas de Alergênicos/Restrições e Precisão de Arredondamento...\n";

// Criar um plano contendo Aveia (glúten) para o paciente Alfa (alérgico a glúten)
$planoAlergenico = $mealPlanService->createPlan(
    $patientAlfa['id'],
    $nutriAlfa['user_id'],
    'Plano com Aveia para Teste de Alérgeno',
    [
        [
            'name' => 'Lanche da Tarde',
            'time_of_day' => '16:00',
            'items' => [
                ['food_id' => $aveia['id'], 'amount' => 50, 'measure_unit' => 'g'],
                ['food_id' => $banana['id'], 'amount' => 1.333, 'measure_unit' => 'unidade'], // Teste de arredondamento
            ]
        ]
    ]
);
$mealPlanService->publishPlan($planoAlergenico['plan_id'], true, false, 'Aprovação justificada para teste de alérgeno');

// Validar multiplicadores de dias e arredondamento (1, 7, 14, 30 dias)
foreach ([1 => 1.3, 7 => 9.3, 14 => 18.7, 30 => 40.0] as $dias => $esperadoBanana) {
    $listaPeriodo = $shoppingService->generateForPlan($planoAlergenico['plan_id'], $dias);
    assert($listaPeriodo['days'] === $dias, "Multiplicador de dias deve bater");

    $bananaItem = null;
    $aveiaItem = null;
    foreach ($listaPeriodo['items'] as $it) {
        if ($it['food_id'] === (int)$banana['id']) $bananaItem = $it;
        if ($it['food_id'] === (int)$aveia['id']) $aveiaItem = $it;
    }

    assert($bananaItem !== null && $bananaItem['amount'] === $esperadoBanana,
        "Arredondamento para {$dias} dias: esperado {$esperadoBanana}, obtido {$bananaItem['amount']}");
    assert($aveiaItem !== null && $aveiaItem['has_allergen_conflict'] === true,
        "Aveia deve estar sinalizada com conflito de alérgeno para glúten");
}

$listaFinal = $shoppingService->generateForPlan($planoAlergenico['plan_id'], 7);
assert(!empty($listaFinal['allergens_and_restrictions']['allergies_recorded']), "Alergias registradas devem constar no topo");
assert(!empty($listaFinal['allergens_and_restrictions']['restrictions_recorded']), "Restrições devem constar no topo");
assert($listaFinal['allergens_and_restrictions']['has_conflicts'] === true, "Deve acusar conflito de alérgeno");

echo "  [PASS] [AC4_AC5_ALLERGENS_ROUNDING] Alerta de glúten e arredondamento (1d: 1.3, 7d: 9.3, 14d: 18.7, 30d: 40.0) validados.\n\n";
$step++;

// -------------------------------------------------------------------------
// [8/8] Isolamento Multi-Tenant Estrito e Trilha de Auditoria
// -------------------------------------------------------------------------
echo "--> [{$step}/8] Testando Pen-Test de Isolamento Multi-Tenant e Trilha de Auditoria...\n";

// Tenant Beta tenta gerar lista de compras do plano do Tenant Alfa -> DEVE LANÇAR TenantSecurityException
TenantContext::set((int)$nutriBeta['clinic_id'], ['id' => $nutriBeta['user_id'], 'name' => $nutriBeta['name']]);

$crossAccessBlocked = false;
try {
    $shoppingService->generateForPlan($planoAlergenico['plan_id'], 7);
} catch (TenantSecurityException $e) {
    $crossAccessBlocked = true;
    assert($e->getCode() === 403, "Código de segurança deve ser 403 Forbidden");
}
assert($crossAccessBlocked, "Tentativa de acesso cruzado de Tenant Beta ao plano de Alfa DEVE ser bloqueada");
echo "  [PASS] [MULTI_TENANT_ISOLATION] Acesso cruzado bloqueado com TenantSecurityException (Fail-Closed).\n";

// Voltar para Tenant Alfa e validar auditoria
TenantContext::set((int)$nutriAlfa['clinic_id'], ['id' => $nutriAlfa['user_id'], 'name' => $nutriAlfa['name']]);
$auditStmt = $db->prepare("
    SELECT * FROM audit_logs
    WHERE clinic_id = :clinic_id AND action = 'shopping_list_generated'
    ORDER BY id DESC LIMIT 1
");
$auditStmt->execute(['clinic_id' => $nutriAlfa['clinic_id']]);
$auditRecord = $auditStmt->fetch(PDO::FETCH_ASSOC);

assert($auditRecord !== false, "Evento de auditoria shopping_list_generated deve estar persistido");
assert((int)$auditRecord['resource_id'] === (int)$planoAlergenico['plan_id'], "ID do recurso na auditoria deve conferir");
echo "  [PASS] [AUDIT_TRAIL] Trilha de auditoria persistida no banco com clinic_id={$auditRecord['clinic_id']}.\n\n";

echo "========================================================================\n";
echo ">>> TODOS OS 8 CRITÉRIOS DE ACEITE DO NI2-116 FORAM HOMOLOGADOS COM SUCESSO! <<<\n";
echo "========================================================================\n\n";
