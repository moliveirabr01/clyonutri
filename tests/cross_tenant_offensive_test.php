<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Chat\ChatService;
use ClyoNutri\Domain\Crm\PracticeCrmService;
use ClyoNutri\Domain\Engagement\EngagementService;
use ClyoNutri\Domain\Finance\FinanceService;
use ClyoNutri\Domain\FoodDiary\FoodDiaryService;
use ClyoNutri\Domain\HealthSync\HealthSyncService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PublicProfile\PublicProfileService;
use ClyoNutri\Domain\Telehealth\TelehealthService;

echo "========================================================\n";
echo "   CLYONUTRI OFFENSIVE CROSS-TENANT PEN-TEST SUITE\n";
echo "   Auditoria e Blindagem dos 12 Serviços P0 (NI2-143)\n";
echo "========================================================\n\n";

// 1. Setup in-memory SQLite e Migrations
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

// 2. Setup Tenants
$auth = new AuthService($db);
$tenantAlfa = $auth->registerNutritionist('Dra. Alfa', 'alfa@clyo.test', 'SenhaForte@1', 'Clínica Alfa', '11111', 'CRN-ALFA');
$tenantBeta = $auth->registerNutritionist('Dr. Beta', 'beta@clyo.test', 'SenhaForte@2', 'Clínica Beta', '22222', 'CRN-BETA');

$clinicAlfaId = (int)$tenantAlfa['clinic_id'];
$clinicBetaId = (int)$tenantBeta['clinic_id'];
$profAlfaId = (int)$tenantAlfa['user_id'];
$profBetaId = (int)$tenantBeta['user_id'];

// Contexto Alfa: Criar Recursos Pertencentes à Clínica Alfa
TenantContext::set($clinicAlfaId, ['id' => $profAlfaId, 'name' => 'Dra. Alfa']);

$patientRepo = new PatientRepository($db);
$patientAlfa = $patientRepo->create(['name' => 'Paciente da Clínica Alfa', 'email' => 'paciente.alfa@test.com']);
$patientAlfaId = (int)$patientAlfa['id'];

// Criar consulta, plano, recebível e conversa legítimos na Clínica Alfa
$appointmentService = new AppointmentService($db);
$apptAlfa = $appointmentService->schedule($patientAlfaId, $profAlfaId, date('Y-m-d H:i:s', strtotime('+1 day')), 'first_visit', 60, 'Consulta de rotina');
$apptAlfaId = (int)$apptAlfa['id'];

$mealPlanService = new MealPlanService($db);
$planAlfa = $mealPlanService->createPlan($patientAlfaId, $profAlfaId, 'Plano Hipertrofia Alfa', []);
$planAlfaId = (int)$planAlfa['plan_id'];

$financeService = new FinanceService($db);
$receivableAlfa = $financeService->createReceivable($patientAlfaId, 'Consulta Presencial', 35000, date('Y-m-d', strtotime('+7 days')));
$receivableAlfaId = (int)$receivableAlfa['id'];

$chatService = new ChatService($db);
$convAlfaId = $chatService->getOrCreateConversation($patientAlfaId, $profAlfaId);

echo "✓ Setup de infraestrutura e dados do Tenant Alfa concluído.\n";
echo "✓ Paciente Alfa: ID {$patientAlfaId} | Consulta: {$apptAlfaId} | Plano: {$planAlfaId} | Recebível: {$receivableAlfaId} | Conversa: {$convAlfaId}\n\n";

// Mudar para Contexto Beta (Atacante)
TenantContext::set($clinicBetaId, ['id' => $profBetaId, 'name' => 'Dr. Beta']);
echo "🚨 Ativando contexto da Clínica Beta (ID {$clinicBetaId}). Iniciando 12 ataques ofensivos cross-tenant...\n\n";

$blockedAttacks = 0;

function assertAttackBlocked(callable $callback, string $serviceName, string $attackDescription, int &$blockedCounter): void {
    try {
        $callback();
        echo "❌ FALHA DE SEGURANÇA: {$serviceName} permitiu {$attackDescription}!\n";
        exit(1);
    } catch (TenantSecurityException $e) {
        $blockedCounter++;
        echo "  [BLOQUEADO] {$serviceName}: {$attackDescription} -> HTTP {$e->getCode()}: {$e->getMessage()}\n";
    } catch (\Throwable $e) {
        echo "❌ ERRO INESPERADO: {$serviceName} lançou " . get_class($e) . " em vez de TenantSecurityException: {$e->getMessage()}\n";
        exit(1);
    }
}

