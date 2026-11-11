#!/usr/bin/env php
<?php
declare(strict_types=1);
/** NI2-717: dump SQLite quando a CLI sqlite3 não está disponível. Uso: php bin/sqlite_dump.php <db> <out.sql> */
if ($argc < 3) {
    fwrite(STDERR, "uso: php bin/sqlite_dump.php <db.sqlite> <out.sql>\n");
    exit(1);
}
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$out = fopen($argv[2], 'w');
if ($out === false) {
    fwrite(STDERR, "não foi possível escrever {$argv[2]}\n");
    exit(1);
}
fwrite($out, "BEGIN TRANSACTION;\n");
foreach ($db->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name") as $r) {
    fwrite($out, $r['sql'] . ";\n");
}
foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) {
    $name = (string)$t['name'];
    $qName = '"' . str_replace('"', '""', $name) . '"';
    foreach ($db->query("SELECT * FROM {$qName}") as $row) {
        $cols = [];
        $vals = [];
        foreach ($row as $c => $v) {
            $cols[] = '"' . str_replace('"', '""', (string)$c) . '"';
            $vals[] = $v === null ? 'NULL' : $db->quote((string)$v);
        }
        fwrite($out, "INSERT INTO {$qName} (" . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
    }
}
fwrite($out, "COMMIT;\n");
fclose($out);
