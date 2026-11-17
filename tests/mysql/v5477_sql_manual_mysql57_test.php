<?php
/**
 * v54.77 no MySQL 5.7 real: SQL manual em ordem (091, 097, 098, 099, 100, 101, 102), 2 vezes, como no phpMyAdmin.
 * Igual ao teste da v54.76 (CR875-15) + a 102 (RT dos catálogos): grava o nome Raphael Lombardi, CRN/e-mail vazios,
 * e a 2ª rodada não apaga um CRN já preenchido.
 *   php tests/mysql/v5477_sql_manual_mysql57_test.php <porta> <banco_com_test> [dump_phpmyadmin.sql]
 * Sem dump: banco novo pelo bin/migrate.php e "produção v54.75" simulada (objetos e registros de 091 e 097–101 removidos).
 * Com dump (ex.: cópia do banco de produção, NUNCA versionada): importa o dump (FKs desligadas, como no phpMyAdmin),
 * aplica a v54.63 completa e a v54.68, remove a 091 (ainda ausente em produção) e segue igual.
 * Confere: nenhum erro, esquema idêntico depois da 2ª rodada, cada migração registrada 1 vez e NADA pendente para o
 * executor de migrações (antes desta correção a 097 ficava pendente: o SQL manual não a registrava).
 */
