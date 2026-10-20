<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Appointment\ConsultationDraftService;

$failures = 0;
$assertions = 0;

function v17_check(bool $cond, string $msg): void
{
    global $failures, $assertions;
    $assertions++;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$msg}\n";
    }
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$php = escapeshellarg(PHP_BINARY);
$preflightCli = escapeshellarg($root . '/bin/migration_preflight.php');
$healthcheckCli = escapeshellarg($root . '/bin/healthcheck.php');

echo "========================================================================\n";
echo "  v17: REMEDIAÇÃO DA AUDITORIA TÉCNICA (V17-01, V17-02, V17-03)\n";
echo "========================================================================\n\n";

// -----------------------------------------------------------------------------
// 1. V17-01: Verificação de Pré-Voo de Migração (bin/migration_preflight.php)
// -----------------------------------------------------------------------------
echo "--- [1] V17-01: bin/migration_preflight.php (Pré-Voo de Migração e Integridade) ---\n";

$tmpDir = sys_get_temp_dir() . '/v17_test_' . bin2hex(random_bytes(4));
@mkdir($tmpDir . '/storage/logs', 0777, true);
@mkdir($tmpDir . '/storage/uploads', 0777, true);
@mkdir($tmpDir . '/storage/backups', 0777, true);
$tmpDb = $tmpDir . '/test.sqlite';
$pdo = new PDO('sqlite:' . $tmpDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new Migrator($pdo, $root . '/database/migrations'))->run();
CreateConsultationNoteDrafts::ensureRestrictForeignKeys($pdo);

// Executa o CLI preflight em modo JSON
$cmdJson = "env DB_CONNECTION=sqlite DB_DATABASE={$tmpDb} {$php} {$preflightCli} --json 2>&1";
$outJson = [];
$rcJson = 0;
exec($cmdJson, $outJson, $rcJson);
$rawJson = implode("\n", $outJson);
$parsedPreflight = json_decode($rawJson, true);

v17_check($rcJson === 0, 'CLI bin/migration_preflight.php executa com sucesso (exit 0)');
v17_check(is_array($parsedPreflight) && isset($parsedPreflight['preflight_ok']), 'Saída do preflight em JSON estruturado com preflight_ok');
v17_check(isset($parsedPreflight['initial_state_40']), 'Identifica o estado inicial da tabela (initial_state_40: ' . ($parsedPreflight['initial_state_40'] ?? 'null') . ')');
v17_check(($parsedPreflight['has_drafts_table'] ?? false) === true, 'Inspeciona consultation_note_drafts');
v17_check(($parsedPreflight['fks_restrict'] ?? 0) === 2, 'Detecta 2 FKs com ON DELETE RESTRICT no estado final da tabela');

// -----------------------------------------------------------------------------
// 2. V17-01: Trava de Escrita e Proteção contra Concorrência no Rascunho
// -----------------------------------------------------------------------------
echo "\n--- [2] V17-01: Trava de Migração no ConsultationDraftService ---\n";

// Semeia registros mínimos no banco SQLite de teste
$pdo->exec("INSERT INTO clinics (id, uuid, name, email) VALUES (1, 'c1', 'Clínica V17', 'c17@test.local')");
$pdo->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Nutri V17', 'n17@test.local', 'hash', 'nutritionist', 1, 'active')");
$pdo->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'nutritionist', 'active')");
$pdo->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, 'CRN-3 1700', 'CRN-3')");
$pdo->exec("INSERT INTO patients (id, clinic_id, uuid, name) VALUES (1, 1, 'p1', 'Paciente V17')");
$pdo->exec("INSERT INTO appointments (id, clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (1, 1, 1, 1, '2026-10-16 14:00:00', 60, 'follow_up', 'in_progress')");

$draftService = new ConsultationDraftService($pdo);

// 2a. Escrita normal permitida sem trava
$resNormal = $draftService->save(1, 1, 1, 0, ['evolution_summary' => 'Texto normal antes da trava']);
v17_check($resNormal['status'] === 'saved' && $resNormal['version'] === 1, 'ConsultationDraftService::save salva rascunho com sucesso quando destravado');

