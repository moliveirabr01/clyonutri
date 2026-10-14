<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * SUÍTE E2E DE AUDITORIA E CONTRATO — NI2-256 (F01 / P0)
 * ==============================================================================
 * Rastreabilidade: Epic NI2-255 / Finding F01
 * Título: Corrigir propagação de papéis no login e acesso legítimo ao Service Desk
 *
 * Valida de ponta a ponta em código de produção e banco SQLite real:
 *  Gate 1: Operador autorizado abre fila após login normal e executa apenas ações permitidas.
 *  Gate 2: Nutricionista sem perfil de suporte é estritamente bloqueado em rotas operacionais (403).
 *  Gate 3: Papel não é alterável por payload, cookie editável ou query string (resolução canônica no DB).
 *  Gate 4: Revogação/alteração de papel e status tem efeito imediato (auth_version / fail-closed).
 *  Gate 5: Trilha de auditoria registra mudança de privilégios sem dados sensíveis/PHI.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;

echo "=== INICIANDO SUÍTE E2E NI2-256 (F01): RBAC, SESSÃO E SERVICE DESK ===\n";

function getTestDb(): PDO {
    \ClyoNutri\Core\Database\Connection::getInstance(); // resolve o banco de testes isolado e exporta DB_DATABASE
    $dbPath = getenv('DB_DATABASE') ?: __DIR__ . '/../storage/database.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 10000;');
    return $pdo;
}

$db = getTestDb();
Connection::setInstance($db);

$testRunId = time() . '_' . mt_rand(100, 999);
$clinicName = "Clínica RBAC Audit {$testRunId}";
$operatorEmail = "operador_{$testRunId}@clyonutri.com.br";
$operatorPassword = "Password!123456";
$nutriEmail = "nutri_regular_{$testRunId}@clyonutri.com.br";
$nutriPassword = "Password!123456";

// 1. Provisionar Clínica, Operador e Nutricionista no Banco Real
$db->beginTransaction();

$db->prepare("INSERT INTO clinics (uuid, name, email, status) VALUES (?, ?, 'clinic@teste.com', 'active')")
   ->execute(["clinic-rbac-{$testRunId}", $clinicName]);
$clinicId = (int)$db->lastInsertId();

// Usuário 1: Operador de Suporte / Service Desk
$opHash = password_hash($operatorPassword, PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, auth_version, status) VALUES (?, ?, ?, ?, 'operator', 1, 'active')")
   ->execute(["usr-op-{$testRunId}", "Operador Carlos", $operatorEmail, $opHash]);
$operatorUserId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'operator', 'active')")
   ->execute([$clinicId, $operatorUserId]);

// Usuário 2: Nutricionista Clínico Regular
$nutriHash = password_hash($nutriPassword, PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, auth_version, status) VALUES (?, ?, ?, ?, 'nutritionist', 1, 'active')")
   ->execute(["usr-nutri-{$testRunId}", "Dra. Helena Nutri", $nutriEmail, $nutriHash]);
$nutriUserId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'nutritionist', 'active')")
   ->execute([$clinicId, $nutriUserId]);

$db->prepare("INSERT INTO professionals (user_id, crn, crn_region, specialty) VALUES (?, 'CRN-3 99112', 'CRN-3', 'Nutrição Clínica')")
   ->execute([$nutriUserId]);

// Ticket de Suporte para teste da Fila
$ticketService = new HelpdeskTicketService($db);
$createdTicket = $ticketService->createTicket(
    $clinicId,
    $nutriUserId,
    "Problema ao salvar refeição personalizada",
    'acesso_login',
    'question',
    "Não estou conseguindo adicionar ingrediente customizado no plano."
);
$ticketId = (int)$createdTicket['id'];
$ticketProtocol = $createdTicket['protocol'];

$db->commit();
echo "✓ Infraestrutura provisionada: Clínica #{$clinicId}, Operador #{$operatorUserId}, Nutricionista #{$nutriUserId}, Ticket #{$ticketId}\n";

