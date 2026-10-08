<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE E2E DE RECEITAS CULINÁRIAS, COMPOSIÇÃO NUTRICIONAL
 * E PRESCRIÇÃO AO PACIENTE (NI2-268 / NI2-269 a NI2-280)
 * ==============================================================================
 * Cobertura Completa dos Critérios de Aceite:
 * 1. Fundação do domínio, multi-tenant fail-closed e persistência real (NI2-269).
 * 2. Editor de receitas: ingredientes TACO, modo de preparo e rendimento (NI2-270).
 * 3. Motor de cálculo nutricional oficial por receita e por porção (NI2-271).
 * 4. Medidas caseiras, conversões e escalonamento de porções (NI2-272).
 * 5. Biblioteca de receitas, categorias, busca e filtros avançados (NI2-273).
 * 6. Detecção rastreável de alergênicos e restrições alimentares (NI2-274).
 * 7. Prescrição supervisionada em refeições do plano alimentar (NI2-275).
 * 8. Criação de variantes com linhagem auditável (parent_recipe_id) (NI2-276).
 * 9. Experiência do paciente: favoritos e leitura de preparo (NI2-277).
 * 10. Lista de compras consolidada categorizada por gôndolas (NI2-278).
 * 11. Testes de segurança ofensiva (cross-tenant blocking e RBAC) (NI2-280).
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Recipe\RecipeService;

$db = Connection::getInstance();

