<?php
// v54.40: feriados nacionais de 2026 e 2027 (+ pontos facultativos opcionais) em Feriados e bloqueios,
// sem duplicar e bloqueando o link público. Dados TESTE.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5440-' . getmypid() . '.sqlite';
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
use ClyoNutri\Domain\Booking\NationalHolidays as NH;
require_once $root . '/vendor/autoload.php';
$exp26 = ['2026-01-01','2026-04-03','2026-04-21','2026-05-01','2026-09-07','2026-10-12','2026-11-02','2026-11-15','2026-11-20','2026-12-25'];
$exp27 = ['2027-01-01','2027-03-26','2027-04-21','2027-05-01','2027-09-07','2027-10-12','2027-11-02','2027-11-15','2027-11-20','2027-12-25'];
$ok(array_column(NH::forYear(2026), 'date') === $exp26, 'feriados nacionais 2026 (Sexta-feira da Paixão 03/04)');
$ok(array_column(NH::forYear(2027), 'date') === $exp27, 'feriados nacionais 2027 (Sexta-feira da Paixão 26/03)');
$ok(array_column(NH::optionalForYear(2026), 'date') === ['2026-02-16','2026-02-17','2026-06-04'], 'pontos facultativos 2026: Carnaval 16–17/02, Corpus Christi 04/06');
$ok(array_column(NH::optionalForYear(2027), 'date') === ['2027-02-08','2027-02-09','2027-05-27'], 'pontos facultativos 2027: Carnaval 08–09/02, Corpus Christi 27/05');
$ok(NH::easter(2026)->format('Y-m-d') === '2026-04-05' && NH::easter(2027)->format('Y-m-d') === '2027-03-28', 'Páscoa 05/04/2026 e 28/03/2027');
$ok(in_array('Dia Nacional de Zumbi e da Consciência Negra', array_column(NH::forYear(2027), 'name'), true), '20/11 Consciência Negra nos dois anos');
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/configuracoes');
    $pdo->exec("UPDATE booking_settings SET min_advance_hours = 0, max_future_days = 800 WHERE clinic_id = 1");
    $wk = []; foreach (['seg','ter','qua','qui','sex','sab','dom'] as $d) $wk[$d] = in_array($d, ['sab','dom'], true) ? ['enabled' => false, 'start' => '', 'end' => '', 'break_start' => '', 'break_end' => ''] : ['enabled' => true, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'];
    $pdo->prepare("UPDATE onboarding_progress SET practice_preferences = ? WHERE user_id = 1 AND clinic_id = 1")->execute([json_encode(['weekly_availability' => $wk, 'default_duration_minutes' => 60, 'working_days' => ['seg','ter','qua','qui','sex']])]);
    $pdo->exec("INSERT INTO booking_calendar_exceptions (clinic_id, professional_id, rule_date, kind, label, created_at) VALUES (1, NULL, '2026-12-25', 'holiday', 'Feriado nacional: Natal', '2026-11-01 10:00:00')");
    [, $h] = $req('/configuracoes?tab=agenda&secao=feriados');
    $ok(str_contains($h, 'data-testid="holiday-import-year-todos"') && str_contains($h, 'Revisar 2026 e 2027'), 'botão "Revisar 2026 e 2027"');
    [, $h] = $req('/configuracoes?tab=agenda&secao=feriados&importar=todos');
    preg_match_all('#name="holiday_dates\[\]" value="([0-9-]+)"([^>]*)>#', $h, $m, PREG_SET_ORDER);
    $all = array_column($m, 1);
    $checked = array_column(array_filter($m, fn($x) => str_contains($x[2], 'checked')), 1);
    $ok(count($all) === 26, 'prévia com 20 feriados + 6 pontos facultativos (' . count($all) . ')');
    $ok(!in_array('2026-12-25', $checked, true) && str_contains($h, 'já cadastrado'), 'Natal 2026 já existente aparece como já cadastrado');
    $ok(count($checked) === 19 && !in_array('2027-02-08', $checked, true) && !in_array('2026-06-04', $checked, true), 'só feriados nacionais vêm marcados; Carnaval/Corpus Christi desmarcados (' . count($checked) . ')');
    $ok(substr_count($h, 'Ponto facultativo</em>') === 6, 'pontos facultativos identificados na lista');
    preg_match('#name="_csrf_token" value="([^"]+)"><input type="hidden" name="section" value="calendar_import"#', $h, $t);
    $post = ['_csrf_token' => $t[1] ?? '', 'section' => 'calendar_import', 'import_year' => 'todos', 'calendar_scope' => 'clinic', 'holiday_dates' => array_merge($checked, ['2027-02-08', '2027-02-09'])];
    [$code, , $hdr] = $req('/configuracoes', $post);
    $ok($code === 302, "salvar 2026 e 2027 ($code)");
    preg_match('#Location: (\S+)#', $hdr, $loc); [, $after] = $req($loc[1] ?? '/');
    $ok(str_contains($after, '21 feriados nacionais importados'), 'mensagem: 21 importados');
    $rows = $pdo->query("SELECT rule_date, label FROM booking_calendar_exceptions WHERE clinic_id = 1 AND kind = 'holiday' AND professional_id IS NULL ORDER BY rule_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ok(count(array_intersect(array_merge($exp26, $exp27), array_keys($rows))) === 20, 'banco: os 20 feriados nacionais de 2026 e 2027');
    $ok(($rows['2027-02-08'] ?? '') === 'Ponto facultativo: Carnaval (segunda-feira)' && !isset($rows['2026-02-16']), 'Carnaval 2027 marcado gravado como ponto facultativo; não marcado não entra');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM booking_calendar_exceptions WHERE rule_date = '2026-12-25'")->fetchColumn() === 1, 'Natal 2026 sem duplicar');
    $req('/configuracoes', $post);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM booking_calendar_exceptions WHERE clinic_id = 1 AND kind = 'holiday'")->fetchColumn() === 22, 'repetir a importação não duplica (22 linhas)');
    [$code] = $req('/configuracoes', ['holiday_dates' => ['2026-03-10']] + $post);
    $ok($code === 422, "data que não é feriado é recusada ($code)");
    $slots = function ($date) use ($req) { [, $b] = $req("/api/v1/booking/slots?professional_id=1&date=$date&modality=presential"); return array_column(json_decode($b, true)['available_slots'] ?? [], 'time'); };
    $ok($slots('2026-11-13') !== [], 'sexta 13/11/2026 com horários');
    $ok($slots('2026-11-20') === [], 'Consciência Negra 20/11/2026 sem horários no link');
    $ok($slots('2027-03-26') === [] && $slots('2027-02-08') === [], 'Sexta da Paixão e Carnaval (marcado) 2027 sem horários');
    $ok($slots('2027-05-27') !== [], 'Corpus Christi 2027 (não marcado) segue aberto');
} finally { proc_terminate($proc); @unlink($db); @unlink($jar); }
echo "\nv54.40 feriados: " . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
