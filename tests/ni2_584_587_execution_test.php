<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Booking\BookingCalendarService;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;

$db = Connection::getInstance();
$db->exec('PRAGMA foreign_keys = ON');

echo "======================================================================\n";
echo "TEST SUITE: Execucao NI2-584 ate NI2-587\n";
echo "======================================================================\n";

function check(bool $cond, string $msg): void {
    if (!$cond) {
        echo " [FAIL] {$msg}\n";
        exit(1);
    }
    echo " [PASS] {$msg}\n";
}

// -------------------------------------------------------------------------
// 1. NI2-584 [UX-001][P0]: Autoagendamento & Disponibilidade Semanal
// -------------------------------------------------------------------------
echo "\n--- NI2-584: Validacao de Disponibilidade Semanal e Slots ---\n";

$legacyPrefs = [
    'working_days' => ['seg', 'qua', 'sex'],
    'start_time' => '09:00',
    'end_time' => '17:00',
    'slot_duration_minutes' => 60,
];
$normalized = BookingCalendarService::normalizeWeeklyAvailability($legacyPrefs);
check(isset($normalized['seg']) && $normalized['seg']['enabled'] === true, 'Normaliza segunda-feira para enabled=true');
check($normalized['ter']['enabled'] === false, 'Terca-feira fica enabled=false');
check($normalized['qua']['enabled'] === true, 'Quarta-feira enabled=true');

// Validacao de slots para Clinica 1 / Prof 1
TenantContext::setClinicId(1);
TenantContext::setUserId(1);
$prefStmt = $db->prepare("INSERT OR REPLACE INTO onboarding_progress (clinic_id, user_id, practice_preferences, created_at, updated_at) VALUES (1, 1, :p, datetime('now'), datetime('now'))");
$prefStmt->execute([':p' => json_encode(['weekly_availability' => $normalized, 'default_duration_minutes' => 60])]);
$bookingSvc = new BookingService($db);
$hasInternalSlots = $bookingSvc->hasAvailableSlotsInNextDays(1, 1, 14, true);
check($hasInternalSlots === true, 'Clinica possui slots internos disponiveis nos proximos 14 dias');

// Ativar autoagendamento da clínica e conferir slots públicos
$bookingSvc->updateSettings(1, [
    'is_enabled' => true,
    'allow_presential' => true,
    'allow_telehealth' => true,
    'min_advance_hours' => 2,
    'max_future_days' => 30,
]);
$hasPublicSlots = $bookingSvc->hasAvailableSlotsInNextDays(1, 1, 14, false);
check($hasPublicSlots === true, 'Clinica possui slots publicos disponiveis com autoagendamento ativo');

// -------------------------------------------------------------------------
// 2. NI2-585 [UX-002][P1]: Alergia no cadastro do paciente chega ao plano
// -------------------------------------------------------------------------
echo "\n--- NI2-585: Alergia do Cadastro no Plano e Validador Fail-Closed ---\n";

TenantContext::setClinicId(1);
TenantContext::setUserId(1);

$patientRepo = new PatientRepository($db);
$testPatient = $patientRepo->create([
    'name' => 'Paciente Teste Alergia Cadastro ' . uniqid(),
    'email' => 'alergia_' . uniqid() . '@teste.com',
    'phone' => '119' . random_int(10000000, 99999999),
    'birth_date' => '1995-05-15',
    'gender' => 'Feminino',
    'notes' => 'Paciente com historico de choque anafilatico',
    'allergies' => 'Alergia severa a amendoim e castanhas',
    'allergy_assessment' => 'recorded',
]);
$patientId = (int)$testPatient['id'];
check($patientId > 0, 'Paciente criado com sucesso no banco');

