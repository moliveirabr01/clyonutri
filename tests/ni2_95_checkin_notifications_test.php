<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * NI2-95 — avisos do check-in diário (NI2-96): lembrete opt-in da paciente e aviso de check-in recebido para o
 * profissional (caixa /avisos + e-mail com opt-out, no máximo um por dia). Também confere que entregas e logs só
 * guardam códigos de falha, nunca texto de mensagem ou dado clínico.
 * Dados sintéticos (TESTE). Roda em SQLite e em MySQL/MariaDB com as migrations aplicadas; transporte do Expo falso.
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Mail\ArrayMailer;
use ClyoNutri\Core\Mail\MailMessage;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Domain\Notification\CheckinNotificationScheduler;
use ClyoNutri\Domain\Notification\ExpoPushClient;
use ClyoNutri\Domain\Notification\NotificationService;
use ClyoNutri\Domain\Notification\PushTokenService;
use ClyoNutri\Domain\Privacy\LgpdService;

putenv('PUSH_ENABLED');
putenv('EXPO_ACCESS_TOKEN');
unset($_ENV['PUSH_ENABLED'], $_ENV['EXPO_ACCESS_TOKEN']);

$db = Connection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
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
function errOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return null;
}
function dst(PDO $db, $eventId, string $channel): string
{
    $r = rows($db, 'SELECT status, reason FROM notification_deliveries WHERE event_id = ? AND channel = ?', [$eventId, $channel])[0] ?? null;
    return $r ? $r['status'] . ':' . $r['reason'] : '';
}
function ev(PDO $db, int $clinic, string $type, int $recipient, ?string $link = null): array
{
    return rows($db, 'SELECT * FROM notification_events WHERE clinic_id = ? AND event_type = ? AND recipient_id = ?' . ($link ? ' AND link = ?' : '') . ' ORDER BY id',
        array_merge([$clinic, $type, $recipient], $link ? [$link] : []));
}

// ------------------------------------------------------------------ fixture TESTE
$u = bin2hex(random_bytes(3));
$mkClinic = function (string $name) use ($db, $u): int {
    $db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, ?, 'active')")->execute(['ck-' . $u . $name, 'Clínica TESTE ' . $name]);
    return (int)$db->lastInsertId();
};
$mkProf = function (string $name) use ($db, $u): int {
    $db->prepare('INSERT INTO users (uuid, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
        ->execute(['uck-' . $u . $name, 'Nutri TESTE ' . $name, "nutri.ck.{$name}.{$u}@example.test", 'x', 'nutritionist']);
    $uid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, ?)')->execute([$uid, '8' . random_int(10000, 99999), 'CRN-3']);
    return (int)$db->lastInsertId();
};
$mkPatient = function (int $clinicId, string $name) use ($db, $u): int {
    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([$clinicId, 'pck-' . $u . $name, 'Paciente TESTE ' . ucfirst($name), "pac.ck.{$name}.{$u}@example.test"]);
    return (int)$db->lastInsertId();
};
$link = function (int $clinic, int $patient, int $pro) use ($db): void {
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO patient_professional_links (clinic_id, patient_id, professional_id, status, source, started_at, created_at, updated_at) VALUES (?, ?, ?, 'active', 'manual', ?, ?, ?)")
        ->execute([$clinic, $patient, $pro, $now, $now, $now]);
};
$NOTE = 'Nota clínica TESTE: comi pouco, adesão 40%';
$checkin = function (int $clinic, int $patient, string $day) use ($db, $NOTE, &$hasUpdatedAt): int {
    $db->prepare('INSERT INTO checkins (clinic_id, patient_id, energy_level, hunger_level, meal_adherence_pct, notes, checkin_date) VALUES (?, ?, 2, 4, 40, ?, ?)')
        ->execute([$clinic, $patient, $NOTE, $day]);
    return (int)$db->lastInsertId();
};
$tok = fn(string $s) => 'ExponentPushToken[' . str_pad(substr(preg_replace('/[^A-Za-z0-9]/', '', $s . $u), 0, 40), 22, 'x') . ']';

