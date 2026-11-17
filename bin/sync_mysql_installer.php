<?php

declare(strict_types=1);

/**
 * NI2-867 (F-08): Sincroniza database/instalacao_mysql8.sql com as tabelas reais criadas pelas migrações.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$tmpDb = tempnam(sys_get_temp_dir(), 'clyo_schema_');
if ($tmpDb === false) {
    fwrite(STDERR, "Não foi possível criar arquivo temporário.\n");
    exit(1);
}
@unlink($tmpDb);
$sqliteFile = $tmpDb . '.sqlite';

try {
    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $migrator = new \ClyoNutri\Core\Database\Migrator($pdo, $root . '/database/migrations');
    $migrator->run();

    $sqlPath = $root . '/database/instalacao_mysql8.sql';
    $installerSql = (string)file_get_contents($sqlPath);
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', $installerSql, $m);
    $existing = array_flip(array_map('strtolower', $m[1]));

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC")
        ->fetchAll(PDO::FETCH_COLUMN);

    $appended = [];
    $blocks = [];
    foreach ($tables as $t) {
        $tl = strtolower((string)$t);
        if ($tl === 'migrations' || str_contains($tl, '__v') || str_ends_with($tl, '_new') || isset($existing[$tl])) {
            continue;
        }
        $cols = $pdo->query("PRAGMA table_info(\"{$t}\")")->fetchAll(PDO::FETCH_ASSOC);
        if ($cols === []) {
            continue;
        }
        $colDefs = [];
        $pks = [];
        foreach ($cols as $c) {
            $name = (string)$c['name'];
            $type = strtoupper((string)($c['type'] ?: 'TEXT'));
            $isPk = (int)$c['pk'] > 0;
            $notNull = (int)$c['notnull'] === 1 || $isPk;
            $dflt = $c['dflt_value'];

            $mysqlType = match (true) {
                $isPk && str_contains($type, 'INT') => 'INT UNSIGNED AUTO_INCREMENT',
                str_contains($type, 'INT') => 'BIGINT',
                str_contains($type, 'REAL'), str_contains($type, 'FLOAT'), str_contains($type, 'DOUBLE'), str_contains($type, 'DECIMAL') => 'DECIMAL(12,4)',
                str_contains($type, 'DATETIME'), str_contains($type, 'TIMESTAMP') => 'DATETIME',
                str_contains($type, 'DATE') => 'DATE',
                str_contains($type, 'VARCHAR') => $type,
                default => 'TEXT',
            };

            $line = "  `{$name}` {$mysqlType}";
            $line .= $notNull ? ' NOT NULL' : ' NULL';
            if ($dflt !== null && !$isPk) {
                $dfltStr = (string)$dflt;
                if (strcasecmp($dfltStr, 'CURRENT_TIMESTAMP') === 0) {
                    $line .= ($mysqlType === 'DATETIME') ? ' DEFAULT CURRENT_TIMESTAMP' : '';
                } elseif (is_numeric($dfltStr) || preg_match("/^'[^']*'$/", $dfltStr)) {
                    $line .= ' DEFAULT ' . $dfltStr;
                }
            }
            $colDefs[] = $line;
            if ($isPk) {
                $pks[] = "`{$name}`";
            }
        }
        if ($pks !== []) {
            $colDefs[] = '  PRIMARY KEY (' . implode(', ', $pks) . ')';
        }
        $blocks[] = "CREATE TABLE IF NOT EXISTS `{$t}` (\n" . implode(",\n", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $appended[] = $t;
    }

    if ($blocks !== []) {
        $addition = "\n\n-- NI2-867 (F-08): Tabelas sincronizadas a partir das migrações\n" . implode("\n\n", $blocks) . "\n";
        file_put_contents($sqlPath, $installerSql . $addition);
    }

    echo 'Sincronizadas ' . count($appended) . " tabela(s) em instalacao_mysql8.sql.\n";
} finally {
    @unlink($sqliteFile);
}
