<?php
declare(strict_types=1);

// Isolated synthetic database: never reads .env or connects to the working database.
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;

date_default_timezone_set('America/Sao_Paulo');
if (($argv[1] ?? '') === '--complete-worker') {
    $workerDb = new PDO('sqlite:' . $argv[2], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $workerDb->exec('PRAGMA foreign_keys = ON');
    $workerDb->exec('PRAGMA busy_timeout = 5000');
    Connection::setInstance($workerDb);
    TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN);
    try {
        (new AppointmentService($workerDb, new DateTimeImmutable('2031-01-10 10:00:00')))->completeConsultation((int)$argv[3], 'Concurrent conclusion');
        echo "completed\n";
    } catch (InvalidArgumentException $e) {
        echo "rejected\n";
    }
    exit;
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys = ON');
Connection::setInstance($db);
(new Migrator($db, dirname(__DIR__) . '/database/migrations'))->run();
$db->exec("INSERT INTO clinics (id, uuid, name, document) VALUES (1, 'review-clinic', 'Review', 'review')");
$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status) VALUES (1, 'review-user', 'Review', 'review@example.test', 'unused', 'nutritionist', 'active')");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'nutritionist', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, 'review', 'review')");
$db->exec("INSERT INTO patients (id, clinic_id, uuid, name) VALUES (1, 1, 'review-p1', 'Patient A'), (2, 1, 'review-p2', 'Patient B')");
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
// NI2-884 (v54.76): relógio da clínica no dia das consultas de 2031, senão a guarda da NI2-879 recusa a conclusão direta.
$s = new AppointmentService($db);
$s2031 = new AppointmentService($db, new DateTimeImmutable('2031-01-10 10:00:00'));
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
    echo "PASS: {$label}\n";
}
function rejects(callable $action, string $class, string $label): void {
    try { $action(); } catch (Throwable $e) {
        if (!$e instanceof $class) throw $e;
        check(true, $label);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $label);
}
function breakHistory(PDO $db, string $condition = ''): void {
    $db->exec("CREATE TRIGGER review_fail_history BEFORE INSERT ON appointment_status_history {$condition} BEGIN SELECT RAISE(ABORT, 'history unavailable'); END");
}

