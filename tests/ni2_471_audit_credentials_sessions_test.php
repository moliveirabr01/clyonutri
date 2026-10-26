<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-471
 * [SEC-AUDIT] Auditar senhas, cookies, sessões e rate limiting (Controles 3, 4, 7, 9)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Security\LoginRateLimiter;
use ClyoNutri\Core\Security\RegistrationRateLimiter;

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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-471 (CONTROLES 3, 4, 7, 9) <<<\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// CONTROLE 3: Hashing de Senhas (Bcrypt / Argon2id)
// -------------------------------------------------------------------------
echo "[Controle 3] Hashing de Senhas e Custo Computacional...\n";

$authServiceSrc = file_get_contents(__DIR__ . '/../src/Core/Auth/AuthService.php');
$pwdResetSrc = file_get_contents(__DIR__ . '/../src/Core/Auth/PasswordResetService.php');

$hasBcryptCost12Auth = str_contains($authServiceSrc, "password_hash(\$password, PASSWORD_BCRYPT, ['cost' => 12])");
$hasBcryptCost12Reset = str_contains($pwdResetSrc, "password_hash(\$newPassword, PASSWORD_BCRYPT, ['cost' => 12])");

check($hasBcryptCost12Auth, "AuthService utiliza PASSWORD_BCRYPT com custo 12");
check($hasBcryptCost12Reset, "PasswordResetService utiliza PASSWORD_BCRYPT com custo 12");

// Teste funcional de hash e verificação
$sampleHash = password_hash('AuditPass@2026', PASSWORD_BCRYPT, ['cost' => 12]);
check(str_starts_with($sampleHash, '$2y$12$'), "Hash Bcrypt gerado com prefixo padrão \$2y\$12\$");
check(strlen($sampleHash) === 60, "Tamanho exato de 60 caracteres do hash Bcrypt");
check(password_verify('AuditPass@2026', $sampleHash), "password_verify confirma senha correta");
check(!password_verify('WrongPass@2026', $sampleHash), "password_verify rejeita senha incorreta");


// -------------------------------------------------------------------------
// CONTROLE 4: Flags de Cookies (HttpOnly, Secure, SameSite)
// -------------------------------------------------------------------------
echo "\n[Controle 4] Flags dos Cookies de Navegação e Sessão...\n";

$indexSrc = file_get_contents(__DIR__ . '/../public/index.php');
$mobileSrc = file_get_contents(__DIR__ . '/../src/Core/Auth/MobileWebSession.php');
$teleSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/TeleconsultationController.php');

check(str_contains($indexSrc, "'httponly' => true"), "Cookie de sessão web configurado com HttpOnly=true");
check(str_contains($indexSrc, "'samesite' => 'Lax'"), "Cookie de sessão web configurado com SameSite=Lax");
check(str_contains($indexSrc, "'secure' => \ClyoNutri\Core\Http\Transport::isHttps()"), "Cookie de sessão web configurado dinamicamente com Secure em HTTPS");

check(str_contains($mobileSrc, "'httponly' => true"), "Cookie móvel configurado com HttpOnly=true");
check(str_contains($mobileSrc, "'samesite' => 'Strict'"), "Cookie móvel configurado com SameSite=Strict");

check(str_contains($teleSrc, "'httponly' => true"), "Cookie de teleconsulta configurado com HttpOnly=true");
check(str_contains($teleSrc, "'samesite' => 'Lax'"), "Cookie de teleconsulta configurado com SameSite=Lax");


// -------------------------------------------------------------------------
// CONTROLE 7: Ciclo de Vida da Sessão (Expiração, Logout, Invalidação e Inatividade)
// -------------------------------------------------------------------------
echo "\n[Controle 7] Ciclo de Vida, Invalidação e Timeout de Inatividade...\n";

$authCtrlSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/AuthController.php');

check(str_contains($authCtrlSrc, 'Clear-Site-Data: "storage"'), "Logout web emite cabeçalho Clear-Site-Data: \"storage\"");
check(str_contains($authCtrlSrc, 'session_destroy()'), "Logout web executa session_destroy() do lado do servidor");
check(str_contains($authCtrlSrc, 'setcookie(session_name()'), "Logout web invalida o cookie de sessão no navegador com timestamp expirado");

check(str_contains($pwdResetSrc, 'auth_version = auth_version + 1'), "Redefinição de senha incrementa users.auth_version");
check(str_contains($indexSrc, "(int)\$mRow['auth_version'] === (int)(\$_SESSION['auth_version'] ?? 0)"), "TenantContext invalida sessões antigas com auth_version desatualizado");
check(str_contains($indexSrc, '$idleTimeout = 1800'), "Sessão web aplica timeout de inatividade de 30 minutos (1800s)");


// -------------------------------------------------------------------------
// CONTROLE 9: Rate Limiting em Endpoints Sensíveis e Anti-Bot
// -------------------------------------------------------------------------
echo "\n[Controle 9] Limitação de Taxa (Login, Cadastro, Reset, Teleconsulta)...\n";

$loginLimiter = new LoginRateLimiter($db);
check($loginLimiter->maxAttempts() === 5, "LoginRateLimiter configurado para 5 tentativas base por janela");

$regLimiter = new RegistrationRateLimiter($db);
check($regLimiter->maxAttempts() === 5, "RegistrationRateLimiter configurado para 5 tentativas por hora por IP");

check(PasswordResetService::MAX_REQUESTS_PER_EMAIL_15M === 5, "PasswordResetService limita a 5 pedidos por e-mail a cada 15 min");
check(PasswordResetService::MAX_REQUESTS_PER_IP_15M === 10, "PasswordResetService limita a 10 pedidos por IP a cada 15 min");

$registerViewSrc = file_get_contents(__DIR__ . '/../views/auth/register.php');
check(str_contains($registerViewSrc, 'name="website_url"'), "Formulário de cadastro protegido por campo honeypot anti-bot");

echo "\n------------------------------------------------------------------------\n";
echo "RESULTADO: {$passed} asserções passaram, {$failed} falharam.\n";
echo "========================================================================\n\n";

exit($failed === 0 ? 0 : 1);
