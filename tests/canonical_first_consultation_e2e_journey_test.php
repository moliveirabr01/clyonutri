<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\FoodDiary\FoodDiaryService;
use ClyoNutri\Application\Api\PatientApiController;

echo "====================================================================\n";
echo "   CLYONUTRI II — SUÍTE E2E DA JORNADA CANÔNICA DE ATENDIMENTO\n";
echo "   Validação de Ponta a Ponta: NI2-179, NI2-181 e NI2-194\n";
echo "====================================================================\n\n";

$db = Connection::getInstance();
$passed = 0;
$failed = 0;

function assertJourney(bool $cond, string $msg, ?string $details = null): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$msg}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$msg}\n";
        if ($details) {
            echo "         Detalhes: {$details}\n";
        }
    }
}

// -----------------------------------------------------------------------------
// SETUP: TENANT 1 (Clínica Primária) e TENANT 2 (Clínica Invasora)
// -----------------------------------------------------------------------------
echo "--> [1/10] Configurando Tenants e Profissionais...\n";

$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (1, 'clinic-uuid-e2e-1', 'Clínica ClyoNutri São Paulo', '12345678000199')");
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (2, 'clinic-uuid-e2e-2', 'Clínica Competitor Outro Tenant', '98765432000111')");

$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role) VALUES (1, 'user-e2e-1', 'Dra. Fernanda Nutricionista', 'fernanda@clyonutri.com', 'hash', 'nutritionist')");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role) VALUES (2, 'user-e2e-2', 'Dr. Invasor Tenant 2', 'invasor@outro.com', 'hash', 'nutritionist')");

$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role) VALUES (1, 1, 'nutritionist')");
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role) VALUES (2, 2, 'nutritionist')");

$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, 'CRN-54321', 'CRN-3')");
$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (2, 2, 'CRN-99999', 'CRN-3')");

TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Fernanda Nutricionista']);