// Helper HTTP isolado para execução de requests web com sessão real
function execHttpRequest(
    string $method,
    string $uri,
    array $postData = [],
    array $sessionData = [],
    array $headers = []
): array {
    if ($method === 'POST') {
        $csrfToken = $sessionData['_clyonutri_csrf_token'] ?? bin2hex(random_bytes(32));
        $sessionData['_clyonutri_csrf_token'] = $csrfToken;
        if (!isset($postData['_csrf_token'])) {
            $postData['_csrf_token'] = $csrfToken;
        }
    }
    $script = '<?php
        namespace ClyoNutri\Application\Controllers {
            function header(string $h, bool $r = true, int $c = 0): void {
                $GLOBALS["__captured_headers"][] = $h;
            }
        }
        namespace {
            $GLOBALS["__captured_headers"] = [];
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION = ' . var_export($sessionData, true) . ';
            $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
            $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
            $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
            $_SERVER["HTTP_USER_AGENT"] = "PHPUnit/E2E-Runner";
            ' . (!empty($headers['HTTP_X_REQUESTED_WITH']) ? '$_SERVER["HTTP_X_REQUESTED_WITH"] = ' . var_export($headers['HTTP_X_REQUESTED_WITH'], true) . ';' : '') . '
            ' . (!empty($headers['HTTP_ACCEPT']) ? '$_SERVER["HTTP_ACCEPT"] = ' . var_export($headers['HTTP_ACCEPT'], true) . ';' : '') . '
            $parsed = parse_url(' . var_export($uri, true) . ');
            if (!empty($parsed["query"])) { parse_str($parsed["query"], $_GET); } else { $_GET = []; }
            $_POST = ' . var_export($postData, true) . ';
            register_shutdown_function(function() {
                echo "\n___SESSION_STATE___" . json_encode($_SESSION) . "___SESSION_STATE___\n";
                echo "\n___HTTP_CODE___" . http_response_code() . "___HTTP_CODE___\n";
                $allHeaders = array_merge(headers_list(), $GLOBALS["__captured_headers"] ?? []);
                echo "\n___HEADERS_STATE___" . json_encode($allHeaders) . "___HEADERS_STATE___\n";
            });
            require "public/index.php";
        }
    ';

    $tempScript = __DIR__ . '/../storage/temp_rbac_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($tempScript, $script);

    $envPrefix = '';
    if (getenv('DB_DATABASE')) {
        $envPrefix .= 'DB_DATABASE=' . escapeshellarg(getenv('DB_DATABASE')) . ' ';
    }
    if (getenv('DB_CONNECTION')) {
        $envPrefix .= 'DB_CONNECTION=' . escapeshellarg(getenv('DB_CONNECTION')) . ' ';
    }
    $cmd = $envPrefix . "php -d display_errors=0 -d error_reporting=0 " . escapeshellarg($tempScript) . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($tempScript);

    $status = 200;
    if (preg_match('/___HTTP_CODE___(\d+)___HTTP_CODE___/', (string)$output, $m)) {
        $status = (int)$m[1];
        $output = preg_replace('/___HTTP_CODE___\d+___HTTP_CODE___/', '', (string)$output);
    }

    $outSession = [];
    if (preg_match('/___SESSION_STATE___(.*?)___SESSION_STATE___/s', (string)$output, $sm)) {
        $outSession = json_decode($sm[1], true) ?: [];
        $output = preg_replace('/___SESSION_STATE___.*?___SESSION_STATE___/s', '', (string)$output);
    }

    $outHeaders = [];
    if (preg_match('/___HEADERS_STATE___(.*?)___HEADERS_STATE___/s', (string)$output, $hm)) {
        $outHeaders = json_decode($hm[1], true) ?: [];
        $output = preg_replace('/___HEADERS_STATE___.*?___HEADERS_STATE___/s', '', (string)$output);
    }

    $trimmed = trim((string)$output);
    $location = null;
    foreach ($outHeaders as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }

    return [
        'status' => $status,
        'session' => $outSession,
        'location' => $location,
        'headers' => $outHeaders,
        'body' => $trimmed,
    ];
}

