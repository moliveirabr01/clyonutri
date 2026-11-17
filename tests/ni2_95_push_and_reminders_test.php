<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * NI2-95 (parte 2) — push pela Expo Push Service (com transporte falso, nada sai para a internet),
 * aparelhos da paciente, recibos, retentativas, silêncio, lembrete configurável, limpeza e LGPD.
 * Dados sintéticos (TESTE). Roda em SQLite e em MySQL/MariaDB com as migrations aplicadas.
 */

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\ArrayMailer;
use ClyoNutri\Domain\Notification\AppointmentReminderScheduler;
use ClyoNutri\Domain\Notification\ExpoPushClient;
use ClyoNutri\Domain\Notification\NotificationService;
use ClyoNutri\Domain\Notification\PushTokenService;
use ClyoNutri\Domain\Notification\ReminderSettingsService;
use ClyoNutri\Domain\Privacy\LgpdService;

putenv('PUSH_ENABLED');
putenv('EXPO_ACCESS_TOKEN');
putenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS');
unset($_ENV['PUSH_ENABLED'], $_ENV['EXPO_ACCESS_TOKEN'], $_ENV['NOTIFICATION_APPOINTMENT_REMINDER_HOURS']);

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
function pst(PDO $db, $eventId): string
{
    $r = rows($db, "SELECT status, reason FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$eventId])[0] ?? null;
    return $r ? $r['status'] . ':' . $r['reason'] : '';
}
function throwsMsg(callable $fn, string $class): ?string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class ? $e->getMessage() : get_class($e);
    }
    return null;
}

