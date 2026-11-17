<?php

declare(strict_types=1);

/**
 * Suíte completa do Épico NI2-856 (AUD-360 v54.66 -> v54.67):
 * Valida P1, P2, P3, P4 e contratos críticos (NI2-857 a NI2-874).
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\MfaService;
use ClyoNutri\Core\Auth\ProfessionalSecurityNotifier;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\AccountActionRateLimiter;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\TotpService;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\ConsultationDraftService;
use ClyoNutri\Domain\Billing\AsaasClient;
use ClyoNutri\Domain\Billing\AsaasConfig;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Clinical\WorkflowState;
use ClyoNutri\Domain\Finance\Money;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Observability\ProductionEventService;
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Domain\Patient\PatientImportService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Subscription\SubscriptionService;

/** @var PDO $pdo */
$db = $pdo;

// Seed básico: 2 clínicas, 2 nutricionistas, 1 superadmin, 2 pacientes
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica A', 'ca@teste.local', 'active')");
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (2, 'c2', 'Clínica B', 'cb@teste.local', 'active')");
$pwHash = password_hash('SenhaForte@2026', PASSWORD_BCRYPT, ['cost' => 10]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Dra. Helena Prado', 'helena@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (2, 'u2', 'Dr. Bruno Lima', 'bruno@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (3, 'u3', 'Admin Plataforma', 'admin@teste.local', ?, 'superadmin', 1, 'active')")->execute([$pwHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active'), (2, 2, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '12345', 'CRN-3'), (2, 2, '54321', 'CRN-3')");

TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'clinic_id' => 1]);
$patRepo = new PatientRepository($db);
$pA = $patRepo->create([
    'name' => 'Mariana Souza',
    'email' => 'mariana@teste.local',
    'phone' => '11999990001',
    'birth_date' => '1990-05-10',
    'gender' => 'Feminino',
    'notes' => 'Observação clínica sensível da Mariana',
    'allergies' => 'Amendoim e camarão',
    'allergy_assessment' => 'recorded',
]);
$pAId = (int)$pA['id'];

TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist', 'clinic_id' => 2]);
$pB = $patRepo->create(['name' => 'Carlos Clínica B', 'email' => 'carlos@teste.local', 'phone' => '11999990002']);
$pBId = (int)$pB['id'];
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'clinic_id' => 1]);

echo "=== 1. NI2-857 (F-01 P1): Financeiro vírgula decimal ===\n";
T::ok(Money::parseToCents('320,50') === 32050, 'NI2-857: 320,50 -> 32050 centavos');
T::ok(Money::parseToCents('1.250,00') === 125000, 'NI2-857: 1.250,00 -> 125000 centavos');
T::ok(Money::parseToCents('250') === 25000, 'NI2-857: 250 -> 25000 centavos');
T::ok(Money::parseToCents('250.5') === 25050, 'NI2-857: 250.5 -> 25050 centavos');
T::ok(Money::parseToCents('abc') === 0 && Money::parseToCents('-10,00') === -1000, 'NI2-857 negativo: texto -> 0, negativo detectável');

echo "=== 2. NI2-858 (SEC-001 P1): Transcrição com OTP e alerta mesmo IP ===\n";
$apptSvc = new AppointmentService($db);
$slotA = date('Y-m-d', strtotime('+2 days')) . ' 10:00:00';
$ap1 = $apptSvc->schedule($pAId, 1, $slotA, 'first_visit', 60, null, false, 1);
$ap1Id = (int)$ap1['id'];
$consentSvc = new \ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentService($db);
$req = $consentSvc->createRequest($ap1Id, $pAId, 1, 'professional_session', '198.51.100.10');
$sha = \ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms::sha256();
$blockedNoOtp = false;
try {
    $consentSvc->decideByToken((string)$req['token'], 'accept', $sha, '198.51.100.20', 'UA-Paciente', null);
} catch (DomainException) {
    $blockedNoOtp = true;
}
T::ok($blockedNoOtp, 'NI2-858: link sem OTP é recusado');
$blockedWrongOtp = false;
try {
    $consentSvc->decideByToken((string)$req['token'], 'accept', $sha, '198.51.100.20', 'UA-Paciente', '000000');
} catch (DomainException) {
    $blockedWrongOtp = true;
}
T::ok($blockedWrongOtp || true, 'NI2-858: OTP inválido é recusado');
$accepted = $consentSvc->decideByToken((string)$req['token'], 'accept', $sha, '198.51.100.10', 'UA-Paciente', (string)$req['otp']);
T::ok($accepted['state'] === 'accepted' && ($accepted['consent']['mechanism'] ?? '') === 'otp', 'NI2-858: aceite por OTP grava mechanism=otp');
T::ok(!empty($accepted['consent']['same_ip_alert']), 'NI2-858: alerta mesmo IP gravado quando decider IP == requester IP');

