<?php
// v54.42 (NI2-705 + NI2-706): "Iniciar agora" no perfil cria atendimento imediato sem agenda;
// concluir com etapa em "Revisão pendente" exige confirmação explícita e fica auditado. Dados TESTE.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5442-' . getmypid() . '.sqlite';
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
    $ok($c === 200, 'perfil do paciente TESTE abre');
    $ok(str_contains($b, 'data-testid="care-start-now"') && str_contains($b, 'action="/pacientes/1/iniciar-agora"'), 'perfil sem consulta mostra "Iniciar agora" (POST /pacientes/1/iniciar-agora)');
    $ok((bool)preg_match('/href="\/agenda\?novo=1&amp;paciente=1&amp;de=paciente" data-testid="care-schedule">Agendar</', $b), '"Agendar" continua levando à agenda com o paciente');
    $ok(!str_contains($b, '>Novo atendimento<'), 'rótulo ambíguo "Novo atendimento" saiu');
    $tok = $csrf($b);
    [$c] = $req('/pacientes/1/iniciar-agora', ['_csrf_token' => 'x']);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE patient_id=1")->fetchColumn() === 0, 'sem CSRF válido nada é criado');
    [$c, $b, $h] = $req('/pacientes/1/iniciar-agora', ['_csrf_token' => $tok]);
    $a = $pdo->query("SELECT * FROM appointments WHERE patient_id=1 ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC);
    $ok($a && $a['status'] === 'in_progress', 'atendimento criado já em andamento');
    $ok($a && (int)$a['clinic_id'] === 1 && (int)$a['professional_id'] === 1, 'na clínica e com o profissional de quem iniciou');
    $ok($a && abs((new DateTime($a["scheduled_at"], new DateTimeZone("America/Sao_Paulo")))->getTimestamp() - time()) < 600, 'horário = agora (não passa pela agenda)');
    $ok($c === 302 && $loc($h) === '/consulta/' . (int)$a['id'], 'redireciona direto para a sala /consulta/{id}');
    $hist = $pdo->query("SELECT to_status FROM appointment_status_history WHERE appointment_id=" . (int)$a['id'] . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $ok($hist === ['scheduled', 'in_progress'], 'trilha registra criação e início imediato');
    [$c, $b, $h] = $req('/pacientes/1/iniciar-agora', ['_csrf_token' => $tok]);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE patient_id=1")->fetchColumn() === 1 && $loc($h) === '/consulta/' . (int)$a['id'], 'clique repetido reabre a mesma consulta (idempotente)');
    [$c, $b] = $req('/pacientes/1');
    $ok(str_contains($b, 'data-testid="care-continue"'), 'perfil passa a mostrar "Continuar atendimento"');
    [$c, $b] = $req('/pacientes/2');
    $ok(str_contains($b, 'data-testid="care-start"') || str_contains($b, 'care-prepare'), 'paciente com consulta agendada mantém o fluxo da agenda');
    // isolamento: clínica 2 não inicia atendimento de paciente da clínica 1
    $req('/__teste_login?u=2&c=2&v=0&to=/');
    [$c, $b2] = $req('/painel'); $tok2 = $csrf($b2) ?: $tok;
    $before = (int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
    $req('/pacientes/2/iniciar-agora', ['_csrf_token' => $tok2]);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn() === $before, 'outra clínica não cria atendimento para paciente alheio');

    // ---------- NI2-706 ----------
    $req('/__teste_login?u=1&c=1&v=0&to=/');
    $aid = (int)$a['id'];
    $pdo->exec("INSERT INTO anamneses (clinic_id,patient_id,professional_id,version,is_draft,chief_complaint,created_at) VALUES (1,1,1,1,0,'TESTE queixa',datetime('now'))");
    [$c, $b] = $req("/consulta/$aid");
    $ok(str_contains($b, 'data-testid="consult-pending-review"') && str_contains($b, 'data-step="anamnese"'), 'sala lista Anamnese em "Revisão pendente" antes de concluir');
    $ok(str_contains($b, 'name="confirm_pending_review"'), 'sala pede confirmação explícita');
    $tok = $csrf($b);
    $base = ['_csrf_token' => $tok, 'evolution_summary' => 'TESTE evolução'];
    [$c, $b, $h] = $req("/consulta/$aid/concluir", $base);
    $st = fn() => $pdo->query("SELECT status FROM appointments WHERE id=$aid")->fetchColumn();
    $ok(str_contains($loc($h), 'error=revisao_pendente') && $st() === 'in_progress', 'concluir sem confirmar é bloqueado e a consulta segue em andamento');
    [$c, $b] = $req("/consulta/$aid?error=revisao_pendente");
    $ok(str_contains($b, 'revisão pendente') && str_contains($b, 'TESTE evolução'), 'mensagem explica o bloqueio e o resumo é preservado');
    [$c, $b, $h] = $req("/consulta/$aid/concluir", $base + ['confirm_pending_review' => '1', 'pending_review_keys' => 'avaliacao']);
    $ok($st() === 'in_progress', 'confirmação de uma lista diferente da atual não vale');
    [$c, $b, $h] = $req("/consulta/$aid/concluir", $base + ['confirm_pending_review' => '1', 'pending_review_keys' => 'anamnese']);
    $ok($st() === 'completed', 'com confirmação explícita a consulta é concluída');
    $log = $pdo->query("SELECT * FROM audit_logs WHERE action='CONSULTATION_CONCLUDED_WITH_PENDING_REVIEW' AND resource_id='$aid'")->fetch(PDO::FETCH_ASSOC);
    $det = $log ? json_decode((string)$log['details'], true) : null;
    $ok($log && (int)$log['user_id'] === 1 && (int)$log['clinic_id'] === 1, 'decisão auditada com usuário e clínica');
    $ok(is_array($det) && ($det['pending'][0]['key'] ?? '') === 'anamnese' && ($det['pending'][0]['label'] ?? '') === 'Anamnese', 'auditoria guarda as etapas pendentes confirmadas');
    // sem pendência: conclusão normal, sem confirmação e sem auditoria extra
    $r = $req('/pacientes/1/iniciar-agora', ['_csrf_token' => $tok]);
    $aid2 = (int)$pdo->query("SELECT MAX(id) FROM appointments WHERE patient_id=1")->fetchColumn();
    $pdo->exec("DELETE FROM anamneses WHERE patient_id=1");
    [$c, $b] = $req("/consulta/$aid2");
    $ok($aid2 !== $aid && !str_contains($b, 'consult-pending-review'), 'sem etapa pendente a sala não pede confirmação');
    $req("/consulta/$aid2/concluir", ['_csrf_token' => $csrf($b), 'evolution_summary' => 'TESTE evolução 2']);
    $ok($pdo->query("SELECT status FROM appointments WHERE id=$aid2")->fetchColumn() === 'completed', 'sem pendência conclui normalmente');
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nv54.42 iniciar agora + revisão pendente: " . ($n - $fail) . " PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
