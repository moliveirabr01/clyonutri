<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // v10: script só de linha de comando
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');

echo "== ClyoNutri Migration Runner ==\n";
try {
    $db = Connection::getInstance();
    $migrationsDir = __DIR__ . '/../database/migrations';
    $migrator = new Migrator($db, $migrationsDir);
    $executed = $migrator->run();

    if (empty($executed)) {
        echo "Nenhuma migração pendente. Banco de dados atualizado.\n";
    } else {
        echo "Migrações executadas com sucesso (" . count($executed) . "):\n";
        foreach ($executed as $m) {
            echo " - {$m}\n";
        }
    }
} catch (\Throwable $e) {
    echo "ERRO ao executar migrações: " . $e->getMessage() . "\n";
    exit(1);
}