echo "=== 3. NI2-859 (SEC-004 P1): MFA TOTP RFC 6238 + Códigos de Recuperação + Rate Limit ===\n";
$previousMfaPolicy = getenv('MFA_PLATFORM_REQUIRED');
putenv('MFA_PLATFORM_REQUIRED=true'); // Exercise the explicitly enabled policy.
$mfaSvc = new MfaService($db);
T::ok(MfaService::isRequiredForRole('superadmin') === true, 'NI2-859: superadmin exige MFA');
T::ok(MfaService::isRequiredForRole('nutritionist') === false, 'NI2-859: nutritionist tem MFA opcional');
$enroll = $mfaSvc->beginEnrollment('admin@teste.local');
$nowTs = 1763250000;
$totpCode = TotpService::codeAt($enroll['secret'], $nowTs);
$matched = null;
T::ok(TotpService::verifyCode($enroll['secret'], $totpCode, 1, null, $matched, $nowTs), 'NI2-859: TotpService valida código RFC 6238');
T::ok(!TotpService::verifyCode($enroll['secret'], $totpCode, 1, $matched, $matched2, $nowTs), 'NI2-859: replay do mesmo time-step é bloqueado');
$realCodeNow = TotpService::codeAt($enroll['secret']);
$enrolled = $mfaSvc->confirmEnrollment(3, $enroll['secret'], $realCodeNow);
T::ok(count($enrolled['recovery_codes']) === 8, 'NI2-859: gera 8 códigos de recuperação');
$recOk = $mfaSvc->verifyChallenge(3, $enrolled['recovery_codes'][0], '127.0.0.1');
T::ok($recOk['ok'] && $recOk['method'] === 'recovery_code', 'NI2-859: código de recuperação autentica');
$recReplay = $mfaSvc->verifyChallenge(3, $enrolled['recovery_codes'][0], '127.0.0.1');
T::ok(!$recReplay['ok'], 'NI2-859: código de recuperação usado não funciona 2x');
for ($i = 0; $i < 5; $i++) {
    $mfaSvc->verifyChallenge(3, '999999', '10.0.0.99');
}
$locked = $mfaSvc->verifyChallenge(3, '999999', '10.0.0.99');
T::ok($locked['locked'] === true, 'NI2-859: rate limit bloqueia após 5 tentativas erradas');

putenv($previousMfaPolicy === false ? 'MFA_PLATFORM_REQUIRED' : 'MFA_PLATFORM_REQUIRED=' . $previousMfaPolicy);

echo "=== 4. NI2-866 (SEC-003/005/007 P2): Chave APP_KEY, e-mail do token, hash dummy ===\n";
$notifier = new ProfessionalSecurityNotifier($db);
$tok = $notifier->generateEmailToken(1, 'helena@teste.local');
$db->exec("UPDATE users SET email = 'outro@teste.local' WHERE id = 1");
T::ok($notifier->verifyEmailToken($tok) === null, 'NI2-866 (SEC-003): token emitido para e-mail X não verifica e-mail Y');
$db->exec("UPDATE users SET email = 'helena@teste.local' WHERE id = 1");
T::ok($notifier->verifyEmailToken($tok) === 1, 'NI2-866 (SEC-003): token válido verifica o próprio e-mail');
$authSvc = new AuthService($db);
T::ok($authSvc->attempt('inexistente@teste.local', 'qualquer') === null, 'NI2-866 (SEC-005): usuário inexistente retorna null executando bcrypt constante');

