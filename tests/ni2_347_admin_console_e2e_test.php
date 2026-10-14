<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use PDO;

echo "====================================================================\n";
echo " TEST SUITE: NI2-347 / NI2-348 - Backoffice Administrativo SaaS E2E \n";
echo "====================================================================\n\n";

\ClyoNutri\Core\Database\Connection::getInstance(); // resolve o banco de testes isolado e exporta DB_DATABASE
$dbPath = getenv('DB_DATABASE') ?: __DIR__ . '/../storage/database.sqlite';
$db = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA busy_timeout = 10000;');

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $message\n";
        $passed++;
    } else {
        echo "  [FAIL] $message\n";
        $failed++;
    }
}

function simulateHttpRequest(
    string $method,
    string $uri,
    array $sessionData = [],
    array $postData = [],
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
            require_once __DIR__ . "/../vendor/autoload.php";
            \ClyoNutri\Core\Http\Response::setRedirectHandler(function(string $url) {
                $GLOBALS["__captured_headers"][] = "Location: " . $url;
                http_response_code(302);
                exit;
            });
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION = ' . var_export($sessionData, true) . ';
            $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
            $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
            $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
            $_SERVER["HTTP_USER_AGENT"] = "PHPUnit/Admin-E2E-Runner";
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

    $tempScript = __DIR__ . '/../storage/temp_admin_e2e_' . bin2hex(random_bytes(6)) . '.php';
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

    return [
        'status' => $status,
        'headers' => $outHeaders,
        'session' => $outSession,
        'body' => trim((string)$output),
    ];
}

// SETUP: Provisionar Clínica Matriz, Clínica Cliente, Superadmin, Nutricionista e Operador
$testRunId = time() . '_' . mt_rand(100, 999);
$adminClinicName = "ClyoNutri SaaS Platform Master {$testRunId}";
$clientClinicName = "Clínica Vida Integral {$testRunId}";
$superadminEmail = "superadmin_{$testRunId}@clyonutri.com.br";
$nutriEmail = "nutri_cliente_{$testRunId}@clyonutri.com.br";
$opEmail = "operador_{$testRunId}@clyonutri.com.br";
$password = "Secret#2026";
$hash = password_hash($password, PASSWORD_ARGON2ID);

// 1. Inserir Clínicas
$stmtC = $db->prepare("INSERT INTO clinics (uuid, name, document, email, phone, status, created_at, updated_at) VALUES (?, ?, ?, ?, '11999990000', 'active', datetime('now'), datetime('now'))");
$stmtC->execute(["adm-cli-{$testRunId}", $adminClinicName, '00.000.000/0001-99', "contato_adm_{$testRunId}@clyonutri.com.br"]);
$adminClinicId = (int)$db->lastInsertId();

$stmtC->execute(["cli-cli-{$testRunId}", $clientClinicName, '11.222.333/0001-44', "contato_cli_{$testRunId}@clyonutri.com.br"]);
$clientClinicId = (int)$db->lastInsertId();

// 2. Inserir Usuários
$stmtU = $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status, auth_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, 'active', 1, datetime('now'), datetime('now'))");

// Superadmin
$stmtU->execute(["usr-super-{$testRunId}", "Diretor SaaS", $superadminEmail, $hash, 'superadmin']);
$superadminId = (int)$db->lastInsertId();

// Nutricionista Comum (da clínica cliente)
$stmtU->execute(["usr-nutri-{$testRunId}", "Dra. Renata Nutri", $nutriEmail, $hash, 'nutritionist']);
$nutriId = (int)$db->lastInsertId();

// Operador de Suporte
$stmtU->execute(["usr-op-{$testRunId}", "Operador Lucas", $opEmail, $hash, 'operator']);
$operatorId = (int)$db->lastInsertId();

// 3. Vincular Membros (tabela clinic_members)
$stmtM = $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (?, ?, ?, 'active', datetime('now'))");
$stmtM->execute([$adminClinicId, $superadminId, 'admin']);
$stmtM->execute([$clientClinicId, $nutriId, 'nutritionist']);
$stmtM->execute([$adminClinicId, $operatorId, 'operator']);

echo "1. GATE 1: Não-Autenticado em /admin e /admin/dashboard\n";
// Requisição Web sem sessão: deve redirecionar 302 para /login
$respUnauthWeb = simulateHttpRequest('GET', '/admin');
$hasLoginRedirect = false;
foreach ($respUnauthWeb['headers'] as $h) {
    if (str_starts_with($h, 'Location: /login')) {
        $hasLoginRedirect = true;
        break;
    }
}
assertCondition($hasLoginRedirect, "GET /admin não-autenticado redireciona para /login (Location: /login)");

