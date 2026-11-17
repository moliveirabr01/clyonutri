<?php

declare(strict_types=1);

/**
 * v54.67 / NI2-867 (F-08): compara tabelas do instalador MySQL (database/instalacao_mysql8.sql)
 * com as tabelas reais criadas pela execução completa das migrações (database/migrations/*.php).
 *   php bin/compare_migrations_schema.php        # sai 0 se convergente, 1 se divergente
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$sql = (string)@file_get_contents($root . '/database/instalacao_mysql8.sql');
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', $sql, $m);
$installer = array_values(array_diff(array_unique(array_map('strtolower', $m[1])), ['migrations']));

$tmpDb = tempnam(sys_get_temp_dir(), 'clyo_cmp_');
@unlink((string)$tmpDb);
$sqliteFile = $tmpDb . '.sqlite';
$migrTables = [];
try {
    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new \ClyoNutri\Core\Database\Migrator($pdo, $root . '/database/migrations'))->run();
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $t) {
        $tl = strtolower((string)$t);
        if ($tl === 'migrations' || str_contains($tl, '__v') || str_ends_with($tl, '_new')) {
            continue;
        }
        $migrTables[] = $tl;
    }
} finally {
    @unlink($sqliteFile);
}
$migrTables = array_values(array_unique($migrTables));

$onlyInstaller = array_values(array_diff($installer, $migrTables));
$onlyMigr = array_values(array_diff($migrTables, $installer));
sort($onlyInstaller);
sort($onlyMigr);

echo 'Tabelas no instalador: ' . count($installer) . ' | nas migrações: ' . count($migrTables) . "\n";
echo 'Só no instalador (' . count($onlyInstaller) . '): ' . implode(', ', $onlyInstaller) . "\n";
echo 'Só nas migrações (' . count($onlyMigr) . '): ' . implode(', ', $onlyMigr) . "\n";
exit(($onlyInstaller || $onlyMigr) ? 1 : 0);
