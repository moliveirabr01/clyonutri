<?php
// v54.39: horários salvos em Configurações > Agenda e disponibilidade persistem e o link público
// oferece horários no passo da duração, dentro do expediente e fora da pausa. Dados TESTE.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5439-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
$pdo = new PDO('sqlite:' . $db);
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
$req = function (string $path, ?array $post = null) use ($port, $jar) {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    return [$code, substr($r, $hs), substr($r, 0, $hs)];
};
$form = function () use ($req) {
    [, $h] = $req('/configuracoes?tab=agenda&secao=horarios');
    preg_match('#name="_csrf_token" value="([^"]+)"#', $h, $t); preg_match('#name="revision" value="([^"]+)"#', $h, $r);
    return [$h, ['tab' => 'agenda', 'section' => 'availability', '_csrf_token' => $t[1] ?? '', 'revision' => $r[1] ?? '']];
};
$val = fn(string $h, string $d, string $f) => preg_match('#id="availability-' . $d . '-' . $f . '"[^>]*value="([^"]*)"#', $h, $m) ? $m[1] : null;
// Configuração do Maicon (print 1): pausa antiga 12–13 continua preenchida em seg/ter, que começam depois.
$cfg = ['seg' => ['14:00', '18:00', '12:00', '13:00'], 'ter' => ['15:00', '19:00', '12:00', '13:00'], 'qua' => ['09:00', '18:00', '12:00', '14:00'], 'qui' => ['08:00', '18:00', '12:00', '13:00'], 'sex' => ['08:00', '18:00', '12:00', '13:00'], 'sab' => null, 'dom' => null];
$weekly = []; foreach ($cfg as $d => $v) $weekly[$d] = $v ? ['enabled' => '1', 'start' => $v[0], 'end' => $v[1], 'break_start' => $v[2], 'break_end' => $v[3]] : ['start' => '', 'end' => '', 'break_start' => '', 'break_end' => ''];
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/configuracoes');
    $pdo->exec("UPDATE booking_settings SET min_advance_hours = 0 WHERE clinic_id = 1");
    [$h, $base] = $form();
    $ok($val($h, 'qui', 'start') === '08:00', 'antes: sugestão legada 08:00 na tela');
    [$code, , $hdr] = $req('/configuracoes', $base + ['default_duration_minutes' => '60', 'weekly' => $weekly]);
    $ok($code === 302 && str_contains($hdr, 'saved='), "salvar com pausa antiga fora do expediente (seg/ter) é aceito ($code)");
    preg_match('#Location: (\S+)#', $hdr, $loc);
    [, $after] = $req($loc[1] ?? '/configuracoes?tab=agenda');
    $ok(str_contains($after, 'Horários de atendimento atualizados.') && str_contains($after, 'segunda-feira, terça-feira'), 'mensagem de sucesso avisa a pausa removida em seg/ter');
    $prefs = json_decode((string)$pdo->query('SELECT practice_preferences FROM onboarding_progress WHERE user_id = 1 AND clinic_id = 1')->fetchColumn(), true);
    $w = $prefs['weekly_availability'] ?? [];
    $ok(($w['seg']['start'] ?? '') === '14:00' && ($w['seg']['break_start'] ?? 'x') === '', 'banco: seg 14:00 sem pausa');
    $ok(($w['ter']['start'] ?? '') === '15:00' && ($w['ter']['end'] ?? '') === '19:00', 'banco: ter 15:00–19:00');
    $ok(($w['qua']['start'] ?? '') === '09:00' && ($w['qua']['break_end'] ?? '') === '14:00', 'banco: qua 09:00, pausa até 14:00 (não 00:00)');
    $ok(empty($w['sab']['enabled']) && empty($w['dom']['enabled']) && ($prefs['default_duration_minutes'] ?? 0) === 60, 'banco: sáb/dom fechados, 60 min');
    // recarregar mostra o salvo
    [$h] = $form();
    foreach (['seg' => '14:00', 'ter' => '15:00', 'qua' => '09:00', 'qui' => '08:00', 'sex' => '08:00'] as $d => $st) $ok($val($h, $d, 'start') === $st, "recarregar: $d começa $st");
    $ok($val($h, 'qua', 'break_end') === '14:00' && $val($h, 'seg', 'break_start') === '', 'recarregar: qua pausa até 14:00, seg sem pausa');
    $ok(!str_contains($h, 'Confirmar estes horários'), 'recarregar: não volta para a sugestão legada');
    // link público: próxima quinta e segunda
    $tz = new DateTimeZone('America/Sao_Paulo');
    $thu = (new DateTimeImmutable('next thursday', $tz))->modify('+7 days')->format('Y-m-d');
    $mon = (new DateTimeImmutable('next monday', $tz))->modify('+7 days')->format('Y-m-d');
    $wed = (new DateTimeImmutable('next wednesday', $tz))->modify('+7 days')->format('Y-m-d');
    $slots = function ($date) use ($req) { [$c, $b] = $req("/api/v1/booking/slots?professional_id=1&date=$date&modality=presential"); return [$c, array_column(json_decode($b, true)['available_slots'] ?? [], 'time')]; };
    [$c, $t] = $slots($thu);
    $ok($c === 200 && $t === ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00', '17:00'], "quinta $thu: 60 em 60 min fora da pausa (" . implode(' ', $t) . ')');
    [, $t] = $slots($mon);
    $ok($t === ['14:00', '15:00', '16:00', '17:00'], 'segunda: 14:00–17:00 (' . implode(' ', $t) . ')');
    [, $t] = $slots($wed);
    $ok($t === ['09:00', '10:00', '11:00', '14:00', '15:00', '16:00', '17:00'], 'quarta: pausa 12–14 (' . implode(' ', $t) . ')');
    // pausa parcialmente fora continua barrada, com o dia por extenso e os dados mantidos na tela
    [, $base] = $form(); $bad = $weekly; $bad['qui']['break_start'] = '07:00';
    [$code, $h] = $req('/configuracoes', $base + ['default_duration_minutes' => '60', 'weekly' => $bad]);
    $ok($code === 422 && str_contains($h, 'data-testid="availability-error"') && str_contains($h, 'Na quinta-feira, a pausa'), "pausa 07:00–13:00 em 08–18 recusada perto do botão ($code)");
    $ok($val($h, 'qui', 'break_start') === '07:00', 'erro mantém o que foi digitado');
    $bad = $weekly; $bad['qua']['break_end'] = '';
    [$code, $h] = $req('/configuracoes', $base + ['default_duration_minutes' => '60', 'weekly' => $bad]);
    $ok($code === 422 && str_contains($h, 'preencha o início e o fim da pausa'), 'pausa só com início é recusada com mensagem clara');
} finally { proc_terminate($proc); @unlink($db); @unlink($jar); }
echo "\nv54.39 agenda: " . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
