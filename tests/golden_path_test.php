<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;

echo "========================================================\n";
echo "   CLYONUTRI GOLDEN PATH TEST SUITE: FASES 2 & 3\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

// 1. Migrations
echo "[1/6] Executando Schema DDL...\n";
$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();
echo "  ✓ Banco de testes em memória estruturado.\n\n";

// 2. Onboarding & Nutricionista
echo "[2/6] Onboarding do Nutricionista...\n";
$auth = new AuthService($db);
$nutri = $auth->registerNutritionist(
    'Dra. Juliana Mendes',
    'juliana@nutricare.com',
    'SenhaNutri@2026',
    'Clínica NutriCare',
    '88990',
    'CRN-3'
);
TenantContext::set($nutri['clinic_id'], ['id' => $nutri['user_id'], 'name' => $nutri['name']]);
echo "  ✓ Nutricionista ativada com sucesso no Tenant: {$nutri['clinic_id']}.\n\n";

// 3. Cadastro do Paciente & Perfil 360
echo "[3/6] Cadastrando Paciente e Consentimentos LGPD...\n";
$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create([
    'name' => 'Roberto da Silva',
    'email' => 'roberto@email.com',
    'phone' => '(11) 98888-7777',
    'birth_date' => '1990-05-15',
    'gender' => 'masculino',
    'lgpd_consent' => true,
]);
assert($patient['id'] > 0, "Paciente deve ser criado");
echo "  ✓ Paciente '{$patient['name']}' registrado (ID: {$patient['id']}).\n\n";

// 4. Anamnese e Antropometria (Cálculo IMC & RCQ)
echo "[4/6] Registrando Anamnese e Avaliação Antropométrica...\n";
$anamnesisService = new AnamnesisService($db);
$anamnese = $anamnesisService->saveAnamnesis($patient['id'], $nutri['user_id'], [
    'habits' => 'Sedentário, rotina de escritório',
    'allergies' => 'Intolerância leve a lactose',
    'food_preferences' => 'Prefere carnes brancas e saladas',
    'water_intake_ml' => 1500,
]);
assert($anamnese['version'] === 1, "Versão da anamnese deve ser 1");
echo "  ✓ Anamnese registrada (Versão: {$anamnese['version']}).\n";

$antropoService = new AnthropometryService($db);
$avaliacao = $antropoService->recordAssessment($patient['id'], $nutri['user_id'], [
    'weight_kg' => 82.5,
    'height_cm' => 175.0,
    'waist_cm' => 88.0,
    'hip_cm' => 100.0,
    'body_fat_pct' => 22.5,
]);
assert($avaliacao['bmi'] === 26.94, "IMC deve ser 26.94");
assert($avaliacao['whr'] === 0.88, "RCQ deve ser 0.88");
echo "  ✓ Antropometria calculada: IMC {$avaliacao['bmi']} kg/m², RCQ {$avaliacao['whr']}.\n\n";

// 5. Catálogo TACO e Prescrição do Plano Alimentar
echo "[5/6] Importando Alimentos TACO e Criando Plano Alimentar...\n";
$foodService = new FoodCatalogService($db);
$seeded = $foodService->seedTacoReference();
echo "  ✓ {$seeded} alimentos TACO importados com sucesso.\n";

$arroz = $foodService->search('Arroz branco')[0];
$frango = $foodService->search('frango grelhado')[0];

$mealPlanService = new MealPlanService($db);
$plano = $mealPlanService->createPlan($patient['id'], $nutri['user_id'], 'Plano Hipertrofia & Definição', [
    [
        'name' => 'Almoço Principal',
        'time_of_day' => '12:30',
        'items' => [
            ['food_id' => $arroz['id'], 'amount' => 150, 'measure_unit' => 'g'],
            ['food_id' => $frango['id'], 'amount' => 150, 'measure_unit' => 'g'],
        ],
    ]
]);
assert($plano['daily_energy_kcal'] > 0, "Calorias do plano devem ser calculadas");
assert($plano['status'] === 'draft', "Plano deve nascer como rascunho");
echo "  ✓ Plano alimentar '{$plano['title']}' criado com {$plano['daily_energy_kcal']} kcal diárias.\n\n";

// 6. Publicação do Plano e Checagem do Perfil 360
echo "[6/6] Publicando Plano e Checando Perfil 360...\n";
$published = $mealPlanService->publishPlan($plano['plan_id']);
assert($published === true, "Plano deve ser publicado");

$p360 = $patientRepo->getProfile360($patient['id']);
assert(count($p360['anamneses']) === 1, "Perfil 360 deve conter a anamnese");
assert(count($p360['assessments']) === 1, "Perfil 360 deve conter a avaliação");
assert(count($p360['meal_plans']) === 1, "Perfil 360 deve conter o plano");
assert($p360['meal_plans'][0]['status'] === 'published', "Status do plano deve ser publicado");
echo "  ✓ Plano publicado e Perfil 360 verificado com sucesso!\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DO GOLDEN PATH PASSARAM! ✓\n";
echo "========================================================\n";
