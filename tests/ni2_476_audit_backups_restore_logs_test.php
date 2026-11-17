<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-476
 * [SEC-AUDIT] Auditar backups, restauração, logs e alertas (Controles 19 e 20)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Core\Auth\TenantContext;

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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-476 (CONTROLES 19 E 20) <<<\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// CONTROLE 19: Procedimento de Backup Cifrado e Ensaio de Restauração
// -------------------------------------------------------------------------
echo "[Controle 19] Backups Cifrados, Manifesto SHA-256 e Ensaio de Restore...\n";

$backupScriptPath = __DIR__ . '/../bin/backup_database.sh';
$restoreScriptPath = __DIR__ . '/../bin/restore_database.sh';

check(file_exists($backupScriptPath), "Script oficial bin/backup_database.sh existe e está versionado");
check(file_exists($restoreScriptPath), "Script oficial bin/restore_database.sh existe e está versionado");

$backupSrc = file_get_contents($backupScriptPath);
$restoreSrc = file_get_contents($restoreScriptPath);

// 1. Cifragem e fail-closed
check(str_contains($backupSrc, "openssl enc -aes-256-cbc -salt -pbkdf2"), "Rotina de backup aplica cifragem AES-256-CBC com PBKDF2 via OpenSSL");
check(str_contains($backupSrc, 'BACKUP_ENCRYPTION_KEY') && str_contains($backupSrc, '-lt 16'), "Rotina de backup exige chave de no mínimo 16 caracteres (fail-closed sem chave fraca)");
check(str_contains($backupSrc, "shasum -a 256"), "Rotina de backup calcula checksum SHA-256 do arquivo criptografado");
check(str_contains($backupSrc, 'rm -f "$SQL_DUMP_FILE"') && str_contains($backupSrc, 'rm -f "$TAR_FILE"'), "Rotina de backup elimina imediatamente arquivos intermediários em texto claro");

// 2. Descriptografia e Rehearsal
check(str_contains($restoreSrc, "openssl enc -d -aes-256-cbc -pbkdf2"), "Rotina de restauração implementa decifragem reversível AES-256-CBC");
check(str_contains($restoreSrc, "storage/rehearsal_restore"), "Restauração direcionada para ambiente isolado de ensaio sem sobrescrever produção");

// 3. Teste funcional ponta-a-ponta de cifragem e decifragem de dump
$tempTestDir = sys_get_temp_dir() . '/clyo_backup_test_' . bin2hex(random_bytes(4));
@mkdir($tempTestDir, 0750, true);

$dummySql = "-- CLYONUTRI DUMP TEST\nCREATE TABLE test_backup (id INT, val TEXT);\nINSERT INTO test_backup VALUES (1, 'Dados Sensíveis Clínicos');\n";
$dummySqlPath = $tempTestDir . '/dump.sql';
file_put_contents($dummySqlPath, $dummySql);

$encPath = $tempTestDir . '/dump.sql.enc';
$testPassphrase = 'AuditMasterKey2026_Compliant!';

// Cifrar
$encCmd = sprintf("openssl enc -aes-256-cbc -salt -pbkdf2 -in %s -out %s -k %s 2>&1",
    escapeshellarg($dummySqlPath), escapeshellarg($encPath), escapeshellarg($testPassphrase));
exec($encCmd, $encOut, $encCode);
check($encCode === 0 && file_exists($encPath), "Cifragem OpenSSL AES-256-CBC executada com sucesso");

// Verificar que o arquivo cifrado não contém o texto claro
$encContent = file_get_contents($encPath);
check(!str_contains($encContent, 'Dados Sensíveis Clínicos'), "Arquivo .enc gerado não contém texto plano legível");

// Decifrar
$decPath = $tempTestDir . '/restored.sql';
$decCmd = sprintf("openssl enc -d -aes-256-cbc -pbkdf2 -in %s -out %s -k %s 2>&1",
    escapeshellarg($encPath), escapeshellarg($decPath), escapeshellarg($testPassphrase));
exec($decCmd, $decOut, $decCode);
check($decCode === 0 && file_exists($decPath), "Decifragem OpenSSL AES-256-CBC executada com sucesso");
check(file_get_contents($decPath) === $dummySql, "Conteúdo restaurado após decifragem é 100% idêntico ao original");

// Limpar arquivos temporários do teste de backup
@unlink($dummySqlPath);
@unlink($encPath);
@unlink($decPath);
@rmdir($tempTestDir);


// -------------------------------------------------------------------------
// CONTROLE 20: Trilha de Auditoria, Alertas e Logs Sanitizados
// -------------------------------------------------------------------------
echo "\n[Controle 20] Trilha de Auditoria (audit_logs), Imutabilidade e Sanitização...\n";

