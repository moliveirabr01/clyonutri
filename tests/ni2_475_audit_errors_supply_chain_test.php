<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-475
 * [SEC-AUDIT] Auditar erros e dependências vulneráveis (Controles 16 e 18)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Http\ErrorExposure;
use ClyoNutri\Core\Http\Transport;
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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-475 (CONTROLES 16 E 18) <<<\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// CONTROLE 16: Tratamento de Erros e Logs Seguros
// -------------------------------------------------------------------------
echo "[Controle 16] Tratamento de Erros, Supressão de Stack Traces e Logs Seguros...\n";

// 1. Inspecionar o código fonte de ErrorExposure e ApiErrorBoundary
$errorExposureSrc = file_get_contents(__DIR__ . '/../src/Core/Http/ErrorExposure.php');
$apiBoundarySrc = file_get_contents(__DIR__ . '/../src/Core/Http/ApiErrorBoundary.php');
$indexSrc = file_get_contents(__DIR__ . '/../public/index.php');

check(str_contains($errorExposureSrc, "ini_set('display_errors', '0')"), "ErrorExposure desativa display_errors incondicionalmente fora de debug autorizado");
check(str_contains($errorExposureSrc, "ini_set('display_startup_errors', '0')"), "ErrorExposure desativa display_startup_errors");
check(str_contains($errorExposureSrc, "ini_set('html_errors', '0')"), "ErrorExposure desativa html_errors");
check(str_contains($errorExposureSrc, "ini_set('log_errors', '1')"), "ErrorExposure garante registro em log do servidor (log_errors=1)");
check(str_contains($errorExposureSrc, "header_remove('X-Powered-By')"), "ErrorExposure suprime o cabeçalho identificador X-Powered-By");

// 2. Validação da lógica debugOutputAllowed
$_ENV['APP_ENV'] = 'production'; putenv('APP_ENV=production');
$_ENV['APP_DEBUG'] = 'true'; putenv('APP_DEBUG=true');
check(ErrorExposure::debugOutputAllowed(['REMOTE_ADDR' => '127.0.0.1']) === false, "debugOutputAllowed: NUNCA permite saída de debug quando APP_ENV=production, mesmo com APP_DEBUG=true");

$_ENV['APP_ENV'] = 'development'; putenv('APP_ENV=development');
$_ENV['APP_DEBUG'] = 'false'; putenv('APP_DEBUG=false');
check(ErrorExposure::debugOutputAllowed(['REMOTE_ADDR' => '127.0.0.1']) === false, "debugOutputAllowed: Bloqueia debug quando APP_DEBUG=false");

// Validar que o código restringe conexões web a 127.0.0.1 e ::1
check(str_contains($errorExposureSrc, "\$ip === '127.0.0.1' || \$ip === '::1'"), "debugOutputAllowed: Restringe estritamente debug a loopback (127.0.0.1 e ::1)");

// Restaurar ambiente
$_ENV['APP_ENV'] = 'production'; putenv('APP_ENV=production');
$_ENV['APP_DEBUG'] = 'false'; putenv('APP_DEBUG=false');

// 3. Teste de renderização da página 500 neutra
$sampleId = 'err_audit_' . bin2hex(random_bytes(4));
$output500 = shell_exec("php -r \"require 'vendor/autoload.php'; ClyoNutri\\Core\\Http\\ErrorExposure::renderGeneric500('{$sampleId}');\" 2>&1") ?? '';

check(str_contains($output500, $sampleId), "Página 500 contém o identificador opaco único do incidente ({$sampleId})");
check(str_contains($output500, 'Não foi possível concluir a solicitação'), "Página 500 exibe mensagem amigável sem jargão técnico");
check(!str_contains($output500, 'Stack trace:'), "Página 500 NÃO vaza 'Stack trace:'");
check(!str_contains($output500, '/Users/') && !str_contains($output500, '/home/') && !str_contains($output500, '\\'), "Página 500 NÃO vaza caminhos absolutos do filesystem");
check(!stripos($output500, 'SELECT ') && !stripos($output500, 'FROM users') && !stripos($output500, 'SQLSTATE'), "Página 500 NÃO vaza comandos SQL ou erros de banco de dados");
check(!stripos($output500, 'PHP Version') && !stripos($output500, 'Fatal error'), "Página 500 NÃO vaza versão do PHP ou detalhes de exceção fatal");
check(str_contains($errorExposureSrc, "while (ob_get_level() > 0)"), "renderGeneric500 esvazia todos os buffers anteriores garantindo zero vazamento de HTML prévio");

