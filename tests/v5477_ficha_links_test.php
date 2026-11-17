<?php
// v54.77 — decisão do Maicon (17/11/2026): a meta da NI2-887 "até 15 links visíveis" na ficha CONTA as 7 abas.
// Servidor PHP real + sessão real (sem mocks). Conta os links visíveis dentro de <main> na carga (ignora <details> fechado,
// [hidden], aria-hidden e display:none inline) em 3 situações: sem consulta, consulta em andamento e com 12 evoluções
// (histórico > 5). Também confere que nada ficou inalcançável: histórico completo com links, etapas recolhidas no Resumo,
// "Mais ações" e as abas. A medição em navegador (Chromium/Firefox, 1366×768, 4 situações) fica em evidencias/browser_nav_v5477.json.
$root = dirname(__DIR__); $fail = 0; $n = 0; $LIMIT = 15;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5477links-' . getmypid() . '.sqlite';
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
/** @return list<string> "texto -> href" dos links visíveis na carga dentro de <main> */
$visibleLinks = function (string $html): array {
    $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors();
    $main = $d->getElementsByTagName('main')->item(0) ?? $d->documentElement;
    $out = [];
    foreach ($main->getElementsByTagName('a') as $a) {
        if (!$a->hasAttribute('href')) continue;
        $hidden = false;
        for ($p = $a; $p instanceof DOMElement; $p = $p->parentNode) {
            if ($p->hasAttribute('hidden') || $p->getAttribute('aria-hidden') === 'true' || preg_match('/display\s*:\s*none/i', $p->getAttribute('style'))) { $hidden = true; break; }
            if ($p->tagName === 'details' && !$p->hasAttribute('open')) {
                $inSummary = false;
                for ($q = $a; $q && $q !== $p; $q = $q->parentNode) { if ($q instanceof DOMElement && $q->tagName === 'summary') { $inSummary = true; break; } }
                if (!$inSummary) { $hidden = true; break; }
            }
        }
        if (!$hidden) $out[] = trim(preg_replace('/\s+/u', ' ', $a->textContent)) . ' -> ' . $a->getAttribute('href');
    }
    return $out;
};
$tabs = static fn(array $l): int => count(array_filter($l, static fn($x) => preg_match('#-> /pacientes/\d+(/(prontuario|plano|anamnese|antropometria|exames|documentos))?$#', $x) && preg_match('/^(Resumo|Atendimento|Plano|Anamnese|Avaliações|Exames|Documentos)/u', $x)));
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/pacientes/2');
    [$c, $b] = $req('/pacientes/2');
    $l = $visibleLinks($b);
    $ok($c === 200 && $tabs($l) === 7 && count($l) <= $LIMIT, 'ficha (situação do seed): ' . count($l) . " links visíveis com as 7 abas (≤ $LIMIT): " . implode(' | ', $l));

    // 12 evoluções pelo app: histórico curto continua com 5 itens, agora em texto; o completo tem os links.
    [, $pr] = $req('/pacientes/2/prontuario');
    preg_match('/name="_csrf_token" value="([^"]+)"/', $pr, $t);
    for ($i = 1; $i <= 12; $i++) {
        $req('/pacientes/2/prontuario', ['_csrf_token' => $t[1] ?? '', 'title' => "Evolução TESTE v5477 #$i", 'category' => 'evolucao', 'content_text' => "Retorno $i."]);
    }
    [, $b] = $req('/pacientes/2');
    $l = $visibleLinks($b);
    $ok(count($l) <= $LIMIT && $tabs($l) === 7, 'com 12 evoluções: ' . count($l) . " links visíveis (≤ $LIMIT)");
    preg_match('/data-testid="profile-timeline">(.*?)<\/ol>/s', $b, $tl);
    $ok(substr_count($tl[1] ?? '', '<li>') === 5 && !str_contains($tl[1] ?? '', '<a '), 'Resumo: 5 registros em texto (sem link por item)');
    $ok(str_contains($b, 'data-testid="timeline-ver-tudo"'), 'Resumo: "Ver histórico completo" presente');
    $ok(!preg_match('/data-testid="op-strip-evolution"><a /', $b) && str_contains($b, 'Evolução TESTE v5477 #12'), 'Última evolução em texto (a aba Atendimento abre o prontuário)');
    $ok(!str_contains($b, '>Revisar alergia<'), 'sem atalho "Revisar alergia" (o bloco da alergia já fica na tela com a ação dele)');
    [$c, $full] = $req('/pacientes/2?historico=completo');
    preg_match('/data-testid="profile-timeline">(.*?)<\/ol>/s', $full, $tf);
    $ok($c === 200 && substr_count($tf[1] ?? '', '<a href=') === 10, 'histórico completo: 10 registros com link (nenhum registro inalcançável)');
    $ok(str_contains($b, '/chat?patient_id=2') && str_contains($b, '/pacientes/2/editar') && str_contains($b, '/materiais?para=2'), '"Mais ações" preservado');

    // Consulta em andamento: etapas recolhidas no Resumo, abertas nas outras abas.
    [, $pg] = $req('/pacientes/2');
    preg_match('/name="_csrf_token" value="([^"]+)"/', $pg, $t2);
    $req('/pacientes/2/iniciar-agora', ['_csrf_token' => $t2[1] ?? '']);
    [$c, $b] = $req('/pacientes/2');
    $l = $visibleLinks($b);
    $ok(str_contains($b, 'data-testid="care-continue"') && str_contains($b, 'data-testid="attendance-steps-toggle"'), 'em andamento: "Continuar atendimento" + etapas em "Etapas deste atendimento" (recolhido)');
    $ok(count($l) <= $LIMIT && $tabs($l) === 7, 'em andamento: ' . count($l) . " links visíveis (≤ $LIMIT): " . implode(' | ', $l));
    [$c, $an] = $req('/pacientes/2/anamnese');
    $ok($c === 200 && str_contains($an, 'data-testid="attendance-steps"') && !str_contains($an, 'data-testid="attendance-steps-toggle"'), 'aba Anamnese: etapas continuam abertas como antes');
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nv54.77 ficha ≤15 links com as abas: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