// ------------------------------------------------------------------ fixture TESTE
$u = bin2hex(random_bytes(3));
$mkClinic = function (string $name) use ($db, $u): int {
    $db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, ?, 'active')")->execute(['c-' . $u . $name, 'Clínica TESTE ' . $name]);
    return (int)$db->lastInsertId();
};
$mkProf = function (string $name) use ($db, $u): int {
    $db->prepare('INSERT INTO users (uuid, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
        ->execute(['u-' . $u . $name, 'Nutri TESTE ' . $name, "nutri.{$name}.{$u}@example.test", 'x', 'nutritionist']);
    $uid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, ?)')->execute([$uid, '9' . random_int(10000, 99999), 'CRN-3']);
    return (int)$db->lastInsertId();
};
$mkPatient = function (int $clinicId, string $name) use ($db, $u): int {
    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([$clinicId, 'p-' . $u . $name, 'Paciente TESTE ' . $name, "pac.{$name}.{$u}@example.test"]);
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
$beto = $mkPatient($cB, 'beto');

$clockTs = strtotime(date('Y-m-d') . ' 10:00:00');
$clock = function () use (&$clockTs) { return $clockTs; };
$at = function (int $offset) use (&$clockTs): string { return date('Y-m-d H:i:s', $clockTs + $offset); };

// Transporte falso do Expo: grava cada chamada e responde conforme $reply.
$calls = [];
$reply = null;
$transport = function (string $url, string $body, array $headers) use (&$calls, &$reply): array {
    $payload = json_decode($body, true);
    $calls[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
    return $reply($url, $payload);
};
$okReply = function (string $url, $payload): array {
    if (str_ends_with($url, '/send')) {
        return ['status' => 200, 'body' => json_encode(['data' => array_map(fn($m) => ['status' => 'ok', 'id' => 'tk-' . substr(md5($m['to'] . microtime()), 0, 12)], $payload)])];
    }
    return ['status' => 200, 'body' => json_encode(['data' => array_fill_keys($payload['ids'], ['status' => 'ok'])])];
};
$reply = $okReply;
$client = new ExpoPushClient($transport, null);
$mailer = new ArrayMailer();
$svc = new NotificationService($db, $mailer, $clock, $client);
$tokens = new PushTokenService($db, $clock);

// ------------------------------------------------------------------ P1: cliente Expo
check('P1', 'Migration 52: tabelas de aparelhos, recibos e lembrete existem', $tokens->available() && (new ReminderSettingsService($db))->available());
check('P1', 'Token do Expo validado pelo formato', ExpoPushClient::validToken($tok('ok')) && !ExpoPushClient::validToken('ExponentPushToken[curto]') && !ExpoPushClient::validToken('abc') && !ExpoPushClient::validToken($tok('ok') . ' '));
check('P1', 'Erro do Expo vira código curto (DeviceNotRegistered → device_not_registered)', ExpoPushClient::errorCode('DeviceNotRegistered') === 'device_not_registered' && ExpoPushClient::errorCode('MessageRateExceeded') === 'message_rate_exceeded');
check('P1', 'Mais de 100 mensagens por requisição é recusado', throwsMsg(fn() => $client->send(array_fill(0, 101, ['to' => 'x'])), \InvalidArgumentException::class) !== null);
$reply = fn() => ['status' => 429, 'body' => '{}'];
$r429 = $client->send([['to' => $tok('a')]]);
$reply = fn() => ['status' => 400, 'body' => '{"errors":[{"code":"VALIDATION_ERROR"}]}'];
$r400 = $client->send([['to' => $tok('a')]]);
$reply = fn() => ['status' => 200, 'body' => '{"data":[]}'];
$rBad = $client->send([['to' => $tok('a')]]);
$reply = fn() => throw new \RuntimeException('rede caiu');
$rNet = $client->send([['to' => $tok('a')]]);
check('P1', '429/5xx/rede/resposta incompleta podem tentar de novo; 400 não', $r429['retryable'] && $r429['error'] === 'push_rate_limited' && !$r400['retryable'] && $r400['error'] === 'push_rejected'
    && $rBad['retryable'] && $rBad['error'] === 'push_bad_response' && $rNet['retryable'] && $rNet['error'] === 'push_network_error', [$r429, $r400, $rBad, $rNet]);
$reply = $okReply;
$calls = [];
(new ExpoPushClient($transport, 'segredo-TESTE'))->send([['to' => $tok('a')]]);
check('P1', 'EXPO_ACCESS_TOKEN vai só no cabeçalho Authorization', in_array('Authorization: Bearer segredo-TESTE', $calls[0]['headers'], true) && !str_contains(json_encode($calls[0]['payload']), 'segredo'));
check('P1', 'Envio vai para o endereço oficial do Expo por HTTPS', $calls[0]['url'] === 'https://exp.host/--/api/v2/push/send');

// ------------------------------------------------------------------ P2: aparelhos
check('P2', 'Token fora do formato é recusado com texto claro', throwsMsg(fn() => $tokens->register($cA, 'patient', $ana, 'qualquer-coisa', 'ios'), \InvalidArgumentException::class) === 'Não foi possível ativar o aviso neste aparelho. Feche e abra o app de novo.');
$r = $tokens->register($cA, 'patient', $ana, $tok('ana1'), 'ios');
check('P2', 'Cadastro devolve registered e se o push está ligado (sem devolver o token)', $r === ['registered' => true, 'push_enabled' => false], $r);
$tokens->register($cA, 'patient', $ana, $tok('ana1'), 'ios');
check('P2', 'Mesmo token duas vezes não duplica', (int)one($db, 'SELECT COUNT(*) FROM notification_push_tokens WHERE token = ?', [$tok('ana1')]) === 1);
$tokens->register($cA, 'patient', $ana, $tok('ana2'), 'android');
$tokens->register($cA, 'patient', $bia, $tok('troca'), 'android');
$tokens->register($cA, 'patient', $caio, $tok('troca'), 'android');
check('P2', 'Aparelho que trocou de conta passa para a paciente atual', (int)one($db, 'SELECT recipient_id FROM notification_push_tokens WHERE token = ?', [$tok('troca')]) === $caio && count($tokens->activeTokens($cA, 'patient', $bia)) === 0);
check('P2', 'Plataforma desconhecida vira unknown', $tokens->register($cB, 'patient', $beto, $tok('beto'), 'blackberry')['registered'] && one($db, 'SELECT platform FROM notification_push_tokens WHERE token = ?', [$tok('beto')]) === 'unknown');
check('P2', 'Paciente de outra clínica não desliga aparelho alheio', $tokens->remove($cB, 'patient', $beto, $tok('ana2')) === false && count($tokens->activeTokens($cA, 'patient', $ana)) === 2);
for ($i = 0; $i < 11; $i++) {
    $clockTs += 1;
    $tokens->register($cA, 'patient', $bia, $tok('bia' . $i), 'android');
}
check('P2', 'No máximo 10 aparelhos ativos por paciente (o mais antigo é desligado)', count($tokens->activeTokens($cA, 'patient', $bia)) === 10
    && (int)one($db, 'SELECT active FROM notification_push_tokens WHERE token = ?', [$tok('bia0')]) === 0);
check('P2', 'Logout desliga o aparelho', $tokens->remove($cA, 'patient', $caio, $tok('troca')) === true && (int)one($db, 'SELECT active FROM notification_push_tokens WHERE token = ?', [$tok('troca')]) === 0
    && one($db, 'SELECT last_error FROM notification_push_tokens WHERE token = ?', [$tok('troca')]) === 'logged_out');

// ------------------------------------------------------------------ P3: push desligado (padrão)
$calls = [];
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p3:' . $u, '/');
$svc->drain();
check('P3', 'Com PUSH_ENABLED desligado o push fica blocked (push_provider_not_configured) e nada sai', pst($db, $r['event_id']) === 'blocked:push_provider_not_configured' && $calls === []);
check('P3', 'App não mostra chaves de push enquanto o servidor não liga', $svc->preferences($cA, 'patient', $ana)['push_available'] === false);
check('P3', 'Recibos não são consultados com push desligado', $svc->checkPushReceipts()['status'] === 'PUSH_DISABLED' && $calls === []);

putenv('PUSH_ENABLED=true');
check('P3', 'Com PUSH_ENABLED=true o app passa a ver as chaves de push', $svc->preferences($cA, 'patient', $ana)['push_available'] === true && $svc->preferences($cA, 'professional', $profA)['push_available'] === false);

// ------------------------------------------------------------------ P4: envio
$calls = [];
$r = $svc->publish($cA, 'chat.message_created', 'patient', $ana, 'p4chat:' . $u, '/chat');
check('P4', 'Push nunca sai dentro da requisição (fica na fila para o cron)', $r['deliveries']['push']['status'] === 'queued' && $calls === [], $r);
$dr = $svc->drain();
$sent = $calls[0]['payload'] ?? [];
check('P4', 'Cron manda uma mensagem por aparelho ativo, numa requisição', count($calls) === 1 && count($sent) === 2 && $dr['sent'] >= 1, $dr);
check('P4', 'Texto do push é neutro (sem conteúdo da mensagem nem dado de saúde)', ($sent[0]['title'] ?? '') === 'Clyo Nutri' && ($sent[0]['body'] ?? '') === 'Você tem uma mensagem nova do consultório. Abra o app para ler.'
    && array_keys($sent[0]['data'] ?? []) === ['event_id', 'category'] && $sent[0]['data']['category'] === 'chat');
check('P4', 'Entrega fica sent e cada aparelho ganha um recibo pendente', one($db, "SELECT status FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$r['event_id']]) === 'sent'
    && (int)one($db, "SELECT COUNT(*) FROM notification_push_tickets t JOIN notification_deliveries d ON d.id = t.delivery_id WHERE d.event_id = ? AND t.status = 'pending'", [$r['event_id']]) === 2);
$r = $svc->publish($cA, 'appointment.reminder', 'patient', $ana, 'p4rem:' . $u, '/', ['date' => '23/10', 'time' => '14:00']);
$calls = [];
$svc->drain();
check('P4', 'Lembrete no push não leva data, hora nem profissional', ($calls[0]['payload'][0]['body'] ?? '') === 'Sua consulta está chegando. Abra o app para ver o dia e o horário.'
    && !str_contains(json_encode($calls[0]['payload'] ?? []), '14:00'));
$calls = [];
$r = $svc->publish($cA, 'chat.message_created', 'professional', $profA, 'p4prof:' . $u, '/chat/1');
check('P4', 'Profissional não tem app: push skipped (no_app)', $r['deliveries']['push'] === ['status' => 'skipped', 'reason' => 'no_app'] && $calls === [], $r);

// ------------------------------------------------------------------ P5: lotes de 100
$many = [];
for ($i = 0; $i < 12; $i++) {
    $pid = $mkPatient($cA, 'lote' . $i);
    $many[] = $pid;
    for ($k = 0; $k < 10; $k++) {
        $tokens->register($cA, 'patient', $pid, $tok("l{$i}k{$k}"), 'ios');
    }
    $svc->publish($cA, 'meal_plan.published', 'patient', $pid, 'p5:' . $i . $u, '/');
}
$calls = [];
$dr = $svc->drain(500);
$sizes = array_map(fn($c) => count($c['payload']), array_values(array_filter($calls, fn($c) => str_ends_with($c['url'], '/send'))));
check('P5', '120 aparelhos saem em 2 requisições (100 + 20)', $sizes === [100, 20], $sizes);
check('P5', 'As 12 entregas ficam sent', (int)one($db, "SELECT COUNT(*) FROM notification_deliveries d JOIN notification_events e ON e.id = d.event_id WHERE d.channel = 'push' AND d.status = 'sent' AND e.dedupe_key LIKE ?", ['p5:%']) === 12);

// ------------------------------------------------------------------ P6: DeviceNotRegistered no envio
$reply = function (string $url, $payload): array {
    return ['status' => 200, 'body' => json_encode(['data' => array_map(fn($m) => ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']], $payload)])];
};
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p6:' . $u, '/');
$svc->drain();
check('P6', 'Aparelho que não existe mais é desligado na hora', count($tokens->activeTokens($cA, 'patient', $ana)) === 0
    && one($db, 'SELECT last_error FROM notification_push_tokens WHERE token = ?', [$tok('ana1')]) === 'device_not_registered');
check('P6', 'Sem nenhum aparelho aceito, a entrega fica failed (device_not_registered)', pst($db, $r['event_id']) === 'failed:device_not_registered');
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p6b:' . $u, '/');
$calls = [];
$svc->drain();
check('P6', 'Paciente sem aparelho ativo: skipped (no_device), sem chamar o Expo', pst($db, $r['event_id']) === 'skipped:no_device' && $calls === []);

// ------------------------------------------------------------------ P7: retentativa
$tokens->register($cA, 'patient', $ana, $tok('ana3'), 'ios');
$reply = fn() => ['status' => 503, 'body' => ''];
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p7:' . $u, '/');
$calls = [];
$svc->drain();
$row = rows($db, "SELECT status, reason, attempts, next_attempt_at FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$r['event_id']])[0];
check('P7', 'Expo fora do ar: volta para a fila em 1 min (tentativa 1)', $row['status'] === 'queued' && $row['reason'] === 'push_server_error' && (int)$row['attempts'] === 1 && $row['next_attempt_at'] === $at(60), $row);
$calls = [];
$svc->drain();
check('P7', 'Antes da hora não tenta de novo', $calls === []);
$waits = [];
foreach ([60, 300, 900] as $w) {
    $clockTs += $w;
    $calls = [];
    $svc->drain();
    $row = rows($db, "SELECT status, reason, attempts FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$r['event_id']])[0];
    $waits[] = count($calls) . ':' . $row['status'] . ':' . $row['attempts'];
}
check('P7', 'Tenta mais 3 vezes (1, 5 e 15 min) e desiste como failed', $waits === ['1:queued:2', '1:queued:3', '1:failed:4'], $waits);
$reply = fn() => ['status' => 503, 'body' => ''];
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p7b:' . $u, '/');
$svc->drain();
$reply = $okReply;
$clockTs += 60;
$svc->drain();
check('P7', 'Se o Expo volta, a retentativa entrega (sent)', one($db, "SELECT status FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$r['event_id']]) === 'sent');

// ------------------------------------------------------------------ P8: preferências, silêncio, já lido
$svc->savePreferences($cA, 'patient', $ana, ['categories' => ['plan' => ['push_enabled' => false]]]);
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p8pref:' . $u, '/');
$calls = [];
$svc->drain();
check('P8', 'Push desligado só para plano: skipped (preference_off)', pst($db, $r['event_id']) === 'skipped:preference_off' && $calls === []);
$svc->savePreferences($cA, 'patient', $ana, ['categories' => ['plan' => ['push_enabled' => true]]]);
$saveTs = $clockTs;
$clockTs = strtotime(date('Y-m-d', $saveTs) . ' 23:00:00');
$svc->savePreferences($cA, 'patient', $ana, ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']]);
$rc = $svc->publish($cA, 'chat.message_created', 'patient', $ana, 'p8chat:' . $u, '/chat');
$rp = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, 'p8plan:' . $u, '/');
$calls = [];
$svc->drain();
$plan = rows($db, "SELECT status, next_attempt_at FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$rp['event_id']])[0];
check('P8', 'Mensagem no silêncio: sem push (fica só no app)', pst($db, $rc['event_id']) === 'skipped:quiet_hours' && $calls === []);
check('P8', 'Plano no silêncio: push espera até 07:00', $plan['status'] === 'queued' && $plan['next_attempt_at'] === date('Y-m-d', $clockTs + 86400) . ' 07:00:00', $plan);
$svc->markRead($cA, 'patient', $ana, (int)$rp['event_id']);
$clockTs = strtotime(date('Y-m-d', $clockTs + 86400) . ' 07:01:00');
$calls = [];
$svc->drain();
check('P8', 'Se a paciente já leu no app, o push atrasado não sai (already_read)', pst($db, $rp['event_id']) === 'skipped:already_read' && $calls === []);
$svc->savePreferences($cA, 'patient', $ana, ['quiet_hours' => ['enabled' => false]]);

// ------------------------------------------------------------------ P9: recibos
$db->exec("UPDATE notification_push_tickets SET status = 'ok' WHERE status = 'pending'");
$reply = $okReply;
$r = $svc->publish($cA, 'meal_plan.published', 'patient', $many[0], 'p9:' . $u, '/');
$svc->drain();
$tk = rows($db, "SELECT t.id, t.ticket_id, t.token_id FROM notification_push_tickets t JOIN notification_deliveries d ON d.id = t.delivery_id WHERE d.event_id = ? ORDER BY t.id", [$r['event_id']]);
$calls = [];
$svc->checkPushReceipts();
check('P9', 'Recibo só é conferido depois de 15 minutos', $calls === [] && count($tk) === 10);
$clockTs += 16 * 60;
$deadTicket = $tk[0]['ticket_id'];
$reply = function (string $url, $payload) use ($deadTicket): array {
    $data = [];
    foreach ($payload['ids'] as $i => $id) {
        if ($i === 9) {
            continue; // ainda não ficou pronto
        }
        $data[$id] = $id === $deadTicket ? ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']] : ['status' => 'ok'];
    }
    return ['status' => 200, 'body' => json_encode(['data' => $data])];
};
$calls = [];
$rep = $svc->checkPushReceipts();
check('P9', 'Recibos conferidos numa consulta só ao Expo', count($calls) === 1 && str_ends_with($calls[0]['url'], '/getReceipts') && count($calls[0]['payload']['ids']) === 10, $rep);
check('P9', 'DeviceNotRegistered no recibo desliga o aparelho', $rep['deactivated'] === 1 && (int)one($db, 'SELECT active FROM notification_push_tokens WHERE id = ?', [$tk[0]['token_id']]) === 0, $rep);
check('P9', 'Recibo que ainda não ficou pronto continua pendente', one($db, 'SELECT status FROM notification_push_tickets WHERE id = ?', [$tk[9]['id']]) === 'pending' && $rep['checked'] === 9 && $rep['ok'] === 8 && $rep['error'] === 1, $rep);
$clockTs += 25 * 3600;
$reply = $okReply;
$rep = $svc->checkPushReceipts();
check('P9', 'Recibo sem resposta em 24 h vira unknown', one($db, 'SELECT status FROM notification_push_tickets WHERE id = ?', [$tk[9]['id']]) === 'unknown' && $rep['unknown'] >= 1, $rep);

// ------------------------------------------------------------------ P10: lembrete configurável
$rs = new ReminderSettingsService($db, $clock);
check('P10', 'Sem ajuste e sem .env, o lembrete sai 24 h antes', $rs->effective($cA, $profA) === ['hours' => 24, 'source' => 'default']);
putenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS=48');
$e48 = ReminderSettingsService::envDefault();
putenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS=500');
$e500 = ReminderSettingsService::envDefault();
putenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS=abc');
$eAbc = ReminderSettingsService::envDefault();
putenv('NOTIFICATION_APPOINTMENT_REMINDER_HOURS');
check('P10', '.env vale como padrão, limitado a 1–72 (inválido = 24)', [$e48, $e500, $eAbc] === [48, 72, 24], [$e48, $e500, $eAbc]);
$bad = array_map(fn($v) => throwsMsg(fn() => $rs->save($cA, 0, $v, 1), \InvalidArgumentException::class), [0, 73, '1.5', 'doze', '-3', [24]]);
check('P10', 'Fora de 1–72 ou não inteiro é recusado com texto claro', $bad === ['Escolha entre 1 e 72 horas antes da consulta.', 'Escolha entre 1 e 72 horas antes da consulta.', 'Informe a antecedência em horas inteiras, de 1 a 72.', 'Informe a antecedência em horas inteiras, de 1 a 72.', 'Informe a antecedência em horas inteiras, de 1 a 72.', 'Informe a antecedência em horas inteiras, de 1 a 72.'], $bad);
$s1 = $rs->save($cA, 0, '6', 1);
$s2 = $rs->save($cA, $profA, 2, 1);
$s3 = $rs->save($cA, 0, ' 8 ', 1);
check('P10', 'Salvar devolve antes/depois para a auditoria', $s1 === ['before' => null, 'after' => 6] && $s2 === ['before' => null, 'after' => 2] && $s3 === ['before' => 6, 'after' => 8], [$s1, $s2, $s3]);
check('P10', 'Profissional com ajuste próprio vence o da clínica; os outros seguem a clínica', $rs->effective($cA, $profA) === ['hours' => 2, 'source' => 'professional'] && $rs->effective($cA, $profA2) === ['hours' => 8, 'source' => 'clinic']
    && $rs->effective($cB, $profB) === ['hours' => 24, 'source' => 'default']);
check('P10', 'Uma linha por clínica/profissional (salvar de novo atualiza)', (int)one($db, 'SELECT COUNT(*) FROM notification_reminder_settings WHERE clinic_id = ?', [$cA]) === 2);
$clockTs = strtotime(date('Y-m-d', $clockTs) . ' 08:00:00');
$mkAppt = function (int $clinicId, int $patientId, int $profId, int $ts) use ($db): int {
    $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status) VALUES (?, ?, ?, ?, 'scheduled')")
        ->execute([$clinicId, $patientId, $profId, date('Y-m-d H:i:s', $ts)]);
    return (int)$db->lastInsertId();
};
$ap5hA = $mkAppt($cA, $bia, $profA, $clockTs + 5 * 3600);    // profA: 2 h → ainda não
$ap1hA = $mkAppt($cA, $bia, $profA, $clockTs + 90 * 60);     // profA: 2 h → sim
$ap5hA2 = $mkAppt($cA, $caio, $profA2, $clockTs + 5 * 3600); // clínica: 8 h → sim
$ap10hA2 = $mkAppt($cA, $caio, $profA2, $clockTs + 10 * 3600); // clínica: 8 h → ainda não
$ap20hB = $mkAppt($cB, $beto, $profB, $clockTs + 20 * 3600);  // padrão 24 h → sim
$rep = (new AppointmentReminderScheduler($db, $svc, $clock))->schedule();
$keys = array_column(rows($db, "SELECT dedupe_key FROM notification_events WHERE event_type = 'appointment.reminder' AND created_at = ?", [$at(0)]), 'dedupe_key');
$has = fn(int $id) => (bool)array_filter($keys, fn($k) => str_starts_with($k, "appointment.reminder:{$id}:"));
check('P10', 'Cron respeita a antecedência de cada profissional e da clínica', $has($ap1hA) && !$has($ap5hA) && $has($ap5hA2) && !$has($ap10hA2) && $has($ap20hB), [$rep, $keys]);
$rs->save($cA, $profA, null, 1);
check('P10', 'Apagar o ajuste do profissional volta a seguir a clínica', $rs->effective($cA, $profA) === ['hours' => 8, 'source' => 'clinic']);
$rep2 = (new AppointmentReminderScheduler($db, $svc, $clock))->schedule();
check('P10', 'Mudar a antecedência não duplica lembrete já criado', $has($ap5hA) === false && $rep2['duplicates'] >= 3 && $rep2['created'] === 1, $rep2);
$rs->save($cA, 0, null, 1);

// ------------------------------------------------------------------ P11: limpeza
$old = date('Y-m-d H:i:s', $clockTs - 200 * 86400);
$recent = date('Y-m-d H:i:s', $clockTs - 10 * 86400);
$mkEv = function (string $key, ?string $readAt) use ($svc, $db, $cA, $ana): int {
    $r = $svc->publish($cA, 'meal_plan.published', 'patient', $ana, $key, '/');
    $db->prepare('UPDATE notification_events SET read_at = ?, created_at = ? WHERE id = ?')->execute([$readAt, $readAt ?? date('Y-m-d H:i:s', 0), $r['event_id']]);
    return (int)$r['event_id'];
};
$evOld = $mkEv('p11old:' . $u, $old);
$evRecent = $mkEv('p11recent:' . $u, $recent);
$evUnread = $mkEv('p11unread:' . $u, null);
$tokenOld = (int)one($db, 'SELECT id FROM notification_push_tokens WHERE token = ?', [$tok('bia0')]);
$db->prepare('UPDATE notification_push_tokens SET updated_at = ? WHERE id = ?')->execute([$old, $tokenOld]);
$delOld = (int)one($db, "SELECT id FROM notification_deliveries WHERE event_id = ? AND channel = 'push'", [$evOld]);
$db->prepare("INSERT INTO notification_push_tickets (clinic_id, delivery_id, token_id, ticket_id, status, created_at) VALUES (?, ?, ?, 'tk-velho', 'ok', ?)")->execute([$cA, $delOld, (int)$tk[1]['token_id'], $recent]);
$pg = $svc->purge();
check('P11', 'Aviso lido há mais de 180 dias sai com entregas e recibos', (int)one($db, 'SELECT COUNT(*) FROM notification_events WHERE id = ?', [$evOld]) === 0
    && (int)one($db, 'SELECT COUNT(*) FROM notification_deliveries WHERE event_id = ?', [$evOld]) === 0 && (int)one($db, "SELECT COUNT(*) FROM notification_push_tickets WHERE ticket_id = 'tk-velho'") === 0, $pg);
check('P11', 'Aviso lido recente e aviso não lido ficam', (int)one($db, 'SELECT COUNT(*) FROM notification_events WHERE id IN (?, ?)', [$evRecent, $evUnread]) === 2);
check('P11', 'Aparelho desligado há mais de 180 dias é apagado; ativo fica', (int)one($db, 'SELECT COUNT(*) FROM notification_push_tokens WHERE id = ?', [$tokenOld]) === 0 && count($tokens->activeTokens($cA, 'patient', $bia)) === 10, $pg);
check('P11', 'Recibos com mais de 30 dias são apagados', (int)one($db, 'SELECT COUNT(*) FROM notification_push_tickets WHERE created_at < ?', [date('Y-m-d H:i:s', $clockTs - 30 * 86400)]) === 0);

// ------------------------------------------------------------------ P12: LGPD
\ClyoNutri\Core\Auth\TenantContext::set($cA, ['id' => 1, 'role' => 'owner', 'name' => 'TESTE']);
$export = (new LgpdService($db))->exportPatientData($bia);
$devs = $export['related_data']['push_devices'] ?? $export['push_devices'] ?? null;
check('P12', 'Exportação lista os aparelhos sem o token', is_array($devs) && count($devs) >= 10 && !str_contains(json_encode($devs), 'ExponentPushToken') && array_keys($devs[0]) === ['platform', 'active', 'created_at', 'updated_at', 'last_sent_at'], is_array($devs) ? array_slice($devs, 0, 1) : array_keys($export));
(new LgpdService($db))->anonymizePatient($many[0]);
check('P12', 'Anonimização apaga aparelhos e recibos da paciente', (int)one($db, "SELECT COUNT(*) FROM notification_push_tokens WHERE recipient_type = 'patient' AND recipient_id = ?", [$many[0]]) === 0
    && (int)one($db, 'SELECT COUNT(*) FROM notification_push_tickets t LEFT JOIN notification_push_tokens k ON k.id = t.token_id WHERE k.id IS NULL') === 0
    && count($tokens->activeTokens($cA, 'patient', $many[1])) === 10);

// ------------------------------------------------------------------ P13: rotas, contrato e cron
$index = file_get_contents(__DIR__ . '/../public/index.php');
$api = json_decode(file_get_contents(__DIR__ . '/../docs/api/openapi_v1.json'), true);
check('P13', 'Rotas de aparelho e da caixa de avisos web existem', str_contains($index, "'/api/v1/patient/push-tokens'") && str_contains($index, "'/api/v1/patient/push-tokens/remove'")
    && str_contains($index, "'/avisos'") && str_contains($index, "'/avisos/{id}/lida'") && str_contains($index, "'/avisos/lidas'"));
check('P13', 'OpenAPI documenta as rotas de aparelho', isset($api['paths']['/patient/push-tokens']['post'], $api['paths']['/patient/push-tokens/remove']['post'], $api['components']['schemas']['PushTokenRegister']));
$cron = file_get_contents(__DIR__ . '/../bin/cron_scheduler.php');
check('P13', 'Cron confere recibos, limpa uma vez por dia e não roda duas vezes junto', str_contains($cron, 'checkPushReceipts()') && str_contains($cron, '->purge()') && str_contains($cron, 'LOCK_NB'));
$envEx = file_get_contents(__DIR__ . '/../docs/env/notificacoes-ni2-95.env.example');
check('P13', 'Exemplo de .env documenta PUSH_ENABLED=false sem segredo', (bool)preg_match('/^PUSH_ENABLED=false$/m', $envEx) && (bool)preg_match('/^EXPO_ACCESS_TOKEN=$/m', $envEx) && (bool)preg_match('/^NOTIFICATION_APPOINTMENT_REMINDER_HOURS=24$/m', $envEx));

putenv('PUSH_ENABLED');
echo "\nResultado NI2-95 push/lembrete: {$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);
