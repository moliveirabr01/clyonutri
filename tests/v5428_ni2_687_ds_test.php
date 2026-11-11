<?php
/**
 * v54.33 NI2-687: migração do Design System nas telas legadas e alertas clínicos.
 * Confere: (1) arquivos migrados sem style=, <style> nem cor hex; (2) toda classe u-/ds- e todo token var(--…)
 * usado existe no CSS; (3) ids e data-testid preservados em relação à base v54.27 (66b66d2); (4) alerta com
 * papel, gravidade, rótulo para leitor de tela e ícone por variante; (5) erro antes de sucesso; (6) mensagem de
 * falha técnica sem detalhe interno; (7) cabeçalho de página com uma ação principal; (8) isolamento entre clínicas
 * no repositório. Dados TESTE, SQLite descartável. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5428-ds.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\View\OperationalUI;

$root = dirname(__DIR__);
putenv('APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk=');
putenv('APP_URL=http://127.0.0.1');
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

const BASE_COMMIT = 'e3e9cf4';
$MIGRATED = [
    'src/Application/Controllers/DashboardController.php', 'src/Application/View/OperationalUI.php',
    'views/layouts/professional-shell.php', 'views/anamnesis/form_page.php', 'views/clinical/medical_record.php',
    'views/patients/app-invitation.php', 'views/patients/import_show.php', 'views/patients/import.php',
    'views/patients/_allergy_badge.php', 'views/patients/chat-link.php', 'views/patients/app-evolution.php',
    'views/patients/edit.php', 'views/patients/longitudinal.php', 'views/patients/exam_show.php',
    'views/patients/_profile_header.php', 'views/patients/app-hydration.php', 'views/patients/partials/exam_results.php',
    'views/patients/consent-choice.php', 'views/meal_plans/_energy_card.php', 'views/meal_plans/clinic_measures.php',
    'views/meal_plans/shopping_list.php', 'views/meal_plans/compare_versions.php', 'views/meal_plans/templates.php',
    'views/settings/subscription.php', 'views/settings/_plano_trocar.php', 'views/settings/profile.php',
    'views/settings/_conta_exclusao.php', 'views/settings/_calendar.php', 'views/settings/_plano_resumo.php',
    'views/settings/team.php', 'views/agenda/change_requests.php', 'views/agenda/_schedule_fields.php',
];
$strip = static fn(string $s): string => preg_replace(['#/\*.*?\*/#s', '#<!--.*?-->#s'], '', $s);

echo "== (1) sem estilo solto nos arquivos migrados\n";
foreach ($MIGRATED as $f) {
    $s = $strip((string)file_get_contents("$root/$f"));
    $style = preg_match_all('/\sstyle\s*=\s*["\']/', $s);
    $tag = preg_match_all('/<style[\s>]/i', $s);
    $noAttr = preg_replace('/\b(?:href|id|data-[\w-]+|aria-controls|for|name)\s*=\s*"[^"]*"/', '', $s);
    $hex = preg_match_all('/(?<![\w&\/=-])#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\b(?![\w-])/', (string)preg_replace('/<\?php.*?\?>/s', '', $noAttr));
    T::ok($style === 0 && $tag === 0 && $hex === 0, "$f: style=$style, <style>=$tag, hex=$hex");
}
T::ok(!file_exists("$root/views/agenda/_calendar_styles.php"), '_calendar_styles.php (bloco <style> da agenda) foi para o CSS');

