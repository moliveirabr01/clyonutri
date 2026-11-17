<?php
// NI2-893 item 3 / CR875-11 (v54.76): agendamento online sem login não devolve ids sequenciais; devolve um código de
// 8 caracteres não sequencial (igual nas confirmações repetidas do mesmo token) que não abre nada de ninguém.
// Servidor PHP real + SQLite isolado (fixture ni2_433), dona liga o autoagendamento pela tela real. Sem mocks.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$dir = '/tmp/ni2-433-cr87511-' . getmypid(); @mkdir($dir); $db = "$dir/test.sqlite";
$envArr = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'APP_ENV' => 'production', 'FORCE_HTTPS' => 'false', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'MAIL_DRIVER' => 'null', 'TZ' => 'America/Sao_Paulo'];
$env = implode(' ', array_map(fn($k, $v) => $k . '=' . escapeshellarg($v), array_keys($envArr), $envArr));
exec("cd " . escapeshellarg($root) . " && env $env " . escapeshellarg(PHP_BINARY) . " tests/support/ni2_433_fixture.php prepare 2>&1", $o, $rc);
$ok($rc === 0, 'fixture TESTE (clínicas A e B, Dra. Ana com jornada)');
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env BOOKING_RATE_LIMIT_HOLD=500 BOOKING_RATE_LIMIT_CONFIRM=500 " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -d session.save_path=" . escapeshellarg($dir) . " -S 127.0.0.1:$port -t public", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$mk = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    return function (string $path, $data = null, bool $json = false) use ($port, $jar) {
        $c = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
        if ($data !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, $json ? json_encode($data) : http_build_query($data));
            curl_setopt($c, CURLOPT_HTTPHEADER, [$json ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded']);
        }
        $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$code, $b, json_decode($b, true)];
    };
};
$csrf = fn(string $h) => preg_match('/name="_csrf_token" value="([^"]+)"/', $h, $m) ? $m[1] : '';
$pdo = new PDO('sqlite:' . $db); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    $owner = $mk();
    [, $h] = $owner('/login'); $owner('/login', ['_csrf_token' => $csrf($h), 'email' => 'dona.a@example.test', 'password' => 'Agenda@2026']);
    [, $h] = $owner('/configuracoes');
    [$c] = $owner('/configuracoes', ['_csrf_token' => $csrf($h), 'section' => 'booking', 'booking_enabled' => '1', 'booking_allow_presential' => '1', 'booking_allow_telehealth' => '1',
        'booking_min_advance_hours' => '2', 'booking_max_future_days' => '60', 'booking_hold_ttl_minutes' => '15', 'booking_cancel_min_advance_hours' => '4', 'booking_reschedule_min_advance_hours' => '4']);
    $on = (int)$pdo->query("SELECT COUNT(*) FROM booking_settings WHERE clinic_id = 1 AND is_enabled = 1")->fetchColumn();
    $ok(in_array($c, [200, 302, 303], true) && $on === 1, "dona liga o autoagendamento pela tela de configurações (HTTP $c, booking_settings ligado)");
    // NI2-893 item 5: siga os links reais do menu do consultório com a sessão autenticada.
    [$menuStatus, $menuHtml] = $owner('/dashboard');
    $ok($menuStatus === 200, 'dashboard autenticado para verificar os links do menu');
    preg_match('/<aside\b[^>]*class="app-sidebar"[^>]*>(.*?)<\/aside>/s', $menuHtml, $sidebar);
    preg_match_all('/<a\b[^>]*href="([^"#]+)"/i', $sidebar[1] ?? '', $links);
    $paths = array_values(array_unique(array_filter(array_map(
        fn($href) => html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $links[1] ?? []
    ), fn($href) => str_starts_with($href, '/') && !str_starts_with($href, '//'))));
    $ok(count($paths) >= 10, 'menu real contém links suficientes para a varredura');
    $ok(!in_array('/relatorios', array_map(fn($href) => parse_url($href, PHP_URL_PATH), $paths), true), 'menu não aponta para /relatorios');
    foreach ($paths as $path) {
        [$linkStatus] = $owner($path);
        $ok($linkStatus === 200, "link do menu $path responde 200 (HTTP $linkStatus)");
    }
    $anon = $mk();
    $term = (int)$pdo->query("SELECT id FROM consent_terms WHERE purpose_code = 'clinical_care' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $d = new DateTime('now', new DateTimeZone('America/Sao_Paulo')); $d->modify('+3 days'); while ((int)$d->format('N') !== 3) $d->modify('+1 day');
    $wed = $d->format('Y-m-d');
    $codes = []; $resp = [];
    foreach (['09:00' => 'Lia TESTE Um', '10:00' => 'Rui TESTE Dois'] as $t => $name) {
        [$hc, , $hj] = $anon('/api/v1/booking/holds', ['professional_id' => 1, 'service_type' => 'first_visit', 'modality' => 'presential', 'scheduled_at' => "$wed $t"], true);
        $token = (string)($hj['hold_token'] ?? '');
        $body = ['hold_token' => $token, 'name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@paciente.test', 'terms_accepted' => true, 'consent_term_id' => $term];
        [$cc, $raw, $cj] = $anon('/api/v1/booking/confirm', $body, true);
        [$cc2, , $cj2] = $anon('/api/v1/booking/confirm', $body, true); // repetição do mesmo token (idempotente)
        $resp[] = [$hc, $cc, $raw, $cj, $cc2, $cj2];
        $codes[] = (string)($cj['booking_code'] ?? '');
    }
    [$r1, $r2] = $resp;
    $ok($r1[0] === 201 || $r1[0] === 200, "reserva do horário (HTTP {$r1[0]})");
    $ok($r1[1] === 200 && $r2[1] === 200, "duas confirmações seguidas pela API pública: {$r1[1]} e {$r2[1]}");
    $ok(!array_key_exists('appointment_id', $r1[3] ?? []) && !array_key_exists('patient_id', $r1[3] ?? []) && !array_key_exists('appointment_id', $r2[3] ?? [])
        && !preg_match('/"(appointment_id|patient_id)"/', $r1[2] . $r2[2]), 'resposta pública sem appointment_id nem patient_id');
    $ok((bool)preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $codes[0]) && (bool)preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $codes[1]) && $codes[0] !== $codes[1], "códigos de 8 caracteres e diferentes: {$codes[0]} / {$codes[1]}");
    $ids = $pdo->query("SELECT id FROM appointments WHERE scheduled_at LIKE '$wed%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $ok(count($ids) === 2 && (int)$ids[1] === (int)$ids[0] + 1 && !ctype_digit($codes[0]) && !ctype_digit($codes[1]), 'no banco as consultas são consecutivas (' . implode(',', $ids) . '); os códigos não são números nem seguem ordem');
    $ok($r1[4] === 200 && ($r1[5]['booking_code'] ?? '') === $codes[0] && !array_key_exists('appointment_id', $r1[5] ?? []), 'confirmação repetida do mesmo token: mesmo código, sem id');
    $aud = $pdo->query("SELECT details FROM audit_logs WHERE action = 'BOOKING_CONFIRMED' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $ok(count($aud) === 2 && str_contains($aud[0], $codes[0]) && str_contains($aud[1], $codes[1]) && !preg_match('/paciente\.test|TESTE Um/i', implode('', $aud)), 'auditoria BOOKING_CONFIRMED guarda o código (suporte acha o agendamento), sem dado pessoal');
    // o código não abre nada: nem como token de reserva, nem em rota de consulta
    [$x1] = $anon('/api/v1/booking/release', ['hold_token' => $codes[0]], true);
    [$x2, $b2] = $anon('/api/v1/booking/confirm', ['hold_token' => $codes[0], 'name' => 'Outra Pessoa', 'email' => 'outra@paciente.test', 'terms_accepted' => true, 'consent_term_id' => $term], true);
    [$x3, $b3] = $anon('/agendar/consulta/' . $codes[0]);
    $ok($x1 === 400 && in_array($x2, [404, 422], true) && !str_contains($b2, 'Lia') && in_array($x3, [404, 302], true) && !str_contains($b3, 'Lia TESTE'), "o código não serve para liberar, confirmar ou abrir agendamento de outra pessoa ($x1/$x2/$x3)");
    $canc = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE scheduled_at LIKE '$wed%' AND status = 'scheduled'")->fetchColumn();
    $ok($canc === 2, 'as duas consultas continuam agendadas depois das tentativas com o código');
    $view = (string)file_get_contents($root . '/views/booking/public_booking.php');
    $ok(str_contains($view, 'data-testid="booking-code"') && !str_contains($view, 'appointment_id'), 'comprovante da página pública mostra o código (sem id)');
} finally {
    proc_terminate($proc); array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
}
echo "\nCR875-11 código do agendamento: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
