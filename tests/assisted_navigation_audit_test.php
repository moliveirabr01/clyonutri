<?php
declare(strict_types=1);

/**
 * ClyoNutri II — Teste de Verificação da Auditoria por Navegação Assistida (16/10/2026)
 * Valida a remediação completa dos pontos P01 a P13 e Migração 41.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\MealPlan\MealPlanPrintService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Domain\PatientPortal\PatientOnboardingService;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite') {
    throw new RuntimeException('Esta suíte exige APP_ENV=testing e SQLite isolado; use o runner full.');
}
$db = Connection::getInstance();
$totalTests = 0;
$passedTests = 0;

function assertTest(bool $condition, string $description): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
        throw new RuntimeException("Falha no teste: {$description}");
    }
}

echo "========================================================================\n";
echo "  AUDITORIA NAVEGAÇÃO ASSISTIDA: SUITE DE VERIFICAÇÃO AUTOMATIZADA\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// 1. MIGRATION 41: BASE DE DADOS E CONSENT_TERMS
// -------------------------------------------------------------------------
echo "--- [1] Migração 41 & Integridade Estrutural do Banco ---\n";

$consentStmt = $db->query("SELECT * FROM consent_terms WHERE purpose_code = 'clinical_care' AND is_active = 1 ORDER BY id DESC LIMIT 1");
$consent = $consentStmt->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($consent), "Termo de atendimento clínico (clinical_care) ativo existe na tabela consent_terms");
assertTest(($consent['version'] ?? '') === '2026.10-r1', "Versão do consent_terms é 2026.10-r1");
assertTest(str_contains((string)$consent['term_text'], 'assistência nutricional'), "Conteúdo do consent_terms contém texto oficial de consentimento");

$colsStmt = $db->query("PRAGMA table_info(clinics)");
$clinicCols = array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'name');
assertTest(in_array('logo_path', $clinicCols, true), "Tabela clinics possui coluna logo_path");
assertTest(in_array('address_street', $clinicCols, true), "Tabela clinics possui coluna address_street");
assertTest(in_array('document_header_text', $clinicCols, true), "Tabela clinics possui coluna document_header_text");

$tcColsStmt = $db->query("PRAGMA table_info(teleconsultation_sessions)");
$tcCols = array_column($tcColsStmt->fetchAll(PDO::FETCH_ASSOC), 'name');
assertTest(in_array('device_check_mode', $tcCols, true), "Tabela teleconsultation_sessions possui coluna device_check_mode");

$fhmCount = (int)$db->query("SELECT COUNT(*) FROM food_household_measures")->fetchColumn();
assertTest($fhmCount >= 15, "food_household_measures possui medidas caseiras populadas ({$fhmCount} registros)");

$helpdeskCount = (int)$db->query("SELECT COUNT(*) FROM helpdesk_articles WHERE category IN ('teleconsulta', 'app_paciente')")->fetchColumn();
assertTest($helpdeskCount >= 2, "helpdesk_articles possui artigos sobre teleconsulta e app ({$helpdeskCount} artigos)");

// -------------------------------------------------------------------------
// 2. P01 + P07: ONBOARDING DISPONIBILIDADE E PERFIL
// -------------------------------------------------------------------------
echo "\n--- [2] P01 + P07: Onboarding -> Disponibilidade da Agenda e Perfil ---\n";

TenantContext::set(1, ['id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Nutricionista Teste']);
$onboardingService = new OnboardingService($db);
$onboardingService->getOrCreateProgress(1, 1);
$onboardingService->saveStep(1, 1, 3, [
    'modalities' => ['presencial', 'online'],
    'default_price' => '150.00',
    'working_days' => ['seg', 'ter', 'qua', 'qui', 'sex'],
]);

$progStmt = $db->query("SELECT practice_preferences FROM onboarding_progress WHERE user_id = 1 AND clinic_id = 1");
$prog = $progStmt->fetch(PDO::FETCH_ASSOC);
$pref = json_decode((string)($prog['practice_preferences'] ?? '{}'), true);
assertTest(!empty($pref['weekly_availability']), "weekly_availability sintetizada e salva no onboarding_progress no passo 3");

$avail = $pref['weekly_availability'] ?? [];
assertTest(is_array($avail) && isset($avail['seg']) && isset($avail['sex']), "weekly_availability contém dias úteis configurados (seg a sex)");
assertTest($avail['seg']['start'] === '08:00' && $avail['seg']['end'] === '18:00', "Horários padrão de disponibilidade configurados (08:00 às 18:00)");

// -------------------------------------------------------------------------
// 3. P02: TELECONSULTA MODO AUDIO-ONLY E BYPASS
// -------------------------------------------------------------------------
echo "\n--- [3] P02: Teleconsulta Sala de Espera, Fallback Audio-Only e Bypass ---\n";

$patientRow = $db->query("SELECT id FROM patients WHERE id = 1")->fetch();
if (!$patientRow) {
    $db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, phone, status, created_at, updated_at)
               VALUES (1, 1, '00000000-0000-0000-0000-000000000001', 'Paciente Teste Audit', 'paciente@teste.com', '(11) 98765-4321', 'active', datetime('now'), datetime('now'))");
}

$db->exec("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, created_at)
           VALUES (1, 1, 1, datetime('now', '+1 hour'), 60, 'confirmed', 'first_visit', datetime('now'))");
$appId = (int)$db->lastInsertId();

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$db->exec("INSERT INTO teleconsultation_sessions (clinic_id, appointment_id, professional_id, patient_id, provider, patient_token_hash, status, created_at)
           VALUES (1, {$appId}, 1, 1, 'daily_co', '{$tokenHash}', 'waiting', datetime('now'))");
$sessId = (int)$db->lastInsertId();

$tcService = new TeleconsultationService($db);

// Teste Audio-Only
$resAudio = $tcService->submitDeviceCheck($rawToken, [
    'mode' => 'audio_only',
    'microphone_ok' => true,
]);
assertTest($resAudio['device_check_passed'] === true, "Modo audio_only resulta em device_check_passed = true");
assertTest($resAudio['device_check_mode'] === 'audio_only', "device_check_mode retornado como 'audio_only'");
$dbCheckAudio = $db->query("SELECT device_check_passed, device_check_mode FROM teleconsultation_sessions WHERE id = {$sessId}")->fetch();
assertTest((int)$dbCheckAudio['device_check_passed'] === 2, "No banco, device_check_passed registrado como 2 (audio_only)");

// Teste Bypass Warning
$resBypass = $tcService->submitDeviceCheck($rawToken, [
    'mode' => 'bypass_warning',
]);
assertTest($resBypass['device_check_passed'] === true, "Modo bypass_warning resulta em device_check_passed = true");
assertTest($resBypass['device_check_mode'] === 'bypass_warning', "device_check_mode retornado como 'bypass_warning'");
$dbCheckBypass = $db->query("SELECT device_check_passed, device_check_mode FROM teleconsultation_sessions WHERE id = {$sessId}")->fetch();
assertTest((int)$dbCheckBypass['device_check_passed'] === 3, "No banco, device_check_passed registrado como 3 (bypass_warning)");

// -------------------------------------------------------------------------
// 4. P03: CONVITE DO APP E PORTAL /APP
// -------------------------------------------------------------------------
echo "\n--- [4] P03: Convite do Paciente e Rota do Portal /app ---\n";

// Prepara paciente sem senha para convite
$db->exec("UPDATE patients SET password_hash = '', access_pin = '' WHERE id = 1");
TenantContext::set(1, ['id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Nutricionista Teste']);

$portalService = new PatientOnboardingService($db);
$inviteResult = $portalService->create(1, 1, 1);
assertTest(!empty($inviteResult['invitation_code']), "Convite gerado com token de 64 caracteres");
assertTest(strlen($inviteResult['invitation_code']) === 64, "Token tem exatamente 64 caracteres hexadecimais");

$preview = $portalService->preview($inviteResult['invitation_code']);
assertTest(!empty($preview['patient']['name']), "Preview do convite carrega nome do paciente: " . $preview['patient']['name']);
assertTest(!empty($preview['clinic_name']), "Preview do convite carrega nome da clínica");

// Teste de Ativação do Primeiro Acesso com Senha e LGPD
$completeRes = $portalService->complete([
    'invitation_code' => $inviteResult['invitation_code'],
    'name' => $preview['patient']['name'],
    'email' => 'paciente.teste.' . time() . '@clyonutri.com',
    'phone' => '(11) 98888-7777',
    'password' => 'SenhaForte123!',
    'password_confirmation' => 'SenhaForte123!',
]);
assertTest(!empty($completeRes['token']), "Primeiro acesso concluído gera token de autenticação");
assertTest(!empty($completeRes['patient']['id']), "Paciente associado ao token com sucesso");

// -------------------------------------------------------------------------
// 5. P04: VALIDAÇÃO LGPD NO CADASTRO
// -------------------------------------------------------------------------
echo "\n--- [5] P04: Aceite Obrigatório dos Termos LGPD no Cadastro ---\n";

$authSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/AuthController.php');
assertTest(str_contains($authSrc, "empty(\$_POST['terms_accepted'])"), "AuthController valida obrigatoriedade de terms_accepted");
assertTest(str_contains($authSrc, "Termos de Uso e a Política de Privacidade (LGPD)"), "Mensagem de erro amigável orientando o aceite da LGPD");

$regViewSrc = file_get_contents(__DIR__ . '/../views/auth/register.php');
assertTest(str_contains($regViewSrc, 'name="terms_accepted"'), "View views/auth/register.php possui checkbox terms_accepted");
assertTest(str_contains($regViewSrc, '/termos') && str_contains($regViewSrc, '/privacidade'), "View de cadastro contém links diretos para /termos e /privacidade");

// -------------------------------------------------------------------------
// 6. P08 + P09: ANAMNESE ATUALIZAÇÃO IN-PLACE DE RASCUNHO
// -------------------------------------------------------------------------
echo "\n--- [6] P08 + P09: Anamnese Atualização In-Place de Rascunhos (Sem Inflar Versões) ---\n";

$anamnesisService = new AnamnesisService($db);
TenantContext::set(1, ['id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Nutricionista Teste']);

// Limpa anamneses anteriores de teste para o paciente 1
$db->exec("DELETE FROM anamneses WHERE patient_id = 1 AND clinic_id = 1");

// Salva primeiro rascunho
$draft1 = $anamnesisService->saveAnamnesis(1, 1, [
    'habits' => 'Alimentação regular',
    'allergies' => 'Nenhuma',
], true);
assertTest(!empty($draft1['is_draft']), "Primeiro salvamento cria rascunho (is_draft = true)");
assertTest((int)$draft1['version'] === 1, "Versão inicial do rascunho é 1");
$draftId1 = $draft1['id'];

// Salva segundo rascunho
$draft2 = $anamnesisService->saveAnamnesis(1, 1, [
    'habits' => 'Alimentação regular, bebe 2L água',
    'allergies' => 'Rinite',
], true);
assertTest($draft2['id'] === $draftId1, "Segundo salvamento atualiza o rascunho existente in-place (mesmo ID)");
assertTest((int)$draft2['version'] === 1, "Versão permanece 1 sem inflação numérica de versões");

$totalDrafts = (int)$db->query("SELECT COUNT(*) FROM anamneses WHERE patient_id = 1 AND clinic_id = 1")->fetchColumn();
assertTest($totalDrafts === 1, "Existe apenas 1 registro no banco de anamnese para os salvamentos de rascunho");

// Finaliza anamnese
$final = $anamnesisService->saveAnamnesis(1, 1, [
    'habits' => 'Alimentação regular, bebe 2L água',
    'allergies' => 'Rinite',
], false);
assertTest(empty($final['is_draft']), "Finalização marca rascunho como concluído (is_draft = false)");

// -------------------------------------------------------------------------
// 7. P10 + P12: ANTROPOMETRIA NORMALIZAÇÃO DE ALTURA E FORMATAÇÃO ACESSÍVEL
// -------------------------------------------------------------------------
echo "\n--- [7] P10 + P12: Antropometria Normalização de Altura e Resumo Acessível ---\n";

// Normalização de altura em metros vs centímetros ao salvar avaliação
TenantContext::set(1, ['id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Nutricionista Teste']);
$anthroService = new AnthropometryService($db);
$eval1 = $anthroService->recordAssessment(1, 1, [
    'weight_kg' => '70.5',
    'height_cm' => '1.75', // Em metros
]);
assertTest((float)$eval1['height_cm'] === 175.0, "recordAssessment normalizou altura de 1.75 m para 175.0 cm");
assertTest((float)$eval1['weight_kg'] === 70.5, "recordAssessment gravou peso de 70.5 kg");

$eval2 = $anthroService->recordAssessment(1, 1, [
    'weight_kg' => '68.2',
    'height_cm' => '175', // Em centímetros diretos
]);
assertTest((float)$eval2['height_cm'] === 175.0, "recordAssessment preservou altura de 175 cm");

// Resumo acessível com vírgula e unidades biológicas
$anthroRefl = new ReflectionClass($anthroService);
$summaryMethod = $anthroRefl->getMethod('buildAccessibleEvolutionSummary');

$summaryWeight = $summaryMethod->invoke($anthroService, 'weight_kg', [
    ['formatted_date' => '10/10/2026', 'value' => 70.5],
    ['formatted_date' => '16/10/2026', 'value' => 68.2],
]);
assertTest(str_contains($summaryWeight, '70,50 kg') || str_contains($summaryWeight, '70,5 kg'), "Resumo acessível formata peso com vírgula e kg: {$summaryWeight}");

$summaryBmi = $summaryMethod->invoke($anthroService, 'bmi', [
    ['formatted_date' => '10/10/2026', 'value' => 24.35],
]);
assertTest(str_contains($summaryBmi, 'kg/m²') && str_contains($summaryBmi, '24,35'), "Resumo acessível formata IMC com unidade kg/m² e vírgula: {$summaryBmi}");

$summaryFat = $summaryMethod->invoke($anthroService, 'body_fat_pct', [
    ['formatted_date' => '10/10/2026', 'value' => 18.2],
]);
assertTest(str_contains($summaryFat, '%') && str_contains($summaryFat, '18,20'), "Resumo acessível formata percentual com % e vírgula: {$summaryFat}");

// -------------------------------------------------------------------------
// 8. P11: PLANO ALIMENTAR EQUIVALÊNCIA DE MEDIDAS CASEIRAS EM GRAMAS
// -------------------------------------------------------------------------
echo "\n--- [8] P11: Equivalência de Medidas Caseiras em Gramas ---\n";

TenantContext::set(1, ['id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Nutricionista Teste']);

// NI2-895: alimento próprio torna a medida determinística, sem depender do seed do catálogo.
$db->exec("INSERT INTO foods (name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
           VALUES ('Alimento NI2-895 TESTE', 'Frutas', 98, 1.3, 26, 0.1, 2)");
$foodId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent, source)
              VALUES (?, 'unidade média', 100.0, 'Medida verificada TESTE NI2-895')")->execute([$foodId]);

$mpService = new MealPlanService($db);
$printService = new MealPlanPrintService($db);
$plan = $mpService->createPlan(1, 1, 'Plano de Teste Gramas NI2-895');
$planId = (int)$plan['plan_id'];
$mealId = $mpService->addMeal($planId, 'Café da Manhã', '08:00');
$itemId = $mpService->addItem($mealId, $foodId, 2.0, 'unidade média');

$planDetails = $mpService->getPlanWithDetails($planId);
assertTest(!empty($planDetails), "Plano alimentar criado pelo serviço carregado com sucesso");
$firstItem = $planDetails['meals'][0]['items'][0];
echo '  NI2-895 obtido: ' . json_encode([
    'amount' => $firstItem['amount'], 'weight_grams' => $firstItem['weight_grams'],
    'energy_kcal' => $firstItem['energy_kcal'], 'weight_is_estimated' => $firstItem['weight_is_estimated'],
], JSON_UNESCAPED_UNICODE) . "\n";
assertTest((float)$firstItem['weight_grams'] === 200.0, "2 unidades de 100 g persistem weight_grams total de 200 g");
assertTest((float)$firstItem['energy_kcal'] === 196.0, "Calorias usam os mesmos 200 g persistidos (196 kcal)");
assertTest((int)$firstItem['weight_is_estimated'] === 0, "Medida verificada do alimento não é marcada como estimativa genérica");
$printed = $printService->build($planId, 1, true);
assertTest($printed['meals'][0]['items'][0]['quantity'] === '2 unidade média (~200g)', "Impressão entrega o peso total persistido de 200 g");

// A leitura não recalcula prescrições antigas a partir de um catálogo que mudou.
$db->prepare("UPDATE food_household_measures SET grams_equivalent=150 WHERE food_id=? AND measure_name='unidade média'")->execute([$foodId]);
$reloaded = (new MealPlanService($db))->getPlanWithDetails($planId);
assertTest((float)$reloaded['meals'][0]['items'][0]['weight_grams'] === 200.0, "Alterar catálogo não muda o peso já prescrito");
assertTest((new MealPlanPrintService($db))->build($planId, 1, true)['meals'][0]['items'][0]['quantity'] === '2 unidade média (~200g)', "Impressão permanece consistente após alteração do catálogo");
$countBefore = (int)$db->query('SELECT COUNT(*) FROM meal_plan_items WHERE meal_id=' . $mealId)->fetchColumn();
$rejected = false;
try { $mpService->addItem($mealId, $foodId, 2.0, 'medida inexistente NI2-895'); }
catch (InvalidArgumentException) { $rejected = true; }
assertTest($rejected && (int)$db->query('SELECT COUNT(*) FROM meal_plan_items WHERE meal_id=' . $mealId)->fetchColumn() === $countBefore, "Medida desconhecida é rejeitada sem persistir um item");

// Testa o método quantity do MealPlanPrintService via Reflection
$printRefl = new ReflectionClass($printService);
$qtyMethod = $printRefl->getMethod('quantity');

$qtySimple = $qtyMethod->invoke($printService, 100, 'g', null);
assertTest($qtySimple === '100 g', "Quantidade em gramas não duplica (~g)");

$qtyHousehold = $qtyMethod->invoke($printService, 2, 'unidades', 200.0);
assertTest($qtyHousehold === '2 unidades (~200g)', "Medida caseira exibe equivalência total em gramas: {$qtyHousehold}");

$qtyUnitWithParen = $qtyMethod->invoke($printService, 2, 'unidade média (100g)', null);
assertTest($qtyUnitWithParen === '2 unidade média (100g) (~200g)', "Medida com grama unitária calcula total para 2 unidades: {$qtyUnitWithParen}");

echo "\n========================================================================\n";
echo "  ✓ RESULTADO: {$passedTests} DE {$totalTests} TESTES PASSARAM COM SUCESSO!\n";
echo "========================================================================\n";
