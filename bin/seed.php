<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // v10: script só de linha de comando
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\CatalogSeeder;
use ClyoNutri\Core\Database\ExampleRecipeCatalogSeeder;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\DemoSeeder;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');

$isDemo = in_array('--demo', $argv ?? [], true);

echo "========================================================================\n";
echo "   CLYONUTRI II — DATABASE SEED RUNNER\n";
echo "========================================================================\n";

try {
    $db = Connection::getInstance();

    if ($isDemo) {
        echo "Modo Solicitado: SEED DE DEMONSTRAÇÃO (--demo)\n";
        $seeder = new DemoSeeder($db);
        $result = $seeder->run();
        echo "✓ DemoSeeder executado com sucesso no ambiente [{$result['env']}].\n";
        if (!empty($result['created'])) {
            echo "  Contas criadas: " . implode(', ', $result['created']) . "\n";
        } else {
            echo "  Contas demo já existentes.\n";
        }
    } else {
        echo "Modo Solicitado: CATÁLOGO DE REFERÊNCIA NUTRICIONAL (TACO)\n";
        $catalog = new CatalogSeeder($db);
        $count = $catalog->run();
        $recipes = (new ExampleRecipeCatalogSeeder($db))->run();
        echo "✓ Catálogo público: {$recipes} receitas de exemplo adicionadas.\n";
        echo "✓ CatalogSeeder executado com sucesso: {$count} alimentos inseridos/atualizados.\n";
    }

    echo "========================================================================\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n❌ ERRO NA EXECUÇÃO DO SEEDER: " . $e->getMessage() . "\n";
    echo "========================================================================\n";
    exit(1);
}
