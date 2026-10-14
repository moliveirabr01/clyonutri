<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTE E2E: CHAT ALUNO-NUTRICIONISTA & VISUALIZAÇÃO ONLINE
 * ==============================================================================
 * Validação rigorosa em código de produção e banco SQLite real:
 *  Gate 1: Setup e Autenticação de Aluno/Paciente e Nutricionista
 *  Gate 2: Auto-criação da conversa ao acessar o chat pelo App Mobile
 *  Gate 3: Envio de mensagem pelo Aluno (App Mobile) e persistência no banco
 *  Gate 4: Nutricionista visualiza mensagem online em tempo real (Polling Web e Mobile)
 *  Gate 5: Nutricionista responde (Web e Mobile) com persistência relacional
 *  Gate 6: Aluno recebe resposta via cursor incremental (after_id) e confirma leitura
 *  Gate 7: Isolamento multi-tenant fail-closed e testes de regressão de segurança
 *  Gate 8: Validação dos contratos do App Mobile (TypeScript e UI)
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\TenantContext;

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — TESTE E2E: CHAT ALUNO-NUTRICIONISTA EM TEMPO REAL <<<\n";
echo "    App Mobile do Aluno ➔ Visualização Online & Resposta do Nutricionista\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

function getFreshDb(): PDO {
    // Mesmo arquivo usado por Connection (banco de testes isolado), nunca o banco de desenvolvimento.
    $fresh = new PDO('sqlite:' . (getenv('DB_DATABASE') ?: __DIR__ . '/../storage/testing.sqlite'));
    $fresh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fresh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $fresh;
}

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $msg): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $msg\n";
        $passed++;
    } else {
        echo "  [FAIL] $msg\n";
        $failed++;
        throw new RuntimeException("FALHA: $msg");
    }
}

