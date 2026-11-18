<?php

declare(strict_types=1);

/**
 * v8: public/.htaccess não pode acrescentar uma 2ª CSP às respostas do PHP.
 * Em PHP via FPM/CGI (HostGator) "Header setifempty" não enxerga os cabeçalhos do PHP; a CSP estática
 * (form-action 'none') ia junto e bloqueava o login. O FilesMatch limita essa política
 * aos estáticos, e a NI2-809 mantém um fallback condicionado à ausência da CSP.
 * Opcional: CSP_FPM_URL=http://127.0.0.1:8090 confere no Apache+FPM real.
 */
$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }
$ht = file_get_contents(__DIR__ . '/../public/.htaccess');
$noComments = preg_replace('/^\s*#.*$/m', '', $ht);
preg_match('/<IfModule mod_headers\.c>(.*?)\n<\/IfModule>/s', $noComments, $m);
$block = $m[1] ?? '';
check($block !== '', 'Bloco mod_headers presente');
preg_match_all('/<FilesMatch "([^"]+)">(.*?)<\/FilesMatch>/s', $block, $fm, PREG_SET_ORDER);
$outside = preg_replace('/<FilesMatch "[^"]+">.*?<\/FilesMatch>/s', '', $block);
function headerDirectives(string $config, string $name): array
{
    $pattern = '/^[ \t]*Header\h+(?:always\h+|onsuccess\h+)?\S+\h+' . preg_quote($name, '/') . '\b[^\r\n]*/mi';
    preg_match_all($pattern, $config, $matches);
    return array_map('trim', $matches[0]);
}

function hasGuardedFallback(string $config, string $name, string $valuePattern): bool
{
    $directives = headerDirectives($config, $name);
    $prefix = preg_quote("Header always setifempty {$name} \"", '~');
    $suffix = preg_quote("\" \"expr=-z resp('{$name}')\"", '~');
    return count($directives) === 1
        && preg_match('~^' . $prefix . $valuePattern . $suffix . '$~D', $directives[0]) === 1;
}

$rootHtaccess = file_get_contents(__DIR__ . '/../.htaccess');
$cspFallback = "[^\"]*form-action 'self';[^\"]*";
check(hasGuardedFallback($outside, 'Content-Security-Policy', $cspFallback), 'CSP global do public condicionada à ausência do cabeçalho');
check(hasGuardedFallback($rootHtaccess, 'Content-Security-Policy', $cspFallback), 'CSP global da raiz condicionada à ausência do cabeçalho');
check(!preg_match('/X-Frame-Options/i', $outside), 'Nenhum X-Frame-Options fora do FilesMatch');
check(hasGuardedFallback($outside, 'X-Content-Type-Options', 'nosniff'), 'nosniff global condicionado à ausência do cabeçalho');
check(hasGuardedFallback($outside, 'Referrer-Policy', 'strict-origin-when-cross-origin'), 'Referrer-Policy global condicionada à ausência do cabeçalho');
check(count($fm) === 1, 'Um FilesMatch de estáticos');
$re = '/' . ($fm[0][1] ?? 'x^') . '/';
foreach (['app.css', 'a.js', 'logo.svg', 'f.woff2', 'site.webmanifest'] as $f) { check(preg_match($re, $f) === 1, "Estático coberto: $f"); }
foreach (['index.php', 'login', 'x.php', 'api/v1/foods'] as $f) { check(preg_match($re, $f) === 0, "Não cobre resposta do PHP: $f"); }
check(substr_count($fm[0][2] ?? '', 'Content-Security-Policy') === 1 && str_contains($fm[0][2] ?? '', "form-action 'none'"), 'CSP estática só no FilesMatch');

$url = getenv('CSP_FPM_URL');
if ($url) {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
    @file_get_contents(rtrim($url, '/') . '/login', false, $ctx);
    $csp = array_values(array_filter($http_response_header ?? [], fn($h) => stripos($h, 'Content-Security-Policy:') === 0));
    check(count($csp) === 1 && str_contains($csp[0], "form-action 'self'"), "Apache+FPM real: /login com exatamente uma CSP (" . count($csp) . ')');
} else {
    echo "  [INFO] CSP_FPM_URL não definido: conferência HTTP no Apache+FPM pulada\n";
}
echo "\nResultado: $passed aprovados, $failed falhas\n";
exit($failed === 0 ? 0 : 1);
