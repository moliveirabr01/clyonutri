<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Api\PatientApiController;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Engagement\EngagementService;
use ClyoNutri\Domain\Finance\FinanceService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;

echo "========================================================\n";
echo "   CLYONUTRI TEST SUITE: FASES 4, 5 & 6\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

// Configurar Tenant e Nutricionista
$auth = new AuthService($db);
$nutri = $auth->registerNutritionist('Dra. Mariana Costa', 'mariana@clyonutri.test', 'SenhaForte123', 'Clínica NutriLife', '99112', 'CRN-3');
TenantContext::set($nutri['clinic_id'], ['id' => $nutri['user_id'], 'name' => $nutri['name']]);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Lucas Peixoto', 'email' => 'lucas@test.com', 'phone' => '1199998888']);

// 1. Fase 4: Agenda e Jornada da Consulta
echo "[1/4] Testando Fase 4: Agenda e Consulta...\n";
$appointmentService = new AppointmentService($db);
$agendamento = $appointmentService->schedule($patient['id'], $nutri['user_id'], '2026-10-10 14:00:00', 'first_visit', 60, 'Primeira consulta de avaliação');
assert($agendamento['status'] === 'scheduled', "Consulta deve nascer como scheduled");

$appointmentService->updateStatus($agendamento['id'], 'in_progress');
$statusUpdated = $appointmentService->updateStatus($agendamento['id'], 'completed');
assert($statusUpdated === true, "Status deve ser atualizado para completed");
echo "  ✓ Agenda e ciclo de consulta validados com sucesso.\n\n";

// 2. Fase 4: Engajamento & Check-in
echo "[2/4] Testando Fase 4: Engajamento, Hidratação e Check-ins...\n";
$engagementService = new EngagementService($db);
$hydration = $engagementService->logHydration($patient['id'], 500);
assert($hydration['amount_ml'] === 500, "Hidratação registrada");

$checkin = $engagementService->recordCheckin($patient['id'], [
    'energy_level' => 4,
    'hunger_level' => 2,
    'meal_adherence_pct' => 90,
    'notes' => 'Segui todo o plano hoje!',
]);
assert($checkin['meal_adherence_pct'] === 90, "Check-in registrado");

$summary = $engagementService->getPatientAdherenceSummary($patient['id']);
assert($summary['water_intake_today_ml'] === 500, "Total diário hídrico correto");
assert($summary['average_adherence_pct'] === 90.0, "Média de adesão correta");
echo "  ✓ Métricas de engajamento do paciente validadas com sucesso.\n\n";

// 3. Fase 5: API do Paciente e Plano Diário
echo "[3/4] Testando Fase 5: Portal / API do Paciente...\n";
$foodService = new FoodCatalogService($db);
$foodService->seedTacoReference();
$foods = $foodService->search('Arroz branco');

$mealPlanService = new MealPlanService($db);
$plano = $mealPlanService->createPlan($patient['id'], $nutri['user_id'], 'Plano Ativo', [
    [
        'name' => 'Café da Manhã',
        'time_of_day' => '08:00',
        'items' => [
            ['food_id' => $foods[0]['id'], 'amount' => 100, 'measure_unit' => 'g'],
        ],
    ]
]);
$mealPlanService->publishPlan($plano['plan_id']);

$api = new PatientApiController($db);
$dailyPlan = $api->getDailyMealPlan($patient['id']);
assert($dailyPlan !== null, "Plano deve ser retornado pela API");
assert(count($dailyPlan['meals']) === 1, "Refeições retornadas");
echo "  ✓ API versionada do paciente validada com sucesso.\n\n";

// 4. Fase 6: Financeiro Essencial
echo "[4/4] Testando Fase 6: Financeiro Essencial (Recebíveis, Pagamento e Recibo)...\n";
$financeService = new FinanceService($db);
$receivable = $financeService->createReceivable($patient['id'], 'Consulta Nutricional Inicial', 25000, '2026-10-10', $agendamento['id']);
assert($receivable['amount_cents'] === 25000, "Valor do recebível deve ser R$ 250,00");
assert($receivable['status'] === 'pending', "Status inicial pending");

$payment = $financeService->recordPayment($receivable['id'], 25000, 'pix');
assert($payment['status'] === 'paid', "Status após pagamento integral deve ser paid");
assert(!empty($payment['receipt_code']), "Código de recibo deve ser gerado");

$receipt = $financeService->generateReceipt($payment['receipt_code']);
assert($receipt !== null, "Recibo deve ser recuperável pelo código");
assert($receipt['patient_name'] === 'Lucas Peixoto', "Paciente no recibo");
echo "  ✓ Recebível, liquidação PIX e recibo ({$payment['receipt_code']}) validados!\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DAS FASES 4, 5 & 6 PASSARAM! ✓\n";
echo "========================================================\n";
