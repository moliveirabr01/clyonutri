<?php
// v54.34: perfil do paciente, seções finais no design system (sintético, só leitura de arquivos).
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; };
$css = file_get_contents("$root/public/assets/css/ds-pages.css");
$lon = file_get_contents("$root/views/patients/longitudinal.php");
$evo = file_get_contents("$root/views/patients/app-evolution.php");
$dash = file_get_contents("$root/src/Application/Controllers/DashboardController.php");
foreach (['.cn-profile-head', '.cn-profile-list', '.cn-profile-timeline', '.cn-profile-links', '.cn-profile-form', '.cn-fieldset', '.cn-check-row'] as $c) $ok(str_contains($css, $c . ' '), "ds-pages define $c");
$ok(str_contains($css, 'body:has(#secao-documentos) .op-context-panel { position: relative'), 'contexto clínico não fica sticky no perfil');
$ok(str_contains($css, 'scroll-margin-top'), 'âncoras com folga de rolagem');
$ok(substr_count($lon, 'class="cn-profile-head"') === 2, 'Abrir agenda e Abrir financeiro em cabeçalho de seção');
$ok(str_contains($lon, 'class="cn-profile-list" data-testid="profile-checkins"'), 'check-ins estilizados');
$ok(str_contains($lon, 'class="cn-profile-timeline"'), 'linha do tempo estilizada');
$ok(substr_count($lon, 'class="cn-profile-form"') >= 2 && substr_count($lon, 'cn-check-row') === 2, 'formulários LGPD no DS');
$ok(!preg_match('/<(ul|ol)>/', $lon), 'sem ul/ol crus no longitudinal');
$ok(substr_count($evo, 'cn-fieldset') === 2 && str_contains($evo, 'cn-check-row'), 'fieldsets da evolução no DS');
$ok(str_contains($dash, 'class="cn-profile-links" data-testid="profile-docs"'), 'documentos como links do DS');
$ok(!str_contains($dash, 'class="patient-docs"'), 'lista crua de documentos removida');
foreach ([$lon, $evo] as $src) { $t = tempnam(sys_get_temp_dir(), 'v'); file_put_contents($t, $src); exec('php -l ' . escapeshellarg($t) . ' 2>&1', $o, $rc); $ok($rc === 0, 'sintaxe PHP ok'); unlink($t); }
echo "\n" . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
