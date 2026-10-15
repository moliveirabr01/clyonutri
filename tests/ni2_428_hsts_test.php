<?php

declare(strict_types=1);

/** NI2-428: HSTS gradual, um único cabeçalho, só por HTTPS, sem includeSubDomains/preload por padrão. */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/ni2_426_server.php';

$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }
$https = ['X-Forwarded-Proto: https'];
$base = ['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com', 'TRUSTED_PROXIES' => '127.0.0.1'];
$port = 18460;
foreach ([['', 'max-age=300'], ['300', 'max-age=300'], ['86400', 'max-age=86400'], ['31536000', 'max-age=31536000'], ['0', null]] as [$age, $exp]) {
    $s = ni2426_server(array_merge($base, ['HSTS_MAX_AGE' => $age]), $port++);
    foreach ([['GET', '/'], ['HEAD', '/'], ['GET', '/login'], ['HEAD', '/login'], ['GET', '/dashboard'], ['GET', '/admin'], ['GET', '/api/v1/patient/home'], ['GET', '/nao-existe']] as [$m, $p]) {
        [$st, $h] = ni2426_req($m, $s['base'] . $p, $https);
        $v = $h['strict-transport-security'] ?? [];
        $ok = $exp === null ? count($v) === 0 : (count($v) === 1 && $v[0] === $exp);
        check($ok, "HSTS_MAX_AGE='" . $age . "' $m $p ($st) -> " . ($v ? implode(' | ', $v) : 'ausente'));
    }
    [$st, $h] = ni2426_req('GET', $s['base'] . '/login');
    check(!isset($h['strict-transport-security']), "HSTS_MAX_AGE='$age': resposta por HTTP ($st) sem HSTS");
    $s['stop']();
}
$s = ni2426_server(array_merge($base, ['HSTS_MAX_AGE' => '31536000', 'HSTS_PRELOAD' => 'true']), $port++);
[, $h] = ni2426_req('GET', $s['base'] . '/login', $https);
check(($h['strict-transport-security'][0] ?? '') === 'max-age=31536000', 'preload sem includeSubDomains é ignorado (staging protegido)');
$s['stop']();
$s = ni2426_server(array_merge($base, ['HSTS_SEND_ZERO' => 'true']), $port++);
[, $h] = ni2426_req('GET', $s['base'] . '/login', $https);
check(($h['strict-transport-security'][0] ?? '') === 'max-age=0', 'Rollback HSTS_SEND_ZERO=true envia max-age=0');
$s['stop']();
echo "\nResultado: {$passed} passaram, {$failed} falharam\n";
exit($failed === 0 ? 0 : 1);
