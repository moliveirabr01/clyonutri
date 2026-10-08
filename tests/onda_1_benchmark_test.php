<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\TemplateService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Recipe\RecipeService;
use ClyoNutri\Domain\ShoppingList\ShoppingListService;

echo "========================================================\n";
echo "   CLYONUTRI BENCHMARK & ONDA 1 TEST SUITE\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$executed = $migrator->run();
assert(count($executed) >= 5, "Devem ser executadas as migrations do sistema");

$auth = new AuthService($db);
$nutri = $auth->registerNutritionist('Dr. Lucas Bench', 'lucas@bench.test', 'SenhaForte@2026', 'Clínica Pro Nutri', '99331', 'CRN-3');
TenantContext::set($nutri['clinic_id'], ['id' => $nutri['user_id'], 'name' => $nutri['name']]);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Clara Fontes', 'email' => 'clara@test.com']);

$foodService = new FoodCatalogService($db);
$foodService->seedTacoReference();
$arroz = $foodService->search('Arroz branco')[0];
$frango = $foodService->search('frango grelhado')[0];
$banana = $foodService->search('Banana prata')[0];
$aveia = $foodService->search('Aveia em flocos')[0];

// 1. NI2-114: Templates de Prescrição Nutricional
echo "[1/4] Testando NI2-114: Criação e Aplicação de Templates de Dieta...\n";
$templateService = new TemplateService($db);
$template = $templateService->createTemplate(
    'Template Equilíbrio e Hipertrofia 2000kcal',
    'Hipertrofia',
    'Template balanceado para ganho de massa magra',
    2000.0,
    [
        'meals' => [
            [
                'name' => 'Almoço Completo',
                'time_of_day' => '12:30',
                'items' => [
                    ['food_id' => $arroz['id'], 'amount' => 180, 'measure_unit' => 'g'],
                    ['food_id' => $frango['id'], 'amount' => 150, 'measure_unit' => 'g'],
                ],
            ],
            [
                'name' => 'Lanche Energético',
                'time_of_day' => '16:00',
                'items' => [
                    ['food_id' => $banana['id'], 'amount' => 100, 'measure_unit' => 'unidade'],
                    ['food_id' => $aveia['id'], 'amount' => 30, 'measure_unit' => 'g'],
                ],
            ],
        ]
    ]
);
assert($template['id'] > 0, "Template deve ser criado com sucesso");

// Clonar template diretamente para a paciente Clara
$novoPlano = $templateService->applyTemplateToPatient($template['id'], $patient['id'], $nutri['user_id']);
assert($novoPlano['plan_id'] > 0, "Plano deve ser gerado a partir do template");
assert($novoPlano['daily_energy_kcal'] > 400.0, "Cálculo calórico automático aplicado");
echo "  ✓ Template criado e clonado para o paciente em 1 clique (Total: {$novoPlano['daily_energy_kcal']} kcal).\n\n";

// 2. NI2-114: Módulo de Receitas Culinárias com Cálculo Nutricional
echo "[2/4] Testando NI2-114: Módulo de Receitas Culinárias...\n";
$recipeService = new RecipeService($db);
$receita = $recipeService->createRecipe(
    [
        'title' => 'Mingau Proteico de Aveia e Banana',
        'category' => 'Café da Manhã',
        'prep_time_minutes' => 10,
        'servings' => 1,
        'instructions' => 'Misture a aveia com água ou leite, aqueça por 3 minutos e finalize com a banana picada.',
    ],
    [
        ['food_id' => $banana['id'], 'amount' => 100, 'measure_unit' => 'unidade'],
        ['food_id' => $aveia['id'], 'amount' => 40, 'measure_unit' => 'g'],
    ]
);
assert($receita['id'] > 0, "Receita deve ser criada");
assert($receita['energy_kcal_per_serving'] > 200.0, "Calorias por porção calculadas");
assert($receita['protein_g_per_serving'] > 5.0, "Proteína por porção calculada");
echo "  ✓ Receita '{$receita['title']}' calculada: {$receita['energy_kcal_per_serving']} kcal e {$receita['protein_g_per_serving']}g proteínas por porção.\n\n";

// 3. NI2-116: Motor de Lista de Compras Agrupada por Gôndola de Supermercado
echo "[3/4] Testando NI2-116: Geração de Lista de Compras Categorizada...\n";
$shoppingService = new ShoppingListService($db);
$listaCompras = $shoppingService->generateForPlan($novoPlano['plan_id'], 7); // 7 dias de compras
assert($listaCompras['total_items_count'] === 4, "Devem ser 4 alimentos diferentes agregados");
assert(isset($listaCompras['aisles']['Cereais & Grãos']), "Corredor Cereais & Grãos deve existir");
assert(isset($listaCompras['aisles']['Açougue & Ovos']), "Corredor Açougue & Ovos deve existir");
assert(isset($listaCompras['aisles']['Hortifrúti & Feira']), "Corredor Hortifrúti deve existir");

// Verificar se calculou 7 dias (Ex: frango 150g * 7 = 1050g)
$frangoItem = null;
foreach ($listaCompras['aisles']['Açougue & Ovos'] as $item) {
    if ($item['name'] === $frango['name']) {
        $frangoItem = $item;
    }
}
assert($frangoItem !== null && $frangoItem['amount'] === 1050.0, "Frango deve somar 1050g para 7 dias");
echo "  ✓ Lista de compras gerada com sucesso para 7 dias (ex: {$frangoItem['amount']}g de peito de frango agregados no Açougue).\n\n";

// 4. NI2-127 & NI2-113: Matriz de Benchmark e Governança Visual Premium
echo "[4/4] Validando Documentação e Critérios de Aceite da Onda 1...\n";
assert(file_exists(__DIR__ . '/../docs/benchmark/MATRIZ_GAPS_BENCHMARK_2026.md'), "Matriz de gaps deve existir");
assert(file_exists(__DIR__ . '/../public/assets/css/clinical-botanical.css'), "CSS Premium deve existir");
echo "  ✓ Matriz de gaps e design system validados.\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DA ONDA 1 PASSARAM COM SUCESSO! ✓\n";
echo "========================================================\n";
