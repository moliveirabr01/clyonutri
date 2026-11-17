<?php

declare(strict_types=1);

/** NI2-428: HSTS gradual, um único cabeçalho, só por HTTPS, sem includeSubDomains/preload por padrão. NI2-885: padrão de produção 15552000 (6 meses). */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/ni2_426_server.php';

$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }
$https = ['X-Forwarded-Proto: https'];
$base = ['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com', 'TRUSTED_PROXIES' => '127.0.0.1'];
$port = 18460;
// HSTS_MAX_AGE=0 cria storage/hsts_rollback (NI2-811, de propósito só sai por restauração explícita).
// O teste devolve o estado da pasta como encontrou, para não contaminar as execuções seguintes.
$flag = dirname(__DIR__) . '/storage/hsts_rollback';
$flagBefore = is_file($flag);
register_shutdown_function(static function () use ($flag, $flagBefore): void { if (!$flagBefore && is_file($flag)) { @unlink($flag); } });
check(!$flagBefore, 'pré-condição: sem storage/hsts_rollback ativo antes do teste');
foreach ([['', 'max-age=15552000'], ['300', 'max-age=300'], ['86400', 'max-age=86400'], ['31536000', 'max-age=31536000'], ['0', 'max-age=0']] as [$age, $exp]) {
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
    if ($age === '0') {
        check(is_file($flag), "HSTS_MAX_AGE='0' grava storage/hsts_rollback (Apache também passa a mandar max-age=0)");
        if (!$flagBefore) { @unlink($flag); } // restauração explícita (equivale a php bin/hsts.php --restore)
    }
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