declare(strict_types=1);
[$self, $port, $dbn, $dump] = $argv + [null, '33057', null, null];
if (!preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <porta> <banco com 'test'> [dump.sql]\n"); exit(2); }
$root = dirname(__DIR__, 2); $M = "$root/database/manual";
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
$split = static function (string $sql): array {
    $stmts = []; $cur = ''; $q = null; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $ch = $sql[$i];
        if ($q !== null) { $cur .= $ch; if ($ch === '\\' && $q !== '`') { $cur .= $sql[++$i] ?? ''; continue; } if ($ch === $q) { if (($sql[$i + 1] ?? '') === $q) { $cur .= $sql[++$i]; } else { $q = null; } } continue; }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\n", "\t", "\r"], true)) { while ($i < $n && $sql[$i] !== "\n") { $i++; } $cur .= "\n"; continue; }
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') { $j = strpos($sql, '*/', $i + 2); $i = $j === false ? $n : $j + 1; continue; }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === ';') { if (trim($cur) !== '') { $stmts[] = trim($cur); } $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') { $stmts[] = trim($cur); }
    return $stmts;
};
$conn = static fn() => new PDO("mysql:host=127.0.0.1;port=$port;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
$run = static function (PDO $db, string $file) use ($split): array {
    $out = '';
    foreach ($split((string)file_get_contents($file)) as $k => $st) {
        try { $r = $db->query($st); if ($r && $r->columnCount() > 0) { $out .= json_encode($r->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . "\n"; } if ($r) { $r->closeCursor(); } }
        catch (\Throwable $e) { return [1, "comando " . ($k + 1) . ': ' . $e->getMessage()]; }
    }
    return [0, $out];
};
$admin = new PDO("mysql:host=127.0.0.1;port=$port", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ok(str_starts_with((string)$admin->query('SELECT VERSION()')->fetchColumn(), '5.7'), 'MySQL ' . $admin->query('SELECT VERSION()')->fetchColumn());
$admin->exec("DROP DATABASE IF EXISTS `$dbn`");
$admin->exec("CREATE DATABASE `$dbn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
if ($dump !== null) {
    $db = $conn(); $db->exec('SET FOREIGN_KEY_CHECKS=0'); [$rc, $o] = $run($db, $dump); $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $ok($rc === 0, 'dump importado' . ($rc ? ": $o" : ''));
    foreach (['atualizacao-v54-63-completa.sql', 'atualizacao-v54-68-completa.sql'] as $f) { [$rc, $o] = $run($conn(), "$M/$f"); $ok($rc === 0, "pré-estado: $f" . ($rc ? ": $o" : '')); }
    $db = $conn();
    $db->exec('DROP TABLE IF EXISTS production_alerts'); $db->exec('DROP TABLE IF EXISTS production_events');
    $db->exec("DELETE FROM migrations WHERE migration LIKE '2026_11_07_000091%'");
} else {
    $env = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_DATABASE' => $dbn, 'APP_ENV' => 'local', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'PATH' => (string)getenv('PATH'), 'PHP_INI_SCAN_DIR' => (string)getenv('PHP_INI_SCAN_DIR'), 'LD_LIBRARY_PATH' => (string)getenv('LD_LIBRARY_PATH')];
    $p = proc_open([PHP_BINARY, 'bin/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); $rc = proc_close($p);
    $ok($rc === 0, 'bin/migrate.php no banco novo' . ($rc ? ': ' . substr($o, -300) : ''));
    $db = $conn();
    foreach (['DROP TABLE IF EXISTS production_alerts', 'DROP TABLE IF EXISTS production_events', 'DROP TABLE IF EXISTS subscription_activation_requests',
              'ALTER TABLE patients MODIFY allergies VARCHAR(500) NULL',
              'ALTER TABLE food_household_measures DROP COLUMN review_status, DROP COLUMN reviewed_by, DROP COLUMN reviewer_name, DROP COLUMN reviewed_at, DROP COLUMN grams_before_review',
              'ALTER TABLE exam_catalog DROP COLUMN clinical_review_source, DROP COLUMN standard_code_note',
              'ALTER TABLE password_resets DROP COLUMN purpose',
              "DELETE FROM platform_settings WHERE setting_key LIKE 'catalog_rt_%'",
              "DELETE FROM migrations WHERE migration LIKE '2026_11_07_000091%' OR migration LIKE '2026_11_16_000097%' OR migration LIKE '2026_11_17_0000%' OR migration LIKE '2026_11_17_0001%'"] as $st) { $db->exec($st); }
    $ok(true, 'produção v54.75 simulada: sem 091 e sem 097–102');
}
$seq = ['091' => 'atualizacao-v54-52-ni2-767-production-events.sql', '097' => 'ni2_878_subscription_activation_mysql57.sql', '098' => 'ni2_885_patients_allergies_text_mysql57.sql',
        '099' => 'ni2_886_medidas_caseiras_revisao_mysql57.sql', '100' => 'ni2_891_exames_revisao_mysql57.sql', '101' => 'cr875_14_password_reset_purpose_mysql57.sql',
        '102' => 'v54_77_rt_catalogos_mysql57.sql'];
$snap = static function (PDO $db): string {
    $c = $db->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1,2")->fetchAll(PDO::FETCH_NUM);
    $i = $db->query("SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() GROUP BY 1,2 ORDER BY 1,2")->fetchAll(PDO::FETCH_NUM);
    return md5(json_encode([$c, $i, $db->query('SELECT COUNT(*) FROM migrations')->fetchColumn()]));
};
$s = [];
foreach ([1, 2] as $round) {
    foreach ($seq as $k => $f) { [$rc, $o] = $run($conn(), "$M/$f"); $ok($rc === 0, "rodada $round: SQL $k ($f)" . ($rc ? ": $o" : '')); }
    $s[$round] = $snap($conn());
    $rt = $conn()->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'catalog_rt_%' ORDER BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($round === 1) {
        $ok($rt === ['catalog_rt_crn' => '', 'catalog_rt_email' => '', 'catalog_rt_name' => 'Raphael Lombardi'], 'rodada 1: SQL 102 grava RT = Raphael Lombardi, CRN e e-mail vazios');
        $conn()->exec("UPDATE platform_settings SET setting_value = 'CRN-9 99999' WHERE setting_key = 'catalog_rt_crn'"); // CRN fictício de TESTE
    } else {
        $ok(($rt['catalog_rt_crn'] ?? '') === 'CRN-9 99999' && count($rt) === 3, 'rodada 2: SQL 102 não apaga o CRN preenchido nem duplica linhas');
    }
}
$ok($s[1] === $s[2], 'esquema e registros de migração idênticos depois da 2ª rodada');
$db = $conn();
$ok($db->query('SELECT COUNT(*) FROM (SELECT migration FROM migrations GROUP BY migration HAVING COUNT(*) > 1) d')->fetchColumn() == 0, 'nenhuma migração registrada 2 vezes');
$reg = array_column($db->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_ASSOC), 'migration');
$pending = array_values(array_diff(array_map(static fn($p) => basename($p, '.php'), glob("$root/database/migrations/*.php")), $reg));
$ok($pending === [], 'nada pendente para o executor de migrações' . ($pending ? ': ' . implode(', ', $pending) : ''));
$admin->exec("DROP DATABASE IF EXISTS `$dbn`");
echo "\nv54.77 SQL manual no MySQL 5.7: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
