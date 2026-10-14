<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Gate de schema em MySQL real (NI2-409 / AUD-07).
 *
 * Executa contra um servidor MySQL 8 de verdade, em bancos descartáveis com prefixo "gate_":
 *   fresh        instalação do zero: todas as migrations num banco vazio + reexecução idempotente
 *   upgrade      banco na versão anterior (N-K migrations) com dados sintéticos -> upgrade até a última
 *   install-sql  importa database/instalacao_mysql8.sql e confirma que nada fica pendente
 *   all          os três acima + comparação dos fingerprints (precisam ser idênticos)
 *   dump-install regenera database/instalacao_mysql8.sql a partir de uma instalação do zero
 *   provision-app <banco>  cria o banco da aplicação do gate como numa instalação de produção
 *                (migrations + catálogo de referência; sem dados demo). Nome: gate_*test.
 *
 * Conexão (variáveis de ambiente): GATE_DB_HOST, GATE_DB_PORT, GATE_DB_USER, GATE_DB_PASS.
 * O usuário precisa poder criar/remover bancos "gate_%". Saída: JSON (stdout) e código 0/1.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Database\MySqlCompatPdo;

final class MySqlSchemaGate
{
    private string $root;
    private string $migrationsDir;

    public function __construct(
        private string $host,
        private string $port,
        private string $user,
        private string $pass,
    ) {
        $this->root = dirname(__DIR__, 2);
        $this->migrationsDir = $this->root . '/database/migrations';
    }

    public function serverVersion(): string
    {
        return (string)$this->server()->query('SELECT VERSION()')->fetchColumn();
    }

    /** Instalação do zero + reexecução idempotente. */
    public function fresh(string $db): array
    {
        $this->recreate($db);
        $pdo = $this->connect($db);
        $applied = (new Migrator($pdo, $this->migrationsDir))->run();
        $second = (new Migrator($pdo, $this->migrationsDir))->run();
        $all = $this->migrationNames();
        $ok = count($applied) === count($all) && $second === [];
        return [
            'ok' => $ok,
            'migrations_total' => count($all),
            'migrations_applied' => count($applied),
            'rerun_pending' => count($second),
            'schema' => $this->schemaInfo($pdo),
            'detail' => $ok ? 'Todas as migrations aplicadas; reexecução sem pendências.'
                : 'Migrations aplicadas: ' . count($applied) . '/' . count($all) . '; pendentes na reexecução: ' . count($second),
        ];
    }

    /** Banco na versão anterior com dados -> upgrade até a última migration, sem perda. */
    public function upgrade(string $db, int $back): array
    {
        $all = $this->migrationNames();
        $back = max(1, min($back, count($all) - 2));
        $baseCount = count($all) - $back;

        $this->recreate($db);
        $pdo = $this->connect($db);

        $tmp = sys_get_temp_dir() . '/gate_migrations_' . getmypid();
        @mkdir($tmp, 0700, true);
        foreach (array_slice($all, 0, $baseCount) as $name) {
            copy($this->migrationsDir . "/{$name}.php", "{$tmp}/{$name}.php");
        }
        (new Migrator($pdo, $tmp))->run();
        array_map('unlink', glob("{$tmp}/*.php") ?: []);
        @rmdir($tmp);

        // Dados sintéticos na versão anterior (colunas presentes desde a primeira migration).
        $seed = $this->seedSynthetic($pdo);
        $before = $this->counts($pdo, array_keys($seed));

        $upgraded = (new Migrator($pdo, $this->migrationsDir))->run();
        $after = $this->counts($pdo, array_keys($seed));
        $preserved = $before === $after;
        $ok = count($upgraded) === $back && $preserved;

        return [
            'ok' => $ok,
            'base_version' => $all[$baseCount - 1],
            'target_version' => end($all),
            'migrations_upgraded' => count($upgraded),
            'rows_before' => $before,
            'rows_after' => $after,
            'data_preserved' => $preserved,
            'schema' => $this->schemaInfo($pdo),
            'detail' => $ok ? "Upgrade de {$back} migrations com dados preservados."
                : 'Upgrade aplicou ' . count($upgraded) . "/{$back}; dados preservados: " . ($preserved ? 'sim' : 'NÃO'),
        ];
    }

