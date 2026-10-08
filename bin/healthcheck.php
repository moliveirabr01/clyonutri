<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');

echo "== ClyoNutri System Observability & Health Check ==\n";

$checks = [];

// 1. PHP Version
$checks['php_version'] = [
    'required' => '>= 8.1',
    'current' => PHP_VERSION,
    'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'FAIL',
];

// 2. PDO Connection & DB Integrity
$driverName = getenv('DB_CONNECTION') ?: 'sqlite';
if (!extension_loaded('pdo')) {
    $checks['database'] = [
        'status' => 'BLOCKED',
        'error' => 'Extensão PDO do PHP não instalada no ambiente de execução.',
    ];
} elseif (!extension_loaded('pdo_' . $driverName)) {
    $checks['database'] = [
        'status' => 'BLOCKED',
        'error' => "Driver pdo_{$driverName} não instalado no ambiente PHP.",
    ];
} else {
    try {
        $db = Connection::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $stmt = $db->query("SELECT COUNT(*) FROM migrations");
        $migCount = (int)$stmt->fetchColumn();
        $checks['database'] = [
            'driver' => $driver,
            'migrations_applied' => $migCount,
            'status' => $migCount >= 4 ? 'OK' : 'WARNING',
        ];
    } catch (\Throwable $e) {
        $checks['database'] = [
            'status' => 'BLOCKED',
            'error' => 'Conexão com o banco bloqueada por dependência de infraestrutura: ' . $e->getMessage(),
        ];
    }
}

// 3. Storage Permissions & Webroot Hardening
$storagePaths = [
    'logs' => __DIR__ . '/../storage/logs',
    'uploads' => __DIR__ . '/../storage/uploads',
    'backups' => __DIR__ . '/../storage/backups',
];

$storageOk = true;
foreach ($storagePaths as $name => $path) {
    if (!is_writable($path)) {
        $storageOk = false;
        break;
    }
}

$checks['storage_writable'] = [
    'status' => $storageOk ? 'OK' : 'FAIL',
];

// 4. Scheduler Heartbeat (HostGator cron */15 monitor)
$heartbeatFile = __DIR__ . '/../storage/scheduler_heartbeat.json';
if (file_exists($heartbeatFile)) {
    $hb = json_decode((string)file_get_contents($heartbeatFile), true);
    $ageMinutes = round((time() - ($hb['timestamp'] ?? time())) / 60, 1);
    $checks['scheduler_heartbeat'] = [
        'status' => $ageMinutes <= 20 ? 'OK' : 'WARNING',
        'age_minutes' => $ageMinutes,
        'last_run' => $hb['last_run'] ?? 'unknown',
    ];
} else {
    $checks['scheduler_heartbeat'] = [
        'status' => 'OK',
        'note' => 'Ciclo inicial pendente',
    ];
}

// 5. Exibir resultado
$allOk = true;
foreach ($checks as $name => $c) {
    echo sprintf(" - [%s] %s\n", $c['status'], ucfirst($name));
    if ($c['status'] === 'FAIL') {
        $allOk = false;
    }
}

if ($allOk) {
    echo "\nStatus Geral: SAUDÁVEL (Healthy) ✓ Pronto para operação e release.\n";
    exit(0);
} else {
    echo "\nStatus Geral: ATENÇÃO / FALHA DETECTADA.\n";
    exit(1);
}
