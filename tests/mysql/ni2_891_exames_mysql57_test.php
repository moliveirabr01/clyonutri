<?php
/**
 * NI2-891 (v54.76) no MySQL 5.7 real: php tests/mysql/ni2_891_exames_mysql57_test.php <porta> <banco_com_test_no_nome>
 * Bancos descartáveis: <banco> (homologação) e <banco>_prod (produção simulada SEM a migração 100).
 *  - produção: database/manual/ni2_891_exames_revisao_mysql57.sql 2 vezes (como no phpMyAdmin);
 *  - revisão real pelo serviço (CSV com linhas inválidas) no MySQL de homologação;
 *  - SQL gerado aplicado 2 vezes no "_prod": mesmo estado, sem linha nova; exames da clínica intocados.
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
$env = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_ENV' => 'local', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'CLINICAL_ENCRYPTION_KEY' => 'chave-teste-891', 'ALLOW_TESTS_ON_DEV_DB' => '1'];
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
// produção simulada: volta ao estado de antes da migração 100
$prod->exec("ALTER TABLE exam_catalog DROP COLUMN clinical_review_source, DROP COLUMN standard_code_note");
$prod->exec("DELETE FROM migrations WHERE migration = '2026_11_17_000100_ni2_891_exam_catalog_review'");
$prod->exec("INSERT INTO exam_catalog (code, canonical_name, short_name, category, sample_type, fasting_hours, nutritional_indication, synonyms, search_text, is_laboratorial, is_nutritional_scope, is_active, version, created_at, updated_at, clinic_id, origin, validation_status, clinical_review_status)
    SELECT 'CLINICA_TESTE_891', 'Exame da clínica TESTE', 'Clínica', 'Bioquímica', 'Soro', 0, 'TESTE', '', 'exame clinica teste', 1, 1, 1, 1, NOW(), NOW(), (SELECT MIN(id) FROM clinics), 'clinic_custom', 'approved', 'pending' FROM DUAL");
$manual = (string)file_get_contents($root . '/database/manual/ni2_891_exames_revisao_mysql57.sql');
[$r1, $o1] = $mysql($dbn . '_prod', $manual); [$r2, $o2] = $mysql($dbn . '_prod', $manual);
$ok($r1 === 0 && $r2 === 0 && preg_match('/"exames_sem_revisao":"?22"?/', $o2) === 1, 'SQL manual 100 roda 2x no MySQL 5.7 (22 sem revisão)' . ($r1 || $r2 ? ': ' . substr($o1 . $o2, -300) : ''));
$cols = (int)$prod->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='exam_catalog' AND COLUMN_NAME IN ('clinical_review_source','standard_code_note')")->fetchColumn();
$mig = (int)$prod->query("SELECT COUNT(*) FROM migrations WHERE migration = '2026_11_17_000100_ni2_891_exam_catalog_review'")->fetchColumn();
$ok($cols === 2 && $mig === 1, "colunas criadas ($cols) e migração registrada 1 vez ($mig)");
$svc = new \ClyoNutri\Domain\LaboratoryExam\ExamCatalogReviewService($db);
$ok($svc->ready() && $svc->stats()['pending'] === 22, 'homologação (migrate): pronto, 22 pendentes');
$sheet = "codigo_interno;sistema;codigo_padrao;justificativa_sem_codigo\nGLICEMIA_JEJUM;TUSS;40302040;\nVITAMINA_D_25OH;;;\"Justificativa TESTE com 'aspas' e ponto; vírgula\"\nFERRO_SERICO;TUSS;123;\n";
$r = $svc->importCsv($sheet, "Revisora TESTE — CRN-3 00000", 'Tabela TUSS TESTE', false, '2026-11-17 10:00:00');
$ok($r['applied'] === 2 && count($r['rejected']) === 1, 'importação no MySQL: 2 aplicadas, 1 rejeitada');
$sql = $svc->productionSql();
[$p1, $q1] = $mysql($dbn . '_prod', $sql);
$snap = static fn() => $prod->query("SELECT id, code, clinic_id, standard_code, standard_code_note, clinical_review_status, clinical_reviewed_at, clinical_review_source FROM exam_catalog ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$s1 = $snap(); $n1 = count($s1);
[$p2, $q2] = $mysql($dbn . '_prod', $sql);
$s2 = $snap();
$ok($p1 === 0 && $p2 === 0 && $s1 === $s2 && $n1 === count($s2), 'SQL de produção 2x no MySQL 5.7: mesmo estado, sem linha nova' . ($p1 || $p2 ? ': ' . substr($q1 . $q2, -300) : ''));
$vd = $prod->query("SELECT standard_code_note FROM exam_catalog WHERE code='VITAMINA_D_25OH' AND clinic_id IS NULL")->fetchColumn();
$ok($vd === "Justificativa TESTE com 'aspas' e ponto; vírgula", 'texto com aspas e ";" chega íntegro');
$cl = $prod->query("SELECT clinical_review_status, standard_code FROM exam_catalog WHERE clinic_id IS NOT NULL AND code='CLINICA_TESTE_891'")->fetch(PDO::FETCH_ASSOC);
$ok($cl && $cl['clinical_review_status'] === 'pending' && $cl['standard_code'] === null, 'exame da clínica não é alterado');
$ok(preg_match('/"exames_sem_revisao":"?20"?/', $q2) === 1, 'conferência final: 20 sem revisão');
echo "\nNI2-891 MySQL 5.7: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
