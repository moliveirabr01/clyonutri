<?php

declare(strict_types=1);

/**
 * NI2-894 (v54.73): um aviso por evento de agenda ao paciente, sem marcador sem preencher.
 * Banco SQLite isolado, dados TESTE. Cobre agendar, confirmar, remarcar, cancelar, paciente sem canal e a trava de marcadores.
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Notification\NotificationService;
use ClyoNutri\Domain\Patient\PatientRepository;

/** @var PDO $pdo */
$db = $pdo;
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica A TESTE', 'ca@teste.local', 'active')");
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Nutri A TESTE', 'a@teste.local', ?, 'nutritionist', 1, 'active')")->execute([password_hash('x', PASSWORD_DEFAULT)]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '12345', 'CRN-3')");
$db->exec("INSERT OR IGNORE INTO booking_settings (clinic_id) VALUES (1)");
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'clinic_id' => 1]);

$repo = new PatientRepository($db);
$withMail = (int)$repo->create(['name' => 'Paciente Com Email TESTE', 'email' => 'com.email@teste.local', 'phone' => '11999990894'])['id'];
$noChannel = (int)$repo->create(['name' => 'Paciente Sem Canal TESTE', 'phone' => '11999990895'])['id'];

$events = static function (int $pid, ?string $type = null) use ($db): array {
    $sql = "SELECT id, event_type, title, body, dedupe_key FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?" . ($type ? ' AND event_type = ?' : '') . ' ORDER BY id';
    $st = $db->prepare($sql);
    $st->execute($type ? [$pid, $type] : [$pid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$deliveries = static function (int $eventId) use ($db): array {
    $st = $db->prepare('SELECT channel, status FROM notification_deliveries WHERE event_id = ? ORDER BY channel');
    $st->execute([$eventId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$noMarker = static fn(array $evs): bool => array_reduce($evs, static fn(bool $ok, array $e): bool => $ok && !preg_match('/\{[a-z_]+\}/', $e['title'] . ' ' . $e['body']), true);
$svc = new AppointmentService($db);
$day = (new DateTimeImmutable('+10 days', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

echo "=== 1. Agendar: um aviso, completo ===\n";
$app = $svc->schedule($withMail, 1, "{$day} 10:00:00", 'follow_up', 50, null, true, 1);
$sch = $events($withMail);
T::ok(count($sch) === 1 && $sch[0]['event_type'] === 'appointment.scheduled', 'Agendar gera exatamente 1 aviso ao paciente (' . count($sch) . ')');
T::ok(isset($sch[0]) && str_contains($sch[0]['body'], 'America/Sao_Paulo') && str_contains($sch[0]['body'], 'modalidade presencial'), 'Aviso traz fuso e modalidade');
T::ok($noMarker($sch), 'Nenhum {marcador} no aviso de agendamento');
$dl = isset($sch[0]) ? $deliveries((int)$sch[0]['id']) : [];
T::ok(array_column($dl, 'channel') === ['email', 'push'], 'Uma entrega por canal (e-mail e push)');
T::ok(count($events($withMail, 'appointment.scheduled')) === 1 && !str_starts_with((string)($sch[0]['dedupe_key'] ?? ''), 'appt.'), 'Não existe mais o segundo aviso appt.N.scheduled');

echo "=== 2. Confirmar pela clínica: um aviso ===\n";
$before = count($events($withMail));
$svc->updateStatus((int)$app['id'], AppointmentService::STATUS_CONFIRMED, null, 1);
$new = array_slice($events($withMail), $before);
T::ok(count($new) === 1 && $new[0]['event_type'] === 'appointment.confirmed', 'Confirmar gera exatamente 1 aviso (' . implode(',', array_column($new, 'event_type')) . ')');
T::ok(count($events($withMail, 'appointment.confirmed_by_clinic')) === 0, 'Sem o aviso duplicado appointment.confirmed_by_clinic');
T::ok($noMarker($new), 'Nenhum {marcador} no aviso de confirmação');

echo "=== 3. Remarcar pela clínica: um aviso ===\n";
$before = count($events($withMail));
$svc->edit((int)$app['id'], ['scheduled_at' => "{$day} 15:00:00"], true, 1);
$new = array_slice($events($withMail), $before);
T::ok(count($new) === 1 && $new[0]['event_type'] === 'appointment.rescheduled', 'Remarcar gera exatamente 1 aviso (' . implode(',', array_column($new, 'event_type')) . ')');
T::ok($noMarker($new) && isset($new[0]) && str_contains($new[0]['body'], '15:00'), 'Aviso de remarcação com o novo horário e sem marcador');

echo "=== 4. Cancelar pela clínica: um aviso ===\n";
$before = count($events($withMail));
$svc->updateStatus((int)$app['id'], AppointmentService::STATUS_CANCELLED, 'TESTE', 1);
$new = array_slice($events($withMail), $before);
T::ok(count($new) === 1 && $new[0]['event_type'] === 'appointment.cancelled_by_clinic', 'Cancelar gera exatamente 1 aviso (' . implode(',', array_column($new, 'event_type')) . ')');
T::ok($noMarker($new), 'Nenhum {marcador} no aviso de cancelamento');

echo "=== 5. Paciente sem canal validado não recebe nada ===\n";
$svc->schedule($noChannel, 1, "{$day} 11:00:00", 'follow_up', 50, null, true, 1);
(new NotificationService($db))->drain(500);
$st = $db->prepare("SELECT COUNT(*) FROM notification_deliveries d JOIN notification_events e ON e.id = d.event_id WHERE e.recipient_type = 'patient' AND e.recipient_id = ? AND d.status IN ('sent', 'logged')");
$st->execute([$noChannel]);
T::ok((int)$st->fetchColumn() === 0, 'Nenhuma entrega enviada para paciente sem e-mail nem app');
$st->execute([$withMail]);
T::ok((int)$st->fetchColumn() > 0, 'Controle: o paciente com e-mail teve entrega processada');

echo "=== 6. Trava: aviso com marcador sem valor não é enfileirado ===\n";
$n = new NotificationService($db);
$cnt = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM notification_events')->fetchColumn();
$c0 = $cnt();
$r = $n->publish(1, 'appointment.scheduled', 'patient', $withMail, 'teste.ni2894.trava', '/paciente/app', ['date' => '01/12/2026', 'time' => '10:00']);
T::ok(($r['blocked'] ?? '') === 'unfilled_placeholder' && $r['event_id'] === null, 'publish() devolve bloqueado quando sobra {timezone}/{modality}');
T::ok($cnt() === $c0, 'Nenhum evento gravado');
$aud = $db->query("SELECT details FROM audit_logs WHERE action = 'NOTIFICATION_BLOCKED_UNFILLED_PLACEHOLDER' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(is_string($aud) && str_contains($aud, 'timezone') && str_contains($aud, 'modality') && !str_contains($aud, 'com.email'), 'Bloqueio registrado na auditoria, sem dado do paciente');
$ok = $n->publish(1, 'appointment.reminder', 'patient', $withMail, 'teste.ni2894.ok', '/', ['date' => '01/12', 'time' => '10:00']);
T::ok(!empty($ok['event_id']), 'Aviso com todos os valores continua sendo enfileirado');

echo "=== 7. Nenhum aviso ao paciente no banco com marcador ===\n";
$left = (int)$db->query("SELECT COUNT(*) FROM notification_events WHERE body LIKE '%{%}%' OR title LIKE '%{%}%'")->fetchColumn();
T::ok($left === 0, "Nenhum evento gravado com {marcador} ({$left})");

T::done();
