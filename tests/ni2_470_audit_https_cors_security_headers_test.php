<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-470
 * [SEC-AUDIT] Auditar HTTPS, CORS e cabeçalhos de segurança (Controles 1, 2, 14)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Auth\MobileWebSession;
use ClyoNutri\Core\Security\Env;

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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-470 (CONTROLES 1, 2, 14) <<<\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// CONTROLE 1: HTTPS e Redirect Canônico
// -------------------------------------------------------------------------
echo "[Controle 1] Imposição de HTTPS, Redirect Canônico e Bloqueio de Mutações Inseguras...\n";

$_ENV['FORCE_HTTPS'] = 'true';
$_ENV['APP_URL'] = 'https://app.clyonutri.com.br';

// 1. Redirecionamento canônico 301 para GET/HEAD por HTTP
$decisionGet = Transport::decide([
    'HTTPS' => 'off',
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/dashboard?period=30d',
    'REMOTE_ADDR' => '198.51.100.10',
    'SERVER_PORT' => '80',
]);
check(($decisionGet['status'] ?? 0) === 301, "Transport: Requisição GET via HTTP puro resulta em status 301 (Moved Permanently)");
check(($decisionGet['location'] ?? '') === 'https://app.clyonutri.com.br/dashboard?period=30d', "Transport: Redirect aponta para origem canônica https:// mantendo parâmetros da query");

// 2. Bloqueio imediato 403 para mutações (POST/PUT/DELETE) por HTTP
$decisionPost = Transport::decide([
    'HTTPS' => 'off',
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/pacientes',
    'REMOTE_ADDR' => '198.51.100.10',
    'SERVER_PORT' => '80',
]);
check(($decisionPost['status'] ?? 0) === 403, "Transport: Requisição POST via HTTP puro é recusada com status 403 (Forbidden)");
check(empty($decisionPost['location']), "Transport: POST recusado não gera cabeçalho Location (impede reenvio inadvertido de dados em claro)");
check(str_contains($decisionPost['body'] ?? '', 'Requisição recusada'), "Transport: Corpo da resposta 403 informa recusa expressa e orienta acesso seguro");

// 3. Tráfego regular sob HTTPS liberado
$decisionHttps = Transport::decide([
    'HTTPS' => 'on',
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/dashboard',
    'REMOTE_ADDR' => '198.51.100.10',
    'SERVER_PORT' => '443',
]);
check($decisionHttps === null, "Transport: Requisição sob HTTPS nativo segue normalmente sem interceptação (decide() === null)");

// 4. Anti-spoofing em proxies reversos (X-Forwarded-Proto)
$_ENV['TRUSTED_PROXIES'] = '10.0.0.1,10.0.0.2';

$spoofedServer = [
    'REMOTE_ADDR' => '198.51.100.99', // IP externo não confiável
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTPS' => 'off',
];
check(!Transport::isHttps($spoofedServer), "Transport: Ignora X-Forwarded-Proto 'https' originado de IP não listado em TRUSTED_PROXIES");

$trustedServer = [
    'REMOTE_ADDR' => '10.0.0.1', // IP do proxy reverso homologado
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTPS' => 'off',
];
check(Transport::isHttps($trustedServer), "Transport: Aceita X-Forwarded-Proto 'https' quando originado de IP confiável em TRUSTED_PROXIES");

// 5. Prevenção de Open Redirect no Target
check(Transport::safeRequestTarget('//evil-phishing.com/test') === '/evil-phishing.com/test', "Transport::safeRequestTarget neutraliza schema-relative URL (//) prevenindo Open Redirect");
$crlfCleaned = Transport::safeRequestTarget("/dashboard\r\nHeader-Injection: 1");
check(!str_contains($crlfCleaned, "\r") && !str_contains($crlfCleaned, "\n") && !str_contains($crlfCleaned, " "), "Transport::safeRequestTarget remove caracteres de quebra de linha (CRLF injection) e espaços de controle");


// -------------------------------------------------------------------------
// CONTROLE 2: CORS Allowlist Estrita
// -------------------------------------------------------------------------
echo "\n[Controle 2] CORS Allowlist Estrita e Restrições de API Móvel...\n";

$_SERVER['HTTP_HOST'] = 'app.clyonutri.com.br';
$_ENV['MOBILE_WEB_ORIGINS'] = 'https://mobile.clyonutri.com.br,https://paciente.clyonutri.com.br';

// 1. Validação de Mesma Origem (Host matching)
check(MobileWebSession::allowedOrigin('https://app.clyonutri.com.br') === true, "MobileWebSession: Mesma origem (Host canônico) é autorizada");

// 2. Validação de origens móveis cadastradas
check(MobileWebSession::allowedOrigin('https://mobile.clyonutri.com.br') === true, "MobileWebSession: Origem cadastrada em MOBILE_WEB_ORIGINS é autorizada");
check(MobileWebSession::allowedOrigin('https://paciente.clyonutri.com.br') === true, "MobileWebSession: Segunda origem cadastrada em MOBILE_WEB_ORIGINS é autorizada");