$cA = $mkClinic('A');
$cB = $mkClinic('B');
$profA = $mkProf('a');
$profA2 = $mkProf('a2');
$profB = $mkProf('b');
$ana = $mkPatient($cA, 'ana');
$bia = $mkPatient($cA, 'bia');
$caio = $mkPatient($cA, 'caio');
$dani = $mkPatient($cA, 'dani');
$eva = $mkPatient($cA, 'eva');
$beto = $mkPatient($cB, 'beto');

$today = date('Y-m-d');
$clockTs = strtotime($today . ' 10:00:00');
$clock = function () use (&$clockTs) { return $clockTs; };
$setClock = function (string $hhmm, int $dayOffset = 0) use (&$clockTs, $today): void {
    $clockTs = strtotime($today . ' ' . $hhmm . ':00') + $dayOffset * 86400;
};
$calls = [];
$transport = function (string $url, string $body, array $headers) use (&$calls): array {
    $payload = json_decode($body, true);
    $calls[] = ['url' => $url, 'payload' => $payload];
    if (str_ends_with($url, '/send')) {
        return ['status' => 200, 'body' => json_encode(['data' => array_map(fn($m) => ['status' => 'ok', 'id' => 'tk-' . substr(md5($m['to'] . microtime()), 0, 12)], $payload)])];
    }
    return ['status' => 200, 'body' => json_encode(['data' => array_fill_keys($payload['ids'], ['status' => 'ok'])])];
};
$mailer = new ArrayMailer();
$svc = new NotificationService($db, $mailer, $clock, new ExpoPushClient($transport, null));
$sched = new CheckinNotificationScheduler($db, $svc, $clock);
$tokens = new PushTokenService($db, $clock);

// ------------------------------------------------------------------ C0: sem check-in diário no app
// A base sem NI2-96 não tem checkins.updated_at: o lembrete não aparece nem pode ser ligado.
if (SchemaInfo::hasColumn($db, 'checkins', 'updated_at')) {
    $db->exec('ALTER TABLE checkins DROP COLUMN updated_at');
    SchemaInfo::reset();
}
$p = $svc->preferences($cA, 'patient', $ana);
check('C0', 'Sem check-in diário (NI2-96) o lembrete fica indisponível e a categoria não aparece para a paciente',
    CheckinNotificationScheduler::reminderAvailable($db) === false && $p['checkin_reminder'] === ['available' => false, 'enabled' => false, 'time' => '20:00']
    && !in_array('checkin', array_column($p['categories'], 'category'), true), $p);
check('C0', 'Ligar o lembrete sem check-in diário no app é recusado com texto claro',
    errOf(fn() => $svc->savePreferences($cA, 'patient', $ana, ['checkin_reminder' => ['enabled' => true]])) === 'O lembrete de check-in ainda não está disponível.');

// ------------------------------------------------------------------ C1: com o check-in diário (coluna da migration 49 do NI2-96)
$db->exec('ALTER TABLE checkins ADD COLUMN updated_at DATETIME NULL');
SchemaInfo::reset();
$p = $svc->preferences($cA, 'patient', $ana);
$cat = array_values(array_filter($p['categories'], fn($c) => $c['category'] === 'checkin'))[0] ?? null;
check('C1', 'Com check-in diário o lembrete aparece, desligado, às 20:00', $p['checkin_reminder'] === ['available' => true, 'enabled' => false, 'time' => '20:00'], $p['checkin_reminder']);
check('C1', 'Categoria "Check-in diário" da paciente: celular ligado, e-mail desligado por padrão',
    $cat && $cat['label'] === 'Check-in diário' && $cat['email_enabled'] === false && $cat['push_enabled'] === true, $cat);
$pp = $svc->preferences($cA, 'professional', $profA);
$pcat = array_values(array_filter($pp['categories'], fn($c) => $c['category'] === 'checkin'))[0] ?? null;
check('C1', 'Profissional: e-mail de check-in recebido ligado por padrão e sem chave de lembrete', $pcat && $pcat['email_enabled'] === true && !array_key_exists('checkin_reminder', $pp), $pp);

