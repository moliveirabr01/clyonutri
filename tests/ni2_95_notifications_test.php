<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * NI2-95 — domínio de notificações: eventos, preferências, silêncio, fila do cron, lembrete de consulta,
 * plano publicado, chat pelo domínio, caixa de avisos, isolamento entre clínicas e LGPD.
 * Dados sintéticos (TESTE). Roda em SQLite com as migrations aplicadas.
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\ArrayMailer;
use ClyoNutri\Domain\Notification\AppointmentReminderScheduler;
use ClyoNutri\Domain\Notification\ChatNotificationDispatcher;
use ClyoNutri\Domain\Notification\NotificationService;
use ClyoNutri\Domain\Privacy\LgpdService;

$db = Connection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = 0;
$fail = 0;
function check(string $id, string $desc, bool $ok, $detail = null): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS [{$id}] {$desc}\n";
    } else {
        $fail++;
        echo "FAIL [{$id}] {$desc}" . ($detail !== null ? ' :: ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
}
function one(PDO $db, string $sql, array $p = [])
{
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
}
function rows(PDO $db, string $sql, array $p = []): array
{
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------------ fixture TESTE
$u = bin2hex(random_bytes(3));
$mkClinic = function (string $name) use ($db, $u): int {
    $db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, ?, 'active')")->execute(['c-' . $u . $name, 'Clínica TESTE ' . $name]);
    return (int)$db->lastInsertId();
};
$mkProf = function (int $clinicId, string $name) use ($db, $u): int {
    $db->prepare('INSERT INTO users (uuid, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
        ->execute(['u-' . $u . $name, 'Nutri TESTE ' . $name, "nutri.{$name}.{$u}@example.test", 'x', 'nutritionist']);
    $uid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, ?)')->execute([$uid, '9' . random_int(10000, 99999), 'CRN-3']);
    return (int)$db->lastInsertId();
};
$mkPatient = function (int $clinicId, string $name, ?string $email = 'auto') use ($db, $u): int {
    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([$clinicId, 'p-' . $u . $name, 'Paciente TESTE ' . $name, $email === 'auto' ? "pac.{$name}.{$u}@example.test" : $email]);
    return (int)$db->lastInsertId();
};
$cA = $mkClinic('A');
$cB = $mkClinic('B');
$profA = $mkProf($cA, 'a');
$profB = $mkProf($cB, 'b');
$ana = $mkPatient($cA, 'ana');
$bia = $mkPatient($cA, 'bia');
$semEmail = $mkPatient($cA, 'sem', null);
$beto = $mkPatient($cB, 'beto');

$clockTs = strtotime(date('Y-m-d') . ' 10:00:00');
$clock = function () use (&$clockTs) { return $clockTs; };
$mailer = new ArrayMailer();
$svc = new NotificationService($db, $mailer, $clock);

check('S0', 'Migration 52 aplicada (tabelas do domínio existem)', $svc->available());

// ------------------------------------------------------------------ S1: publicação, fila e dedupe
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'meal_plan.published:1:v1', '/');
check('S1', 'Plano publicado: aviso criado e e-mail/push na fila (não entregam na hora)', $r['event_id'] > 0 && !$r['duplicate']
    && $r['deliveries']['email']['status'] === 'queued' && $r['deliveries']['push']['status'] === 'queued', $r);
$r2 = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'meal_plan.published:1:v1', '/');
check('S1', 'Mesma chave não cria segundo aviso nem segunda entrega', $r2['duplicate'] && $r2['event_id'] === $r['event_id']
    && (int)one($db, 'SELECT COUNT(*) FROM notification_deliveries WHERE event_id = ?', [$r['event_id']]) === 2);
check('S1', 'Nada sai antes do cron', count($mailer->sent) === 0);
$d = $svc->drain();
check('S1', 'Cron entrega o e-mail (sent) e o push fica bloqueado sem provedor', $d['sent'] === 1 && $d['blocked'] === 1 && $d['pending'] === 0, $d);
$st = array_column(rows($db, 'SELECT channel, status, reason, attempts FROM notification_deliveries WHERE event_id = ?', [$r['event_id']]), null, 'channel');
check('S1', 'Status real gravado por canal', $st['email']['status'] === 'sent' && (int)$st['email']['attempts'] === 1 && $st['push']['status'] === 'blocked' && $st['push']['reason'] === 'push_provider_not_configured', $st);
$m = $mailer->sent[0] ?? null;
check('S1', 'E-mail para a paciente, texto neutro (sem nome, sem dado clínico)', $m && $m->toAddress === "pac.ana.{$u}@example.test"
    && $m->subject === 'Plano alimentar disponível' && !str_contains($m->textBody . $m->htmlBody, 'Paciente TESTE') && !preg_match('/kcal|alerg|peso/i', $m->textBody));
