<?php
declare(strict_types=1);

// v10 — achados "HTTPS não obrigatório" e "Endpoint público de diagnóstico".
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Http\ErrorExposure;

$fails = 0; $n = 0;
$ok = function (bool $c, string $m) use (&$fails, &$n): void { $n++; echo ($c ? "  OK   " : "  FALHA ") . $m . "\n"; if (!$c) $fails++; };
$setEnv = function (array $vars): void {
    foreach (['APP_ENV', 'FORCE_HTTPS', 'APP_URL', 'APP_DEBUG'] as $k) { putenv($k); unset($_ENV[$k], $_SERVER[$k]); }
    foreach ($vars as $k => $v) { putenv("{$k}={$v}"); }
};
$root = realpath(__DIR__ . '/..');

echo "[1] FORCE_HTTPS: padrão ligado em produção\n";
$matrix = [
    [['APP_ENV' => 'production'], true, 'produção sem FORCE_HTTPS -> ligado'],
    [['APP_ENV' => 'production', 'FORCE_HTTPS' => ''], true, 'produção com FORCE_HTTPS vazio -> ligado'],
    [['APP_ENV' => 'production', 'FORCE_HTTPS' => 'talvez'], true, 'produção com valor desconhecido -> ligado'],
    [['APP_ENV' => 'production', 'FORCE_HTTPS' => 'true'], true, 'produção FORCE_HTTPS=true -> ligado'],
    [['APP_ENV' => 'production', 'FORCE_HTTPS' => 'false'], false, 'produção FORCE_HTTPS=false explícito -> desligado (com aviso)'],
    [['APP_ENV' => 'local'], false, 'local sem FORCE_HTTPS -> desligado'],
    [['APP_ENV' => 'local', 'FORCE_HTTPS' => '1'], true, 'local FORCE_HTTPS=1 -> ligado'],
];
foreach ($matrix as [$env, $exp, $msg]) { $setEnv($env); $ok(Transport::forceHttpsEnabled() === $exp, $msg); }

$setEnv(['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com']);
$d = Transport::decide(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/login?x=1', 'REMOTE_ADDR' => '203.0.113.9']);
$ok(($d['status'] ?? 0) === 301 && $d['location'] === 'https://clyonutri.com/login?x=1', 'GET http -> 301 https://clyonutri.com/login?x=1 sem FORCE_HTTPS no .env');
$d = Transport::decide(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'REMOTE_ADDR' => '203.0.113.9']);
$ok(($d['status'] ?? 0) === 403, 'POST http -> 403');
$d = Transport::decide(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/login', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']);
$ok(($d['status'] ?? 0) === 301, 'X-Forwarded-Proto forjado (sem proxy confiável) não evita o redirecionamento');
$ok(Transport::decide(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9']) === null, 'HTTPS=on segue normal');

echo "[2] APP_DEBUG=true em produção não libera saída de depuração\n";
$setEnv(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);
$ok(ErrorExposure::debugOutputAllowed(['REMOTE_ADDR' => '127.0.0.1']) === false, 'produção + APP_DEBUG=true + loopback -> sem depuração');
$ok(ErrorExposure::debugOutputAllowed(['REMOTE_ADDR' => '203.0.113.9']) === false, 'produção + APP_DEBUG=true + IP externo -> sem depuração');

echo "[3] Link de redefinição de senha sempre https em produção\n";
$src = (string)file_get_contents("{$root}/src/Core/Auth/PasswordResetService.php");
$ok(str_contains($src, "preg_replace('#^http://#i', 'https://'"), 'PasswordResetService força https:// no link em produção');

echo "[4] HTTP real (php -S): /health, /up, stack trace\n";
$port = 18000 + random_int(0, 900);
$run = function (array $env, callable $fn) use ($root, &$port) {
    $port++;
    $envStr = '';
    foreach ($env as $k => $v) $envStr .= $k . '=' . escapeshellarg($v) . ' ';
    $log = sys_get_temp_dir() . "/v10_srv_{$port}.log";
    $pid = (int)shell_exec("cd " . escapeshellarg($root) . " && env {$envStr} " . escapeshellarg(PHP_BINARY)
        . " -d display_errors=1 -d html_errors=1 -S 127.0.0.1:{$port} -t public public/index.php > {$log} 2>&1 & echo $!");
    for ($i = 0; $i < 100; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(50000); }
    try { $fn("http://127.0.0.1:{$port}"); } finally { exec("kill {$pid} 2>/dev/null"); }
};
$get = function (string $url): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    $body = @file_get_contents($url, false, $ctx);
    $h = $http_response_header ?? [];
    preg_match('#HTTP/\S+ (\d+)#', $h[0] ?? '', $m);
    return ['status' => (int)($m[1] ?? 0), 'body' => (string)$body, 'headers' => implode("\n", $h)];
};
$dbCopy = sys_get_temp_dir() . '/v10_health_' . bin2hex(random_bytes(3)) . '.sqlite';
copy("{$root}/storage/testing.sqlite", $dbCopy);
$dbEnv = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbCopy];
$run($dbEnv + ['APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'FORCE_HTTPS' => 'false', 'APP_URL' => 'https://clyonutri.com'], function ($base) use ($get, $ok) {
    $r = $get("{$base}/health");
    $ok($r['status'] === 200 && trim($r['body']) === '{"status":"ok"}', '/health -> 200 {"status":"ok"} (recebido: ' . substr($r['body'], 0, 80) . ')');
    $ok(stripos($r['headers'], 'Cache-Control: no-store') !== false, '/health com Cache-Control: no-store');
    $r = $get("{$base}/up");
    $ok(in_array($r['status'], [200, 503], true) && preg_match('/^\{"status":"(ok|unavailable)"\}$/', trim($r['body'])) === 1, '/up mínimo (recebido: ' . substr($r['body'], 0, 80) . ')');
    foreach (['version', 'timestamp', 'service', 'database', 'scheduler'] as $w) {
        $ok(stripos($r['body'], $w) === false, "/up não contém '{$w}'");
    }
    $ok(stripos($r['headers'], 'X-Powered-By') === false, 'sem X-Powered-By');
});
// Banco inacessível -> exceção não tratada no front controller
$run(['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'nao_existe', 'DB_USERNAME' => 'x', 'DB_PASSWORD' => 'segredo-de-teste',
      'APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'FORCE_HTTPS' => 'false', 'APP_URL' => 'https://clyonutri.com'], function ($base) use ($get, $ok) {
    $r = $get("{$base}/login");
    $leak = preg_match('/Stack trace|#0 |\.php|Fatal error|Uncaught|PDOException|SQLSTATE|segredo-de-teste|\/workspace|\/home/i', $r['body']);
    $ok($r['status'] === 500, 'erro interno em produção -> 500 (recebido ' . $r['status'] . ')');
    $ok($leak === 0, 'produção + APP_DEBUG=true + display_errors=1: corpo sem stack trace, caminho, SQL ou segredo');
    $r = $get("{$base}/api/v1/patient/home");
    $ok(preg_match('/Stack trace|\.php|SQLSTATE|segredo-de-teste/i', $r['body']) === 0, 'API em erro também sem stack trace');
});
// Sem FORCE_HTTPS no .env: produção redireciona
$run($dbEnv + ['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com'], function ($base) use ($get, $ok) {
    $r = $get("{$base}/login");
    $ok($r['status'] === 301 && stripos($r['headers'], 'Location: https://clyonutri.com/login') !== false, 'produção sem FORCE_HTTPS: GET http /login -> 301 https://clyonutri.com/login');
});