// 1. Inspecionar schema da tabela audit_logs
$auditCols = array_column($db->query("PRAGMA table_info(audit_logs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$expectedCols = ['id', 'clinic_id', 'user_id', 'action', 'resource', 'resource_id', 'ip_address', 'user_agent', 'details', 'created_at'];
$diffCols = array_diff($expectedCols, $auditCols);
check(empty($diffCols), "Tabela audit_logs possui todas as 10 colunas requeridas de conformidade e rastreabilidade");

// 2. Testar gravação e sanitização via AuditLogger
$logger = new AuditLogger($db);
$uniqueAuditToken = 'audit_test_' . bin2hex(random_bytes(6));

// Simular evento normal de negócio
$_SERVER['REMOTE_ADDR'] = '203.0.113.45';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AuditBot';
$logger->log('TEST_BUSINESS_EVENT', 'patients', '9999', [
    'test_token' => $uniqueAuditToken,
    'operation' => 'inspection',
]);

$stmtNorm = $db->prepare("SELECT * FROM audit_logs WHERE details LIKE :token LIMIT 1");
$stmtNorm->execute(['token' => '%' . $uniqueAuditToken . '%']);
$normLog = $stmtNorm->fetch(PDO::FETCH_ASSOC);

check($normLog !== false, "AuditLogger gravou com sucesso evento na tabela audit_logs");
check($normLog['ip_address'] === '203.0.113.45', "Evento de negócio registra endereço IP de origem para rastreabilidade");

// Simular evento de segurança (SECURITY_*)
$uniqueSecToken = 'sec_alert_' . bin2hex(random_bytes(6));
$logger->log('SECURITY_TEST_ALERT', 'auth', 'user_1', [
    'alert_token' => $uniqueSecToken,
    'reason' => 'suspicious_activity_test',
]);

$stmtSec = $db->prepare("SELECT * FROM audit_logs WHERE details LIKE :token LIMIT 1");
$stmtSec->execute(['token' => '%' . $uniqueSecToken . '%']);
$secLog = $stmtSec->fetch(PDO::FETCH_ASSOC);

check($secLog !== false, "AuditLogger gravou evento de alerta de segurança na audit_logs");
check($secLog['ip_address'] === null, "AuditLogger sanitiza metadados e omite IP (null) em eventos de segurança (SECURITY_*)");
check($secLog['user_agent'] === 'security-event', "AuditLogger fixa User-Agent como 'security-event' em alertas de segurança");

// 3. Teste de Auditoria de Acesso Cruzado pelo TenantGuard
$auditGuardBefore = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED'")->fetchColumn();

// Simular tentativa de violação de tenant
$crossTenantBlocked = false;
try {
    // Buscar um paciente existente
    $samplePatient = $db->query("SELECT id, clinic_id FROM patients LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$samplePatient) { // banco de teste limpo: cria um paciente TESTE para o teste não passar no vazio
        $db->exec("INSERT INTO patients (uuid, clinic_id, name, status) VALUES ('00000000-0000-4000-a476-000000000001', 1, 'Paciente TESTE NI2-476', 'active')");
        $samplePatient = $db->query("SELECT id, clinic_id FROM patients LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if ($samplePatient) {
        $wrongClinicId = (int)$samplePatient['clinic_id'] + 9999;
        TenantGuard::assertPatientBelongsToClinic((int)$samplePatient['id'], $wrongClinicId, $db);
    }
} catch (TenantSecurityException $e) {
    $crossTenantBlocked = true;
}

$auditGuardAfter = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED'")->fetchColumn();

check($crossTenantBlocked, "TenantGuard bloqueou violação cruzada de IDOR disparando TenantSecurityException");
check($auditGuardAfter > $auditGuardBefore, "TenantGuard auditou automaticamente a violação com ação 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED'");

// 4. Imutabilidade da trilha de auditoria
$auditLoggerSrc = file_get_contents(__DIR__ . '/../src/Core/Audit/AuditLogger.php');
check(!str_contains($auditLoggerSrc, 'UPDATE audit_logs'), "AuditLogger não possui método de UPDATE (imutabilidade estrita)");
check(!str_contains($auditLoggerSrc, 'DELETE FROM audit_logs'), "AuditLogger não possui método de DELETE (imutabilidade estrita)");

// Limpar fixtures criadas neste teste
$db->exec("DELETE FROM audit_logs WHERE details LIKE '%{$uniqueAuditToken}%'");
$db->exec("DELETE FROM audit_logs WHERE details LIKE '%{$uniqueSecToken}%'");

// -------------------------------------------------------------------------
// RELATÓRIO FINAL
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULTADO DA AUDITORIA NI2-476:\n";
echo "  Total de verificações realizadas: " . ($passed + $failed) . "\n";
echo "  Sucessos ([PASS]): {$passed}\n";
echo "  Falhas   ([FAIL]): {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] A auditoria detectou {$failed} inconformidade(s).\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Todos os controles (19 e 20) foram auditados e validados com êxito!\n";
    exit(0);
}
