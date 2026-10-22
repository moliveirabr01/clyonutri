<?php
// NI2-140: design system e acessibilidade do app da paciente e da página /app.
// Teste estático (sem banco): calcula o contraste WCAG dos pares de token que o app usa de verdade e
// confere os pontos da revisão que não dá para ver no navegador (texto ampliado, Alert.alert, containers
// "accessible" com botões dentro, emojis). O comportamento no navegador fica em tests/ni2_140_app_web_real_test.cjs.
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function ok(bool $cond, string $name, $detail = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $name\n"; return; }
    $fail++;
    echo "FAIL $name" . ($detail === null ? '' : ' → ' . substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300)) . "\n";
}
function lum(string $hex): float
{
    $hex = ltrim($hex, '#');
    $c = array_map(static fn($i) => hexdec(substr($hex, $i, 2)) / 255, [0, 2, 4]);
    $c = array_map(static fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function ratio(string $a, string $b): float
{
    [$l1, $l2] = [lum($a), lum($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
$read = static fn(string $rel): string => (string)file_get_contents($root . '/' . $rel);

// ---------------------------------------------------------------- tokens
$tokensSrc = $read('mobile/src/theme/tokens.ts');
preg_match_all("/(\\w+):\\s*'(#[0-9A-Fa-f]{6})'/", $tokensSrc, $m);
$t = array_combine($m[1], array_map('strtoupper', $m[2]));
$css = $read('public/assets/css/clinical-botanical.css');
foreach (['navy' => '--brand-navy-900', 'copper' => '--brand-copper-600', 'background' => '--neutral-warm-50', 'success' => '--semantic-success'] as $k => $var) {
    $ok = isset($t[$k]) && preg_match('/' . preg_quote($var, '/') . ':\s*' . preg_quote($t[$k], '/') . '\b/i', $css);
    ok((bool)$ok, "M1: tokens.$k igual a $var do DS", $t[$k] ?? null);
}
$pairs = [
    ['A1: texto branco sobre o botão cobre', 'white', 'copper', 4.5],
    ['A1: texto branco sobre o navy', 'white', 'navy', 4.5],
    ['M2: texto auxiliar (muted) sobre branco', 'muted', 'white', 4.5],
    ['M2: texto auxiliar (muted) sobre o fundo', 'muted', 'background', 4.5],
    ['M2: borda de campo sobre branco (1.4.11)', 'inputBorder', 'white', 3.0],
    ['Texto de erro sobre o fundo de erro', 'error', 'errorBg', 4.5],
    ['Texto de sucesso sobre o fundo de sucesso', 'success', 'successBg', 4.5],
    ['Texto cobre (links, horários) sobre o fundo', 'copper', 'background', 4.5],
    ['Texto principal sobre o fundo', 'ink', 'background', 4.5],
];
foreach ($pairs as [$name, $fg, $bg, $min]) {
    $r = isset($t[$fg], $t[$bg]) ? ratio($t[$fg], $t[$bg]) : 0.0;
    ok($r >= $min, sprintf('%s: %.2f:1 (mínimo %.1f)', $name, $r, $min), [$fg => $t[$fg] ?? null, $bg => $t[$bg] ?? null]);
}
ok(isset($t['copperAccent']) && ratio('#FFFFFF', $t['copperAccent']) < 3.0, 'A1: copperAccent continua reprovado para texto, então só pode ser decorativo');

// ---------------------------------------------------------------- arquivos da paciente
$patientFiles = [
    'mobile/src/screens/PatientHomeScreen.tsx', 'mobile/src/screens/PatientOnboardingScreen.tsx', 'mobile/src/components/PatientChat.tsx',
    'mobile/src/components/NextAppointmentCard.tsx', 'mobile/src/components/DailyCheckinCard.tsx', 'mobile/src/components/BrandLogo.tsx',
];
$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2139}\x{21B3}]/u';
foreach ($patientFiles as $f) {
    $src = $read($f);
    $short = basename($f);
    ok(!preg_match('/\bAlert\s*\.\s*alert\b|import\s*\{[^}]*\bAlert\b[^}]*\}\s*from\s*\'react-native\'/', $src), "A4: $short sem Alert.alert (no web ele não aparece)");
    ok(!preg_match($emoji, $src, $e), "M6: $short sem emoji ou glifo no texto", $e[0] ?? null);
    ok(!preg_match("/'#(E67A3F|E67E3A|94A3B8|F8F4ED|0F2A44|047857)'/i", $src, $h), "A1/M1/M2: $short sem as cores reprovadas ou antigas", $h[0] ?? null);
    ok(!preg_match("/'#0F2747'/i", $src), "M1: $short usa o token do navy, sem o hex solto");
}
$home = $read('mobile/src/screens/PatientHomeScreen.tsx');
ok(!preg_match('/accessible\s+accessibilityLabel=\{`Meta de hidratação/', $home) && str_contains($home, 'accessibilityRole="progressbar"'), 'A2: card de Hidratação sem container accessible (os botões continuam focáveis) e com barra de progresso');
ok(str_contains($home, 'testID="water-feedback"') && str_contains($home, 'announce('), 'A4: registro de água com aviso na tela e anúncio para o leitor de tela');
ok(substr_count($home, 'useAnnounce(') >= 6, 'A4: avisos de refeição, medida, privacidade e saída também são anunciados', substr_count($home, 'useAnnounce('));
ok(str_contains($home, 'maxFontSizeMultiplier={t.maxNavFontScale}'), 'A3: rótulo das abas com limite de ampliação (maxFontSizeMultiplier)');
preg_match('/navBar:\s*\{[^}]*\}/s', $home, $nb);
ok(isset($nb[0]) && !preg_match('/position:\s*\'absolute\'|\bheight:\s*70\b/', $nb[0]), 'A3: barra de abas no fluxo, sem altura fixa', $nb[0] ?? null);
ok(str_contains($home, 'horizontal') && str_contains($home, 'accessibilityRole="tablist"'), 'A3: barra de abas rola na horizontal dentro de um tablist');
ok(!preg_match('/accessibilityLabel=\{?[`\'"]Aba /', $home), 'B1: abas sem "Aba …" no rótulo');
ok(!preg_match('/minHeight:\s*36\b/', $home), 'B4: nenhum chip com 36 px de altura');
ok(!preg_match('/\b[A-Z]\w+ [A-Z]\w+ (Presença|Consumo|Sessão|Publicado|Completo)\b|Encerrar Sessão|Registrar Consumo|Nenhum Plano Publicado|Plano Alimentar Completo/u', $home), 'B2: botões e títulos em caixa de frase');
ok(!preg_match('/\bClique\b/u', $home), 'B2: "Toque", não "Clique"');
ok(str_contains($home, 'role="main"'), 'M3: conteúdo com landmark main');
$chat = $read('mobile/src/components/PatientChat.tsx');
ok(!preg_match('/<View style=\{s\.errorBox\} accessibilityRole="alert">/', $chat), 'A2: o alerta de erro do chat fica no texto, não no container do botão "Tentar de novo"');
ok(str_contains($read('mobile/src/components/BrandLogo.tsx'), 'accessibilityLabel="ClyoNutri"'), 'B6: logo com rótulo');
$html = is_file(__DIR__ . '/../mobile/public/index.html') ? $read('mobile/public/index.html') : ''; // ausente antes da correção
ok(str_contains($html, '<html lang="pt-BR">') && str_contains($html, '<title>ClyoNutri · Paciente</title>'), 'M3: index.html do web em pt-BR, com título do produto');
ok((bool)preg_match('/:focus-visible\s*\{[^}]*outline:\s*3px solid #B94F1F/', $html), 'B5: foco do teclado visível no web');

// ---------------------------------------------------------------- views/patient/app.php
$app = $read('views/patient/app.php');
$appVisible = (string)preg_replace('/<!--.*?-->/s', '', $app);
ok(preg_match_all('/<button[^>]*role="tab"/', $app) === 2 && substr_count($app, 'role="tabpanel"') === 2 && substr_count($app, 'aria-controls=') === 2, 'A8: abas com role=tab, aria-controls e painéis');
ok(str_contains($app, "e.key === 'ArrowRight'") && str_contains($app, "e.key === 'Home'"), 'A8: abas navegáveis por setas e Home/End');
ok(!preg_match('/#94a3b8|#ea580c|#c2410c/i', $app, $h), 'A8: sem as cores do Tailwind reprovadas no contraste', $h[0] ?? null);
preg_match('/--brand-copper-600:\s*(#[0-9A-Fa-f]{6})/', $app, $c);
ok(isset($c[1]) && strtoupper($c[1]) === '#B94F1F' && ratio('#FFFFFF', $c[1]) >= 4.5, 'A8: botão do /app no cobre do DS com 4,5:1', $c[1] ?? null);
preg_match('/--brand-muted:\s*(#[0-9A-Fa-f]{6})/', $app, $mu);
ok(isset($mu[1]) && ratio($mu[1], '#FFFFFF') >= 4.5, 'A8: texto auxiliar do /app com 4,5:1', $mu[1] ?? null);
ok(str_contains($app, '<img src="/assets/clyonutri-icon.png" alt=""'), 'A8: logo decorativo com alt vazio');
ok(!preg_match($emoji, preg_replace('/aria-hidden="true">\s*✓/u', '', $app), $e), 'M6: /app sem emoji lido em voz alta', $e[0] ?? null);
ok(!preg_match('/Primeiro Acesso|Já Tenho Cadastro|Código do Convite|Nome Completo|Confirmar Senha|Ir para o Meu Portal/u', $appVisible), 'B2: /app em caixa de frase');

echo "\nNI2-140 (estático): $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