check('S1', 'Segundo ciclo do cron não reenvia nada', $svc->drain()['processed'] === 0 && count($mailer->sent) === 1);
try {
    $svc->publish($cA, 'evento.inventado', 'patient', $ana, 'x');
    check('S1', 'Evento fora do catálogo é recusado', false);
} catch (InvalidArgumentException $e) {
    check('S1', 'Evento fora do catálogo é recusado', true);
}

// ------------------------------------------------------------------ S2: preferências por categoria
$svc->savePreferences($cA, 'patient', $bia, ['categories' => ['plan' => ['email_enabled' => false]]]);
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $bia, 'meal_plan.published:2:v1', '/');
$svc->drain();
$e = rows($db, "SELECT status, reason FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']])[0];
check('S2', 'E-mail de plano desligado pela paciente: skipped/preference_off, aviso continua no app', $e['status'] === 'skipped' && $e['reason'] === 'preference_off'
    && $svc->unreadCount($cA, 'patient', $bia) === 1, $e);
$prefs = $svc->preferences($cA, 'patient', $bia);
$byCat = array_column($prefs['categories'], null, 'category');
check('S2', 'Só a categoria alterada mudou; o resto segue no padrão', $byCat['plan']['email_enabled'] === false && $byCat['plan']['push_enabled'] === true
    && $byCat['appointments']['email_enabled'] === true && $byCat['chat']['email_enabled'] === true && $prefs['push_available'] === false);
foreach ([
    ['categories' => ['financeiro' => ['email_enabled' => false]]],
    ['categories' => ['chat' => ['email_enabled' => 'nao']]],
    ['categories' => ['chat' => ['sms_enabled' => true]]],
    ['quiet_hours' => ['enabled' => true, 'start' => '25:00']],
    ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '22:00']],
    [],
] as $i => $bad) {
    try {
        $svc->savePreferences($cA, 'patient', $bia, $bad);
        check('S2', "Entrada inválida #{$i} é recusada", false);
    } catch (InvalidArgumentException $ex) {
        check('S2', "Entrada inválida #{$i} é recusada ({$ex->getMessage()})", true);
    }
}
check('S2', 'Recusa não grava nada pela metade', (int)one($db, 'SELECT COUNT(*) FROM notification_preferences WHERE recipient_id = ? AND recipient_type = ?', [$bia, 'patient']) === 1
    && (int)one($db, 'SELECT COUNT(*) FROM notification_quiet_hours WHERE recipient_id = ?', [$bia]) === 0);

// ------------------------------------------------------------------ S3: horário de silêncio
check('S3', 'Janela que vira a meia-noite', NotificationService::inQuietHours(['enabled' => true, 'start' => '22:00', 'end' => '07:00'], '23:30')
    && NotificationService::inQuietHours(['enabled' => true, 'start' => '22:00', 'end' => '07:00'], '06:59')
    && !NotificationService::inQuietHours(['enabled' => true, 'start' => '22:00', 'end' => '07:00'], '07:00')
    && NotificationService::inQuietHours(['enabled' => true, 'start' => '13:00', 'end' => '14:00'], '13:30') && !NotificationService::inQuietHours(['enabled' => true, 'start' => '13:00', 'end' => '14:00'], '14:00')
    && !NotificationService::inQuietHours(['enabled' => false, 'start' => '00:00', 'end' => '23:59'], '12:00'));
