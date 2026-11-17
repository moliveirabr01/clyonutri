<?php

declare(strict_types=1);

/**
 * NI2-879 (v54.72): consulta marcada para outro dia atendida hoje.
 * Cobre os cenários do Code Review 360° (CR879-02 a CR879-07) no nível de serviço, com banco SQLite isolado.
 * O fluxo pela tela (sala, controller, envio duplicado pelo navegador) é coberto pelo roteiro E2E da revisão.
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Patient\PatientRepository;

/** @var PDO $pdo */
$db = $pdo;
$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$todayBr = $now->format('d/m/Y');

$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica A TESTE', 'ca@teste.local', 'active')");
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (2, 'c2', 'Clínica B TESTE', 'cb@teste.local', 'active')");
$pw = password_hash('x', PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Nutri A TESTE', 'a@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pw]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (2, 'u2', 'Nutri B TESTE', 'b@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pw]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active'), (2, 2, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '12345', 'CRN-3'), (2, 2, '54321', 'CRN-3')");

TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'clinic_id' => 1]);
$repo = new PatientRepository($db);
$pid = (int)$repo->create(['name' => 'Paciente Outro Dia TESTE', 'email' => 'outro.dia@teste.local', 'phone' => '11999990879'])['id'];
$pid2 = (int)$repo->create(['name' => 'Paciente Horário Ocupado TESTE', 'email' => 'ocupado@teste.local', 'phone' => '11999990880'])['id'];

