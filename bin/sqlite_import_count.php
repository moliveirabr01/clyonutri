#!/usr/bin/env php
<?php
declare(strict_types=1);
/** NI2-717: importa SQL em SQLite de ensaio e imprime contagem de tabelas. */
if ($argc < 3) {
    fwrite(STDERR, "uso: php bin/sqlite_import_count.php <db.sqlite> <in.sql>\n");
    exit(1);
}
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents($argv[2]);
if ($sql === false) {
    fwrite(STDERR, "SQL ilegível\n");
    exit(1);
}
$db->exec($sql);
echo (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