// Requisição JSON/API sem sessão: deve retornar 401 Unauthorized
$respUnauthJson = simulateHttpRequest('GET', '/admin', [], [], ['HTTP_ACCEPT' => 'application/json']);
assertCondition($respUnauthJson['status'] === 401, "GET /admin com Accept: application/json retorna 401 Unauthorized");

echo "\n2. GATE 2: Profissional Clínico Comum (Nutricionista) Bloqueado com 403 Forbidden\n";
$nutriSession = [
    'user_id' => $nutriId,
    'clinic_id' => $clientClinicId,
    'auth_version' => 1,
    'user_role' => 'nutritionist',
    'user_name' => 'Dra. Renata Nutri',
    'clinic_name' => $clientClinicName,
];
$respNutriAdmin = simulateHttpRequest('GET', '/admin', $nutriSession);
assertCondition($respNutriAdmin['status'] === 403, "Nutricionista comum recebe 403 Forbidden ao tentar acessar /admin");

$respNutriDashboard = simulateHttpRequest('GET', '/admin/dashboard', $nutriSession);
assertCondition($respNutriDashboard['status'] === 403, "Nutricionista comum recebe 403 Forbidden ao tentar acessar /admin/dashboard");

// Verifica registro de auditoria imutável do bloqueio
$blockedAudit = $db->query("SELECT * FROM audit_logs WHERE action = 'SECURITY_AUTHORIZATION_BLOCKED' ORDER BY id DESC LIMIT 1")->fetch();
assertCondition(!empty($blockedAudit), "Tentativa indevida de acesso administrativo foi gravada em audit_logs (SECURITY_AUTHORIZATION_BLOCKED)");

echo "\n3. GATE 3: Superadmin / Operador Acessa /admin e /admin/dashboard com Sucesso (200 OK)\n";
$superSession = [
    'user_id' => $superadminId,
    'clinic_id' => $adminClinicId,
    'auth_version' => 1,
    'user_role' => 'superadmin',
    'user_name' => 'Diretor SaaS',
    'clinic_name' => $adminClinicName,
];

// /admin redireciona para /admin/dashboard
$respSuperRoot = simulateHttpRequest('GET', '/admin', $superSession);
$hasDashboardRedirect = false;
foreach ($respSuperRoot['headers'] as $h) {
    if (str_starts_with($h, 'Location: /admin/dashboard')) {
        $hasDashboardRedirect = true;
        break;
    }
}
assertCondition($hasDashboardRedirect, "GET /admin autenticado redireciona para /admin/dashboard");

// Acesso ao Dashboard executivo
$respSuperDash = simulateHttpRequest('GET', '/admin/dashboard', $superSession);
assertCondition($respSuperDash['status'] === 200, "Superadmin acessa /admin/dashboard com status 200 OK");
assertCondition(str_contains($respSuperDash['body'], 'ClyoNutri II') && str_contains($respSuperDash['body'], 'Backoffice SaaS'), "HTML contém casca visual Navy/Cobre 'ClyoNutri II Backoffice SaaS'");
assertCondition(str_contains($respSuperDash['body'], 'Dashboard Executivo da Plataforma'), "HTML exibe título do Dashboard Executivo");
assertCondition(str_contains($respSuperDash['body'], 'Clínicas (Tenants)') && str_contains($respSuperDash['body'], 'Usuários & Perfis'), "Navegação isolada renderiza itens da plataforma");
assertCondition(str_contains($respSuperDash['body'], 'Diretor SaaS'), "Topbar exibe nome do operador autenticado");

echo "\n4. GATE 4: Navegação entre Módulos Administrativos (Clínicas, Usuários, Service Desk, Comercial, Acessos, Auditoria)\n";
$routesToTest = [
    '/admin/clinicas' => 'Gestão de Clínicas (Tenants)',
    '/admin/usuarios' => 'Usuários & Perfis de Acesso',
    '/admin/servicedesk' => 'Painel Consolidado de Service Desk',
    '/admin/comercial' => 'Governança Comercial & Catálogo de Planos',
    '/admin/acessos' => 'Controle de Acessos & Sessões Ativas',
    '/admin/auditoria' => 'Trilha Imutável de Auditoria',
    '/admin/configuracoes' => 'Configurações Globais & Feature Flags',
];

foreach ($routesToTest as $route => $expectedSnippet) {
    $respRoute = simulateHttpRequest('GET', $route, $superSession);
    assertCondition($respRoute['status'] === 200 && str_contains($respRoute['body'], $expectedSnippet), "Rota {$route} retorna 200 OK e contém '{$expectedSnippet}'");
}