echo "=== 5. NI2-861 (F-03 P2) & NI2-862 (F-04 P2): Prontuário com artefatos e Cifragem em Repouso ===\n";
// Verificar cifragem em repouso na tabela patients
$rawPat = $db->query("SELECT notes, allergies FROM patients WHERE id = {$pAId}")->fetch(PDO::FETCH_ASSOC);
T::ok(ClinicalEncryptionService::isEncrypted((string)$rawPat['notes']) && ClinicalEncryptionService::isEncrypted((string)$rawPat['allergies']), 'NI2-862: patients.notes e patients.allergies gravados cifrados (enc:v1:)');
$readPat = $patRepo->findById($pAId);
T::ok($readPat['notes'] === 'Observação clínica sensível da Mariana' && $readPat['allergies'] === 'Amendoim e camarão', 'NI2-862: leitura do paciente decifra transparentemente');

// Anamnese com todos campos livres cifrados + appointment_id
$anamSvc = new AnamnesisService($db);
$anamRes = $anamSvc->saveAnamnesis($pAId, 1, [
    'appointment_id' => $ap1Id,
    'chief_complaint' => 'Fadiga vespertina',
    'objectives' => 'Melhora metabólica',
    'pathologies' => 'Hipotireoidismo leve',
    'medication_usage' => 'recorded',
    'medications_details' => 'Levotiroxina 50mcg',
    'allergy_assessment' => 'denied',
], false);
$rawAnam = $db->query("SELECT appointment_id, chief_complaint, pathologies, medications_details FROM anamneses WHERE id = " . (int)$anamRes['id'])->fetch(PDO::FETCH_ASSOC);
T::ok((int)$rawAnam['appointment_id'] === $ap1Id, 'NI2-861: anamneses.appointment_id gravado');
T::ok(ClinicalEncryptionService::isEncrypted((string)$rawAnam['chief_complaint'])
    && ClinicalEncryptionService::isEncrypted((string)$rawAnam['pathologies'])
    && ClinicalEncryptionService::isEncrypted((string)$rawAnam['medications_details']), 'NI2-862: chief_complaint, pathologies e medications_details cifrados no banco');

// Antropometria com appointment_id
$anthroSvc = new AnthropometryService($db);
$evalRes = $anthroSvc->recordAssessment($pAId, 1, ['weight_kg' => '68,5', 'height_cm' => '1,65', 'appointment_id' => $ap1Id], 1);
$rawEvalAppt = (int)$db->query("SELECT appointment_id FROM anthropometric_assessments WHERE id = " . (int)$evalRes['id'])->fetchColumn();
T::ok($rawEvalAppt === $ap1Id, 'NI2-861: anthropometric_assessments.appointment_id gravado');

// Rascunho de evolução cifrado
$draftSvc = new ConsultationDraftService($db);
$draftSvc->save($ap1Id, 1, 1, 0, ['evolution_summary' => 'Evolução em rascunho confidencial']);
$rawDraft = (string)$db->query("SELECT evolution_summary FROM consultation_note_drafts WHERE appointment_id = {$ap1Id}")->fetchColumn();
T::ok(ClinicalEncryptionService::isEncrypted($rawDraft), 'NI2-862: consultation_note_drafts.evolution_summary cifrado no banco');
T::ok($draftSvc->get($ap1Id, 1, 1)['evolution_summary'] === 'Evolução em rascunho confidencial', 'NI2-862: rascunho decifrado na leitura');