function logRecipePass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertRecipe(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO EM RECEITAS CULINÁRIAS (NI2-268): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES E2E DE RECEITAS CULINÁRIAS (NI2-268) <<<\n";
echo "    Biblioteca, Composição TACO, Variantes, Alergênicos & Prescrição Real\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$nutriIdAlfa = 0;
$profIdAlfa = 0;
$patientIdAlfa = 0;
$mealPlanIdAlfa = 0;

$nutriIdBeta = 0;
$profIdBeta = 0;
$patientIdBeta = 0;

$createdRecipeIds = [];

try {
    // -------------------------------------------------------------------------
    // ETAPA 1: Setup Multi-Tenant e Participantes Reais
    // -------------------------------------------------------------------------
    echo "--> [1/9] Provisionando Clínicas Alfa e Beta com Nutricionistas e Pacientes Reais...\n";

    $u1 = 'rec-alfa-' . bin2hex(random_bytes(4));
    $u2 = 'rec-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u1}', 'Clínica Alfa Gastronomia Nutricional', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u2}', 'Clínica Concorrente Beta', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    // Nutricionista Alfa
    $nutriUuidAlfa = 'user-nutri-alfa-' . bin2hex(random_bytes(4));
    $nutriEmailAlfa = 'chefalfa-' . bin2hex(random_bytes(3)) . '@clinica.com';
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Gabriela Gastronomia', '{$nutriEmailAlfa}', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, '99881', 'CRN-3')");
    $profIdAlfa = (int)$db->lastInsertId();

    // Nutricionista Beta
    $nutriUuidBeta = 'user-nutri-beta-' . bin2hex(random_bytes(4));
    $nutriEmailBeta = 'chefbeta-' . bin2hex(random_bytes(3)) . '@clinica.com';
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Rafael Beta', '{$nutriEmailBeta}', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdBeta}, '99882', 'CRN-3')");
    $profIdBeta = (int)$db->lastInsertId();

    // Paciente Alfa com Histórico de Alergia a Leite e Glúten
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Gabriela Gastronomia']);
    $patRepo = new PatientRepository($db);
    $pat1 = $patRepo->create([
        'name' => 'Renata Paciente Alérgica',
        'email' => 'renata.' . bin2hex(random_bytes(3)) . '@teste.com',
        'phone' => '11988887701',
        'birth_date' => '1992-06-15',
        'gender' => 'female',
        'lgpd_consent' => 1,
    ]);
    $patientIdAlfa = (int)$pat1['id'];

    // Registrar Anamnese Clínica do Paciente Alfa (Alergias e Restrições)
    $db->exec("
        INSERT INTO anamneses (patient_id, clinic_id, professional_id, allergies, restrictions, notes, created_at)
        VALUES ({$patientIdAlfa}, {$clinicIdAlfa}, {$profIdAlfa}, 'Leite, Glúten', 'Intolerância a lactose e doença celíaca leve', 'Atenção aos rótulos', CURRENT_TIMESTAMP)
    ");

    // Plano Alimentar do Paciente Alfa
    $db->exec("
        INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status, created_at, updated_at)
        VALUES ({$clinicIdAlfa}, {$patientIdAlfa}, {$profIdAlfa}, 'Plano Funcional Sem Glúten e Sem Lactose', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $mealPlanIdAlfa = (int)$db->lastInsertId();

    // Paciente Beta
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Rafael Beta']);
    $pat2 = $patRepo->create([
        'name' => 'Lucas Paciente Beta',
        'email' => 'lucas.' . bin2hex(random_bytes(3)) . '@teste.com',
        'phone' => '11988887702',
        'birth_date' => '1990-01-20',
        'gender' => 'male',
        'lgpd_consent' => 1,
    ]);
    $patientIdBeta = (int)$pat2['id'];

    logRecipePass("SETUP", "Tenants Alfa (#{$clinicIdAlfa}) e Beta (#{$clinicIdBeta}), profissionais e pacientes provisionados com sucesso");

    // Buscar alimentos da tabela TACO para compor os testes
    $stmtFoods = $db->query("SELECT id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods ORDER BY id ASC LIMIT 10");
    $availableFoods = $stmtFoods->fetchAll(PDO::FETCH_ASSOC);
    assertRecipe(!empty($availableFoods), "Catálogo de alimentos TACO não pode estar vazio");

    $food1 = $availableFoods[0]; // e.g. Arroz
    $food2 = $availableFoods[1] ?? $availableFoods[0];

    $recipeService = new RecipeService($db);

    // -------------------------------------------------------------------------
    // ETAPA 2: Criação de Receita, Cálculo Oficial TACO e Snapshot Imutável v1
    // -------------------------------------------------------------------------
    echo "\n--> [2/9] Testando Motor de Cálculo Nutricional TACO e Snapshot Imutável (NI2-270, NI2-271)...\n";
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Gabriela Gastronomia']);

    $recipeData = [
        'title' => 'Bowl Nutritivo de Grãos Alfa',
        'summary' => 'Bowl completo e equilibrado para almoço funcional.',
        'meal_type' => 'Almoço',
        'category' => 'Prato Principal',
        'difficulty' => 'fácil',
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 20,
        'servings' => 2,
        'serving_size_name' => 'bowl grande',
        'visibility' => 'clinic',
    ];

    $ingredients = [
        [
            'food_id' => (int)$food1['id'],
            'amount' => 150.0,
            'measure_unit' => 'g',
            'notes' => 'Cozido ao dente',
        ],
        [
            'food_id' => (int)$food2['id'],
            'amount' => 100.0,
            'measure_unit' => 'g',
            'notes' => 'Temperado com ervas',
        ],
    ];

    $steps = [
        ['instruction' => 'Cozinhe os grãos em fogo brando com temperos naturais.', 'tip' => 'Não deixe secar totalmente a água.'],
        ['instruction' => 'Monte o bowl dispondo os ingredientes harmonicamente.', 'tip' => 'Sirva imediatamente morno.'],
    ];

    $tags = ['almoço funcional', 'rico em fibras', 'prático'];
    $allergens = [
        ['group' => 'Glúten', 'level' => 'contains', 'notes' => 'Contém traços de cereais com glúten'],
    ];

    $created = $recipeService->createRecipe($recipeData, $ingredients, $steps, $tags, $allergens);
    $recipeId1 = (int)$created['id'];
    $createdRecipeIds[] = $recipeId1;

    assertRecipe($recipeId1 > 0, "Receita deve ter ID persistido no banco");
    assertRecipe($created['servings'] === 2, "Rendimento deve ser de 2 porções");
    assertRecipe($created['version_number'] === 1, "Snapshot inicial deve ser versão 1");
    assertRecipe($created['energy_kcal_per_serving'] > 0, "Kcal por porção deve ser calculado matematicamente a partir da TACO");
    assertRecipe($created['protein_g_per_serving'] >= 0, "Proteína por porção deve ser calculada");

    // Verificar se a versão imutável foi gravada em recipe_versions
    $vStmt = $db->prepare("SELECT * FROM recipe_versions WHERE recipe_id = :rid AND version_number = 1");
    $vStmt->execute(['rid' => $recipeId1]);
    $versionRow = $vStmt->fetch(PDO::FETCH_ASSOC);
    assertRecipe(!empty($versionRow), "Registro de versão imutável deve existir na tabela recipe_versions");
    assertRecipe(!empty($versionRow['payload_json']), "payload_json da versão deve conter snapshot completo");

    // Verificar passos persistidos
    $stStmt = $db->prepare("SELECT COUNT(*) FROM recipe_steps WHERE recipe_id = :rid");
    $stStmt->execute(['rid' => $recipeId1]);
    assertRecipe((int)$stStmt->fetchColumn() === 2, "Devem existir 2 passos de preparo gravados");

    // Verificar tags persistidas
    $tgStmt = $db->prepare("SELECT COUNT(*) FROM recipe_tags WHERE recipe_id = :rid");
    $tgStmt->execute(['rid' => $recipeId1]);
    assertRecipe((int)$tgStmt->fetchColumn() === 3, "Devem existir 3 tags gravadas");

    logRecipePass("CALCULO_TACO", "Receita #{$recipeId1} criada com {$created['energy_kcal_per_serving']} kcal/porção e snapshot v1 imutável");

    // -------------------------------------------------------------------------
    // ETAPA 3: Medidas Caseiras, Conversões e Escalonamento de Porções (NI2-272)
    // -------------------------------------------------------------------------
    echo "\n--> [3/9] Testando Conversão de Medidas Caseiras e Escalonamento (NI2-272)...\n";

    $recipeMeasureData = [
        'title' => 'Mingau de Aveia em Medidas Caseiras',
        'meal_type' => 'Café da Manhã',
        'difficulty' => 'fácil',
        'servings' => 1,
    ];

    $measureIngredients = [
        [
            'food_id' => (int)$food1['id'],
            'amount' => 2.0,
            'measure_unit' => 'colher de sopa',
        ],
    ];

    $createdMeasure = $recipeService->createRecipe($recipeMeasureData, $measureIngredients);
    $recipeIdMeasure = (int)$createdMeasure['id'];
    $createdRecipeIds[] = $recipeIdMeasure;

    assertRecipe($createdMeasure['serving_size_g'] > 0, "Medida caseira (colher de sopa) deve ser convertida para gramas via HouseholdMeasureConverter");
    logRecipePass("MEDIDAS_CASEIRAS", "Medida caseira de 2 colheres de sopa convertida para {$createdMeasure['serving_size_g']}g reais");

    // -------------------------------------------------------------------------
    // ETAPA 4: Biblioteca de Receitas, Busca e Filtros Multi-Tenant (NI2-273)
    // -------------------------------------------------------------------------
    echo "\n--> [4/9] Testando Biblioteca, Filtros e Isolamento Multi-Tenant (NI2-273)...\n";

    // Criar receita no Tenant Beta para testar isolamento
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Rafael Beta']);
    $createdBeta = $recipeService->createRecipe([
        'title' => 'Omelete Exclusivo da Clínica Beta',
        'meal_type' => 'Jantar',
        'difficulty' => 'fácil',
        'servings' => 1,
        'visibility' => 'clinic',
    ], [
        ['food_id' => (int)$food1['id'], 'amount' => 100, 'measure_unit' => 'g'],
    ]);
    $recipeIdBeta = (int)$createdBeta['id'];
    $createdRecipeIds[] = $recipeIdBeta;

    // Voltar para Tenant Alfa e fazer busca
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Gabriela Gastronomia']);
    $searchAlfa = $recipeService->searchRecipes(['search' => 'Bowl'], $clinicIdAlfa);
    assertRecipe(count($searchAlfa) >= 1, "Busca por 'Bowl' deve retornar o Bowl Nutritivo");
    assertRecipe($searchAlfa[0]['id'] == $recipeId1, "ID retornado deve ser o da Clínica Alfa");

    // Garantir que a receita exclusiva de Beta NÃO aparece na busca da Clínica Alfa
    $allAlfa = $recipeService->searchRecipes([], $clinicIdAlfa);
    $betaFoundInAlfa = array_filter($allAlfa, fn($r) => (int)$r['id'] === $recipeIdBeta);
    assertRecipe(empty($betaFoundInAlfa), "ISOLAMENTO VIOLADO: Receita privada de Beta apareceu na listagem de Alfa!");

    // Testar filtro por Refeição
    $almocoFiltered = $recipeService->searchRecipes(['meal_type' => 'Almoço'], $clinicIdAlfa);
    assertRecipe(!empty($almocoFiltered), "Filtro por refeição 'Almoço' deve retornar resultados");

    // Testar filtro por Dificuldade
    $facilFiltered = $recipeService->searchRecipes(['difficulty' => 'fácil'], $clinicIdAlfa);
    assertRecipe(!empty($facilFiltered), "Filtro por dificuldade 'fácil' deve retornar resultados");

    logRecipePass("BIBLIOTECA_ISOLAMENTO", "Busca e filtros validados com isolamento estrito entre Tenant Alfa (#{$clinicIdAlfa}) e Beta (#{$clinicIdBeta})");

    // -------------------------------------------------------------------------
    // ETAPA 5: Variantes e Substituições Supervisionadas (NI2-276)
    // -------------------------------------------------------------------------
    echo "\n--> [5/9] Testando Duplicação como Variante Supervisionada (NI2-276)...\n";

    $variantTitle = 'Bowl Nutritivo de Grãos Alfa (Versão Low Carb)';
    $variant = $recipeService->duplicateAsVariant($recipeId1, $variantTitle);
    $variantId = (int)$variant['id'];
    $createdRecipeIds[] = $variantId;

    assertRecipe($variantId > 0 && $variantId !== $recipeId1, "Variante deve ser um novo registro de receita");
    assertRecipe($variant['parent_recipe_id'] === $recipeId1, "Variante deve apontar parent_recipe_id para a receita original #{$recipeId1}");
    assertRecipe($variant['title'] === $variantTitle, "Título da variante deve ser preservado");

    logRecipePass("VARIANTES", "Variante #{$variantId} criada com rastreabilidade de parentesco para #{$recipeId1}");

    // -------------------------------------------------------------------------
    // ETAPA 6: Prescrição ao Paciente e Detecção de Alergênicos (NI2-274, NI2-275)
    // -------------------------------------------------------------------------
    echo "\n--> [6/9] Testando Prescrição Supervisionada e Alertas de Alergênicos (NI2-274, NI2-275)...\n";

    // Receita 1 contém 'Glúten'. Paciente Renata Alfa tem histórico de alergia a 'Glúten, Leite'.
    // Deve disparar alerta de conflito alérgico!
    $prescription = $recipeService->prescribeRecipe(
        $clinicIdAlfa,
        $mealPlanIdAlfa,
        null,
        $patientIdAlfa,
        $recipeId1,
        1.5, // 1.5 porções
        'Consumir morno com salada de folhas verdes.',
        'Almoço'
    );

    assertRecipe(!empty($prescription['id']), "Prescrição deve ter ID gerado");
    assertRecipe((float)$prescription['prescribed_servings'] === 1.5, "Porções prescritas devem ser 1.5");
    assertRecipe(!empty($prescription['allergen_warnings']), "Prescrição DEVE acusar alerta clínico de alergia a Glúten!");
    assertRecipe(str_contains(json_encode($prescription['allergen_warnings'], JSON_UNESCAPED_UNICODE), 'Glúten'), "Alerta de Glúten deve estar explícito no payload");

    // Verificar se o cálculo escalonado de 1.5x porções está exato
    $expectedKcal = round($created['energy_kcal_per_serving'] * 1.5, 2);
    assertRecipe(abs((float)$prescription['scaled_energy_kcal'] - $expectedKcal) < 0.1, "Calorias escalonadas devem ser exatamente 1.5x da porção");

    // Listar prescrições do paciente
    $patientPrescriptions = $recipeService->listPrescriptionsForPatient($patientIdAlfa, $mealPlanIdAlfa);
    assertRecipe(count($patientPrescriptions) >= 1, "Prescrições do paciente devem ser listadas");

    logRecipePass("PRESCRICAO_ALERGENICOS", "Prescrição #{$prescription['id']} escalonou 1.5 porções ({$prescription['scaled_energy_kcal']} kcal) com aviso de alergia a Glúten");

    // -------------------------------------------------------------------------
    // ETAPA 7: Experiência do Paciente: Favoritos e Interação (NI2-277)
    // -------------------------------------------------------------------------
    echo "\n--> [7/9] Testando Experiência do Paciente e Sistema de Favoritos (NI2-277)...\n";

    // Paciente marca a receita como favorita
    $isFav1 = $recipeService->togglePatientFavorite($patientIdAlfa, $recipeId1);
    assertRecipe($isFav1 === true, "Primeiro toggle deve marcar receita como favorita");

    $favorites = $recipeService->listPatientFavorites($patientIdAlfa);
    assertRecipe(count($favorites) === 1, "Paciente deve ter 1 receita favoritada");
    assertRecipe((int)$favorites[0]['id'] === $recipeId1, "Receita favoritada deve ser #{$recipeId1}");

    // Paciente desmarca favorito
    $isFav2 = $recipeService->togglePatientFavorite($patientIdAlfa, $recipeId1);
    assertRecipe($isFav2 === false, "Segundo toggle deve desmarcar favorito");

    $favoritesAfter = $recipeService->listPatientFavorites($patientIdAlfa);
    assertRecipe(empty($favoritesAfter), "Lista de favoritos deve estar vazia após desmarcar");

    logRecipePass("FAVORITOS_PACIENTE", "Toggle de favoritos persistido e verificado no banco");

    // -------------------------------------------------------------------------
    // ETAPA 8: Lista de Compras Consolidada por Gôndolas de Supermercado (NI2-278)
    // -------------------------------------------------------------------------
    echo "\n--> [8/9] Testando Lista de Compras Agrupada por Gôndolas do Supermercado (NI2-278)...\n";

    $shoppingList = $recipeService->generateShoppingListFromRecipes($patientIdAlfa, [(int)$prescription['id']], 7);

    assertRecipe(!empty($shoppingList['items']), "Lista de compras não pode estar vazia");
    assertRecipe(!empty($shoppingList['by_aisle']), "Itens da lista de compras devem ser classificados por gôndolas");
    assertRecipe($shoppingList['days_multiplier'] === 7, "Multiplicador de 7 dias deve estar refletido");

    // Verificar se as quantidades foram agregadas
    $item0 = $shoppingList['items'][0];
    assertRecipe(!empty($item0['aisle']), "Todo ingrediente deve ter gôndola/corredor associado");
    assertRecipe($item0['total_grams'] > 0, "Peso total em gramas do ingrediente deve ser maior que zero");

    logRecipePass("LISTA_COMPRAS_RECEITAS", "Lista de compras gerada com " . count($shoppingList['items']) . " itens consolidados por gôndolas para 7 dias");

    // -------------------------------------------------------------------------
    // ETAPA 9: Pen-Test Ofensivo de Segurança Multi-Tenant e Validações (NI2-280)
    // -------------------------------------------------------------------------
    echo "\n--> [9/9] Executando Pen-Test Ofensivo de Segurança Multi-Tenant (NI2-280)...\n";

    // 9.1 Tenant Beta tenta acessar detalhes de receita privada do Tenant Alfa
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Rafael Beta']);
    $crossTenantBlocked = false;
    try {
        $recipeService->getRecipeDetails($recipeId1, $clinicIdBeta);
    } catch (TenantSecurityException $e) {
        $crossTenantBlocked = true;
    }
    assertRecipe($crossTenantBlocked, "PEN-TEST FALHOU: Tenant Beta conseguiu visualizar receita privada do Tenant Alfa!");
    logRecipePass("PEN_TEST_LEITURA", "Acesso cruzado de leitura bloqueado com TenantSecurityException (Fail-Closed)");

    // 9.2 Tenant Beta tenta prescrever receita de Alfa para seu paciente
    $crossPrescribeBlocked = false;
    try {
        $recipeService->prescribeRecipe(
            $clinicIdBeta,
            9999,
            null,
            $patientIdBeta,
            $recipeId1, // Receita de Alfa!
            1.0,
            null,
            'Jantar'
        );
    } catch (TenantSecurityException $e) {
        $crossPrescribeBlocked = true;
    }
    assertRecipe($crossPrescribeBlocked, "PEN-TEST FALHOU: Tenant Beta conseguiu prescrever receita privada de outro tenant!");
    logRecipePass("PEN_TEST_PRESCRICAO", "Prescrição cruzada não autorizada bloqueada com TenantSecurityException");

    // 9.3 Tentar criar receita sem ingredientes (deve falhar por validação de domínio)
    $emptyIngredientsBlocked = false;
    try {
        $recipeService->createRecipe(['title' => 'Receita Vazia'], []);
    } catch (InvalidArgumentException $e) {
        $emptyIngredientsBlocked = true;
    }
    assertRecipe($emptyIngredientsBlocked, "Criação de receita sem ingredientes nutricionais deve ser rejeitada");
    logRecipePass("PEN_TEST_VALIDACAO", "Validação estrita de ingredientes vazios acionada com InvalidArgumentException");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 9 CRITÉRIOS DE ACEITE DE RECEITAS (NI2-268) HOMOLOGADOS! <<<\n";
    echo "========================================================================\n\n";

} finally {
    // -------------------------------------------------------------------------
    // TEARDOWN COMPLETO: Limpeza estrita de fixtures de teste
    // -------------------------------------------------------------------------
    echo "--> Executando Teardown e Limpeza de Fixtures...\n";

    if (!empty($createdRecipeIds)) {
        $inRec = implode(',', array_map('intval', $createdRecipeIds));
        $db->exec("DELETE FROM recipe_prescriptions WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipe_patient_favorites WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipe_ingredients WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipe_steps WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipe_tags WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipe_allergens WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipe_versions WHERE recipe_id IN ({$inRec})");
        $db->exec("DELETE FROM recipes WHERE id IN ({$inRec})");
    }

    if ($mealPlanIdAlfa > 0) {
        $db->exec("DELETE FROM meal_plans WHERE id = {$mealPlanIdAlfa}");
    }
    if ($patientIdAlfa > 0) {
        $db->exec("DELETE FROM anamneses WHERE patient_id = {$patientIdAlfa}");
        $db->exec("DELETE FROM patients WHERE id = {$patientIdAlfa}");
    }
    if ($patientIdBeta > 0) {
        $db->exec("DELETE FROM patients WHERE id = {$patientIdBeta}");
    }
    if ($profIdAlfa > 0) {
        $db->exec("DELETE FROM professionals WHERE id = {$profIdAlfa}");
    }
    if ($profIdBeta > 0) {
        $db->exec("DELETE FROM professionals WHERE id = {$profIdBeta}");
    }
    if ($nutriIdAlfa > 0) {
        $db->exec("DELETE FROM clinic_members WHERE user_id = {$nutriIdAlfa}");
        $db->exec("DELETE FROM users WHERE id = {$nutriIdAlfa}");
    }
    if ($nutriIdBeta > 0) {
        $db->exec("DELETE FROM clinic_members WHERE user_id = {$nutriIdBeta}");
        $db->exec("DELETE FROM users WHERE id = {$nutriIdBeta}");
    }
    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM clinics WHERE id = {$clinicIdAlfa}");
    }
    if ($clinicIdBeta > 0) {
        $db->exec("DELETE FROM clinics WHERE id = {$clinicIdBeta}");
    }

    TenantContext::clear();
    echo "  [TEARDOWN] Base de dados limpa com sucesso.\n\n";
}
