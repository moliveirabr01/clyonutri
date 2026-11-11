<?php
// v54.47 UX Excellence — Z01 (continuidade), G01 (progresso real), V01 (uma ação primária) e isolamento.
// Integração real: servidor php -S + SQLite descartável (seed TESTE "tenant"), decisões gravadas pela rota HTTP.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5447-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' tenant 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE (tenant: v1 concluída + v2 rascunho, clínica B)');
$pdo = new PDO('sqlite:' . $db); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
$req = function (string $path, ?array $post = null, ?string $j = null) use ($port, &$jar) {
    $c = curl_init("http://127.0.0.1:$port$path"); $j = $j ?? $jar;
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    return [$code, substr($r, $hs), substr($r, 0, $hs)];
};
$bar = static function (string $b): string { return preg_match('#<aside class="attendance-mode".*?</aside>#s', $b, $m) ? $m[0] : ''; };
$stepState = static function (string $html, string $key): string { return preg_match('#data-step="' . $key . '" data-state="([a-z_]+)"#', $html, $m) ? $m[1] : ''; };
// Decisão pela rota real, com os campos que o próprio formulário renderiza (revision/evidence/csrf).
$decide = function (string $step, string $decision, string $reason = '') use ($req): int {
    [, $b] = $req('/pacientes/2');
    if (!preg_match('#<li class="care-flow__step[^"]*" data-step="' . $step . '".*?</form>#s', $b, $m)) return 0;
    $f = []; preg_match_all('#<input type="hidden" name="([a-z_]+)" value="([^"]*)"#', $m[0], $mm, PREG_SET_ORDER);
    foreach ($mm as $x) $f[$x[1]] = html_entity_decode($x[2], ENT_QUOTES);
    $f['decision'] = $decision; $f['reason'] = $reason;
    return $req('/consulta/1/progresso', $f)[0];
};
try {
    $pdo->exec("UPDATE appointments SET status='in_progress', scheduled_at=datetime('now','localtime'), type='follow_up' WHERE id=1");
    [$c, $b] = $req('/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
    echo "== Z01 continuidade\n";
    [$c, $b] = $req('/pacientes/2'); $ab = $bar($b);
    $ok($c === 200 && str_contains($ab, 'data-testid="attendance-steps"'), 'Z01 barra do Modo Atendimento lista as etapas do banco');
    $ok($stepState($ab, 'anamnese') === 'in_progress' && str_contains($ab, 'Anamnese: Em andamento'), 'Z01 rascunho v2 aparece como "Em andamento" (texto, não só cor)');
    $ok(str_contains($ab, 'data-testid="attendance-resume"') && str_contains($ab, 'href="/pacientes/2/anamnese?atendimento=1"') && str_contains($ab, 'Continuar anamnese'), 'Z01 próxima ação "Continuar anamnese" com id do atendimento');
    $ok(str_contains($ab, 'data-testid="attendance-last-saved"') && str_contains($ab, 'Anamnese'), 'Z01 última etapa salva vem do registro persistido');
    $ok(str_contains($b, 'a versão concluída permanece vigente'), 'Z-AC04 v2 rascunho não substitui v1 concluída (detalhe da etapa)');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM anamneses WHERE patient_id=2 AND version=1 AND COALESCE(is_draft,0)=0")->fetchColumn() === 1, 'Z-AC04 banco: v1 continua concluída');
    $ok(substr_count($b, 'href="/pacientes/2/anamnese?atendimento=1"') >= 2 && str_contains($b, 'data-step="avaliacao"') && str_contains($b, '/pacientes/2/antropometria?atendimento=1'), 'Z01 links das etapas preservam ?atendimento=');
    echo "== V01 uma ação primária\n";
    $ok(preg_match('#data-testid="care-flow-next"><span>Próxima ação: </span><a class="op-button op-button--secondary"#', $b) === 1, 'V-AC03 bloco de etapas não repete CTA primária quando a barra já tem a próxima ação');
    $ok(substr_count($ab, 'btn btn-primary') === 1, 'V-AC03 barra tem exatamente uma CTA primária');
    // Plano em rascunho: conclui anamnese v2 no banco e cria/garante plano em rascunho
    $pdo->exec("UPDATE anamneses SET is_draft=0 WHERE patient_id=2 AND version=2");
    $plan = (int)$pdo->query("SELECT id FROM meal_plans WHERE clinic_id=1 AND patient_id=2 ORDER BY id LIMIT 1")->fetchColumn();
    if ($plan) $pdo->exec("UPDATE meal_plans SET status='draft' WHERE id=$plan");
    echo "== Z-AC01 plano em rascunho\n";
    [$c, $b] = $req('/pacientes/2'); $ab = $bar($b);
    if ($plan) {
        $ok($stepState($ab, 'plano') === 'in_progress', 'Z-AC01 plano em rascunho = "Em andamento"');
    }
    $ok($stepState($ab, 'anamnese') === 'review', 'G01 anamnese salva mas não vinculada = revisão (existir no banco ≠ concluída)');
    [$c2, $b2] = $req('/pacientes/2');
    $ok($bar($b2) === $ab, 'Z-AC02 refresh: mesmo estado persistido');
    echo "== G01 progresso real\n";
    $pdo->prepare("INSERT INTO care_workflow_policies (clinic_id,user_id,consultation_type,policy_json,request_key,created_at) VALUES (1,1,'follow_up',?,?,datetime('now'))")
        ->execute([json_encode(['anamnese' => 'required', 'avaliacao' => 'required', 'plano' => 'required', 'revisao' => 'required']), 'v5447-policy-' . bin2hex(random_bytes(6))]);
    [$c, $b] = $req('/pacientes/2'); $ab = $bar($b);
    $ok(str_contains($ab, 'Progresso: 0 de 4 etapas necessárias'), 'G-AC01 0 de 4 sem confirmações');
    $ok($decide('anamnese', 'confirm') === 303, 'G01 confirmação gravada pela rota (303)');
    [$c, $b] = $req('/pacientes/2'); $ab = $bar($b);
    $ok(str_contains($ab, 'Progresso: 1 de 4 etapas necessárias') && $stepState($ab, 'anamnese') === 'done', 'G-AC01 1 de 4 após confirmação persistida');
    $ok($decide('avaliacao', 'waived', '') === 422, 'G-AC03 dispensa sem motivo é recusada (422)');
    $ok($decide('avaliacao', 'waived', 'not_indicated') === 303, 'G-AC03 dispensa com motivo gravada');
    [$c, $b] = $req('/pacientes/2'); $ab = $bar($b);
    $ok(str_contains($ab, 'Progresso: 1 de 3 etapas necessárias') && $stepState($ab, 'avaliacao') === 'not_applicable', 'G-AC03 denominador respeita a dispensa (1 de 3), sem erro');
    $ok(!str_contains($b, 'data-testid="care-flow-ready"') && str_contains($ab, 'data-testid="attendance-resume"'), 'G-AC05/G-AC04 com pendência: sem "pronto" e com próxima ação');
    // cross-check com o serviço (mesma fonte)
    $ok((int)$pdo->query("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=1")->fetchColumn() === 2, 'G01 eventos no banco = decisões feitas (2)');
    echo "== Z-AC03 falha de gravação\n";
    $ok($req('/consulta/1/progresso', ['_csrf_token' => 'x', 'patient_id' => 2, 'step' => 'plano', 'decision' => 'required'])[0] === 403, 'Z-AC03 CSRF inválido: 403, nada gravado');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=1")->fetchColumn() === 2, 'Z-AC03 banco inalterado após falha');
    echo "== Z-AC05 isolamento\n";
    $jarB = tempnam(sys_get_temp_dir(), 'jarB');
    $req('/__teste_login?u=2&c=2&v=0&to=/dashboard', null, $jarB);
    [$cb, $bb] = $req('/pacientes/2', null, $jarB);
    $ok(!str_contains($bb, 'data-testid="attendance-mode"') && !str_contains($bb, 'amendoim') && !str_contains($bb, 'Paciente TESTE Dois'), 'Z-AC05 clínica B não vê barra/etapas/dados do paciente A (HTTP ' . $cb . ')');
    [$cp] = $req('/consulta/1/progresso', ['patient_id' => 2, 'step' => 'plano', 'decision' => 'required'], $jarB);
    $ok(in_array($cp, [403, 404, 422], true) && (int)$pdo->query("SELECT COUNT(*) FROM care_progress_events WHERE appointment_id=1")->fetchColumn() === 2, 'Z-AC05 clínica B não grava decisão no atendimento de A (HTTP ' . $cp . ')');
    @unlink($jarB);
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nv54.47 UX excellence: " . ($n - $fail) . " PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