// ------------------------------------------------------------------ C2: opt-in
$setClock('20:30');
$r = $sched->schedule();
check('C2', 'Ninguém ligou o lembrete: o cron não cria nada', $r['status'] === 'OK'
    && (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE event_type = 'checkin.reminder' AND clinic_id IN (?, ?)", [$cA, $cB]) === 0, $r);

// ------------------------------------------------------------------ C3: preferências
check('C3', 'Hora fora do formato é recusada', errOf(fn() => $svc->savePreferences($cA, 'patient', $ana, ['checkin_reminder' => ['enabled' => true, 'time' => '25:00']])) === 'Informe a hora do lembrete no formato HH:MM, por exemplo 20:00.');
check('C3', 'Ligar com valor que não é verdadeiro/falso é recusado', errOf(fn() => $svc->savePreferences($cA, 'patient', $ana, ['checkin_reminder' => ['enabled' => 'sim']])) === 'Use verdadeiro ou falso para ligar ou desligar o lembrete de check-in.');
check('C3', 'Profissional não tem lembrete de check-in', errOf(fn() => $svc->savePreferences($cA, 'professional', $profA, ['checkin_reminder' => ['enabled' => true]])) !== null);
check('C3', 'Recusa não grava nada', (int)one($db, 'SELECT COUNT(*) FROM notification_checkin_settings WHERE patient_id = ?', [$ana]) === 0);
$res = $svc->savePreferences($cA, 'patient', $ana, ['checkin_reminder' => ['enabled' => true, 'time' => '19:00'], 'categories' => ['checkin' => ['email_enabled' => true]]]);
check('C3', 'Paciente liga o lembrete às 19:00 (e o e-mail) e a resposta mostra o que ficou salvo',
    $res['after']['checkin_reminder'] === ['available' => true, 'enabled' => true, 'time' => '19:00'] && $res['before']['checkin_reminder']['enabled'] === false, $res['after']['checkin_reminder']);
$svc->savePreferences($cA, 'patient', $ana, ['checkin_reminder' => ['time' => '19:00']]);
check('C3', 'Salvar de novo não duplica a linha', (int)one($db, 'SELECT COUNT(*) FROM notification_checkin_settings WHERE patient_id = ?', [$ana]) === 1);
$svc->savePreferences($cA, 'patient', $bia, ['checkin_reminder' => ['enabled' => true, 'time' => '08:00']]);
$svc->savePreferences($cA, 'patient', $caio, ['checkin_reminder' => ['enabled' => false, 'time' => '19:00']]);
$svc->savePreferences($cA, 'patient', $dani, ['checkin_reminder' => ['enabled' => true, 'time' => '18:00']]);
$svc->savePreferences($cB, 'patient', $beto, ['checkin_reminder' => ['enabled' => true, 'time' => '22:30'], 'categories' => ['checkin' => ['email_enabled' => true]],
    'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']]);

// ------------------------------------------------------------------ C4: hora, janela e um por dia
$checkin($cA, $dani, $today); // Dani já fez o check-in de hoje
$setClock('18:59');
$r = $sched->schedule();
check('C4', 'Antes da hora escolhida não lembra (e quem já fez o check-in não recebe)', $r['reminders']['already_done'] >= 1
    && ev($db, $cA, 'checkin.reminder', $ana) === [] && ev($db, $cA, 'checkin.reminder', $dani) === [], $r);
$setClock('19:05');
putenv('PUSH_ENABLED=true');
$tokens->register($cA, 'patient', $ana, $tok('ana'), 'ios');
$r = $sched->schedule();
$e = ev($db, $cA, 'checkin.reminder', $ana);
check('C4', 'Na hora escolhida sai um lembrete para a Ana', $r['reminders']['created'] >= 1 && count($e) === 1 && $e[0]['dedupe_key'] === 'checkin.reminder:' . $ana . ':' . date('Ymd', $clockTs), $r);
check('C4', 'Bia marcou 08:00: às 19:05 a janela de 3 h já passou, não lembra fora de hora', ev($db, $cA, 'checkin.reminder', $bia) === []);
check('C4', 'Caio deixou desligado: nada', ev($db, $cA, 'checkin.reminder', $caio) === []);
$setClock('19:30');
$r = $sched->schedule();
check('C4', 'Rodar o cron de novo no mesmo dia não duplica (idempotente)', $r['reminders']['duplicates'] >= 1 && count(ev($db, $cA, 'checkin.reminder', $ana)) === 1, $r);

// ------------------------------------------------------------------ C5: texto neutro e canais
$calls = [];
$mailer->sent = [];
$svc->drain();
$e = ev($db, $cA, 'checkin.reminder', $ana)[0];
$push = $calls[0]['payload'][0] ?? [];
// O banco de teste é compartilhado na suíte: o drain também entrega avisos pendentes de outros testes; procura o e-mail desta fixture.
$mail = array_values(array_filter($mailer->sent, fn($m) => $m->subject === 'Lembrete de check-in'))[0] ?? null;
$all = json_encode([$e, $push, $mail ? [$mail->subject, $mail->textBody, $mail->htmlBody] : null], JSON_UNESCAPED_UNICODE);
check('C5', 'Lembrete com texto neutro', $e['title'] === 'Lembrete de check-in' && $e['body'] === 'Quando puder, registre seu check-in de hoje no app Clyo Nutri. Leva menos de um minuto.' && $e['link'] === '/');
check('C5', 'Push sai com texto curto e categoria checkin (o app abre em Hoje)', ($push['body'] ?? '') === 'Quando puder, registre seu check-in de hoje no app.' && ($push['data']['category'] ?? '') === 'checkin'
    && dst($db, $e['id'], 'push') === 'sent:', $push);
check('C5', 'E-mail (ligado pela Ana) sai com o mesmo texto neutro', $mail && $mail->subject === 'Lembrete de check-in' && dst($db, $e['id'], 'email') === 'sent:');
check('C5', 'Nada de nome, notas, adesão ou energia no aviso, no push ou no e-mail', !str_contains($all, 'TESTE') && !str_contains($all, '40%') && !preg_match('/ades[aã]o|energia|fome/iu', $all));

// ------------------------------------------------------------------ C6: fez o check-in antes da entrega / dia acabou
$svc->savePreferences($cA, 'patient', $bia, ['checkin_reminder' => ['time' => '19:00']]);
$svc->savePreferences($cA, 'patient', $caio, ['checkin_reminder' => ['enabled' => true, 'time' => '19:00'], 'categories' => ['checkin' => ['email_enabled' => true]]]);
$svc->savePreferences($cA, 'patient', $bia, ['categories' => ['checkin' => ['email_enabled' => true]]]);
$tokens->register($cA, 'patient', $bia, $tok('bia'), 'android');
// O cron cria o aviso, mas ainda não entregou: monta as entregas sem drenar.
$setClock('19:40');
$sched->schedule();
$eBia = ev($db, $cA, 'checkin.reminder', $bia)[0] ?? null;
$eCaio = ev($db, $cA, 'checkin.reminder', $caio)[0] ?? null;
check('C6', 'Bia e Caio entram na fila às 19:40', $eBia && $eCaio && dst($db, $eBia['id'], 'email') === 'queued:' && dst($db, $eCaio['id'], 'push') === 'queued:');
$checkin($cA, $bia, $today);
$calls = [];
$mailer->sent = [];
$setClock('19:41');
$svc->drain();
check('C6', 'Bia fez o check-in antes da entrega: e-mail e push pulados (checkin_done), nada sai',
    dst($db, $eBia['id'], 'email') === 'skipped:checkin_done' && dst($db, $eBia['id'], 'push') === 'skipped:checkin_done'
    && !in_array("pac.ck.bia.{$u}@example.test", array_map(fn($m) => $m->toAddress, $mailer->sent), true) && !str_contains(json_encode($calls), $tok('bia')));
// Caio: entrega atrasada para o dia seguinte (cron parado) não sai mais.
$db->prepare("UPDATE notification_deliveries SET status = 'queued', reason = NULL, next_attempt_at = NULL WHERE event_id = ?")->execute([$eCaio['id']]);
$setClock('00:30', 1);
$svc->drain();
check('C6', 'Lembrete de ontem não sai depois da meia-noite (expired)', dst($db, $eCaio['id'], 'email') === 'skipped:expired' && dst($db, $eCaio['id'], 'push') === 'skipped:expired');

// ------------------------------------------------------------------ C7: horário de silêncio
$setClock('22:35');
$r = $sched->schedule();
$eBeto = ev($db, $cB, 'checkin.reminder', $beto)[0] ?? null;
$svc->drain();
check('C7', 'Lembrete das 22:30 com silêncio 22:00–07:00 é pulado (quiet_hours), não fica para depois',
    $eBeto && dst($db, $eBeto['id'], 'email') === 'skipped:quiet_hours' && (int)one($db, "SELECT COUNT(*) FROM notification_deliveries WHERE event_id = ? AND status = 'queued'", [$eBeto['id']]) === 0);
check('C7', 'O aviso continua na caixa do app (só não toca)', count($svc->inbox($cB, 'patient', $beto)['items']) === 1);

// ------------------------------------------------------------------ C8: check-in recebido (profissional)
$db->exec("DELETE FROM checkins WHERE patient_id IN ({$ana},{$bia},{$caio},{$dani},{$eva},{$beto})");
$setClock('09:00', 2);
$day = date('Y-m-d', $clockTs);
$link($cA, $ana, $profA);
$link($cA, $dani, $profA);
$link($cA, $eva, $profA2);
$link($cB, $beto, $profB);
// Bia sem vínculo: vale quem fez o plano publicado mais recente (Nutri a2).
$db->prepare("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status, published_at) VALUES (?, ?, ?, 'Plano TESTE', 'published', ?)")->execute([$cA, $bia, $profA2, date('Y-m-d H:i:s', $clockTs)]);
$ckAna = $checkin($cA, $ana, $day);
$checkin($cA, $bia, $day);
$checkin($cA, $caio, $day); // sem vínculo nem plano
$checkin($cB, $beto, $day);
$mailer->sent = [];
$r = $sched->schedule();
check('C8', 'Cada check-in novo vira um aviso para o profissional responsável', $r['submitted']['created'] >= 3 && $r['submitted']['no_professional'] >= 1
    && (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE event_type = 'checkin.submitted' AND clinic_id IN (?, ?)", [$cA, $cB]) === 3
    && (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE event_type = 'checkin.submitted' AND link = ?", ['/pacientes/' . $caio]) === 0, $r['submitted']);
$eA = ev($db, $cA, 'checkin.submitted', $profA);
check('C8', 'Aviso para a Nutri a (vínculo ativo), com link para o Perfil 360 da paciente', count($eA) === 1 && $eA[0]['link'] === '/pacientes/' . $ana && $eA[0]['recipient_type'] === 'professional'
    && $eA[0]['title'] === 'Check-in recebido' && $eA[0]['body'] === 'Um novo check-in foi enviado. Entre no Clyo Nutri para ver.');
check('C8', 'Sem vínculo, vai para quem publicou o plano mais recente', count(ev($db, $cA, 'checkin.submitted', $profA2, '/pacientes/' . $bia)) === 1);
check('C8', 'Outra clínica: só o profissional de lá recebe', count(ev($db, $cB, 'checkin.submitted', $profB, '/pacientes/' . $beto)) === 1
    && ev($db, $cA, 'checkin.submitted', $profB) === [] && ev($db, $cB, 'checkin.submitted', $profA) === []);
$inbox = $svc->inbox($cA, 'professional', $profA);
check('C8', 'Aparece na caixa de avisos do profissional (/avisos)', ($inbox['items'][0]['event_type'] ?? '') === 'checkin.submitted' && $inbox['unread_count'] >= 1);
$svc->drain();
check('C8', 'Push não se aplica a profissional (no_app)', dst($db, $eA[0]['id'], 'push') === 'skipped:no_app', dst($db, $eA[0]['id'], 'push'));
$mailA = array_values(array_filter($mailer->sent, fn($m) => $m->toAddress === "nutri.ck.a.{$u}@example.test"));
$allP = json_encode([rows($db, "SELECT title, body, link FROM notification_events WHERE event_type = 'checkin.submitted' AND clinic_id IN (?, ?)", [$cA, $cB]), array_map(fn($m) => [$m->subject, $m->textBody, $m->htmlBody], $mailer->sent)], JSON_UNESCAPED_UNICODE);
check('C8', 'E-mail do profissional sai (opt-out) e é neutro', count($mailA) === 1 && $mailA[0]->subject === 'Check-in recebido' && dst($db, $eA[0]['id'], 'email') === 'sent:');
check('C8', 'Texto do profissional não supõe gênero (sem "uma paciente")', !preg_match('/\\b(uma|a|o) paciente\\b/iu', $allP), $allP);
check('C8', 'Sem nome, notas, adesão, energia ou fome em aviso ou e-mail', !str_contains($allP, 'TESTE') && !str_contains($allP, '40%') && !preg_match('/ades[aã]o|energia|fome/iu', $allP));

// ------------------------------------------------------------------ C9: idempotência, correção no dia, limite diário e opt-out
$setClock('09:05', 2);
$r = $sched->schedule();
check('C9', 'Cron de novo: nada duplicado', $r['submitted']['duplicates'] >= 3
    && (int)one($db, "SELECT COUNT(*) FROM notification_events WHERE event_type = 'checkin.submitted' AND clinic_id IN (?, ?)", [$cA, $cB]) === 3, $r['submitted']);
$db->prepare('UPDATE checkins SET notes = ?, updated_at = ? WHERE id = ?')->execute(['Correção TESTE', date('Y-m-d H:i:s', $clockTs), $ckAna]);
$sched->schedule();
check('C9', 'Paciente corrigiu o check-in no mesmo dia: não gera outro aviso', count(ev($db, $cA, 'checkin.submitted', $profA)) === 1);
$checkin($cA, $dani, $day);
$sched->schedule();
$eD = ev($db, $cA, 'checkin.submitted', $profA, '/pacientes/' . $dani)[0] ?? null;
$svc->drain();
check('C9', 'Segundo check-in do dia para a mesma Nutri: fica na caixa, sem outro e-mail (daily_email_limit)', $eD && dst($db, $eD['id'], 'email') === 'skipped:daily_email_limit'
    && count(array_filter($mailer->sent, fn($m) => $m->toAddress === "nutri.ck.a.{$u}@example.test")) === 1);
$setClock('09:00', 3);
$checkin($cA, $dani, date('Y-m-d', $clockTs));
$sched->schedule();
$svc->drain();
check('C9', 'No dia seguinte o e-mail volta a sair', count(array_filter($mailer->sent, fn($m) => $m->toAddress === "nutri.ck.a.{$u}@example.test")) === 2);
$svc->savePreferences($cA, 'professional', $profA2, ['categories' => ['checkin' => ['email_enabled' => false]]]);
$checkin($cA, $eva, date('Y-m-d', $clockTs));
$sched->schedule();
$eE = ev($db, $cA, 'checkin.submitted', $profA2, '/pacientes/' . $eva)[0] ?? null;
$svc->drain();
check('C9', 'Nutri que desligou o e-mail de check-in só vê na caixa (preference_off)', $eE && dst($db, $eE['id'], 'email') === 'skipped:preference_off'
    && count($svc->inbox($cA, 'professional', $profA2)['items']) === 2);
$old = $checkin($cA, $caio, date('Y-m-d', $clockTs - 5 * 86400));
$link($cA, $caio, $profA);
$r = $sched->schedule();
check('C9', 'Check-in antigo (fora de ontem/hoje) não gera aviso atrasado', (int)one($db, 'SELECT COUNT(*) FROM notification_events WHERE dedupe_key = ?', ['checkin.submitted:' . $old]) === 0);

// ------------------------------------------------------------------ C10: falhas sem conteúdo sensível
check('C10', 'safeReason guarda só códigos curtos', NotificationService::safeReason('mail_error') === 'mail_error' && NotificationService::safeReason(null) === null
    && NotificationService::safeReason('SMTP 550 ana@example.test: Nota clínica') === 'error' && NotificationService::safeReason(str_repeat('a', 61)) === 'error');
$boom = new class implements Mailer {
    public function send(MailMessage $message): void
    {
        throw new \RuntimeException('SMTP recusou: ' . $message->toAddress . ' | ' . $message->textBody);
    }
};
$svcFail = new NotificationService($db, $boom, $clock, new ExpoPushClient($transport, null));
$setClock('10:00', 4);
$checkin($cA, $ana, date('Y-m-d', $clockTs));
(new CheckinNotificationScheduler($db, $svcFail, $clock))->schedule();
$svcFail->drain();
$eF = rows($db, "SELECT id FROM notification_events WHERE clinic_id = ? AND event_type = 'checkin.submitted' AND recipient_id = ? ORDER BY id DESC LIMIT 1", [$cA, $profA])[0];
$reasons = array_merge(
    array_column(rows($db, "SELECT reason FROM notification_deliveries WHERE reason IS NOT NULL"), 'reason'),
    array_column(rows($db, "SELECT error FROM notification_push_tickets WHERE error IS NOT NULL"), 'error'),
    array_column(rows($db, "SELECT last_error FROM notification_push_tokens WHERE last_error IS NOT NULL"), 'last_error')
);
check('C10', 'Falha de e-mail grava só o código (mail_error) e tenta de novo depois', dst($db, $eF['id'], 'email') === 'queued:mail_error');
check('C10', 'Todo motivo gravado em entregas, recibos e aparelhos é um código', $reasons !== [] && !array_filter($reasons, fn($x) => !preg_match('/^[a-z][a-z0-9_]{0,59}$/', (string)$x)), array_values(array_unique($reasons)));
check('C10', 'Nenhum motivo leva e-mail, texto do aviso ou nota', !str_contains(json_encode($reasons), '@') && !str_contains(json_encode($reasons, JSON_UNESCAPED_UNICODE), 'Nota'));
$src = file_get_contents(__DIR__ . '/../src/Domain/Notification/ChatNotificationDispatcher.php') . file_get_contents(__DIR__ . '/../src/Domain/Notification/NotificationService.php');
check('C10', 'Logs de erro dos avisos registram só a classe da exceção', !preg_match('/error_log\([^;]*getMessage\(\)/', $src));

// ------------------------------------------------------------------ C11: LGPD
TenantContext::set($cA, ['id' => 1, 'role' => 'owner', 'name' => 'TESTE']);
$export = (new LgpdService($db))->exportPatientData($ana);
check('C11', 'Exportação LGPD leva a escolha do lembrete de check-in', ($export['notification_preferences']['checkin_reminder'] ?? null) === ['available' => true, 'enabled' => true, 'time' => '19:00']);
(new LgpdService($db))->anonymizePatient($ana);
check('C11', 'Anonimização apaga o lembrete e os avisos de check-in da paciente na caixa do profissional',
    (int)one($db, 'SELECT COUNT(*) FROM notification_checkin_settings WHERE patient_id = ?', [$ana]) === 0
    && ev($db, $cA, 'checkin.submitted', $profA, '/pacientes/' . $ana) === []
    && count(ev($db, $cA, 'checkin.submitted', $profA, '/pacientes/' . $dani)) === 2
    && (int)one($db, 'SELECT COUNT(*) FROM notification_deliveries d LEFT JOIN notification_events e ON e.id = d.event_id WHERE e.id IS NULL') === 0);
$setClock('19:10', 4);
$sched->schedule();
check('C11', 'Paciente anonimizada não gera mais aviso nem lembrete', ev($db, $cA, 'checkin.reminder', $ana) === [] && ev($db, $cA, 'checkin.submitted', $profA, '/pacientes/' . $ana) === []);

// ------------------------------------------------------------------ C12: cron e telas
$cron = file_get_contents(__DIR__ . '/../bin/cron_scheduler.php');
check('C12', 'Cron agenda os avisos de check-in antes de drenar a fila e mostra a linha Check-in', str_contains($cron, 'CheckinNotificationScheduler') && strpos($cron, 'CheckinNotificationScheduler') < strpos($cron, '->drain(')
    && str_contains($cron, 'Check-in  :'));
$view = file_get_contents(__DIR__ . '/../views/settings/profile.php');
check('C12', 'Configurações do profissional têm o opt-out do e-mail de check-in', str_contains($view, 'name="notify_checkin_email"') && str_contains($view, 'no máximo um por dia'));
$tsx = file_get_contents(__DIR__ . '/../mobile/src/components/PatientNotifications.tsx');
check('C12', 'App da paciente mostra o lembrete só quando disponível', str_contains($tsx, 'prefs.checkin_reminder?.available') && str_contains($tsx, 'Lembrete de check-in'));

// Não deixa entrega pendente no banco de teste (outras suítes contam a fila).
$db->prepare("UPDATE notification_deliveries SET status = 'skipped', reason = 'test_cleanup', next_attempt_at = NULL WHERE status = 'queued' AND event_id IN (SELECT id FROM notification_events WHERE clinic_id IN (?, ?))")->execute([$cA, $cB]);
putenv('PUSH_ENABLED');
echo "\nResultado NI2-95 check-in: {$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);
