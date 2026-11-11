<?php
// v54.35: CSRF do Financeiro. Sobe php -S com banco SQLite TESTE, abre /financeiro, posta lançamento.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
// estático: nenhuma chave de sessão paralela para CSRF em src/
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) if (str_ends_with($f->getFilename(), '.php') && preg_match('/\$_SESSION\[\s*[\'"]csrf_token[\'"]\s*\]/', file_get_contents($f->getPathname()))) $hits[] = $f->getPathname();
$ok($hits === [], 'src/ sem $_SESSION[\'csrf_token\'] ' . implode(',', $hits));
$db = sys_get_temp_dir() . '/v5435-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
$req = function (string $path, ?array $post = null) use ($port, $jar) {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: text/html']]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $loc = (string)curl_getinfo($c, CURLINFO_REDIRECT_URL); curl_close($c);
    return [$code, $b, $loc];
};
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/financeiro');
    [$code, $html] = $req('/financeiro');
    $ok($code === 200, "GET /financeiro 200 ($code)");
    preg_match('#action="/financeiro/recebiveis/novo">\s*<input type="hidden" name="_csrf_token" value="([a-f0-9]{64})"#', $html, $m);
    $tok = $m[1] ?? '';
    $ok($tok !== '', 'token no formulário de lançamento');
    $form = ['patient_id' => 2, 'description' => 'TESTE CSRF v54.35', 'amount' => '0,01', 'due_date' => date('Y-m-d')];
    [$code, $b] = $req('/financeiro/recebiveis/novo', $form + ['_csrf_token' => str_repeat('0', 64)]);
    $ok($code === 403, "token errado bloqueado com 403 ($code)");
    [$code, $b, $loc] = $req('/financeiro/recebiveis/novo', $form + ['_csrf_token' => $tok]);
    $ok($code === 302 && str_ends_with($loc, '/financeiro'), "token da página aceito, volta ao Financeiro ($code $loc)");
    $ok(!str_contains($b, 'Sua sessão expirou'), 'sem tela de sessão expirada');
    $pdo = new PDO('sqlite:' . $db);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM receivables WHERE description = 'TESTE CSRF v54.35'")->fetchColumn() === 1, 'lançamento TESTE gravado uma vez');
} finally {
    proc_terminate($proc); proc_close($proc); foreach (glob($db . '*') as $f) @unlink($f); @unlink($jar);
}
echo "\n" . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