echo "== (2) classes e tokens usados existem\n";
$cssFiles = glob("$root/public/assets/css/*.css");
$css = '';
foreach ($cssFiles as $c) $css .= file_get_contents($c) . "\n";
preg_match_all('/\.((?:u|ds)-[A-Za-z0-9_-]+)/', $css, $m);
$defined = array_flip($m[1]);
preg_match_all('/(--[A-Za-z0-9_-]+)\s*:/', $css, $m);
$tokens = array_flip($m[1]);
$missing = [];
foreach ($MIGRATED as $f) {
    $s = (string)file_get_contents("$root/$f");
    preg_match_all('/class\s*=\s*"([^"]*)"/', $s, $cm);
    foreach ($cm[1] as $cls) {
        foreach (preg_split('/\s+/', (string)preg_replace('/<\?.*?\?>/s', ' ', $cls)) as $c) {
            if (preg_match('/^(u|ds)-[A-Za-z0-9_-]+$/', $c) && !isset($defined[$c])) $missing[$c] = $f;
        }
    }
}
T::ok($missing === [], 'toda classe u-/ds- dos arquivos migrados tem regra no CSS' . ($missing ? ': ' . implode(', ', array_slice(array_keys($missing), 0, 12)) : ''));
$undef = [];
foreach (['ds-components.css', 'ds-utilities.css', 'ds-pages.css', 'shopping-list.css'] as $c) {
    preg_match_all('/var\((--[A-Za-z0-9_-]+)(\s*,)?/', (string)file_get_contents("$root/public/assets/css/$c"), $vm, PREG_SET_ORDER);
    foreach ($vm as $v) if (!isset($tokens[$v[1]]) && empty($v[2])) $undef[$v[1]] = $c;
}
T::ok($undef === [], 'todo var(--…) dos CSS novos aponta para token definido' . ($undef ? ': ' . implode(', ', array_keys($undef)) : ''));
foreach (['ds-components.css', 'ds-utilities.css', 'ds-pages.css', 'shopping-list.css'] as $c) {
    $raw = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents("$root/public/assets/css/$c"));
    T::ok(!preg_match('/#[0-9a-fA-F]{3,8}\b/', (string)$raw), "$c: sem cor hex (só tokens)");
}
$shell = (string)file_get_contents("$root/views/layouts/professional-shell.php");
foreach (['ds-components', 'ds-utilities', 'ds-pages'] as $c) T::ok(str_contains($shell, "/assets/css/$c.css?v=v54-47"), "shell carrega $c.css?v=v54-47");

echo "== (3) ids e data-testid preservados (base " . BASE_COMMIT . ")\n";
// v54.46 / CR2-NI2-004: baseline embutido (tests/fixtures/ds_baseline_e3e9cf4.json) — o gate não depende de .git.
// Com git disponível, o fixture é conferido contra o commit base; sem git, usa só o fixture (motivo registrado).
$fixture = json_decode((string)@file_get_contents("$root/tests/fixtures/ds_baseline_e3e9cf4.json"), true);
$baseline = is_array($fixture['files'] ?? null) ? $fixture['files'] : null;
$git = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse --verify -q ' . BASE_COMMIT . ' 2>/dev/null'));
if ($baseline === null && $git === '') {
    echo "  SKIP  baseline DS indisponível (sem fixture e sem git) — verificação (3) não executada\n";
} else {
    if ($git === '') echo "  INFO  sem .git: usando baseline embutido de " . BASE_COMMIT . "\n";
    foreach ($MIGRATED as $f) {
        if ($baseline !== null) {
            $attrs = $baseline[$f] ?? null;
            if ($attrs === null) continue;
            if ($git !== '') {
                $old = (string)shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . BASE_COMMIT . ':' . escapeshellarg($f) . ' 2>/dev/null');
                preg_match_all('/\b(id|data-testid)="([A-Za-z0-9_:-]+)"/', $old, $om, PREG_SET_ORDER);
                $fromGit = array_values(array_unique(array_map(static fn($o) => $o[1] . '="' . $o[2] . '"', $om)));
                T::ok($fromGit === $attrs, "$f: fixture embutido confere com git " . BASE_COMMIT);
            }
        } else {
            $old = (string)shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . BASE_COMMIT . ':' . escapeshellarg($f) . ' 2>/dev/null');
            if ($old === '') continue;
            preg_match_all('/\b(id|data-testid)="([A-Za-z0-9_:-]+)"/', $old, $om, PREG_SET_ORDER);
            $attrs = array_values(array_unique(array_map(static fn($o) => $o[1] . '="' . $o[2] . '"', $om)));
        }
        $new = (string)file_get_contents("$root/$f");
        $lost = array_values(array_filter($attrs, static fn($a) => !str_contains($new, $a)));
        T::ok($lost === [], "$f: " . count($attrs) . ' id/data-testid preservados' . ($lost ? ' — faltam ' . implode(', ', array_slice($lost, 0, 8)) : ''));
    }
}

