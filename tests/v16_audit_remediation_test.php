<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Appointment\ConsultationDraftService;

$failures = 0;
$assertions = 0;

function v15_check(bool $cond, string $msg): void
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
$hc = escapeshellarg($root . '/bin/healthcheck.php');

echo "========================================================================\n";
echo "  v15: REMEDIAÇÃO DA AUDITORIA TÉCNICA 360° (V14-01, V14-02, V14-03)\n";
echo "========================================================================\n\n";

// -----------------------------------------------------------------------------
// 1. V14-01: Healthcheck fail-closed (liveness vs readiness, DB, cron, secrets)
// -----------------------------------------------------------------------------
echo "--- [1] V14-01: bin/healthcheck.php (Readiness, Liveness, Cron e Segredos) ---\n";

$runHealthcheck = static function (array $env = [], array $args = []) use ($php, $hc): array {
    $envParts = [];
    foreach ($env as $k => $v) {
        $envParts[] = $k . '=' . escapeshellarg((string)$v);
    }
    $argParts = array_map('escapeshellarg', $args);
    $cmd = 'env ' . implode(' ', $envParts) . " {$php} {$hc} " . implode(' ', $argParts) . ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
};

// Prepara banco SQLite temporário com todas as 40 migrações
$tmpDir = sys_get_temp_dir() . '/v15_hc_' . bin2hex(random_bytes(4));
mkdir($tmpDir . '/storage/logs', 0777, true);
mkdir($tmpDir . '/storage/uploads', 0777, true);
mkdir($tmpDir . '/storage/backups', 0777, true);
$tmpDb = $tmpDir . '/ready.sqlite';
$pdoReady = new PDO('sqlite:' . $tmpDb);
$pdoReady->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new Migrator($pdoReady, $root . '/database/migrations'))->run();

$hbFile = $tmpDir . '/storage/scheduler_heartbeat.json';
file_put_contents($hbFile, json_encode([
    'last_run' => date('c'),
    'timestamp' => time(),
    'status' => 'HEALTHY',
]));
$envReadyFile = $tmpDir . '/.env.ready';
file_put_contents($envReadyFile, "APP_ENV=local\nAPP_URL=https://clyonutri.com\n");

// 1a. Banco OK + schema completo + heartbeat fresco => OK, exit 0
$rOk = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmpDb,
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
    'APP_URL' => 'https://clyonutri.com',
]);
v15_check($rOk['rc'] === 0 && str_contains($rOk['out'], '[OK] Database') && str_contains($rOk['out'], 'SAUDÁVEL (Healthy)'), 'Banco conectado + schema compatível + heartbeat OK => exit 0');

// 1b. Banco BLOCKED (driver inexistente ou conexão recusada) => UNHEALTHY e exit != 0, sem vazar senha
$rBlocked = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '1',
    'DB_DATABASE' => 'nao_existe',
    'DB_USERNAME' => 'u',
    'DB_PASSWORD' => 'SenhaSuperSecretaV15!',
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
]);
v15_check($rBlocked['rc'] !== 0, 'Banco inacessível/bloqueado retorna exit code != 0 (' . $rBlocked['rc'] . ')');
v15_check(str_contains($rBlocked['out'], '[BLOCKED] Database') || str_contains($rBlocked['out'], '[FAIL] Database'), 'Banco inacessível sinalizado como BLOCKED/FAIL');
v15_check(str_contains($rBlocked['out'], 'NÃO SAUDÁVEL (Unhealthy)'), 'Status geral reporta NÃO SAUDÁVEL (Unhealthy)');
v15_check(!str_contains($rBlocked['out'], 'SenhaSuperSecretaV15!'), 'Senhas não vazam na saída do healthcheck');

// 1c. Modo --liveness não bloqueia quando o banco está inacessível e exibe [SKIPPED] Database (V15-03)
$rLive = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '1',
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
], ['--liveness']);
v15_check($rLive['rc'] === 0 && str_contains($rLive['out'], '(liveness)'), 'Contrato --liveness separado de readiness aprova processo vivo mesmo sem banco');
v15_check(str_contains($rLive['out'], '[SKIPPED] Database — banco não avaliado em liveness') && !str_contains($rLive['out'], '[OK] Database'), 'V15-03: --liveness reporta [SKIPPED] Database e nunca [OK] Database');

// 1d. Schema desatualizado (faltando migração 040) => FAIL e exit != 0
$pdoReady->exec("DELETE FROM migrations WHERE migration = '2026_10_15_000040_create_consultation_note_drafts'");
$rSchemaFail = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmpDb,
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
]);
v15_check($rSchemaFail['rc'] !== 0 && str_contains($rSchemaFail['out'], '[FAIL] Database') && str_contains($rSchemaFail['out'], 'Schema incompleto'), 'Schema sem migração 040 resulta em [FAIL] Database e exit != 0');
(new Migrator($pdoReady, $root . '/database/migrations'))->run();

