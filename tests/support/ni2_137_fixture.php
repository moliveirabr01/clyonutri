<?php
// Fixture do teste real da NI2-137 (próxima consulta e ações da paciente). Dados fictícios.
// Só roda em banco isolado: SQLite em /tmp/ni2-137-* ou MySQL ni2_137_test_*.
// Base: fixture da NI2-91 (clínicas 1 e 2, Ana/Bruno/Carla, nutri dona, nutricionista e recepção).
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_137_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-137-')) {
    throw new RuntimeException('Isolated test database required');
}
$mode = $argv[1] ?? 'prepare';
// Mesmo fuso do servidor (public/index.php usa APP_TIMEZONE); sem isto o CLI ficaria em UTC.
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$tz = new DateTimeZone(date_default_timezone_get());
$at = static fn(float $hours): string => (new DateTimeImmutable('now', $tz))->modify(sprintf('%+d minutes', (int)round($hours * 60)))->format('Y-m-d H:i:00');

if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_91_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) {
        throw new RuntimeException('base fixture');
    }
    $db = Connection::getInstance();
    // As consultas da base (ids 11-13) saem do caminho: o teste cria as suas com horários relativos a agora.
    $db->exec('DELETE FROM appointments WHERE id IN (11, 12, 13)');
    // Disponibilidade semanal da Nutri Um (profissional 1): todos os dias, 07:00-22:00, sem pausa.
    $weekly = [];
    foreach (['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'] as $d) {
        $weekly[$d] = ['enabled' => true, 'start' => '07:00', 'end' => '22:00', 'break_start' => '', 'break_end' => ''];
    }
    $db->prepare('UPDATE onboarding_progress SET practice_preferences = ? WHERE clinic_id = 1 AND user_id = 1')
        ->execute([json_encode(['weekly_availability' => $weekly, 'default_duration_minutes' => 60])]);
    $db->exec("UPDATE clinics SET phone = '(11) 4000-0000', address_street = 'Rua Fictícia', address_number = '100', address_complement = 'Sala 12',
        address_neighborhood = 'Centro', address_city = 'São Paulo', address_state = 'SP' WHERE id = 1");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'appt') {
    // appt <id> <patient_id> <clinic_id> <professional_id> <status> <horas a partir de agora> <type>
    [, , $id, $patient, $clinic, $prof, $status, $hours, $type] = $argv;
    $db = Connection::getInstance();
    $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([(int)$id]);
    $db->prepare('INSERT INTO appointments(id,clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type,notes) VALUES(?,?,?,?,?,60,?,?,?)')
        ->execute([(int)$id, (int)$clinic, (int)$patient, (int)$prof, $at((float)$hours), $status, $type, 'Nota interna da agenda: não mostrar à paciente']);
    echo json_encode(['id' => (int)$id, 'scheduled_at' => $at((float)$hours)]);
} elseif ($mode === 'tele') {
    // tele <appointment_id>: sessão de teleconsulta aberta pelo consultório (como na agenda).
    $db = Connection::getInstance();
    $row = $db->query('SELECT * FROM appointments WHERE id = ' . (int)$argv[2])->fetch(PDO::FETCH_ASSOC);
    TenantContext::set((int)$row['clinic_id'], ['id' => 1, 'role' => 'nutritionist']);
    $s = (new \ClyoNutri\Domain\Telehealth\TeleconsultationService($db))->scheduleSession((int)$row['id'], (int)$row['professional_id'], (int)$row['clinic_id']);
    echo json_encode(['session_id' => (int)($s['id'] ?? $s['session_id'] ?? 0)]);
} elseif ($mode === 'settings') {
    // settings <json>: regras da clínica 1 pela mesma API da tela de Configurações.
    $data = json_decode((string)($argv[2] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode((new \ClyoNutri\Domain\Booking\BookingService(Connection::getInstance()))->updateSettings(1, $data));
} elseif ($mode === 'portal-ticket') {
    // portal-ticket <patient_id> <appointment_id>: o bilhete ainda é emitido para esta consulta?
    $db = Connection::getInstance();
    $row = $db->query('SELECT clinic_id FROM appointments WHERE id = ' . (int)$argv[3])->fetch(PDO::FETCH_ASSOC);
    $t = (new \ClyoNutri\Domain\Telehealth\TeleconsultationService($db))->issuePortalTicket((int)$argv[2], (int)$argv[3], (int)$row['clinic_id']);
    echo json_encode(['ticket' => $t !== null]);
} elseif ($mode === 'del') {
    // del <appointment_id>: tira a consulta do caminho (com sessão de teleconsulta e pedidos).
    $db = Connection::getInstance();
    foreach (['appointment_change_requests', 'teleconsultation_sessions', 'appointment_status_history'] as $tbl) {
        try { $db->prepare("DELETE FROM {$tbl} WHERE appointment_id = ?")->execute([(int)$argv[2]]); } catch (\Throwable) { /* tabela ausente */ }
    }
    $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([(int)$argv[2]]);
    echo json_encode(['deleted' => (int)$argv[2]]);
} elseif ($mode === 'age-request') {
    // age-request <request_id>: o horário pedido passa a estar no passado (para o vencimento do pedido).
    Connection::getInstance()->prepare('UPDATE appointment_change_requests SET requested_scheduled_at = ? WHERE id = ?')->execute([$at(-1), (int)$argv[2]]);
    echo json_encode(['aged' => (int)$argv[2]]);
} elseif ($mode === 'staff-status') {
    // staff-status <appointment_id> <status>: a equipe muda o status pela agenda (motor da NI2-10).
    $db = Connection::getInstance();
    $row = $db->query('SELECT clinic_id FROM appointments WHERE id = ' . (int)$argv[2])->fetch(PDO::FETCH_ASSOC);
    TenantContext::set((int)$row['clinic_id'], ['id' => 1, 'role' => 'nutritionist']);
    echo json_encode(['ok' => (new \ClyoNutri\Domain\Appointment\AppointmentService($db))->updateStatus((int)$argv[2], (string)$argv[3], 'Alterado pela equipe (teste)', 1)]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) {
        throw new RuntimeException('Somente SELECT');
    }
    echo json_encode(Connection::getInstance()->query($q)->fetchAll(PDO::FETCH_ASSOC));
} else {
    throw new RuntimeException('Unknown fixture mode');
}
