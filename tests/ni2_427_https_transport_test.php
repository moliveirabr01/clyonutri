<?php

declare(strict_types=1);

/**
 * NI2-427 (F01): HTTPS obrigatório. Sobe o front controller real (php -S, HTTP puro) com
 * APP_ENV=production e confere: GET/HEAD -> 301 para APP_URL com caminho e query; POST -> 403
 * sem chegar ao controller (sem Set-Cookie, sem HTML de login); Host forjado não vira Location;
 * X-Forwarded-Proto só vale com proxy confiável; HEAD não cai mais em 404.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/ni2_426_server.php';
require_once __DIR__ . '/support/ni2_898_server.php';

use ClyoNutri\Core\Http\Transport;

$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }

echo "[unidade] Transport\n";
check(Transport::safeRequestTarget('//evil.example/x?a=1') === '/evil.example/x?a=1', 'Caminho "//host" não vira URL de outro host');
check(Transport::safeRequestTarget("/login\r\nX: y") === '/loginX:y', 'CR/LF e espaços removidos do Location');
check(Transport::ipMatches('10.1.2.3', '10.0.0.0/8') && !Transport::ipMatches('11.1.2.3', '10.0.0.0/8'), 'CIDR IPv4');
check(Transport::ipMatches('::1', '::1/128'), 'CIDR IPv6');

$prod = ['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com', 'FORCE_HTTPS' => '', 'TRUSTED_PROXIES' => '', 'APP_DEBUG' => 'false'];
$s = ni2898_server($prod);
$b = $s['base'];
echo "[HTTP puro, produção]\n";
foreach (['/', '/login', '/login?teste=https', '/api/v1/patient/home', '/assets/css/clinical-botanical.css'] as $p) {
    foreach (['GET', 'HEAD'] as $m) {
        [$st, $h, $body] = ni2426_req($m, $b . $p);
        check($st === 301 && ($h['location'][0] ?? '') === 'https://clyonutri.com' . $p, "$m $p -> 301 https://clyonutri.com$p (obtido $st " . ($h['location'][0] ?? '-') . ')');
        check(!isset($h['set-cookie']) && !str_contains($body, '<form'), "$m $p sem sessão nem HTML por HTTP");
    }
}
[$st, $h] = ni2426_req('GET', $b . '/login', ['Host: evil.example']);
check(($h['location'][0] ?? '') === 'https://clyonutri.com/login', 'Host forjado ignorado: Location usa APP_URL');
[$st, $h] = ni2426_req('GET', $b . '/login', ['X-Forwarded-Proto: https']);
check($st === 301, 'X-Forwarded-Proto: https de cliente não confiável NÃO evita o redirect (sem downgrade)');
[$st, $h, $body] = ni2426_req('POST', $b . '/login', ['Content-Type: application/x-www-form-urlencoded'], 'email=ficticio%40example.invalid&password=ficticia&_csrf_token=x');
check($st === 403 && !isset($h['location']) && !isset($h['set-cookie']) && str_contains($body, 'HTTPS') && !str_contains($body, 'CSRF'), 'POST /login por HTTP -> 403 antes do CSRF/controller, sem sessão');
[$st] = ni2426_req('POST', $b . '/api/v1/auth/professional-login', ['Content-Type: application/json'], '{"email":"x@example.invalid","password":"y"}');
check($st === 403, 'POST na API por HTTP -> 403 sem processar');
$s['stop']();

echo "[proxy confiável]\n";
$s = ni2898_server(array_merge($prod, ['TRUSTED_PROXIES' => '127.0.0.1']));
[$st, $h, $body] = ni2426_req('GET', $s['base'] . '/login', ['X-Forwarded-Proto: https']);
check($st === 200 && str_contains($body, '<form'), 'Com TRUSTED_PROXIES=127.0.0.1 e X-Forwarded-Proto https -> 200 (sem loop; obtido ' . $st . ', Location=' . ($h['location'][0] ?? '-') . ', form=' . (str_contains($body, '<form') ? 'sim' : 'não') . ')');
check(str_contains(implode(';', $h['set-cookie'] ?? []), 'secure'), 'Cookie de sessão com Secure via proxy confiável');
[$st] = ni2426_req('GET', $s['base'] . '/login');
check($st === 301, 'Mesmo proxy sem X-Forwarded-Proto https -> 301 (obtido ' . $st . ')');
[$st, $h] = ni2426_req('GET', $s['base'] . '/login', ['X-Forwarded-Proto: http']);
check($st === 301 && ($h['location'][0] ?? '') === 'https://clyonutri.com/login', 'Proxy confiável declarando http -> 301 canônico (obtido ' . $st . ')');
[$st, $h] = ni2426_req('GET', $s['base'] . '/login', ['X-Forwarded-Proto: https']);
check(str_contains(implode(';', $h['strict-transport-security'] ?? []), 'max-age=15552000'), 'HTTPS reconhecido pelo proxy também recebe HSTS de seis meses');
[$st, $h] = ni2426_req('HEAD', $s['base'] . '/login', ['X-Forwarded-Proto: https']);
check($st === 200, 'HEAD /login -> 200 (antes 404)');
[$st] = ni2426_req('HEAD', $s['base'] . '/rota-que-nao-existe', ['X-Forwarded-Proto: https']);
check($st === 404, 'HEAD em rota inexistente continua 404');
$s['stop']();

echo "[lista de proxies]\n";
$s = ni2898_server(array_merge($prod, ['TRUSTED_PROXIES' => '127.0.0.0/8']));
[$st, $h] = ni2426_req('GET', $s['base'] . '/login', ['X-Forwarded-Proto: https']);
check($st === 200 && !isset($h['location']), 'CIDR confiável reconhece HTTPS sem redirect (obtido ' . $st . ')');
$s['stop']();
$s = ni2898_server(array_merge($prod, ['TRUSTED_PROXIES' => '203.0.113.10']));
[$st, $h] = ni2426_req('GET', $s['base'] . '/login', ['X-Forwarded-Proto: https']);
check($st === 301 && !isset($h['set-cookie']), 'Cliente fora da lista não pode forjar HTTPS nem abrir sessão (obtido ' . $st . ')');
$s['stop']();

echo "[desligado]\n";
$s = ni2898_server(array_merge($prod, ['FORCE_HTTPS' => 'false']));
[$st] = ni2426_req('GET', $s['base'] . '/login');
check($st === 200, 'FORCE_HTTPS=false (rollback) -> HTTP volta a responder 200');
$s['stop']();
$s = ni2898_server(['APP_ENV' => 'local', 'APP_URL' => 'http://localhost']);
[$st] = ni2426_req('GET', $s['base'] . '/login');
check($st === 200, 'APP_ENV=local sem FORCE_HTTPS -> padrão desligado');
$s['stop']();
$s = ni2898_server(['APP_ENV' => 'production', 'APP_URL' => 'http://clyonutri.com']);
[$st, $h] = ni2426_req('GET', $s['base'] . '/login');
check($st === 403 && !isset($h['location']), 'APP_URL sem https em produção -> 403 (não monta Location inseguro)');
$s['stop']();

echo "\nResultado: {$passed} passaram, {$failed} falharam\n";
exit($failed === 0 ? 0 : 1);