// Garantir alimentos TACO
$db->exec("INSERT OR IGNORE INTO foods (id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
VALUES (301, 'Arroz Integral Cozido TACO', 'Cereais', 'TACO', 124.00, 2.60, 25.80, 1.00, 2.70)");

$db->exec("INSERT OR IGNORE INTO foods (id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
VALUES (302, 'Lentilha Cozida TACO', 'Leguminosas', 'TACO', 93.00, 6.30, 16.30, 0.50, 7.90)");

$db->exec("INSERT OR IGNORE INTO foods (id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
VALUES (303, 'Filé de Salmão Grelhado TACO', 'Pescados', 'TACO', 229.00, 23.90, 0.00, 14.00, 0.00)");

$db->exec("INSERT OR IGNORE INTO foods (id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
VALUES (304, 'Tofu Grelhado TACO', 'Leguminosas', 'TACO', 64.00, 6.60, 2.10, 4.00, 0.40)");

// -----------------------------------------------------------------------------
// PASSO 2: CADASTRO DO PACIENTE
// -----------------------------------------------------------------------------
echo "\n--> [2/10] Cadastro Real do Paciente na Plataforma...\n";
$patientRepo = new PatientRepository($db);
$patientEmail = 'lucas.jornada@teste.com';
$db->exec("DELETE FROM patients WHERE email = '{$patientEmail}'");

$patient = $patientRepo->create([
    'name' => 'Lucas Vinicius Silva',
    'email' => $patientEmail,
    'phone' => '(11) 98765-4321',
    'birth_date' => '1992-04-10',
    'gender' => 'male',
    'notes' => 'Objetivo: reeducação alimentar, melhora da composição corporal e disposição física.',
    'lgpd_consent' => 1,
]);
$patientId = (int)$patient['id'];

assertJourney($patientId > 0, "Paciente cadastrado com persistência relacional (ID #{$patientId})");
assertJourney($patient['name'] === 'Lucas Vinicius Silva', "Nome e dados cadastrais corretos");
assertJourney(!empty($patient['uuid']), "UUID seguro gerado para rastreabilidade");

// -----------------------------------------------------------------------------
// PASSO 3: AGENDAMENTO DA PRIMEIRA CONSULTA
// -----------------------------------------------------------------------------
echo "\n--> [3/10] Agendamento da Primeira Consulta Nutricional...\n";
$appService = new AppointmentService($db);
$scheduledTime = date('Y-m-d 10:00:00', strtotime('+1 day'));

$app = $appService->schedule(
    $patientId,
    1,
    $scheduledTime,
    'first_visit',
    60,
    'Primeira consulta de avaliação clínica e prescrição inicial.'
);
$appId = (int)$app['id'];

assertJourney($appId > 0, "Consulta criada com sucesso na agenda (ID #{$appId})");
assertJourney($app['status'] === AppointmentService::STATUS_SCHEDULED, "Status inicial é 'scheduled'");

// -----------------------------------------------------------------------------
// PASSO 4: EXECUÇÃO DO ATENDIMENTO & ANAMNESE CLÍNICA
// -----------------------------------------------------------------------------
echo "\n--> [4/10] Atendimento em Andamento & Registro de Anamnese...\n";
$appService->updateStatus($appId, AppointmentService::STATUS_IN_PROGRESS, 'Profissional iniciou o atendimento na sala clínica');
$currentApp = $appService->getAppointment($appId);
assertJourney($currentApp['status'] === AppointmentService::STATUS_IN_PROGRESS, "Consulta transicionada para 'in_progress'");

// Gravar Anamnese Real
$db->exec("DELETE FROM anamneses WHERE patient_id = {$patientId}");
$anStmt = $db->prepare("
    INSERT INTO anamneses (
        clinic_id, patient_id, professional_id, version, habits, clinical_history,
        routine, water_intake_ml, allergies, food_preferences
    ) VALUES (
        1, :pid, 1, 1, 'Intestino regular 1x/dia, sono regular 7h/noite', 'Sem histórico de cardiopatias. Cansaço no fim da tarde.',
        'Musculação 3x/semana', 1800, 'Nenhuma alergia alimentar conhecida', 'Preferência por peixes e frutas cítricas'
    )
");
$anStmt->execute(['pid' => $patientId]);
$anamneseId = (int)$db->lastInsertId();
assertJourney($anamneseId > 0, "Anamnese clínica completa registrada no prontuário");

// -----------------------------------------------------------------------------
// PASSO 5: AVALIAÇÃO ANTROPOMÉTRICA & INDICADORES OMS
// -----------------------------------------------------------------------------
echo "\n--> [5/10] Avaliação Antropométrica e Indicadores OMS...\n";
$anthroService = new AnthropometryService($db);
$eval = $anthroService->recordAssessment($patientId, 1, [
    'weight_kg' => 84.5,
    'height_cm' => 178.0,
    'waist_cm' => 86.0,
    'hip_cm' => 102.0,
    'neck_cm' => 38.0,
    'body_fat_percent' => 20.5,
    'notes' => 'Avaliação física inicial da primeira consulta.',
]);
$evalId = (int)$eval['id'];

assertJourney($evalId > 0, "Avaliação antropométrica registrada (ID #{$evalId})");
assertJourney($eval['bmi'] === 26.67, "IMC canônico calculado: {$eval['bmi']} kg/m² (Sobrepeso)");
assertJourney($eval['whr'] === 0.84, "Relação Cintura-Quadril calculada: {$eval['whr']}");
assertJourney($eval['whtr'] === 0.48, "Relação Cintura-Estatura calculada: {$eval['whtr']} (Baixo risco cardiometabólico)");

// Compartilhar avaliação para ficar visível ao paciente
$anthroService->shareAssessment($evalId, true, 1, 'Liberado para visualização no aplicativo do paciente');
$sharedEval = $anthroService->getAssessment($evalId);
assertJourney((bool)$sharedEval['is_shared'] === true, "Avaliação compartilhada com autorização auditada");

// -----------------------------------------------------------------------------
// PASSO 6: MONTAGEM E PUBLICAÇÃO DO PLANO ALIMENTAR
// -----------------------------------------------------------------------------
echo "\n--> [6/10] Elaboração do Plano Alimentar e Publicação com Snapshot...\n";
$mealPlanService = new MealPlanService($db);

$plan = $mealPlanService->createPlan($patientId, 1, 'Plano Alimentar de Reeducação - Fase 1', 'Plano personalizado com foco em densidade nutricional.');
$planId = (int)$plan['plan_id'];
assertJourney($planId > 0, "Rascunho de plano alimentar criado (ID #{$planId})");

// Adicionar Refeições
$mAlmocoId = $mealPlanService->addMeal($planId, 'Almoço Balanceado', '12:30', 'Consumir vegetais folhosos crus como entrada.');
$mJantarId = $mealPlanService->addMeal($planId, 'Jantar Leve', '19:30', 'Mastigar devagar.');

// Adicionar Itens no Almoço:
// 150g de Arroz Integral (124 * 1.5 = 186 kcal)
$itArroz = $mealPlanService->addItem($mAlmocoId, 301, 150.0, 'g');
// 100g de Lentilha (93 kcal)
$itLentilha = $mealPlanService->addItem($mAlmocoId, 302, 100.0, 'g');
// 120g de Salmão (229 * 1.2 = 274.8 kcal)
$itSalmao = $mealPlanService->addItem($mAlmocoId, 303, 120.0, 'g');

// Adicionar Substituição vinculada ao Salmão (Tofu 150g = 64 * 1.5 = 96 kcal)
$subTofu = $mealPlanService->addSubstitution($itSalmao, 304, 150.0, 'g', 'Opção vegetal para dias sem peixe');
assertJourney($subTofu > 0, "Opção de substituição vinculada configurada");

// Total esperado no Almoço: 186 + 93 + 274.8 = 553.8 kcal (sem somar substituição)
$planDetails = $mealPlanService->getPlanWithDetails($planId);
assertJourney(abs((float)$planDetails['daily_energy_kcal'] - 553.8) < 0.2, "Totais calculados com precisão proporcional: {$planDetails['daily_energy_kcal']} kcal");

// Publicar o Plano com Snapshot Imutável
$publishResult = $mealPlanService->publishPlan($planId, true, true);
assertJourney($publishResult['status'] === 'published', "Plano alimentar publicado com sucesso");
assertJourney($publishResult['version'] === 1, "Publicado como Versão 1 imutável");

// -----------------------------------------------------------------------------
// PASSO 7: CONCLUSÃO DO ATENDIMENTO E PRONTUÁRIO COM HASH
// -----------------------------------------------------------------------------
echo "\n--> [7/10] Conclusão Clínica da Consulta e Assinatura com Hash...\n";
$appService->completeConsultation(
    $appId,
    'Paciente compareceu pontualmente à primeira consulta. Realizada anamnese clínica detalhada e avaliação física antropométrica. Prescrito plano alimentar de 550 kcal no almoço com substituição vegetal. Agendado retorno em 30 dias.',
    [
        'weight_kg' => 84.5,
        'waist_cm' => 86.0,
    ]
);

$finishedApp = $appService->getAppointment($appId);
assertJourney($finishedApp['status'] === AppointmentService::STATUS_COMPLETED, "Status da consulta atualizado para 'completed'");

// Verificar registro gerado em clinical_records com encadeamento de hash SHA-256
$recStmt = $db->prepare("SELECT * FROM clinical_records WHERE patient_id = :pid ORDER BY id DESC LIMIT 1");
$recStmt->execute(['pid' => $patientId]);
$clinicalRec = $recStmt->fetch();

assertJourney(!empty($clinicalRec), "Registro de prontuário append-only gerado para a consulta");
assertJourney(strlen($clinicalRec['record_hash']) === 64, "Hash SHA-256 de integridade computado (Hash: " . substr($clinicalRec['record_hash'], 0, 16) . "...)");

// -----------------------------------------------------------------------------
// PASSO 8: ACESSO DO PACIENTE VIA API MÓVEL & ENGAJAMENTO REAL
// -----------------------------------------------------------------------------
echo "\n--> [8/10] Experiência do Paciente no Aplicativo (API Móvel)...\n";
$apiController = new PatientApiController($db);

// Gerar token de acesso do paciente (Sanctum)
$plainToken = bin2hex(random_bytes(32));
$hashedToken = hash('sha256', $plainToken);
$tokStmt = $db->prepare("
    INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, created_at)
    VALUES ('patient', :pid, 'mobile_app_e2e', :token, CURRENT_TIMESTAMP)
");
$tokStmt->execute(['pid' => $patientId, 'token' => $hashedToken]);

// Obter plano alimentar ativo pela API do paciente
$patientPlan = $apiController->getDailyMealPlan($patientId);
assertJourney($patientPlan !== null, "Paciente obtém com sucesso a prescrição alimentar ativa");
assertJourney((int)$patientPlan['id'] === $planId, "Plano entregue é exatamente a versão publicada #{$planId}");
assertJourney(count($patientPlan['meals']) === 2, "Paciente visualiza as 2 refeições prescritas");

// Paciente registra ingestão de água (hidratação real)
$waterLog1 = $apiController->logHydration($patientId, 500);
$waterLog2 = $apiController->logHydration($patientId, 300);
assertJourney(isset($waterLog1['id']) && $waterLog1['id'] > 0 && isset($waterLog2['id']) && $waterLog2['id'] > 0, "Registros de água executados via API");

// Checar soma persistida no banco
$hStmt = $db->prepare("SELECT SUM(amount_ml) FROM hydration_logs WHERE patient_id = :pid");
$hStmt->execute(['pid' => $patientId]);
$totalWater = (int)$hStmt->fetchColumn();
assertJourney($totalWater === 800, "Hidratação persistida fisicamente no banco relacional (Total: {$totalWater} ml)");

// Paciente registra refeição no Diário Alimentar
$diaryService = new FoodDiaryService($db);
$diaryEntry = $diaryService->logMeal($patientId, [
    'meal_name' => 'Almoço no Trabalho',
    'description' => '150g de arroz integral, 100g de lentilha e filé de salmão grelhado conforme prescrito.',
    'photo_path' => '/uploads/diary/prato_lucas_almoco.jpg',
    'hunger_level' => 3,
    'satiety_level' => 4,
    'symptoms' => 'Refeição muito saborosa e saciante.',
]);
$diaryId = (int)$diaryEntry['id'];
assertJourney($diaryId > 0, "Entrada no Diário Alimentar persistida com sucesso (ID #{$diaryId})");

// -----------------------------------------------------------------------------
// PASSO 9: VISÃO CONSOLIDADA PELO NUTRICIONISTA NO CONSULTÓRIO
// -----------------------------------------------------------------------------
echo "\n--> [9/10] Visualização dos Registros do Paciente pelo Nutricionista...\n";
$timeline = $diaryService->getPatientTimeline($patientId);
assertJourney(count($timeline) >= 1, "Nutricionista visualiza o diário alimentar do paciente na linha do tempo");
assertJourney($timeline[0]['meal_name'] === 'Almoço no Trabalho', "Dados do diário refletem exatamente o consumo real registrado");

// -----------------------------------------------------------------------------
// PASSO 10: PEN-TEST MULTI-TENANT FAIL-CLOSED
// -----------------------------------------------------------------------------
echo "\n--> [10/10] Pen-Test de Isolamento Multi-Tenant em Cada Etapa...\n";
// Trocar contexto para o Tenant 2 (Clínica Concorrente)
TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist', 'name' => 'Dr. Invasor Tenant 2']);

// 1. Perfil 360 do paciente do Tenant 1 deve retornar null no Tenant 2
$p360 = $patientRepo->getProfile360($patientId);
$crossPatientGuardBlocked = false;
try {
    \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, 2, $db);
} catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
    $crossPatientGuardBlocked = true;
}
assertJourney($p360 === null && $crossPatientGuardBlocked, "Tentativa de Tenant B acessar Perfil 360 do paciente bloqueada (403/Forbidden)");

// 2. Consulta agendada do Tenant 1 deve retornar null no Tenant 2 e TenantGuard lançar 403
$appBlocked = $appService->getAppointment($appId);
$crossAppGuardBlocked = false;
try {
    \ClyoNutri\Core\Security\TenantGuard::assertAppointmentBelongsToClinic($appId, 2, $db);
} catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
    $crossAppGuardBlocked = true;
}
assertJourney($appBlocked === null && $crossAppGuardBlocked, "Tentativa de Tenant B acessar consulta agendada bloqueada");

// 3. Plano alimentar do Tenant 1 deve ser bloqueado por TenantGuard
$crossPlanBlocked = false;
try {
    $mealPlanService->getPlanWithDetails($planId);
} catch (\Throwable $e) {
    $crossPlanBlocked = true;
}
assertJourney($crossPlanBlocked, "Tentativa de Tenant B acessar plano alimentar bloqueada");

// 4. Avaliação antropométrica do Tenant 1 deve retornar null no Tenant 2
$anthroBlocked = $anthroService->getAssessment($evalId);
$crossAnthroGuardBlocked = false;
try {
    \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, 2, $db);
} catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
    $crossAnthroGuardBlocked = true;
}
assertJourney($anthroBlocked === null && $crossAnthroGuardBlocked, "Tentativa de Tenant B acessar avaliação antropométrica bloqueada");

// Restaurar contexto do Tenant 1
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Fernanda Nutricionista']);

// -----------------------------------------------------------------------------
// SUMÁRIO FINAL
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "RESULTADO DA JORNADA CANÔNICA DE ATENDIMENTO (NI2-179 / NI2-181):\n";
echo "  TOTAL DE ASSERÇÕES: " . ($passed + $failed) . "\n";
echo "  PASSOU            : {$passed}\n";
echo "  FALHOU            : {$failed}\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