$svc->savePreferences($cA, 'patient', $ana, ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']]);
$clockTs = strtotime(date('Y-m-d') . ' 23:00:00');
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'meal_plan.published:3:v1', '/');
$before = count($mailer->sent);
$svc->drain();
$e = rows($db, "SELECT status, reason, next_attempt_at FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']])[0];
check('S3', 'Plano às 23h com silêncio 22h–07h: e-mail adiado para 07:00 do dia seguinte', $e['status'] === 'queued' && $e['reason'] === 'quiet_hours'
    && $e['next_attempt_at'] === date('Y-m-d', strtotime('+1 day', $clockTs)) . ' 07:00:00' && count($mailer->sent) === $before, $e);
$clockTs = strtotime(date('Y-m-d', $clockTs) . ' +1 day 06:45:00');
$svc->drain();
check('S3', 'Às 06:45 ainda não sai', count($mailer->sent) === $before);
$clockTs += 20 * 60;
$svc->drain();
check('S3', 'Às 07:05 o cron entrega', count($mailer->sent) === $before + 1
    && one($db, "SELECT status FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']]) === 'sent');

// ------------------------------------------------------------------ S4: falha e nova tentativa
$clockTs = strtotime(date('Y-m-d') . ' 10:00:00');
$mailer->fail = true;
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $bia, 'meal_plan.published:4:v1', '/');
$svc->savePreferences($cA, 'patient', $bia, ['categories' => ['plan' => ['email_enabled' => true]]]);
$svc->drain();
$e = rows($db, "SELECT status, reason, attempts, next_attempt_at FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']])[0];
check('S4', 'Falha de SMTP: volta para a fila com motivo e espera (1ª tentativa)', $e['status'] === 'queued' && $e['reason'] === 'mail_error' && (int)$e['attempts'] === 1
    && $e['next_attempt_at'] === date('Y-m-d H:i:s', $clockTs + 900), $e);
$clockTs += 901;
$svc->drain();
$clockTs += 1801;
$svc->drain();
$e = rows($db, "SELECT status, reason, attempts FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']])[0];
check('S4', 'Depois de 3 tentativas fica failed (não tenta para sempre)', $e['status'] === 'failed' && (int)$e['attempts'] === 3 && $e['reason'] === 'mail_error', $e);
$mailer->fail = false;
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $semEmail, 'meal_plan.published:5:v1', '/');
$svc->drain();
check('S4', 'Paciente sem e-mail: skipped/no_email', one($db, "SELECT reason FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']]) === 'no_email');
// Entrega presa em "sending" (processo morto) volta para a fila depois de 30 min.
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'meal_plan.published:6:v1', '/');
$db->prepare("UPDATE notification_deliveries SET status = 'sending', updated_at = ? WHERE event_id = ?")->execute([date('Y-m-d H:i:s', $clockTs - 3600), $r['event_id']]);
$d = $svc->drain();
check('S4', 'Entrega presa em sending é retomada', one($db, "SELECT status FROM notification_deliveries WHERE event_id = ? AND channel = 'email'", [$r['event_id']]) === 'sent', $d);

// ------------------------------------------------------------------ S5: lembrete de consulta
$mkAppt = function (int $clinicId, int $patientId, int $profId, int $ts, string $status = 'scheduled') use ($db): int {
    $db->prepare('INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$clinicId, $patientId, $profId, date('Y-m-d H:i:s', $ts), $status]);
    return (int)$db->lastInsertId();
};
$clockTs = strtotime(date('Y-m-d') . ' 09:00:00');
$apOk = $mkAppt($cA, $bia, $profA, $clockTs + 23 * 3600);
$apConf = $mkAppt($cA, $ana, $profA, $clockTs + 5 * 3600, 'confirmed');
$apLonge = $mkAppt($cA, $bia, $profA, $clockTs + 30 * 3600);
$apCanc = $mkAppt($cA, $bia, $profA, $clockTs + 20 * 3600, 'cancelled');
$apAgora = $mkAppt($cA, $bia, $profA, $clockTs + 10 * 60);
$apB = $mkAppt($cB, $beto, $profB, $clockTs + 10 * 3600);
// Isolamento: o banco de teste é compartilhado com outros testes da suíte, que deixam consultas futuras (ex.: NI2-292 às 14:30).
// As que não são desta fixture ficam canceladas só durante o S5 e voltam ao status original depois.
$mineIds = [$apOk, $apConf, $apLonge, $apCanc, $apAgora, $apB];
$parked = rows($db, "SELECT id, status FROM appointments WHERE status IN ('scheduled', 'confirmed') AND id NOT IN (" . implode(',', $mineIds) . ')');
foreach ($parked as $pk) {
    $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$pk['id']]);
}
$sched = new AppointmentReminderScheduler($db, $svc, $clock);
$rep = $sched->schedule(24);
$rem = rows($db, "SELECT clinic_id, recipient_id, body, dedupe_key FROM notification_events WHERE event_type = 'appointment.reminder' ORDER BY id");
$keys = array_column($rem, 'dedupe_key');
check('S5', 'Lembretes só para consultas ativas entre 15 min e 24 h (cancelada, distante e em 10 min ficam de fora)', $rep['created'] === 3 && count($rem) === 3
    && !preg_grep("/:{$apLonge}:|:{$apCanc}:|:{$apAgora}:/", $keys), [$rep, $keys]);
check('S5', 'Cada lembrete vai para a paciente certa, na clínica certa', in_array(['clinic_id' => (string)$cB, 'recipient_id' => (string)$beto], array_map(fn($x) => ['clinic_id' => (string)$x['clinic_id'], 'recipient_id' => (string)$x['recipient_id']], $rem), true));
$bodyOk = array_values(array_filter($rem, fn($x) => str_contains($x['dedupe_key'], ":{$apOk}:")))[0]['body'] ?? '';
check('S5', 'Texto com data e hora, sem profissional nem local', str_contains($bodyOk, date('d/m', $clockTs + 23 * 3600)) && str_contains($bodyOk, date('H:i', $clockTs + 23 * 3600))
    && !str_contains($bodyOk, 'Nutri TESTE'), $bodyOk);
$rep2 = $sched->schedule(24);
check('S5', 'Cron seguinte não duplica lembrete', $rep2['created'] === 0 && $rep2['duplicates'] === 3, $rep2);
$db->prepare('UPDATE appointments SET scheduled_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', $clockTs + 22 * 3600), $apOk]);
$rep3 = $sched->schedule(24);
check('S5', 'Consulta remarcada ganha lembrete novo', $rep3['created'] === 1, $rep3);
$db->prepare("UPDATE patients SET status = 'inactive' WHERE id = ?")->execute([$beto]);
$apB2 = $mkAppt($cB, $beto, $profB, $clockTs + 12 * 3600);
check('S5', 'Paciente inativa não recebe lembrete', $sched->schedule(24)['created'] === 0);
$db->prepare("UPDATE patients SET status = 'active' WHERE id = ?")->execute([$beto]);
$svc->savePreferences($cA, 'patient', $bia, ['categories' => ['appointments' => ['email_enabled' => false]]]);
$svc->drain();
foreach ($parked as $pk) {
    $db->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$pk['status'], $pk['id']]);
}
$bReason = one($db, "SELECT d.reason FROM notification_deliveries d JOIN notification_events e ON e.id = d.event_id WHERE e.recipient_id = ? AND e.event_type = 'appointment.reminder' AND d.channel = 'email' ORDER BY d.id LIMIT 1", [$bia]);
check('S5', 'Lembrete respeita a preferência de consultas da paciente', $bReason === 'preference_off', $bReason);

