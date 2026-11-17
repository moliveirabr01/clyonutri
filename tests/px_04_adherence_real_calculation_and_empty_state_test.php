<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: ADESÃO REAL E ESTADO DE VAZIO (PX-04 / NI2-297)
 * ==============================================================================
 * Cobertura de Critérios de Aceite para NI2-297 (PX-04):
 * 1. Zero check-ins ➔ Exibe "Sem dados" (elimina o fallback fictício de 94.0%).
 * 2. Check-ins com meal_adherence_pct = NULL não contam como dados válidos.
 * 3. Valores de adesão derivam estritamente de dados reais elegíveis na janela de 30 dias.
 * 4. Metadados de contexto clínico visíveis: período (30 dias), respostas e população elegível.
 * 5. Check-ins fora da janela temporal (> 30 dias) são excluídos do cálculo.
 * 6. Isolamento multi-tenant estrito: tenant virgem não acessa nem agrega métricas de outro tenant.
 * 7. Validação defensiva com rejeição de clinic_id inválido via InvalidArgumentException.
 * 8. Renderização completa do Dashboard HTML validada no Design System ClyoNutri.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Engagement\EngagementService;
use ClyoNutri\Application\Controllers\DashboardController;

$db = Connection::getInstance();

function logPx04Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx04(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO PX-04 (NI2-297): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-04: ADESÃO REAL NO DASHBOARD (NI2-297) <<<\n";
echo "    Eliminação de 94.0% Sintético, Estado de Vazio 'Sem dados' & Métricas Reais\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$nutriIdAlfa = 0;
$nutriIdBeta = 0;
$patientIdAlfa1 = 0;
$patientIdAlfa2 = 0;
$patientIdBeta1 = 0;

try {
    // -------------------------------------------------------------------------
    // SETUP: Provisionando Tenants Reais (Alfa & Beta)
    // -------------------------------------------------------------------------
    echo "--> [1/7] Provisionando Tenants Reais Alfa (#Alfa) e Beta (#Beta)...\n";

    $u1 = 'px04-alfa-' . bin2hex(random_bytes(4));
    $u2 = 'px04-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u1}', 'Clínica Alfa Adesão Real', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u2}', 'Clínica Beta Virgem', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    // Nutricionistas
    $nutriUuidAlfa = 'user-px04-alfa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Camila Mendes Alfa', 'nutri-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, 'CRN3-12345', 'CRN-3')");

    $nutriUuidBeta = 'user-px04-beta-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Roberto Beta', 'nutri-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdBeta}, 'CRN3-67890', 'CRN-3')");

    // Pacientes do Tenant Alfa
    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender) 
               VALUES ('p-alfa-1-" . bin2hex(random_bytes(3)) . "', {$clinicIdAlfa}, 'Paciente Alfa 1', 'alfa1@teste.com', '1990-01-01', 'female')");
    $patientIdAlfa1 = (int)$db->lastInsertId();

    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender) 
               VALUES ('p-alfa-2-" . bin2hex(random_bytes(3)) . "', {$clinicIdAlfa}, 'Paciente Alfa 2', 'alfa2@teste.com', '1985-05-12', 'male')");
    $patientIdAlfa2 = (int)$db->lastInsertId();

    // Paciente do Tenant Beta
    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender) 
               VALUES ('p-beta-1-" . bin2hex(random_bytes(3)) . "', {$clinicIdBeta}, 'Paciente Beta 1', 'beta1@teste.com', '1992-07-20', 'female')");
    $patientIdBeta1 = (int)$db->lastInsertId();

    logPx04Pass("SETUP", "Tenants e pacientes provisionados com sucesso");

    $engagementService = new EngagementService($db);

    // -------------------------------------------------------------------------
    // GATE 1: Tenant Virgem com 0 Check-ins (Sem Dados)
    // -------------------------------------------------------------------------
    echo "\n--> [2/7] Testando Estado de Vazio em Tenant com Zero Check-ins...\n";

    $emptyMetrics = $engagementService->getClinicAdherenceMetrics($clinicIdBeta, 30);

    assertPx04($emptyMetrics['has_data'] === false, "has_data deve ser FALSE para tenant sem check-ins");
    assertPx04($emptyMetrics['average_pct'] === null, "average_pct deve ser NULL");
    assertPx04($emptyMetrics['formatted_value'] === 'Sem dados', "formatted_value deve ser exatamente 'Sem dados' (e NUNCA '94.0%')");
    assertPx04($emptyMetrics['responses_count'] === 0, "responses_count deve ser 0");
    assertPx04($emptyMetrics['total_eligible_patients'] === 1, "total_eligible_patients deve ser 1 para Tenant Beta");

    logPx04Pass("GATE_1", "Estado de vazio retorna 'Sem dados' sem qualquer valor fictício", "Formatado: Sem dados");

    // -------------------------------------------------------------------------
    // GATE 2: Check-ins com meal_adherence_pct = NULL
    // -------------------------------------------------------------------------
    echo "\n--> [3/7] Testando Check-ins com meal_adherence_pct NULL (apenas energia/fome)...\n";

    // Inserir check-in sem meal_adherence_pct
    TenantContext::setClinicId($clinicIdBeta);
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'clinic_id' => $clinicIdBeta]);

    $engagementService->recordCheckin($patientIdBeta1, [
        'energy_level' => 4,
        'hunger_level' => 3,
        'meal_adherence_pct' => null,
        'notes' => 'Check-in qualitativo sem percentual preenchido',
        'checkin_date' => date('Y-m-d'),
    ]);

    $nullMetrics = $engagementService->getClinicAdherenceMetrics($clinicIdBeta, 30);
    assertPx04($nullMetrics['has_data'] === false, "Check-in com meal_adherence_pct NULL não deve ativar has_data");
    assertPx04($nullMetrics['formatted_value'] === 'Sem dados', "formatted_value deve continuar 'Sem dados'");
    assertPx04($nullMetrics['responses_count'] === 0, "responses_count deve ignorar registros NULL de adesão");

    logPx04Pass("GATE_2", "Check-ins com aderência NULL não poluem a métrica e mantêm 'Sem dados'");

    // -------------------------------------------------------------------------
    // GATE 3: Check-ins Reais Elegíveis na Janela de 30 Dias
    // -------------------------------------------------------------------------
    echo "\n--> [4/7] Testando Cálculo com Check-ins Reais (80% e 90% ➔ Média 85.0%)...\n";

    TenantContext::setClinicId($clinicIdAlfa);
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'clinic_id' => $clinicIdAlfa]);

    $engagementService->recordCheckin($patientIdAlfa1, [
        'meal_adherence_pct' => 80,
        'checkin_date' => date('Y-m-d', strtotime('-5 days')),
    ]);

    $engagementService->recordCheckin($patientIdAlfa2, [
        'meal_adherence_pct' => 90,
        'checkin_date' => date('Y-m-d', strtotime('-2 days')),
    ]);

    $realMetrics = $engagementService->getClinicAdherenceMetrics($clinicIdAlfa, 30);

    assertPx04($realMetrics['has_data'] === true, "has_data deve ser TRUE para check-ins válidos");
    assertPx04($realMetrics['average_pct'] === 85.0, "average_pct deve ser 85.0 (encontrado: " . var_export($realMetrics['average_pct'], true) . ")");
    assertPx04($realMetrics['formatted_value'] === '85,0%', "formatted_value deve ser '85,0%'");
    assertPx04($realMetrics['responses_count'] === 2, "responses_count deve ser 2");
    assertPx04($realMetrics['responding_patients_count'] === 2, "responding_patients_count deve ser 2");
    assertPx04($realMetrics['total_eligible_patients'] === 2, "total_eligible_patients deve ser 2");

    logPx04Pass("GATE_3", "Média calculada com fidelidade a partir de dados reais elegíveis", "Média: 85.0% (2 respostas)");

    // -------------------------------------------------------------------------
    // GATE 4: Limite Temporal da Janela de 30 Dias (Exclusão de Registros Antigos)
    // -------------------------------------------------------------------------
    echo "\n--> [5/7] Testando Exclusão de Check-in Fora da Janela (> 30 dias)...\n";

    // Inserir check-in de 45 dias atrás com 100% de adesão
    $db->prepare("
        INSERT INTO checkins (clinic_id, patient_id, meal_adherence_pct, checkin_date)
        VALUES (:cid, :pid, 100, :cdate)
    ")->execute([
        'cid' => $clinicIdAlfa,
        'pid' => $patientIdAlfa1,
        'cdate' => date('Y-m-d', strtotime('-45 days')),
    ]);

    // O cálculo da janela de 30 dias não deve incluir o check-in de 45 dias atrás (média deve continuar 85.0%)
    $windowMetrics = $engagementService->getClinicAdherenceMetrics($clinicIdAlfa, 30);
    assertPx04($windowMetrics['responses_count'] === 2, "Check-in de 45 dias atrás deve ser ignorado na janela de 30 dias");
    assertPx04($windowMetrics['average_pct'] === 85.0, "Média deve permanecer 85.0%");

    // Se expandirmos a janela para 60 dias, o check-in antigo deve ser incluído: (80 + 90 + 100) / 3 = 90.0%
    $sixtyDaysMetrics = $engagementService->getClinicAdherenceMetrics($clinicIdAlfa, 60);
    assertPx04($sixtyDaysMetrics['responses_count'] === 3, "Janela de 60 dias deve incluir o check-in de 45 dias");
    assertPx04($sixtyDaysMetrics['average_pct'] === 90.0, "Média em 60 dias deve ser 90.0%");

    logPx04Pass("GATE_4", "Filtro temporal restritivo comprovado: registros fora da janela são estritamente excluídos");

    // -------------------------------------------------------------------------
    // GATE 5: Isolamento Multi-Tenant Estrito
    // -------------------------------------------------------------------------
    echo "\n--> [6/7] Testando Isolamento Multi-Tenant entre Alfa e Beta...\n";

    // Métricas do Tenant Beta não podem ser afetadas pelos dados do Tenant Alfa
    $betaMetrics = $engagementService->getClinicAdherenceMetrics($clinicIdBeta, 30);
    assertPx04($betaMetrics['has_data'] === false, "Tenant Beta deve continuar sem dados");
    assertPx04($betaMetrics['formatted_value'] === 'Sem dados', "Tenant Beta deve exibir 'Sem dados'");
    assertPx04($betaMetrics['responses_count'] === 0, "Tenant Beta deve ter 0 respostas");

    logPx04Pass("GATE_5", "Isolamento multi-tenant garantido: agregação clínica é estritamente confinada ao tenant");

    // -------------------------------------------------------------------------
    // GATE 6 & 7: Validação Defensiva & Renderização da View do Dashboard HTML
    // -------------------------------------------------------------------------
    echo "\n--> [7/7] Testando Rejeição Defensiva e Renderização HTML do Dashboard...\n";

    // Validação defensiva
    $defensiveException = false;
    try {
        $engagementService->getClinicAdherenceMetrics(0);
    } catch (\InvalidArgumentException $e) {
        $defensiveException = true;
    }
    assertPx04($defensiveException, "Clinic ID 0 deve lançar InvalidArgumentException");

    // Renderização do Dashboard para Tenant Beta (Estado de Vazio)
    $_SESSION['clinic_id'] = $clinicIdBeta;
    $_SESSION['user_id'] = $nutriIdBeta;
    $_SESSION['user_name'] = 'Dr. Roberto Beta';
    $_SESSION['clinic_name'] = 'Clínica Beta Virgem';
    TenantContext::setClinicId($clinicIdBeta);
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'clinic_id' => $clinicIdBeta]);

    $controller = new DashboardController();

    ob_start();
    $controller->index();
    $dashboardBetaHtml = (string)ob_get_clean();

    assertPx04(!str_contains($dashboardBetaHtml, '94.0%'), "Dashboard NÃO deve conter o fallback fixo '94.0%'");
    assertPx04(str_contains($dashboardBetaHtml, 'Sem dados'), "Dashboard deve conter 'Sem dados'");
    assertPx04(str_contains($dashboardBetaHtml, 'Sem dados (30 dias)'), "Badge deve indicar 'Sem dados (30 dias)'");
    assertPx04(str_contains($dashboardBetaHtml, '0 respostas'), "Dashboard deve informar 0 respostas");
    assertPx04(str_contains($dashboardBetaHtml, 'paciente(s) elegíveis'), "Dashboard deve informar população elegível");

    // Renderização do Dashboard para Tenant Alfa (Estado com Dados Reais)
    $_SESSION['clinic_id'] = $clinicIdAlfa;
    $_SESSION['user_id'] = $nutriIdAlfa;
    $_SESSION['user_name'] = 'Dra. Camila Mendes Alfa';
    $_SESSION['clinic_name'] = 'Clínica Alfa Adesão Real';
    TenantContext::setClinicId($clinicIdAlfa);
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'clinic_id' => $clinicIdAlfa]);

    ob_start();
    $controller->index();
    $dashboardAlfaHtml = (string)ob_get_clean();

    assertPx04(!str_contains($dashboardAlfaHtml, '94.0%'), "Dashboard Alfa NÃO deve conter 94.0%");
    assertPx04(str_contains($dashboardAlfaHtml, '85,0%'), "Dashboard Alfa deve exibir '85,0%'");
    assertPx04(str_contains($dashboardAlfaHtml, '2 respostas'), "Badge Alfa deve informar respostas e período");
    assertPx04(str_contains($dashboardAlfaHtml, '2 de 2 paciente(s) elegíveis responderam'), "Dashboard Alfa deve informar amostra sobre elegíveis");

    logPx04Pass("GATE_6", "Validação defensiva contra clinic_id inválido aprovada");
    logPx04Pass("GATE_7", "Renderização HTML aprovada no Design System: empty state e métrica real com amostra visível");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 7 GATES DO PX-04 (NI2-297) FORAM APROVADOS COM SUCESSO! <<<\n";
    echo "========================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[ERROR] FALHA NO TESTE PX-04: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    echo "--> [CLEANUP] Limpando dados de teste PX-04...\n";
    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM checkins WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM patients WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM professionals WHERE user_id IN ({$nutriIdAlfa}, {$nutriIdBeta})");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM users WHERE id IN ({$nutriIdAlfa}, {$nutriIdBeta})");
        $db->exec("DELETE FROM clinics WHERE id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
    }
    echo "  [OK] Teardown concluído.\n\n";
}
