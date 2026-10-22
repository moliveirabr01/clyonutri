<?php
// Fixture do teste real da NI2-96 (métricas de adesão com privacidade). Dados fictícios.
// Só roda em banco isolado: SQLite em /tmp/ni2-96-* ou MySQL ni2_96_test_*.
// Base: fixture da NI2-91 (clínica 1: Ana, Bruno; clínica 2: Carla; Nutri Um dona; equipe e recepção).
// Acrescenta 5 pacientes com app na clínica 1 (ids 11–15), operadores da plataforma (superadmin e suporte).
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_96_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-96-')) {
    throw new RuntimeException('Isolated test database required');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_91_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) {
        throw new RuntimeException('base fixture');
    }
    $db = Connection::getInstance();
    $hash = password_hash('PacienteTeste@2026', PASSWORD_DEFAULT);
    $p = $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,password_hash,status) VALUES(?,1,?,?,?,?,'active')");
    foreach ([11 => 'Dora', 12 => 'Elisa', 13 => 'Fabi', 14 => 'Gabi', 15 => 'Helô'] as $id => $name) {
        $p->execute([$id, 'n96-p' . $id, $name . ' Fictícia', strtolower($name === 'Helô' ? 'helo' : $name) . '@example.test', $hash]);
    }
    $op = password_hash('Plataforma@2026', PASSWORD_DEFAULT);
    $u = $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status,is_verified) VALUES(?,?,?,?,?,?,'active',1)");
    $u->execute([20, 'n96-u20', 'Operadora Plataforma', 'plataforma@example.test', $op, 'superadmin']);
    $u->execute([21, 'n96-u21', 'Suporte Plataforma', 'suporte@example.test', $op, 'support_agent']);
    $db->exec("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,20,'admin','active'),(1,21,'admin','active')");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) {
        throw new RuntimeException('Somente SELECT');
    }
    echo json_encode(Connection::getInstance()->query($q)->fetchAll(PDO::FETCH_ASSOC));
} elseif ($mode === 'flag') {
    $db = Connection::getInstance();
    $has = $db->query("SELECT COUNT(*) FROM platform_settings WHERE setting_key = 'analytics_adherence_enabled'")->fetchColumn();
    $has ? $db->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'analytics_adherence_enabled'")->execute([$argv[2]])
         : $db->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('analytics_adherence_enabled', ?)")->execute([$argv[2]]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'shift-days') {
    // shift-days <n>: leva o que foi coletado hoje para n dias atrás (simula a virada do dia).
    $db = Connection::getInstance();
    $to = (new DateTimeImmutable('today'))->modify('-' . (int)$argv[2] . ' days')->format('Y-m-d');
    foreach (['analytics_daily_metrics', 'analytics_daily_subjects', 'analytics_daily_salts'] as $t) {
        $db->prepare("UPDATE {$t} SET metric_date = ? WHERE metric_date = ?")->execute([$to, date('Y-m-d')]);
    }
    echo json_encode(['ok' => true, 'to' => $to]);
} elseif ($mode === 'purge') {
    echo json_encode((new \ClyoNutri\Domain\Analytics\AdherenceAnalytics(Connection::getInstance()))->purge());
} elseif ($mode === 'old-aggregate') {
    // Agregado de 25 meses atrás (deve sair na limpeza).
    $d = (new DateTimeImmutable('today'))->modify('-25 months')->format('Y-m-d');
    Connection::getInstance()->prepare("INSERT INTO analytics_daily_metrics (metric_date, clinic_id, metric, event_count, unique_patients, updated_at) VALUES (?, 1, 'plan_viewed', 9, 9, ?)")->execute([$d, date('Y-m-d H:i:s')]);
    echo json_encode(['ok' => true, 'date' => $d]);
} elseif ($mode === 'break-analytics' || $mode === 'fix-analytics') {
    // Simula falha do armazenamento das métricas (a ação da paciente não pode quebrar).
    $db = Connection::getInstance();
    $mode === 'break-analytics'
        ? $db->exec('ALTER TABLE analytics_daily_subjects RENAME TO analytics_daily_subjects_off')
        : $db->exec('ALTER TABLE analytics_daily_subjects_off RENAME TO analytics_daily_subjects');
    echo json_encode(['ok' => true]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