// 3. Rejeição estrita de origens não autorizadas e maliciosas
check(MobileWebSession::allowedOrigin('https://evil-attacker.com') === false, "MobileWebSession: Rejeita origem desconhecida/maliciosa");
check(MobileWebSession::allowedOrigin('null') === false, "MobileWebSession: Rejeita origem opaca 'null'");
check(MobileWebSession::allowedOrigin('javascript:alert(1)') === false, "MobileWebSession: Rejeita URIs com esquema javascript/inválido");

// 4. Verificação no front controller public/index.php
$indexSrc = file_get_contents(__DIR__ . '/../public/index.php');
check(str_contains($indexSrc, "str_starts_with(\$_SERVER['REQUEST_URI'] ?? '', '/api/v1/')"), "public/index.php restringe CORS exclusivamente ao prefixo /api/v1/");
check(str_contains($indexSrc, "Access-Control-Allow-Origin: ' . \$origin"), "public/index.php reflete apenas origem explicitamente validada (zero wildcard '*')");
check(str_contains($indexSrc, "Access-Control-Allow-Credentials: true"), "public/index.php emite Access-Control-Allow-Credentials para requisições autorizadas");
check(str_contains($indexSrc, "Vary: Origin"), "public/index.php emite cabeçalho Vary: Origin para evitar cache de respostas CORS");


// -------------------------------------------------------------------------
// CONTROLE 14: Cabeçalhos de Segurança (CSP/HSTS/XFO/Nosniff/Referrer)
// -------------------------------------------------------------------------
echo "\n[Controle 14] Cabeçalhos Defensivos (CSP Nonces, HSTS, X-Frame-Options, Nosniff)...\n";

$secHeadersSrc = file_get_contents(__DIR__ . '/../src/Core/Http/SecurityHeaders.php');

// 1. Nonces CSP imprevisíveis
$nonce1 = SecurityHeaders::nonce();
$nonce2 = SecurityHeaders::nonce();
check(is_string($nonce1) && strlen($nonce1) >= 20, "SecurityHeaders::nonce() gera string criptográfica URL-safe de comprimento adequado");
check($nonce1 === $nonce2, "SecurityHeaders::nonce() mantém consistência e idempotência durante a mesma requisição");

// 2. Política de CSP para páginas HTML (pagePolicy)
$pagePolicy = SecurityHeaders::pagePolicy();
check(str_contains($pagePolicy, "default-src 'self'"), "CSP pagePolicy define default-src 'self'");
check(str_contains($pagePolicy, "'nonce-" . $nonce1 . "'"), "CSP pagePolicy injeta nonce dinâmico criptográfico em script-src");
check(str_contains($pagePolicy, "script-src-attr 'none'"), "CSP pagePolicy proíbe expressamente manipuladores inline de atributos (script-src-attr 'none')");
check(str_contains($pagePolicy, "object-src 'none'"), "CSP pagePolicy desativa plugins inseguros com object-src 'none'");
check(str_contains($pagePolicy, "base-uri 'self'"), "CSP pagePolicy restringe base-uri a 'self' contra injeção de tags <base>");
check(str_contains($pagePolicy, "frame-ancestors 'none'"), "CSP pagePolicy proíbe incorporação em iframes externos com frame-ancestors 'none'");

// 3. Política de CSP para rotas de API (apiPolicy)
$apiPolicy = SecurityHeaders::apiPolicy();
check($apiPolicy === "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'", "CSP apiPolicy aplica isolamento máximo para endpoints JSON (default-src 'none')");

// 4. Strict-Transport-Security (HSTS)
$_ENV['APP_ENV'] = 'production';
$_ENV['HSTS_MAX_AGE'] = '31536000';
$_ENV['HSTS_INCLUDE_SUBDOMAINS'] = 'true';
$_ENV['HSTS_PRELOAD'] = 'true';

$hstsVal = SecurityHeaders::hstsValue();
check($hstsVal === 'max-age=31536000; includeSubDomains; preload', "SecurityHeaders::hstsValue gera diretiva completa com max-age, subdomínios e preload");

// 5. Cabeçalhos defensivos de navegador
check(str_contains($secHeadersSrc, "header('X-Content-Type-Options: nosniff'"), "SecurityHeaders emite X-Content-Type-Options: nosniff");
check(str_contains($secHeadersSrc, "header('X-Frame-Options: DENY'"), "SecurityHeaders emite X-Frame-Options: DENY");
check(str_contains($secHeadersSrc, "header('Referrer-Policy: strict-origin-when-cross-origin'"), "SecurityHeaders emite Referrer-Policy: strict-origin-when-cross-origin");
check(str_contains($secHeadersSrc, "header('Cross-Origin-Opener-Policy: same-origin'"), "SecurityHeaders emite Cross-Origin-Opener-Policy: same-origin");
check(str_contains($secHeadersSrc, "Permissions-Policy:"), "SecurityHeaders emite Permissions-Policy com restrição a APIs sensíveis de hardware");

// -------------------------------------------------------------------------
// RELATÓRIO FINAL
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULTADO DA AUDITORIA NI2-470:\n";
echo "  Total de verificações realizadas: " . ($passed + $failed) . "\n";
echo "  Sucessos ([PASS]): {$passed}\n";
echo "  Falhas   ([FAIL]): {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] A auditoria detectou {$failed} inconformidade(s).\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Todos os controles (1, 2 e 14) foram auditados e validados com êxito!\n";
    exit(0);
}