// ------------------------------------------------------------------ S6: chat pelo domínio
$db->prepare("INSERT INTO chat_conversations (clinic_id, patient_id, professional_id, status) VALUES (?, ?, ?, 'active')")->execute([$cA, $ana, $profA]);
$conv = (int)$db->lastInsertId();
$chat = new ChatNotificationDispatcher($db, $mailer);
$n = count($mailer->sent);
$res = $chat->notifyNewMessage($cA, $conv, 9001, 'professional', $profA);
check('S6', 'Mensagem nova: no app (sent), e-mail na hora (sent), push bloqueado', ($res['in_app']['status'] ?? '') === 'sent' && ($res['email']['status'] ?? '') === 'sent'
    && ($res['push']['status'] ?? '') === 'blocked' && count($mailer->sent) === $n + 1, $res);
check('S6', 'Histórico do chat (chat_notifications_log) continua sendo gravado', (int)one($db, 'SELECT COUNT(*) FROM chat_notifications_log WHERE message_id = 9001') === 3);
check('S6', 'O aviso entra na caixa do profissional com link para a conversa', one($db, "SELECT link FROM notification_events WHERE recipient_type = 'professional' AND recipient_id = ? AND dedupe_key = 'chat.message:9001'", [$profA]) === '/chat/' . $conv);
$mm = end($mailer->sent);
check('S6', 'E-mail do chat sem conteúdo e com o aviso de urgência', $mm->subject === 'Nova mensagem no ClyoNutri' && str_contains($mm->textBody, \ClyoNutri\Domain\Chat\ChatSettingsService::EMERGENCY_NOTICE));
$res = $chat->notifyNewMessage($cA, $conv, 9002, 'professional', $profA);
check('S6', 'Segunda mensagem em 30 min: e-mail pulado (recent_email)', ($res['email']['status'] ?? '') === 'skipped' && $res['email']['reason'] === 'recent_email', $res);
check('S6', 'Repetir a mesma mensagem não gera novo aviso', $chat->notifyNewMessage($cA, $conv, 9002, 'professional', $profA) === []
    && (int)one($db, 'SELECT COUNT(*) FROM chat_notifications_log WHERE message_id = 9002') === 3);