// =========================================================================
// GATE 1: Operador autorizado abre fila após login normal e executa ações permitidas
// =========================================================================
echo "\n--- GATE 1: OPERADOR AUTORIZADO (LOGIN, FILA E LIMITES) ---\n";

// 1.1 Login do Operador
$opLogin = execHttpRequest('POST', '/login', [
    'email' => $operatorEmail,
    'password' => $operatorPassword,
]);

assert($opLogin['location'] === '/suporte/fila', "Operador não foi redirecionado para /suporte/fila! Obtido: {$opLogin['location']}");
assert($opLogin['session']['user_role'] === 'operator', "Papel de operador não foi propagado na sessão!");
assert($opLogin['session']['user_id'] === $operatorUserId, "ID de usuário divergente na sessão");
echo "✓ Login de operador propagou papel 'operator' e redirecionou para /suporte/fila com sucesso\n";

$opSession = $opLogin['session'];

// 1.2 Operador acessa a Fila do Service Desk
$queueResp = execHttpRequest('GET', '/suporte/fila', [], $opSession);
assert($queueResp['status'] === 200, "Operador não conseguiu abrir a fila (Status {$queueResp['status']})");
assert(str_contains($queueResp['body'], 'Fila do Service Desk'), "Título da fila não encontrado na resposta");
assert(str_contains($queueResp['body'], $ticketProtocol), "Ticket #{$ticketProtocol} não visível na fila do operador");
echo "✓ Operador autorizado abriu a fila e visualizou chamados pendentes (HTTP 200)\n";

// 1.3 Operador abre os detalhes do chamado
$ticketResp = execHttpRequest('GET', "/suporte/chamado/{$ticketId}", [], $opSession);
assert($ticketResp['status'] === 200, "Operador não conseguiu abrir chamado {$ticketId} (Status {$ticketResp['status']})");
assert(str_contains($ticketResp['body'], $ticketProtocol), "Protocolo do chamado não renderizado na tela de atendimento");
assert(str_contains($ticketResp['body'], 'Atualizar Status'), "Botão de status não renderizado na tela de atendimento");
echo "✓ Operador abriu chamado individual no backoffice (HTTP 200)\n";

// 1.4 Operador tenta acessar rotas clínicas protegidas (Ex: /dashboard, /pacientes)
$dashOp = execHttpRequest('GET', '/dashboard', [], $opSession, ['HTTP_ACCEPT' => 'application/json']);
assert($dashOp['status'] === 403, "Operador não deve acessar dashboard clínico! Esperava 403, obtido {$dashOp['status']}");

$patOp = execHttpRequest('GET', '/pacientes', [], $opSession, ['HTTP_ACCEPT' => 'application/json']);
assert($patOp['status'] === 403, "Operador não deve acessar prontuários/pacientes clínicos! Esperava 403, obtido {$patOp['status']}");
echo "✓ Operador bloqueado estritamente em rotas clínicas (HTTP 403 nas rotas /dashboard e /pacientes)\n";
echo "✓ GATE 1 PASS: Operador autorizado atua no Service Desk e é impedido em rotas clínicas!\n";

// =========================================================================
// GATE 2: Nutricionista sem perfil de suporte não acessa rotas operacionais
// =========================================================================
echo "\n--- GATE 2: NUTRICIONISTA SEM PERFIL DE SUPORTE BLOQUEADO EM ROTAS OPERACIONAIS ---\n";

// 2.1 Login da Nutricionista
$nutriLogin = execHttpRequest('POST', '/login', [
    'email' => $nutriEmail,
    'password' => $nutriPassword,
]);
assert($nutriLogin['location'] === '/dashboard', "Nutricionista não redirecionada para /dashboard! Obtido: {$nutriLogin['location']}");
assert($nutriLogin['session']['user_role'] === 'nutritionist', "Papel de nutricionista não propagado");
$nutriSession = $nutriLogin['session'];

