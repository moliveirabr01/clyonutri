<?php
// NI2-892 fatia 1 (v54.76): checagem de paciente do TenantGuard memorizada só em GET da web (positivo, por conexão+clínica+paciente),
// isolamento entre clínicas intacto, e nenhum catch vazio em PHP (registrar no log ou justificar).
// Servidor PHP real (php -S com DB_QUERY_PROFILE=1) + SQLite temporário (seed "tenant": clínicas A e B), sem mocks.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/ni2892-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' tenant 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE (clínicas A e B)');
require_once $root . '/vendor/autoload.php';
$pdo = new PDO('sqlite:' . $db); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pa = (int)$pdo->query("SELECT id FROM patients WHERE clinic_id = 1 ORDER BY id LIMIT 1")->fetchColumn();
$pb = (int)$pdo->query("SELECT id FROM patients WHERE clinic_id = 2 ORDER BY id LIMIT 1")->fetchColumn();
$ok($pa > 0 && $pb > 0, "paciente A ($pa) e paciente B ($pb)");

// 1) CLI: sem memória. Paciente que muda de clínica no meio do processo é recusado na hora.
\ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
$g = fn() => \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($pa, 1, $pdo);
$first = $g();
$pdo->exec("UPDATE patients SET clinic_id = 2 WHERE id = $pa");
$denied = false; try { $g(); } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) { $denied = true; }
$pdo->exec("UPDATE patients SET clinic_id = 1 WHERE id = $pa");
$ok((int)$first['id'] === $pa && $denied, 'CLI: checagem consulta o banco a cada chamada (troca de clínica recusada na hora)');
$deniedB = false; try { \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($pb, 1, $pdo); } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) { $deniedB = true; }
$ok($deniedB, 'clínica A não acessa paciente da clínica B');

// 2) Web real: a ficha faz 1 checagem do paciente (antes 18) e a clínica B continua isolada.
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$log = sys_get_temp_dir() . '/ni2892-srv-' . getmypid() . '.log';
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local DB_QUERY_PROFILE=1 APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
// recusa = 403/404 ou redirecionamento para fora da ficha (o app manda para a lista com aviso)
$denied = fn(int $code, string $loc, int $pid) => in_array($code, [403, 404], true) || ($code === 302 && !preg_match('#/pacientes/' . $pid . '(\D|$)#', $loc));
$req = function (string $path, ?array $post = null) use ($port, $jar) {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $loc = (string)curl_getinfo($c, CURLINFO_REDIRECT_URL); curl_close($c); return [$code, $r, $loc];
};
$metricsAfter = function (callable $fn) use ($log) {
    clearstatcache(); $pos = filesize($log); $fn(); usleep(300000); clearstatcache();
    preg_match_all('/\[query-metrics\] (\{.*\})/', (string)file_get_contents($log, false, null, $pos), $m);
    return $m[1] ? json_decode(end($m[1]), true) : null;
};
$guardSql = 'SELECT id, clinic_id, name, email FROM patients WHERE id = :id AND clinic_id = :clinic_id LIMIT 1';
$fp = hash('sha256', $guardSql);
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/dashboard');
    $code = 0; $m = $metricsAfter(function () use ($req, $pa, &$code) { [$code] = $req("/pacientes/$pa"); });
    $ok($code === 200 && $m !== null && ($m['fingerprints'][$fp] ?? 0) === 1, 'ficha (GET real): checagem do paciente roda 1 vez por requisição (' . ($m['fingerprints'][$fp] ?? '?') . '); total ' . ($m['queries'] ?? '?') . ' consultas');
    $m2 = $metricsAfter(function () use ($req, $pa) { $req("/pacientes/$pa"); });
    $ok(($m2['fingerprints'][$fp] ?? 0) === 1, 'nova requisição começa sem memória (checagem roda de novo)');
    [$cb, $bb, $lb] = $req("/pacientes/$pb");
    $ok($denied($cb, $lb, $pb) && !str_contains($bb, 'Paciente TESTE Clinica B'), "clínica A pede a ficha do paciente da clínica B: $cb $lb, sem o nome");
    $pdo->exec("UPDATE patients SET clinic_id = 2 WHERE id = $pa");
    [$cm, , $lm] = $req("/pacientes/$pa");
    $pdo->exec("UPDATE patients SET clinic_id = 1 WHERE id = $pa");
    $ok($denied($cm, $lm, $pa), "paciente movido para a clínica B: a próxima requisição da clínica A é recusada ($cm $lm)");
    $req('/__teste_login?u=2&c=2&v=0&to=/dashboard');
    [$cx, $bx, $lx] = $req("/pacientes/$pa");
    $ok($denied($cx, $lx, $pa), "clínica B pede a ficha do paciente da clínica A: $cx $lx");
    [$cy] = $req("/pacientes/$pb");
    $ok($cy === 200, "clínica B abre a própria paciente: $cy");
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar); @unlink($log);
}

// 3) Nenhum catch vazio em PHP (NI2-892 etapa 5): ou registra no log ou justifica em comentário.
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = substr($f->getPathname(), strlen($root) + 1);
    if (!str_ends_with($p, '.php') || !preg_match('#^(src|public|views|bin|routes|config)/#', $p)) continue;
    foreach (token_get_all((string)file_get_contents($f->getPathname())) as $i => $t) {} // garante PHP válido
    if (preg_match_all('/catch\s*\(\s*\\\\?[A-Z][^)]*\)\s*\{\s*\}/', (string)file_get_contents($f->getPathname()), $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $x) $found[] = $p . ':' . (substr_count(substr((string)file_get_contents($f->getPathname()), 0, $x[1]), "\n") + 1);
    }
}
$ok($found === [], 'nenhum catch vazio em PHP (src, public, views, bin, routes, config)' . ($found ? ': ' . implode(', ', array_slice($found, 0, 5)) : ''));
echo "\nNI2-892 fatia 1: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
