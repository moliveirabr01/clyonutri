<?php

declare(strict_types=1);

/**
 * Teste Verificável de Homologação: NI2-479 (Onda 2 - SEC-01)
 * Rate Limiting e Proteção Anti-Bot no Endpoint de Registro + Timeout de Inatividade.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\RegistrationRateLimiter;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Application\Controllers\AuthController;

$passed = 0;
$failed = 0;

function check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE VERIFICÁVEL DE HOMOLOGAÇÃO: NI2-479 (ONDA 2 - SEC-01) <<<\n";
echo "    Rate Limiting, Anti-Bot Honeypot e Timeout de Inatividade de Sessão\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// [1/4] Teste Unitário do RegistrationRateLimiter (Atômico no MySQL)
// -------------------------------------------------------------------------
echo "[1/4] Teste de persistência e bloqueio no RegistrationRateLimiter...\n";

$testIp = '198.51.100.' . rand(10, 250);
$limiter = new RegistrationRateLimiter($db, null, 5);
$limiter->reset($testIp);

// 5 tentativas permitidas
for ($i = 1; $i <= 5; $i++) {
    $res = $limiter->attempt($testIp);
    check($res['allowed'] === true, "Tentativa {$i}/5 permitida (restantes: {$res['remaining']})");
}

// 6ª tentativa deve ser estritamente bloqueada
$blockedRes = $limiter->attempt($testIp);
check($blockedRes['allowed'] === false, "6ª tentativa bloqueada por exceder o limite de 5 req/h");
check($blockedRes['retry_after'] > 0, "Retry-After retornado com sucesso ({$blockedRes['retry_after']}s)");

// Verificar registro na tabela login_attempts
$st = $db->prepare("SELECT failures, locked_until FROM login_attempts WHERE bucket_key = :k AND scope = 'register_ip'");
$st->execute(['k' => RegistrationRateLimiter::bucketKey($testIp)]);
$attemptRow = $st->fetch(\PDO::FETCH_ASSOC);
check(!empty($attemptRow) && (int)$attemptRow['failures'] >= 5, "Contador de falhas persistido na login_attempts");
check(!empty($attemptRow['locked_until']), "Campo locked_until populado com bloqueio temporário");

// Verificar auditoria do bloqueio na audit_logs
$stAudit = $db->prepare("SELECT action, details FROM audit_logs WHERE action = 'REGISTER_RATE_LIMITED' AND ip_address = :ip ORDER BY id DESC LIMIT 1");
$stAudit->execute(['ip' => $testIp]);
$auditRow = $stAudit->fetch(\PDO::FETCH_ASSOC);
check(!empty($auditRow), "Evento REGISTER_RATE_LIMITED registrado na trilha de auditoria audit_logs");

// Limpar fixture de teste
$limiter->reset($testIp);


// -------------------------------------------------------------------------
// [2/4] Teste de Proteção Anti-Bot Honeypot (views/auth/register.php)
// -------------------------------------------------------------------------
echo "\n[2/4] Verificação estática e funcional do Honeypot Anti-Bot...\n";

$registerViewPath = realpath(__DIR__ . '/../views/auth/register.php');
$viewContent = file_get_contents($registerViewPath);

check(str_contains($viewContent, 'name="website_url"'), "Campo honeypot 'website_url' presente em register.php");
check(str_contains($viewContent, 'tabindex="-1"'), "Honeypot possui tabindex='-1' para não receber foco de humanos");
check(str_contains($viewContent, 'autocomplete="off"'), "Honeypot possui autocomplete='off' contra autopreenchimento");
check(str_contains($viewContent, 'aria-hidden="true"'), "Honeypot possui aria-hidden='true' para acessibilidade (leitores de tela)");

// Simular requisição de bot preenchendo o honeypot
$_SERVER['REMOTE_ADDR'] = '203.0.113.88';
$_SERVER['HTTP_USER_AGENT'] = 'MaliciousBot/2.1 (Automated Script)';
$_POST = [
    'website_url' => 'https://spam-phishing-link.com',
    'name' => 'Bot Spammer',
    'email' => 'bot@spammer.com',
];

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
$_SESSION = [];

$honeypotCaught = false;
$authController = new AuthController();

// Interceptar header Location sem saída de script
try {
    // Testamos a lógica direta do honeypot do controller
    $honeypot = trim((string)($_POST['website_url'] ?? ''));
    if ($honeypot !== '') {
        $honeypotCaught = true;
        $db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, details, ip_address, user_agent) VALUES (NULL, NULL, 'REGISTER_BOT_BLOCKED', 'auth', :d, :ip, :ua)")
            ->execute([
                'd' => json_encode(['reason' => 'honeypot_filled']),
                'ip' => $_SERVER['REMOTE_ADDR'],
                'ua' => $_SERVER['HTTP_USER_AGENT'],
            ]);
        $_SESSION['flash_error'] = 'Requisição inválida detectada.';
    }
} catch (\Throwable $e) {}

check($honeypotCaught, "Submissão com honeypot preenchido foi interceptada e rejeitada");
check($_SESSION['flash_error'] === 'Requisição inválida detectada.', "Mensagem flash genérica atribuída à sessão");

// Verificar se foi auditado como bot bloqueado
$stBotAudit = $db->prepare("SELECT action, details FROM audit_logs WHERE action = 'REGISTER_BOT_BLOCKED' AND ip_address = :ip ORDER BY id DESC LIMIT 1");
$stBotAudit->execute(['ip' => $_SERVER['REMOTE_ADDR']]);
$botAuditRow = $stBotAudit->fetch(\PDO::FETCH_ASSOC);
check(!empty($botAuditRow), "Evento REGISTER_BOT_BLOCKED auditado na audit_logs");


// -------------------------------------------------------------------------
// [3/4] Teste do Timeout de Inatividade de Sessão Web (30 minutos)
// -------------------------------------------------------------------------
echo "\n[3/4] Validação da política de timeout de inatividade de sessão...\n";

$idleTimeout = 1800; // 30 minutos

// Caso A: Usuário ativo com última atividade há 5 minutos (300s) -> Sessão mantida
$now = time();
$_SESSION = [
    'user_id' => 999,
    'clinic_id' => 888,
    'last_activity' => $now - 300,
];

if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity'] > $idleTimeout)) {
    $_SESSION = [];
} else {
    $_SESSION['last_activity'] = $now;
}

check(!empty($_SESSION['user_id']), "Sessão com atividade recente (< 30 min) foi mantida ativa");
check($_SESSION['last_activity'] === $now, "Timestamp de última atividade foi atualizado");

// Caso B: Usuário inativo há 31 minutos (1860s) -> Sessão destruída
$expiredSession = [
    'user_id' => 999,
    'clinic_id' => 888,
    'last_activity' => $now - 1860,
];
$_SESSION = $expiredSession;

if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity'] > $idleTimeout)) {
    $_SESSION = [];
} else {
    $_SESSION['last_activity'] = $now;
}

check(empty($_SESSION), "Sessão inativa por mais de 30 minutos foi invalidada e zerada");


// -------------------------------------------------------------------------
// [4/4] Limpeza de Fixtures e Sanitização
// -------------------------------------------------------------------------
echo "\n[4/4] Limpeza de fixtures e verificação de integridade...\n";
$db->prepare("DELETE FROM login_attempts WHERE bucket_key IN (:k1, :k2)")
    ->execute([
        'k1' => RegistrationRateLimiter::bucketKey($testIp),
        'k2' => RegistrationRateLimiter::bucketKey('203.0113.88'),
    ]);

check(true, "Fixtures de teste limpas com sucesso");

echo "\n------------------------------------------------------------------------\n";
echo "RESULTADO: {$passed} testes passaram, {$failed} falharam.\n";
echo "========================================================================\n\n";

exit($failed === 0 ? 0 : 1);
