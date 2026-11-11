<?php
// v54.44 (NI2-710..714): resumo operacional, rotas Plano/Documentos, Preparar, empty Manipulados, Modo Atendimento.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5444-' . getmypid() . '.sqlite';
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
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    return [$code, substr($r, $hs), substr($r, 0, $hs)];
};
try {
    [$c, $b] = $req('/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
    // NI2-710
    [$c, $b] = $req('/pacientes/2');
    $ok($c === 200 && str_contains($b, 'data-testid="operational-strip"'), 'resumo: faixa operacional presente');
    $ok(str_contains($b, 'data-testid="op-strip-allergy"') && str_contains($b, 'data-testid="op-strip-plan"'), 'resumo: alergias e plano na faixa');
    $ok(str_contains($b, 'data-testid="profile-details"'), 'resumo: progressive disclosure dos detalhes');
    $ok(str_contains($b, 'id="secao-planos"') && str_contains($b, 'id="secao-documentos"'), 'resumo: âncoras antigas de planos/documentos ainda existem');

    // NI2-711
    $ok(str_contains($b, 'href="/pacientes/2/plano" data-tab="plano"'), 'aba Plano aponta para rota própria');
    $ok(str_contains($b, 'href="/pacientes/2/documentos" data-tab="documentos"'), 'aba Documentos aponta para rota própria');
    [$c, $b] = $req('/pacientes/2/plano');
    $ok($c === 200 && str_contains($b, 'data-testid="plans-page"') && str_contains($b, 'data-tab="plano" aria-current="page"'), 'rota /plano renderiza aba Plano');
    [$c, $b] = $req('/pacientes/2/documentos');
    $ok($c === 200 && str_contains($b, 'data-testid="documents-page"') && str_contains($b, 'profile-manipulados-btn'), 'rota /documentos lista Manipulados');

    // NI2-712
    $pdo->exec("UPDATE appointments SET status='scheduled', scheduled_at=datetime('now','+3 days') WHERE id=1");
    [$c, $b] = $req('/pacientes/2');
    $ok(str_contains($b, 'href="/pacientes/2/preparar" data-testid="care-prepare"'), 'Preparar consulta aponta para /preparar');
    [$c, $b] = $req('/pacientes/2/preparar');
    $ok($c === 200 && str_contains($b, 'data-testid="prepare-panel"'), 'painel Preparar consulta abre');
    $ok(str_contains($b, 'data-testid="prep-allergy"') && str_contains($b, 'data-testid="prep-plan"') && str_contains($b, 'data-testid="prep-anthro"'), 'painel traz alergias, plano e antropometria');
    $ok(str_contains($b, 'data-testid="prep-open-anamnesis"') && str_contains($b, 'href="/pacientes/2/anamnese"'), 'Abrir anamnese é ação separada');
    $ok(str_contains($b, 'data-testid="prep-start-now"') || str_contains($b, 'data-testid="prep-start-return"') || str_contains($b, 'data-testid="prep-continue"'), 'CTA de iniciar/continuar retorno presente no painel');
    $ok(!str_contains($b, 'name="allergy_assessment"'), 'painel Preparar não é o formulário de anamnese');

    // NI2-713
    [$c, $b] = $req('/pacientes/2/prescricoes');
    $ok($c === 200 && str_contains($b, 'data-testid="rx-empty-catalog"'), 'catálogo vazio: empty state acionável');
    $ok(str_contains($b, 'data-testid="rx-back-docs"') && str_contains($b, '/pacientes/2/documentos'), 'volta para Documentos (não só prontuário genérico)');
    $ok(str_contains($b, 'Próximo passo') && !str_contains($b, 'ativos classificados'), 'mensagem orienta próximo passo sem jargão só de backend');

    // NI2-714
    $pdo->exec("UPDATE appointments SET status='in_progress', scheduled_at=datetime('now','localtime') WHERE id=1");
    [$c, $b] = $req('/pacientes/2/anamnese');
    $ok($c === 200 && str_contains($b, 'data-testid="attendance-mode"'), 'anamnese com consulta em andamento: Modo Atendimento visível');
    $ok(str_contains($b, 'data-testid="attendance-hub"') && str_contains($b, 'href="/consulta/1"'), 'barra tem Hub da consulta');
    [$c, $b] = $req('/pacientes/2/antropometria');
    $ok(str_contains($b, 'data-testid="attendance-mode"'), 'avaliações mantêm Modo Atendimento');
    [$c, $b] = $req('/pacientes/2/plano');
    $ok(str_contains($b, 'data-testid="attendance-mode"'), 'plano mantém Modo Atendimento');
    [$c, $b] = $req('/consulta/1');
    $ok($c === 200 && str_contains($b, '/pacientes/2/anamnese?atendimento=1') && !str_contains($b, 'Abrir anamnese ↗'), 'hub da consulta abre anamnese na mesma janela com contexto');
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nv54.44 workspace + preparar + modo: " . ($n - $fail) . " PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
