<?php
/**
 * CR875-15 (v54.76): cada SQL manual da sequência de produção da v54.76 registra a própria migração em `migrations`
 * (sem duplicar) e aponta para um arquivo de migração que existe. Sem isso o executor de migrações considera a
 * migração pendente depois do deploy (foi o caso da 097 / NI2-878 até a v54.75).
 * A prova em banco real é tests/mysql/cr875_15_sql_manual_v5476_mysql57_test.php (MySQL 5.7.44).
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$seq = [
    '2026_11_07_000091_create_production_events_table' => 'atualizacao-v54-52-ni2-767-production-events.sql',
    '2026_11_16_000097_ni2_878_subscription_activation' => 'ni2_878_subscription_activation_mysql57.sql',
    '2026_11_17_000098_ni2_885_patients_allergies_text' => 'ni2_885_patients_allergies_text_mysql57.sql',
    '2026_11_17_000099_ni2_886_household_measure_review' => 'ni2_886_medidas_caseiras_revisao_mysql57.sql',
    '2026_11_17_000100_ni2_891_exam_catalog_review' => 'ni2_891_exames_revisao_mysql57.sql',
    '2026_11_17_000101_cr875_14_password_reset_purpose' => 'cr875_14_password_reset_purpose_mysql57.sql',
];
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
foreach ($seq as $mig => $file) {
    $sql = (string)@file_get_contents("$root/database/manual/$file");
    $ok($sql !== '', "$file existe");
    $ok(is_file("$root/database/migrations/$mig.php"), "migração $mig existe");
    $code = preg_replace('/^\s*--.*$/m', '', $sql);
    $q = preg_quote($mig, '/');
    $ok((bool)preg_match("/INSERT\s+INTO\s+`?migrations`?\s*\(\s*`?migration`?[^;]*'$q'[^;]*WHERE\s+NOT\s+EXISTS\s*\([^;]*'$q'/is", $code), "$file registra $mig uma vez (INSERT … WHERE NOT EXISTS)");
}
// A última migração do repositório tem SQL manual nesta sequência (nada novo esquecido fora dela).
$files = glob("$root/database/migrations/*.php"); sort($files);
$ok(basename((string)end($files), '.php') === array_key_last($seq), 'a última migração do repositório está na sequência de SQL manual da v54.76');
echo "\nCR875-15 registro das migrações nos SQL manuais: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
