<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // v10: script só de linha de comando
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;

$envPath = getenv('HEALTHCHECK_ENV_FILE') ?: (__DIR__ . '/../.env');
if (is_file($envPath)) {
    Env::load($envPath);
}

$mode = in_array('--liveness', $argv ?? [], true) ? 'liveness' : 'readiness';

/**
 * Remove credenciais, strings de conexão e valores sensíveis de mensagens de erro.
 */
$redactSecrets = static function (string $msg): string {
    $secrets = array_filter([
        (string)getenv('DB_PASSWORD'),
        (string)getenv('MAIL_PASSWORD'),
        (string)getenv('APP_KEY'),
        (string)Env::get('DB_PASSWORD', ''),
        (string)Env::get('MAIL_PASSWORD', ''),
        (string)Env::get('APP_KEY', ''),
    ], static fn(string $v): bool => strlen($v) >= 3);

    foreach ($secrets as $secret) {
        $msg = str_replace($secret, '[REDACTED]', $msg);
    }
    $msg = (string)preg_replace('/(password|passwd|pwd|secret|token|key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $msg);
    $msg = (string)preg_replace('#[a-z0-9+.-]+://[^/\s:@]+:[^@\s]+@#i', '[REDACTED_DSN]@', $msg);
    return $msg;
};

echo "== ClyoNutri System Observability & Health Check ({$mode}) ==\n";

$checks = [];

// 1. PHP Version (Crítico em liveness e readiness)
$checks['php_version'] = [
    'critical' => true,
    'required' => '>= 8.1',
    'current' => PHP_VERSION,
    'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'FAIL',
];

$storageBase = (string)(getenv('HEALTHCHECK_STORAGE_DIR') ?: (__DIR__ . '/../storage'));

// 2. PDO Connection, Query SELECT 1 & Schema Migrations (Crítico em readiness)
$driverName = strtolower((string)(getenv('DB_CONNECTION') ?: Env::get('DB_CONNECTION', 'sqlite') ?: 'sqlite'));
$migrationFiles = glob(__DIR__ . '/../database/migrations/*.php') ?: [];
$expectedMigrations = count($migrationFiles);

if ($mode === 'liveness') {
    $checks['database'] = [
        'critical' => false,
        'status' => 'SKIPPED',
        'note' => 'banco não avaliado em liveness',
    ];
} elseif (!extension_loaded('pdo')) {
    $checks['database'] = [
        'critical' => true,
        'status' => 'BLOCKED',
        'note' => 'Extensão PDO do PHP não instalada no ambiente de execução.',
    ];
} elseif (!extension_loaded('pdo_' . $driverName)) {
    $checks['database'] = [
        'critical' => true,
        'status' => 'BLOCKED',
        'note' => "Driver pdo_{$driverName} não instalado no ambiente PHP.",
    ];
} else {
    $timeoutSec = max(1, (int)(getenv('HEALTHCHECK_DB_TIMEOUT') ?: 5));
    try {
        if ($driverName === 'mysql') {
            $host = (string)(getenv('DB_HOST') ?: Env::get('DB_HOST', '127.0.0.1'));
            $port = (string)(getenv('DB_PORT') ?: Env::get('DB_PORT', '3306'));
            $dbName = (string)(getenv('DB_DATABASE') ?: Env::get('DB_DATABASE', 'clyonutri'));
            $user = (string)(getenv('DB_USERNAME') ?: Env::get('DB_USERNAME', 'root'));
            $pass = (string)(getenv('DB_PASSWORD') ?: Env::get('DB_PASSWORD', ''));
            $charset = (string)(getenv('DB_CHARSET') ?: Env::get('DB_CHARSET', 'utf8mb4'));
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
            $db = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => $timeoutSec,
            ]);
        } else {
            $db = Connection::getInstance();
        }

        $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $ping = (int)$db->query('SELECT 1')->fetchColumn();
        if ($ping !== 1) {
            throw new \RuntimeException('Ping SELECT 1 retornou resultado inesperado.');
        }

        $stmt = $db->query('SELECT COUNT(*) FROM migrations');
        $migCount = (int)$stmt->fetchColumn();
        $latestMig = (string)($db->query('SELECT migration FROM migrations ORDER BY migration DESC LIMIT 1')->fetchColumn() ?: 'none');

        $expectedFile = $migrationFiles ? basename((string)end($migrationFiles), '.php') : '';
        $hasLatest = $expectedFile === '' || (bool)$db->prepare('SELECT 1 FROM migrations WHERE migration = ? LIMIT 1')
            ->execute([$expectedFile]);
        if ($expectedFile !== '') {
            $chkStmt = $db->prepare('SELECT 1 FROM migrations WHERE migration = ? LIMIT 1');
            $chkStmt->execute([$expectedFile]);
            $hasLatest = (bool)$chkStmt->fetchColumn();
        }

        $fkIssue = null;
        if ($driver === 'mysql') {
            $fks = (int)$db->query("SELECT COUNT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_note_drafts'
                  AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE = 'RESTRICT'")->fetchColumn();
            $cascades = (int)$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_note_drafts'
                  AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE <> 'RESTRICT'")->fetchColumn();
            if ($fks < 2 || $cascades > 0) {
                $fkIssue = "consultation_note_drafts sem integridade referencial validada (RESTRICT={$fks}/2, OUTRAS={$cascades})";
            }
        } elseif ($driver === 'sqlite') {
            $fksList = $db->query("PRAGMA foreign_key_list('consultation_note_drafts')")->fetchAll(PDO::FETCH_ASSOC);
            $fks = 0;
            $cascades = 0;
            foreach ($fksList as $fkl) {
                if (in_array($fkl['table'] ?? '', ['clinics', 'appointments'], true)) {
                    if (strtoupper((string)($fkl['on_delete'] ?? '')) === 'RESTRICT') {
                        $fks++;
                    } else {
                        $cascades++;
                    }
                }
            }
            if ($fks < 2 || $cascades > 0) {
                $fkIssue = "consultation_note_drafts sem integridade referencial validada (RESTRICT={$fks}/2, OUTRAS={$cascades})";
            }
        }

        $migrationLockActive = is_file($storageBase . '/maintenance_migration.lock');

        if ($migCount < $expectedMigrations || !$hasLatest) {
            $checks['database'] = [
                'critical' => true,
                'driver' => $driver,
                'migrations_applied' => $migCount,
                'migrations_expected' => $expectedMigrations,
                'latest_migration' => $latestMig,
                'status' => 'FAIL',
                'note' => "Schema incompleto: {$migCount}/{$expectedMigrations} migrações aplicadas (última esperada: {$expectedFile}).",
            ];
        } elseif ($fkIssue !== null) {
            $checks['database'] = [
                'critical' => true,
                'driver' => $driver,
                'status' => 'FAIL',
                'note' => $fkIssue,
            ];
        } elseif ($migrationLockActive) {
            $checks['database'] = [
                'critical' => true,
                'driver' => $driver,
                'status' => 'FAIL',
                'note' => 'Bloqueio de migração ativo (storage/maintenance_migration.lock)',
            ];
        } else {
            $checks['database'] = [
                'critical' => true,
                'driver' => $driver,
                'migrations_applied' => $migCount,
                'migrations_expected' => $expectedMigrations,
                'latest_migration' => $latestMig,
                'status' => 'OK',
                'note' => "driver={$driver}, migrations={$migCount}/{$expectedMigrations}, fks_restrict=2",
            ];
        }
    } catch (\Throwable $e) {
        $rawMsg = $redactSecrets($e->getMessage());
        $isTimeout = stripos($rawMsg, 'timed out') !== false || stripos($rawMsg, 'timeout') !== false || stripos($rawMsg, '110') !== false;
        $checks['database'] = [
            'critical' => true,
            'status' => $isTimeout ? 'FAIL' : 'BLOCKED',
            'note' => 'Falha de conexão/schema no banco (' . get_class($e) . '): ' . $rawMsg,
        ];
    }
}

