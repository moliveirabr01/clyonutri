<?php
// NI2-233: semeia o banco MySQL descartável da homologação (php tests/mysql/ni2_233_e2e_seed.php <socket> <banco_com_test_no_nome>).
// O banco deve estar com o esquema completo (migração 84). Só dados TESTE; recusa banco sem "test" no nome.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/homologacao/ni2_233_fixture.php';
[$self, $sock, $dbn] = $argv + [null, null, null];
if (!$sock || !preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <socket> <banco com 'test'>\n"); exit(2); }
date_default_timezone_set('America/Sao_Paulo');
$pdo = new PDO("mysql:unix_socket=$sock;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
echo json_encode(ni2_233_fixture($pdo), JSON_UNESCAPED_UNICODE), "\n";