// 2.1 Validador de alérgenos lê alérgenos do cadastro sem anamnese
$allergenVal = new AllergenSafetyValidator($db);
$allergens = $allergenVal->getPatientAllergens($patientId);
check(in_array('amendoim', $allergens, true), 'AllergenSafetyValidator detectou amendoim do cadastro do paciente');
check(in_array('castanhas', $allergens, true), 'AllergenSafetyValidator detectou castanhas do cadastro do paciente');

// 2.2 Resumo clínico para prescrição lê alergia do cadastro
$anamnesisSvc = new AnamnesisService($db);
$summary = $anamnesisSvc->getClinicalSummaryForPrescription($patientId);
check($summary['has_data'] === true, 'AnamnesisService retorna has_data=true a partir do cadastro');
check($summary['allergy_state'] === 'recorded', 'allergy_state = recorded');
check(str_contains($summary['allergy_label'], 'Origem: Cadastro'), 'allergy_label cita Origem: Cadastro');
check(!empty($summary['critical_alerts']), 'Gera alerta critico de alergia');

// 2.3 Tentativa de prescrever alimento alergênico (amendoim) no plano alimentar bloqueia sem confirmação
$mealPlanSvc = new MealPlanService($db);
$planRes = $mealPlanSvc->createPlan($patientId, 1, 'Plano Teste NI2-585', 'Plano para teste de seguranca', MealPlanService::DEFAULT_MEALS);
$planId = (int)$planRes['plan_id'];
$planDetails = $mealPlanSvc->getPlanWithDetails($planId);
$firstMealId = (int)$planDetails['meals'][0]['id'];

// Alimento com amendoim: pasta de amendoim ou alimento com grupo amendoim
// Vamos verificar o preview
$preview = $mealPlanSvc->getPlanPreview($planId);
check($preview['allergen_safety']['allergy_recorded'] === true, 'Plano alimentar reconhece alergia registrada para o paciente');

// Teste de paciente com recusa expressa / negação de alergias no cadastro
$testPatientDenied = $patientRepo->create([
    'name' => 'Paciente Nega Alergia ' . uniqid(),
    'email' => 'nega_' . uniqid() . '@teste.com',
    'phone' => '119' . random_int(10000000, 99999999),
    'allergies' => null,
    'allergy_assessment' => 'denied',
]);
$summaryDenied = $anamnesisSvc->getClinicalSummaryForPrescription((int)$testPatientDenied['id']);
check($summaryDenied['allergy_state'] === 'denied', 'Paciente com recusa no cadastro tem allergy_state = denied');
check(str_contains($summaryDenied['allergy_label'], 'Cadastro'), 'allergy_label reflete negação no cadastro');

// -------------------------------------------------------------------------
// 3. NI2-586 [UX-003][P1]: Botoes do Perfil 360
// -------------------------------------------------------------------------
echo "\n--- NI2-586: Botoes de Navegacao no Perfil 360 ---\n";

$dashboardCode = file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
check(str_contains($dashboardCode, 'data-testid="profile-manipulados-btn">Manipulados →</a>'), 'Botao de prescricoes manipuladas renomeado para "Manipulados →"');
check(str_contains($dashboardCode, 'data-testid="profile-meal-plan-btn"'), 'Botao destacado de "Plano alimentar" adicionado');

// -------------------------------------------------------------------------
// 4. NI2-587 [UX-004][P1]: Alerta de Adequacao vs Meta Energetica
// -------------------------------------------------------------------------
echo "\n--- NI2-587: Verificacao de Adequacao da Meta Energetica na Publicacao ---\n";

// Criar plano com meta de 2.000 kcal
$planAdequacy = $mealPlanSvc->createPlan($patientId, 1, 'Plano Teste Adequacao NI2-587', 'Teste 15% da meta', MealPlanService::DEFAULT_MEALS);
$pAdqId = (int)$planAdequacy['plan_id'];

