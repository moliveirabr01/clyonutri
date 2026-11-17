<?php
// CR875-12 / NI2-889: nenhum texto de usuário com plural "(s)", "(es)", "(ns)" ou "(is)" em src, views e JS,
// e o helper UserText::count cobre 0, 1 e n. Varredura estática + execução real do helper.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
require $root . '/vendor/autoload.php';
use ClyoNutri\Application\View\UserText;
$ok(UserText::count(0, '{n} consulta', '{n} consultas', 'Nenhuma consulta') === 'Nenhuma consulta', 'zero');
$ok(UserText::count(1, '{n} consulta', '{n} consultas', 'Nenhuma consulta') === '1 consulta', 'um');
$ok(UserText::count(2, '{n} consulta', '{n} consultas', 'Nenhuma consulta') === '2 consultas', 'dois');
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (!preg_match('~^(src|views|public/assets/js)/.*\.(php|js)$~', $rel) || str_ends_with($rel, '.min.js')) continue;
    foreach (file($f->getPathname()) as $i => $line) {
        $t = ltrim($line);
        if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) continue;
        // palavra imediatamente seguida de (s)/(es)/(ns)/(is): "paciente(s)", "item(ns)", "profissional(is)"
        if (preg_match('~(?<![\w.$])[a-zà-ú]{2,}\((s|es|ns|is)\)(?!\s*[;{.]\s*$)~u', $line, $m) && !preg_match('~(?<![\w.$])[a-zà-ú]{2,}\((s|es|ns|is)\)\s*[;{]~u', $line)) $hits[] = "$rel:" . ($i + 1) . ' ' . $m[0];
    }
}
$ok($hits === [], 'nenhum plural "(s)" em texto de usuário' . ($hits ? ': ' . implode(', ', array_slice($hits, 0, 10)) : ''));
echo "\nCR875-12 plurais: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
