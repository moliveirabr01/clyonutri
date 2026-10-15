<?php

declare(strict_types=1);

/**
 * NI2-429: CSP efetiva com nonce por resposta, anti-enquadramento, nosniff, Referrer/Permissions-Policy
 * nas páginas, na API (JSON intacto + CORS) e em erro/redirect; nenhum atributo de evento inline ou
 * javascript: no código das telas; todo <script> inline tem nonce.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/ni2_426_server.php';

$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }
$root = realpath(__DIR__ . '/..');

echo "[código-fonte]\n";
$files = array_merge(glob("$root/views/*/*.php"), glob("$root/src/Application/Controllers/*.php"));
$handlers = 0; $jsurl = 0; $noNonce = 0;
foreach ($files as $f) {
    $s = file_get_contents($f);
    $handlers += preg_match_all('/\son[a-z]+\s*=\s*["\']/i', $s);
    $jsurl += preg_match_all('/javascript:/i', $s);
    $noNonce += preg_match_all('/<script(?![^>]*(\bsrc=|\bnonce=|type="application\/json"))[^>]*>/i', $s);
}
check($handlers === 0, "Nenhum atributo on*= nas telas ($handlers)");
check($jsurl === 0, "Nenhum link javascript: ($jsurl)");
check($noNonce === 0, "Todo <script> inline tem nonce ($noNonce sem)");

$s = ni2426_server(['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com', 'TRUSTED_PROXIES' => '127.0.0.1'], 18480);
$https = ['X-Forwarded-Proto: https'];
echo "[HTTP: páginas]\n";
$nonces = [];
foreach (['/', '/login', '/recuperar-senha', '/cadastro', '/nao-existe', '/dashboard'] as $p) {
    foreach (['GET', 'HEAD'] as $m) {
        [$st, $h, $body] = ni2426_req($m, $s['base'] . $p, $https);
        $csp = $h['content-security-policy'] ?? [];
        check(count($csp) === 1 && !isset($h['content-security-policy-report-only']), "$m $p ($st): exatamente uma CSP efetiva");
        $c = $csp[0] ?? '';
        foreach (["frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "script-src-attr 'none'"] as $d) {
            check(str_contains($c, $d) || ($st === 404 && str_contains($c, "frame-ancestors 'none'")), "$m $p: $d");
        }
        check(!preg_match("/script-src[^;]*('unsafe-inline'|'unsafe-eval'|\\*)/", $c), "$m $p: script-src sem unsafe-inline/unsafe-eval/curinga");
        check(($h['x-frame-options'] ?? []) === ['DENY'] && ($h['x-content-type-options'] ?? []) === ['nosniff'], "$m $p: X-Frame-Options DENY e nosniff únicos");
        check(isset($h['referrer-policy']) && isset($h['permissions-policy']), "$m $p: Referrer-Policy e Permissions-Policy");
        if ($m === 'GET' && preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $c, $mm)) {
            $nonces[] = $mm[1];
            preg_match_all('/<script\b(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/i', $body, $tags);
            $bad = array_filter($tags[0], fn($t) => !str_contains($t, 'nonce="' . $mm[1] . '"'));
            check(count($bad) === 0, "GET $p: todo <script> inline da resposta usa o nonce desta resposta");
            if (str_contains($body, '<html')) check(str_contains($body, '/assets/js/csp-actions.js'), "GET $p: csp-actions.js carregado");
        }
    }
}
check(count($nonces) === count(array_unique($nonces)) && count($nonces) >= 3, 'Nonce muda a cada resposta (' . count($nonces) . ' distintos)');
[$st, $h] = ni2426_req('GET', $s['base'] . '/dashboard', $https);
check($st === 302 && isset($h['content-security-policy']) && isset($h['strict-transport-security']), 'Redirect 302 /dashboard com CSP e HSTS');

echo "[HTTP: API]\n";
[$st, $h, $body] = ni2426_req('GET', $s['base'] . '/api/v1/patient/home', array_merge($https, ['Origin: https://example.org']));
check($st === 401 && json_decode($body, true) !== null, 'API 401 continua JSON');
check(($h['content-security-policy'] ?? [''])[0] === "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'", 'API: CSP default-src none');
check(($h['x-content-type-options'] ?? []) === ['nosniff'] && !isset($h['access-control-allow-origin']), 'API: nosniff; origem externa sem Allow-Origin (CORS inalterado)');
check(stripos(implode('', $h['content-type'] ?? []), 'application/json') !== false, 'API: Content-Type JSON');
[$st, $h] = ni2426_req('OPTIONS', $s['base'] . '/api/v1/patient/home', array_merge($https, ['Origin: https://example.org', 'Access-Control-Request-Method: GET']));
echo "  [INFO] Preflight OPTIONS no php -S: $st (no v6 original também não é 204 neste servidor; ver Apache)\n";
$s['stop']();

echo "[CSP_MODE]\n";
$s = ni2426_server(['APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com', 'TRUSTED_PROXIES' => '127.0.0.1', 'CSP_MODE' => 'report-only'], 18481);
[, $h] = ni2426_req('GET', $s['base'] . '/login', $https);
check(isset($h['content-security-policy-report-only']) && ($h['content-security-policy'][0] ?? '') === "frame-ancestors 'none'; object-src 'none'; base-uri 'self'", 'report-only mantém anti-enquadramento efetivo');
$s['stop']();

echo "\nResultado: {$passed} passaram, {$failed} falharam\n";
exit($failed === 0 ? 0 : 1);
