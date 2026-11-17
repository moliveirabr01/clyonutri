<?php

declare(strict_types=1);

/**
 * CLYONUTRI II — SUÍTE DE TESTES DO ÉPICO NI2-7
 * [P0] Antropometria, Avaliação e Evolução Clínica
 *
 * Histórias Validadas:
 * - NI2-65: Registrar avaliação antropométrica com validações de plausibilidade
 * - NI2-66: Calcular IMC, RCQ e derivados conforme padrões da OMS
 * - NI2-67: Exibir evolução temporal com deltas e alternativa textual acessível
 * - NI2-68: Comparar avaliações com deltas, percentuais e tratamento de missing data
 * - NI2-69: Compartilhamento seletivo com o paciente, auditoria e revogação
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Anthropometry\AnthropometryCalculator;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;

echo "====================================================================\n";
echo "   CLYONUTRI II — SUÍTE DE VERIFICAÇÃO INTEGRAL DO ÉPICO NI2-7\n";
echo "   Antropometria, Indicadores OMS e Evolução Clínica\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertCondition(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

$db = Connection::getInstance();

// -----------------------------------------------------------------------------
// SETUP DE TENANTS, PROFISSIONAIS E PACIENTES
// -----------------------------------------------------------------------------
$clinicAId = 1;
$clinicBId = 3;

TenantContext::setTenant($clinicAId, [
    'id' => 1,
    'name' => 'Dra. Camila Mendes',
    'role' => 'professional',
    'clinic_id' => $clinicAId,
]);

// Garante profissional A
$profStmt = $db->prepare("SELECT id FROM professionals WHERE user_id = 1 LIMIT 1");
$profStmt->execute();
$profIdA = (int)$profStmt->fetchColumn();
if (!$profIdA) {
    $db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (1, '12345', 'CRN-3')")->execute();
    $profIdA = (int)$db->lastInsertId();
}

// Cria Pacientes para os testes
$patientRepo = new \ClyoNutri\Domain\Patient\PatientRepository($db);
$patientA = $patientRepo->create([
    'name' => 'Patrícia Rocha Lima (Teste Antropo)',
    'email' => 'patricia.rocha.' . time() . '_' . mt_rand(100, 999) . '@exemplo.com.br',
    'phone' => '(11) 9' . mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999),
    'birth_date' => '1992-05-15',
    'gender' => 'Feminino',
    'lgpd_consent' => 1,
]);
$patientAId = (int)$patientA['id'];

// Paciente de outro tenant para testes cross-tenant
TenantContext::setTenant($clinicBId, [
    'id' => 2,
    'name' => 'Dr. Tenant B',
    'role' => 'professional',
    'clinic_id' => $clinicBId,
]);
$patientB = $patientRepo->create([
    'name' => 'Rodrigo Cross Tenant',
    'email' => 'rodrigo.tenantb.' . time() . '_' . mt_rand(100, 999) . '@exemplo.com.br',
    'phone' => '(21) 9' . mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999),
    'birth_date' => '1988-11-20',
    'gender' => 'Masculino',
    'lgpd_consent' => 1,
]);
$patientBId = (int)$patientB['id'];

// Restabelece Tenant A
TenantContext::setTenant($clinicAId, [
    'id' => 1,
    'name' => 'Dra. Camila Mendes',
    'role' => 'professional',
    'clinic_id' => $clinicAId,
]);

$service = new AnthropometryService($db);

// =============================================================================
// [1] NI2-65: REGISTRAR AVALIAÇÃO ANTROPOMÉTRICA & VALIDAÇÕES DE PLAUSIBILIDADE
// =============================================================================
echo "[1] Testando NI2-65 — Registrar Avaliação e Validações Biológicas...\n";

// 1.1 Registro completo de baseline com dados válidos
$eval1 = $service->recordAssessment($patientAId, $profIdA, [
    'weight_kg' => 78.5,
    'height_cm' => 165.0,
    'waist_cm' => 84.0,
    'hip_cm' => 102.0,
    'neck_cm' => 34.0,
    'chest_cm' => 95.0,
    'arm_cm' => 31.0,
    'thigh_cm' => 58.0,
    'calf_cm' => 38.0,
    'body_fat_pct' => 29.5,
    'muscle_mass_pct' => 32.0,
    'notes' => 'Avaliação inicial pré-plano de emagrecimento.',
    'is_shared' => false,
    'assessed_at' => '2026-08-01 10:00:00',
], 1);

assertCondition("Avaliação #1 criada com ID persistido", isset($eval1['id']) && $eval1['id'] > 0);
assertCondition("Peso e altura persistidos exatamente", (float)$eval1['weight_kg'] === 78.5 && (float)$eval1['height_cm'] === 165.0);
assertCondition("Avaliação criada inicialmente como privada (is_shared = false)", $eval1['is_shared'] === false);

// 1.2 Verificação física no banco de dados relacional
$stmtCheck = $db->prepare("SELECT * FROM anthropometric_assessments WHERE id = :id");
$stmtCheck->execute(['id' => $eval1['id']]);
$dbRow = $stmtCheck->fetch();
assertCondition("Registro persistido fisicamente na tabela relacional", $dbRow !== false);
assertCondition("Circunferências complementares persistidas (pescoço, braço)", (float)$dbRow['neck_cm'] === 34.0 && (float)$dbRow['arm_cm'] === 31.0);

// 1.3 Bloqueio de peso implausível (< 10 kg ou > 400 kg)
$plausibilityBlocked = false;
try {
    $service->recordAssessment($patientAId, $profIdA, [
        'weight_kg' => 550.0, // Implausível
        'height_cm' => 165.0,
    ]);
} catch (InvalidAnthropometricDataException $e) {
    $plausibilityBlocked = true;
}
assertCondition("Peso implausível (> 400kg) bloqueado com InvalidAnthropometricDataException", $plausibilityBlocked);

// 1.4 Bloqueio de altura implausível (< 45 cm ou > 250 cm)
$heightBlocked = false;
try {
    $service->recordAssessment($patientAId, $profIdA, [
        'weight_kg' => 70.0,
        'height_cm' => 25.0, // Implausível
    ]);
} catch (InvalidAnthropometricDataException $e) {
    $heightBlocked = true;
}
assertCondition("Altura implausível (< 45cm) bloqueada com InvalidAnthropometricDataException", $heightBlocked);

// 1.5 Bloqueio de percentual de gordura fora da escala biológica (< 1% ou > 75%)
$fatBlocked = false;
try {
    $service->recordAssessment($patientAId, $profIdA, [
        'weight_kg' => 70.0,
        'height_cm' => 165.0,
        'body_fat_pct' => 95.0, // Implausível
    ]);
} catch (InvalidAnthropometricDataException $e) {
    $fatBlocked = true;
}
assertCondition("Percentual de gordura implausível (> 75%) bloqueado", $fatBlocked);

// 1.6 Tenant Guard: Tentativa de registrar avaliação em paciente de outro tenant
$crossTenantBlocked = false;
try {
    $service->recordAssessment($patientBId, $profIdA, [
        'weight_kg' => 80.0,
        'height_cm' => 180.0,
    ]);
} catch (TenantSecurityException $e) {
    $crossTenantBlocked = true;
}
assertCondition("Tentativa cross-tenant de registrar avaliação em outro tenant bloqueada (403)", $crossTenantBlocked);

// =============================================================================
// [2] NI2-66: CÁLCULO DE IMC, RCQ, RCE E DERIVADOS SEGUNDO A OMS
// =============================================================================
echo "\n[2] Testando NI2-66 — Fórmulas e Indicadores OMS (IMC, RCQ, RCE)...\n";

// 2.1 IMC canônico: 78.5 kg / (1.65 m)^2 = 78.5 / 2.7225 = 28.8337... -> 28.83
$calcBmi = AnthropometryCalculator::calculateBmi(78.5, 165.0);
assertCondition("IMC canônico calculado exatamente (28.83 kg/m²)", $calcBmi === 28.83);
assertCondition("Classificação OMS do IMC 28.83 é 'Sobrepeso'", AnthropometryCalculator::classifyBmi($calcBmi) === 'Sobrepeso');

// 2.2 Faixas completas de IMC segundo a OMS
assertCondition("IMC 17.5 é Abaixo do peso", AnthropometryCalculator::classifyBmi(17.5) === 'Abaixo do peso');
assertCondition("IMC 22.0 é Eutrofia", AnthropometryCalculator::classifyBmi(22.0) === 'Eutrofia (Peso adequado)');
assertCondition("IMC 27.5 é Sobrepeso", AnthropometryCalculator::classifyBmi(27.5) === 'Sobrepeso');
assertCondition("IMC 32.0 é Obesidade Grau I", AnthropometryCalculator::classifyBmi(32.0) === 'Obesidade Grau I');
assertCondition("IMC 37.0 é Obesidade Grau II", AnthropometryCalculator::classifyBmi(37.0) === 'Obesidade Grau II');
assertCondition("IMC 42.0 é Obesidade Grau III", AnthropometryCalculator::classifyBmi(42.0) === 'Obesidade Grau III');

// 2.3 Relação Cintura-Quadril (RCQ): 84.0 cm / 102.0 cm = 0.8235... -> 0.82
$calcWhr = AnthropometryCalculator::calculateWhr(84.0, 102.0);
assertCondition("RCQ calculado exatamente (0.82)", $calcWhr === 0.82);

// Mulher com RCQ 0.82 -> Risco moderado (0.80 a 0.84)
$whrClassFemale = AnthropometryCalculator::classifyWhr(0.82, 'Feminino');
assertCondition("Mulher com RCQ 0.82 classificada como 'Risco cardiovascular moderado'", $whrClassFemale['risk'] === 'moderado');

// Homem com RCQ 0.88 -> Baixo risco (< 0.90)
$whrClassMale = AnthropometryCalculator::classifyWhr(0.88, 'Masculino');
assertCondition("Homem com RCQ 0.88 classificado como 'Baixo risco cardiovascular'", $whrClassMale['risk'] === 'baixo');

// 2.4 Relação Cintura-Estatura (RCE): 84.0 cm / 165.0 cm = 0.509... -> 0.51
$calcWhtr = AnthropometryCalculator::calculateWhtr(84.0, 165.0);
assertCondition("RCE calculada exatamente (0.51)", $calcWhtr === 0.51);
assertCondition("RCE >= 0.50 classificada como 'Risco cardiometabólico aumentado'", AnthropometryCalculator::classifyWhtr($calcWhtr) === 'Risco cardiometabólico aumentado');

// 2.5 Faixa Saudável de Peso (Eutrofia OMS: IMC 18.5 a 24.9 para 1.65m):
// Min: 18.5 * (1.65^2) = 50.37 kg | Max: 24.9 * (1.65^2) = 67.79 kg
$idealWeight = AnthropometryCalculator::calculateIdealWeightRange(165.0);
assertCondition("Faixa saudável de peso calculada corretamente (50.37 a 67.79 kg)", $idealWeight['min_kg'] === 50.37 && $idealWeight['max_kg'] === 67.79);

// 2.6 Composição Corporal Derivada: 78.5 kg com 29.5% de gordura -> 23.16 kg massa gorda, 55.34 kg massa magra
$compDeriv = AnthropometryCalculator::calculateBodyComposition(78.5, 29.5, 32.0);
assertCondition("Massa gorda derivada exatamente (23.16 kg)", $compDeriv['fat_mass_kg'] === 23.16);
assertCondition("Massa magra derivada exatamente (55.34 kg)", $compDeriv['fat_free_mass_kg'] === 55.34);

// 2.7 Princípio de Missing Data: Ausência de dado NÃO gera valor falso inventado
$whrMissing = AnthropometryCalculator::calculateWhr(80.0, null);
assertCondition("Ausência de quadril resulta estritamente em RCQ null (sem 0 ou números fixos)", $whrMissing === null);

// =============================================================================
// [3] NI2-67: EXIBIR EVOLUÇÃO TEMPORAL COM DELTAS E RESUMO ACESSÍVEL
// =============================================================================
echo "\n[3] Testando NI2-67 — Exibir Evolução Temporal e Alternativa Textual...\n";

// Registra avaliação intermediária (Mês 2)
$eval2 = $service->recordAssessment($patientAId, $profIdA, [
    'weight_kg' => 75.2,
    'height_cm' => 165.0,
    'waist_cm' => 80.0,
    'hip_cm' => 99.0,
    'body_fat_pct' => 26.8,
    'muscle_mass_pct' => 33.2,
    'is_shared' => true,
    'assessed_at' => '2026-09-01 10:00:00',
], 1);

// Registra avaliação de follow-up (Mês 3)
$eval3 = $service->recordAssessment($patientAId, $profIdA, [
    'weight_kg' => 72.0,
    'height_cm' => 165.0,
    'waist_cm' => 76.0,
    'hip_cm' => 96.0,
    'body_fat_pct' => 24.0,
    'muscle_mass_pct' => 34.5,
    'is_shared' => true,
    'assessed_at' => '2026-10-01 10:00:00',
], 1);

$evolution = $service->getEvolution($patientAId, 'weight_kg');
assertCondition("getEvolution retornou 3 avaliações cronológicas", $evolution['total_assessments'] === 3);
assertCondition("Ordenação cronológica correta (agosto -> setembro -> outubro)", 
    $evolution['series'][0]['date'] === '2026-08-01' &&
    $evolution['series'][1]['date'] === '2026-09-01' &&
    $evolution['series'][2]['date'] === '2026-10-01'
);

// Deltas ponto a ponto
assertCondition("Baseline não possui delta anterior (null)", $evolution['series'][0]['delta_previous'] === null);
assertCondition("Delta de peso entre avaliação 1 e 2 é -3.3 kg", $evolution['series'][1]['delta_previous'] === -3.3);
assertCondition("Delta de peso entre avaliação 2 e 3 é -3.2 kg", $evolution['series'][2]['delta_previous'] === -3.2);

// Alternativa textual acessível para leitores de tela
assertCondition("Alternativa textual acessível gerada com sucesso", !empty($evolution['accessible_summary']));
assertCondition("Resumo textual descreve redução total de 6,5 kg (vírgula decimal pt-BR)", str_contains($evolution['accessible_summary'], '6,5') && str_contains($evolution['accessible_summary'], 'redução'));

// =============================================================================
// [4] NI2-68: COMPARAR AVALIAÇÕES (BASELINE VS. FOLLOW-UP E MISSING DATA)
// =============================================================================
echo "\n[4] Testando NI2-68 — Comparar Avaliações com Deltas e Missing Data...\n";

$compResult = $service->compareAssessments($patientAId, $eval1['id'], $eval3['id']);

assertCondition("Comparador calculou período de 61 dias decorridos", $compResult['days_between'] === 61);
$weightMetrics = $compResult['metrics']['weight_kg'];
assertCondition("Valor inicial de peso é 78.5 kg e final é 72 kg", $weightMetrics['initial_value'] === 78.5 && $weightMetrics['final_value'] === 72.0);
assertCondition("Delta absoluto de peso é -6.5 kg", $weightMetrics['delta_abs'] === -6.5);
assertCondition("Delta percentual de peso é -8.28%", $weightMetrics['delta_pct'] === -8.28);
assertCondition("Tendência do peso classificada como 'decrease'", $weightMetrics['trend'] === 'decrease');

// Testando tratamento estrito de missing data
// Registra avaliação 4 sem medição de pescoço
$eval4 = $service->recordAssessment($patientAId, $profIdA, [
    'weight_kg' => 71.0,
    'height_cm' => 165.0,
    'neck_cm' => null, // Dado ausente
    'assessed_at' => '2026-10-05 10:00:00',
], 1);

$compMissing = $service->compareAssessments($patientAId, $eval1['id'], $eval4['id']);
$neckMetric = $compMissing['metrics']['neck_cm'];
assertCondition("Métrica ausente no follow-up identificada como 'missing_in_followup'", $neckMetric['status'] === 'missing_in_followup');
assertCondition("Delta da métrica ausente é estritamente null sem quebrar cálculo", $neckMetric['delta_abs'] === null && $neckMetric['delta_pct'] === null);

// =============================================================================
// [5] NI2-69: COMPARTILHAMENTO SELETIVO, AUDITORIA E PORTAL DO PACIENTE
// =============================================================================
echo "\n[5] Testando NI2-69 — Compartilhamento Explícito, Revogação e Auditoria...\n";

// 5.1 No Portal do Paciente, apenas avaliações com is_shared = 1 devem aparecer
$portalService = new PatientPortalService($db);
$portalTimeline = $portalService->getEvolutionTimeline($patientAId);

$visibleClinicalIds = array_column($portalTimeline['clinical_records'], 'id');
assertCondition("Avaliação 1 (não compartilhada) NÃO aparece no portal do paciente", !in_array($eval1['id'], $visibleClinicalIds));
assertCondition("Avaliação 2 (compartilhada) aparece no portal do paciente", in_array($eval2['id'], $visibleClinicalIds));
assertCondition("Avaliação 3 (compartilhada) aparece no portal do paciente", in_array($eval3['id'], $visibleClinicalIds));

// 5.2 Nutricionista libera compartilhamento da Avaliação 1
$okShare = $service->shareAssessment($eval1['id'], true, 1, 'Liberado após alinhamento de metas na consulta');
assertCondition("Avaliação 1 compartilhada com sucesso", $okShare);

// Auditoria do compartilhamento
$auditHistory = $service->getShareHistory($eval1['id']);
assertCondition("Trilha de auditoria registrou ação 'shared'", count($auditHistory) > 0 && $auditHistory[count($auditHistory) - 1]['action'] === 'shared');
assertCondition("Justificativa registrada na auditoria", str_contains($auditHistory[count($auditHistory) - 1]['reason'], 'alinhamento de metas'));

// Paciente agora vê a Avaliação 1
$portalTimelineAfter = $portalService->getEvolutionTimeline($patientAId);
$visibleClinicalIdsAfter = array_column($portalTimelineAfter['clinical_records'], 'id');
assertCondition("Avaliação 1 agora está imediatamente visível no portal do paciente", in_array($eval1['id'], $visibleClinicalIdsAfter));

// 5.3 Nutricionista revoga o compartilhamento da Avaliação 1
$okRevoke = $service->shareAssessment($eval1['id'], false, 1, 'Revogado para revisão de dados');
assertCondition("Revogação executada com sucesso", $okRevoke);

// Paciente não vê mais a Avaliação 1
$portalTimelineRevoked = $portalService->getEvolutionTimeline($patientAId);
$visibleClinicalIdsRevoked = array_column($portalTimelineRevoked['clinical_records'], 'id');
assertCondition("Avaliação 1 deixa de ser visível no portal imediatamente após revogação", !in_array($eval1['id'], $visibleClinicalIdsRevoked));

$auditHistoryAfterRevoke = $service->getShareHistory($eval1['id']);
assertCondition("Auditoria registrou evento de revogação ('revoked')", $auditHistoryAfterRevoke[count($auditHistoryAfterRevoke) - 1]['action'] === 'revoked');

// 5.4 API Móvel do Paciente (/api/v1/patient/evolution) respeita liberação
$sharedOnly = $service->getSharedAssessmentsForPatient($patientAId);
assertCondition("getSharedAssessmentsForPatient retorna estritamente avaliações autorizadas", count($sharedOnly) === 2);

// =============================================================================
// [6] TESTES DE INTEGRAÇÃO HTTP & INTERFACE WEB (/pacientes/{id}/antropometria)
// =============================================================================
echo "\n[6] Testando Rotas HTTP Web (/pacientes/{id}, /antropometria)...\n";

$_SESSION['clinic_id'] = $clinicAId;
$_SESSION['user_id'] = 1;
$_SESSION['clinic_name'] = 'Clínica ClyoNutri Demo';
$_SESSION['user_name'] = 'Dra. Camila Mendes';

// 6.1 Renderização de GET /pacientes/{id} (Perfil 360 com card antropométrico)
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = "/pacientes/{$patientAId}";
ob_start();
require $rootDir . '/public/index.php';
$profileHtml = (string)ob_get_clean();

assertCondition("GET /pacientes/{id} retornou HTML válido", strlen($profileHtml) > 1000);
assertCondition("Página contém card de Avaliações Antropométricas", str_contains($profileHtml, 'Avaliações Antropométricas'));
assertCondition("Página contém link para Análise Completa", str_contains($profileHtml, "/pacientes/{$patientAId}/antropometria"));
assertCondition("Página exibe status de liberação ao paciente", str_contains($profileHtml, 'Liberado ao Paciente') || str_contains($profileHtml, 'Privado (Clínico)'));

// 6.2 Renderização de GET /pacientes/{id}/antropometria
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = "/pacientes/{$patientAId}/antropometria";
ob_start();
require $rootDir . '/public/index.php';
$anthroHtml = (string)ob_get_clean();

assertCondition("GET /pacientes/{id}/antropometria retornou HTML completo de evolução", strlen($anthroHtml) > 2000);
assertCondition("Tela exibe KPIs de peso atual e IMC", str_contains($anthroHtml, 'ÚLTIMO PESO REGISTRADO') && str_contains($anthroHtml, 'IMC ATUAL (OMS)'));
assertCondition("Tela exibe resumo de acessibilidade", str_contains($anthroHtml, 'Resumo Clínico da Evolução (Acessibilidade & Auditoria)'));
assertCondition("Tela exibe tabela com comparativo entre avaliações", str_contains($anthroHtml, 'Comparativo Clínico Entre Avaliações'));
assertCondition("Tela contém formulário de nova avaliação com CSRF", str_contains($anthroHtml, 'action="/pacientes/' . $patientAId . '/antropometria"') && str_contains($anthroHtml, '_csrf_token'));

// =============================================================================
// RESUMO FINAL DA SUÍTE DE TESTES DO ÉPICO NI2-7
// =============================================================================
echo "\n====================================================================\n";
echo "   RESULTADO DA SUÍTE DE TESTES NI2-7: {$passed} PASS, {$failed} FAIL\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