    /** Importa o SQL de instalação (phpMyAdmin) e confirma que o migrator não vê pendências. */
    public function installSql(string $db): array
    {
        $file = $this->root . '/database/instalacao_mysql8.sql';
        if (!is_file($file)) {
            return ['ok' => false, 'detail' => 'database/instalacao_mysql8.sql não encontrado.'];
        }
        $this->recreate($db);
        $this->importWithCli($db, $file);
        $pdo = $this->connect($db);
        $pending = (new Migrator($pdo, $this->migrationsDir))->run();
        $ok = $pending === [];
        return [
            'ok' => $ok,
            'pending_after_import' => $pending,
            'schema' => $this->schemaInfo($pdo),
            'detail' => $ok ? 'SQL de instalação importado; nenhuma migration pendente.'
                : 'SQL de instalação desatualizado: ' . count($pending) . ' migration(s) pendente(s). Rode: php bin/homologation/mysql_schema_gate.php dump-install',
        ];
    }

    /** Regenera o SQL de instalação a partir de uma instalação do zero (estrutura + dados de referência). */
    public function dumpInstall(string $db): array
    {
        $this->fresh($db);
        $pdo = $this->connect($db);
        (new \ClyoNutri\Core\Database\CatalogSeeder($pdo))->run();
        $header = <<<SQL
-- ============================================================================
-- ClyoNutri II — Instalação inicial do banco MySQL 8 (importar pelo phpMyAdmin)
-- ----------------------------------------------------------------------------
-- Gerado por bin/homologation/mysql_schema_gate.php dump-install a partir das
-- migrations oficiais + catálogo de referência, num MySQL 8 real.
-- Contém estrutura, controle de migrations, planos SaaS, configurações e
-- catálogo de alimentos. NÃO contém contas, pacientes nem dados demo.
--
-- USE SOMENTE EM BANCO VAZIO: se alguma tabela já existir, a importação para
-- com erro (intencional: este arquivo nunca apaga dados).
-- Atualizações futuras: php bin/migrate.php (aplica só o que faltar).
-- ============================================================================


SQL;
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --skip-add-drop-table --no-tablespaces --skip-comments --skip-dump-date --single-transaction --default-character-set=utf8mb4 %s',
            escapeshellarg($this->host), escapeshellarg($this->port), escapeshellarg($this->user), escapeshellarg($db)
        );
        $dump = $this->runCli($cmd);
        $dump = preg_replace('/ AUTO_INCREMENT=\d+/', '', $dump);
        $target = $this->root . '/database/instalacao_mysql8.sql';
        file_put_contents($target, $header . $dump);
        $this->drop($db);
        return ['ok' => true, 'detail' => 'Gerado ' . $target . ' (' . filesize($target) . ' bytes).'];
    }

    /** Banco da aplicação para os testes HTTP/navegador: igual a uma instalação de produção. */
    public function provisionApp(string $db): array
    {
        if (!preg_match('/^gate_[a-z0-9_]*test$/', $db)) {
            throw new InvalidArgumentException("Banco da aplicação '{$db}' deve seguir gate_*test.");
        }
        $this->recreate($db);
        $pdo = $this->connect($db);
        $applied = (new Migrator($pdo, $this->migrationsDir))->run();
        (new \ClyoNutri\Core\Database\CatalogSeeder($pdo))->run();
        return ['ok' => count($applied) === count($this->migrationNames()), 'database' => $db, 'migrations_applied' => count($applied), 'schema' => $this->schemaInfo($pdo)];
    }

    /** Fingerprint estável do schema: tabelas, colunas, tipos, collation, índices e FKs. */
    public function schemaInfo(PDO $pdo): array
    {
        $q = static fn(string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        $tables = $q("SELECT TABLE_NAME, TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1");
        $columns = $q("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT,'<null>'), COLUMN_KEY,
                              TRIM(REPLACE(EXTRA,'DEFAULT_GENERATED','')), COALESCE(COLLATION_NAME,'')
                       FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1, 2");
        $indexes = $q("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
                       FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1, 2, 4");
        $fks = $q("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
                   FROM information_schema.KEY_COLUMN_USAGE k
                   JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                     ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                   WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1, 2, 3");
        $collations = array_values(array_unique(array_merge(
            array_column($tables, 1),
            array_filter(array_column($columns, 7))
        )));
        sort($collations);
        $last = $pdo->query("SELECT migration FROM migrations ORDER BY migration DESC LIMIT 1")->fetchColumn();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $canonical = json_encode([$tables, $columns, $indexes, $fks]);
        return [
            'version' => (string)$last,
            'migrations_recorded' => $count,
            'tables' => count($tables),
            'columns' => count($columns),
            'indexes' => count(array_unique(array_map(static fn($r) => $r[0] . '.' . $r[1], $indexes))),
            'foreign_keys' => count($fks),
            'collations' => $collations,
            'fingerprint' => hash('sha256', (string)$canonical),
        ];
    }

    public function drop(string $db): void
    {
        $this->assertGateDb($db);
        $this->server()->exec("DROP DATABASE IF EXISTS `{$db}`");
    }

    private function seedSynthetic(PDO $pdo): array
    {
        $uuid = static fn() => bin2hex(random_bytes(4)) . '-gate-' . bin2hex(random_bytes(6));
        $pdo->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, 'Consultório Upgrade A', 'active'), (?, 'Consultório Upgrade B', 'active')")
            ->execute([$uuid(), $uuid()]);
        $clinicId = (int)$pdo->query("SELECT MIN(id) FROM clinics")->fetchColumn();
        $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status) VALUES (?, 'Nutri Upgrade', 'nutri.upgrade@gate.test', ?, 'nutritionist', 'active')")
            ->execute([$uuid(), password_hash('gate-upgrade', PASSWORD_BCRYPT)]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")->execute([$clinicId, $userId]);
        $ins = $pdo->prepare("INSERT INTO patients (uuid, clinic_id, name, email) VALUES (?, ?, ?, ?)");
        foreach (['Paciente Upgrade Um', 'Paciente Upgrade Dois', 'Paciente Upgrade Três'] as $i => $name) {
            $ins->execute([$uuid(), $clinicId, $name, "paciente{$i}.upgrade@gate.test"]);
        }
        return ['clinics' => 2, 'users' => 1, 'clinic_members' => 1, 'patients' => 3];
    }

    private function counts(PDO $pdo, array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        }
        return $out;
    }

    private function migrationNames(): array
    {
        $names = array_map(static fn($f) => basename($f, '.php'), glob($this->migrationsDir . '/*.php') ?: []);
        sort($names);
        return $names;
    }

    private function recreate(string $db): void
    {
        $this->assertGateDb($db);
        $srv = $this->server();
        $srv->exec("DROP DATABASE IF EXISTS `{$db}`");
        $srv->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE " . MySqlCompatPdo::COLLATION);
    }

    private function assertGateDb(string $db): void
    {
        if (!preg_match('/^gate_[a-z0-9_]{1,50}$/', $db)) {
            throw new InvalidArgumentException("Banco '{$db}' recusado: o gate só usa bancos descartáveis com prefixo gate_.");
        }
    }

    private function server(): PDO
    {
        return new PDO("mysql:host={$this->host};port={$this->port};charset=utf8mb4", $this->user, $this->pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function connect(string $db): PDO
    {
        $pdo = new MySqlCompatPdo("mysql:host={$this->host};port={$this->port};dbname={$db};charset=utf8mb4", $this->user, $this->pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $pdo->exec('SET NAMES utf8mb4 COLLATE ' . MySqlCompatPdo::COLLATION);
        return $pdo;
    }

    private function importWithCli(string $db, string $file): void
    {
        $cmd = sprintf(
            'mysql --host=%s --port=%s --user=%s --default-character-set=utf8mb4 %s < %s',
            escapeshellarg($this->host), escapeshellarg($this->port), escapeshellarg($this->user),
            escapeshellarg($db), escapeshellarg($file)
        );
        $this->runCli($cmd);
    }

    private function runCli(string $cmd): string
    {
        $env = array_merge(getenv(), ['MYSQL_PWD' => $this->pass]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Não foi possível executar: ' . strtok($cmd, ' '));
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException(strtok($cmd, ' ') . ' falhou: ' . trim((string)$err));
        }
        return (string)$out;
    }
}

$mode = $argv[1] ?? 'all';
$prefix = 'gate_' . preg_replace('/[^a-z0-9]/', '', strtolower((string)(getenv('GATE_RUN_ID') ?: (string)getmypid())));
$gate = new MySqlSchemaGate(
    (string)(getenv('GATE_DB_HOST') ?: '127.0.0.1'),
    (string)(getenv('GATE_DB_PORT') ?: '3306'),
    (string)(getenv('GATE_DB_USER') ?: 'root'),
    (string)(getenv('GATE_DB_PASS') ?: ''),
);

try {
    $report = ['mode' => $mode, 'mysql_version' => $gate->serverVersion()];
    switch ($mode) {
        case 'fresh':
            $report['fresh'] = $gate->fresh("{$prefix}_fresh");
            $report['ok'] = $report['fresh']['ok'];
            break;
        case 'upgrade':
            $report['upgrade'] = $gate->upgrade("{$prefix}_upgrade", (int)(getenv('GATE_UPGRADE_BACK') ?: 6));
            $report['ok'] = $report['upgrade']['ok'];
            break;
        case 'install-sql':
            $report['install_sql'] = $gate->installSql("{$prefix}_install");
            $report['ok'] = $report['install_sql']['ok'];
            break;
        case 'provision-app':
            $report['provision_app'] = $gate->provisionApp((string)($argv[2] ?? ''));
            $report['ok'] = $report['provision_app']['ok'];
            break;
        case 'drop':
            $gate->drop((string)($argv[2] ?? ''));
            $report['ok'] = true;
            break;
        case 'dump-install':
            $report['dump_install'] = $gate->dumpInstall("{$prefix}_dump");
            $report['ok'] = true;
            break;
        case 'all':
            // Cada modo roda em processo próprio: o Migrator usa require_once por caminho de arquivo.
            $sub = static function (string $m) use ($argv): array {
                $out = [];
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($m) . ' 2>&1', $out, $code);
                $json = json_decode(implode("\n", $out), true);
                return is_array($json) ? $json : ['ok' => false, 'error' => trim(implode("\n", $out))];
            };
            $fresh = $sub('fresh');
            $upgrade = $sub('upgrade');
            $install = $sub('install-sql');
            $report['fresh'] = $fresh['fresh'] ?? ['ok' => false, 'detail' => $fresh['error'] ?? 'falhou'];
            $report['upgrade'] = $upgrade['upgrade'] ?? ['ok' => false, 'detail' => $upgrade['error'] ?? 'falhou'];
            $report['install_sql'] = $install['install_sql'] ?? ['ok' => false, 'detail' => $install['error'] ?? 'falhou'];
            $fp = [
                'fresh' => $report['fresh']['schema']['fingerprint'] ?? null,
                'upgrade' => $report['upgrade']['schema']['fingerprint'] ?? null,
                'install_sql' => $report['install_sql']['schema']['fingerprint'] ?? null,
            ];
            $report['fingerprints_identical'] = count(array_unique($fp)) === 1 && !in_array(null, $fp, true);
            $report['fingerprints'] = $fp;
            $report['schema'] = $report['fresh']['schema'] ?? null;
            $report['ok'] = ($report['fresh']['ok'] ?? false) && ($report['upgrade']['ok'] ?? false)
                && ($report['install_sql']['ok'] ?? false) && $report['fingerprints_identical'];
            if (getenv('GATE_KEEP_DBS') !== '1') {
                foreach (['fresh', 'upgrade', 'install'] as $s) {
                    $gate->drop("{$prefix}_{$s}");
                }
            }
            break;
        default:
            throw new InvalidArgumentException("Modo desconhecido: {$mode}");
    }
} catch (Throwable $e) {
    $report = ['mode' => $mode, 'ok' => false, 'error' => $e->getMessage()];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit(($report['ok'] ?? false) ? 0 : 1);