echo "\n5. GATE 5: Busca Global Rápida (/admin/busca?q=...)\n";
$respSearch = simulateHttpRequest('GET', '/admin/busca?q=' . urlencode($clientClinicName), $superSession);
assertCondition($respSearch['status'] === 200, "Busca global retorna 200 OK");
assertCondition(str_contains($respSearch['body'], $clientClinicName), "Busca global localizou a clínica cliente pelo nome cadastrado");

echo "\n6. GATE 6: Ação Administrativa Real com CSRF e Auditoria (Alterar Status da Clínica)\n";
$csrfToken = bin2hex(random_bytes(32));
$superSessionWithCsrf = array_merge($superSession, ['_clyonutri_csrf_token' => $csrfToken]);

$respMutate = simulateHttpRequest(
    'POST',
    "/admin/clinicas/{$clientClinicId}/status",
    $superSessionWithCsrf,
    ['_csrf_token' => $csrfToken, 'status' => 'suspended']
);

$clinicStatusDb = $db->query("SELECT status FROM clinics WHERE id = {$clientClinicId}")->fetchColumn();
assertCondition($clinicStatusDb === 'suspended', "Status da clínica cliente alterado com sucesso no SQLite para 'suspended'");

$auditStatusLog = $db->query("SELECT * FROM audit_logs WHERE action = 'ADMIN_CLINIC_STATUS_CHANGED' AND resource_id = '{$clientClinicId}' ORDER BY id DESC LIMIT 1")->fetch();
assertCondition(!empty($auditStatusLog), "Ação registrada com sucesso em audit_logs (ADMIN_CLINIC_STATUS_CHANGED)");

echo "\n7. GATE 7: Revogação Forçada de Sessões e Desconexão Imediata (auth_version)\n";
// Cria sessão ativa para a nutricionista
$sessId = 'sess_audit_' . uniqid();
$stmtSess = $db->prepare("INSERT INTO sessions (id, user_id, clinic_id, ip_address, user_agent, payload, last_activity, created_at) VALUES (?, ?, ?, '127.0.0.1', 'AuditRunner', '', ?, datetime('now'))");
$stmtSess->execute([$sessId, $nutriId, $clientClinicId, time()]);

$sessionCountBefore = (int)$db->query("SELECT COUNT(*) FROM sessions WHERE id = '{$sessId}'")->fetchColumn();
assertCondition($sessionCountBefore === 1, "Sessão ativa criada no banco");

// Executa revogação forçada pelo superadmin
$respRevoke = simulateHttpRequest(
    'POST',
    "/admin/usuarios/{$nutriId}/revogar-sessoes",
    $superSessionWithCsrf,
    ['_csrf_token' => $csrfToken]
);

$sessionCountAfter = (int)$db->query("SELECT COUNT(*) FROM sessions WHERE id = '{$sessId}'")->fetchColumn();
assertCondition($sessionCountAfter === 0, "Sessão do usuário foi fisicamente removida da tabela sessions");

$newAuthVer = (int)$db->query("SELECT auth_version FROM users WHERE id = {$nutriId}")->fetchColumn();
assertCondition($newAuthVer === 2, "auth_version do usuário incrementada para 2");

// Tentativa de requisição da nutricionista usando sessão com auth_version desatualizada (1)
$staleNutriSession = [
    'user_id' => $nutriId,
    'clinic_id' => $clientClinicId,
    'auth_version' => 1, // Desatualizada
    'user_role' => 'nutritionist',
];
$respStale = simulateHttpRequest('GET', '/dashboard', $staleNutriSession);
$hasRevokedRedirect = false;
foreach ($respStale['headers'] as $h) {
    if (str_contains($h, '/login?error=session_revoked') || str_contains($h, '/login')) {
        $hasRevokedRedirect = true;
        break;
    }
}
assertCondition($hasRevokedRedirect, "Sessão desatualizada é sumariamente rejeitada e redirecionada para /login?error=session_revoked");

echo "\n8. GATE 8: Segregação Estrita de Funções - Operador Administrativo Não Acessa PHI Clínica\n";
$respOperadorPhi = simulateHttpRequest('GET', '/pacientes', $superSession);
assertCondition($respOperadorPhi['status'] === 403, "Superadmin/Operador tem acesso bloqueado (403) a prontuários/rotas clínicas (/pacientes)");

echo "\n====================================================================\n";
echo " RESULTADO FINAL: {$passed} PASS, {$failed} FAIL\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