// Preferência antiga do NI2-138 (chat_preferences) continua valendo até a pessoa salvar a nova.
$db->prepare("INSERT INTO chat_preferences (clinic_id, user_type, user_id, email_enabled, push_enabled, quiet_hours_enabled) VALUES (?, 'patient', ?, 0, 0, 0)")->execute([$cA, $semEmail]);
$res = $chat->notifyNewMessage($cA, $conv, 9003, 'patient', $semEmail);
check('S6', 'chat_preferences antiga ainda é respeitada (e-mail e push desligados)', $res['email']['reason'] === 'preference_off' && $res['push']['status'] === 'skipped', $res);
$svc->savePreferences($cA, 'patient', $semEmail, ['categories' => ['chat' => ['push_enabled' => true]]]);
$p = array_column($svc->preferences($cA, 'patient', $semEmail)['categories'], null, 'category')['chat'];
check('S6', 'Salvar a preferência nova parte do valor antigo (só muda o que foi enviado)', $p['email_enabled'] === false && $p['push_enabled'] === true, $p);
$svc->savePreferences($cA, 'professional', $profA, ['quiet_hours' => ['enabled' => true, 'start' => '08:00', 'end' => '12:00']]);
$res = $chat->notifyNewMessage($cA, $conv, 9004, 'professional', $profA);
// Este caminho usa o relógio real; só confere quando o relógio real está dentro da janela.
$realQuiet = NotificationService::inQuietHours(['enabled' => true, 'start' => '08:00', 'end' => '12:00'], date('H:i'));
check('S6', 'Silêncio no chat descarta o e-mail (a mensagem já está no app)', !$realQuiet || $res['email']['reason'] === 'quiet_hours', $res);