// 3. Storage Permissions & Webroot Hardening (Crítico)
$storagePaths = [
    'logs' => $storageBase . '/logs',
    'uploads' => $storageBase . '/uploads',
    'backups' => $storageBase . '/backups',
];

$storageOk = true;
$missingStorage = [];
foreach ($storagePaths as $name => $path) {
    if (!is_dir($path) || !is_writable($path)) {
        $storageOk = false;
        $missingStorage[] = $name;
    }
}

$checks['storage_writable'] = [
    'critical' => true,
    'status' => $storageOk ? 'OK' : 'FAIL',
    'note' => $storageOk ? 'Diretórios de escrita íntegros' : 'Sem permissão de escrita em: ' . implode(', ', $missingStorage),
];

// 4. Scheduler Heartbeat (HostGator cron */15 monitor)
$heartbeatFile = getenv('HEALTHCHECK_HEARTBEAT_FILE') ?: ($storageBase . '/scheduler_heartbeat.json');
$requireCron = getenv('HEALTHCHECK_REQUIRE_CRON') === '1';
if ($mode === 'liveness') {
    $checks['scheduler_heartbeat'] = [
        'critical' => false,
        'status' => 'SKIPPED',
        'scheduler_state' => 'skipped_in_liveness',
        'note' => 'agendador não avaliado em liveness',
    ];
} elseif (!file_exists($heartbeatFile)) {
    $cronConfigured = getenv('CRON_CONFIGURED') === '1';
    $state = $cronConfigured ? 'never_ran' : 'not_configured';
    $checks['scheduler_heartbeat'] = [
        'critical' => $requireCron,
        'status' => $requireCron ? 'FAIL' : 'WARNING',
        'scheduler_state' => $state,
        'note' => $state === 'never_ran'
            ? 'Cron configurado, mas nunca executado (never_ran: arquivo scheduler_heartbeat.json ausente)'
            : 'Heartbeat ausente (not_configured / never_ran): cron ainda não registrou execução real',
    ];
} else {
    $hb = json_decode((string)file_get_contents($heartbeatFile), true);
    if (!is_array($hb) || empty($hb['timestamp']) || !is_numeric($hb['timestamp']) || empty($hb['last_run'])) {
        $checks['scheduler_heartbeat'] = [
            'critical' => $requireCron,
            'status' => 'FAIL',
            'scheduler_state' => 'invalid',
            'note' => 'Arquivo scheduler_heartbeat.json inválido ou corrompido',
        ];
    } else {
        $ageMinutes = round((time() - (int)$hb['timestamp']) / 60, 1);
        $lastStatus = strtoupper((string)($hb['status'] ?? 'UNKNOWN'));
        if ($lastStatus !== 'HEALTHY' && $lastStatus !== 'OK') {
            $checks['scheduler_heartbeat'] = [
                'critical' => $requireCron,
                'status' => 'FAIL',
                'scheduler_state' => 'failed_last_run',
                'age_minutes' => $ageMinutes,
                'last_run' => $hb['last_run'],
                'note' => "Última execução do cron reportou status={$lastStatus}",
            ];
        } elseif ($ageMinutes > 20 || $ageMinutes < -5) {
            $checks['scheduler_heartbeat'] = [
                'critical' => $requireCron,
                'status' => $requireCron ? 'FAIL' : 'WARNING',
                'scheduler_state' => 'stale',
                'age_minutes' => $ageMinutes,
                'last_run' => $hb['last_run'],
                'note' => "Heartbeat defasado (stale): última execução há {$ageMinutes} min (em {$hb['last_run']})",
            ];
        } else {
            $checks['scheduler_heartbeat'] = [
                'critical' => $requireCron,
                'status' => 'OK',
                'scheduler_state' => 'OK',
                'age_minutes' => $ageMinutes,
                'last_run' => $hb['last_run'],
                'note' => "Última execução há {$ageMinutes} min",
            ];
        }
    }
}

