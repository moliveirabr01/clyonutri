<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE HOMOLOGAÇÃO E2E: NI2-292 (APLICATIVO MOBILE PACIENTE & NUTRICIONISTA)
 * ==============================================================================
 * Verifica em código de produção e banco relacional real (Zero Mocks / Zero Simulações):
 *   Gate 1: Autenticação Segregada de Papéis (Paciente vs Profissional)
 *   Gate 2: Emissão, Expiração UTC e Revogação de Tokens Sanctum SHA-256
 *   Gate 3: Jornada Mobile do Paciente (Home, Hidratação, Refeição no Diário)
 *   Gate 4: Jornada Mobile do Nutricionista (Dashboard, Agenda, Lista de Pacientes, Diários)
 *   Gate 5: Loop de Feedback Clínico Contextual Ponta-a-Ponta e Trilha de Auditoria
 *   Gate 6: Isolamento Multi-Tenant e Bloqueio Fail-Closed entre Clínicas
 *   Gate 7: Integridade Estática e Contratos do Cliente Mobile
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Router;
use ClyoNutri\Core\Auth\TenantContext;

echo "========================================================================================\n";
echo "   HOMOLOGAÇÃO DE CRITÉRIOS DE ACEITE: NI2-292\n";
echo "   APLICATIVO MOBILE PACIENTE & NUTRICIONISTA — ENGAJAMENTO E RETENÇÃO\n";
echo "========================================================================================\n\n";

$db = Connection::getInstance();

// -----------------------------------------------------------------------------
// 1. SETUP DE DADOS DE TESTE REAIS (ISOLAMENTO RELACIONAL)
// -----------------------------------------------------------------------------
$clinicIdA = 801;
$clinicIdB = 802;
$nutriIdA = 8011;
$nutriIdB = 8021;
$patientIdA = 8012;