echo "== (4) alertas: papel, gravidade, rótulo e ícone\n";
$shapes = [];
foreach (['info' => 'status', 'success' => 'status', 'warning' => 'status', 'error' => 'alert', 'critical' => 'alert'] as $sev => $role) {
    $h = OperationalUI::alert('Título TESTE', 'Mensagem TESTE <b>', $sev);
    T::ok(str_contains($h, 'role="' . $role . '"') && str_contains($h, 'data-severity="' . $sev . '"') && str_contains($h, 'op-alert__icon') && str_contains($h, '<span class="sr-only">'), "alerta $sev: role=$role, data-severity, ícone e rótulo para leitor de tela");
    T::ok(str_contains($h, 'Mensagem TESTE &lt;b&gt;'), "alerta $sev: texto escapado");
}
$ops = (string)file_get_contents("$root/public/assets/css/operational-ui.css") . $css;
foreach (['info', 'success', 'warning', 'error', 'critical'] as $sev) {
    T::ok((bool)preg_match('/\.op-(?:message|alert)--' . $sev . '\b[^{]*\{[^}]*--op-msg-mask\s*:/s', $ops), "variante $sev tem forma de ícone própria (--op-msg-mask)");
}
preg_match_all('/\.op-(?:message|alert)--(\w+)\b[^{]*\{[^}]*--op-msg-mask\s*:\s*([^;]+);/s', $ops, $mm, PREG_SET_ORDER);
foreach ($mm as $x) $shapes[$x[1]] = md5(trim($x[2]));
T::ok(count(array_unique(array_intersect_key($shapes, array_flip(['success', 'warning', 'error', 'critical'])))) === 4, 'sucesso, aviso, erro e alerta crítico têm ícones diferentes (não só a cor)');
T::ok((bool)preg_match('/forced-colors\s*:\s*active/', $css), 'alto contraste (forced-colors) tratado no CSS');

echo "== (5) erro vem antes do sucesso\n";
foreach (['views/anamnesis/form_page.php', 'views/clinical/medical_record.php', 'views/patients/import.php', 'views/patients/import_show.php',
          'views/meal_plans/templates.php', 'views/meal_plans/clinic_measures.php', 'views/foods/equivalence_rules.php'] as $f) {
    $s = (string)file_get_contents("$root/$f");
    $pe = strpos($s, 'op-message--error'); $ps = false;
    foreach (['op-message--success', 'op-message--info" role="status"><?= $e($feedback)', 'op-message--info u-mb-4" role="status"'] as $needle) {
        $q = strpos($s, $needle); if ($q !== false && ($ps === false || $q < $ps)) $ps = $q;
    }
    T::ok($pe !== false && ($ps === false || $pe < $ps), "$f: bloco de erro antes do de sucesso");
}
$inv = json_decode((string)shell_exec('php ' . escapeshellarg("$root/tests/tools/ds_inventory.php") . ' ' . escapeshellarg($root)), true);
$sum = static function (?array $j, string $k): int { $t = 0; foreach ((array)$j as $r) $t += (int)($r[$k] ?? 0); return $t; };
T::ok(is_array($inv) && $sum($inv, 'sucessoAntesErro') === 0, 'inventário: nenhuma tela com sucesso impresso antes do erro');
T::ok(is_array($inv) && $sum($inv, 'modalInline') <= 1, 'inventário: no máximo 1 janela com display:none inline (ChatController, fora do escopo)');