// 4. Teste de proteção da API em ApiErrorBoundary
check(str_contains($apiBoundarySrc, "INTERNAL_ERROR"), "ApiErrorBoundary define código padronizado neutro INTERNAL_ERROR");
check(str_contains($apiBoundarySrc, "X-Request-ID"), "ApiErrorBoundary propaga header X-Request-ID para rastreabilidade");
check(str_contains($apiBoundarySrc, "basename(\$e->getFile())"), "ApiErrorBoundary sanitiza logs gravando apenas o basename dos arquivos");
check(str_contains($indexSrc, "ErrorExposure::configure()"), "public/index.php registra ErrorExposure antes de processar qualquer requisição");
check(str_contains($indexSrc, "ApiErrorBoundary::register()"), "public/index.php registra ApiErrorBoundary globalmente");


// -------------------------------------------------------------------------
// CONTROLE 18: Gestão de Dependências e Supply Chain
// -------------------------------------------------------------------------
echo "\n[Controle 18] Gestão de Dependências, Lockfiles e Supply Chain...\n";

// 1. Inspeção do composer.json
$composerJsonPath = __DIR__ . '/../composer.json';
check(file_exists($composerJsonPath), "composer.json existe na raiz do projeto");
$composerData = json_decode(file_get_contents($composerJsonPath), true);
check(is_array($composerData), "composer.json é um JSON válido");
check(isset($composerData['require']['phpmailer/phpmailer']), "composer.json declara phpmailer/phpmailer no runtime");
check(count($composerData['require']) <= 2, "composer.json possui superfície mínima de dependências de terceiros (apenas php e phpmailer)");

// 2. Inspeção e integridade do composer.lock
$composerLockPath = __DIR__ . '/../composer.lock';
check(file_exists($composerLockPath), "composer.lock existe e está sob controle de versão");
$lockData = json_decode(file_get_contents($composerLockPath), true);
check(is_array($lockData), "composer.lock é um JSON válido");
check(isset($lockData['content-hash']) && strlen($lockData['content-hash']) > 0, "composer.lock contém content-hash de integridade");

$packages = $lockData['packages'] ?? [];
$packageNames = array_column($packages, 'name');
check(in_array('phpmailer/phpmailer', $packageNames, true), "composer.lock contém pacote oficial phpmailer/phpmailer");

$phpmailer = null;
foreach ($packages as $pkg) {
    if ($pkg['name'] === 'phpmailer/phpmailer') {
        $phpmailer = $pkg;
        break;
    }
}

check($phpmailer !== null, "Pacote phpmailer/phpmailer localizado no lockfile");
if ($phpmailer) {
    check(version_compare(ltrim($phpmailer['version'], 'v'), '6.9.0', '>='), "PHPMailer está na versão fixada segura ({$phpmailer['version']})");
    $thirdPartyDeps = array_filter(array_keys($phpmailer['require'] ?? []), fn($k) => str_contains($k, '/'));
    check(count($thirdPartyDeps) === 0, "PHPMailer não possui dependências transitivas de terceiros no PHP");
}

check(empty($lockData['packages-dev']), "composer.lock não inclui pacotes de desenvolvimento em pacotes de produção");

// 3. Inspeção do ecossistema Mobile
$mobilePkgPath = __DIR__ . '/../mobile/package.json';
$mobileLockPath = __DIR__ . '/../mobile/package-lock.json';
check(file_exists($mobilePkgPath), "mobile/package.json existe");
check(file_exists($mobileLockPath), "mobile/package-lock.json existe e está versionado");

$mobileLockData = json_decode(file_get_contents($mobileLockPath), true);
check(is_array($mobileLockData), "mobile/package-lock.json é um JSON válido");
check(isset($mobileLockData['lockfileVersion']), "mobile/package-lock.json especifica lockfileVersion (v{$mobileLockData['lockfileVersion']})");

// Verificar se as dependências do mobile possuem hashes de integridade SHA-512
$packagesWithIntegrity = 0;
$mobilePackages = $mobileLockData['packages'] ?? [];
foreach ($mobilePackages as $pkgName => $pkgInfo) {
    if (isset($pkgInfo['integrity']) && str_starts_with($pkgInfo['integrity'], 'sha512-')) {
        $packagesWithIntegrity++;
    }
}
check($packagesWithIntegrity > 50, "mobile/package-lock.json contém hashes de integridade criptográfica SHA-512 ({$packagesWithIntegrity} pacotes verificados)");

// -------------------------------------------------------------------------
// RELATÓRIO FINAL
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULTADO DA AUDITORIA NI2-475:\n";
echo "  Total de verificações realizadas: " . ($passed + $failed) . "\n";
echo "  Sucessos ([PASS]): {$passed}\n";
echo "  Falhas   ([FAIL]): {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] A auditoria detectou {$failed} inconformidade(s).\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Todos os controles (16 e 18) foram auditados e validados com êxito!\n";
    exit(0);
}
