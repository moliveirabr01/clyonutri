<?php
// Fixture do teste real da NI2-132 (diário alimentar). Dados fictícios.
// Só roda em banco isolado: SQLite em /tmp/ni2-132-* ou MySQL/MariaDB ni2_132_test_*.
// Base: fixture da NI2-91 (clínicas 1 e 2; Ana, Bruno e Carla; Nutri Um dona da clínica 1; Nutri Dois dona da 2).
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_132_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-132-')) {
    throw new RuntimeException('Isolated test database required');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_91_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) {
        throw new RuntimeException('base fixture');
    }
    echo json_encode(['ok' => true]);
    return;
}
$db = Connection::getInstance();
if ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) throw new RuntimeException('Somente SELECT');
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} elseif ($mode === 'legacy') {
    // legacy <patient_id> <clinic_id> <chave|-> <refeição> <consumed_at> <descrição>: registro de antes da migração 54 (sem hash/fuso).
    $db->prepare('INSERT INTO food_diary_entries (clinic_id, patient_id, meal_name, consumed_at, description, idempotency_key) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$argv[3], (int)$argv[2], $argv[5], $argv[6], $argv[7] ?? null, $argv[4] === '-' ? null : $argv[4]]);
    echo json_encode(['id' => (int)$db->lastInsertId()]);
} elseif ($mode === 'anonymize') {
    TenantContext::set((int)($argv[3] ?? 1), ['id' => 1, 'role' => 'nutritionist']);
    (new \ClyoNutri\Domain\Privacy\LgpdService($db))->anonymizePatient((int)$argv[2]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'down54') {
    // Banco como estava antes da migração 54 (o código novo precisa continuar funcionando).
    require_once __DIR__ . '/../../database/migrations/2026_10_24_000054_harden_food_diary_entries.php';
    (new HardenFoodDiaryEntries())->down($db);
    $db->exec("DELETE FROM migrations WHERE migration = '2026_10_24_000054_harden_food_diary_entries'");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'up54') {
    (new \ClyoNutri\Core\Database\Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    echo json_encode(['ok' => true]);
} elseif ($mode === 'seed-many') {
    // seed-many <patient_id> <clinic_id> <n>: n registros com horários de 1 em 1 minuto (de 5 em 5 repete o horário, para testar o desempate da paginação).
    $st = $db->prepare('INSERT INTO food_diary_entries (clinic_id, patient_id, meal_name, consumed_at, description, hunger_level) VALUES (?,?,?,?,?,3)');
    $base = new DateTimeImmutable('2026-09-01 08:00:00');
    $db->beginTransaction();
    for ($i = 0; $i < (int)$argv[4]; $i++) {
        $st->execute([(int)$argv[3], (int)$argv[2], 'Refeição teste ' . $i, $base->modify('+' . intdiv($i, 5) . ' minutes')->format('Y-m-d H:i:s'), 'Registro sintético ' . $i]);
    }
    $db->commit();
    echo json_encode(['ok' => true]);
} elseif ($mode === 'svc-photo') {
    // Validação do caminho da foto direto no serviço (a rota ignora o campo, mas o serviço também precisa recusar).
    TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
    $svc = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    $out = [];
    foreach (['/etc/passwd', '../../etc/passwd', 'a/../../b.jpg', 'C:\\Windows\\x.jpg', 'http://example.test/x.jpg', "fotos/a\0.jpg", 'fotos/ok-1.jpg', '/uploads/diary/ok-2.jpg', '/uploads/outro/x.jpg'] as $path) {
        try {
            $svc->logMeal(1, ['meal_name' => 'Foto ' . substr(md5($path), 0, 6), 'photo_path' => $path]);
            $out[$path] = 'aceito';
        } catch (\InvalidArgumentException $e) {
            $out[$path] = 'recusado';
        }
    }
    echo json_encode($out);
} elseif ($mode === 'export-keys') {
    TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
    $data = (new \ClyoNutri\Domain\Privacy\LgpdService($db))->exportPatientData((int)$argv[2]);
    $rows = $data['food_diary_entries'] ?? [];
    echo json_encode(['rows' => count($rows), 'keys' => $rows ? array_keys($rows[0]) : []]);
} elseif ($mode === 'clear-limits') {
    $db->exec('DELETE FROM teleconsultation_access_attempts');
    echo json_encode(['ok' => true]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