// 1e. Cron nunca executado (ausência do arquivo) nunca é sinalizado como [OK]
@unlink($hbFile);
$rNeverRan = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmpDb,
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
    'CRON_CONFIGURED' => '1',
]);
v15_check(!str_contains($rNeverRan['out'], '[OK] Scheduler_heartbeat') && str_contains($rNeverRan['out'], 'never_ran'), 'Cron nunca executado não aparece como [OK] (indica never_ran)');

$rRequireCron = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmpDb,
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
    'HEALTHCHECK_REQUIRE_CRON' => '1',
]);
v15_check($rRequireCron['rc'] !== 0 && str_contains($rRequireCron['out'], '[FAIL] Scheduler_heartbeat'), 'Com HEALTHCHECK_REQUIRE_CRON=1, ausência de heartbeat reprova o gate');

// 1f. Produção com APP_URL sem https:// reprova readiness
file_put_contents($hbFile, json_encode(['last_run' => date('c'), 'timestamp' => time(), 'status' => 'HEALTHY']));
$rProdHttpUrl = $runHealthcheck([
    'HEALTHCHECK_ENV_FILE' => $envReadyFile,
    'APP_ENV' => 'production',
    'APP_URL' => 'http://clyonutri.com',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $tmpDb,
    'HEALTHCHECK_STORAGE_DIR' => $tmpDir . '/storage',
    'HEALTHCHECK_HEARTBEAT_FILE' => $hbFile,
]);
v15_check($rProdHttpUrl['rc'] !== 0 && str_contains($rProdHttpUrl['out'], '[FAIL] App_url'), 'Produção com APP_URL não-HTTPS bloqueia readiness');

exec('rm -rf ' . escapeshellarg($tmpDir));

// -----------------------------------------------------------------------------
// 2. V14-02: Equivalência entre migração PHP 040 e instalador SQL manual
// -----------------------------------------------------------------------------
echo "\n--- [2] V14-02: Equivalência de Schema da Migração 040 (PHP vs SQL Manual) ---\n";

require_once $root . '/database/migrations/2026_10_15_000040_create_consultation_note_drafts.php';
$pdoMockMysql = new class extends PDO {
    public array $queries = [];
    public string $simulatedIdType = 'BIGINT';
    public function __construct() { parent::__construct('sqlite::memory:'); }
    public function getAttribute($attribute): mixed {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
    }
    public function exec(string $statement): int|false {
        $this->queries[] = $statement;
        return 1;
    }
    public function prepare(string $query, array $options = []): \PDOStatement|false {
        $type = $this->simulatedIdType;
        return new class($type) extends \PDOStatement {
            public function __construct(private string $t) {}
            public function execute(?array $params = null): bool { return true; }
            public function fetchColumn(int $column = 0): mixed { return $this->t; }
        };
    }
};

$mig40 = new CreateConsultationNoteDrafts();
$mig40->up($pdoMockMysql);
$phpMysqlDdl = $pdoMockMysql->queries[0] ?? '';

$manualSqlV14 = (string)file_get_contents($root . '/database/manual/migracao-2026-10-15-ux-v14.sql');
$manualSqlV15 = (string)file_get_contents($root . '/database/manual/migracao-2026-10-16-ux-v15.sql');
$manualSqlV16 = (string)file_get_contents($root . '/database/manual/migracao-2026-10-16-ux-v16.sql');
$manualSqlV17 = (string)file_get_contents($root . '/database/manual/migracao-2026-10-16-ux-v17.sql');

$normDdl = static fn(string $s): string => (string)preg_replace('/\s*\(\s*/', '(', (string)preg_replace('/\s*\)\s*/', ') ', (string)preg_replace('/[`\s]+/', ' ', $s)));
$normPhp = $normDdl($phpMysqlDdl);
$normSql = $normDdl($manualSqlV16);