// 5. E-mail transacional (recuperação de senha) e URL pública dos links
$appEnv = strtolower((string)(getenv('APP_ENV') ?: Env::get('APP_ENV', 'local')));
$isProd = $appEnv === 'production';

if ($mode === 'liveness') {
    $checks['mail'] = [
        'critical' => false,
        'status' => 'SKIPPED',
        'note' => 'transporte de e-mail não avaliado em liveness',
    ];
    $checks['app_url'] = [
        'critical' => false,
        'status' => 'SKIPPED',
        'note' => 'URL pública não avaliada em liveness',
    ];
} else {
    $mailInfo = \ClyoNutri\Core\Mail\MailerFactory::describe();
    $mailProblems = $mailInfo['problems'];
    if (!$mailProblems && !$mailInfo['delivers']) {
        $mailProblems[] = "MAIL_DRIVER={$mailInfo['driver']} não entrega e-mails (só grava em log ou descarta)";
    }
    $checks['mail'] = [
        'critical' => false,
        'status' => $mailProblems ? 'WARNING' : 'OK',
        'note' => $mailProblems
            ? $redactSecrets(implode('; ', $mailProblems)) . ' — teste com: php bin/test_mail.php voce@exemplo.com'
            : "{$mailInfo['driver']} via " . ($mailInfo['host'] ?? 'servidor local') . ", remetente {$mailInfo['from']}",
    ];

    $appUrl = trim((string)(getenv('APP_URL') ?: Env::get('APP_URL', '')));
    $appUrlValid = (bool)preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?/?$#', $appUrl)
        && (!$isProd || str_starts_with(strtolower($appUrl), 'https://'));
    $checks['app_url'] = [
        'critical' => $isProd,
        'status' => $appUrlValid ? 'OK' : ($isProd ? 'FAIL' : 'WARNING'),
        'note' => $appUrl !== ''
            ? ($appUrlValid ? $appUrl : "APP_URL inválido para {$appEnv}: {$appUrl}")
            : 'APP_URL não definido: links de recuperação de senha não podem ser gerados',
    ];
}

