<?php
/**
 * v54.46 / CR-NI2-008 (ADR-0008): compara tabelas do instalador MySQL (database/instalacao_mysql8.sql)
 * com as tabelas criadas pelas migrações (database/migrations/*.php). Somente leitura; não toca banco.
 *   php bin/compare_migrations_schema.php        # sai 0 se convergente, 1 se divergente
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$sql = (string)@file_get_contents($root . '/database/instalacao_mysql8.sql');
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', $sql, $m);
$installer = array_unique(array_map('strtolower', $m[1]));
$migr = [];
foreach (glob($root . '/database/migrations/*.php') ?: [] as $f) {
    $c = (string)file_get_contents($f);
    if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', $c, $mm)) {
        foreach ($mm[1] as $t) { $migr[strtolower($t)][] = basename($f); }
    }
    if (preg_match_all('/Schema::create\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $c, $mm)) {
        foreach ($mm[1] as $t) { $migr[strtolower($t)][] = basename($f); }
    }
}
$onlyInstaller = array_values(array_diff($installer, array_keys($migr)));
$onlyMigr = array_values(array_diff(array_keys($migr), $installer));
sort($onlyInstaller); sort($onlyMigr);
echo 'Tabelas no instalador: ' . count($installer) . ' | nas migrações: ' . count($migr) . "\n";
echo "Só no instalador (" . count($onlyInstaller) . "): " . implode(', ', $onlyInstaller) . "\n";
echo "Só nas migrações (" . count($onlyMigr) . "): " . implode(', ', $onlyMigr) . "\n";
exit(($onlyInstaller || $onlyMigr) ? 1 : 0);