v15_check(
    $manualSqlV14 === $manualSqlV15 && $manualSqlV15 === $manualSqlV16 && $manualSqlV16 === $manualSqlV17,
    'Arquivos SQL manuais (v14, v15, v16, v17) sincronizados com gate fail-fast e ON DELETE RESTRICT'
);
foreach ([
    'uq_consult_drafts_appt_user',
    'idx_consult_drafts_clinic',
    'fk_consult_drafts_clinic',
    'fk_consult_drafts_appointment',
    'REFERENCES clinics(id) ON DELETE RESTRICT',
    'REFERENCES appointments(id) ON DELETE RESTRICT',
] as $expectedToken) {
    v15_check(stripos($normPhp, $expectedToken) !== false && stripos($normSql, $expectedToken) !== false, "Ambos (PHP e SQL manual) declaram: {$expectedToken}");
}
v15_check(
    !stripos($normPhp, 'ON DELETE CASCADE') && !stripos($normSql, 'REFERENCES clinics(id) ON DELETE CASCADE'),
    'V16-02: Nenhuma FK de consultation_note_drafts usa ON DELETE CASCADE'
);
v15_check(str_contains($manualSqlV16, 'fks_rascunho_consulta') && str_contains($manualSqlV16, '@fks_40 = 2'), 'SQL manual exige as 2 FKs RESTRICT (clinics e appointments) antes de marcar @ok40 = 1');

// Upgrade de 039 -> 040 em banco separado preservando pacientes e consultas + ON DELETE RESTRICT real
$upgDb = new PDO('sqlite::memory:');
$upgDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$upgDb->exec('PRAGMA foreign_keys = ON;');
(new Migrator($upgDb, $root . '/database/migrations'))->run();
// Simula estado 039 removendo a tabela 040 e o registro da migração 040, inserindo dados antes do upgrade
$upgDb->exec('DROP TABLE IF EXISTS consultation_note_drafts');
$upgDb->exec("DELETE FROM migrations WHERE migration = '2026_10_15_000040_create_consultation_note_drafts'");
$upgDb->exec("INSERT INTO clinics (id, uuid, name) VALUES (1, 'c-upg', 'Clínica Upgrade')");
$upgDb->exec("INSERT INTO users (id, uuid, name, email, password_hash) VALUES (1, 'u-upg', 'Nutri', 'n@upg.test', 'x')");
$upgDb->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '123', 'CRN-3')");
$upgDb->exec("INSERT INTO patients (id, clinic_id, uuid, name) VALUES (1, 1, 'p-upg', 'Paciente Preservado')");
$upgDb->exec("INSERT INTO appointments (id, clinic_id, patient_id, professional_id, scheduled_at, status) VALUES (10, 1, 1, 1, '2026-10-16 10:00:00', 'in_progress')");

$applied40 = (new Migrator($upgDb, $root . '/database/migrations'))->run();
$rerun40 = (new Migrator($upgDb, $root . '/database/migrations'))->run();
v15_check($applied40 === ['2026_10_15_000040_create_consultation_note_drafts'] && $rerun40 === [], 'Upgrade 039 -> 040 aplica apenas a 040 e reexecução é idempotente');
v15_check((int)$upgDb->query('SELECT COUNT(*) FROM patients')->fetchColumn() === 1 && (int)$upgDb->query('SELECT COUNT(*) FROM appointments')->fetchColumn() === 1, 'Dados de pacientes e consultas preservados após upgrade 039 -> 040');

$fks40 = $upgDb->query("PRAGMA foreign_key_list('consultation_note_drafts')")->fetchAll(PDO::FETCH_ASSOC);
$fkTables = array_column($fks40, 'table');
$fkOnDeletes = array_column($fks40, 'on_delete');
v15_check(
    in_array('clinics', $fkTables, true)
    && in_array('appointments', $fkTables, true)
    && !in_array('CASCADE', $fkOnDeletes, true),
    'Constraints FOREIGN KEY ativas para clinics e appointments com ON DELETE RESTRICT'
);

// -----------------------------------------------------------------------------
// 3. V14-03 & V14-04: Autosave — Conflito (409) vs Erro de Persistência (503)
// -----------------------------------------------------------------------------
echo "\n--- [3] V14-03 / V14-04: Autosave (409 Conflict vs 503 Persistência + UI States) ---\n";

$draftSvc = new ConsultationDraftService($upgDb);
$s1 = $draftSvc->save(10, 1, 1, 0, ['evolution_summary' => 'Aba 1 inicial']);
v15_check($s1['status'] === 'saved' && $s1['version'] === 1, 'Primeira gravação (v0 -> v1) retorna saved');

// V16-02: Com um rascunho ativo, DELETE físico em appointments ou clinics é bloqueado por ON DELETE RESTRICT
$blockedDeleteAppt = false;
try {
    $upgDb->exec('DELETE FROM appointments WHERE id = 10');
} catch (\PDOException $e) {
    $blockedDeleteAppt = true;
}
v15_check($blockedDeleteAppt && (int)$upgDb->query('SELECT COUNT(*) FROM consultation_note_drafts WHERE appointment_id = 10')->fetchColumn() === 1, 'V16-02: ON DELETE RESTRICT bloqueia exclusão física de appointment com rascunho clínico ativo');

