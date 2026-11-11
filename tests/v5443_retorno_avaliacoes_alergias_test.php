<?php
// v54.43 (NI2-707 + NI2-708 + NI2-709): agendar retorno no contexto, aba Avaliações e alergias coerentes.
// Dados TESTE.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5443-' . getmypid() . '.sqlite';
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
$csrf = fn(string $b) => preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m) ? html_entity_decode($m[1]) : '';
$loc = fn(string $h) => preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : '';
try {
    // ---------- NI2-705 ----------
    [$c, $b] = $req('/__teste_login?u=1&c=1&v=0&to=/pacientes/1');
    [$c, $b] = $req('/pacientes/1');
    // ---------- NI2-709: "Nega alergias" do cadastro é a fonte quando a anamnese não avaliou ----------
    [$c, $b] = $req('/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
    $pdo->exec("UPDATE patients SET allergy_assessment='denied', allergies=NULL WHERE id=2");
    $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 1, NULL, 'not_assessed', 0, '2026-11-01 10:00:00')");
    [$c, $b] = $req('/pacientes/2');
    $ok($c === 200 && str_contains($b, 'Nega alergias'), 'perfil: selo "Nega alergias" vindo do cadastro');
    $ok(!preg_match('/alergias? n[aã]o registrad/iu', $b), 'perfil: nada de "não registradas" depois de negar');
    [$c, $b] = $req('/pacientes/2/anamnese');
    $ok($c === 200 && str_contains($b, 'Nega alergias') && str_contains($b, 'Origem: Cadastro'), 'anamnese: resumo crítico mostra "Nega alergias (Origem: Cadastro)"');
    $ok(!preg_match('/alergias? n[aã]o (registrad|avaliad)/iu', $b), 'anamnese: sem "não registradas/não avaliadas"');
    [$c, $b] = $req('/planos/1');
    $ok($c === 200 && str_contains($b, 'Nega alergias'), 'editor do plano: mesmo estado "Nega alergias"');
    $ok(!preg_match('/alergias? n[aã]o (registrad|avaliad)/iu', $b), 'editor do plano: sem "não registradas/não avaliadas"');
    $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 2, NULL, 'not_assessed', 1, '2026-11-02 10:00:00')");
    [$c, $b] = $req('/pacientes/2');
    $ok(str_contains($b, 'Nega alergias') && !preg_match('/alergias? n[aã]o registrad/iu', $b), 'rascunho de anamnese vazio não apaga a negativa do cadastro');
    $pdo->exec("DELETE FROM anamneses WHERE patient_id=2");
    $pdo->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (1, 2, 1, 1, 'amendoim TESTE', 'recorded', 0, '2026-11-03 10:00:00')");
    [$c, $b] = $req('/pacientes/2/anamnese');
    $ok(str_contains($b, 'amendoim TESTE') && !str_contains($b, 'Origem: Cadastro'), 'alergia registrada na anamnese continua prevalecendo');
    $pdo->exec("DELETE FROM anamneses WHERE patient_id=2");

    // ---------- NI2-708: aba Avaliações (antropometria); "Evolução" só para prontuário ----------
    [$c, $b] = $req('/pacientes/2');
    $ok(str_contains($b, 'href="/pacientes/2/antropometria" data-tab="avaliacoes">Avaliações<'), 'aba "Avaliações" abre a antropometria');
    $ok(!preg_match('#href="/pacientes/2/antropometria"[^>]*>Evolução#u', $b), 'nenhuma aba "Evolução" aponta para antropometria');
    [$c, $b] = $req('/pacientes/2/antropometria');
    $ok($c === 200 && str_contains($b, 'data-tab="avaliacoes" aria-current="page"') && str_contains($b, 'Antropometria e avaliações'), 'página de antropometria: aba Avaliações ativa e título "Antropometria e avaliações"');

    // ---------- NI2-707: agendar retorno a partir do perfil ----------
    $pdo->exec("UPDATE booking_settings SET min_advance_hours = 0, max_future_days = 800 WHERE clinic_id = 1");
    $wk = []; foreach (['seg','ter','qua','qui','sex','sab','dom'] as $d) $wk[$d] = ['enabled' => true, 'start' => '08:00', 'end' => '23:00', 'break_start' => '12:00', 'break_end' => '13:00'];
    $pdo->prepare("UPDATE onboarding_progress SET practice_preferences = ? WHERE user_id = 1 AND clinic_id = 1")->execute([json_encode(['weekly_availability' => $wk, 'default_duration_minutes' => 60, 'working_days' => array_keys($wk)])]);
    [$c, $b] = $req('/pacientes/1');
    $ok((bool)preg_match('/href="\/agenda\?novo=1&amp;paciente=1&amp;de=paciente" data-testid="care-schedule">Agendar</', $b), 'perfil sem consulta: "Agendar" leva à agenda no contexto do paciente');
    [$c, $b] = $req('/agenda?novo=1&paciente=1&de=paciente');
    $ok(str_contains($b, 'name="return_to" value="patient"') && str_contains($b, '<option value="first_visit" selected'), 'paciente sem consulta realizada: tipo padrão Primeira consulta, volta ao perfil');
    $pdo->exec("UPDATE appointments SET status='completed', scheduled_at='2026-10-20 10:00:00' WHERE id=1");
    [$c, $b] = $req('/pacientes/2');
    $ok((str_contains($b, 'data-testid="care-schedule-return"') || str_contains($b, 'data-testid="care-schedule"')) && str_contains($b, '/agenda?novo=1&amp;paciente=2&amp;de=paciente'), 'perfil com consulta: "Agendar retorno" disponível');
    [$c, $b] = $req('/agenda?novo=1&paciente=2&de=paciente');
    $ok(str_contains($b, '<option value="follow_up" selected'), 'paciente que já teve consulta: tipo padrão Retorno');
    $ok(str_contains($b, 'data-testid="agenda-suggested-slot"'), 'sugere o próximo horário livre');
    $sd = preg_match('/name="scheduled_date"[^>]*value="([0-9-]+)"|value="([0-9-]+)"[^>]*name="scheduled_date"/', $b, $m) ? ($m[1] ?: $m[2]) : '';
    $st = preg_match('/name="scheduled_time"[^>]*value="([0-9:]+)"|value="([0-9:]+)"[^>]*name="scheduled_time"/', $b, $m) ? ($m[1] ?: $m[2]) : '';
    $nowSp = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $ok($sd !== '' && $st !== '' && "$sd $st" > $nowSp->format('Y-m-d H:i'), "horário sugerido é futuro ($sd $st)");
    [$c, $b2, $h] = $req('/agenda', ['_csrf_token' => $csrf($b), 'patient_id' => 2, 'scheduled_date' => $sd, 'scheduled_time' => $st, 'duration_minutes' => 60, 'type' => 'follow_up', 'return_to' => 'patient']);
    $ok(str_starts_with($loc($h), '/pacientes/2?success=agendado'), 'depois de salvar volta ao perfil do paciente (' . $loc($h) . ')');
    $row = $pdo->query("SELECT type, status FROM appointments WHERE patient_id=2 AND id<>1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_NUM);
    $ok($row && $row[0] === 'follow_up' && $row[1] === 'scheduled', 'retorno gravado como follow_up agendado');
    [$c, $b3] = $req('/agenda?novo=1&paciente=2&de=paciente');
    $sd2 = preg_match('/name="scheduled_date"[^>]*value="([0-9-]+)"|value="([0-9-]+)"[^>]*name="scheduled_date"/', $b3, $m) ? ($m[1] ?: $m[2]) : '';
    $st2 = preg_match('/name="scheduled_time"[^>]*value="([0-9:]+)"|value="([0-9:]+)"[^>]*name="scheduled_time"/', $b3, $m) ? ($m[1] ?: $m[2]) : '';
    $ok("$sd2 $st2" > "$sd $st", "horário já ocupado não é sugerido de novo ($sd2 $st2)");
    [$c, $b] = $req('/pacientes/2?success=agendado');
    $ok(str_contains($b, 'Consulta agendada.'), 'perfil confirma o agendamento');
    [$c, $b2, $h] = $req('/agenda', ['_csrf_token' => $csrf($b), 'patient_id' => 1, 'scheduled_date' => $sd, 'scheduled_time' => $st, 'duration_minutes' => 30, 'type' => 'first_visit', 'allow_conflict' => '1']);
    $ok(str_starts_with($loc($h), '/agenda?date='), 'agendamento pela agenda continua voltando para a agenda');
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nv54.43 retorno + avaliações + alergias: " . ($n - $fail) . " PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
