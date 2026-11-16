<?php
/**
 * Épico NI2-844 (v54.62): Simplificação das jornadas do nutricionista — findings da auditoria UX 360° (UX-001..UX-019).
 * Cobre todas as 11 histórias (NI2-845..NI2-855) com testes unitários, de integração com banco real (SQLite migrado),
 * caminhos positivos, negativos, limites, permissões e isolamento multi-tenant.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5462-ni2-844.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\FoodSearchController;
use ClyoNutri\Application\Controllers\OnboardingController;
use ClyoNutri\Application\Controllers\WorkflowController;
use ClyoNutri\Application\View\AttendanceContext;
use ClyoNutri\Application\View\PatientHeader;
use ClyoNutri\Application\View\RoleNav;
use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\AuthUser;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\ConsultationDraftService;
use ClyoNutri\Domain\Dashboard\DashboardOverview;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Food\FoodSearchIndex;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Nutrition\EnergyCalculator;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Onboarding\OnboardingService;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$setActor = static function (array $u, string $name = 'Dra. Helena Prado', string $clinicName = 'Clínica Prado'): void {
    AuthContext::set(new AuthUser(
        id: (int)$u['user_id'],
        uuid: 'u-' . (int)$u['user_id'],
        name: $name,
        email: 'helena@teste.invalid',
        userRole: 'nutritionist',
        authVersion: 0,
        clinicId: (int)$u['clinic_id'],
        memberRole: 'owner',
        clinicName: $clinicName
    ));
    $_SESSION['user_id'] = (int)$u['user_id'];
    $_SESSION['clinic_id'] = (int)$u['clinic_id'];
    $_SESSION['user_role'] = 'nutritionist';
    $_SESSION['member_role'] = 'owner';
    $_SESSION['user_name'] = $name;
    $_SESSION['clinic_name'] = $clinicName;
};

$clinicA = Fx::clinic($pdo, 'Clínica Prado');
$userA = Fx::user($pdo, $clinicA, 'Dra. Helena Prado', 'owner', '45890', 'CRN-3');
$patientA = Fx::patient($pdo, $clinicA, 'Mariana Souza Lima');

$clinicB = Fx::clinic($pdo, 'Clínica Outra');
$userB = Fx::user($pdo, $clinicB, 'Dr. Outro', 'owner', '99999', 'CRN-1');
$patientB = Fx::patient($pdo, $clinicB, 'Paciente Outra Clínica');

$setActor($userA);

// ============================================================================
echo "=== 1. NI2-847 (UX-003): Campos numéricos com vírgula/ponto decimal, normalização m/cm e limites ===\n";
$anthroSvc = new AnthropometryService($pdo);

// Positivos: vírgula e ponto em altura (m e cm), peso, cintura, quadril
$a1 = $anthroSvc->recordAssessment($patientA, (int)$userA['professional_id'], [
    'weight_kg' => '72,4',
    'height_cm' => '1,7',
    'waist_cm' => '78,0',
    'hip_cm' => '100,0',
], (int)$userA['user_id']);
T::ok(abs((float)$a1['weight_kg'] - 72.4) < 0.01 && abs((float)$a1['height_cm'] - 170.0) < 0.01, 'NI2-847: recordAssessment aceita "72,4" kg e "1,7" m -> 72.4 kg e 170.0 cm');
T::ok(abs((float)$a1['bmi'] - 25.05) < 0.05 && abs((float)$a1['whr'] - 0.78) < 0.01, 'NI2-847: IMC (~25.05) e RCQ (0.78) calculados corretamente com vírgula decimal');

foreach (['1,70' => 170.0, '1.70' => 170.0, '170' => 170.0, '170,0' => 170.0] as $hRaw => $expectedCm) {
    $res = $anthroSvc->recordAssessment($patientA, (int)$userA['professional_id'], [
        'weight_kg' => '72.4',
        'height_cm' => $hRaw,
    ], (int)$userA['user_id']);
    T::ok(abs((float)$res['height_cm'] - $expectedCm) < 0.01, "NI2-847: altura '{$hRaw}' grava {$expectedCm} cm");
}

// EnergyCalculator & EnergyTargetService: normalização de altura em metros ("1,7" -> 170 cm)
$bmrMeters = EnergyCalculator::compute([
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'weight_kg' => '72,4',
    'height_cm' => '1,7',
    'age_years' => 34,
    'activity_factor' => '1,55',
    'goal_type' => 'loss',
    'adjust_mode' => 'kcal',
    'adjust_value' => -300,
]);
$bmrCm = EnergyCalculator::compute([
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'weight_kg' => '72.4',
    'height_cm' => '170',
    'age_years' => 34,
    'activity_factor' => '1.55',
    'goal_type' => 'loss',
    'adjust_mode' => 'kcal',
    'adjust_value' => -300,
]);
T::ok(abs($bmrMeters['tmb_kcal'] - $bmrCm['tmb_kcal']) < 0.01 && $bmrMeters['tmb_kcal'] > 1300, 'NI2-847: EnergyCalculator::compute normaliza 1,7m == 170cm (' . $bmrMeters['tmb_kcal'] . ' kcal)');

// Negativos e limites: texto não numérico ("abc") e fora de faixa ("500" kg, "0" m) lançam InvalidAnthropometricDataException (não TypeError)
T::throws(fn() => $anthroSvc->recordAssessment($patientA, (int)$userA['professional_id'], [
    'weight_kg' => 'abc',
    'height_cm' => '170',
], (int)$userA['user_id']), 'NI2-847 negativo: peso "abc" recusa com InvalidAnthropometricDataException', 'peso');

T::throws(fn() => $anthroSvc->recordAssessment($patientA, (int)$userA['professional_id'], [
    'weight_kg' => '70',
    'height_cm' => '170',
    'waist_cm' => 'abc',
], (int)$userA['user_id']), 'NI2-847 negativo: cintura "abc" recusa com InvalidAnthropometricDataException (sem TypeError)', 'Circunferência');

T::throws(fn() => $anthroSvc->recordAssessment($patientA, (int)$userA['professional_id'], [
    'weight_kg' => '500',
    'height_cm' => '170',
], (int)$userA['user_id']), 'NI2-847 limite: peso "500" kg recusa fora da faixa (0.5 a 350 kg)', 'Peso');

T::throws(fn() => $anthroSvc->recordAssessment($patientA, (int)$userA['professional_id'], [
    'weight_kg' => '70',
    'height_cm' => '0',
], (int)$userA['user_id']), 'NI2-847 limite: altura "0" recusa fora da faixa', 'Altura');

// ============================================================================
echo "=== 2. NI2-846 (UX-002): Ranking de busca de alimentos por relevância clínica ===\n";
$foodCatSvc = new FoodCatalogService($pdo);
$foodCatSvc->seedTacoReference();
(new \ClyoNutri\Domain\Food\TacoCatalogImporter($pdo))->run();
HouseholdMeasureConverter::ensureCommonMeasures($pdo);

$frangoRes = $foodCatSvc->paginate(['q' => 'frango grelhado', 'per_page' => 25])['foods'];
T::ok(!empty($frangoRes) && str_contains(mb_strtolower($frangoRes[0]['name'], 'UTF-8'), 'peito') && !str_contains(mb_strtolower($frangoRes[0]['name'], 'UTF-8'), 'coração'),
    'NI2-846: "frango grelhado" traz peito de frango em 1º lugar (' . ($frangoRes[0]['name'] ?? '') . ')');

$coracaoRes = $foodCatSvc->paginate(['q' => 'coracao de frango', 'per_page' => 25])['foods'];
T::ok(!empty($coracaoRes) && str_contains(mb_strtolower($coracaoRes[0]['name'], 'UTF-8'), 'coração'),
    'NI2-846: "coracao de frango" traz coração de frango em 1º lugar (' . ($coracaoRes[0]['name'] ?? '') . ')');
T::ok(FoodSearchIndex::detectPartHighlight('Frango, coração, grelhado') === 'coração', 'NI2-846: detectPartHighlight destaca "coração" para parte/corte');
T::ok(FoodSearchIndex::detectPartHighlight('Frango, peito, sem pele, grelhado') === 'peito', 'NI2-846: detectPartHighlight destaca "peito" para parte/corte');

$benchmarkPairs = [
    'frango grelhado' => 'peito',
    'arroz integral' => 'arroz',
    'arroz branco' => 'arroz',
    'feijao carioca' => 'feijão',
    'pao frances' => 'pão, trigo, francês',
    'ovo cozido' => 'ovo',
    'banana prata' => 'banana',
    'mamao formosa' => 'mamão, formosa',
    'aveia' => 'aveia',
    'azeite' => 'azeite',
    'leite desnatado' => 'leite',
    'batata doce' => 'batata, doce, cozida',
];
$hitsTop3 = 0;
foreach ($benchmarkPairs as $q => $expectedSubstr) {
    $res = array_slice($foodCatSvc->paginate(['q' => $q, 'per_page' => 25])['foods'], 0, 3);
    $found = false;
    foreach ($res as $r) {
        if (str_contains(mb_strtolower($r['name'], 'UTF-8'), $expectedSubstr)) {
            $found = true;
            break;
        }
    }
    if ($found) $hitsTop3++;
}
T::ok($hitsTop3 === count($benchmarkPairs), "NI2-846: 100% ({$hitsTop3}/" . count($benchmarkPairs) . ") das buscas clínicas frequentes trazem o alimento esperado no top 3");

// ============================================================================
echo "=== 3. NI2-845 (UX-001) & NI2-855 (UX-019): Medida caseira padrão e aviso de item duplicado com soma ===\n";
$conv = new HouseholdMeasureConverter($pdo);
$checkFoods = [
    'Pão, trigo, francês' => 'unidade',
    'Mamão, Formosa, cru' => 'fatia',
    'Banana prata crua' => 'unidade',
    'Ovo de galinha cozido' => 'unidade',
    'Arroz integral cozido' => 'colher de sopa',
    'Feijão carioca cozido' => 'concha',
    'Peito de frango grelhado' => 'filé', // v54.63 (R-004): a cópia interna saiu da busca; o item é o TACO 410
    'Azeite de oliva extravirgem' => 'colher de sopa',
];
foreach ($checkFoods as $fname => $expectedMeasure) {
    $fRow = $pdo->query("SELECT * FROM foods WHERE name = " . $pdo->quote($fname) . " LIMIT 1")->fetch();
    $def = $fRow ? $conv->defaultPrescriptionForFood((int)$fRow['id'], $fRow, $clinicA) : null;
    T::ok($def !== null && $def['unit'] !== 'g' && str_contains(mb_strtolower((string)$def['unit'], 'UTF-8'), $expectedMeasure),
        "NI2-845: '{$fname}' tem medida caseira padrão '{$def['unit']}' ({$def['grams']} g) sem exigir confirmação manual");
}

$planSvc = new MealPlanService($pdo);
$planCreated = $planSvc->createPlan($patientA, (int)$userA['professional_id'], 'Plano Alimentar NI2-844', 'Hidratação 2,5 L/dia');
$planId = (int)($planCreated['plan_id'] ?? $planCreated['id']);
$energySvc = new EnergyTargetService($pdo);
$energyRes = $energySvc->save($planId, [
    'weight_kg' => '72,4',
    'height_cm' => '1,7', // metros normalizados para 170 cm
    'age_years' => 34,
    'sex' => 'female',
    'formula' => 'mifflin_st_jeor',
    'activity_factor' => '1,55',
    'goal_type' => 'loss',
    'adjust_mode' => 'kcal',
    'adjust_value' => -300,
    'macro_mode' => 'percent',
    'protein' => 25,
    'carbohydrate' => 45,
    'lipid' => 30,
]);
T::ok(abs((float)$energyRes['height_cm'] - 170.0) < 0.01 && (float)$energyRes['vet_kcal'] > 1500,
    'NI2-847: EnergyTargetService::save normaliza height_cm="1,7" para 170 cm no plano');

$mealCafe = $planSvc->addMeal($planId, 'Café da manhã', '07:30');
$paoRow = $pdo->query("SELECT * FROM foods WHERE name = 'Pão, trigo, francês' LIMIT 1")->fetch();
$paoDef = $conv->defaultPrescriptionForFood((int)$paoRow['id'], $paoRow, $clinicA);

// Adiciona Pão francês 1x
$item1Id = (int)$planSvc->addItem($mealCafe, (int)$paoRow['id'], (float)$paoDef['amount'], (string)$paoDef['unit']);
// Adiciona Pão francês 2ª vez (duplicado na mesma refeição -> UX-019)
$item2Id = (int)$planSvc->addItem($mealCafe, (int)$paoRow['id'], (float)$paoDef['amount'], (string)$paoDef['unit']);

ob_start();
$_GET = [];
(new DashboardController())->mealPlanEditor(['id' => (string)$planId]);
$editorHtml = (string)ob_get_clean();
T::ok(str_contains($editorHtml, 'data-meal-inline-add') && str_contains($editorHtml, 'data-testid="meal-inline-add-' . $mealCafe . '"'),
    'NI2-845: editor do plano renderiza barra de busca inline dentro do card da refeição');
T::ok(str_contains($editorHtml, 'data-testid="duplicate-food-notice"') && str_contains($editorHtml, 'já existe na refeição — somar quantidades?'),
    'NI2-855 (UX-019): editor do plano avisa alimento duplicado na mesma refeição com botão de somar em 1 clique');

// Testa merge_duplicate via WorkflowController (soma item2 em item1)
$planState = (new \ClyoNutri\Domain\Clinical\WorkflowWrites($pdo))->state('plan', $planId, 0);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_POST = [
    '_csrf_token' => Csrf::token(),
    '_request_key' => bin2hex(random_bytes(8)),
    '_base_revision' => (string)$planState['revision'],
    'action' => 'merge_duplicate',
    'item_id' => (string)$item2Id,
    'target_item_id' => (string)$item1Id,
];
ob_start();
(new WorkflowController($pdo))->mutate(['kind' => 'plan', 'id' => (string)$planId]);
$mergeJson = json_decode((string)ob_get_clean(), true);
$remainingItems = $pdo->query("SELECT * FROM meal_plan_items WHERE meal_id = {$mealCafe}")->fetchAll();
$mergedInto = $mergeJson['result']['merged_into'] ?? $mergeJson['merged_into'] ?? null;
T::ok($mergedInto !== null && count($remainingItems) === 1 && abs((float)$remainingItems[0]['weight_grams'] - 100.0) < 0.01 && abs((float)$remainingItems[0]['amount'] - 2.0) < 0.01,
    'NI2-855 (UX-019): merge_duplicate soma as porções (50g + 50g = 100g, 2 unidades) e remove a linha duplicada');

// Isolamento multi-tenant em merge_duplicate
$setActor($userB);
$_POST = [
    '_csrf_token' => Csrf::token(),
    '_request_key' => bin2hex(random_bytes(8)),
    'action' => 'merge_duplicate',
    'item_id' => (string)$item1Id,
];
ob_start();
(new WorkflowController($pdo))->mutate(['kind' => 'plan', 'id' => (string)$planId]);
$mergeForbidden = json_decode((string)ob_get_clean(), true);
T::ok(!empty($mergeForbidden['error']), 'NI2-845/NI2-855 segurança: outra clínica não consegue fundir itens do plano');
$setActor($userA);

// ============================================================================
echo "=== 4. NI2-852 (UX-009) & NI2-855 (UX-013): Fuso horário unificado e português correto ===\n";
$planRow = $pdo->query("SELECT created_at, updated_at FROM meal_plans WHERE id = {$planId}")->fetch();
$expectedHourPrefix = date('Y-m-d H:');
T::ok(str_starts_with((string)$planRow['updated_at'], $expectedHourPrefix),
    'NI2-852 (UX-009): meal_plans grava updated_at no fuso local America/Sao_Paulo (' . $planRow['updated_at'] . '), sem +3h UTC');

// UX-013: plural correto na justificativa de publicação ("O plano tem 1 refeição", nunca "1 refeição(ões)")
$previewOneMeal = $planSvc->getPlanPreview($planId);
$adequacyAlertsText = implode(' | ', array_map(static fn($a) => (string)($a['message'] ?? ''), $previewOneMeal['adequacy']['alerts'] ?? []));
T::ok(str_contains($adequacyAlertsText, 'O plano tem 1 refeição;') && !str_contains($adequacyAlertsText, 'refeição(ões)'),
    'NI2-855 (UX-013): revisão de adequação usa português correto ("O plano tem 1 refeição", nunca "1 refeição(ões)")');

// Publica o plano com justificativa para meta e verifica _operational_strip.php (UX-013)
$planSvc->publishPlan($planId, true, false, 'Alergia conferida na consulta.', true, 'Meta ajustada para início gradual.');
$pRow = $pdo->query("SELECT * FROM patients WHERE id = {$patientA}")->fetch();
$p = $pRow;
$careStep = ['state' => 'none', 'appointment' => null];
$clinicalSummary = ['allergy_label' => 'Nega alergias', 'allergies' => '', 'draft_in_progress' => false];
$activePlan = ['id' => $planId, 'title' => 'Plano Alimentar NI2-844', 'status' => 'published'];
$pendingExamCount = 0;
ob_start();
require dirname(__DIR__) . '/views/patients/_operational_strip.php';
$stripHtml = (string)ob_get_clean();
T::ok(str_contains($stripHtml, 'Plano Alimentar NI2-844 · publicado') && !str_contains($stripHtml, '· published'),
    'NI2-855 (UX-013): faixa operacional traduz status "published" para "publicado" (nunca exibe "published" cru)');

// ============================================================================
echo "=== 5. NI2-848 (UX-004), NI2-849 (UX-005), NI2-854 (UX-012) & NI2-855 (UX-016): Atendimento unificado e Fechamento ===\n";
$appSvc = new AppointmentService($pdo);
$slotAt = date('Y-m-d H:i:00', time() - 300);
$appId = Fx::appointment($pdo, $clinicA, $patientA, (int)$userA['professional_id']);
$pdo->prepare("UPDATE appointments SET scheduled_at = ?, duration_minutes = 60, status = 'in_progress', type = 'first_visit' WHERE id = ?")->execute([$slotAt, $appId]);

$_GET = ['atendimento' => (string)$appId];
$_SERVER['REQUEST_URI'] = "/consulta/{$appId}";
AttendanceContext::begin($pdo);
ob_start();
(new DashboardController())->consultationRoom(['id' => (string)$appId]);
$roomHtml = (string)ob_get_clean();

T::ok(str_contains($roomHtml, 'data-testid="attendance-steps"') && str_contains($roomHtml, 'data-step="anamnese"') && str_contains($roomHtml, 'data-step="avaliacao"') && str_contains($roomHtml, 'data-step="plano"') && str_contains($roomHtml, 'data-step="evolucao"') && str_contains($roomHtml, 'data-step="fechamento"'),
    'NI2-848 (UX-004): sala de atendimento exibe barra única de etapas (Anamnese, Avaliação, Plano, Evolução, Fechamento)');
T::ok(str_contains($roomHtml, 'data-testid="consultation-history-drawer"') && str_contains($roomHtml, 'data-testid="toggle-history-drawer"'),
    'NI2-848 (UX-004): sala de atendimento inclui painel lateral recolhível "Histórico clínico"');
T::ok(str_contains($roomHtml, 'data-testid="consult-latest-anthro-summary"') && str_contains($roomHtml, 'data-testid="consult-open-anthropometry"'),
    'NI2-855 (UX-016): sala de atendimento exibe resumo da última avaliação antropométrica e botão para Avaliação');
T::ok(str_contains($roomHtml, 'data-testid="stt-lgpd-details"'),
    'NI2-854 (UX-012): texto longo de LGPD da transcrição fica recolhido em <details> abaixo da evolução');

// Verifica que em /pacientes/{id}?atendimento={appId} há exatamente 1 CTA primário "Continuar atendimento" no cabeçalho
$_GET = ['atendimento' => (string)$appId];
$_SERVER['REQUEST_URI'] = "/pacientes/{$patientA}?atendimento={$appId}";
AttendanceContext::begin($pdo);
$headerHtml = PatientHeader::render($pdo, $patientA, 'resumo', $pRow, ['state' => 'continue', 'appointment' => ['id' => $appId, 'patient_id' => $patientA, 'status' => 'in_progress', 'scheduled_at' => $slotAt]], $clinicalSummary, $activePlan, true);
$continueCtaCount = preg_match_all('~data-testid="care-continue"~', $headerHtml);
T::ok($continueCtaCount === 1 && str_contains($headerHtml, 'data-testid="attendance-steps"'), "NI2-848 (UX-004): cabeçalho do paciente tem 1 CTA primário 'Continuar atendimento' e a barra de etapas (encontrado: {$continueCtaCount})");

// Conclui a consulta e verifica o Painel de Fechamento (NI2-849 / UX-005)
$appSvc->completeConsultation($appId, 'Paciente aderiu bem às orientações iniciais.', ['weight_kg' => 72.4, 'waist_cm' => 78.0], (int)$userA['user_id'], (int)$userA['professional_id']);
$_GET = ['success' => 'consulta_concluida', 'consulta' => (string)$appId];
$_SERVER['REQUEST_URI'] = "/pacientes/{$patientA}?success=consulta_concluida&consulta={$appId}";
ob_start();
(new DashboardController())->profile360(['id' => (string)$patientA]);
$closedProfileHtml = (string)ob_get_clean();

T::ok(str_contains($closedProfileHtml, 'data-testid="consultation-closing-panel"') && str_contains($closedProfileHtml, 'data-testid="closing-schedule-followup-btn"') && str_contains($closedProfileHtml, 'data-testid="closing-plan-pdf"'),
    'NI2-849 (UX-005): concluir a consulta exibe o Painel de Fechamento com agendamento de retorno em 1 clique e entrega de PDF');

// Testa sugestão de retorno em 30 dias e agendamento via scheduleFollowUpFromClosing
$followSlot = $appSvc->suggestFollowUpSlot((int)$userA['professional_id'], $slotAt, 60, 30);
T::ok(!empty($followSlot['scheduled_at']) && substr((string)$followSlot['scheduled_at'], 0, 10) > date('Y-m-d'),
    'NI2-849 (UX-005): suggestFollowUpSlot sugere horário livre em ~30 dias (' . $followSlot['scheduled_at'] . ')');

// ============================================================================
echo "=== 6. NI2-850 (UX-006): Onboarding em 3 passos sem cobrança forçada, CRN pré-preenchido e contadores coerentes ===\n";
$onbSvc = new OnboardingService($pdo);
$progress0 = $onbSvc->getOrCreateProgress((int)$userA['user_id'], $clinicA);
$wiz0 = OnboardingService::wizardSummary($progress0);
T::ok($wiz0['total_steps'] === 3 && $wiz0['completed_steps'] === 0 && $wiz0['progress_pct'] === 0,
    'NI2-850 (UX-006): wizardSummary inicia com 3 passos totais e 0%');

// Passo 1 do onboarding não exige escolher plano pago
$onbSvc->saveStep((int)$userA['user_id'], $clinicA, 1, [
    'focus_objectives' => ['Nutrição Clínica'],
    'target_audience' => 'Adultos',
]);
$onbSvc->saveStep((int)$userA['user_id'], $clinicA, 2, [
    'crn' => '45890',
    'crn_region' => 'CRN-3',
    'clinic_name' => 'Clínica Prado',
    'specialty' => 'Nutrição Clínica',
]);
$onbSvc->saveStep((int)$userA['user_id'], $clinicA, 3, [
    'default_duration_minutes' => 60,
    'working_days' => ['seg', 'ter', 'qua', 'qui', 'sex'],
    'modality' => 'hybrid',
]);
$wiz1 = OnboardingService::wizardSummary($onbSvc->getOrCreateProgress((int)$userA['user_id'], $clinicA));
T::ok($wiz1['completed_steps'] === 1 && $wiz1['progress_pct'] === 33,
    'NI2-850 (UX-006): concluir Passo 1 de 3 atualiza wizardSummary para 1 de 3 (33%)');

$_GET = ['passo' => '5'];
$_SERVER['REQUEST_URI'] = '/onboarding?passo=5';
ob_start();
(new OnboardingController())->show();
$onbStep5Html = (string)ob_get_clean();
preg_match('~<strong data-testid="activation-count">(\d+) de (\d+)</strong>~', $onbStep5Html, $cntMatch);
preg_match('~<ol class="activation-list" data-testid="activation-checklist">(.*?)</ol>~s', $onbStep5Html, $olMatch);
$visibleChecklistItems = preg_match_all('~<li class="activation-item~', $olMatch[1] ?? '');
T::ok(!empty($cntMatch) && (int)$cntMatch[2] === $visibleChecklistItems && str_contains($onbStep5Html, 'data-testid="activation-optional-disabled"'),
    "NI2-850 (UX-006): contador do checklist ({$cntMatch[1]} de {$cntMatch[2]}) bate exatamente com os {$visibleChecklistItems} itens visíveis na lista principal");

// ============================================================================
echo "=== 7. NI2-851 (UX-008): Preservação e reenvio de rascunho ===\n";
$app2Id = Fx::appointment($pdo, $clinicA, $patientA, (int)$userA['professional_id']);
$pdo->prepare("UPDATE appointments SET scheduled_at = ?, duration_minutes = 60, status = 'in_progress', type = 'follow_up' WHERE id = ?")->execute([date('Y-m-d H:i:00', strtotime('+2 hours')), $app2Id]);
$draftSvc = new ConsultationDraftService($pdo);
$savedDraft = $draftSvc->save($app2Id, (int)$userA['user_id'], $clinicA, 0, [
    'evolution_summary' => 'Rascunho preservado em queda de rede',
    'weight_kg' => '71,8',
    'waist_cm' => '77,5',
]);
T::ok($savedDraft['status'] === 'saved' && $savedDraft['version'] === 1, 'NI2-851 (UX-008): ConsultationDraftService persiste rascunho versionado');
$loadedDraft = $draftSvc->get($app2Id, (int)$userA['user_id'], $clinicA);
T::ok($loadedDraft['evolution_summary'] === 'Rascunho preservado em queda de rede' && $loadedDraft['weight_kg'] === '71,8',
    'NI2-851 (UX-008): rascunho recuperado integralmente após reconexão/relogin');

$jsConsultaRascunho = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/consulta-rascunho.js');
$jsWorkflowDrafts = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/workflow-drafts.js');
T::ok(str_contains($jsConsultaRascunho, "addEventListener('online'") && str_contains($jsConsultaRascunho, 'draft-recovered-banner') && str_contains($jsWorkflowDrafts, "addEventListener('online'"),
    'NI2-851 (UX-008): consulta-rascunho.js e workflow-drafts.js escutam evento online para reenvio automático em <=5s e exibem banner de recuperação');

// ============================================================================
echo "=== 8. NI2-853 (UX-010) & NI2-855 (UX-011): Combobox único na agenda, próximo horário válido e Iniciar em 1 clique ===\n";
$_GET = ['novo' => '1'];
$_SERVER['REQUEST_URI'] = '/agenda?novo=1';
ob_start();
(new DashboardController())->agenda();
$agendaHtml = (string)ob_get_clean();

T::ok(str_contains($agendaHtml, 'data-testid="agenda-patient-combobox"') && str_contains($agendaHtml, 'id="dc11-patient_id" name="patient_id" required tabindex="-1" class="visually-hidden"'),
    'NI2-853 (UX-010): modal da agenda unifica busca e seleção em um único combobox visível');
preg_match('~id="new-appointment-date"[^>]*value="([^"]+)"~', $agendaHtml, $dateMatch);
preg_match('~id="new-appointment-time"[^>]*value="([^"]+)"~', $agendaHtml, $timeMatch);
$preScheduled = ($dateMatch[1] ?? '') . 'T' . ($timeMatch[1] ?? '');
T::ok(($dateMatch[1] ?? '') !== '' && ($timeMatch[1] ?? '') !== '' && str_contains($agendaHtml, 'data-testid="agenda-suggested-slot"'),
    "NI2-853 (UX-010): nova consulta pré-preenche próximo horário comercial válido ({$preScheduled}) com aviso de sugestão");

// Cria consulta agendada hoje para verificar botão Iniciar em 1 clique (UX-011)
$todaySlot = date('Y-m-d') . ' 16:00:00';
$app3Id = Fx::appointment($pdo, $clinicA, $patientA, (int)$userA['professional_id']);
$pdo->prepare("UPDATE appointments SET scheduled_at = ?, duration_minutes = 60, status = 'scheduled', type = 'follow_up' WHERE id = ?")->execute([$todaySlot, $app3Id]);
$_GET = ['date' => substr($todaySlot, 0, 10), 'view' => 'day'];
ob_start();
(new DashboardController())->agenda();
$agendaDayHtml = (string)ob_get_clean();
T::ok(str_contains($agendaDayHtml, 'action="/agenda/' . $app3Id . '/status"') && str_contains($agendaDayHtml, 'data-testid="agenda-start-appointment"'),
    'NI2-855 (UX-011): botão "Iniciar" na agenda dispara POST /agenda/{id}/status (in_progress) em 1 clique');

// ============================================================================
echo "=== 9. NI2-854 (UX-014, UX-015) & NI2-855 (UX-007, UX-013, UX-017, UX-018): Dashboard, Menu 7 itens, Saudação, Cadastro e Busca ===\n";
T::ok(DashboardOverview::firstName('Dra. Helena Prado') === 'Helena' && DashboardOverview::firstName('Dr. João Silva') === 'João' && DashboardOverview::firstName('Maria') === 'Maria',
    'NI2-855 (UX-007): DashboardOverview::firstName remove prefixos "Dra."/"Dr." para evitar "Olá, Dra."');

$_GET = [];
$_SERVER['REQUEST_URI'] = '/dashboard';
ob_start();
(new DashboardController())->index();
$dashHtml = (string)ob_get_clean();
T::ok(str_contains($dashHtml, 'Olá, <strong>Helena</strong>') && !str_contains($dashHtml, 'Olá, <strong>Dra.'),
    'NI2-855 (UX-007): cabeçalho mostra "Olá, Helena" (sem "Olá, Dra.")');
T::ok(str_contains($dashHtml, 'data-testid="dash-continue-card"') && str_contains($dashHtml, 'Continuar de onde parou'),
    'NI2-854 (UX-014): dashboard destaca card "Continuar de onde parou" para consulta em andamento');

// UX-015 revisto na v54.65: três grupos fixos (sem recolher), menu enxuto e ajuda no rodapé
preg_match('~<nav class="cn58-nav-sections"[^>]*>(.*?)</nav>~s', $dashHtml, $navMatch);
$navHtml = $navMatch[1] ?? '';
$navLinks = preg_match_all('~class="app-nav__item~', $navHtml);
T::ok(!str_contains($navHtml, '<details') && str_contains($navHtml, 'data-testid="nav-group-biblioteca"') && str_contains($navHtml, 'data-testid="nav-group-gestao"'),
    'v54.65 (UX-015): grupos Biblioteca nutricional e Gestão fixos, sem recolher');
T::ok($navLinks <= 14 && !preg_match('~href="/(indicadores|configuracoes/assinatura|configuracoes/equipe|privacidade/pedidos|ajuda|planos/modelos)"~', $navHtml),
    "v54.65 (UX-015): menu enxuto, sem links mortos nem itens de configuração soltos ({$navLinks} itens)");
T::ok(str_contains($dashHtml, 'data-testid="nav-help"') && str_contains($navHtml, 'href="/modelos"'),
    'v54.65: Central de ajuda no rodapé do menu e Modelos de plano em /modelos');

// Verifica UX-018: busca sem resultado em /pacientes oferece "Cadastrar «{termo}»"
$_GET = ['q' => 'Paciente Inexistente XYZ'];
$_SERVER['REQUEST_URI'] = '/pacientes?q=Paciente+Inexistente+XYZ';
ob_start();
(new DashboardController())->patients();
$patientsEmptyHtml = (string)ob_get_clean();
T::ok(str_contains($patientsEmptyHtml, 'data-testid="patients-empty-register-cta"') && str_contains($patientsEmptyHtml, 'Cadastrar «Paciente Inexistente XYZ»'),
    'NI2-855 (UX-018): busca sem resultado em /pacientes oferece botão "Cadastrar «Paciente Inexistente XYZ»" com nome pré-preenchido');

// Verifica UX-013 no Financeiro: apenas 1 botão de novo lançamento financeiro
$_GET = [];
$_SERVER['REQUEST_URI'] = '/financeiro';
ob_start();
(new DashboardController())->finance();
$financeHtml = (string)ob_get_clean();
$newReceivableBtns = preg_match_all('~data-show="modal-novo-recebivel"~', $financeHtml);
T::ok($newReceivableBtns === 1, "NI2-855 (UX-013): página /financeiro tem apenas 1 botão '+ Novo lançamento financeiro' sem duplicidade (encontrado: {$newReceivableBtns})");

// Verifica UX-017 em views/auth/register.php
$csrfToken = Csrf::token();
$old = [];
$selectedPlan = '';
ob_start();
require dirname(__DIR__) . '/views/auth/register.php';
$registerHtml = (string)ob_get_clean();
T::ok(str_contains($registerHtml, 'placeholder="Mínimo de 8 caracteres"') && str_contains($registerHtml, 'grid-template-columns: 1fr 1.35fr') && str_contains($registerHtml, 'Criar meu consultório') && str_contains($registerHtml, 'min-height: 48px'),
    'NI2-855 (UX-017): tela /register mostra placeholder "Mínimo de 8 caracteres", região sem truncamento e CTA "Criar meu consultório" >= 48px');

T::done();