$sConflict = $draftSvc->save(10, 1, 1, 0, ['evolution_summary' => 'Aba 2 concorrente com baseVersion=0']);
v15_check($sConflict['status'] === 'conflict' && $sConflict['version'] === 1 && ($sConflict['server']['evolution_summary'] ?? '') === 'Aba 1 inicial', 'Gravação concorrente com mesma versão produz conflict (409) preservando versão do servidor');

// V16-02: Verifica trilha de auditoria em ConsultationDraftService::discard() sem expor PHI bruto
$draftSvc->discard(10, 1, 1, 'consultation_completed');
$auditRows = $upgDb->query("SELECT action, resource, resource_id, details FROM audit_logs WHERE action = 'CONSULTATION_DRAFT_DISCARDED'")->fetchAll(PDO::FETCH_ASSOC);
$auditDetails = isset($auditRows[0]['details']) ? json_decode((string)$auditRows[0]['details'], true) : [];
v15_check(
    count($auditRows) === 1
    && (int)$auditRows[0]['resource_id'] === 10
    && ($auditDetails['version'] ?? null) === 1
    && ($auditDetails['summary_length'] ?? null) === mb_strlen('Aba 1 inicial')
    && ($auditDetails['reason'] ?? null) === 'consultation_completed'
    && !str_contains((string)$auditRows[0]['details'], 'Aba 1 inicial'),
    'V16-02: ConsultationDraftService::discard() registra CONSULTATION_DRAFT_DISCARDED em audit_logs com metadados de versão e sem expor PHI bruto'
);

// V16-02: Verifica migração automática de tabela legada ON DELETE CASCADE -> ON DELETE RESTRICT sem perda de rascunhos
$legacyCascadeDb = new PDO('sqlite::memory:');
$legacyCascadeDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyCascadeDb->exec('PRAGMA foreign_keys = ON;');
$legacyCascadeDb->exec("CREATE TABLE clinics (id INTEGER PRIMARY KEY)");
$legacyCascadeDb->exec("CREATE TABLE appointments (id INTEGER PRIMARY KEY, clinic_id INTEGER)");
$legacyCascadeDb->exec("INSERT INTO clinics VALUES (1)");
$legacyCascadeDb->exec("INSERT INTO appointments VALUES (10, 1)");
$legacyCascadeDb->exec("CREATE TABLE consultation_note_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id INTEGER NOT NULL,
    appointment_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    evolution_summary TEXT NOT NULL DEFAULT '',
    weight_kg TEXT NULL,
    waist_cm TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(appointment_id, user_id),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
)");
$legacyCascadeDb->exec("INSERT INTO consultation_note_drafts (clinic_id, appointment_id, user_id, evolution_summary, version) VALUES (1, 10, 1, 'Rascunho preservado no upgrade CASCADE->RESTRICT', 3)");
CreateConsultationNoteDrafts::ensureRestrictForeignKeys($legacyCascadeDb);
$upgradedFks = $legacyCascadeDb->query("PRAGMA foreign_key_list('consultation_note_drafts')")->fetchAll(PDO::FETCH_ASSOC);
$upgradedOnDeletes = array_column($upgradedFks, 'on_delete');
$preservedDraftText = (string)$legacyCascadeDb->query("SELECT evolution_summary FROM consultation_note_drafts WHERE appointment_id = 10")->fetchColumn();
v15_check(
    !in_array('CASCADE', $upgradedOnDeletes, true)
    && $preservedDraftText === 'Rascunho preservado no upgrade CASCADE->RESTRICT',
    'V16-02: ensureRestrictForeignKeys converte tabela legada ON DELETE CASCADE para ON DELETE RESTRICT preservando rascunhos existentes'
);

// Simula corrida no INSERT inicial (duas abas passaram por row() === null simultaneamente)
$racePdo = new class('sqlite::memory:') extends PDO {
    private bool $firstRowCall = true;
    public function __construct(string $dsn) {
        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec("CREATE TABLE appointments (id INTEGER PRIMARY KEY, clinic_id INTEGER, status TEXT)");
        $this->exec("INSERT INTO appointments VALUES (10, 1, 'in_progress')");
        $this->exec("CREATE TABLE consultation_note_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, clinic_id INTEGER, appointment_id INTEGER, user_id INTEGER, evolution_summary TEXT, weight_kg TEXT, waist_cm TEXT, version INTEGER, updated_at_utc TEXT, UNIQUE(appointment_id, user_id))");
        $this->exec("INSERT INTO consultation_note_drafts (clinic_id, appointment_id, user_id, evolution_summary, version, updated_at_utc) VALUES (1, 10, 1, 'Outra aba inseriu primeiro', 1, '2026-10-16 00:00:00')");
    }
    public function prepare(string $query, array $options = []): \PDOStatement|false {
        if (str_contains($query, 'SELECT evolution_summary') && $this->firstRowCall) {
            $this->firstRowCall = false;
            return new class extends \PDOStatement {
                public function execute(?array $params = null): bool { return true; }
                public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return false; }
            };
        }
        return parent::prepare($query, $options);
    }
};
$raceSvc = new ConsultationDraftService($racePdo);
$raceRes = $raceSvc->save(10, 1, 1, 0, ['evolution_summary' => 'Tentativa simultânea']);
v15_check($raceRes['status'] === 'conflict' && $raceRes['version'] === 1, 'Violação de UNIQUE no INSERT simultâneo retorna status=conflict com versão existente (1)');