// ------------------------------------------------------------------ S7: caixa de avisos e isolamento
$inbox = $svc->inbox($cA, 'patient', $ana, null, 2);
check('S7', 'Caixa da paciente: mais novos primeiro, paginada', count($inbox['items']) === 2 && $inbox['items'][0]['id'] > $inbox['items'][1]['id'] && $inbox['next_before_id'] === $inbox['items'][1]['id'], $inbox);
$page2 = $svc->inbox($cA, 'patient', $ana, $inbox['next_before_id'], 50);
check('S7', 'Segunda página continua de onde parou, sem repetir', !array_intersect(array_column($page2['items'], 'id'), array_column($inbox['items'], 'id')) && $page2['next_before_id'] === null);
$all = array_merge($inbox['items'], $page2['items']);
check('S7', 'Só avisos da própria paciente', (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE clinic_id = ? AND recipient_type = 'patient' AND recipient_id = ?", [$cA, $ana]) === count($all));
$biaEvent = (int)one($db, "SELECT id FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ? LIMIT 1", [$bia]);
$betoEvent = (int)one($db, "SELECT id FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ? LIMIT 1", [$beto]);
check('S7', 'Marcar como lido aviso de outra paciente ou de outra clínica: não encontrado', !$svc->markRead($cA, 'patient', $ana, $biaEvent) && !$svc->markRead($cA, 'patient', $ana, $betoEvent)
    && !$svc->markRead($cB, 'patient', $ana, $all[0]['id']) && one($db, 'SELECT read_at FROM notification_events WHERE id = ?', [$biaEvent]) === null);
$unread = $svc->unreadCount($cA, 'patient', $ana);
check('S7', 'Marcar um como lido baixa o contador em 1 (e repetir não muda)', $svc->markRead($cA, 'patient', $ana, $all[0]['id']) && $svc->markRead($cA, 'patient', $ana, $all[0]['id'])
    && $svc->unreadCount($cA, 'patient', $ana) === $unread - 1);
check('S7', 'Marcar todos como lidos zera só a própria caixa', $svc->markAllRead($cA, 'patient', $ana) === $unread - 1 && $svc->unreadCount($cA, 'patient', $ana) === 0
    && $svc->unreadCount($cA, 'patient', $bia) > 0);
check('S7', 'Mesmo id de destinatário em outra clínica não enxerga a caixa', $svc->inbox($cB, 'patient', $ana)['items'] === []);

// ------------------------------------------------------------------ S8: plano publicado de verdade (MealPlanService)
$planOk = false;
try {
    TenantContext::set($cA, ['id' => (int)one($db, 'SELECT user_id FROM professionals WHERE id = ?', [$profA]), 'role' => 'nutritionist', 'name' => 'Nutri TESTE a']);
    $db->prepare("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status, daily_energy_kcal) VALUES (?, ?, ?, 'Plano TESTE', 'draft', 1800)")->execute([$cA, $bia, $profA]);
    $planId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO meal_plan_meals (meal_plan_id, name, time_of_day, ordering) VALUES (?, 'Café TESTE', '08:00', 1)")->execute([$planId]);
    $mealId = (int)$db->lastInsertId();
    $foodId = (int)one($db, 'SELECT id FROM foods ORDER BY id LIMIT 1');
    $db->prepare('INSERT INTO meal_plan_items (meal_id, food_id, amount, measure_unit, weight_grams) VALUES (?, ?, 100, "g", 100)')->execute([$mealId, $foodId]);
    (new \ClyoNutri\Domain\MealPlan\MealPlanService($db))->publishPlan($planId, true, false, 'Teste sintético NI2-95 sem alergia');
    $planOk = (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE event_type = 'meal_plan.published' AND recipient_id = ? AND dedupe_key LIKE ?", [$bia, "meal_plan.published:{$planId}:v%"]) === 1;
    check('S8', 'Publicar o plano cria o aviso para a paciente', $planOk);
} catch (Throwable $ex) {
    check('S8', 'Publicar o plano cria o aviso para a paciente', false, get_class($ex) . ': ' . $ex->getMessage());
}

// ------------------------------------------------------------------ S9: LGPD
TenantContext::set($cA, ['id' => 1, 'role' => 'owner', 'name' => 'TESTE']);
$export = (new LgpdService($db))->exportPatientData($bia);
check('S9', 'Exportação LGPD leva avisos e preferências da paciente', !empty($export['notifications']) && isset($export['notification_preferences']['categories'])
    && !array_diff(array_keys($export['notifications'][0]), ['id', 'event_type', 'category', 'title', 'body', 'created_at', 'read_at']));
(new LgpdService($db))->anonymizePatient($bia);
check('S9', 'Anonimização apaga avisos, entregas pendentes e preferências da paciente', (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?", [$bia]) === 0
    && (int)one($db, "SELECT COUNT(*) FROM notification_preferences WHERE recipient_type = 'patient' AND recipient_id = ?", [$bia]) === 0
    && (int)one($db, 'SELECT COUNT(*) FROM notification_deliveries d LEFT JOIN notification_events e ON e.id = d.event_id WHERE e.id IS NULL') === 0
    && (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE recipient_type = 'patient' AND recipient_id = ?", [$ana]) > 0);

// ------------------------------------------------------------------ S10: cron
$cronSrc = file_get_contents(__DIR__ . '/../bin/cron_scheduler.php');
check('S10', 'Cron não conta mais a tabela antiga nem diz DRAINED sem entregar', !str_contains($cronSrc, 'unread_notifications') && !str_contains($cronSrc, "'DRAINED'")
    && str_contains($cronSrc, 'AppointmentReminderScheduler') && str_contains($cronSrc, '->drain('));

echo "\nResultado NI2-95: {$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);