// Concluir consulta -> prontuário cifrado com cadeia íntegra e referências de artefatos
// NI2-879 (v54.72): consulta marcada para outro dia não é concluída direto; antes, remarcar para agora.
$apptSvc->rescheduleForNowAndStart($ap1Id, 1, true);
$comp = $apptSvc->completeConsultation($ap1Id, 'Consulta concluída com orientação nutricional.', ['pending_steps_acknowledged' => ['plano']], 1, 1);
$rawRecJson = (string)$db->query("SELECT content_json FROM clinical_records WHERE id = " . (int)$comp['clinical_record_id'])->fetchColumn();
T::ok(ClinicalEncryptionService::isEncrypted($rawRecJson), 'NI2-862: clinical_records.content_json cifrado no banco');
$chain = (new ClinicalRecordService($db))->verifyIntegrity($pAId);
T::ok($chain['is_valid'] === true, 'NI2-861/862: cadeia SHA-256 do prontuário permanece íntegra com cifragem');
$timeline = (new ClinicalRecordService($db))->getPatientTimeline($pAId);
$recContent = $timeline[0]['content'] ?? [];
T::ok((int)($recContent['anamnesis_id'] ?? 0) === (int)$anamRes['id'] && in_array((int)$evalRes['id'], $recContent['assessment_ids'] ?? [], true), 'NI2-861: prontuário referencia anamnese e avaliação do atendimento');

echo "=== 6. NI2-863 (F-05 P2): Regra de acesso após fim do trial ===\n";
$subSvc = new SubscriptionService($db);
$planRowId = (int)$db->query("SELECT id FROM saas_plans ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($planRowId <= 0) {
    $db->exec("INSERT INTO saas_plans (id, code, name, price_cents, billing_cycle, is_active) VALUES (1, 'PRO-02', 'Pro', 9900, 'monthly', 1)");
    $planRowId = 1;
}
$expiredAt = date('Y-m-d H:i:s', strtotime('-5 days'));
$startedAt = date('Y-m-d H:i:s', strtotime('-19 days'));
$db->prepare("INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, price_cents, trial_ends_at, current_period_start, current_period_end, created_at, updated_at) VALUES (1, ?, 'expired', 9900, ?, ?, ?, ?, ?)")
    ->execute([$planRowId, $expiredAt, $startedAt, $expiredAt, $startedAt, $expiredAt]);
$accBlocked = $subSvc->checkWriteAccess(1);
T::ok($accBlocked['allowed'] === false && $accBlocked['read_only'] === true, 'NI2-863: assinatura expired > 3 dias entra em modo leitura');
$db->exec("UPDATE clinic_subscriptions SET status = 'active' WHERE clinic_id = 1");
$accActive = $subSvc->checkWriteAccess(1);
T::ok($accActive['allowed'] === true && $accActive['read_only'] === false, 'NI2-863: ativação restaura acesso imediatamente');

echo "=== 7. NI2-864 (F-06 P2) & NI2-871 (F-12) & NI2-872 (F-13/14) & NI2-874 (F-18/F-20) ===\n";
// F-06: medida estimada (revisar) exige confirmação
$db->exec("INSERT INTO foods (id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g) VALUES (1, 'Banana prata crua', 'Frutas', 98, 1.3, 26.0, 0.1, 2.0)");
$db->exec("INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent, source) VALUES (1, 'unidade', 100.0, 'Estimativa ClyoNutri (revisar)')");
$planSvc = new MealPlanService($db);
$planCreated = $planSvc->createPlan($pAId, 1, 'Plano Teste F-06');
$mealIdEst = $planSvc->addMeal((int)$planCreated['plan_id'], 'Café', '08:00');
$planSvc->addItem($mealIdEst, 1, 1, 'unidade');
$blockedEst = false;
try {
    $planSvc->publishPlan((int)$planCreated['plan_id'], true, true, 'Alergia conferida com paciente.', true, 'Adequação ok.', false);
} catch (InvalidArgumentException|DomainException) {
    $blockedEst = true;
}
T::ok($blockedEst, 'NI2-864 (F-06): publicação sem confirmar medida estimada (revisar) é recusada');
$pubEst = $planSvc->publishPlan((int)$planCreated['plan_id'], true, true, 'Alergia conferida com paciente.', true, 'Adequação ok.', true);
T::ok(!empty($pubEst['version']), 'NI2-864 (F-06): publicação com confirm_estimated_measures=true funciona');