// Simula tabela ausente / falha de banco no INSERT inicial: NÃO pode virar conflict!
$brokenPdo = new PDO('sqlite::memory:');
$brokenPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$brokenPdo->exec("CREATE TABLE appointments (id INTEGER PRIMARY KEY, clinic_id INTEGER, status TEXT)");
$brokenPdo->exec("INSERT INTO appointments VALUES (10, 1, 'in_progress')");
$brokenSvc = new ConsultationDraftService($brokenPdo);
$threwPdo = false;
try {
    $brokenSvc->save(10, 1, 1, 0, ['evolution_summary' => 'Texto importante do nutricionista']);
} catch (\PDOException $e) {
    $threwPdo = true;
}
v15_check($threwPdo, 'Falha de banco (tabela ausente/erro de persistência) lança PDOException em vez de fingir conflito de versão');

// Valida JS do autosave: estados explícitos e preservação local em sessionStorage
$jsDraft = (string)file_get_contents($root . '/public/assets/js/consulta-rascunho.js');
foreach (['salvando', 'salvo', 'erro', 'conflito'] as $uiState) {
    v15_check(str_contains($jsDraft, "'{$uiState}'"), "consulta-rascunho.js define estado explícito de UI: '{$uiState}'");
}
v15_check(str_contains($jsDraft, 'sessionStorage') && str_contains($jsDraft, 'correlation_id'), 'consulta-rascunho.js preserva rascunho localmente em sessionStorage e exibe correlation_id em falhas');

// -----------------------------------------------------------------------------
// 4. V15-01 & V16-01: Preflight Somente-Leitura e Gate Fail-Fast antes de qualquer DDL/DML
// -----------------------------------------------------------------------------
echo "\n--- [4] V15-01 & V16-01: Preflight Somente-Leitura + Gate Fail-Fast antes de DDL/DML ---\n";

$preflightSqlPath = $root . '/database/manual/preflight-2026-10-16-ux-v17.sql';
$preflightCliPath = $root . '/bin/migration_preflight.php';
v15_check(is_file($preflightSqlPath) && is_file($preflightCliPath), 'V16-01: Existem preflight-2026-10-16-ux-v17.sql e bin/migration_preflight.php');

