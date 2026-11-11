<?php
// NI2-233: leitura do banco do cenário (só SELECT, com parâmetros). Serve para SQLite e para MySQL de teste.
// Uso: php sqm.php <arquivo.sqlite | mysql:<socket>:<banco_com_test>> "<SELECT ...>" [param ...]
declare(strict_types=1);
[$self, $target, $sql] = $argv + [null, null, null];
$params = array_slice($argv, 3);
if (!$target || !$sql || !preg_match('/^\s*SELECT\s/i', $sql) || str_contains($sql, ';')) { fwrite(STDERR, "uso: sqm.php <alvo> \"SELECT ...\" [params]\n"); exit(2); }
if (str_starts_with($target, 'mysql:')) {
    [, $sock, $db] = explode(':', $target, 3) + [null, null, null];
    if (!$sock || !preg_match('/test/i', (string)$db)) { fwrite(STDERR, "banco MySQL sem 'test' no nome: recusado\n"); exit(2); }
    $pdo = new PDO("mysql:unix_socket=$sock;dbname=$db;charset=utf8mb4", 'root', '');
} elseif (str_ends_with($target, '.sqlite')) {
    $pdo = new PDO('sqlite:' . $target);
} else { fwrite(STDERR, "alvo inválido\n"); exit(2); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$st = $pdo->prepare($sql);
$st->execute($params);
foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) echo implode('|', array_map(static fn($v) => $v === null ? '' : (string)$v, $r)), "\n";