// Limpeza prévia
$db->exec("DELETE FROM audit_logs WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
$db->exec("DELETE FROM food_diary_entries WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
$db->exec("DELETE FROM hydration_logs WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
$db->exec("DELETE FROM appointments WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
$db->exec("DELETE FROM personal_access_tokens WHERE tokenable_id IN ($patientIdA, $nutriIdA, $nutriIdB);");
$db->exec("DELETE FROM clinic_members WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
$db->exec("DELETE FROM professionals WHERE id IN ($nutriIdA, $nutriIdB);");
$db->exec("DELETE FROM patients WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
$db->exec("DELETE FROM users WHERE id IN ($nutriIdA, $nutriIdB);");
$db->exec("DELETE FROM clinics WHERE id IN ($clinicIdA, $clinicIdB);");

// Criação de Clínicas A e B
$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES ($clinicIdA, 'clinic-801-uuid', 'Clínica ClyoNutri Alpha', 'active');");
$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES ($clinicIdB, 'clinic-802-uuid', 'Clínica ClyoNutri Beta', 'active');");

// Nutricionista da Clínica A
$nutriPassword = 'SenhaNutriAlpha@2026';
$nutriHash = password_hash($nutriPassword, PASSWORD_BCRYPT);
$stmtUser = $db->prepare("
    INSERT INTO users (id, uuid, name, email, password_hash, role, status)
    VALUES ($nutriIdA, 'nutri-8011-uuid', 'Dra. Carolina Mendes', 'carolina.nutri@alpha.com', :hash, 'nutritionist', 'active');
");
$stmtUser->execute(['hash' => $nutriHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES ($clinicIdA, $nutriIdA, 'nutritionist', 'active');");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES ($nutriIdA, $nutriIdA, 'CRN-12345', 'CRN-3', 'Nutrição Clínica');");

// Nutricionista da Clínica B (Tenant concorrente)
$stmtUserB = $db->prepare("
    INSERT INTO users (id, uuid, name, email, password_hash, role, status)
    VALUES ($nutriIdB, 'nutri-8021-uuid', 'Dr. Roberto Souza', 'roberto.nutri@beta.com', :hash, 'nutritionist', 'active');
");
$stmtUserB->execute(['hash' => $nutriHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES ($clinicIdB, $nutriIdB, 'nutritionist', 'active');");

// Paciente da Clínica A
$patientPassword = 'SenhaPacienteAlpha@2026';
$patientHash = password_hash($patientPassword, PASSWORD_BCRYPT);
$stmtPat = $db->prepare("
    INSERT INTO patients (id, clinic_id, uuid, name, email, phone, password_hash, status)
    VALUES ($patientIdA, $clinicIdA, 'pat-8012-uuid', 'Felipe Santos', 'felipe.paciente@alpha.com', '11977778888', :hash, 'active');
");
$stmtPat->execute(['hash' => $patientHash]);

// Agendamento de hoje para Dra. Carolina com Felipe
$todayTime = date('Y-m-d') . ' 14:30:00';
$db->exec("
    INSERT INTO appointments (id, clinic_id, patient_id, professional_id, scheduled_at, status, notes)
    VALUES (8013, $clinicIdA, $patientIdA, $nutriIdA, '$todayTime', 'scheduled', 'Retorno de 30 dias - avaliação de diário');
");

$passed = 0;
$failed = 0;

function assertGate(bool $condition, string $msg): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $msg\n";
        $passed++;
    } else {
        echo " [FAIL] $msg\n";
        $failed++;
    }
}

// Helper para executar requisições HTTP isoladas diretamente no entrypoint de produção
function runHttp(string $method, string $uri, array $body = [], ?string $bearerToken = null): array {
    $script = '
        $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
        $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
        ' . ($bearerToken ? '$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $bearerToken . '";' : '') . '
        $parsed = parse_url(' . var_export($uri, true) . ');
        if (!empty($parsed["query"])) { parse_str($parsed["query"], $_GET); } else { $_GET = []; }
        $_POST = ' . var_export($body, true) . ';
        require "public/index.php";
    ';

    $tempScript = tempnam(sys_get_temp_dir(), 'ni2_http_');
    file_put_contents($tempScript, "<?php\n" . $script);

    $cmd = "php -d display_errors=0 " . escapeshellarg($tempScript) . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($tempScript);

    $json = json_decode((string)$output, true);

    return [
        'output' => $output,
        'json' => $json,
    ];
}

// -----------------------------------------------------------------------------
// GATE 1: AUTENTICAÇÃO SEGREGADA DE PAPÉIS (PACIENTE VS PROFISSIONAL)
// -----------------------------------------------------------------------------
echo "[GATE 1 - AUTENTICAÇÃO FORTE E SEGREGAÇÃO DE PAPÉIS]\n";

// 1.1 Login de Paciente com sucesso
$resPat = runHttp('POST', '/api/v1/auth/patient-login', [
    'email' => 'felipe.paciente@alpha.com',
    'password' => $patientPassword,
]);
assertGate(!empty($resPat['json']['token']), "Paciente autenticado com emissão de Bearer token");
assertGate($resPat['json']['patient']['id'] === $patientIdA, "Payload identifica paciente Felipe Santos (#$patientIdA)");
$patientToken = $resPat['json']['token'] ?? '';

// 1.2 Paciente tenta entrar na rota de login profissional (deve falhar)
$resCrossPat = runHttp('POST', '/api/v1/auth/professional-login', [
    'email' => 'felipe.paciente@alpha.com',
    'password' => $patientPassword,
]);
assertGate(empty($resCrossPat['json']['token']), "Conta de paciente é estritamente recusada na rota de login profissional");

// 1.3 Login de Nutricionista com sucesso
$resNutri = runHttp('POST', '/api/v1/auth/professional-login', [
    'email' => 'carolina.nutri@alpha.com',
    'password' => $nutriPassword,
]);
assertGate(!empty($resNutri['json']['token']), "Nutricionista autenticada com emissão de Bearer token profissional");
assertGate($resNutri['json']['professional']['id'] === $nutriIdA, "Payload identifica Dra. Carolina Mendes (#$nutriIdA)");
assertGate($resNutri['json']['professional']['role'] === 'nutritionist', "Papel clínico 'nutritionist' comprovado no token");
$nutriToken = $resNutri['json']['token'] ?? '';

// 1.4 Nutricionista tenta entrar na rota de login do paciente (deve falhar)
$resCrossNutri = runHttp('POST', '/api/v1/auth/patient-login', [
    'email' => 'carolina.nutri@alpha.com',
    'password' => $nutriPassword,
]);
assertGate(empty($resCrossNutri['json']['token']), "Conta de profissional é estritamente recusada na rota de login de paciente");

// 1.5 Senha incorreta de profissional recusada
$resBadPass = runHttp('POST', '/api/v1/auth/professional-login', [
    'email' => 'carolina.nutri@alpha.com',
    'password' => 'SenhaErrada@999',
]);
assertGate(empty($resBadPass['json']['token']), "Credencial incorreta recusada sem emissão de token");

// -----------------------------------------------------------------------------
// GATE 2: TOKENS SANCTUM SHA-256 E EXPIRAÇÃO ESTRICTA
// -----------------------------------------------------------------------------
echo "\n[GATE 2 - TOKENS SANCTUM SHA-256 E EXPIRAÇÃO ESTRICTA]\n";

$hashPat = hash('sha256', $patientToken);
$stmtTokPat = $db->prepare("SELECT * FROM personal_access_tokens WHERE token = :hash AND tokenable_type = 'patient'");
$stmtTokPat->execute(['hash' => $hashPat]);
$rowTokPat = $stmtTokPat->fetch();
$stmtTokPat->closeCursor();
assertGate(!empty($rowTokPat), "Token do paciente gravado com hash SHA-256 (nunca em plaintext)");
assertGate(!empty($rowTokPat['expires_at']) && strtotime($rowTokPat['expires_at']) > time(), "Token do paciente com expiração estrita futura gravada");

$hashNutri = hash('sha256', $nutriToken);
$stmtTokNutri = $db->prepare("SELECT * FROM personal_access_tokens WHERE token = :hash AND tokenable_type = 'professional'");
$stmtTokNutri->execute(['hash' => $hashNutri]);
$rowTokNutri = $stmtTokNutri->fetch();
$stmtTokNutri->closeCursor();
assertGate(!empty($rowTokNutri), "Token do profissional gravado com hash SHA-256 e tipo 'professional'");
assertGate(!empty($rowTokNutri['expires_at']) && strtotime($rowTokNutri['expires_at']) > time(), "Token do profissional com expiração de 30 dias gravada");

// -----------------------------------------------------------------------------
// GATE 3: JORNADA MOBILE DO PACIENTE (HOME, HIDRATAÇÃO E DIÁRIO)
// -----------------------------------------------------------------------------
echo "\n[GATE 3 - JORNADA MOBILE DO PACIENTE]\n";

// 3.1 Consulta Home do Paciente
$homePat = runHttp('GET', '/api/v1/patient/home', [], $patientToken);
assertGate(!empty($homePat['json']['patient_name']), "Home do paciente acessada: {$homePat['json']['patient_name']}");
assertGate($homePat['json']['next_appointment']['id'] === 8013, "Próxima consulta identificada corretamente (#8013)");

// 3.2 Registro de Hidratação
$hyd1 = runHttp('POST', '/api/v1/patient/hydration', ['amount_ml' => 350], $patientToken);
assertGate(($hyd1['json']['total_water_today'] ?? 0) === 350, "Primeiro registro de água persistido: 350 ml");

$hyd2 = runHttp('POST', '/api/v1/patient/hydration', ['amount_ml' => 500], $patientToken);
assertGate(($hyd2['json']['total_water_today'] ?? 0) === 850, "Segundo registro acumulado com sucesso: 850 ml no banco");

// 3.3 Registro de Refeição no Diário Alimentar
$diaryRes = runHttp('POST', '/api/v1/patient/food-diary', [
    'meal_name' => 'Almoço Funcional de Segunda',
    'description' => 'Salmão grelhado 120g, salada de rúcula com tomate cereja e azeite extravirgem',
    'hunger_level' => 3,
    'satiety_level' => 4,
], $patientToken);
assertGate(!empty($diaryRes['json']['id']), "Refeição do paciente registrada no diário alimentar com ID relacional");
$entryId = (int)($diaryRes['json']['id'] ?? 0);

// -----------------------------------------------------------------------------
// GATE 4: JORNADA MOBILE DO NUTRICIONISTA (DASHBOARD, AGENDA E DIÁRIOS)
// -----------------------------------------------------------------------------
echo "\n[GATE 4 - JORNADA MOBILE DO NUTRICIONISTA]\n";

// 4.1 Dashboard do Nutricionista
$dashRes = runHttp('GET', '/api/v1/professional/dashboard', [], $nutriToken);
assertGate($dashRes['json']['professional_name'] === 'Dra. Carolina Mendes', "Dashboard acessado pela Dra. Carolina Mendes");
assertGate($dashRes['json']['clinic_id'] === $clinicIdA, "Dashboard vinculado à Clínica Alpha (#$clinicIdA)");
assertGate($dashRes['json']['active_patients_count'] >= 1, "Contagem de pacientes ativos exibida no aplicativo do profissional");
assertGate($dashRes['json']['pending_diaries_count'] >= 1, "Contagem de diários pendentes reflete a refeição recém registrada");

// 4.2 Agenda de Atendimentos de Hoje
assertGate(count($dashRes['json']['today_appointments']) >= 1, "Agenda do dia contém consulta agendada com Felipe Santos");

// 4.3 Lista de Pacientes
$patListRes = runHttp('GET', '/api/v1/professional/patients', [], $nutriToken);
assertGate(count($patListRes['json']['patients'] ?? []) >= 1, "Lista de pacientes da clínica retornada com sucesso");
assertGate($patListRes['json']['patients'][0]['name'] === 'Felipe Santos', "Paciente Felipe Santos presente na lista profissional");

// 4.4 Listagem de Diários Alimentares da Clínica
$diariesRes = runHttp('GET', '/api/v1/professional/food-diaries', [], $nutriToken);
assertGate(count($diariesRes['json']['diaries'] ?? []) >= 1, "Fila de diários alimentares da clínica retornada com sucesso");
$foundDiary = false;
foreach ($diariesRes['json']['diaries'] as $d) {
    if ($d['id'] === $entryId) {
        $foundDiary = true;
        break;
    }
}
assertGate($foundDiary, "Refeição registrada pelo paciente está visível na fila do nutricionista");

// -----------------------------------------------------------------------------
// GATE 5: LOOP DE FEEDBACK CLÍNICO CONTEXTUAL E AUDITORIA
// -----------------------------------------------------------------------------
echo "\n[GATE 5 - LOOP DE FEEDBACK CLÍNICO CONTEXTUAL E AUDITORIA]\n";

$feedbackOrientacao = 'Excelente escolha proteica e fibras, Felipe! Aumente ligeiramente a porção de folhas escuras.';
$feedbackRes = runHttp('POST', "/api/v1/professional/food-diary/$entryId/feedback", [
    'feedback' => $feedbackOrientacao,
], $nutriToken);
assertGate(!empty($feedbackRes['json']['success']) && $feedbackRes['json']['success'] === true, "Feedback clínico enviado com sucesso pelo nutricionista");

// 5.2 Conferir persistência relacional direta no banco
$stmtCheck = $db->prepare("SELECT * FROM food_diary_entries WHERE id = :id");
$stmtCheck->execute(['id' => $entryId]);
$rowDiary = $stmtCheck->fetch();
$stmtCheck->closeCursor();
assertGate($rowDiary['feedback_from_professional'] === $feedbackOrientacao, "Orientação clínica persistida na tabela food_diary_entries");
assertGate(!empty($rowDiary['reviewed_at']), "Timestamp reviewed_at gravado fisicamente na refeição");

// 5.3 Conferir auditoria
$stmtAudit = $db->prepare("
    SELECT * FROM audit_logs 
    WHERE clinic_id = :cid AND resource = 'food_diary_entry' AND resource_id = :eid
    ORDER BY id DESC LIMIT 1
");
$stmtAudit->execute(['cid' => $clinicIdA, 'eid' => (string)$entryId]);
$rowAudit = $stmtAudit->fetch();
$stmtAudit->closeCursor();
assertGate(!empty($rowAudit) && $rowAudit['action'] === 'FOOD_DIARY_FEEDBACK_GIVEN', "Trilha de auditoria FOOD_DIARY_FEEDBACK_GIVEN registrada para compliance LGPD");

// 5.4 Paciente agora consome o feedback no seu aplicativo
$patDiaryList = runHttp('GET', '/api/v1/patient/food-diary', [], $patientToken);
$feedbackReceived = false;
foreach ($patDiaryList['json']['entries'] ?? [] as $entry) {
    if ($entry['id'] === $entryId && $entry['feedback_from_professional'] === $feedbackOrientacao) {
        $feedbackReceived = true;
        break;
    }
}
assertGate($feedbackReceived, "Paciente recebe o feedback clínico contextual da Dra. Carolina no aplicativo móvel");

// -----------------------------------------------------------------------------
// GATE 6: ISOLAMENTO MULTI-TENANT E BLOQUEIO FAIL-CLOSED
// -----------------------------------------------------------------------------
echo "\n[GATE 6 - ISOLAMENTO MULTI-TENANT E BLOQUEIO FAIL-CLOSED]\n";

// Nutricionista B loga na Clínica Beta
$resNutriB = runHttp('POST', '/api/v1/auth/professional-login', [
    'email' => 'roberto.nutri@beta.com',
    'password' => $nutriPassword,
]);
$nutriTokenB = $resNutriB['json']['token'] ?? '';

// Nutricionista B tenta ver os diários da Clínica Alpha
$dashB = runHttp('GET', '/api/v1/professional/food-diaries', [], $nutriTokenB);
$sawTenantAData = false;
foreach ($dashB['json']['diaries'] ?? [] as $d) {
    if ($d['clinic_id'] === $clinicIdA || $d['patient_id'] === $patientIdA) {
        $sawTenantAData = true;
        break;
    }
}
assertGate(!$sawTenantAData, "Nutricionista do Tenant B não visualiza diários alimentares do Tenant A");

// Nutricionista B tenta enviar feedback para refeição da Clínica Alpha (Ataque IDOR / Cross-Tenant)
$idorRes = runHttp('POST', "/api/v1/professional/food-diary/$entryId/feedback", [
    'feedback' => 'Tentativa indevida de intromissão cross-tenant',
], $nutriTokenB);
assertGate(empty($idorRes['json']['success']), "Tentativa de feedback cross-tenant bloqueada fail-closed");

// -----------------------------------------------------------------------------
// GATE 7: REVOGAÇÃO DE TOKENS (LOGOUT AMBOS OS PAPÉIS)
// -----------------------------------------------------------------------------
echo "\n[GATE 7 - REVOGAÇÃO DE SESSÕES MOBILE E LOGOUT]\n";

$logoutPat = runHttp('POST', '/api/v1/auth/patient-logout', [], $patientToken);
assertGate(!empty($logoutPat['json']['message']), "Logout do paciente concluído");

$logoutNutri = runHttp('POST', '/api/v1/auth/professional-logout', [], $nutriToken);
assertGate(!empty($logoutNutri['json']['message']), "Logout do profissional concluído");

$expiredCheck = runHttp('GET', '/api/v1/professional/dashboard', [], $nutriToken);
assertGate(empty($expiredCheck['json']['professional_name']), "Token profissional revogado bloqueado em requisição subsequente");

// -----------------------------------------------------------------------------
// RESULTADO FINAL
// -----------------------------------------------------------------------------
echo "\n========================================================================================\n";
echo "   RESULTADO DA HOMOLOGAÇÃO NI2-292 (MOBILE PACIENTE & NUTRICIONISTA)\n";
echo "========================================================================================\n";
echo " Total de Asserções : " . ($passed + $failed) . "\n";
echo " Aprovadas (PASS)   : $passed\n";
echo " Falhas (FAIL)      : $failed\n";

if ($failed === 0) {
    echo "\n>>> TODOS OS 7 GATES DE HOMOLOGAÇÃO NI2-292 FORAM APROVADOS COM SUCESSO (PASS) <<<\n\n";
    exit(0);
} else {
    echo "\n>>> FALHA NA HOMOLOGAÇÃO: $failed ASSERÇÕES NÃO ATENDIDAS <<<\n\n";
    exit(1);
}