// 6. Exibir resultado e avaliar gate (FAIL CLOSED)
$allowedStatuses = ['OK', 'WARNING', 'FAIL', 'BLOCKED', 'UNKNOWN', 'SKIPPED'];
$allOk = true;
$criticalFailures = [];

foreach ($checks as $name => $c) {
    $st = in_array($c['status'] ?? '', $allowedStatuses, true) ? $c['status'] : 'UNKNOWN';
    $isCritical = !empty($c['critical']);
    $noteSuffix = isset($c['note']) && $st !== 'OK' ? ' — ' . $redactSecrets((string)$c['note']) : '';
    echo sprintf(" - [%s] %s%s\n", $st, ucfirst($name), $noteSuffix);

    // Qualquer status FAIL, BLOCKED ou UNKNOWN reprova o healthcheck.
    // Em verificações críticas, qualquer coisa diferente de 'OK' reprova.
    if (in_array($st, ['FAIL', 'BLOCKED', 'UNKNOWN'], true) || ($isCritical && $st !== 'OK')) {
        $allOk = false;
        $criticalFailures[] = "{$name}={$st}";
    }
}

if ($mode === 'liveness') {
    if ($allOk) {
        echo "\nStatus Geral: Liveness OK (processo local ativo; dependências externas e aptidão para release exigem modo readiness).\n";
        exit(0);
    }
    echo "\nStatus Geral: Liveness FAIL — Falha crítica no processo local (" . implode(', ', $criticalFailures) . ").\n";
    exit(1);
}

if ($allOk) {
    echo "\nStatus Geral: SAUDÁVEL (Healthy) ✓ Pronto para operação e release.\n";
    exit(0);
}

echo "\nStatus Geral: NÃO SAUDÁVEL (Unhealthy) — Bloqueio de readiness detectado (" . implode(', ', $criticalFailures) . ").\n";
exit(1);