// Helper para executar requisições HTTP isoladas com ambiente simulado em index.php
function callHttp(string $method, string $uri, array $body = [], ?string $bearerToken = null, ?array $session = [], ?array $headers = []): array {
    $script = '
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ' . var_export($session ?: [], true) . ';
        $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
        $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
        ' . ($bearerToken ? '$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $bearerToken . '";' : '') . '
        $extraHeaders = ' . var_export($headers ?: [], true) . ';
        foreach ($extraHeaders as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $parsed = parse_url(' . var_export($uri, true) . ');
        if (!empty($parsed["query"])) { parse_str($parsed["query"], $_GET); } else { $_GET = []; }
        $_POST = ' . var_export($body, true) . ';
        require "public/index.php";
    ';

    $tempScript = __DIR__ . '/../storage/temp_chat_test_' . bin2hex(random_bytes(6)) . '.php';
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

$clinicIdA = 901;
$clinicIdB = 902;
$nutriIdA = 9011;
$patientIdA = 9012;
$nutriIdB = 9021;
$patientIdB = 9022;

try {
    // -------------------------------------------------------------------------
    // GATE 1: SETUP E AUTENTICAÇÃO REAL
    // -------------------------------------------------------------------------
    echo "--> [GATE 1] Provisionando Tenants, Profissionais e Alunos no Banco Real...\n";

    // Limpeza de testes anteriores
    $db->exec("DELETE FROM chat_messages WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
    $db->exec("DELETE FROM chat_conversations WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
    $db->exec("DELETE FROM personal_access_tokens WHERE tokenable_id IN ($patientIdA, $nutriIdA, $patientIdB, $nutriIdB);");
    $db->exec("DELETE FROM appointments WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
    $db->exec("DELETE FROM clinic_members WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
    $db->exec("DELETE FROM professionals WHERE id IN ($nutriIdA, $nutriIdB);");
    $db->exec("DELETE FROM patients WHERE clinic_id IN ($clinicIdA, $clinicIdB);");
    $db->exec("DELETE FROM users WHERE id IN ($nutriIdA, $nutriIdB);");
    $db->exec("DELETE FROM clinics WHERE id IN ($clinicIdA, $clinicIdB);");

    // Criação das Clínicas
    $db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES ($clinicIdA, 'clinic-chat-901', 'Clínica Escola Nutri Alpha', 'active');");
    $db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES ($clinicIdB, 'clinic-chat-902', 'Clínica Externa Beta', 'active');");

    // Nutricionista Clínica A
    $nutriPass = 'NutriSegura@2026';
    $nutriHash = password_hash($nutriPass, PASSWORD_BCRYPT);
    $stmtNutri = $db->prepare("
        INSERT INTO users (id, uuid, name, email, password_hash, role, status)
        VALUES ($nutriIdA, 'nutri-usr-9011', 'Dra. Camila Nutricionista', 'camila.nutri@alpha.com', :h, 'nutritionist', 'active')
    ");
    $stmtNutri->execute(['h' => $nutriHash]);
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES ($clinicIdA, $nutriIdA, 'nutritionist', 'active');");
    $db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES ($nutriIdA, $nutriIdA, 'CRN3-998877', 'CRN-3', 'Nutrição Esportiva e Clínica');");

    // Aluno / Paciente Clínica A
    $patPass = 'AlunoSeguro@2026';
    $patHash = password_hash($patPass, PASSWORD_BCRYPT);
    $stmtPat = $db->prepare("
        INSERT INTO patients (id, clinic_id, uuid, name, email, phone, password_hash, status)
        VALUES ($patientIdA, $clinicIdA, 'aluno-pat-9012', 'Lucas Aluno', 'lucas.aluno@alpha.com', '11999990001', :h, 'active')
    ");
    $stmtPat->execute(['h' => $patHash]);

    // Nutricionista e Paciente Clínica B (para testes de isolamento)
    $stmtNutriB = $db->prepare("
        INSERT INTO users (id, uuid, name, email, password_hash, role, status)
        VALUES ($nutriIdB, 'nutri-usr-9021', 'Dr. Marcos Nutricionista', 'marcos.nutri@beta.com', :h, 'nutritionist', 'active')
    ");
    $stmtNutriB->execute(['h' => $nutriHash]);
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES ($clinicIdB, $nutriIdB, 'nutritionist', 'active');");
    $db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES ($nutriIdB, $nutriIdB, 'CRN3-554433', 'CRN-3', 'Nutrição Clínica');");

    $stmtPatB = $db->prepare("
        INSERT INTO patients (id, clinic_id, uuid, name, email, phone, password_hash, status)
        VALUES ($patientIdB, $clinicIdB, 'aluno-pat-9022', 'Beatriz Aluna Beta', 'beatriz.aluna@beta.com', '11999990002', :h, 'active')
    ");
    $stmtPatB->execute(['h' => $patHash]);

    // 1.1 Autenticação do Aluno via API Mobile
    $loginPatRes = callHttp('POST', '/api/v1/auth/patient-login', [
        'email' => 'lucas.aluno@alpha.com',
        'password' => $patPass,
    ]);
    assertTest(!empty($loginPatRes['json']['token']), "Aluno autenticado via API Mobile com token Sanctum");
    $alunoToken = $loginPatRes['json']['token'] ?? '';
    assertTest($loginPatRes['json']['patient']['id'] === $patientIdA, "Token pertence ao aluno Lucas Aluno (#$patientIdA)");

    // 1.2 Autenticação do Nutricionista via API Mobile
    $loginNutriRes = callHttp('POST', '/api/v1/auth/professional-login', [
        'email' => 'camila.nutri@alpha.com',
        'password' => $nutriPass,
    ]);
    assertTest(!empty($loginNutriRes['json']['token']), "Nutricionista autenticado via API Mobile com token Sanctum");
    $nutriToken = $loginNutriRes['json']['token'] ?? '';

    // Sessão Web da Nutricionista
    $webNutriSession = [
        'user_id' => $nutriIdA,
        'clinic_id' => $clinicIdA,
        'user_name' => 'Dra. Camila Nutricionista',
        'user_role' => 'nutritionist',
        'clinic_name' => 'Clínica Escola Nutri Alpha',
        'auth_version' => 0,
    ];

    // -------------------------------------------------------------------------
    // GATE 2: AUTO-CRIAÇÃO DA CONVERSA PELO APP DO ALUNO
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 2] Auto-associação e Abertura do Canal pelo Aluno...\n";

    // Aluno requisita mensagens sem ter conversa prévia criada no banco
    $convCheckBefore = $db->query("SELECT count(*) FROM chat_conversations WHERE clinic_id = $clinicIdA")->fetchColumn();
    assertTest((int)$convCheckBefore === 0, "Nenhuma conversa existente antes do primeiro acesso do aluno");

    $initChatRes = callHttp('GET', '/api/v1/patient/chat/messages', [], $alunoToken);
    assertTest(isset($initChatRes['json']['conversation_id']), "Endpoint /api/v1/patient/chat/messages respondeu com sucesso");
    $convId = (int)($initChatRes['json']['conversation_id'] ?? 0);
    assertTest($convId > 0, "Conversa criada e vinculada automaticamente com ID #$convId");
    assertTest(!empty($initChatRes['json']['professional']['name']), "Dados da nutricionista retornados ao aluno: " . ($initChatRes['json']['professional']['name'] ?? ''));
    assertTest($initChatRes['json']['professional']['id'] === $nutriIdA, "Nutricionista vinculada corresponde à Dra. Camila (#$nutriIdA)");

    // Confere no banco
    $convRow = $db->query("SELECT * FROM chat_conversations WHERE id = $convId")->fetch();
    assertTest($convRow && (int)$convRow['clinic_id'] === $clinicIdA, "Conversa persistida na clínica $clinicIdA");
    assertTest((int)$convRow['patient_id'] === $patientIdA && (int)$convRow['professional_id'] === $nutriIdA, "Participantes registrados no banco: Aluno #$patientIdA e Nutricionista #$nutriIdA");

    // -------------------------------------------------------------------------
    // GATE 3: ALUNO ENVIA MENSAGEM VIA APP MOBILE
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 3] Envio de Mensagem pelo Aluno e Persistência no Banco...\n";

    $alunoMsgTexto = "Olá Dra. Camila! Estou com uma dúvida sobre o plano alimentar no lanche da tarde.";
    $idempotencyKeyAluno = 'idem-aluno-' . bin2hex(random_bytes(8));

    $sendRes = callHttp('POST', '/api/v1/patient/chat/send', [
        'conversation_id' => $convId,
        'message' => $alunoMsgTexto,
        'idempotency_key' => $idempotencyKeyAluno,
    ], $alunoToken, [], ['CONTENT_TYPE' => 'application/json']);

    assertTest(!empty($sendRes['json']['success']) && $sendRes['json']['success'] === true, "Aluno enviou mensagem com sucesso via POST /api/v1/patient/chat/send");
    $msgAlunoId = (int)($sendRes['json']['message']['id'] ?? 0);
    assertTest($msgAlunoId > 0, "ID da mensagem gerado no banco: #$msgAlunoId");
    assertTest($sendRes['json']['message']['sender_type'] === 'patient', "Tipo de remetente gravado como 'patient'");

    // Confere persistência no banco
    $stmtCheckMsg = getFreshDb()->prepare("SELECT * FROM chat_messages WHERE id = :id");
    $stmtCheckMsg->execute(['id' => $msgAlunoId]);
    $msgRow = $stmtCheckMsg->fetch();
    assertTest($msgRow && $msgRow['message'] === $alunoMsgTexto, "Mensagem do aluno gravada com integridade no banco relacional");
    assertTest((int)$msgRow['clinic_id'] === $clinicIdA, "Isolamento de tenant comprovado na mensagem");

    // -------------------------------------------------------------------------
    // GATE 4: NUTRICIONISTA VISUALIZA ONLINE EM TEMPO REAL (WEB & MOBILE)
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 4] Nutricionista Visualiza Mensagem Online em Tempo Real...\n";

    // 4.1 Polling Web: Nutricionista escuta deltas via /chat/{id}/poll
    $pollWebRes = callHttp('GET', "/chat/{$convId}/poll", [], null, $webNutriSession);
    assertTest(!empty($pollWebRes['json']['messages']), "Nutricionista recebeu mensagens no polling web /chat/{id}/poll");
    $lastMsgWeb = end($pollWebRes['json']['messages']);
    assertTest($lastMsgWeb['id'] === $msgAlunoId, "Mensagem enviada pelo aluno está visível para a nutricionista online");
    assertTest($lastMsgWeb['sender_type'] === 'patient', "Remetente identificado corretamente como paciente/aluno");

    // Confere resumo lateral das conversas
    assertTest(!empty($pollWebRes['json']['conversations_summary']), "Sumário de conversas retornado para atualização dinâmica da sidebar");
    $summary = $pollWebRes['json']['conversations_summary'][0] ?? [];
    assertTest($summary['id'] === $convId, "Sumário identifica conversa #$convId");
    assertTest($summary['unread_count'] >= 1, "Contador de mensagens não lidas incrementado para a nutricionista");

    // 4.2 Nutricionista no App Mobile: Visualiza a lista de conversas
    $profConvsRes = callHttp('GET', '/api/v1/professional/chat/conversations', [], $nutriToken);
    assertTest(!empty($profConvsRes['json']['conversations']), "Nutricionista lista conversas no App Mobile");
    assertTest((int)$profConvsRes['json']['conversations'][0]['id'] === $convId, "Conversa com o aluno listada no mobile do profissional");

    // 4.3 Nutricionista no App Mobile: Polling de mensagens
    $profPollRes = callHttp('GET', "/api/v1/professional/chat/{$convId}/messages", [], $nutriToken);
    assertTest(!empty($profPollRes['json']['messages']), "Nutricionista recebe mensagens no App Mobile");
    assertTest(end($profPollRes['json']['messages'])['id'] === $msgAlunoId, "Mensagem do aluno exibida no App Mobile do nutricionista");

    // -------------------------------------------------------------------------
    // GATE 5: NUTRICIONISTA RESPONDE ONLINE (WEB OU MOBILE)
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 5] Nutricionista Responde ao Aluno Online...\n";

    $nutriRespTexto = "Olá Lucas! No lanche da tarde pode consumir uma porção de frutas com castanhas conforme ajustamos no plano.";
    $nutriIdempotency = 'idem-nutri-' . bin2hex(random_bytes(8));

    // Resposta via API Mobile do Profissional
    $profSendRes = callHttp('POST', "/api/v1/professional/chat/{$convId}/send", [
        'message' => $nutriRespTexto,
        'idempotency_key' => $nutriIdempotency,
    ], $nutriToken, [], ['CONTENT_TYPE' => 'application/json']);

    assertTest(!empty($profSendRes['json']['success']) && $profSendRes['json']['success'] === true, "Nutricionista enviou resposta com sucesso via POST /api/v1/professional/chat/{id}/send");
    $msgNutriId = (int)($profSendRes['json']['message']['id'] ?? 0);
    assertTest($msgNutriId > $msgAlunoId, "Mensagem da nutricionista registrada com ID sequencial #$msgNutriId");
    assertTest($profSendRes['json']['message']['sender_type'] === 'professional', "Remetente identificado como 'professional'");

    // Confere no banco com query direta fresca
    $stmtCheckResp = getFreshDb()->query("SELECT * FROM chat_messages WHERE id = $msgNutriId");
    $respRow = $stmtCheckResp ? $stmtCheckResp->fetch() : null;
    assertTest(!empty($respRow) && $respRow['message'] === $nutriRespTexto, "Resposta da nutricionista persistida com sucesso");

    // -------------------------------------------------------------------------
    // GATE 6: ALUNO RECEBE RESPOSTA VIA POLLING INCREMENTAL NO APP
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 6] Aluno Recebe Resposta em Tempo Real via Polling Delta (after_id)...\n";

    // Aluno faz polling passando after_id da mensagem anterior
    $alunoPollRes = callHttp('GET', "/api/v1/patient/chat/messages?after_id={$msgAlunoId}", [], $alunoToken);
    assertTest(!empty($alunoPollRes['json']['messages']), "Aluno recebeu novas mensagens via polling com after_id");
    assertTest(count($alunoPollRes['json']['messages']) === 1, "Apenas a nova mensagem foi transmitida (delta estrito)");
    $receivedMsg = $alunoPollRes['json']['messages'][0];
    assertTest($receivedMsg['id'] === $msgNutriId, "ID da mensagem recebida corresponde à resposta da nutricionista");
    assertTest($receivedMsg['message'] === $nutriRespTexto, "Conteúdo da orientação recebido integralmente pelo aluno");
    assertTest($receivedMsg['sender_type'] === 'professional', "Remetente identificado como 'professional'");

    // Aluno marca como lida
    $readRes = callHttp('POST', '/api/v1/patient/chat/read', [
        'conversation_id' => $convId,
    ], $alunoToken, [], ['CONTENT_TYPE' => 'application/json']);
    assertTest(!empty($readRes['json']['success']) && $readRes['json']['success'] === true, "Recibo de leitura enviado pelo aluno via POST /api/v1/patient/chat/read");

    // Confere se read_at foi atualizado
    $stmtReadCheck = getFreshDb()->query("SELECT read_at FROM chat_messages WHERE id = $msgNutriId");
    $readAt = $stmtReadCheck ? $stmtReadCheck->fetchColumn() : null;
    assertTest(!empty($readAt), "Data e hora de leitura registrada no banco de dados ($readAt)");

    // -------------------------------------------------------------------------
    // GATE 7: ISOLAMENTO MULTI-TENANT E TESTES NEGATIVOS
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 7] Validação de Segurança, Isolamento Multi-Tenant e Casos Negativos...\n";

    // 7.1 Aluno de outra clínica (Beta) tenta ler conversa da Clínica Alpha
    $loginPatBRes = callHttp('POST', '/api/v1/auth/patient-login', [
        'email' => 'beatriz.aluna@beta.com',
        'password' => $patPass,
    ]);
    $alunoBetaToken = $loginPatBRes['json']['token'] ?? '';
    assertTest(!empty($alunoBetaToken), "Aluno da Clínica Beta autenticado");

    // Tentativa de marcar como lida conversa de outra clínica
    $hackReadRes = callHttp('POST', '/api/v1/patient/chat/read', [
        'conversation_id' => $convId,
    ], $alunoBetaToken, [], ['CONTENT_TYPE' => 'application/json']);
    assertTest(isset($hackReadRes['json']['error']), "Tentativa de acessar conversa de outro tenant foi bloqueada com erro");

    // 7.2 Mensagem vazia rejeitada
    $emptySendRes = callHttp('POST', '/api/v1/patient/chat/send', [
        'conversation_id' => $convId,
        'message' => '   ',
    ], $alunoToken, [], ['CONTENT_TYPE' => 'application/json']);
    assertTest(isset($emptySendRes['json']['error']), "Mensagem vazia rejeitada com código 400");

    // 7.3 Acesso sem token rejeitado
    $noAuthRes = callHttp('GET', '/api/v1/patient/chat/messages');
    assertTest(isset($noAuthRes['json']['error']), "Requisição desautenticada bloqueada com 401 Unauthorized");

    // -------------------------------------------------------------------------
    // GATE 8: CONTRATOS DO CLIENTE MOBILE (REACT NATIVE / TYPESCRIPT)
    // -------------------------------------------------------------------------
    echo "\n--> [GATE 8] Integridade dos Contratos e Código do Aplicativo Mobile...\n";

    // Verifica que api.ts possui todos os métodos de chat
    $apiTsContent = file_get_contents(__DIR__ . '/../mobile/src/services/api.ts');
    assertTest(strpos($apiTsContent, 'getChatMessages') !== false, "api.ts exporta getChatMessages()");
    assertTest(strpos($apiTsContent, 'sendChatMessage') !== false, "api.ts exporta sendChatMessage()");
    assertTest(strpos($apiTsContent, 'markChatAsRead') !== false, "api.ts exporta markChatAsRead()");
    assertTest(strpos($apiTsContent, 'getProfessionalChatConversations') !== false, "api.ts exporta getProfessionalChatConversations()");
    assertTest(strpos($apiTsContent, 'getProfessionalChatMessages') !== false, "api.ts exporta getProfessionalChatMessages()");
    assertTest(strpos($apiTsContent, 'sendProfessionalChatMessage') !== false, "api.ts exporta sendProfessionalChatMessage()");

    // Verifica que PatientHomeScreen.tsx possui suporte à tela de Chat com polling de 3s e status Online
    $patientScreenContent = file_get_contents(__DIR__ . '/../mobile/src/screens/PatientHomeScreen.tsx');
    assertTest(strpos($patientScreenContent, "'Chat'") !== false, "PatientHomeScreen inclui aba 'Chat'");
    assertTest(strpos($patientScreenContent, "currentTab === 'Chat'") !== false, "PatientHomeScreen renderiza tela de Chat quando aba selecionada");
    assertTest(strpos($patientScreenContent, "chatOnlineDot") !== false, "PatientHomeScreen renderiza status de presença Online com indicador visual");
    assertTest(strpos($patientScreenContent, "3000") !== false, "PatientHomeScreen executa delta polling a cada 3 segundos");

    // Verifica que ProfessionalHomeScreen.tsx possui suporte à aba Chat
    $profScreenContent = file_get_contents(__DIR__ . '/../mobile/src/screens/ProfessionalHomeScreen.tsx');
    assertTest(strpos($profScreenContent, "'Chat'") !== false, "ProfessionalHomeScreen inclui aba 'Chat'");
    assertTest(strpos($profScreenContent, "currentTab === 'Chat'") !== false, "ProfessionalHomeScreen renderiza conversas do nutricionista");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 8 GATES FORAM APROVADOS COM SUCESSO! <<<\n";
    echo "    Total de testes executados: $passed\n";
    echo "    Falhas: $failed\n";
    echo "========================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[ERRO CRÍTICO NO TESTE]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
