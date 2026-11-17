<?php
/**
 * NI2-886 (v54.76) no MySQL 5.7 real: php tests/mysql/ni2_886_medidas_mysql57_test.php <porta> <banco_com_test_no_nome> [cliente mysql]
 * Bancos descartáveis (recriados): <banco> (homologação, onde a revisão é feita) e <banco>_prod (produção simulada).
 *  - produção sem a migração 99: database/manual/ni2_886_medidas_caseiras_revisao_mysql57.sql pelo cliente mysql, 2 vezes;
 *  - revisão real pelo serviço (aprovar, corrigir, remover, CSV com linha inválida) no MySQL;
 *  - SQL gerado para produção aplicado 2 vezes no banco "_prod": peso anterior guardado, sem auditoria duplicada.
 */
declare(strict_types=1);
[$self, $port, $dbn, $client] = $argv + [null, '33057', null, '/workspace/mysql57/base/bin/mysql'];
if (!preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <porta> <banco com 'test'> [cliente mysql]\n"); exit(2); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
$admin = new PDO("mysql:host=127.0.0.1;port=$port", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ok(str_starts_with((string)$admin->query('SELECT VERSION()')->fetchColumn(), '5.7'), 'MySQL ' . $admin->query('SELECT VERSION()')->fetchColumn());
$env = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_ENV' => 'local', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'CLINICAL_ENCRYPTION_KEY' => 'chave-teste-886', 'ALLOW_TESTS_ON_DEV_DB' => '1'];
$sh = static function (array $cmd, array $extra = [], ?string $stdin = null) use ($root, $env): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + $extra + ['PATH' => getenv('PATH'), 'PHP_INI_SCAN_DIR' => (string)getenv('PHP_INI_SCAN_DIR'), 'LD_LIBRARY_PATH' => (string)getenv('LD_LIBRARY_PATH')]);
    if ($stdin !== null) { fwrite($pipes[0], $stdin); }
    fclose($pipes[0]);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $o];
};
// Como o phpMyAdmin: divide em comandos no ";" fora de aspas e executa em ordem, mostrando os SELECTs.
$mysql = static function (string $dbName, string $sql) use ($port): array {
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$dbName;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmts = []; $cur = ''; $q = null; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $ch = $sql[$i];
        if ($q === null && $ch === '-' && ($sql[$i + 1] ?? '') === '-') { while ($i < $n && $sql[$i] !== "\n") { $i++; } continue; }
        if ($q !== null) { $cur .= $ch; if ($ch === '\\') { $cur .= $sql[++$i] ?? ''; continue; } if ($ch === $q) { if (($sql[$i + 1] ?? '') === $q) { $cur .= $sql[++$i]; } else { $q = null; } } continue; }
        if ($ch === "'" || $ch === '"') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === ';') { if (trim($cur) !== '') { $stmts[] = trim($cur); } $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') { $stmts[] = trim($cur); }
    $out = '';
    try {
        foreach ($stmts as $st) { $r = $pdo->query($st); if ($r && $r->columnCount() > 0) { $out .= json_encode($r->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . "\n"; } }
        return [0, $out];
    } catch (\Throwable $e) { return [1, $out . $e->getMessage()]; }
};
foreach ([$dbn, $dbn . '_prod'] as $d) {
    $admin->exec("DROP DATABASE IF EXISTS `$d`");
    $admin->exec("CREATE DATABASE `$d` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    [$rc, $o] = $sh([PHP_BINARY, 'bin/migrate.php'], ['DB_DATABASE' => $d]);
    [$rc2] = $sh([PHP_BINARY, 'bin/seed.php'], ['DB_DATABASE' => $d]);
    [$rc3] = $sh([PHP_BINARY, 'bin/seed.php', '--demo'], ['DB_DATABASE' => $d]);
    $ok($rc === 0 && $rc2 === 0 && $rc3 === 0, "migrações + seed no MySQL 5.7 ($d)" . ($rc ? ': ' . substr($o, -300) : ''));
}
$db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prod = new PDO("mysql:host=127.0.0.1;port=$port;dbname={$dbn}_prod;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::ensureCommonMeasures($db);
\ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::ensureCommonMeasures($prod);
$cnt = static fn(PDO $p) => (int)$p->query("SELECT COUNT(*) FROM food_household_measures WHERE source LIKE '%revisar%'")->fetchColumn();
$initial = $cnt($db);
$ok($initial > 1000 && $initial === $cnt($prod), 'medidas estimadas no MySQL: ' . $initial);

// produção = esquema sem a migração 99
foreach (['review_status', 'reviewed_by', 'reviewer_name', 'reviewed_at', 'grams_before_review'] as $c) { $prod->exec("ALTER TABLE food_household_measures DROP COLUMN $c"); }
$prod->exec("DELETE FROM migrations WHERE migration = '2026_11_17_000099_ni2_886_household_measure_review'");
$manual = (string)file_get_contents($root . '/database/manual/ni2_886_medidas_caseiras_revisao_mysql57.sql');
[$r1, $o1] = $mysql($dbn . '_prod', $manual);
[$r2, $o2] = $mysql($dbn . '_prod', $manual);
$cols = (int)$prod->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('review_status','reviewed_by','reviewer_name','reviewed_at','grams_before_review')")->fetchColumn();
$reg = (int)$prod->query("SELECT COUNT(*) FROM migrations WHERE migration = '2026_11_17_000099_ni2_886_household_measure_review'")->fetchColumn();
$ok($r1 === 0 && $r2 === 0 && $cols === 5 && $reg === 1, 'SQL manual da migração 99: 2 execuções sem erro, 5 colunas, 1 registro em migrations' . ($r1 || $r2 ? ': ' . substr($o1 . $o2, -300) : ''));
$ok(str_contains($o2, 'medidas_estimadas_sem_revisao'), 'SQL manual mostra a contagem atual');

// revisão real no MySQL
\ClyoNutri\Core\Database\Connection::setInstance($db);
$svc = new \ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService($db);
$uid = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$rows = $svc->list(['status' => 'pending', 'order' => 'nome', 'limit' => 4]);
$svc->approve((int)$rows[0]['id'], $uid, 'Revisora TESTE MySQL', 'Pinheiro 5ª ed. TESTE');
$res = $svc->approve((int)$rows[1]['id'], $uid, 'Revisora TESTE MySQL', 'Pesagem própria TESTE', (float)$rows[1]['grams_equivalent'] + 7.5);
$svc->remove((int)$rows[2]['id'], $uid, 'Revisora TESTE MySQL', 'não usual TESTE');
$hdr = implode(';', \ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService::CSV_HEADER);
$csv = $hdr . "\n{$rows[3]['id']};{$rows[3]['food_id']};x;x;x;0;x;0;estimativa;corrigir;-3;Pesagem\n{$rows[3]['id']};{$rows[3]['food_id']};x;x;x;0;x;0;estimativa;corrigir;12,5;Pesagem TESTE\n";
$imp = $svc->importCsv($csv, $uid, 'Revisora TESTE MySQL');
$ok($imp['applied'] === 1 && array_column($imp['rejected'], 'linha') === [2], 'CSV no MySQL: linha inválida (-3 g) rejeitada sozinha, válida aplicada');
$st = $svc->stats();
$ok($st['approved'] === 3 && $st['removed'] === 1 && $st['pending'] === $initial - 4, "contagem no MySQL: 3 aprovadas, 1 removida, {$st['pending']} pendentes");
$aud = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE resource = 'food_household_measure' AND action LIKE 'HOUSEHOLD_MEASURE_%' AND action <> 'HOUSEHOLD_MEASURE_CSV_IMPORTED'")->fetchColumn();
$ok($aud === 4, 'audit_logs no MySQL: 4 registros de revisão');

// SQL para produção, 2 vezes
$sql = $svc->productionSql();
[$p1, $q1] = $mysql($dbn . '_prod', $sql);
$snap = static fn() => $prod->query("SELECT food_id, measure_name, grams_equivalent, grams_before_review, source, review_status, reviewer_name FROM food_household_measures WHERE review_status IS NOT NULL ORDER BY food_id, measure_name")->fetchAll(PDO::FETCH_ASSOC);
$a1 = $snap();
$audP1 = (int)$prod->query("SELECT COUNT(*) FROM audit_logs WHERE user_agent = 'SQL NI2-886'")->fetchColumn();
[$p2, $q2] = $mysql($dbn . '_prod', $sql);
$a2 = $snap();
$audP2 = (int)$prod->query("SELECT COUNT(*) FROM audit_logs WHERE user_agent = 'SQL NI2-886'")->fetchColumn();
$ok($p1 === 0 && $p2 === 0 && count($a1) === 4 && $a1 === $a2, 'SQL das revisões aplicado 2 vezes na produção simulada: 4 medidas, 2ª execução não muda nada' . ($p1 || $p2 ? ': ' . substr($q1 . $q2, -300) : ''));
$ok($audP1 === 4 && $audP2 === 4, 'auditoria em produção: 4 registros, sem duplicar na 2ª execução');
$corr = array_values(array_filter($a1, static fn($r) => $r['source'] === 'Pesagem própria TESTE'))[0] ?? [];
$ok(abs((float)($corr['grams_before_review'] ?? 0) - (float)$rows[1]['grams_equivalent']) < 0.01 && abs((float)($corr['grams_equivalent'] ?? 0) - ((float)$rows[1]['grams_equivalent'] + 7.5)) < 0.01, 'corrigida em produção: peso anterior guardado (' . ($corr['grams_before_review'] ?? '?') . ' → ' . ($corr['grams_equivalent'] ?? '?') . ')');
$pend = (int)$prod->query("SELECT COUNT(*) FROM food_household_measures WHERE source LIKE '%revisar%' AND review_status IS NULL")->fetchColumn();
$ok($pend === $initial - 4, "pendentes em produção após o SQL: {$pend} (4 revisadas saíram da fila)");
echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