// 1. MealPlanService (createPlan e publishPlan cross-tenant)
assertAttackBlocked(function () use ($mealPlanService, $patientAlfaId, $profBetaId) {
    $mealPlanService->createPlan($patientAlfaId, $profBetaId, 'Plano Invasor', []);
}, 'MealPlanService', 'criação de plano alimentar em paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($mealPlanService, $planAlfaId) {
    $mealPlanService->publishPlan($planAlfaId);
}, 'MealPlanService', 'publicação de plano alimentar pertencente a outro tenant', $blockedAttacks);

// 2. FinanceService (createReceivable e recordPayment cross-tenant)
assertAttackBlocked(function () use ($financeService, $patientAlfaId) {
    $financeService->createReceivable($patientAlfaId, 'Cobrança Fraudulenta', 99900, '2026-11-01');
}, 'FinanceService', 'criação de recebível em paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($financeService, $receivableAlfaId) {
    $financeService->recordPayment($receivableAlfaId, 35000, 'pix');
}, 'FinanceService', 'baixa/liquidação de recebível de outro tenant', $blockedAttacks);

// 3. AnthropometryService (recordAssessment e getEvolution cross-tenant)
$anthropometryService = new AnthropometryService($db);
assertAttackBlocked(function () use ($anthropometryService, $patientAlfaId, $profBetaId) {
    $anthropometryService->recordAssessment($patientAlfaId, $profBetaId, ['weight_kg' => 75.5, 'height_cm' => 175.0]);
}, 'AnthropometryService', 'registro de avaliação antropométrica em paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($anthropometryService, $patientAlfaId) {
    $anthropometryService->getEvolution($patientAlfaId);
}, 'AnthropometryService', 'consulta evolutiva de paciente de outro tenant', $blockedAttacks);

// 4. EngagementService (logHydration, recordCheckin e getPatientAdherenceSummary)
$engagementService = new EngagementService($db);
assertAttackBlocked(function () use ($engagementService, $patientAlfaId) {
    $engagementService->logHydration($patientAlfaId, 500);
}, 'EngagementService', 'registro de hidratação em paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($engagementService, $patientAlfaId) {
    $engagementService->recordCheckin($patientAlfaId, ['meal_adherence_pct' => 80]);
}, 'EngagementService', 'registro de check-in em paciente de outro tenant', $blockedAttacks);

// 5. FoodDiaryService (logMeal e getPatientTimeline)
$foodDiaryService = new FoodDiaryService($db);
assertAttackBlocked(function () use ($foodDiaryService, $patientAlfaId) {
    $foodDiaryService->logMeal($patientAlfaId, ['meal_name' => 'Almoço', 'description' => 'Arroz e feijão']);
}, 'FoodDiaryService', 'registro de refeição no diário de paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($foodDiaryService, $patientAlfaId) {
    $foodDiaryService->getPatientTimeline($patientAlfaId);
}, 'FoodDiaryService', 'leitura de timeline do diário de paciente de outro tenant', $blockedAttacks);

// 6. AppointmentService (schedule com paciente de outro tenant e updateStatus em agendamento de outro tenant)
assertAttackBlocked(function () use ($appointmentService, $patientAlfaId, $profBetaId) {
    $appointmentService->schedule($patientAlfaId, $profBetaId, date('Y-m-d H:i:s'), 'first_visit', 60, 'Tentativa IDOR');
}, 'AppointmentService', 'agendamento com paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($appointmentService, $apptAlfaId) {
    $appointmentService->updateStatus($apptAlfaId, 'cancelled');
}, 'AppointmentService', 'alteração de status de agendamento de outro tenant', $blockedAttacks);

// 7. ChatService (getOrCreateConversation e sendMessage em conversa de outro tenant)
assertAttackBlocked(function () use ($chatService, $patientAlfaId, $profBetaId) {
    $chatService->getOrCreateConversation($patientAlfaId, $profBetaId);
}, 'ChatService', 'acesso/criação de conversa com paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($chatService, $convAlfaId, $profBetaId) {
    $chatService->sendMessage($convAlfaId, 'professional', $profBetaId, 'Mensagem espiã');
}, 'ChatService', 'envio de mensagem em canal de conversa de outro tenant', $blockedAttacks);