/** Insere uma consulta direto no banco (sem regras de jornada), como a agenda faria. */
$mk = static function (int $patient, string $scheduledAt, string $status = 'scheduled', int $duration = 50) use ($db): int {
    $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status, created_at, updated_at) VALUES (1, ?, 1, ?, ?, 'follow_up', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
        ->execute([$patient, $scheduledAt, $duration, $status]);
    return (int)$db->lastInsertId();
};
$row = static function (int $id) use ($db): array {
    $st = $db->prepare('SELECT status, scheduled_at FROM appointments WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$count = static fn(string $sql, array $args = []): int => (static function () use ($db, $sql, $args): int { $s = $db->prepare($sql); $s->execute($args); return (int)$s->fetchColumn(); })();
$svc = new AppointmentService($db);
$future = $now->modify('+30 days')->format('Y-m-d') . ' 10:15:00';

echo "=== 1. isDifferentDay com relógio injetado ===\n";
$fixed = new AppointmentService($db, new DateTimeImmutable('2026-11-16 21:07:00', $tz));
T::ok($fixed->isDifferentDay('2026-12-16 10:15:00'), 'Consulta de 16/12 vista em 16/11: outro dia');
T::ok($fixed->isDifferentDay('2026-11-15 10:00:00'), 'Consulta de ontem não iniciada: outro dia');
T::ok(!$fixed->isDifferentDay('2026-11-16 23:30:00'), 'Mesmo dia, mais tarde: não é outro dia');
T::ok(!$fixed->isDifferentDay('2026-11-16 08:00:00'), 'Mesmo dia, mais cedo: não é outro dia');

echo "=== 2. CR879-02: conclusão direta de consulta de outro dia é recusada ===\n";
$aDirect = $mk($pid, $future);
T::throws(fn() => $svc->completeConsultation($aDirect, 'Tentativa sem escolha TESTE', [], 1, 1), 'Concluir direto consulta futura agendada lança erro', 'agendada para');
T::ok(($row($aDirect)['status'] ?? '') === 'scheduled', 'Consulta futura continua agendada após a recusa');
T::ok($count('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', [$pid]) === 0, 'Nenhum registro de prontuário criado na recusa');

echo "=== 3. Opção 1: remarcar para agora ===\n";
$aRes = $mk($pid, $future);
$r = $svc->rescheduleForNowAndStart($aRes, 1);
$after = $row($aRes);
T::ok($r['rescheduled'] === true && $after['status'] === 'in_progress', 'Remarcada e em andamento');
T::ok(substr($after['scheduled_at'], 0, 10) === $today, 'Data da consulta passou a ser hoje');
$hist = (string)$db->query("SELECT notes FROM appointment_status_history WHERE appointment_id = {$aRes} AND to_status = 'in_progress' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(str_contains($hist, 'agendada originalmente para ' . date('d/m/Y', strtotime($future))), 'Histórico guarda a data original');
T::ok($count("SELECT COUNT(*) FROM audit_logs WHERE action = 'APPOINTMENT_RESCHEDULED_TO_NOW' AND resource_id = ?", [(string)$aRes]) === 1, 'Remarcação registrada na auditoria');
$again = $svc->rescheduleForNowAndStart($aRes, 1);
T::ok($again['rescheduled'] === false && $again['id'] === $aRes, 'Reenvio da remarcação não altera nada (idempotente)');
$res = $svc->completeConsultation($aRes, 'Evolução após remarcar TESTE', [], 1, 1);
$title = (string)$db->query('SELECT title FROM clinical_records WHERE id = ' . (int)$res['clinical_record_id'])->fetchColumn();
T::ok(str_contains($title, "({$todayBr})"), "Prontuário com a data de hoje ({$title})");

echo "=== 4. CR879-05: remarcar para agora sobre horário ocupado ===\n";
$busy = $mk($pid2, $now->format('Y-m-d H:i:00'), 'confirmed');
$aConf = $mk($pid, $now->modify('+12 days')->format('Y-m-d') . ' 16:00:00');
T::throws(fn() => $svc->rescheduleForNowAndStart($aConf, 1), 'Sem confirmação, conflito com outra consulta é recusado');
try { $svc->rescheduleForNowAndStart($aConf, 1); } catch (AppointmentConflictException $e) { T::ok((int)($e->getConflictingAppointment()['id'] ?? 0) === $busy, 'Erro informa a consulta que ocupa o horário'); }
T::ok(($row($aConf)['status'] ?? '') === 'scheduled' && substr($row($aConf)['scheduled_at'], 0, 10) !== $today, 'Recusada, a consulta fica como estava');
$ov = $svc->rescheduleForNowAndStart($aConf, 1, true);
T::ok($ov['overbooked_appointment_id'] === $busy && ($row($aConf)['status'] ?? '') === 'in_progress', 'Com confirmação, remarca como encaixe');
T::ok($count("SELECT COUNT(*) FROM audit_logs WHERE action = 'APPOINTMENT_OVERBOOK_AUTHORIZED' AND resource_id = ?", [(string)$aConf]) === 1, 'Encaixe registrado na auditoria');
$svc->completeConsultation($aConf, 'Encerrando encaixe TESTE', [], 1, 1);
$db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$busy]);

echo "=== 5. Opção 2: atendimento de hoje mantendo o agendamento (CR879-03, CR879-04, CR879-06) ===\n";
$aKeep = $mk($pid, $now->modify('+20 days')->format('Y-m-d') . ' 09:00:00');
$eventsBefore = $count("SELECT COUNT(*) FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?", [$pid]);
$appsBefore = $count('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$pid]);
$t1 = $svc->startTodayKeepingFuture($aKeep, 1);
$t2 = $svc->startTodayKeepingFuture($aKeep, 1);
T::ok($t1['created'] === true && ($row($t1['id'])['status'] ?? '') === 'in_progress', 'Atendimento de hoje criado e em andamento');
T::ok(substr($row($t1['id'])['scheduled_at'], 0, 10) === $today, 'Atendimento de hoje com a data de hoje');
T::ok($row($aKeep) === ['status' => 'scheduled', 'scheduled_at' => $now->modify('+20 days')->format('Y-m-d') . ' 09:00:00'], 'Agendamento futuro intacto');
T::ok($t2['created'] === false && $t2['id'] === $t1['id'], 'Segundo envio devolve o mesmo atendimento (sem duplicar)');
T::ok($count('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$pid]) === $appsBefore + 1, 'Só um atendimento novo no banco');
T::ok($count("SELECT COUNT(*) FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?", [$pid]) === $eventsBefore, 'Nenhum aviso de agendamento enviado ao paciente');
$notes = (string)$db->query("SELECT notes FROM appointments WHERE id = {$t1['id']}")->fetchColumn();
T::ok(str_contains($notes, "consulta #{$aKeep}"), 'Atendimento de hoje aponta a consulta de origem');
$res2 = $svc->completeConsultation($t1['id'], 'Evolução do atendimento de hoje TESTE', [], 1, 1);
$title2 = (string)$db->query('SELECT title FROM clinical_records WHERE id = ' . (int)$res2['clinical_record_id'])->fetchColumn();
T::ok(str_contains($title2, "({$todayBr})"), "Prontuário do atendimento de hoje com a data de hoje ({$title2})");
T::ok(($row($aKeep)['status'] ?? '') === 'scheduled', 'Depois de concluir hoje, o agendamento futuro continua agendado');
// Controle: neste banco, um agendamento comum gera aviso ao paciente; logo o "nenhum aviso" acima é significativo.
$ctrlBefore = $count("SELECT COUNT(*) FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?", [$pid]);
$db->exec("INSERT OR IGNORE INTO booking_settings (clinic_id) VALUES (1)");
try { $svc->schedule($pid, 1, $now->modify('+40 days')->format('Y-m-d') . ' 10:00:00', 'follow_up', 50, null, true, 1); } catch (\Throwable $e) { echo "  (controle: agendamento comum não aceito neste banco: {$e->getMessage()})\n"; }
T::ok($count("SELECT COUNT(*) FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?", [$pid]) > $ctrlBefore, 'Controle: agendamento comum gera aviso ao paciente');

echo "=== 6. Outra clínica não mexe na consulta ===\n";
$aOther = $mk($pid, $now->modify('+22 days')->format('Y-m-d') . ' 08:00:00');
TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist', 'clinic_id' => 2]);
T::throws(fn() => (new AppointmentService($db))->rescheduleForNowAndStart($aOther, 2), 'Clínica B não remarca consulta da clínica A');
T::throws(fn() => (new AppointmentService($db))->startTodayKeepingFuture($aOther, 2), 'Clínica B não cria atendimento a partir da consulta da clínica A');
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'clinic_id' => 1]);
T::ok(($row($aOther)['status'] ?? '') === 'scheduled', 'Consulta da clínica A intacta');

echo "=== 7. CR879-07: data efetiva para consultas antigas concluídas fora do dia ===\n";
$legacy = $mk($pid, $now->modify('+2 days')->format('Y-m-d') . ' 09:30:00', 'completed');
$db->prepare("INSERT INTO appointment_status_history (clinic_id, appointment_id, from_status, to_status, changed_by_user_id, notes, created_at) VALUES (1, ?, 'in_progress', 'completed', 1, 'legado TESTE', ?)")
    ->execute([$legacy, $now->modify('-1 hour')->format('Y-m-d H:i:s')]);
$profile = $repo->getProfile360($pid);
$entry = null;
foreach ($profile['timeline'] as $t) { if ($t['type'] === 'Consulta' && (int)$t['id'] === $legacy) { $entry = $t; } }
T::ok($entry !== null && substr((string)$entry['date'], 0, 10) === $today, 'Linha do tempo usa o dia em que a consulta foi concluída');
T::ok($entry !== null && str_contains((string)$entry['title'], 'agendada para ' . $now->modify('+2 days')->format('d/m')), 'Linha do tempo indica a data agendada');
$legacyRow = null;
foreach ($profile['appointments'] as $a) { if ((int)$a['id'] === $legacy) { $legacyRow = $a; } }
T::ok($legacyRow !== null && substr((string)$legacyRow['effective_at'], 0, 10) === $today, 'Lista de consultas da ficha traz a data efetiva');
$eff = AppointmentService::effectiveAtSql('a');
$last = $db->query("SELECT a.id, {$eff} AS effective_at FROM appointments a WHERE a.clinic_id = 1 AND a.patient_id = {$pid} AND a.status = 'completed' ORDER BY effective_at DESC, a.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
T::ok(substr((string)$last['effective_at'], 0, 10) === $today, '"Última consulta concluída" não aponta para data futura');

echo "=== 8. Regressão: mesmo dia segue sem a escolha ===\n";
$aSame = $mk($pid2, $now->format('Y-m-d') . ' 23:59:00');
T::ok(!$svc->isDifferentDay($row($aSame)['scheduled_at']), 'Consulta de hoje não pede a escolha');

T::done();
