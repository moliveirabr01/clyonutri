<?php

declare(strict_types=1);

/**
 * Suíte Automatizada — NI2-519 e NI2-520 v44:
 * - Indicação explícita de correspondência por sinônimo na busca ("aipim = mandioca", "jerimum = abóbora", "bergamota = tangerina")
 * - Desempate por intenção de preparo (alimentos prontos para consumo sobem quando a busca não cita preparo)
 * - Sincronização de rotas da NI2-514 entre public/index.php e routes/web.php (B1)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodSearchIndex;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);
(new Migrator($db, __DIR__ . '/../database/migrations'))->run();

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=== NI2-519 / NI2-520 v44 — Correspondência por Sinônimo, Intenção de Preparo e Rotas (B1) ===\n";

// 1. FoodSearchIndex::synonymHits e synonymLabel
$termsAipim = FoodSearchIndex::terms('aipim cozido');
check(FoodSearchIndex::synonymLabel($termsAipim, 'Mandioca, cozida') === 'aipim = mandioca', 'Exibe "aipim = mandioca" quando o nome do alimento traz Mandioca');
check(FoodSearchIndex::synonymLabel($termsAipim, 'Bolo de aipim') === null, 'Não exibe aviso de sinônimo quando o próprio termo digitado já está no nome');

$termsJerimum = FoodSearchIndex::terms('jerimum');
check(FoodSearchIndex::synonymLabel($termsJerimum, 'Abóbora, cabotian, cozida') === 'jerimum = abóbora', 'Exibe "jerimum = abóbora" com acentuação correta');

$termsBergamota = FoodSearchIndex::terms('bergamota');
check(FoodSearchIndex::synonymLabel($termsBergamota, 'Tangerina, Poncã, crua') === 'bergamota = tangerina', 'Exibe "bergamota = tangerina" para busca regional sulista');

// 2. Ordenação por intenção de preparo (cozido/grelhado antes de cru quando a busca não pede preparo)
$db->exec("INSERT INTO foods (id, clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, source, is_current) VALUES
    (801, NULL, 'Mandioca, crua', 'Verduras, hortaliças e derivados', 151, 1.1, 36.2, 0.3, 1.9, 'TACO', 1),
    (802, NULL, 'Mandioca, cozida', 'Verduras, hortaliças e derivados', 125, 0.6, 30.1, 0.3, 1.6, 'TACO', 1)");
FoodSearchIndex::sync($db);

$qNoPrep = FoodSearchIndex::sql(FoodSearchIndex::terms('aipim'), 'f.search_text', 'f.name', $db, ['synonyms' => true]);
$st = $db->prepare("SELECT f.name FROM foods f WHERE {$qNoPrep['where']} ORDER BY {$qNoPrep['order']}");
$st->execute($qNoPrep['where_params'] + $qNoPrep['order_params']);
$namesNoPrep = $st->fetchAll(PDO::FETCH_COLUMN);
check(($namesNoPrep[0] ?? '') === 'Mandioca, cozida', 'Busca por "aipim" sem preparo posiciona "Mandioca, cozida" antes de "Mandioca, crua"');

$qWithPrep = FoodSearchIndex::sql(FoodSearchIndex::terms('aipim cru'), 'f.search_text', 'f.name', $db, ['synonyms' => true]);
$st2 = $db->prepare("SELECT f.name FROM foods f WHERE {$qWithPrep['where']} ORDER BY {$qWithPrep['order']}");
$st2->execute($qWithPrep['where_params'] + $qWithPrep['order_params']);
$namesWithPrep = $st2->fetchAll(PDO::FETCH_COLUMN);
check(count($namesWithPrep) === 1 && $namesWithPrep[0] === 'Mandioca, crua', 'Busca por "aipim cru" filtra estritamente a versão crua');

// 3. B1: Verificar que todas as rotas da NI2-514 estão sincronizadas entre public/index.php e routes/web.php
$indexContent = (string)file_get_contents(__DIR__ . '/../public/index.php');
$webContent = (string)file_get_contents(__DIR__ . '/../routes/web.php');

$requiredRoutes = [
    '/pacientes/importar',
    '/pacientes/importar/modelo.csv',
    '/pacientes/importar/previa',
    '/pacientes/importar/{id}',
    '/pacientes/importar/{id}/confirmar',
    '/pacientes/importar/{id}/descartar',
    '/pacientes/importar/{id}/desfazer',
    '/alimentos/equivalencias',
    '/alimentos/buscas',
    '/medidas-caseiras',
    '/medidas-caseiras/modelo.csv',
    '/medidas-caseiras/importar',
    '/planos/{id}/energia',
    '/planos/{id}/paciente',
    '/planos/{id}/refeicoes/{mealId}/editar',
    '/planos/{id}/salvar-como-modelo',
    '/modelos',
    '/modelos/{id}/usar',
    '/modelos/{id}/arquivar',
    '/modelos/{id}/padrao',
    '/modelos/{id}/aprovacao',
    '/modelos/{id}/visibilidade',
];

foreach ($requiredRoutes as $route) {
    check(
        str_contains($indexContent, "'{$route}'") && str_contains($webContent, "'{$route}'"),
        "B1: Rota {$route} registrada tanto em public/index.php quanto em routes/web.php"
    );
}

echo "\nResultado NI2-519/520 v44: {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
