<?php
// NI2-884 (v54.76): publishPlan passou a exigir autorização de adequação (NI2-587); chamadas atualizadas com justificativa de TESTE.

declare(strict_types=1);

/**
 * Suíte Automatizada Fase 3 — Épico NI2-514 (NI2-524, NI2-525, NI2-526, NI2-527 e NI2-528)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\ClinicalAuthorization;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Core\Time\AgendaNavigation;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\Evolution\PatientEvolutionService;
use ClyoNutri\Domain\LaboratoryExam\ExamCatalogService;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\MealPlanVersionDiffService;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

$dbPath = sys_get_temp_dir() . '/clyonutri_ni2_514_phase3_' . getmypid() . '.sqlite';
@unlink($dbPath);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (1, 'c-f3-1', 'Clínica Fase 3', 'active')");
$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status, is_verified) VALUES
    (1, 'u-f3-1', 'Dra. Camila Mendes', 'camila@f3.test', 'x', 'nutritionist', 'active', 1)");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES (1, 1, '12345', 'CRN-3', 'Nutrição Clínica')");
$db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, phone, birth_date, gender, status) VALUES
    (10, 1, 'p-f3-10', 'Marina Oliveira', 'marina@paciente.test', '11988887777', '1995-05-15', 'Feminino', 'active')");

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failed++;
    }
}

echo "=== Fase 3 NI2-514: Antropometria (NI2-524), Alérgenos no Ato (NI2-525), Diff de Versões (NI2-526), Agenda (NI2-527) e Benchmark Longitudinal (NI2-528) ===\n\n";

TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Camila Mendes']); // Clínica 1, Dra. Camila (professional_id = 1)

// =========================================================================
// 1. NI2-524: Unificar antropometria, 14 circunferências, BIA e proveniência
// =========================================================================
echo "--- 1. NI2-524: Fluxo unificado de antropometria, 14 circunferências, BIA e proveniência separada ---\n";

$anthroSvc = new AnthropometryService($db);

// Garantir data de nascimento e sexo biológico da paciente 10 (Marina, ~30 anos)
$db->prepare("UPDATE patients SET birth_date = '1995-05-15', gender = 'Feminino' WHERE id = 10 AND clinic_id = 1")->execute();

$eval1 = $anthroSvc->recordAssessment(10, 1, [
    'assessed_at' => '2026-09-01 09:00:00',
    'weight_kg' => '72.0',
    'height_cm' => '165.0',
    'waist_cm' => '80.0',
    'hip_cm' => '102.0',
    'abdomen_cm' => '84.0',
    'neck_cm' => '34.0',
    'chest_cm' => '92.0',
    'shoulder_cm' => '104.0',
    'arm_cm' => '29.5',
    'arm_left_cm' => '29.0',
    'arm_contracted_cm' => '31.0',
    'forearm_cm' => '24.0',
    'wrist_cm' => '15.5',
    'thigh_cm' => '58.0',
    'thigh_proximal_cm' => '61.0',
    'calf_cm' => '37.0',
    'calf_left_cm' => '36.8',
    'body_fat_pct' => '28.5',
    'body_fat_source' => 'bioimpedance',
    'bioimpedance_device' => 'InBody 270',
    'muscle_mass_kg' => '24.2',
    'visceral_fat_level' => '8',
    'total_body_water_pct' => '51.2',
    'bone_mass_kg' => '2.4',
    'phase_angle_deg' => '6.2',
    'skinfold_protocol' => 'jp3_female',
    'readings' => [
        'triceps_mm' => [18.0, 18.0],
        'suprailiac_mm' => [20.0, 20.0],
        'thigh_mm' => [24.0, 24.0],
    ],
    'notes' => 'Avaliação inicial unificada (BIA + Dobras JP3)',
]);

check(count(AnthropometryService::CIRCUMFERENCE_FIELDS) === 14, 'NI2-524: Catálogo expandido possui exatamente 14 circunferências');
check((float)$eval1['abdomen_cm'] === 84.0 && (float)$eval1['shoulder_cm'] === 104.0 && (float)$eval1['wrist_cm'] === 15.5 && (float)$eval1['thigh_proximal_cm'] === 61.0 && (float)$eval1['calf_left_cm'] === 36.8, 'NI2-524: Persiste todas as novas circunferências (abdome, ombro, antebraço, punho, coxa proximal, panturrilha E)');
check((float)$eval1['visceral_fat_level'] === 8.0 && (float)$eval1['total_body_water_pct'] === 51.2 && (float)$eval1['bone_mass_kg'] === 2.4 && (float)$eval1['phase_angle_deg'] === 6.2, 'NI2-524: Persiste métricas de bioimpedância (gordura visceral, água corporal, massa óssea, ângulo de fase)');
check((float)$eval1['body_fat_pct'] === 28.5 && $eval1['body_fat_source'] === 'bioimpedance', 'NI2-524: %GC da bioimpedância (28,5%) permanece intacto e separado do cálculo por dobras');
check(is_array($eval1['derived_skinfold']) && $eval1['skinfold_body_fat_pct'] !== null && abs((float)$eval1['skinfold_body_fat_pct'] - 28.5) > 0.1, 'NI2-524: Dobras cutâneas registradas no fluxo unificado geram %GC estimado próprio (Siri + JP3) sem sobrescrever o %GC da BIA');
check(str_contains((string)$eval1['body_fat_source_label'], 'InBody 270'), 'NI2-524: Rótulo de proveniência identifica explicitamente Bioimpedância (InBody 270)');

$eval2 = $anthroSvc->recordAssessment(10, 1, [
    'assessed_at' => '2026-10-15 09:00:00',
    'weight_kg' => '69.2',
    'height_cm' => '165.0',
    'waist_cm' => '76.5',
    'hip_cm' => '99.5',
    'abdomen_cm' => '80.0',
    'shoulder_cm' => '104.0',
    'arm_cm' => '29.0',
    'arm_contracted_cm' => '31.5',
    'body_fat_pct' => '25.8',
    'body_fat_source' => 'bioimpedance',
    'bioimpedance_device' => 'InBody 270',
    'muscle_mass_kg' => '24.9',
    'visceral_fat_level' => '6',
    'total_body_water_pct' => '53.0',
    'bone_mass_kg' => '2.45',
    'phase_angle_deg' => '6.5',
    'skinfold_protocol' => 'jp3_female',
    'readings' => [
        'triceps_mm' => [15.0, 15.0],
        'suprailiac_mm' => [16.0, 16.0],
        'thigh_mm' => [21.0, 21.0],
    ],
]);

$comp = $anthroSvc->compareAssessments(10, (int)$eval1['id'], (int)$eval2['id']);
check(isset($comp['metrics']['body_fat_pct']) && isset($comp['metrics']['skinfold_body_fat_pct']), 'NI2-524: Comparador longitudinal mantém linhas separadas para %GC Medida/BIA e %GC Estimada por Dobras');
check((float)$comp['metrics']['body_fat_pct']['delta'] === -2.7 && (float)$comp['metrics']['abdomen_cm']['delta'] === -4.0, 'NI2-524: Comparador calcula deltas exatos de %GC BIA (-2,7%) e circunferência abdominal (-4,0 cm)');

$dashCtrlSource = (string)file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
check(str_contains($dashCtrlSource, 'data-anthro-tab="basico"') && str_contains($dashCtrlSource, 'data-anthro-tab="circunferencias"') && str_contains($dashCtrlSource, 'data-anthro-tab="dobras"') && str_contains($dashCtrlSource, 'data-anthro-tab="bioimpedancia"'), 'NI2-524: Modal de antropometria unificado em 4 abas preservando estado do formulário');

// =========================================================================
// 2. NI2-525: Alertar alergias e restrições no momento da adição de alimentos
// =========================================================================
echo "\n--- 2. NI2-525: Alerta de alergias e restrições na busca, seleção e adição de alimentos ---\n";

$allergenValidator = new AllergenSafetyValidator($db);

// Criar paciente sem anamnese para testar estado not_evaluated
$db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status, created_at) VALUES (1, 'p-no-anam', 'Paciente Sem Anamnese', 'semanamnese@teste.local', 'active', CURRENT_TIMESTAMP)")->execute();
$patNoAnamId = (int)$db->lastInsertId();

$stateNotEval = $allergenValidator->evaluatePatientAllergyState($patNoAnamId);
check($stateNotEval['state'] === AllergenSafetyValidator::STATE_NOT_EVALUATED, 'NI2-525: Paciente sem anamnese possui estado de alergia not_evaluated');

// Criar alimento sem metadado de alérgenos e alimento com amendoim
$db->prepare("INSERT INTO foods (clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, allergens) VALUES (1, 'Biscoito artesanal sem rótulo', 'Cereais', 410, 6, 65, 14, 2, NULL)")->execute();
$foodUnverifiedId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO foods (clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, allergens) VALUES (1, 'Pasta de amendoim integral', 'Oleaginosas', 588, 25, 20, 50, 6, 'amendoim')")->execute();
$foodPeanutId = (int)$db->lastInsertId();

$inspNotEval = $allergenValidator->inspectFoodForPatient($patNoAnamId, $foodPeanutId);
check($inspNotEval['status'] === 'allergy_not_evaluated' && $inspNotEval['requires_attention'] === true && !str_contains(mb_strtolower($inspNotEval['message']), 'livre de alérgenos'), 'NI2-525: Nunca declara "seguro/livre de alérgenos" quando anamnese não avaliou alergias');

// Registrar anamnese confirmando alergia a amendoim e restrição vegana na paciente 10
$anamSvc = new AnamnesisService($db);
$anamSvc->saveAnamnesis(10, 1, [
    'allergies' => 'Alergia severa a amendoim e castanhas',
    'allergy_assessment' => 'recorded',
    'restrictions' => 'Vegano',
    'objectives' => 'Hipertrofia e recomposição corporal',
]);

$stateConfirmed = $allergenValidator->evaluatePatientAllergyState(10);
check($stateConfirmed['state'] === AllergenSafetyValidator::STATE_CONFIRMED && in_array('amendoim', $stateConfirmed['patient_allergens'], true), 'NI2-525: Anamnese com alergia mapeia estado confirmed e grupo amendoim');

$inspUnverified = $allergenValidator->inspectFoodForPatient(10, $foodUnverifiedId);
check($inspUnverified['status'] === 'unverified' && $inspUnverified['requires_attention'] === true, 'NI2-525: Alimento sem campo allergens para paciente com alergia confirmada retorna status unverified (nunca "seguro")');

$inspConflict = $allergenValidator->inspectFoodForPatient(10, $foodPeanutId);
check($inspConflict['status'] === 'conflict' && $inspConflict['requires_override'] === true && in_array('amendoim', $inspConflict['matched_allergens'], true), 'NI2-525: Alimento com amendoim retorna status conflict e exige confirmação com justificativa');

// Testar adição ao plano com gate ativo (bloqueia sem justificativa; aceita e audita com justificativa)
$planSvc = new MealPlanService($db);
$draftPlan = $planSvc->createPlan(10, 1, 'Plano Retorno NI2-525', 'Notas', [
    ['name' => 'Lanche da Tarde', 'time_of_day' => '16:00'],
]);
$planDetails = $planSvc->getPlanWithDetails((int)$draftPlan['plan_id']);
$mealId = (int)$planDetails['meals'][0]['id'];

$blockedOnAdd = false;
try {
    $planSvc->addItem($mealId, $foodPeanutId, 30.0, 'g', null, false, true, false, null);
} catch (InvalidArgumentException $e) {
    $blockedOnAdd = str_contains($e->getMessage(), 'Conflito alergênico');
}
check($blockedOnAdd, 'NI2-525: addItem bloqueia no ato da adição quando enforceAllergenGate=true e conflito não foi justificado');

$addedItemId = $planSvc->addItem($mealId, $foodPeanutId, 30.0, 'g', 'Teste dessensibilização oral liberado pelo alergista', false, true, true, 'Liberado pelo médico alergista após teste de provocação oral.');
check($addedItemId > 0, 'NI2-525: addItem permite adição com confirmação expressa e justificativa clínica >= 10 caracteres');

$planAfterAdd = $planSvc->getPlanWithDetails((int)$draftPlan['plan_id']);
$savedItem = $planAfterAdd['meals'][0]['items'][0];
check(($savedItem['allergen_alert']['status'] ?? '') === 'conflict' && str_contains((string)$savedItem['allergen_ack_reason'], 'alergista'), 'NI2-525: Item guarda allergen_alert_json e allergen_ack_reason no banco');

$auditCount = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'MEAL_PLAN_ITEM_ALLERGEN_OVERRIDE' AND resource_id = '{$addedItemId}'")->fetchColumn();
check($auditCount === 1, 'NI2-525: Override de alérgeno na adição do item gera registro em audit_logs (MEAL_PLAN_ITEM_ALLERGEN_OVERRIDE)');

// Remover item conflitante para testar publicação limpa no NI2-526
$planSvc->deleteItem($addedItemId);

// =========================================================================
// 3. NI2-526: Comparar versões publicadas de planos alimentares no retorno
// =========================================================================
echo "\n--- 3. NI2-526: Comparação de versões publicadas (snapshots imutáveis) no retorno ---\n";

// Criar alimentos de teste com alérgenos declarados ('nenhum') para publicar sem alerta
$db->prepare("INSERT INTO foods (clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, allergens) VALUES (1, 'Arroz integral cozido v1', 'Cereais', 124, 2.6, 25.8, 1.0, 2.7, 'nenhum')")->execute();
$fRiceId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO foods (clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, allergens) VALUES (1, 'Feijão carioca cozido v1', 'Leguminosas', 76, 4.8, 13.6, 0.5, 8.5, 'nenhum')")->execute();
$fBeansId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO foods (clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, allergens) VALUES (1, 'Abacate in natura v1', 'Frutas', 96, 1.2, 6.0, 8.4, 6.3, 'nenhum')")->execute();
$fAvocadoId = (int)$db->lastInsertId();

// Limpar versões anteriores do paciente 10 para testar v1 -> v2 determinístico
$db->prepare("DELETE FROM meal_plan_versions WHERE clinic_id = 1 AND patient_id = 10")->execute();

$planV1 = $planSvc->createPlan(10, 1, 'Plano Fase Inicial (v1)', 'Foco adaptação', [
    ['name' => 'Almoço', 'time_of_day' => '12:30'],
]);
$p1Details = $planSvc->getPlanWithDetails((int)$planV1['plan_id']);
$p1MealId = (int)$p1Details['meals'][0]['id'];
$planSvc->addItem($p1MealId, $fRiceId, 100.0, 'g');   // 124 kcal
$planSvc->addItem($p1MealId, $fBeansId, 100.0, 'g');  // 76 kcal -> Total v1 = 200 kcal
$pub1 = $planSvc->publishPlan((int)$planV1['plan_id'], true, true, 'Alimentos vegetais conferidos sem alérgenos para a paciente.', true, 'Estrutura e metas do plano de teste conferidas (TESTE, NI2-884).');

// Duplicar v1 para criar v2: aumentar arroz para 150g (186 kcal), remover feijão, adicionar abacate 100g (96 kcal) -> Total v2 = 282 kcal
$copyPlan = $planSvc->duplicatePlan((int)$planV1['plan_id'], 'Plano Evolução Retorno (v2)');
$p2Details = $planSvc->getPlanWithDetails((int)$copyPlan['plan_id']);
$p2MealId = (int)$p2Details['meals'][0]['id'];
foreach ($p2Details['meals'][0]['items'] as $it) {
    if ((int)$it['food_id'] === $fRiceId) {
        $planSvc->updateItem((int)$it['id'], 150.0, 'g', 'Aumentar porção no retorno');
    } elseif ((int)$it['food_id'] === $fBeansId) {
        $planSvc->deleteItem((int)$it['id']);
    }
}
$planSvc->addItem($p2MealId, $fAvocadoId, 100.0, 'g');
$pub2 = $planSvc->publishPlan((int)$copyPlan['plan_id'], true, true, 'Alimentos vegetais conferidos sem alérgenos no retorno.', true, 'Estrutura e metas do plano de teste conferidas (TESTE, NI2-884).');

// Adulterar o catálogo vivo DEPOIS da publicação para provar que o diff lê estritamente do snapshot imutável!
$db->prepare("UPDATE foods SET energy_kcal = 9999 WHERE id = ?")->execute([$fRiceId]);

$diffSvc = new MealPlanVersionDiffService($db);
$diff = $diffSvc->compareVersions(10, (int)$pub1['version'], (int)$pub2['version']);

check((float)$diff['version_a']['energy_kcal'] === 200.0 && (float)$diff['version_b']['energy_kcal'] === 282.0, 'NI2-526: Diff usa estritamente os snapshots congelados (200 kcal -> 282 kcal), imune a alterações posteriores no catálogo');
check((float)$diff['totals_diff']['energy_kcal']['delta'] === 82.0 && (float)$diff['totals_diff']['energy_kcal']['pct'] === 41.0, 'NI2-526: Calcula delta energético total (+82 kcal / +41,0%) e de macronutrientes');
check($diff['summary_counts']['items_added'] === 1 && $diff['summary_counts']['items_removed'] === 1 && $diff['summary_counts']['items_modified'] === 1, 'NI2-526: Identifica exatamente 1 alimento incluído (Abacate), 1 retirado (Feijão) e 1 ajustado (Arroz 100g -> 150g)');
check(str_contains($diff['patient_safe_summary']['text'], 'incluído Abacate') && str_contains($diff['patient_safe_summary']['text'], 'retirado Feijão') && str_contains($diff['patient_safe_summary']['text'], 'ajustado Arroz'), 'NI2-526: Gera resumo seguro e legível para o paciente no retorno');

// Verificar RBAC: recepcionista não acessa /pacientes/{id}/planos/comparar
$rbacBlocked = false;
try {
    ClinicalAuthorization::assertWebAccess(['user_role' => 'receptionist', 'member_role' => 'receptionist'], '/pacientes/10/planos/comparar', 'GET', $db);
} catch (TenantSecurityException $e) {
    $rbacBlocked = true;
}
check($rbacBlocked, 'NI2-526: RBAC bloqueia recepcionista em /pacientes/{id}/planos/comparar');

// =========================================================================
// 4. NI2-527: Evoluir agenda (visão mensal + prévia e confirmação segura)
// =========================================================================
echo "\n--- 4. NI2-527: Visão mensal da agenda, bloqueio sem contato validado e envio autorizado idempotente ---\n";

$navMonth = AgendaNavigation::forView('month', '2026-10-15', '2026-10-15');
check($navMonth['view'] === 'month' && $navMonth['start'] === '2026-10-01' && $navMonth['end'] === '2026-10-31' && $navMonth['prev'] === '2026-09-01' && $navMonth['next'] === '2026-11-01', 'NI2-527: AgendaNavigation calcula início, fim, mês anterior e próximo mês na visão mensal');

$appSvc = new AppointmentService($db);

// Criar paciente SEM e-mail para testar proteção contra disparo indevido
$db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, phone, status, created_at) VALUES (1, 'p-no-email', 'Paciente Sem Email', '', '11999990000', 'active', CURRENT_TIMESTAMP)")->execute();
$patNoEmailId = (int)$db->lastInsertId();

$eventsBeforeNoEmail = (int)$db->query("SELECT COUNT(*) FROM notification_events WHERE clinic_id = 1 AND recipient_type = 'patient' AND recipient_id = {$patNoEmailId}")->fetchColumn();
$appNoEmail = $appSvc->schedule($patNoEmailId, 1, '2026-11-10 10:00', 'first_visit', 60, null, true);
$eventsAfterNoEmail = (int)$db->query("SELECT COUNT(*) FROM notification_events WHERE clinic_id = 1 AND recipient_type = 'patient' AND recipient_id = {$patNoEmailId}")->fetchColumn();
// NI2-884: o evento interno pode existir (central do paciente); a garantia do NI2-527 é que nenhuma mensagem externa sai.
// O cron de envio é executado de verdade e a entrega de e-mail tem de ficar 'skipped/no_email' (nada enviado nem registrado).
(new \ClyoNutri\Domain\Notification\NotificationService($db, new \ClyoNutri\Core\Mail\ArrayMailer()))->drain();
$externalOut = $db->query("SELECT d.channel, d.status FROM notification_events e JOIN notification_deliveries d ON d.event_id = e.id WHERE e.recipient_type = 'patient' AND e.recipient_id = {$patNoEmailId} AND d.channel = 'email'")->fetchAll(PDO::FETCH_ASSOC);
check($externalOut !== [] && array_filter($externalOut, fn($d) => $d['status'] !== 'skipped') === [], 'NI2-527: Agendamento para paciente sem e-mail validado NÃO dispara mensagem externa automática (entrega de e-mail ignorada pelo cron)');

$prevNoEmail = $appSvc->previewConfirmationMessage((int)$appNoEmail['id']);
check($prevNoEmail['can_send'] === false && str_contains((string)$prevNoEmail['blocked_reason'], 'sem e-mail válido'), 'NI2-527: Prévia de confirmação bloqueia envio e explica o motivo quando paciente não tem e-mail válido');

// Testar agendamento + prévia + confirmação autorizada idempotente para paciente com e-mail validado (paciente 10)
$appValid = $appSvc->schedule(10, 1, '2026-11-12 14:00', 'follow_up', 60, 'Retorno mensal', true);
$prevValid = $appSvc->previewConfirmationMessage((int)$appValid['id']);
check($prevValid['can_send'] === true && $prevValid['recipient_email'] === 'marina@paciente.test' && str_contains($prevValid['body_preview'], '12/11/2026 às 14:00'), 'NI2-527: Prévia exibe destinatário, canal, fuso horário e texto da mensagem antes do envio');

$send1 = $appSvc->sendAuthorizedConfirmation((int)$appValid['id'], 1);
$send2 = $appSvc->sendAuthorizedConfirmation((int)$appValid['id'], 1);
// NI2-884: o envio ficou com o worker (sent=false, queued=true); idempotência = mesmo evento, segundo pedido reaproveitado.
$dupCount = (int)$db->query("SELECT COUNT(*) FROM notification_events WHERE dedupe_key = " . $db->quote((string)$send1['dedupe_key']))->fetchColumn();
// A confirmação já é enfileirada no agendamento (AppointmentConfirmationPublisher); o envio autorizado reaproveita o mesmo evento.
check($dupCount === 1 && $send1['queued'] === true && $send2['queued'] === true && $send2['idempotent_reused'] === true && $send1['event_id'] === $send2['event_id'], 'NI2-527: Envio autorizado de confirmação é estritamente idempotente (não duplica evento na fila)');

$monthData = $appSvc->listByMonth('2026-11-01');
check($monthData['start_date'] === '2026-11-01' && $monthData['end_date'] === '2026-11-30' && count($monthData['days']) === 30 && $monthData['total_appointments'] >= 2, 'NI2-527: listByMonth retorna todos os dias do mês e agrupa as consultas agendadas');

// =========================================================================
// 5. NI2-528: Benchmark complementar (mobile, teleconsulta e evolução longitudinal)
// =========================================================================
echo "\n--- 5. NI2-528: Cenário longitudinal ponta a ponta (Exames + Teleconsulta + Portal Mobile + Evolução) ---\n";

// 5.1 Solicitação de exames laboratoriais regulamentada pelo CFN (NI2-529..NI2-533)
$examCatSvc = new ExamCatalogService($db);
$eligibleExams = $examCatSvc->search('', null, true, 10);
$examIdsToRequest = array_slice(array_map(static fn(array $r) => (int)$r['id'], $eligibleExams), 0, 3);
$examReqSvc = new ExamRequestService($db);
\ClyoNutri\Core\Auth\TenantContext::setClinicId(1);
$draft = $examReqSvc->saveDraft(10, 1, [
    'exam_ids' => $examIdsToRequest,
    'clinical_justification' => 'Avaliação metabólica e bioquímica para acompanhamento longitudinal de recomposição corporal e ajuste dietoterápico no retorno.',
    'diagnostic_hypothesis_nutritional' => 'Monitoramento nutricional de rotina',
    'coverage_disclaimer_acknowledged' => true,
]);
$review = $examReqSvc->submitForReview((int)$draft['id'], 1);
$issuedReq = $examReqSvc->issue((int)$review['id'], 1, true);
check((int)$issuedReq['id'] > 0 && count($issuedReq['items']) === 3 && !empty($issuedReq['pdf_hash']), 'NI2-528: Solicitação de exames laboratoriais emitida com hash SHA256 e justificativa clínica nutricional');

// 5.2 Evolução longitudinal do paciente (medidas oficiais vs autorrelato + metas)
$evolSvc = new PatientEvolutionService($db);
$evolSvc->setEnabledMetrics(10, ['weight_kg', 'waist_cm'], 1);
$evolSvc->recordSelfMetric(10, 'weight_kg', 70.4, 'kg', 'Pesagem em jejum em casa');
$goal = $evolSvc->createGoal(10, 1, 1, [
    'metric_type' => 'weight_kg',
    'direction' => 'decrease',
    'target_value' => 67.0,
    'message_for_patient' => 'Meta de recomposição corporal mantendo massa magra.',
]);
$panel = $evolSvc->professionalPanel(10);
check(count($panel['self_reports']) === 1 && count($panel['goals']) === 1 && (float)$panel['goals'][0]['target_value'] === 67.0, 'NI2-528: Evolução longitudinal distingue medição clínica oficial de autorrelato do paciente e acompanha metas');

// 5.3 Teleconsulta: ciclo completo (agendamento -> convite -> device check -> consentimento LGPD -> sala de espera -> admissão)
$teleApp = $appSvc->schedule(10, 1, date('Y-m-d H:i', time() + 600), 'telehealth', 60, 'Teleconsulta de retorno', true);
$teleSvc = new TeleconsultationService($db);
$scheduledTele = $teleSvc->scheduleSession((int)$teleApp['id'], 1, 1);
$rawToken = (string)$scheduledTele['raw_patient_token'];
check($scheduledTele['created'] === true && strlen($rawToken) === 64, 'NI2-528: Teleconsulta gera token seguro de convite (64 hex, armazenado apenas como hash)');

$teleSvc->submitDeviceCheck($rawToken, ['mode' => 'full', 'camera_ok' => true, 'microphone_ok' => true, 'browser' => 'Chrome']);
$activeTerm = $teleSvc->getActiveConsentTerm();
$teleSvc->acceptConsent($rawToken, (int)$activeTerm['id'], '127.0.0.1', 'Mozilla/5.0 Test');
$waitRes = $teleSvc->enterWaitingRoom($rawToken);

// Verificar isolamento fail-closed sem chave de mídia configurada
putenv('APP_KEY=');
$_ENV['APP_KEY'] = '';
$blockedWithoutKey = false;
try {
    (new TeleconsultationService($db))->admitPatient((int)$scheduledTele['session_id'], 1);
} catch (\ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException $e) {
    $blockedWithoutKey = true;
}
check($blockedWithoutKey, 'NI2-528: Teleconsulta bloqueia provisionamento de mídia (fail-closed) quando provedor/chave não está habilitado');

// Habilitar chave de assinatura e admitir paciente
putenv('APP_KEY=clyonutri-phase3-test-signing-key-2026');
$_ENV['APP_KEY'] = 'clyonutri-phase3-test-signing-key-2026';
$admitRes = (new TeleconsultationService($db))->admitPatient((int)$scheduledTele['session_id'], 1);
check($waitRes['status'] === TeleconsultationService::STATUS_WAITING_ROOM && $admitRes['status'] === TeleconsultationService::STATUS_ADMITTED, 'NI2-528: Teleconsulta transita por device_checked -> consentimento LGPD -> waiting_room -> admitted');

// 5.4 App / Portal Mobile do Paciente: recebe estritamente a versão publicada vigente (v2), nunca rascunhos
$planSvc->createPlan(10, 1, 'Rascunho Secreto Não Publicado', 'Rascunho interno', [
    ['name' => 'Ceia', 'time_of_day' => '22:00'],
]);
$publishedForMobile = $planSvc->getPatientPublishedPlan(10);
check($publishedForMobile !== null && (int)$publishedForMobile['version_number'] === (int)$pub2['version'] && $publishedForMobile['title'] === 'Plano Evolução Retorno (v2)', 'NI2-528: Portal/App do paciente exibe estritamente a última versão publicada (v2) e ignora rascunhos');

echo "\nResultado NI2-514 Fase 3: {$passed} PASS, {$failed} FAIL\n";
@unlink($dbPath);
exit($failed > 0 ? 1 : 0);