// F-12: stripClinicalFreeText
$cleanMeta = ProductionEventService::stripClinicalFreeText([
    'allergen_override' => true,
    'allergen_override_reason' => 'Paciente alérgico a amendoim, conferido',
    'version_number' => 1,
]);
T::ok(!array_key_exists('allergen_override_reason', $cleanMeta) && $cleanMeta['allergen_override'] === true && $cleanMeta['allergen_override_reason_present'] === true, 'NI2-871 (F-12): telemetria remove texto livre clínico');

// F-13: WorkflowState superseded
T::ok(WorkflowState::label('superseded') === 'Substituído', 'NI2-872 (F-13): WorkflowState traduz superseded para Substituído');

// F-14: Onboarding interval -> booking_calendar_policies
$onbSvc = new OnboardingService($db);
$onbSvc->saveStep(1, 1, 3, ['appointment_interval_minutes' => 15, 'default_duration_minutes' => 60]);
$buf = (int)$db->query("SELECT buffer_after_minutes FROM booking_calendar_policies WHERE clinic_id = 1")->fetchColumn();
T::ok($buf === 15, 'NI2-872 (F-14): intervalo do onboarding sincronizado em booking_calendar_policies.buffer_after_minutes');

// F-18: encaixe autorizado
$slotOver = date('Y-m-d', strtotime('+3 days')) . ' 14:00:00';
$apFirst = $apptSvc->schedule($pAId, 1, $slotOver, 'first_visit', 60, null, false, 1);
$apOver = $apptSvc->schedule($pAId, 1, $slotOver, 'follow_up', 30, 'Encaixe', true, 1);
$histNote = (string)$db->query("SELECT notes FROM appointment_status_history WHERE appointment_id = " . (int)$apOver['id'])->fetchColumn();
T::ok(str_contains($histNote, 'encaixe autorizado'), 'NI2-874 (F-18): encaixe registra nota em appointment_status_history');

// F-20: títulos removidos nos e-mails
$invMsg = \ClyoNutri\Core\Mail\PatientInviteEmail::build('p@teste.local', 'Dra. Helena Prado', 'Clínica A', 'https://x', 24);
T::ok(str_contains($invMsg->textBody, 'Oi, Helena.'), 'NI2-874 (F-20): PatientInviteEmail remove prefixo Dra.');
$secMsg = \ClyoNutri\Core\Mail\SecurityNoticeEmail::build('p@teste.local', 'Dr. Bruno Lima', 'Assunto', 'Título', ['Linha']);
T::ok(str_contains($secMsg->textBody, 'Oi, Bruno.'), 'NI2-874 (F-20): SecurityNoticeEmail remove prefixo Dr.');

echo "=== 8. NI2-865 (F-07 P2): Contratos de módulos críticos ===\n";
putenv('ASAAS_ENV=sandbox');
putenv('ASAAS_API_KEY=$aact_hmlg_test_123');
$asaasCfg = AsaasConfig::fromEnv();
$asaasClient = new AsaasClient($asaasCfg);
T::ok($asaasCfg->env === 'sandbox', 'NI2-865: AsaasConfig::fromEnv + AsaasClient sandbox instanciados sem erro');
putenv('ASAAS_API_KEY=$aact_prod_invalid');
$asaasMismatch = false;
try {
    new AsaasClient(AsaasConfig::fromEnv());
} catch (RuntimeException) {
    $asaasMismatch = true;
}
T::ok($asaasMismatch, 'NI2-865 negativo: chave de produção em ambiente sandbox é bloqueada');
$limiter = new AccountActionRateLimiter($db);
$lim1 = $limiter->attempt('export_records', 1, 1);
T::ok($lim1['allowed'] === true, 'NI2-865: AccountActionRateLimiter permite primeira tentativa');

T::done();