echo "[5] release_check reprova /health detalhado, rota de diagnóstico e .htaccess sem HTTPS\n";
$check = function (string $dir) use ($root): int { exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/bin/release_check.php") . ' ' . escapeshellarg($dir) . ' 2>&1', $o, $rc); return $rc; };
$tmp = sys_get_temp_dir() . '/v10_rel_' . bin2hex(random_bytes(4));
mkdir("{$tmp}/public", 0777, true);
$idx = (string)file_get_contents("{$root}/public/index.php");
$reset = function () use ($tmp, $root, $idx) {
    file_put_contents("{$tmp}/public/index.php", $idx);
    copy("{$root}/public/.htaccess", "{$tmp}/public/.htaccess");
    copy("{$root}/.htaccess", "{$tmp}/.htaccess");
};
$reset(); $ok($check($tmp) === 0, 'árvore atual aprovada');
file_put_contents("{$tmp}/public/index.php", str_replace("echo json_encode(['status' => 'ok']);", "echo json_encode(['status' => 'ok', 'version' => '1.0.0']);", $idx));
$ok($check($tmp) === 1, 'reprova /health com version'); $reset();
file_put_contents("{$tmp}/public/index.php", $idx . "\n\$router->get('/debug/env', fn() => 1);\n");
$ok($check($tmp) === 1, 'reprova rota /debug/env'); $reset();
file_put_contents("{$tmp}/public/index.php", str_replace('ErrorExposure::configure()', 'X()', $idx));
$ok($check($tmp) === 1, 'reprova front controller sem ErrorExposure'); $reset();
file_put_contents("{$tmp}/.htaccess", "RewriteEngine On\nRewriteRule ^(.*)$ public/$1 [L]\n");
$ok($check($tmp) === 1, 'reprova .htaccess da raiz sem HTTPS'); $reset();
file_put_contents("{$tmp}/phpinfo.php", "<?php phpinfo();");
$ok($check($tmp) === 1, 'reprova PHP solto na raiz'); unlink("{$tmp}/phpinfo.php");
foreach (['public/dump.sql', 'public/backup.zip', 'public/banco.sqlite', 'public/app.log', 'public/error_log'] as $rel) {
    file_put_contents("{$tmp}/{$rel}", 'x'); $ok($check($tmp) === 1, "reprova {$rel}"); unlink("{$tmp}/{$rel}");
}
exec('rm -rf ' . escapeshellarg($tmp));
@unlink($dbCopy);

echo "\n{$n} verificações, {$fails} falha(s)\n";
exit($fails ? 1 : 0);