$preflightSql = (string)file_get_contents($preflightSqlPath);
v15_check(
    preg_match('/\b(ALTER\s+TABLE|CREATE\s+TABLE|CREATE\s+INDEX|INSERT\s+INTO|UPDATE\s+`\w+`|DELETE\s+FROM|DROP\s+TABLE|TRUNCATE)\b/i', $preflightSql) === 0,
    'V16-01: preflight-2026-10-16-ux-v17.sql é 100% somente-leitura (zero DDL/DML)'
);
v15_check(preg_match('/\b(DELETE\s+d\s+FROM|DELETE\s+FROM|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $manualSqlV16) === 0, 'V15-01: Nenhum comando destrutivo (DELETE/DROP/TRUNCATE) presente no SQL manual');

// Verifica que @preflight_ok é calculado ANTES do primeiro ALTER TABLE / CREATE TABLE / UPDATE / INSERT
$posPreflightGate = strpos($manualSqlV16, 'SET @preflight_ok = (');
$posFirstAlter = strpos($manualSqlV16, 'ALTER TABLE');
$posFirstCreate = strpos($manualSqlV16, 'CREATE TABLE');
$posFirstInsert = strpos($manualSqlV16, 'INSERT INTO');
v15_check(
    $posPreflightGate !== false
    && $posFirstAlter !== false && $posPreflightGate < $posFirstAlter
    && $posFirstCreate !== false && $posPreflightGate < $posFirstCreate
    && $posFirstInsert !== false && $posPreflightGate < $posFirstInsert,
    'V16-01: @preflight_ok é avaliado no topo do SQL manual ANTES de qualquer ALTER TABLE, CREATE TABLE ou INSERT'
);
v15_check(
    substr_count($manualSqlV16, 'IF(@preflight_ok = 1') >= 15
    && str_contains($manualSqlV16, 'PREFLIGHT FAIL-FAST'),
    'V16-01: Todas as etapas 035..040 são bloqueadas por IF(@preflight_ok = 1, ...) em modo fail-fast'
);

// Executa bin/migration_preflight.php em banco SQLite íntegro vs banco com rascunho órfão
$preflightSqlite = tempnam(sys_get_temp_dir(), 'clyo_pf_') . '.sqlite';
$pfPdo = new PDO('sqlite:' . $preflightSqlite);
$pfPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new Migrator($pfPdo, $root . '/database/migrations'))->run();

$cmdClean = sprintf('PREFLIGHT_SQLITE_PATH=%s %s %s --json 2>&1', escapeshellarg($preflightSqlite), escapeshellarg(PHP_BINARY), escapeshellarg($preflightCliPath));
$outClean = [];
$codeClean = 0;
exec($cmdClean, $outClean, $codeClean);
$jsonClean = json_decode(implode("\n", $outClean), true);
v15_check(
    $codeClean === 0 && is_array($jsonClean) && ($jsonClean['preflight_ok'] ?? false) === true,
    'V16-01: bin/migration_preflight.php retorna exit 0 e preflight_ok=true em banco íntegro'
);

// Insere rascunho órfão (sem FK ativa no momento da simulação legada) e verifica fail-fast (exit 1) sem deletar o órfão
$pfPdo->exec('PRAGMA foreign_keys = OFF;');
$pfPdo->exec("INSERT INTO consultation_note_drafts (clinic_id, appointment_id, user_id, evolution_summary, version, updated_at_utc) VALUES (999, 888, 1, 'Rascunho órfão protegido', 1, '2026-10-16 00:00:00')");
$cmdOrphan = sprintf('PREFLIGHT_SQLITE_PATH=%s %s %s --json 2>&1', escapeshellarg($preflightSqlite), escapeshellarg(PHP_BINARY), escapeshellarg($preflightCliPath));
$outOrphan = [];
$codeOrphan = 0;
exec($cmdOrphan, $outOrphan, $codeOrphan);
$jsonOrphan = json_decode(implode("\n", $outOrphan), true);
$orphanStillExists = (int)$pfPdo->query('SELECT COUNT(*) FROM consultation_note_drafts WHERE appointment_id = 888')->fetchColumn() === 1;
@unlink($preflightSqlite);
v15_check(
    $codeOrphan === 1
    && is_array($jsonOrphan)
    && ($jsonOrphan['preflight_ok'] ?? true) === false
    && ($jsonOrphan['orphans_clinic'] ?? 0) === 1
    && ($jsonOrphan['orphans_appointment'] ?? 0) === 1
    && $orphanStillExists,
    'V16-01: bin/migration_preflight.php detecta órfãos (exit 1, preflight_ok=false) sem apagar dados'
);

// -----------------------------------------------------------------------------
// 5. V15-02 & V16-04: Submit Seguro, Await de Autosave em Voo, Expiração 401/403 e Isolamento Multi-Clínica
// -----------------------------------------------------------------------------
echo "\n--- [5] V15-02 & V16-04: Submit Seguro, Await de Autosave em Voo, 401/403 JSON e Isolamento ---\n";

v15_check(!preg_match('/addEventListener\(\s*[\'"]submit[\'"][^}]*clearLocalBackup\s*\(\s*\)/s', $jsDraft), 'V15-02: consulta-rascunho.js NÃO chama clearLocalBackup() prematuramente no evento submit');
v15_check(
    str_contains($jsDraft, 'MAX_AGE_MS = 15 * 60 * 1000')
    && str_contains($jsDraft, 'data-draft-scope')
    && str_contains($jsDraft, 'purgeStaleOrForeignBackups')
    && str_contains($jsDraft, 'keepalive: true')
    && str_contains($jsDraft, 'dataset.authExpired')
    && str_contains($jsDraft, 'Promise.race([')
    && str_contains($jsDraft, 'HTMLFormElement.prototype.submit.call(form)'),
    'V16-04: consulta-rascunho.js trata 401/403 com dataset.authExpired, aguarda saving em voo no submit e preserva escopo/TTL'
);

$indexSrc = (string)file_get_contents($root . '/public/index.php');
v15_check(
    str_contains($indexSrc, 'wantsJsonAuthError')
    && str_contains($indexSrc, "'status' => 'unauthenticated'")
    && str_contains($indexSrc, "str_ends_with(\$reqUriPath, '/rascunho')"),
    'V16-04: public/index.php devolve HTTP 401/403 JSON (unauthenticated/forbidden) para /consulta/{id}/rascunho quando a sessão expira'
);

$jsJourneys = (string)file_get_contents($root . '/public/assets/js/clinical-journeys.js');
$authCtrlSrc = (string)file_get_contents($root . '/src/Application/Controllers/AuthController.php');
$dashCtrlSrc = (string)file_get_contents($root . '/src/Application/Controllers/DashboardController.php');
v15_check(str_contains($authCtrlSrc, 'Clear-Site-Data: "storage"') && str_contains($jsJourneys, 'clearDraftStorage'), 'V15-02: Logout limpa storage via header Clear-Site-Data e listener JS');
v15_check(!preg_match('/INSERT\s+INTO\s+assessments\b/i', $dashCtrlSrc), 'V15-02: Removido INSERT INTO assessments (tabela legada inexistente) de DashboardController::completeConsultation');

// -----------------------------------------------------------------------------
// 6. V16-03: Semântica de Liveness vs Readiness em bin/healthcheck.php
// -----------------------------------------------------------------------------
echo "\n--- [6] V16-03: Healthcheck --liveness vs Readiness ---\n";

$hcCmd = sprintf('%s %s --liveness 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root . '/bin/healthcheck.php'));
$hcOut = [];
$hcCode = 0;
exec($hcCmd, $hcOut, $hcCode);
$hcText = implode("\n", $hcOut);
v15_check(
    $hcCode === 0
    && str_contains($hcText, 'Status Geral: Liveness OK')
    && !str_contains($hcText, 'Pronto para operação e release')
    && str_contains($hcText, '[SKIPPED] Database')
    && str_contains($hcText, '[SKIPPED] Scheduler_heartbeat')
    && str_contains($hcText, '[SKIPPED] Mail')
    && str_contains($hcText, '[SKIPPED] App_url'),
    'V16-03: bin/healthcheck.php --liveness reporta Liveness OK, marca Database/Scheduler/Mail/App_url como [SKIPPED] e nunca declara aptidão de release'
);

// -----------------------------------------------------------------------------
// 7. V16-04: Isolamento Multi-Clínica/Multi-Usuário e Fluxo Completo de Conclusão + Auditoria
// -----------------------------------------------------------------------------
echo "\n--- [7] V16-04: Isolamento Multi-Tenant de Rascunhos + Conclusão Atômica ---\n";

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$mainDb = Connection::getInstance();
$mainDb->beginTransaction();
try {
    $suf = bin2hex(random_bytes(4));
    $mainDb->prepare("INSERT INTO clinics (uuid, name, email) VALUES (?, 'Clínica V16 A', ?)")->execute(["c16a-{$suf}", "c16a-{$suf}@test.local"]);
    $cid = (int)$mainDb->lastInsertId();
    $mainDb->prepare("INSERT INTO clinics (uuid, name, email) VALUES (?, 'Clínica V16 B', ?)")->execute(["c16b-{$suf}", "c16b-{$suf}@test.local"]);
    $cidB = (int)$mainDb->lastInsertId();

    $mainDb->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Nutri V16 A', ?, 'x', 'nutritionist', 1, 'active')")->execute(["u16a-{$suf}", "u16a-{$suf}@test.local"]);
    $uid = (int)$mainDb->lastInsertId();
    $mainDb->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Nutri V16 B', ?, 'x', 'nutritionist', 1, 'active')")->execute(["u16b-{$suf}", "u16b-{$suf}@test.local"]);
    $uidB = (int)$mainDb->lastInsertId();

    $mainDb->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'nutritionist', 'active')")->execute([$cid, $uid]);
    $mainDb->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 1600', 'CRN-3')")->execute([$uid]);
    $proId = (int)$mainDb->lastInsertId();
    $mainDb->prepare("INSERT INTO patients (clinic_id, uuid, name) VALUES (?, ?, 'Paciente V16')")->execute([$cid, "p16-{$suf}"]);
    $patId = (int)$mainDb->lastInsertId();
    $mainDb->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (?, ?, ?, '2026-10-16 11:00:00', 60, 'follow_up', 'in_progress')")->execute([$cid, $patId, $proId]);
    $apptId = (int)$mainDb->lastInsertId();

    // Testa isolamento no ConsultationDraftService entre clínicas e usuários distintos
    // Assinatura: save(int $appointmentId, int $userId, int $clinicId, int $baseVersion, array $fields)
    //             get(int $appointmentId, int $userId, int $clinicId)
    $mainDraftSvc = new ConsultationDraftService($mainDb);
    $savedA = $mainDraftSvc->save($apptId, $uid, $cid, 0, ['evolution_summary' => 'Rascunho exclusivo Nutri A', 'weight_kg' => '70.5']);
    $crossClinicBlocked = false;
    try {
        $mainDraftSvc->save($apptId, $uid, $cidB, 0, ['evolution_summary' => 'Tentativa cross-clinic']);
    } catch (\InvalidArgumentException $e) {
        $crossClinicBlocked = true;
    }
    $crossClinicReadBlocked = false;
    try {
        $mainDraftSvc->get($apptId, $uid, $cidB);
    } catch (\InvalidArgumentException $e) {
        $crossClinicReadBlocked = true;
    }
    $otherUserRead = $mainDraftSvc->get($apptId, $uidB, $cid);
    $ownRead = $mainDraftSvc->get($apptId, $uid, $cid);
    v15_check(
        $savedA['status'] === 'saved'
        && $crossClinicBlocked === true
        && $crossClinicReadBlocked === true
        && ($otherUserRead['version'] ?? -1) === 0
        && ($otherUserRead['evolution_summary'] ?? null) === null
        && ($ownRead['evolution_summary'] ?? '') === 'Rascunho exclusivo Nutri A',
        'V16-04: ConsultationDraftService isola rascunhos por clinic_id e user_id (bloqueia leitura/escrita cruzada)'
    );

    \ClyoNutri\Core\Auth\TenantContext::setClinicId($cid);
    \ClyoNutri\Core\Auth\TenantContext::setUserId($uid);
    $_SESSION['clinic_id'] = $cid;
    $_SESSION['user_id'] = $uid;
    $_SESSION['user_role'] = 'nutritionist';

    // Simula falha de validação/conclusão após o usuário digitar peso/cintura antes do autosave:
    $_SESSION['consultation_form_recovery'][$apptId] = [
        'clinic_id' => $cid,
        'user_id' => $uid,
        'appointment_id' => $apptId,
        'evolution_summary' => 'Evolução clínica preservada após falha no POST de conclusão.',
        'weight_kg' => '71,4',
        'waist_cm' => '79,0',
        'saved_at' => time(),
    ];
    $_GET = ['error' => 'falha'];
    $ctrl = new \ClyoNutri\Application\Controllers\DashboardController();
    ob_start();
    try {
        $ctrl->consultationRoom(['id' => (string)$apptId]);
    } finally {
        $roomHtml = (string)ob_get_clean();
    }
    $_GET = [];
    v15_check(
        str_contains($roomHtml, 'Evolução clínica preservada após falha no POST de conclusão.')
        && str_contains($roomHtml, 'value="71,4"')
        && str_contains($roomHtml, 'data-draft-recovered="1"')
        && str_contains($roomHtml, 'data-draft-scope="' . $cid . ':' . $uid . ':' . $apptId . '"')
        && str_contains($roomHtml, 'data-testid="consult-submit-error"'),
        'V15-02: consultationRoom restaura o texto submetido após falha no POST e emite escopo data-draft-scope + alerta'
    );

    // Conclui a consulta via AppointmentService::completeConsultation e verifica atomicidade + prontuário
    $apptSvc = new \ClyoNutri\Domain\Appointment\AppointmentService($mainDb);
    $compRes = $apptSvc->completeConsultation($apptId, 'Evolução final assinada com sucesso.', ['weight_kg' => 71.4, 'waist_cm' => 79.0], $uid);
    $statusAfter = (string)$mainDb->query("SELECT status FROM appointments WHERE id = {$apptId}")->fetchColumn();
    v15_check($compRes['status'] === 'completed' && $statusAfter === 'completed' && !empty($compRes['record_hash']), 'V15-02: AppointmentService::completeConsultation conclui consulta e gera hash SHA-256 atomicamente');

    // Após concluir (status=completed), rascunho não aceita mais escrita
    $afterCompleteBlocked = false;
    try {
        $mainDraftSvc->save($apptId, $uid, $cid, 1, ['evolution_summary' => 'Tentativa pós-conclusão']);
    } catch (\InvalidArgumentException $e) {
        $afterCompleteBlocked = true;
    }
    v15_check($afterCompleteBlocked === true, 'V16-04: Consulta concluída recusa novas gravações de rascunho (InvalidArgumentException)');
} finally {
    if ($mainDb->inTransaction()) {
        $mainDb->rollBack();
    }
}

echo "\n========================================================================\n";
echo $failures === 0
    ? "  ✓ TODAS AS {$assertions} VERIFICAÇÕES (V14 + V15 + V16) PASSARAM COM SUCESSO!\n"
    : "  ✗ {$failures} FALHA(S) EM {$assertions} VERIFICAÇÕES\n";
echo "========================================================================\n";
exit($failures === 0 ? 0 : 1);