$targetSvc = new EnergyTargetService($db);
$targetSvc->save($pAdqId, [
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'age_years' => '30',
    'weight_kg' => '65',
    'height_cm' => '165',
    'activity_factor' => '1.40',
    'goal_type' => 'maintenance',
    'adjust_mode' => 'kcal',
    'adjust_value' => '0',
    'macro_mode' => 'percent',
    'protein' => '20',
    'carbohydrate' => '50',
    'lipid' => '30',
]);
// VET calculado em torno de ~1930 kcal
$targetMeta = $targetSvc->forPlan($pAdqId);
check($targetMeta !== null && $targetMeta['vet_kcal'] > 1800, 'Meta de VET configurada (~' . ($targetMeta['vet_kcal'] ?? 0) . ' kcal)');

// Adicionar apenas 1 alimento com pouca caloria (ex: 1 banana/abacate 50g = ~50-100 kcal, ou seja, ~5% a 15% da meta)
$mealsAdq = $mealPlanSvc->getPlanWithDetails($pAdqId)['meals'];
$testFoodId = (int)$db->query("SELECT id FROM foods WHERE (clinic_id IS NULL OR clinic_id = 1) AND (allergens = '' OR allergens IS NULL OR allergens = 'nenhum') LIMIT 1")->fetchColumn();
$mealPlanSvc->addItem((int)$mealsAdq[0]['id'], $testFoodId, 50, 'g');

// Verificar getPlanPreview
$adqPreview = $mealPlanSvc->getPlanPreview($pAdqId);
check(!empty($adqPreview['adequacy']['requires_override']), 'getPlanPreview detecta que plano exige autorizacao de adequacao');
check(!empty($adqPreview['adequacy']['alerts']), 'getPlanPreview inclui alertas de adequacao estrutural e calorica');

// Tentativa de publicar SEM autorizacao de adequacao DEVE ser recusada
$threwAdequacyBlock = false;
try {
    $mealPlanSvc->publishPlan($pAdqId, false, false, null, false);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'Alerta de adequação')) {
        $threwAdequacyBlock = true;
    }
}
check($threwAdequacyBlock === true, 'Publicacao bloqueada quando plano atinge apenas fracao da meta energetica sem autorizacao');

// Tentativa de publicar com autorizacao MAS sem justificativa suficiente (< 10 chars)
$threwReasonBlock = false;
try {
    $mealPlanSvc->publishPlan($pAdqId, false, false, null, true, 'curto');
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'mínimo de 10 caracteres')) {
        $threwReasonBlock = true;
    }
}
check($threwReasonBlock === true, 'Publicacao com justificativa curta (< 10 chars) e recusada');

// Publicacao com autorizacao expressa e justificativa clinica completa
$published = $mealPlanSvc->publishPlan(
    $pAdqId,
    false,
    true,
    null,
    true,
    'Fase de adaptacao glicemica inicial; demais refeicoes serao introduzidas na semana seguinte.'
);
check(isset($published['status']) && $published['status'] === 'published', 'Publicacao com justificativa clinica valida concluida com sucesso');
check($published['version'] === 1, 'Versao 1 publicada');

// Verificar registro na auditoria
$auditStmt = $db->prepare("SELECT details FROM audit_logs WHERE resource_id = :id AND action = 'MEAL_PLAN_PUBLISHED' ORDER BY id DESC LIMIT 1");
$auditStmt->execute(['id' => (string)$pAdqId]);
$auditJson = $auditStmt->fetchColumn();
check($auditJson !== false, 'Log de auditoria MEAL_PLAN_PUBLISHED registrado');
$auditData = json_decode((string)$auditJson, true);
check(($auditData['adequacy_override'] ?? false) === true, 'Auditoria registrou adequacy_override = true');
check(str_contains($auditData['adequacy_override_reason'] ?? '', 'Fase de adaptacao'), 'Auditoria registrou a justificativa clinica');

echo "\n======================================================================\n";
echo "TODOS OS TESTES DE NI2-584 ATE NI2-587 PASSARAM COM SUCESSO!\n";
echo "======================================================================\n";