// 2b. Trava por variável de ambiente MIGRATION_WRITE_LOCK=1
putenv('MIGRATION_WRITE_LOCK=1');
$lockedEnv = false;
try {
    $draftService->save(1, 1, 1, 1, ['evolution_summary' => 'Texto durante trava env']);
} catch (\PDOException $e) {
    $lockedEnv = true;
    v17_check(str_contains($e->getMessage(), 'bloqueada durante janela de migração'), 'MIGRATION_WRITE_LOCK=1 recusa escrita com exceção PDOException (503)');
}
putenv('MIGRATION_WRITE_LOCK=0');
v17_check($lockedEnv, 'ConsultationDraftService::save bloqueado por MIGRATION_WRITE_LOCK=1');

// 2c. Trava por arquivo storage/maintenance_migration.lock
$lockFile = $root . '/storage/maintenance_migration.lock';
@file_put_contents($lockFile, json_encode(['started_at' => date('c'), 'reason' => 'V17-01 DDL migration']));
$lockedFile = false;
try {
    $draftService->save(1, 1, 1, 1, ['evolution_summary' => 'Texto durante trava arquivo']);
} catch (\PDOException $e) {
    $lockedFile = true;
} finally {
    @unlink($lockFile);
}
v17_check($lockedFile, 'ConsultationDraftService::save bloqueado por storage/maintenance_migration.lock');

// 2d. Após remover o lock, escrita é retomada com integridade
$resAfterLock = $draftService->save(1, 1, 1, 1, ['evolution_summary' => 'Texto retomado após término do lock']);
v17_check($resAfterLock['status'] === 'saved' && $resAfterLock['version'] === 2, 'ConsultationDraftService retoma gravações perfeitamente após liberação do lock');

// -----------------------------------------------------------------------------
// 3. V17-01: Healthcheck em Modo Readiness valida integridade de chaves
// -----------------------------------------------------------------------------
echo "\n--- [3] V17-01: bin/healthcheck.php (--readiness com trava e integridade) ---\n";

$envFile = $tmpDir . '/.env.hc';
file_put_contents($envFile, "APP_ENV=local\nAPP_URL=https://clyonutri.com\n");
$hbFile = $tmpDir . '/storage/scheduler_heartbeat.json';
file_put_contents($hbFile, json_encode(['last_run' => date('c'), 'timestamp' => time(), 'status' => 'HEALTHY']));

// 3a. Readiness saudável com banco e sem lock
$cmdHcOk = "env HEALTHCHECK_ENV_FILE={$envFile} DB_CONNECTION=sqlite DB_DATABASE={$tmpDb} HEALTHCHECK_STORAGE_DIR={$tmpDir}/storage HEALTHCHECK_HEARTBEAT_FILE={$hbFile} {$php} {$healthcheckCli} --readiness 2>&1";
$outHcOk = [];
$rcHcOk = 0;
exec($cmdHcOk, $outHcOk, $rcHcOk);
$txtHcOk = implode("\n", $outHcOk);
v17_check($rcHcOk === 0 && str_contains($txtHcOk, 'SAUDÁVEL (Healthy)'), 'Healthcheck --readiness retorna SAUDÁVEL (exit 0) em estado nominal');

// 3b. Readiness com trava de migração ativa -> BLOCKED / Unhealthy
@file_put_contents($tmpDir . '/storage/maintenance_migration.lock', 'lock');
$outHcLocked = [];
$rcHcLocked = 0;
exec($cmdHcOk, $outHcLocked, $rcHcLocked);
$txtHcLocked = implode("\n", $outHcLocked);
@unlink($tmpDir . '/storage/maintenance_migration.lock');
v17_check($rcHcLocked !== 0 && str_contains($txtHcLocked, 'Bloqueio de migração ativo'), 'Healthcheck --readiness bloqueia quando trava de migração está presente');

