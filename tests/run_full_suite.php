<?php
/**
 * NI2-884 — suíte completa num comando: `bin/run-tests full`.
 * Cada arquivo tests/*.php (exceto helpers "_*" e os do manifesto) roda isolado, com asserções ligadas,
 * num SQLite novo (cópia de um modelo migrado + seed base + seed --demo), APP_ENV=testing e fuso de São Paulo. Nada usa o banco do .env.
 * Saída: tabela por arquivo, resumo e storage/test-reports/full-<data>.tsv. Código de saída 1 se algo falhar.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$timeout = (int)(getenv('FULL_SUITE_TIMEOUT') ?: 180);
$only = $argv[1] ?? null;
// "@arquivo": roda só a lista do arquivo (ex.: tests/core_modules.txt, usado pelo gate core); ignora known_failures.
$list = null;
if ($only !== null && str_starts_with($only, '@')) {
    $list = [];
    foreach (file($root . '/' . substr($only, 1), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') $list[$line] = true;
    }
    $only = null;
}

$manifest = [];
foreach (file($root . '/tests/suite_manifest.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#') continue;
    [$f, $cat, $why] = array_pad(explode("\t", $line), 3, '');
    $manifest[$f] = ['cat' => $cat, 'why' => $why];
}
$known = [];
foreach (file($root . '/tests/known_failures_full.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#') continue;
    [$f, $ref] = array_pad(explode("\t", $line), 2, '');
    $known[$f] = $ref;
}

$files = glob($root . '/tests/*.php') ?: [];
sort($files);
$tmp = sys_get_temp_dir() . '/clyonutri-full-' . getmypid();
@mkdir($tmp, 0700, true);
$rows = [];
$t0 = microtime(true);

// Modelo único do banco de teste: as suítes legadas presumem a base da instalação (planos, catálogo) e as
// contas demo (usuário 1 = Dra. Camila, clínica 1). Preparado uma vez e copiado para cada arquivo.
$template = $tmp . '/_modelo.sqlite';
@unlink($template);
$baseEnv = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $template, 'APP_ENV' => 'testing', 'APP_TIMEZONE' => 'America/Sao_Paulo',
    'CLINICAL_ENCRYPTION_KEY' => 'test-clinical-key-full-suite-not-for-prod', 'MAIL_DRIVER' => 'log',
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp'];
foreach ([['bin/migrate.php'], ['bin/seed.php'], ['bin/seed.php', '--demo'], ['tests/_full_suite_base_fixture.php']] as $cmd) {
    $p = proc_open(array_merge([PHP_BINARY], $cmd), [1 => ['file', $tmp . '/_modelo.log', 'a'], 2 => ['file', $tmp . '/_modelo.log', 'a']], $pipes, $root, $baseEnv);
    if (proc_close($p) !== 0 || !is_file($template)) {
        fwrite(STDERR, "Falha ao preparar o banco modelo ({$cmd[0]}); ver {$tmp}/_modelo.log\n");
        exit(2);
    }
}
$count = ['PASS' => 0, 'FAIL' => 0, 'KNOWN' => 0, 'NAO_EXECUTADO' => 0];

if ($list !== null) {
    foreach (array_keys($list) as $want) {
        if (!is_file($root . '/' . $want)) { fwrite(STDERR, "ERRO: arquivo de teste ausente: {$want}\n"); exit(1); }
    }
}
foreach ($files as $abs) {
    $rel = 'tests/' . basename($abs);
    if (str_starts_with(basename($abs), '_') || $rel === 'tests/run_full_suite.php') continue;
    if ($only !== null && !str_contains($rel, $only)) continue;
    if ($list !== null && !isset($list[$rel])) continue;
    $m = $manifest[$rel] ?? null;
    $dbDir = $tmp;
    if ($m !== null && !str_starts_with($m['cat'], 'dbprefix:')) {
        $rows[] = [$rel, 'NAO_EXECUTADO', 0.0, $m['cat'] . ': ' . $m['why']];
        $count['NAO_EXECUTADO']++;
        continue;
    }
    $name = preg_replace('/[^a-z0-9_]/i', '_', basename($abs, '.php'));
    $db = ($m !== null ? substr($m['cat'], strlen('dbprefix:')) . $name : $dbDir . '/' . $name) . '.sqlite';
    @mkdir(dirname($db), 0700, true);
    @unlink($db);
    $env = [
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'TEST_DB_DATABASE' => $db, 'APP_ENV' => 'testing',
        'APP_TIMEZONE' => 'America/Sao_Paulo', 'CLINICAL_ENCRYPTION_KEY' => 'test-clinical-key-full-suite-not-for-prod',
        'MAIL_DRIVER' => 'log', 'MAIL_LOG_PATH' => $dbDir . '/' . $name . '-mail.log',
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp',
    ];
    // Banco novo copiado do modelo (migrado + seed base + seed --demo): o "banco de teste vazio" da v54.67 não se repete.
    copy($template, $db);
    $s = microtime(true);
    $out = $dbDir . '/' . $name . '.out';
    $p = proc_open(['timeout', (string)$timeout, PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', '-d', 'memory_limit=1G', $rel],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes, $root, $env);
    $rc = proc_close($p);
    $dt = microtime(true) - $s;
    $text = (string)@file_get_contents($out);
    $first = '';
    if (preg_match('/^.*(Fatal error|Uncaught|\bFAIL\b|AssertionError).*$/m', $text, $mm)) $first = trim(mb_substr($mm[0], 0, 220));
    if ($rc === 0) { $st = 'PASS'; }
    elseif ($list === null && isset($known[$rel])) { $st = 'KNOWN'; $first = $known[$rel] . ' · ' . $first; }
    else { $st = 'FAIL'; }
    if ($rc === 124) $first = "timeout {$timeout}s · " . $first;
    $count[$st]++;
    $rows[] = [$rel, $st, $dt, $first];
    printf("%-14s %6.1fs  %s%s\n", $st, $dt, $rel, $first !== '' && $st !== 'PASS' ? "\n               ↳ " . $first : '');
    @unlink($db);
}
$total = microtime(true) - $t0;
@mkdir($root . '/storage/test-reports', 0775, true);
$report = $root . '/storage/test-reports/full-' . date('Ymd-His') . '.tsv';
$fh = fopen($report, 'w');
fwrite($fh, "arquivo\tresultado\tsegundos\tdetalhe\n");
foreach ($rows as $r) fwrite($fh, sprintf("%s\t%s\t%.1f\t%s\n", $r[0], $r[1], $r[2], str_replace(["\t", "\n"], ' ', (string)$r[3])));
fclose($fh);
foreach ($rows as $r) if ($r[1] === 'NAO_EXECUTADO') printf("NAO_EXECUTADO          %s — %s\n", $r[0], $r[3]);
printf("\nResumo bin/run-tests [full]: %d PASS, %d FAIL, %d falha conhecida (triada em tests/known_failures_full.tsv), %d não executado (infra/fixture/wrapper). Tempo total: %.0fs. Relatório: %s\n",
    $count['PASS'], $count['FAIL'], $count['KNOWN'], $count['NAO_EXECUTADO'], $total, $report);
exit(($count['FAIL'] + $count['KNOWN']) > 0 ? 1 : 0);
