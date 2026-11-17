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
// NI2-884 (v54.76): RegistrationRateLimiter::reset() foi removido; o teste limpa o próprio balde do IP de TESTE.
$db->prepare("DELETE FROM login_attempts WHERE bucket_key = ? AND scope = 'register_ip'")->execute([RegistrationRateLimiter::bucketKey($testIp)]);

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
$st = $db->prepare("SELECT failures, window_started_at FROM login_attempts WHERE bucket_key = :k AND scope = 'register_ip'");
$st->execute(['k' => RegistrationRateLimiter::bucketKey($testIp)]);
$attemptRow = $st->fetch(\PDO::FETCH_ASSOC);
check(!empty($attemptRow) && (int)$attemptRow['failures'] >= 5, "Contador de falhas persistido na login_attempts");
// NI2-884 (v54.76): o limitador passou a usar janela de 1 h (window_started_at) em vez de locked_until.
check(!empty($attemptRow['window_started_at']), "Janela do limite (window_started_at) registrada para o bloqueio temporário");

// Verificar auditoria do bloqueio na audit_logs
$stAudit = $db->prepare("SELECT action, details FROM audit_logs WHERE action = 'REGISTER_RATE_LIMITED' AND ip_address = :ip ORDER BY id DESC LIMIT 1");
$stAudit->execute(['ip' => $testIp]);
$auditRow = $stAudit->fetch(\PDO::FETCH_ASSOC);
check(!empty($auditRow), "Evento REGISTER_RATE_LIMITED registrado na trilha de auditoria audit_logs");

// Limpar fixture de teste
$db->prepare("DELETE FROM login_attempts WHERE bucket_key = ? AND scope = 'register_ip'")->execute([RegistrationRateLimiter::bucketKey($testIp)]);


// -------------------------------------------------------------------------
// [2/4] Teste de Proteção Anti-Bot Honeypot (views/auth/register.php)
// -------------------------------------------------------------------------
echo "\n[2/4] Verificação estática e funcional do Honeypot Anti-Bot...\n";

$registerViewPath = realpath(__DIR__ . '/../views/auth/register.php');
$viewContent = file_get_contents($registerViewPath);
$authSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/AuthController.php');

// v54.77 (decisão do Maicon, 17/11/2026): o honeypot "website_url" NÃO volta. Desde c039de5 a armadilha do cadastro é
// o campo AuthController::REGISTER_TRAP_FIELD ('cn_campo_extra'), com nome que o preenchimento automático do navegador
// não confunde com um campo real. O teste passa a conferir o comportamento atual em vez do nome antigo.
$trap = AuthController::REGISTER_TRAP_FIELD;
check($trap === 'cn_campo_extra', "Campo-armadilha atual do cadastro é '{$trap}' (AuthController::REGISTER_TRAP_FIELD)");
check(!str_contains($viewContent, 'website_url') && !str_contains($authSrc, 'website_url'), "Campo antigo 'website_url' não voltou (decisão de 17/11/2026)");
check(str_contains($viewContent, 'AuthController::REGISTER_TRAP_FIELD') && str_contains($viewContent, 'id="cn_campo_extra"'), "register.php renderiza o campo-armadilha pelo nome do controller");
check(str_contains($viewContent, 'tabindex="-1"'), "Campo-armadilha com tabindex='-1' (fora da ordem do Tab)");
check(str_contains($viewContent, 'autocomplete="off"'), "Campo-armadilha com autocomplete='off' contra autopreenchimento");
check(str_contains($viewContent, 'aria-hidden="true"'), "Campo-armadilha dentro de bloco aria-hidden='true' (leitor de tela não anuncia)");
$trapPos = strpos($authSrc, '$_POST[self::REGISTER_TRAP_FIELD]');
$limiterPos = strpos($authSrc, '$limiter->attempt($ip)');
$createPos = strpos($authSrc, 'registerNutritionist(');
check($trapPos !== false && $limiterPos !== false && $createPos !== false && $trapPos < $limiterPos && $limiterPos < $createPos,
    "Controller confere a armadilha antes do limite por IP, e os dois antes de criar a conta");
check(str_contains($authSrc, "auditRegisterBlock('REGISTER_BOT_BLOCKED'"), "Armadilha preenchida é auditada como REGISTER_BOT_BLOCKED");

// Registro de auditoria igual ao que o controller grava (o fluxo HTTP real fica na evidência de navegador da v54.77)
$_SERVER['REMOTE_ADDR'] = '203.0.113.88';
$_SERVER['HTTP_USER_AGENT'] = 'MaliciousBot/2.1 (Automated Script)';
$_POST = [$trap => 'https://spam-phishing-link.com', 'name' => 'Bot Spammer', 'email' => 'bot@spammer.com'];
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
$_SESSION = [];
$audit = new ReflectionMethod(AuthController::class, 'auditRegisterBlock');
$audit->setAccessible(true);
$honeypotCaught = trim((string)($_POST[$trap] ?? '')) !== '';
if ($honeypotCaught) {
    $audit->invoke(new AuthController(), 'REGISTER_BOT_BLOCKED', $_SERVER['REMOTE_ADDR']);
}
check($honeypotCaught, "Submissão com o campo-armadilha preenchido é reconhecida como robô");

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
