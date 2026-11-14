<?php
/**
 * v54.27: janela de entrada da teleconsulta com a configuração atual + telas de vídeo sem estilo inline.
 * A abertura efetiva é min(gravada no convite, agendado − TELECONSULT_JOIN_EARLY_MINUTES atual); o fechamento
 * continua o gravado. Mensagem com o horário exato; mesma regra no portal do paciente; token e clínica isolados.
 * Dados TESTE, SQLite descartável. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5427-teleconsulta.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Appointment\PatientAppointmentService;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

$root = dirname(__DIR__);
putenv('APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk=');
putenv('APP_URL=http://127.0.0.1');
$tzL = new DateTimeZone('America/Sao_Paulo');
$utc = new DateTimeZone('UTC');

$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$a = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '27001');
$b = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner', '27002');
$p = Fx::patient($pdo, $cA, 'Paciente TESTE Vídeo');
$pB = Fx::patient($pdo, $cB, 'Paciente TESTE Clínica B');

// consulta online daqui a 30 min (relógio fixo)
$now = new DateTimeImmutable('2026-11-10 14:00:00', $tzL);
$sched = $now->modify('+30 minutes');
$mk = function (int $clinic, int $patient, int $prof) use ($pdo, $sched): int {
    $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (?, ?, ?, ?, 60, 'confirmed', 'teleconsultation')")
        ->execute([$clinic, $patient, $prof, $sched->format('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
};
$appA = $mk($cA, $p, $a['professional_id']);
$appB = $mk($cB, $pB, $b['professional_id']);
$svc = fn(?DateTimeImmutable $at = null) => new TeleconsultationService($pdo, null, null, ($at ?? $now)->setTimezone($utc));

echo "== convite gerado com 15 min de antecedência\n";
putenv('TELECONSULT_JOIN_EARLY_MINUTES=15');
Fx::act($a);
$tA = $svc()->scheduleSession($appA, $a['professional_id'], $cA);
Fx::act($b);
$tB = $svc()->scheduleSession($appB, $b['professional_id'], $cB);
Fx::act($a);
$tokA = (string)$tA['raw_patient_token'];
$tokB = (string)$tB['raw_patient_token'];
T::ok($tokA !== '' && $tokB !== '' && $tokA !== $tokB, 'duas sessões com tokens distintos');
$row = $pdo->query('SELECT join_opens_at_utc, join_closes_at_utc FROM teleconsultation_sessions WHERE id = ' . (int)$tA['session_id'])->fetch();
$storedOpen = new DateTimeImmutable($row['join_opens_at_utc'], $utc);
$storedClose = (string)$row['join_closes_at_utc'];
T::ok($storedOpen == $sched->modify('-15 minutes')->setTimezone($utc), 'abertura gravada = agendado − 15 min');

$term = $svc()->getActiveConsentTerm();
T::ok($term !== null, 'termo da teleconsulta ativo');
$svc()->submitDeviceCheck($tokA, ['camera_ok' => true, 'microphone_ok' => true, 'mode' => 'full', 'browser' => 'TESTE']);
$svc()->acceptConsent($tokA, (int)$term['id'], '127.0.0.1', 'TESTE');

echo "== antes da abertura: erro com o horário exato\n";
$clock = $svc()->checkWaitingRoomStatus($tokA);
T::ok($clock['is_open'] === false && $clock['opens_label'] === $sched->modify('-15 minutes')->format('H:i'), 'status: fechada, abre às ' . $sched->modify('-15 minutes')->format('H:i'));
T::ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $clock['join_opens_at_utc']) === 1 && $clock['server_now_utc'] === $now->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'status traz abertura e hora do servidor em UTC (ISO, com Z)');
T::throws(fn() => $svc()->enterWaitingRoom($tokA), 'entrar 30 min antes com 15 min de antecedência: recusado', 'abre às ' . $sched->modify('-15 minutes')->format('H:i'));
T::throws(fn() => $svc()->enterWaitingRoom($tokA), 'mensagem diz o fuso e o que fazer', 'Volte a tocar em "Entrar"');

echo "== configuração atual abre mais cedo (45 min): vale sem novo convite\n";
putenv('TELECONSULT_JOIN_EARLY_MINUTES=45');
$clock = $svc()->checkWaitingRoomStatus($tokA);
T::ok($clock['is_open'] === true && $clock['opens_label'] === $sched->modify('-45 minutes')->format('H:i'), 'status: aberta (abertura efetiva = agendado − 45 min)');
$r = $svc()->enterWaitingRoom($tokA);
T::ok(($r['status'] ?? '') === 'waiting_room', 'paciente entra na sala de espera');
$row2 = $pdo->query('SELECT join_opens_at_utc, join_closes_at_utc FROM teleconsultation_sessions WHERE id = ' . (int)$tA['session_id'])->fetch();
T::ok((string)$row2['join_closes_at_utc'] === $storedClose, 'fechamento gravado não muda');
T::ok((string)$row2['join_opens_at_utc'] === (string)$row['join_opens_at_utc'], 'abertura gravada não é reescrita (cálculo só na hora de entrar)');

echo "== configuração atual abre mais tarde (5 min): continua valendo a gravada\n";
putenv('TELECONSULT_JOIN_EARLY_MINUTES=5');
$at = $sched->modify('-10 minutes');
$s = $svc($at)->getSessionByPatientToken($tokB);
T::ok($svc($at)->effectiveJoinOpensAt($s) == $sched->modify('-15 minutes')->setTimezone($utc), 'min(gravada, atual): nunca abre depois do que o convite prometeu');
T::ok($svc($at)->waitingRoomClock($s)['is_open'] === true, '10 min antes com gravada de 15 min: aberta');
T::ok($svc($sched->modify('-20 minutes'))->waitingRoomClock($s)['is_open'] === false, '20 min antes: fechada');

echo "== antecedência fora do limite é contida (0..120)\n";
putenv('TELECONSULT_JOIN_EARLY_MINUTES=999');
T::ok(TeleconsultationService::joinEarlyMinutes() === 120, '999 vira 120');
$e = $svc($sched->modify('-130 minutes'))->effectiveJoinOpensAt($s);
T::ok($e == $sched->modify('-120 minutes')->setTimezone($utc), 'abertura efetiva respeita o teto de 120 min');
putenv('TELECONSULT_JOIN_EARLY_MINUTES=abc');
T::ok(TeleconsultationService::joinEarlyMinutes() === 15, 'valor inválido volta ao padrão de 15 min');

echo "== abertura nunca passa do fechamento gravado\n";
putenv('TELECONSULT_JOIN_EARLY_MINUTES=15');
$fake = ['join_opens_at_utc' => '2026-11-10 17:00:00', 'join_closes_at_utc' => '2026-11-10 16:00:00', 'scheduled_at' => '2026-11-10 14:00:00'];
T::ok($svc()->effectiveJoinOpensAt($fake) <= new DateTimeImmutable('2026-11-10 17:00:00', $utc), 'dados incoerentes não abrem depois do gravado');

echo "== token e clínica\n";
T::throws(fn() => $svc()->checkWaitingRoomStatus('token-invalido-TESTE'), 'token inválido é recusado');
$sB = $svc()->getSessionByPatientToken($tokB);
T::ok((int)$sB['id'] === (int)$tB['session_id'] && (int)$sB['id'] !== (int)$tA['session_id'], 'token da clínica B só abre a sessão da clínica B');
$cBStatus = $svc()->checkWaitingRoomStatus($tokB);
T::ok($cBStatus['status'] !== 'waiting_room', 'entrada do paciente A não muda a sessão da clínica B');

echo "== portal do paciente usa a mesma regra\n";
putenv('TELECONSULT_JOIN_EARLY_MINUTES=45');
$pa = (new PatientAppointmentService($pdo, $now))->nextAppointment($p);
$expect = $sched->modify('-45 minutes')->format(DATE_ATOM);
T::ok($pa !== null && ($pa['video_opens_at_iso'] ?? '') === $expect, 'portal: abertura = agendado − 45 min (' . ($pa['video_opens_at_iso'] ?? 'null') . ')');
T::ok($pa !== null && $pa['video_status'] !== 'aguardando_horario', 'portal: não manda esperar quando a sala já abriu');
putenv('TELECONSULT_JOIN_EARLY_MINUTES=5');
$pa = (new PatientAppointmentService($pdo, $now))->nextAppointment($p);
T::ok($pa !== null && ($pa['video_opens_at_iso'] ?? '') === $sched->modify('-15 minutes')->format(DATE_ATOM), 'portal com 5 min: mantém a abertura gravada (15 min)');
putenv('TELECONSULT_JOIN_EARLY_MINUTES');

echo "== telas: sem estilo inline, sem cor fixa, sem script inline\n";
$files = glob($root . '/views/teleconsultation/*.php') ?: [];
$files = array_merge($files, glob($root . '/views/teleconsultation/partials/*.php') ?: []);
$bad = [];
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match('/\sstyle\s*=/i', $src)) $bad[] = basename($f) . ': style=';
    if (preg_match('/#[0-9a-f]{3,8}\b(?![^<]*>)/i', preg_replace('/href="#[^"]*"/', '', $src))) $bad[] = basename($f) . ': cor hex';
    if (preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $src)) $bad[] = basename($f) . ': <script> inline';
    if (preg_match('/\son[a-z]+\s*=/i', $src)) $bad[] = basename($f) . ': handler inline';
}
T::ok(count($files) >= 5 && $bad === [], 'views da teleconsulta limpas' . ($bad ? ' — ' . implode('; ', $bad) : ''));
$css = (string)file_get_contents($root . '/public/assets/css/teleconsulta.css');
T::ok(preg_match('/#[0-9a-f]{3,8}\b/i', $css) === 0 && preg_match('/rgba?\(/i', $css) === 0, 'teleconsulta.css só com tokens (sem hex/rgb)');
preg_match_all('/var\((--[a-z0-9-]+)/', $css, $m);
$tokens = (string)file_get_contents($root . '/public/assets/css/clinical-botanical.css');
$missing = array_values(array_filter(array_unique($m[1]), fn($t) => !str_contains($tokens, $t . ':')));
T::ok($missing === [], 'todo token usado existe em clinical-botanical.css' . ($missing ? ' — ' . implode(', ', $missing) : ''));
foreach (['professional_room.php', 'patient_room.php', 'patient_waiting.php', 'confirm_open.php', 'error.php'] as $v) {
    $src = (string)file_get_contents($root . '/views/teleconsultation/' . $v);
    T::ok(strpos($src, 'clinical-botanical.css') !== false && strpos($src, 'clinical-botanical.css') < strpos($src, 'teleconsulta.css?v=v54-27'), "$v carrega os tokens antes de teleconsulta.css?v=v54-27");
}
$js = (string)file_get_contents($root . '/public/assets/js/teleconsulta-webrtc.js');
T::ok(!str_contains($js, '.style.'), 'webrtc.js não escreve estilo inline (usa classe is-screen)');
$pro = (string)file_get_contents($root . '/views/teleconsultation/professional_room.php');
foreach (['tc-remote', 'tc-local', 'tc-start', 'tc-mic', 'tc-cam', 'tc-screen', 'tc-status', 'tc-evolution', 'tc-lobby', 'tc-lobby-actions', 'tc-draft-status', 'tc-fullscreen', 'tc-focus', 'tc-panel'] as $id) {
    if (!str_contains($pro, 'id="' . $id . '"')) $bad[] = $id;
}
T::ok($bad === [], 'sala do nutricionista mantém os ids usados pelo JS');
T::ok(str_contains($pro, 'Sem gravação') || str_contains($pro, 'sem gravação'), 'aviso de "sem gravação" continua visível');

T::done();