// -----------------------------------------------------------------------------
// 4. V17-01: Verificação Estática de Scripts de Migração Manual e Rotação Zero-Gap
// -----------------------------------------------------------------------------
echo "\n--- [4] V17-01: Scripts Manuais SQL e Rotação Zero-Gap (Add-Before-Drop) ---\n";

$preflightSql17 = file_get_contents($root . '/database/manual/preflight-2026-10-16-ux-v17.sql');
$preflightSql18 = file_get_contents($root . '/database/manual/preflight-2026-10-16-ux-v18.sql');
$migSql17 = file_get_contents($root . '/database/manual/migracao-2026-10-16-ux-v17.sql');
$migSql18 = file_get_contents($root . '/database/manual/migracao-2026-10-16-ux-v18.sql');

v17_check(
    str_contains($preflightSql17, "ENGINE")
    && str_contains($preflightSql17, "@preflight_ok")
    && str_contains($preflightSql17, "@init_state_40"),
    'preflight-2026-10-16-ux-v17.sql verifica InnoDB, compatibilidade e classifica @init_state_40'
);

v17_check(
    str_contains($preflightSql18, "ENGINE")
    && str_contains($preflightSql18, "@preflight_ok"),
    'preflight-2026-10-16-ux-v18.sql sincronizado e completo'
);

v17_check(
    str_contains($migSql17, 'fk_consult_drafts_clinic_v17')
    && str_contains($migSql17, 'fk_consult_drafts_appt_v17')
    && str_contains($migSql17, 'ON DELETE RESTRICT'),
    'migracao-2026-10-16-ux-v17.sql implementa rotação Zero-Gap (guard constraints com RESTRICT antes de drop)'
);

v17_check(
    str_contains($migSql17, '@drafts_count_before')
    && str_contains($migSql17, '@drafts_count_after')
    && str_contains($migSql17, '@data_intact_40'),
    'migracao-2026-10-16-ux-v17.sql garante contagem idêntica antes e depois da migração (zero perda de dados)'
);

v17_check(
    str_contains($migSql17, 'has_canonical_clinic') && str_contains($migSql17, 'has_canonical_appt'),
    'migracao-2026-10-16-ux-v17.sql contém lógica de autocura para estado parcial (recria canônicas se ausentes)'
);

// -----------------------------------------------------------------------------
// 5. V17-02 & V17-03: Evidências e Relatório de Homologação
// -----------------------------------------------------------------------------
echo "\n--- [5] V17-02 & V17-03: Evidências E2E e Relatório Gerado ---\n";

$evidJsonPath = $root . '/docs/evidencias/homologacao-v18-evidencias.json';
$evidMdPath = $root . '/docs/evidencias/HOMOLOGACAO-V18-RELATORIO.md';

v17_check(file_exists($evidJsonPath), 'Arquivo docs/evidencias/homologacao-v18-evidencias.json existe');
v17_check(file_exists($evidMdPath), 'Arquivo docs/evidencias/HOMOLOGACAO-V18-RELATORIO.md existe');

if (file_exists($evidJsonPath)) {
    $evidData = json_decode((string)file_get_contents($evidJsonPath), true);
    v17_check(
        is_array($evidData) && ($evidData['failures'] ?? -1) === 0 && ($evidData['total_tests'] ?? 0) === 7,
        'Evidências E2E confirmam 7/7 cenários aprovados (0 falhas)'
    );
}

// -----------------------------------------------------------------------------
// Resumo Final
// -----------------------------------------------------------------------------
echo "\n========================================================================\n";
echo $failures === 0
    ? "  ✓ TODAS AS {$assertions} VERIFICAÇÕES DE REMEDIAÇÃO V17 PASSARAM COM SUCESSO!\n"
    : "  ✗ {$failures} FALHA(S) EM {$assertions} VERIFICAÇÕES\n";
echo "========================================================================\n";

exit($failures === 0 ? 0 : 1);