// 2.2 Nutricionista acessa o Dashboard clínico com sucesso
$nutriDash = execHttpRequest('GET', '/dashboard', [], $nutriSession);
assert($nutriDash['status'] === 200, "Nutricionista não conseguiu acessar seu dashboard");
echo "✓ Nutricionista autenticada acessa seu dashboard clínico legítimo (HTTP 200)\n";

// 2.3 Nutricionista tenta acessar rotas operacionais (/suporte/fila, /suporte/chamado/{id})
$nutriQueue = execHttpRequest('GET', '/suporte/fila', [], $nutriSession, ['HTTP_ACCEPT' => 'application/json']);
assert($nutriQueue['status'] === 403, "Nutricionista NÃO deve acessar /suporte/fila! Esperava 403, obtido {$nutriQueue['status']}");

$nutriTicket = execHttpRequest('GET', "/suporte/chamado/{$ticketId}", [], $nutriSession, ['HTTP_ACCEPT' => 'application/json']);
assert($nutriTicket['status'] === 403, "Nutricionista NÃO deve acessar /suporte/chamado! Esperava 403, obtido {$nutriTicket['status']}");

$nutriReply = execHttpRequest('POST', "/suporte/chamado/{$ticketId}/responder", ['message' => 'Tentativa indevida'], $nutriSession, ['HTTP_ACCEPT' => 'application/json']);
assert($nutriReply['status'] === 403, "Nutricionista NÃO deve responder chamado como backoffice! Esperava 403, obtido {$nutriReply['status']}");
echo "✓ Nutricionista bloqueada com 403 Forbidden em todas as rotas operacionais do Service Desk\n";
echo "✓ GATE 2 PASS: Isolamento de privilégios clínicos vs operacionais comprovado!\n";

// =========================================================================
// GATE 3: Papel não é alterável por payload, cookie editável ou query string
// =========================================================================
echo "\n--- GATE 3: IMUTABILIDADE DO PAPEL CONTRA MANIPULAÇÃO DO CLIENTE ---\n";

// 3.1 Atacante envia sessão com user_role adulterado para 'operator' via sessão/cookie adulterado
$tamperedSession = $nutriSession;
$tamperedSession['user_role'] = 'operator'; // Tentativa de elevação local de privilégio

$tamperResp = execHttpRequest('GET', '/suporte/fila', [], $tamperedSession, ['HTTP_ACCEPT' => 'application/json']);
assert($tamperResp['status'] === 403, "Falha de segurança! Role forjada na sessão permitiu acesso!");
// Confirmar que o AuthContext sobrescreveu o papel adulterado pelo canônico do banco
assert($tamperResp['session']['user_role'] === 'nutritionist', "Papel forjado não foi corrigido pelo banco de dados canônico!");

// 3.2 Tentativa de injetar role=admin ou role=operator via Query String e POST
$tamperQuery = execHttpRequest('GET', '/suporte/fila?role=operator', [], $nutriSession, ['HTTP_ACCEPT' => 'application/json']);
assert($tamperQuery['status'] === 403, "Query string alterou o papel de autorização!");

$tamperPost = execHttpRequest('POST', "/suporte/chamado/{$ticketId}/responder", ['role' => 'operator', 'message' => 'Injeção'], $nutriSession, ['HTTP_ACCEPT' => 'application/json']);
assert($tamperPost['status'] === 403, "Payload POST alterou o papel de autorização!");
echo "✓ AuthContext resolveu exclusivamente do SQLite canônico, ignorando tentativas de injeção em sessão, query string e body\n";
echo "✓ GATE 3 PASS: Papel do usuário blindado contra manipulação de cliente!\n";

// =========================================================================
// GATE 4: Revogação e alteração dinâmica de papel (auth_version e status)
// =========================================================================
echo "\n--- GATE 4: REVOGAÇÃO E ALTERAÇÃO DINÂMICA DE PAPEL ---\n";

$authService = new AuthService(getTestDb());

// 4.1 Elevação legítima de privilégio pelo administrador (Nutricionista vira Support Agent)
$authService->updateUserRole($nutriUserId, 'support_agent', $operatorUserId);

