<?php

declare(strict_types=1);

/**
 * NI2-25 — Tokens oficiais (Clinical Botanical é a paleta oficial; DS Premium é legado).
 *
 * Confere, contra os arquivos reais:
 *  - a paleta Botanical existe como token, com os valores exatos, e os nomes legados
 *    do Premium (--brand-*, --neutral-warm-50) viraram aliases dela;
 *  - semânticos têm valor próprio (não herdam da marca; sucesso é teal) e passam 4,5:1;
 *  - pares de cor-chave passam WCAG AA (botão verde, botão cobre sobre Forest, cobre sobre claro...);
 *  - escalas de espaçamento, raio, sombra e tipografia completas e usadas nos títulos;
 *  - CSS, app (clinicalBotanical.ts) e docs/design-system/tokens.md dizem a mesma coisa;
 *  - nenhuma tela chama Google Fonts e a CSP não libera mais o Google;
 *  - semânticos do admin passam AA;
 *  - no servidor real (php -S, SQLite ou MariaDB pelo DB_CONNECTION), páginas públicas
 *    carregam /assets/css/fonts.css, as fontes saem do próprio domínio e a CSP não cita Google.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/ni2_426_server.php';

use ClyoNutri\Core\Http\SecurityHeaders;

$root = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }

function rootVars(string $css): array
{
    if (!preg_match('/:root\s*\{(.*?)\n\}/s', $css, $m)) return [];
    $body = preg_replace('#/\*.*?\*/#s', '', $m[1]);
    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $mm, PREG_SET_ORDER);
    $out = [];
    foreach ($mm as $x) $out[$x[1]] = trim($x[2]);
    return resolveAliases($out);
}
/** Troca "var(--outro)" pelo valor de --outro (aliases legados apontam para --botanical-*). */
function resolveAliases(array $vars): array
{
    foreach ($vars as $k => $v) {
        for ($i = 0; $i < 5 && preg_match('/^var\(--([a-z0-9-]+)\)$/i', $v, $m) && isset($vars[$m[1]]); $i++) $v = $vars[$m[1]];
        $vars[$k] = $v;
    }
    return $vars;
}
function lum(string $hex): float
{
    $hex = ltrim($hex, '#');
    $c = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [0, 2, 4]);
    $c = array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function ratio(string $a, string $b): float
{
    [$x, $y] = [lum($a), lum($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}
/** php -S sem script de roteamento: arquivos de public/ saem direto (como no Apache) e o resto cai no index.php. */
function ni225_server(array $env, int $port): array
{
    $root = realpath(__DIR__ . '/..');
    $sess = sys_get_temp_dir() . '/ni225_sess_' . getmypid();
    @mkdir($sess, 0700, true);
    $envStr = '';
    foreach ($env as $k => $v) $envStr .= escapeshellarg("$k=$v") . ' ';
    $log = sys_get_temp_dir() . "/ni225_srv_$port.log";
    $cmd = 'cd ' . escapeshellarg($root) . " && env $envStr php -d display_errors=0 -d session.save_path=" . escapeshellarg($sess) . " -S 127.0.0.1:$port -t public > " . escapeshellarg($log) . ' 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($cmd));
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
    return ['base' => "http://127.0.0.1:$port", 'stop' => function () use ($pid, $sess) { if ($pid > 0) exec("kill $pid 2>/dev/null"); exec('rm -rf ' . escapeshellarg($sess)); }];
}
function isHex(string $v): bool { return (bool)preg_match('/^#[0-9a-f]{6}$/i', $v); }
/** Normaliza "16px" / "1rem" / "1.25" / 16 para número (px quando houver unidade). */
function num(string $v): ?float
{
    $v = trim($v);
    if (preg_match('/^(-?[0-9.]+)px$/', $v, $m)) return (float)$m[1];
    if (preg_match('/^(-?[0-9.]+)rem$/', $v, $m)) return (float)$m[1] * 16;
    if (preg_match('/^-?[0-9.]+$/', $v)) return (float)$v;
    return null;
}

$cssPath = $root . '/public/assets/css/clinical-botanical.css';
$css = (string)file_get_contents($cssPath);
$vars = rootVars($css);
echo "[1] Paleta Clinical Botanical (oficial)\n";
check(count($vars) > 40, ':root de clinical-botanical.css lido (' . count($vars) . ' tokens)');
$botanical = [
    'botanical-forest-950' => '#0A2A10', 'botanical-forest-900' => '#0F3E17', 'botanical-forest-800' => '#1B5226',
    'botanical-green-700' => '#2F6140', 'botanical-green-600' => '#39734A',
    'botanical-sage-300' => '#BFD8C2', 'botanical-sage-200' => '#D3E5D5', 'botanical-sage-100' => '#E5F0E5',
    'botanical-cream-50' => '#FAF9F4',
    'botanical-copper-400' => '#F4A06A', 'botanical-copper-500' => '#EF8F4F', 'botanical-copper-600' => '#B04A1B',
    'botanical-copper-700' => '#8F3D14', 'botanical-copper-100' => '#FBEADF',
];
foreach ($botanical as $k => $v) check(strcasecmp($vars[$k] ?? '', $v) === 0, "--$k = $v");
check(str_contains($vars['font-body'] ?? '', "'Inter'"), '--font-body começa por Inter');
// Nomes legados do Premium: continuam existindo, mas como alias da paleta oficial (nenhum valor Premium sobrou).
$legacy = ['brand-navy-900' => 'botanical-forest-900', 'brand-navy-reference' => 'botanical-forest-900', 'brand-copper-500' => 'botanical-copper-500',
    'brand-copper-600' => 'botanical-copper-600', 'brand-copper-reference' => 'botanical-copper-500', 'brand-green-500' => 'botanical-green-600',
    'brand-green-200' => 'botanical-sage-300', 'neutral-warm-50' => 'botanical-cream-50'];
foreach ($legacy as $old => $new) {
    check(preg_match('/--' . preg_quote($old, '/') . ':\s*var\(--' . preg_quote($new, '/') . '\);/', $css) === 1 && strcasecmp($vars[$old] ?? '', $vars[$new]) === 0, "--$old é alias de --$new ({$vars[$new]})");
}
$premium = ['#0F2747', '#0F2A44', '#E67A3F', '#E67E3A', '#B94F1F', '#FAF7F2', '#2E7D58', '#A7C7A1', '#18794E'];
$rootBlock = preg_match('/:root\s*\{(.*?)\n\}/s', $css, $rb) ? $rb[1] : '';
$still = array_filter($premium, fn ($h) => stripos($rootBlock, $h) !== false);
check($still === [], 'Nenhum valor do DS Premium sobrou no :root de clinical-botanical.css' . ($still ? ': ' . implode(', ', $still) : ''));
check(!preg_match('/--(?:brand|botanical)-[a-z0-9-]+\s*:\s*#(?:0F2747|E67A3F|B94F1F)/i', $css), 'CSS não redefine token de marca com valor Premium');

echo "[1b] Contraste AA dos pares-chave (WCAG 2.2)\n";
$pairs = [
    ['Texto Forest sobre branco', 'botanical-forest-900', 'neutral-white', 4.5],
    ['Texto Forest sobre Cream (fundo da página)', 'botanical-forest-900', 'botanical-cream-50', 4.5],
    ['Botão primário: branco sobre Clyo Green', 'neutral-white', 'botanical-green-600', 4.5],
    ['Botão primário em hover: branco sobre Green 700', 'neutral-white', 'botanical-green-700', 4.5],
    ['Link Clyo Green sobre Cream', 'botanical-green-600', 'botanical-cream-50', 4.5],
    ['Clyo Green sobre Sage claro (selo)', 'botanical-green-600', 'botanical-sage-100', 4.5],
    ['Forest sobre Sage', 'botanical-forest-900', 'botanical-sage-300', 4.5],
    ['Botão de destaque sobre fundo Forest: texto Forest sobre cobre 500 claro', 'botanical-forest-900', 'botanical-copper-500', 4.5],
    ['Hover do botão de destaque: texto Forest sobre cobre 400', 'botanical-forest-900', 'botanical-copper-400', 4.5],
    ['Cobre 500 (texto de acento) sobre Forest', 'botanical-copper-500', 'botanical-forest-900', 4.5],
    ['Cobre 500 sobre Forest 950 (rodapé do menu)', 'botanical-copper-500', 'botanical-forest-950', 4.5],
    ['Cobre 600 (acento) sobre branco', 'botanical-copper-600', 'neutral-white', 4.5],
    ['Cobre 600 (acento) sobre Cream', 'botanical-copper-600', 'botanical-cream-50', 4.5],
    ['Texto cobre 700 sobre o selo cobre 100', 'botanical-copper-700', 'botanical-copper-100', 4.5],
    ['Sage 200 (texto secundário) sobre Forest', 'botanical-sage-200', 'botanical-forest-900', 4.5],
    ['Branco sobre Forest 800 (degradê)', 'neutral-white', 'botanical-forest-800', 4.5],
    ['Sucesso (teal) sobre branco', 'semantic-success', 'neutral-white', 4.5],
    ['Sucesso (teal) sobre o fundo claro', 'semantic-success', 'semantic-success-light', 4.5],
    ['Texto de sucesso sobre o fundo claro', 'semantic-success-text', 'semantic-success-light', 4.5],
    ['Sucesso sobre Forest (ponto de status do menu)', 'semantic-success-on-dark', 'botanical-forest-900', 4.5],
    ['Foco do menu Forest (cobre 500), componente de UI', 'botanical-copper-500', 'botanical-forest-900', 3.0],
    ['Foco em fundo claro (cobre 600), componente de UI', 'botanical-copper-600', 'neutral-white', 3.0],
];
foreach ($pairs as [$label, $fg, $bg, $min]) {
    $r = isHex($vars[$fg] ?? '') && isHex($vars[$bg] ?? '') ? ratio($vars[$fg], $vars[$bg]) : 0.0;
    check($r >= $min, sprintf('%s: %.2f:1 (mín. %.1f)', $label, $r, $min));
}
// O que NÃO pode acontecer: cobre claro como texto sobre branco ou como fundo de botão com texto branco.
check(ratio($vars['botanical-copper-500'], '#FFFFFF') < 4.5 && ratio('#FFFFFF', $vars['botanical-copper-500']) < 4.5, 'Cobre 500 reprova como texto sobre branco: só vale sobre Forest (documentado)');
check(ratio($vars['semantic-success'], $vars['botanical-green-600']) < 1.5, 'Sucesso (teal) é distinto do Clyo Green só pelo matiz: a confirmação sempre vem com texto/ícone (regra do documento)');

echo "[2] Semânticos independentes da marca\n";
$brand = array_filter($vars, fn ($v, $k) => (str_starts_with($k, 'brand-') || str_starts_with($k, 'botanical-')) && isHex($v), ARRAY_FILTER_USE_BOTH);
foreach (['success', 'warning', 'error', 'info'] as $s) {
    $v = $vars["semantic-$s"] ?? ''; $l = $vars["semantic-$s-light"] ?? '';
    check(isHex($v) && isHex($l), "--semantic-$s e -light têm valor próprio (não var(--brand-*))");
    check(!in_array(strtoupper($v), array_map('strtoupper', $brand), true), "--semantic-$s não é igual a nenhum token de marca");
    if (isHex($v) && isHex($l)) {
        check(ratio($v, '#FFFFFF') >= 4.5, sprintf('--semantic-%s sobre branco %.2f:1', $s, ratio($v, '#FFFFFF')));
        check(ratio($v, $l) >= 4.5, sprintf('--semantic-%s sobre o fundo claro %.2f:1', $s, ratio($v, $l)));
    }
}
check(!preg_match('/--semantic-[a-z-]+\s*:\s*var\(--(brand|botanical)/', $css), 'Nenhum semântico aponta para var(--brand-*) nem var(--botanical-*)');
check(strcasecmp($vars['semantic-success'], '#0A7075') === 0 && strcasecmp($vars['semantic-success-light'], '#E3F4F2') === 0, 'Sucesso é teal #0A7075 sobre #E3F4F2');

echo "[3] Escalas\n";
foreach ([1 => 4, 2 => 8, 3 => 12, 4 => 16, 5 => 20, 6 => 24, 8 => 32, 10 => 40, 12 => 48, 16 => 64, 20 => 80, 24 => 96] as $k => $px) check(num($vars["space-$k"] ?? '') === (float)$px, "--space-$k = {$px}px");
foreach (['input' => 8, 'button' => 10, 'card' => 12, 'modal' => 16, 'pill' => 9999] as $k => $px) check(num($vars["radius-$k"] ?? '') === (float)$px, "--radius-$k = {$px}px");
foreach (['sm', 'md', 'lg', 'modal'] as $k) check(str_contains($vars["shadow-$k"] ?? '', 'rgba('), "--shadow-$k definido");
$fs = ['caption' => 12, 'compact' => 14, 'body' => 16, 'card-title' => 18, 'h3' => 20, 'h2' => 24, 'h1' => 32, 'display' => 40, 'hero' => 64];
foreach ($fs as $k => $px) check(num($vars["font-size-$k"] ?? '') === (float)$px, "--font-size-$k = {$px}px");
foreach (['regular' => 400, 'medium' => 500, 'semibold' => 600, 'bold' => 700] as $k => $w) check(($vars["font-weight-$k"] ?? '') === (string)$w, "--font-weight-$k = $w");
check(isset($vars['line-height-tight'], $vars['line-height-body']), '--line-height-tight e --line-height-body definidos');
foreach (['h1', 'h2', 'h3'] as $h) check((bool)preg_match('/^' . $h . '\s*\{\s*font-size:\s*var\(--font-size-' . $h . '\)/m', $css), "$h usa var(--font-size-$h)");
check(substr_count($css, 'box-shadow: var(--shadow-') >= 4, 'Sombras grandes usam os tokens (sem rgba solto nos modais)');
check((bool)preg_match('/\.app-sidebar :focus-visible\s*\{\s*outline-color:\s*var\(--botanical-copper-500\)/', $css) && ratio($vars['botanical-copper-500'], $vars['botanical-forest-900']) >= 3, sprintf('Foco no menu Forest usa cobre 500 claro (%.2f:1)', ratio($vars['botanical-copper-500'], $vars['botanical-forest-900'])));

echo "[4] App espelha o CSS\n";
$ts = (string)file_get_contents($root . '/mobile/src/theme/clinicalBotanical.ts');
preg_match('/export const cssVars = \{(.*?)\n\} as const;/s', $ts, $tm);
preg_match_all("/'([a-z0-9-]+)':\s*('?)([^',\n]+)\\2,/", $tm[1] ?? '', $tsm, PREG_SET_ORDER);
check(count($tsm) >= 55, 'cssVars do app lido (' . count($tsm) . ' chaves)');
$diverge = [];
foreach ($tsm as $x) {
    [$k, $v] = [$x[1], $x[3]];
    $c = $vars[$k] ?? null;
    $ok = $c !== null && (isHex($v) ? strcasecmp($c, $v) === 0 : num($c) === num($v));
    if (!$ok) $diverge[] = "$k (app $v / css " . ($c ?? 'ausente') . ')';
}
check($diverge === [], 'Todos os valores do app batem com o CSS' . ($diverge ? ': ' . implode('; ', $diverge) : ''));
$tsKeys = array_column($tsm, 1);
$faltando = array_filter(array_keys($vars), fn ($k) => preg_match('/^(brand-|botanical-|neutral-|semantic-|radius-|space-|font-size-|font-weight-|line-height-)/', $k) && !in_array($k, $tsKeys, true));
check($faltando === [], 'Nenhum token de cor/escala do CSS ficou fora do app' . ($faltando ? ': ' . implode(', ', $faltando) : ''));

echo "[5] Documento bate com o CSS\n";
$doc = (string)@file_get_contents($root . '/docs/design-system/tokens.md');
$docHex = [];
foreach (preg_split('/\R/', $doc) as $line) {
    if (!str_starts_with(trim($line), '|')) continue;
    $last = null;
    foreach (explode('|', $line) as $cell) {
        $cell = trim($cell);
        if (preg_match('/^`--([a-z0-9-]+)`$/', $cell, $x)) $last = $x[1];
        elseif ($last !== null && preg_match('/^`(#[0-9A-Fa-f]{6})`$/', $cell, $x)) { $docHex[$last] = $x[1]; $last = null; }
    }
}
check(count($docHex) >= 25, 'tokens.md lista as cores (' . count($docHex) . ')');
$errDoc = [];
foreach ($docHex as $k => $v) if (strcasecmp($vars[$k] ?? '', $v) !== 0) $errDoc[] = "$k ($v)";
check($errDoc === [], 'Toda cor do documento é igual à do CSS' . ($errDoc ? ': ' . implode(', ', $errDoc) : ''));
$semDoc = array_filter(array_keys($vars), fn ($k) => preg_match('/^(brand-|botanical-|neutral-|semantic-)/', $k) && isHex($vars[$k]) && !isset($docHex[$k]));
check($semDoc === [], 'Toda cor de marca/neutra/semântica do CSS está no documento' . ($semDoc ? ': ' . implode(', ', $semDoc) : ''));
check(str_contains($doc, 'Paleta oficial: Clinical Botanical') && !str_contains($doc, 'Decisão pendente'), 'Documento declara o Clinical Botanical como paleta oficial (sem decisão pendente)');
check(str_contains($doc, 'legado/deprecado') && str_contains($doc, '### Legado do DS Premium (deprecado)'), 'Documento marca o DS Premium como legado/deprecado');
$oldInDoc = array_filter(['#0F2747', '#E67A3F', '#B94F1F', '#FAF7F2', '#18794E'], fn ($h) => str_contains($doc, $h));
check(count($oldInDoc) === 5 && !preg_match('/`#(0F2747|E67A3F|B94F1F|FAF7F2|18794E)`/i', $doc), 'Valores antigos do Premium listados só como histórico (fora das tabelas de token)');
check(str_contains($doc, 'NI2-140') && str_contains($doc, 'tokens.ts'), 'Documento registra a migração pendente do tokens.ts (NI2-140)');
check(str_contains($doc, 'Logo (pendente)') && str_contains($doc, 'versão verde'), 'Documento registra o logo pendente');
check(str_contains($doc, 'Telas com `:root` próprio') && str_contains($doc, 'mobile/src/screens'), 'Documento lista o que não foi coberto');

echo "[6] Fontes no próprio domínio (sem Google)\n";
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = substr($f->getPathname(), strlen($root) + 1);
    if (!preg_match('#^(views|src|public|mobile/src)/#', $p) || !preg_match('/\.(php|css|js|mjs|ts|tsx|html)$/', $p)) continue;
    if (preg_match('/fonts\.(googleapis|gstatic)\.com/', (string)file_get_contents($f->getPathname()))) $hits[] = $p;
}
check($hits === [], 'Nenhum arquivo de views/src/public/mobile chama fonts.googleapis/gstatic' . ($hits ? ': ' . implode(', ', $hits) : ''));
$fontsCss = (string)@file_get_contents($root . '/public/assets/css/fonts.css');
preg_match_all("#url\('(/assets/fonts/[a-z0-9.-]+\.woff2)'\)#", $fontsCss, $um);
check(count($um[1]) === 6, 'fonts.css declara 6 faces (Inter, Playfair normal e itálico; latin e latin-ext)');
foreach ($um[1] as $u) { $b = (string)@file_get_contents($root . '/public' . $u, false, null, 0, 4); check($b === 'wOF2', "$u existe e é woff2"); }
check(str_contains($fontsCss, "font-family: 'Inter'") && str_contains($fontsCss, "font-family: 'Playfair Display'") && substr_count($fontsCss, 'font-display: swap') === 6, 'fonts.css: Inter + Playfair com font-display: swap');
check(is_file($root . '/public/assets/fonts/OFL-Inter.txt') && is_file($root . '/public/assets/fonts/OFL-PlayfairDisplay.txt'), 'Licenças OFL junto das fontes (.txt, que o .htaccess serve)');
$views = ['views/layouts/admin-shell.php', 'views/layouts/professional-shell.php', 'views/legal/privacy.php', 'views/legal/terms.php', 'views/landing/index.php', 'views/auth/register.php', 'views/auth/reset-password.php', 'views/auth/login.php', 'views/auth/forgot-password.php'];
foreach ($views as $v) check(str_contains((string)file_get_contents($root . '/' . $v), '<link rel="stylesheet" href="/assets/css/fonts.css">'), "$v carrega /assets/css/fonts.css");
$csp = SecurityHeaders::pagePolicy();
check(!str_contains($csp, 'google') && str_contains($csp, "font-src 'self'") && !preg_match('/style-src[^;]*https:/', $csp), 'CSP das páginas sem Google (font-src e style-src só do próprio domínio)');
check(str_contains((string)file_get_contents($root . '/public/.htaccess'), 'AddType font/woff2 .woff2'), '.htaccess garante o MIME do woff2');

echo "[7] Admin, landing e telas com :root próprio batem com a paleta oficial\n";
$admin = (string)file_get_contents($root . '/views/layouts/admin-shell.php');
$av = rootVars(preg_replace('/^    /m', '', (string)preg_replace('/^.*?(:root\s*\{)/s', '$1', $admin, 1)));
foreach (['success', 'warning', 'danger', 'info'] as $s) {
    $v = $av["admin-$s"] ?? ''; $bg = $av["admin-$s-bg"] ?? '';
    check(isHex($v) && isHex($bg) && ratio($v, '#FFFFFF') >= 4.5 && ratio($v, $bg) >= 4.5, sprintf('--admin-%s %s: %.2f:1 no branco, %.2f:1 no fundo', $s, $v, isHex($v) ? ratio($v, '#FFFFFF') : 0, isHex($v) && isHex($bg) ? ratio($v, $bg) : 0));
}
foreach (['admin-navy-900' => 'botanical-forest-900', 'admin-navy-950' => 'botanical-forest-950', 'admin-navy-800' => 'botanical-forest-800', 'admin-green-600' => 'botanical-green-600',
          'admin-green-700' => 'botanical-green-700', 'admin-copper-600' => 'botanical-copper-600', 'admin-copper-500' => 'botanical-copper-500', 'admin-copper-400' => 'botanical-copper-400',
          'admin-copper-100' => 'botanical-copper-100', 'admin-success' => 'semantic-success', 'admin-success-bg' => 'semantic-success-light'] as $k => $ref) {
    check(strcasecmp($av[$k] ?? '', $vars[$ref]) === 0, "admin --$k igual ao DS ({$vars[$ref]})");
}
check(ratio($av['admin-copper-500'] ?? '#FFFFFF', $av['admin-navy-900'] ?? '#FFFFFF') >= 4.5 && ratio('#FFFFFF', $av['admin-green-600'] ?? '#FFFFFF') >= 4.5, 'Admin: cobre 500 legível sobre o menu Forest e botão primário verde com texto branco passa 4,5:1');
check(str_contains($admin, '.btn--primary {' . "\n" . '      background-color: var(--admin-green-600);'), 'Botão primário do admin é Clyo Green (cobre fica de acento)');
$lv = rootVars((string)preg_replace('#^/\*.*?\*/\s*#s', '', (string)file_get_contents($root . '/public/assets/css/landing-premium.css')));
foreach (['brand-navy-900', 'brand-copper-500', 'brand-copper-600', 'neutral-warm-50', 'botanical-forest-900', 'botanical-green-600', 'botanical-sage-200', 'botanical-cream-50'] as $k) check(strcasecmp($lv[$k] ?? '', $vars[$k]) === 0, "landing --$k igual ao DS ({$vars[$k]})");
check(ratio($lv['brand-copper-600'] ?? '#FFFFFF', $lv['neutral-warm-50'] ?? '#FFFFFF') >= 4.5, 'Cobre 600 da landing passa 4,5:1 no creme');
$landingCss = (string)file_get_contents($root . '/public/assets/css/landing-premium.css');
check((bool)preg_match('/\.btn-primary\s*\{[^}]*background:\s*var\(--botanical-green-600\)/s', $landingCss), 'Botão primário da landing é Clyo Green');
$own = [
    'public/assets/css/teleconsulta.css' => ['navy' => 'botanical-forest-900', 'copper-dark' => 'botanical-copper-600', 'green' => 'botanical-green-600', 'success' => 'semantic-success'],
    'public/assets/css/pre-consulta.css' => ['pc-navy' => 'botanical-forest-900', 'pc-copper' => 'botanical-copper-600', 'pc-bg' => 'botanical-cream-50'],
    'public/assets/css/prontuario-impressao.css' => ['navy' => 'botanical-forest-900', 'copper-dark' => 'botanical-copper-600', 'green' => 'botanical-green-600'],
];
foreach ($own as $file => $map) {
    $ov = rootVars((string)file_get_contents($root . '/' . $file));
    foreach ($map as $k => $ref) check(strcasecmp($ov[$k] ?? '', $vars[$ref]) === 0, basename($file) . " --$k igual ao DS ({$vars[$ref]})");
}
$appView = (string)file_get_contents($root . '/views/patient/app.php');
$pv = rootVars((string)preg_replace('/^.*?(:root\s*\{)/s', '$1', preg_replace('/^    /m', '', $appView), 1));
foreach (['botanical-forest-900', 'botanical-green-600', 'botanical-green-700', 'botanical-copper-600', 'botanical-cream-50', 'botanical-sage-100', 'botanical-sage-200'] as $k) check(strcasecmp($pv[$k] ?? '', $vars[$k]) === 0, "/app --$k igual ao DS ({$vars[$k]})");
// Nenhum Premium sobrou nas telas e e-mails que foram remapeados.
$premiumHex = '/#(?:0F2747|0F2A44|102D47|1E3A5F|E67A3F|E67E3A|B94F1F|FAF7F2|F8F4ED|EAD8C7|18794E)\b/i';
$scan = ['views/auth/login.php', 'views/auth/register.php', 'views/auth/forgot-password.php', 'views/auth/reset-password.php', 'views/booking/public_booking.php', 'views/patient/app.php', 'views/layouts/admin-shell.php',
    'views/layouts/professional-shell.php', 'views/legal/privacy.php', 'views/settings/subscription.php', 'src/Core/Mail/PasswordResetEmail.php', 'src/Domain/Telehealth/TeleconsultationInviteMail.php',
    'src/Domain/PreConsultation/PreConsultationMail.php', 'src/Application/Controllers/DashboardController.php', 'src/Application/Controllers/ChatController.php', 'src/Application/Controllers/HelpdeskController.php',
    'public/assets/css/teleconsulta.css', 'public/assets/css/pre-consulta.css', 'public/assets/css/prontuario-impressao.css', 'public/assets/css/clinical-journeys.css', 'public/assets/css/landing-premium.css'];
$left = [];
foreach ($scan as $f) if (preg_match($premiumHex, (string)file_get_contents($root . '/' . $f), $pm)) $left[] = "$f ({$pm[0]})";
check($left === [], 'Nenhum hex do DS Premium sobrou em telas, CSS e e-mails remapeados' . ($left ? ': ' . implode('; ', $left) : ''));
$mail = (string)file_get_contents($root . '/src/Core/Mail/PasswordResetEmail.php');
check(str_contains($mail, 'background:#39734A') && ratio('#FFFFFF', '#39734A') >= 4.5 && ratio('#B04A1B', '#FFFFFF') >= 4.5, 'E-mail de senha: botão Clyo Green com texto branco e link cobre 600 (AA)');
check(!str_contains((string)file_get_contents($root . '/public/assets/css/prontuario-impressao.css'), 'background: var(--navy)') && str_contains((string)file_get_contents($root . '/public/assets/css/prontuario-impressao.css'), 'background: #fff'), 'Impressão do prontuário segue em fundo branco (legível em impressora comum)');

echo "[8] Servidor real (" . (getenv('DB_CONNECTION') ?: 'sqlite') . ")\n";
$port = 18525;
$env = ['APP_ENV' => 'production', 'APP_URL' => "http://127.0.0.1:$port", 'FORCE_HTTPS' => 'false', 'TRUSTED_PROXIES' => '', 'APP_DEBUG' => 'false'];
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_TIMEZONE'] as $k) if (getenv($k) !== false && getenv($k) !== '') $env[$k] = getenv($k);
if (($env['DB_CONNECTION'] ?? 'sqlite') === 'sqlite') $env['DB_DATABASE'] = getenv('TEST_DB_DATABASE') ?: ($env['DB_DATABASE'] ?? $root . '/storage/testing.sqlite');
$s = ni225_server($env, $port);
$b = $s['base'];
try {
    foreach (['/', '/login', '/cadastro', '/recuperar-senha', '/termos', '/privacidade'] as $p) {
        [$st, $h, $body] = ni2426_req('GET', $b . $p);
        $pcsp = implode(' ', $h['content-security-policy'] ?? []);
        check($st === 200, "GET $p -> 200 (obtido $st)");
        check(str_contains($body, 'href="/assets/css/fonts.css"') && str_contains($body, 'href="/assets/fonts/inter-normal-latin.woff2" as="font"'), "GET $p: fonts.css e preload da Inter");
        check(!preg_match('/fonts\.(googleapis|gstatic)/', $body . $pcsp), "GET $p: HTML e CSP sem Google Fonts");
        check(str_contains($pcsp, "font-src 'self'"), "GET $p: CSP com font-src 'self'");
    }
    [$st, $h, $body] = ni2426_req('GET', $b . '/assets/css/fonts.css');
    check($st === 200 && str_contains($body, '@font-face'), 'GET /assets/css/fonts.css -> 200 com @font-face');
    foreach ($um[1] as $u) {
        [$st, $h, $body] = ni2426_req('GET', $b . $u);
        check($st === 200 && substr($body, 0, 4) === 'wOF2' && strlen($body) === filesize($root . '/public' . $u), "GET $u -> 200, arquivo íntegro");
    }
    [$st, $h, $body] = ni2426_req('GET', $b . '/assets/css/clinical-botanical.css');
    check($st === 200 && str_contains($body, '--botanical-forest-900: #0F3E17'), 'CSS servido já com os tokens Botanical');
} finally {
    $s['stop']();
}

echo "\nResultado: $passed passaram, $failed falharam\n";
exit($failed === 0 ? 0 : 1);