$a = $s->schedule(1, 1, '2031-01-10T10:00');
$r = $s2031->completeConsultation($a['id'], 'First and only conclusion');
$count = (int)$db->query('SELECT COUNT(*) FROM clinical_records')->fetchColumn();
rejects(fn() => $s->completeConsultation($a['id'], 'First and only conclusion'), InvalidArgumentException::class, 'Repeated conclusion rejected');
check((int)$db->query('SELECT COUNT(*) FROM clinical_records')->fetchColumn() === $count, 'Retry creates no second signed record');
foreach (['patient_id' => 2, 'professional_id' => 1, 'scheduled_at' => '2031-01-11 10:00', 'notes' => 'changed'] as $key => $value) {
    rejects(fn() => $s->edit($a['id'], [$key => $value]), InvalidArgumentException::class, 'Closed appointment rejects edit: ' . $key);
}
check((int)$s->getAppointment($a['id'])['patient_id'] === 1, 'Completed appointment retains original patient');
foreach (['cancelled', 'no_show'] as $i => $status) {
    $closed = $s->schedule(1, 1, '2031-01-' . (12 + $i) . ' 10:00');
    $s->updateStatus($closed['id'], $status);
    rejects(fn() => $s->edit($closed['id'], ['patient_id' => 2]), InvalidArgumentException::class, $status . ' cannot be reassigned');
    rejects(fn() => $s->completeConsultation($closed['id'], 'Invalid completion'), InvalidArgumentException::class, $status . ' cannot append clinical record');
}
$night = $s->schedule(1, 1, '2031-02-10 23:30', 'first_visit', 120);
rejects(fn() => $s->schedule(2, 1, '2031-02-11 00:15'), AppointmentConflictException::class, 'Overlap from previous day blocked');
check($s->schedule(2, 1, '2031-02-11 01:30')['id'] > 0, 'Adjacent intervals allowed');
$s->schedule(1, 1, '2031-02-13 00:15');
rejects(fn() => $s->schedule(2, 1, '2031-02-12 23:30', 'first_visit', 120), AppointmentConflictException::class, 'Overlap into following day blocked');
$move = $s->schedule(1, 1, '2031-02-14 10:00');
rejects(fn() => $s->edit($move['id'], ['scheduled_at' => '2031-02-11 00:30']), AppointmentConflictException::class, 'Rescheduling also checks midnight overlap');
check($s->schedule(2, 1, '2031-02-11 00:15', 'follow_up', 30, null, true)['id'] > 0, 'Explicit overlap remains supported');
foreach (['2031-02-30 15:00', '2031-02-29T10:00', '2031-01-10 25:00', 'tomorrow', '', '2031-01-10'] as $bad) {
    rejects(fn() => $s->schedule(1, 1, $bad), InvalidArgumentException::class, 'Invalid datetime rejected: ' . $bad);
}
rejects(fn() => $s->edit($move['id'], ['scheduled_at' => '2031-02-30T15:00']), InvalidArgumentException::class, 'Editing rejects invalid calendar day');
check($s->schedule(1, 1, '2032-02-29T15:00:00')['scheduled_at'] === '2032-02-29 15:00:00', 'Valid leap day accepted');
$pending = $s->schedule(1, 1, '2031-03-10 10:00');
breakHistory($db);
$appointments = (int)$db->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
rejects(fn() => $s->schedule(1, 1, '2031-03-11 10:00'), PDOException::class, 'Schedule reports audit persistence failure');
check((int)$db->query('SELECT COUNT(*) FROM appointments')->fetchColumn() === $appointments, 'Schedule rolled back without history');
rejects(fn() => $s->updateStatus($pending['id'], 'confirmed'), PDOException::class, 'Status reports audit persistence failure');
check($s->getAppointment($pending['id'])['status'] === 'scheduled', 'State rolls back with failed history');
rejects(fn() => $s->edit($pending['id'], ['patient_id' => 2]), PDOException::class, 'Edit reports audit persistence failure');
check((int)$s->getAppointment($pending['id'])['patient_id'] === 1, 'Edit rolls back with failed history');
$db->beginTransaction();
$db->exec("UPDATE patients SET name = 'Outer change' WHERE id = 1");
rejects(fn() => $s->edit($pending['id'], ['patient_id' => 2]), PDOException::class, 'Nested edit reports audit persistence failure');
check($db->inTransaction() && (int)$s->getAppointment($pending['id'])['patient_id'] === 1, 'Nested edit rolls back to savepoint');
check($db->query('SELECT name FROM patients WHERE id = 1')->fetchColumn() === 'Outer change', 'Caller transaction preserved');
$db->rollBack();
$db->exec('DROP TRIGGER review_fail_history');
$s->updateStatus($pending['id'], 'in_progress');
// Fail only the final transition, after the signed clinical record was inserted.
breakHistory($db, "WHEN NEW.to_status = 'completed'");
rejects(fn() => $s->completeConsultation($pending['id'], 'Must not survive rollback'), PDOException::class, 'Conclusion reports final audit failure');
check($s->getAppointment($pending['id'])['status'] === 'in_progress', 'Failed conclusion preserves original state');
check((int)$db->query('SELECT COUNT(*) FROM clinical_records')->fetchColumn() === $count, 'Failed conclusion rolls back signed record');
$db->exec('DROP TRIGGER review_fail_history');
check($s->completeConsultation($pending['id'], 'Valid retry')['clinical_record_id'] > 0, 'Conclusion succeeds after persistence recovers');
// Two independent PHP processes compete for the same consultation in a real SQLite file.
$race = $s->schedule(1, 1, '2031-04-10 10:00');
$databasePath = tempnam(sys_get_temp_dir(), 'ni2-10-race-');
if ($databasePath === false) throw new RuntimeException('Cannot create isolated concurrency database');
$workers = [];
try {
    $db->exec('VACUUM INTO ' . $db->quote($databasePath));
    for ($i = 0; $i < 2; ++$i) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--complete-worker', $databasePath, (string)$race['id']],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker');
        foreach ($pipes as $pipe) stream_set_timeout($pipe, 10);
        $workers[] = ['process' => $process, 'pipes' => $pipes];
    }
    foreach ($workers as $worker) {
        check(trim((string)fgets($worker['pipes'][1])) === 'ready', 'Independent conclusion worker ready');
    }
    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "go\n");
        fclose($worker['pipes'][0]);
    }
    $results = [];
    foreach ($workers as $worker) {
        $results[] = trim(stream_get_contents($worker['pipes'][1]));
        $errors = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        check(proc_close($worker['process']) === 0, 'Concurrent process exits successfully: ' . trim($errors));
    }
    sort($results);
    check($results === ['completed', 'rejected'], 'Exactly one concurrent conclusion succeeds');
    $raceDb = new PDO('sqlite:' . $databasePath);
    check((int)$raceDb->query('SELECT COUNT(*) FROM clinical_records')->fetchColumn() === $count + 2, 'Concurrent requests create only one additional signed record');
    $raceDb = null;
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) unlink($path);
    }
}
echo "{$checks} assertions passed\n";