// A sessão antiga da Nutricionista (com auth_version = 1) agora deve ser rejeitada imediatamente
$oldSessionAttempt = execHttpRequest('GET', '/dashboard', [], $nutriSession, ['HTTP_ACCEPT' => 'application/json']);
assert(in_array($oldSessionAttempt['status'], [401, 403], true), "Sessão anterior com auth_version desatualizada não retornou 401/403!");
assert(empty($oldSessionAttempt['session']['user_id']), "Sessão não foi destruída na invalidação!");
echo "✓ Sessão anterior invalidada imediatamente após alteração de papel (auth_version desatualizada)\n";

// 4.2 Novo login do usuário com seu novo papel de 'support_agent'
$newLogin = execHttpRequest('POST', '/login', [
    'email' => $nutriEmail,
    'password' => $nutriPassword,
]);
assert($newLogin['location'] === '/suporte/fila', "Novo papel não redirecionou para /suporte/fila! Obtido: {$newLogin['location']}");
assert($newLogin['session']['user_role'] === 'support_agent', "Novo papel não propagado");
$supportSession = $newLogin['session'];

// Agora o usuário pode acessar a fila de suporte
$supportQueue = execHttpRequest('GET', '/suporte/fila', [], $supportSession);
assert($supportQueue['status'] === 200, "Usuário promovido a support_agent não conseguiu abrir fila");
echo "✓ Re-autenticação após promoção de papel concedeu acesso à fila do Service Desk\n";

// 4.3 Desativação do usuário (status = 'inactive')
$db = getTestDb();
$db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$nutriUserId]);

$inactiveAttempt = execHttpRequest('GET', '/suporte/fila', [], $supportSession, ['HTTP_ACCEPT' => 'application/json']);
assert(in_array($inactiveAttempt['status'], [401, 403], true), "Usuário inativo não foi bloqueado com 401/403!");
assert(empty($inactiveAttempt['session']['user_id']), "Sessão do usuário inativo não foi limpa!");
echo "✓ Usuário desativado no banco bloqueado imediatamente (fail-closed)\n";
echo "✓ GATE 4 PASS: Revogação e alteração dinâmica de privilégios validadas em tempo real!\n";

// =========================================================================
// GATE 5: Trilha de auditoria registra mudança de privilégios sem dados sensíveis
// =========================================================================
echo "\n--- GATE 5: AUDITORIA DE MUDANÇA DE PRIVILÉGIOS (SEM DADOS SENSÍVEIS) ---\n";

$db = getTestDb();
$auditStmt = $db->prepare("
    SELECT action, resource, resource_id, details, created_at
    FROM audit_logs
    WHERE resource = 'users' AND resource_id = ? AND action = 'USER_ROLE_CHANGED'
    ORDER BY id DESC LIMIT 1
");
$auditStmt->execute([(string)$nutriUserId]);
$auditRecord = $auditStmt->fetch();

assert(!empty($auditRecord), "Registro de auditoria USER_ROLE_CHANGED não encontrado!");
$auditDetails = json_decode($auditRecord['details'], true);

assert($auditDetails['old_role'] === 'nutritionist', "old_role divergente na auditoria");
assert($auditDetails['new_role'] === 'support_agent', "new_role divergente na auditoria");
assert($auditDetails['changed_by'] === $operatorUserId, "changed_by divergente na auditoria");

// Checar ausência de senhas, hashes ou tokens no JSON de detalhes
assert(!isset($auditDetails['password']), "Vazamento de senha em audit_logs!");
assert(!isset($auditDetails['password_hash']), "Vazamento de hash em audit_logs!");
assert(!isset($auditDetails['token']), "Vazamento de token em audit_logs!");

echo "✓ Evento USER_ROLE_CHANGED registrado com integridade e sem dados sensíveis:\n";
echo "  " . json_encode($auditDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "✓ GATE 5 PASS: Trilha de auditoria atende aos requisitos de conformidade e segurança!\n";

echo "\n============================================================\n";
echo "TODOS OS 5 GATES DA HISTÓRIA NI2-256 (F01 / P0) PASSARAM COM SUCESSO!\n";
echo "============================================================\n";