echo "== (6) falha técnica sem detalhe interno\n";
$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$a = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '28101');
$bU = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner', '28102');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE Alfa');
$pB = Fx::patient($pdo, $cB, 'Paciente TESTE Beta');
Fx::act($a);
$_SESSION['user_id'] = $a['user_id']; $_SESSION['clinic_id'] = $cA; $_SESSION['clinic_name'] = 'Clínica TESTE A'; $_SESSION['user_name'] = 'Nutri TESTE A';
$dash = new \ClyoNutri\Application\Controllers\DashboardController();
$uf = new ReflectionMethod($dash, 'userFacingError');
$prevLog = ini_set('error_log', sys_get_temp_dir() . '/v5428-ds-' . getmypid() . '.log');
$tech = $uf->invoke($dash, 'teste', new PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column patients.cpf in /home/app/src/X.php'));
T::ok(!preg_match('/SQLSTATE|Column|patients|\.php/', $tech) && (bool)preg_match('/código [0-9a-f]{12}\.$/', $tech), 'PDOException vira texto genérico com código de suporte');
$typeErr = $uf->invoke($dash, 'teste', new TypeError('Argument #1 must be of type int, string given, called in /var/www/src/Foo.php on line 12'));
T::ok(!str_contains($typeErr, 'Foo.php') && !str_contains($typeErr, 'Argument'), 'erro de programação (TypeError) sem caminho nem detalhe');
T::ok($uf->invoke($dash, 'teste', new InvalidArgumentException('O nome da refeição é obrigatório.')) === 'O nome da refeição é obrigatório.', 'mensagem de validação escrita para pessoas é mantida');
$src = (string)file_get_contents("$root/src/Application/Controllers/DashboardController.php");
// Em todo catch genérico (Throwable, Exception, Error, RuntimeException, PDOException), $e->getMessage() só pode ir para o log.
$leaks = [];
if (preg_match_all('/catch\s*\(([^)]*)\$e\)\s*\{/', $src, $cm, PREG_OFFSET_CAPTURE)) {
    foreach ($cm[1] as $i => $c) {
        $types = array_map(static fn($t) => ltrim(trim($t), '\\'), explode('|', $c[0]));
        if (!array_intersect($types, ['Throwable', 'Exception', 'Error', 'RuntimeException', 'PDOException'])) continue;
        $from = $cm[0][$i][1]; $next = $cm[0][$i + 1][1] ?? strlen($src);
        $body = substr($src, $from, min($next - $from, 1500));
        $body = substr($body, 0, (int)(strpos($body, "\n        }") ?: strlen($body)));
        foreach (explode("\n", $body) as $ln) {
            if (str_contains($ln, '$e->getMessage()') && !str_contains($ln, 'error_log(') && !str_contains($ln, 'instanceof')) $leaks[] = (substr_count(substr($src, 0, $from), "\n") + 1) . ': ' . trim($ln);
        }
    }
}
T::ok($leaks === [], 'DashboardController: catch genérico não manda $e->getMessage() para a tela' . ($leaks ? ' — ' . implode(' | ', array_slice($leaks, 0, 4)) : ''));
if ($prevLog !== false) ini_set('error_log', (string)$prevLog);

echo "== (7) cabeçalho de página e ação principal\n";
ob_start();
try { $dash->mealPlans(); $h = (string)ob_get_clean(); } catch (\Throwable $e) { $h = (string)ob_get_clean(); T::ok(false, '/planos renderiza: ' . get_class($e)); }
T::ok(str_contains($h, 'data-testid="plans-page-header"') && str_contains($h, 'class="ds-page-header"'), '/planos usa ds-page-header');
preg_match('/<div class="ds-page-actions">(.*?)<\/div>/s', $h, $act);
T::ok(isset($act[1]) && substr_count($act[1], 'btn-primary') === 1 && str_contains($act[1], 'Novo plano alimentar') && str_contains($act[1], 'aria-haspopup="dialog"'), '/planos: uma única ação principal, "Novo plano alimentar", anunciada como janela');
T::ok(!preg_match('/\sstyle="/', $h), '/planos renderizado sem style=');
ob_start();
try { $dash->patients(); $h2 = (string)ob_get_clean(); } catch (\Throwable $e) { $h2 = (string)ob_get_clean(); T::ok(false, '/pacientes renderiza: ' . get_class($e)); }
T::ok(str_contains($h2, 'data-testid="patients-page-header"') && str_contains($h2, 'Paciente TESTE Alfa') && !str_contains($h2, 'Paciente TESTE Beta'), '/pacientes: cabeçalho DS e só pacientes da clínica A');
T::ok(!preg_match('/\sstyle="/', $h2), '/pacientes renderizado sem style=');
T::ok(str_contains($h2, 'v54.51'), 'rodapé mostra v54.51');

echo "== (8) isolamento entre clínicas\n";
$repo = new \ClyoNutri\Domain\Patient\PatientRepository();
T::ok($repo->getProfile360($pB) === null || $repo->getProfile360($pB) === false || $repo->getProfile360($pB) === [], 'clínica A não carrega o perfil do paciente da clínica B');
T::ok(!empty($repo->getProfile360($pA)), 'clínica A carrega o próprio paciente');

T::done();
