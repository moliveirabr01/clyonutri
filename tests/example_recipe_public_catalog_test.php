<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Database\ExampleRecipeCatalogSeeder;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Recipe\RecipeService;
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::setInstance($db);
(new Migrator($db, __DIR__ . '/../database/migrations'))->run();
(new FoodCatalogService($db))->seedTacoReference();
function verifyCatalog(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$seeder = new ExampleRecipeCatalogSeeder($db);
TenantContext::set(999, ['id' => 123]);
verifyCatalog($seeder->run() === 6, 'Six curated examples');
verifyCatalog(TenantContext::getClinicId() === 999 && TenantContext::getUserId() === 123, 'Caller context restored');
verifyCatalog($seeder->run() === 0, 'Idempotent installation');
$service = new RecipeService($db);
foreach ([1, 2, 999] as $clinic) {
    TenantContext::set($clinic, ['id' => 123]);
    $recipes = $service->searchRecipes([], $clinic);
    verifyCatalog(count($recipes) === 6, 'Examples visible in new clinic');
    foreach ($recipes as $recipe) {
        verifyCatalog($recipe['clinic_id'] === null && (int)$recipe['is_custom'] === 0, 'Institutional ownership');
        $details = $service->getRecipeDetails((int)$recipe['id'], $clinic);
        verifyCatalog(!empty($details['ingredients']), 'Example details accessible');
    }
}
$db->exec("UPDATE recipes SET clinic_id=1, visibility='clinic', is_custom=1 WHERE id=(SELECT MIN(id) FROM recipes)");
verifyCatalog(count($service->searchRecipes([], 2)) === 5, 'Private recipes stay isolated');
verifyCatalog($seeder->run() === 1, 'Recreates curated example without exposing private copy');
verifyCatalog((int)$db->query("SELECT COUNT(*) FROM recipes WHERE clinic_id=1 AND visibility='clinic'")->fetchColumn() === 1, 'Private copy untouched');
echo "PASS: global catalog, new clinics, details, idempotence, private isolation, context restoration\n";
