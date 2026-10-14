<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Recipe\RecipeService;

if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-268-')) {
    throw new RuntimeException('Isolated test database required');
}

$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();

    $hash = password_hash('PlanTeste@2026', PASSWORD_DEFAULT);

    // Clínicas 1 e 2
    for ($i = 1; $i <= 3; $i++) {
        $role = $i === 3 ? 'receptionist' : 'nutritionist';
        $clinic = $i === 3 ? 1 : $i;
        if ($i < 3) {
            $db->prepare("INSERT INTO clinics(id, uuid, name, status) VALUES(?, ?, ?, 'active')")
               ->execute([$i, "ni268-c$i", "Clínica de Receitas $i"]);
        }
        $db->prepare("INSERT INTO users(id, uuid, name, email, password_hash, role, status) VALUES(?, ?, ?, ?, ?, ?, 'active')")
           ->execute([$i, "ni268-u$i", "Nutricionista $i", "nutri$i@example.test", $hash, $role]);
        $db->prepare("INSERT INTO clinic_members(clinic_id, user_id, role, status) VALUES(?, ?, ?, 'active')")
           ->execute([$clinic, $i, $role]);
        if ($i < 3) {
            $db->prepare("INSERT INTO professionals(id, user_id, crn, crn_region) VALUES(?, ?, 'CRN-TEST', 'CRN-3')")
               ->execute([$i, $i]);
            $db->prepare("INSERT INTO patients(id, clinic_id, uuid, name, status) VALUES(?, ?, ?, ?, 'active')")
               ->execute([$i, $i, "ni268-p$i", "Paciente Receitas $i"]);
        }
    }

    $db->exec("UPDATE clinic_members SET role='owner' WHERE user_id=1");

    // Token Sanctum para Paciente 1
    $rawToken = 'test-token-paciente-1-hex-secret';
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->exec("INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, clinic_id, name, token, abilities, created_at, expires_at)
               VALUES ('patient', 1, 1, 'Mobile App', '{$tokenHash}', '[\"patient\"]', datetime('now'), '{$expiresAt}')");

    // Alimentos TACO
    $db->exec("INSERT INTO foods(id, name, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g) VALUES
        (101, 'Arroz Integral Cozido TACO', 124, 2.6, 25.8, 1.0, 2.7),
        (102, 'Feijão Carioca Cozido TACO', 76, 4.8, 13.6, 0.5, 8.5),
        (103, 'Peito de Frango Grelhado TACO', 159, 32.0, 0.0, 2.5, 0.0)
    ");

    // Plano Alimentar para Paciente 1
    TenantContext::set(1, ['id' => 1]);
    $mealPlanService = new MealPlanService($db);
    $planResult = $mealPlanService->createPlan(1, 1, 'Plano de Alta Densidade Nutricional', null, [
        [
            'name' => 'Almoço Funcional',
            'time_of_day' => '12:30',
            'items' => [
                ['food_id' => 101, 'amount' => 100, 'measure_unit' => 'g'],
            ],
        ],
    ]);

    // Criar Receita Inicial no Tenant 1
    $recipeService = new RecipeService($db);
    $rec1 = $recipeService->createRecipe([
        'title' => 'Bowl Proteico de Frango com Arroz Integral',
        'summary' => 'Refeição balanceada com alto teor proteico e fibras.',
        'meal_type' => 'Almoço',
        'category' => 'Prato Principal',
        'difficulty' => 'fácil',
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 20,
        'servings' => 2,
        'serving_size_name' => 'bowl',
        'visibility' => 'clinic',
    ], [
        ['food_id' => 101, 'amount' => 150.0, 'measure_unit' => 'g'],
        ['food_id' => 103, 'amount' => 120.0, 'measure_unit' => 'g'],
    ], [
        ['instruction' => 'Grelhe o frango com ervas finas e sal rosa.'],
        ['instruction' => 'Disponha o arroz no bowl e o frango fatiado por cima.'],
    ], ['alta proteína', 'funcional'], [
        ['group' => 'Glúten', 'level' => 'free', 'notes' => 'Ingredientes naturalmente sem glúten'],
    ]);

    // Criar Receita Exclusiva no Tenant 2 para teste de isolamento
    TenantContext::set(2, ['id' => 2]);
    $rec2 = (new RecipeService($db))->createRecipe([
        'title' => 'Omelete Exclusivo da Clínica 2',
        'meal_type' => 'Jantar',
        'difficulty' => 'fácil',
        'servings' => 1,
        'visibility' => 'clinic',
    ], [
        ['food_id' => 102, 'amount' => 80.0, 'measure_unit' => 'g'],
    ]);

    echo json_encode([
        'recipe1_id' => $rec1['id'],
        'recipe2_id' => $rec2['id'],
        'plan_id' => 1,
        'meal_id' => 1,
        'patient_id' => 1,
        'raw_token' => $rawToken,
    ]);
} elseif ($mode === 'inspect') {
    echo json_encode([
        'recipes' => $db->query('SELECT * FROM recipes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'versions' => $db->query('SELECT * FROM recipe_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'prescriptions' => $db->query('SELECT * FROM recipe_prescriptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'favorites' => $db->query('SELECT * FROM recipe_patient_favorites ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
