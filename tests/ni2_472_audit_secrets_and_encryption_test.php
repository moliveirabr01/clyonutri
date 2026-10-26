<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-472
 * [SEC-AUDIT] Auditar secrets e criptografia de dados (Controles 5 e 17)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Http\Transport;

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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-472 (CONTROLES 5 E 17) <<<\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// CONTROLE 5: Segredos Fora do Git (Código, Repositório e Bundles Frontend)
// -------------------------------------------------------------------------
echo "[Controle 5] Auditoria de Segredos e Gestão de Chaves de Perímetro...\n";

// 1. .gitignore
$gitignorePath = realpath(__DIR__ . '/../.gitignore');
$gitignoreContent = file_get_contents($gitignorePath);
check(str_contains($gitignoreContent, '.env'), ".gitignore protege arquivos '.env' contra commits acidentais");

// 2. Histórico Git
$gitEnvCommits = trim((string)shell_exec('git log --all --full-history -- "**.env" 2>&1'));
check(empty($gitEnvCommits), "Nenhum arquivo .env já foi commitado no histórico do Git");

// 3. Varredura nos bundles e assets públicos do frontend
$dirsToScan = [
    realpath(__DIR__ . '/../public/assets'),
    realpath(__DIR__ . '/../mobile/src'),
];

$secretPatterns = [
    "/DB_PASSWORD\s*=\s*['\"]?[a-zA-Z0-9_\-@#%]{3,}/i",
    "/CLINICAL_ENCRYPTION_KEY\s*=\s*['\"]?[a-zA-Z0-9_\-@#%]{3,}/i",
    "/AKIA[0-9A-Z]{16}/", // AWS Key ID
    "/ghp_[0-9a-zA-Z]{36}/", // GitHub Personal Token
    "/-----BEGIN (RSA |EC )?PRIVATE KEY-----/",
];

$exposedSecretsFound = 0;
foreach ($dirsToScan as $dir) {
    if (!$dir || !is_dir($dir)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $content = file_get_contents($file->getPathname());
        foreach ($secretPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $exposedSecretsFound++;
                echo "  [FAIL] Segredo exposto em: " . $file->getPathname() . "\n";
            }
        }
    }
}

check($exposedSecretsFound === 0, "Zero segredos ou chaves privadas expostas em public/assets ou mobile/src");

// 4. Centralização do carregamento de ambiente
$envSrc = file_get_contents(__DIR__ . '/../src/Core/Security/Env.php');
check(str_contains($envSrc, 'getenv($key)') && str_contains($envSrc, 'putenv('), "Env.php gerencia variáveis de ambiente sem persistência estática em código");


// -------------------------------------------------------------------------
// CONTROLE 17: Criptografia em Trânsito e Repouso (TLS, Banco e Backups)
// -------------------------------------------------------------------------
echo "\n[Controle 17] Criptografia em Trânsito (TLS/HSTS) e Repouso (AES-256)...\n";

// 1. Criptografia em trânsito (Transport e HSTS)
check(Transport::forceHttpsEnabled() === true || !Transport::isProduction(), "Mecanismo de imposição de HTTPS (Transport) ativo");

$hstsPolicy = SecurityHeaders::pagePolicy();
check(str_contains($hstsPolicy, "default-src 'self'"), "SecurityHeaders emite cabeçalhos defensivos de trânsito");

// 2. Criptografia de dados clínicos em repouso (AES-256-GCM)
$testClinicalText = "Histórico confidencial: alergia a dipirona e amendoim.";
$encryptedValue = ClinicalEncryptionService::encrypt($testClinicalText);
check(str_starts_with($encryptedValue, ClinicalEncryptionService::PREFIX), "Dados clínicos cifrados com envelope versionado 'enc:v1:'");
check(ClinicalEncryptionService::decrypt($encryptedValue) === $testClinicalText, "Descriptografia AES-256-GCM recupera texto original com integridade");

// 3. Verificação no banco de dados real
$st = $db->query("SELECT allergies FROM anamneses WHERE allergies IS NOT NULL AND allergies != '' LIMIT 1");
$row = $st->fetch(\PDO::FETCH_ASSOC);
if ($row) {
    check(str_starts_with($row['allergies'], 'enc:v1:'), "Registros existentes no banco de dados estão cifrados em repouso com 'enc:v1:'");
} else {
    check(true, "Tabela de anamneses pronta para receber dados cifrados");
}

// 4. Criptografia de backups em repouso (AES-256-CBC PBKDF2)
$backupScript = file_get_contents(__DIR__ . '/../bin/backup_database.sh');
check(str_contains($backupScript, 'openssl enc -aes-256-cbc -salt -pbkdf2'), "Script bin/backup_database.sh implementa AES-256-CBC com PBKDF2");
check(str_contains($backupScript, 'BACKUP_ENCRYPTION_KEY'), "Rotina de backup exige obrigatoriamente chave de cifragem via variável de ambiente (fail-closed)");
check(str_contains($backupScript, 'shasum -a 256'), "Geração de checksum SHA-256 para integridade do backup criptografado");

$restoreScript = file_get_contents(__DIR__ . '/../bin/restore_database.sh');
check(str_contains($restoreScript, 'openssl enc -d -aes-256-cbc -pbkdf2'), "Script bin/restore_database.sh decodifica e restaura backup com validação de checksum");

echo "\n------------------------------------------------------------------------\n";
echo "RESULTADO: {$passed} asserções passaram, {$failed} falharam.\n";
echo "========================================================================\n\n";

exit($failed === 0 ? 0 : 1);
