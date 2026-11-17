<?php
// NI2-887 (fatia 1: ficha): resumo curto, 3 ações, timeline com 5 itens + "ver tudo", detalhes fechados
// e nenhuma rota/ação perdida. Servidor PHP real + sessão real (sem mocks).
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/ni2887-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
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
$section = function (string $html, string $testid): string {
    $p = strpos($html, 'data-testid="' . $testid . '"'); if ($p === false) return '';
    $end = strpos($html, '</section>', $p); return substr($html, $p, $end === false ? 4000 : $end - $p);
};
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
    [$c, $b] = $req('/pacientes/2');
    $ok($c === 200, 'ficha abre (200)');
    $ok(preg_match('/<details class="profile-details"[^>]*>/', $b, $m) === 1 && !str_contains($m[0], ' open'), 'detalhes fechados na carga');
    $ok(str_contains($b, '/assets/js/details-reveal.js'), 'âncoras #secao-* abrem os detalhes (details-reveal.js)');
    foreach (['secao-dados', 'secao-prontuario', 'secao-anamnese', 'secao-planos', 'secao-documentos', 'modal-new-plan-p360'] as $id) {
        $ok(str_contains($b, 'id="' . $id . '"'), "conteúdo preservado: #$id");
    }
    $strip = $section($b, 'operational-strip');
    $ok(str_contains($strip, 'data-testid="op-strip-evolution"'), 'resumo mostra última evolução');
    $ok(str_contains($strip, 'data-testid="acao-plano"') && str_contains($strip, 'data-testid="acao-mensagem"'), 'ações: plano + mensagem');
    $ok(substr_count($strip, 'class="btn btn-secondary btn-sm"') <= 3, 'no máximo 3 ações no resumo');
    $ok(str_contains($b, 'data-testid="care-start-now"') || str_contains($b, 'data-testid="care-start"') || str_contains($b, 'data-testid="care-prepare"') || str_contains($b, 'data-testid="care-continue"') || str_contains($b, 'Agendar') , 'cabeçalho mantém a ação de consulta');
    $ok(str_contains($b, '/chat?patient_id=2') && str_contains($b, '/pacientes/2/editar') && str_contains($b, '/materiais?para=2'), 'Mais ações preservado');

    // Prontuário: grava 12 evoluções pelo app para a timeline passar de 5.
    [, $pr] = $req('/pacientes/2/prontuario');
    preg_match('/name="_csrf_token" value="([^"]+)"/', $pr, $t);
    for ($i = 1; $i <= 12; $i++) {
        $req('/pacientes/2/prontuario', ['_csrf_token' => $t[1] ?? '', 'title' => "Evolução TESTE 887 #$i", 'category' => 'evolucao', 'content_text' => "Retorno $i: adesão boa, sem queixas gastrointestinais."]);
    }
    [, $b] = $req('/pacientes/2');
    $tl = $section($b, 'longitudinal-summary');
    $items = substr_count($tl, '<li>');
    $ok($items === 5, "timeline mostra 5 itens na carga (viu $items)");
    $ok(str_contains($tl, 'data-testid="timeline-ver-tudo"'), 'timeline tem "ver histórico completo"');
    $ok(!str_contains($tl, 'name="timeline_type"'), 'filtros não aparecem no resumo curto');
    $ok(preg_match('/>\d+ registros no resumo\./', $tl) === 1, 'contagem do resumo com número e plural ("N registros no resumo")');
    $strip = $section($b, 'operational-strip');
    $ok(str_contains($strip, 'Evolução TESTE 887 #12'), 'última evolução = a mais recente gravada');

    [$c, $full] = $req('/pacientes/2?historico=completo');
    $tl = $section($full, 'longitudinal-summary');
    $ok($c === 200 && substr_count($tl, '<li>') === 10 && str_contains($tl, 'name="timeline_type"'), 'histórico completo: 10 por página + filtros');
    preg_match('/Página 1 de (\d+)/', $tl, $pg);
    $ok(((int)($pg[1] ?? 1) >= 2) && (str_contains($tl, 'historico=completo') && str_contains($tl, 'timeline_page=2')), 'paginação mantém o modo completo');
    [$c, $p2] = $req('/pacientes/2?historico=completo&timeline_page=2');
    $ok($c === 200 && str_contains($section($p2, 'longitudinal-summary'), 'Página '), 'página 2 do histórico abre (ou ajusta para a última)');
    [$c, $f] = $req('/pacientes/2?historico=completo&timeline_type=' . rawurlencode('Prontuário'));
    $tl = $section($f, 'longitudinal-summary');
    $ok($c === 200 && str_contains($tl, 'Evolução TESTE 887') && !str_contains($tl, '>Consulta</span>'), 'filtro por tipo continua funcionando');
    [$c, $d] = $req('/pacientes/2?detalhes=1');
    $ok(preg_match('/<details class="profile-details"[^>]* open>/', $d) === 1, '?detalhes abre os detalhes');
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nNI2-887 ficha: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