// 8. PublicProfileService (createOrUpdateProfile em profissional de outro tenant)
$publicProfileService = new PublicProfileService($db);
assertAttackBlocked(function () use ($publicProfileService, $profAlfaId) {
    $publicProfileService->createOrUpdateProfile($profAlfaId, ['bio' => 'Perfil adulterado']);
}, 'PublicProfileService', 'adulteração de perfil de nutricionista de outro tenant', $blockedAttacks);

// 9. TelehealthService (generateSession em consulta de outro tenant)
$telehealthService = new TelehealthService($db);
assertAttackBlocked(function () use ($telehealthService, $apptAlfaId) {
    $telehealthService->generateSession($apptAlfaId);
}, 'TelehealthService', 'geração de sessão de teleconsulta para agendamento de outro tenant', $blockedAttacks);

// 10. PracticeCrmService (assignPackageToPatient com paciente de outro tenant)
$crmService = new PracticeCrmService($db);
$pkgBeta = $crmService->createPackage('Acompanhamento Trimestral Beta', 3, 90000);
$pkgBetaId = (int)$pkgBeta['id'];
assertAttackBlocked(function () use ($crmService, $patientAlfaId, $pkgBetaId) {
    $crmService->assignPackageToPatient($patientAlfaId, $pkgBetaId);
}, 'PracticeCrmService', 'associação de pacote a paciente de outro tenant', $blockedAttacks);

// 11. HealthSyncService (syncBiometrics e getLatestSummary em paciente de outro tenant)
$healthSyncService = new HealthSyncService($db);
assertAttackBlocked(function () use ($healthSyncService, $patientAlfaId) {
    $healthSyncService->syncBiometrics($patientAlfaId, 'apple_health', [['type' => 'steps', 'value' => 10000, 'unit' => 'count']]);
}, 'HealthSyncService', 'sincronização de biometria de paciente de outro tenant', $blockedAttacks);

assertAttackBlocked(function () use ($healthSyncService, $patientAlfaId) {
    $healthSyncService->getLatestSummary($patientAlfaId);
}, 'HealthSyncService', 'consulta de dados biométricos de paciente de outro tenant', $blockedAttacks);

// 12. AnamnesisService (saveAnamnesis em paciente de outro tenant)
$anamnesisService = new AnamnesisService($db);
assertAttackBlocked(function () use ($anamnesisService, $patientAlfaId, $profBetaId) {
    $anamnesisService->saveAnamnesis($patientAlfaId, $profBetaId, ['habits' => 'Invasão']);
}, 'AnamnesisService', 'gravação de anamnese clínica em paciente de outro tenant', $blockedAttacks);

// 13. Verificação de Integridade do Banco e Trilha de Auditoria
echo "\n[Auditoria] Verificando logs de segurança contra tentativas cross-tenant...\n";
$stmtAudit = $db->prepare("SELECT COUNT(*) as total FROM audit_logs WHERE action = 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED'");
$stmtAudit->execute();
$auditCount = (int)$stmtAudit->fetch()['total'];

echo "  ✓ Total de violações ofensivas capturadas e auditadas: {$auditCount} / {$blockedAttacks}\n";
assert($auditCount === $blockedAttacks, "Todas as tentativas de ataque devem ter gerado registro de auditoria");

// Garantir que nenhum dado espúrio foi persistido na Clínica Beta
$stmtCheck = $db->prepare("SELECT COUNT(*) as total FROM meal_plans WHERE clinic_id = :clinic_id");
$stmtCheck->execute(['clinic_id' => $clinicBetaId]);
assert((int)$stmtCheck->fetch()['total'] === 0, "Nenhum plano alimentar espúrio deve existir na Clínica Beta");

$stmtCheckAppt = $db->prepare("SELECT status FROM appointments WHERE id = :id");
$stmtCheckAppt->execute(['id' => $apptAlfaId]);
assert($stmtCheckAppt->fetch()['status'] !== 'cancelled', "Agendamento da Clínica Alfa não pode ter sido cancelado pela invasão");

echo "\n========================================================\n";
echo "   RESULTADO FINAL DA PEN-TEST SUITE: APROVADO COM 100%\n";
echo "   Total de ataques barrados: {$blockedAttacks}\n";
echo "   Isolamento e integridade multi-tenant comprovados!\n";
echo "========================================================\n";
