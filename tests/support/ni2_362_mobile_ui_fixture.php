<?php
require __DIR__ . '/../../vendor/autoload.php';
if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-281-362-')) throw new RuntimeException('Isolated fixture required');
$db = ClyoNutri\Core\Database\Connection::getInstance();
ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1]);
$service = new ClyoNutri\Domain\Recipe\RecipeService($db);
foreach (['Receita completa de teste', 'Receita histórica de teste'] as $index => $title) {
    $recipe = $service->createRecipe(['title' => $title, 'summary' => 'Conteúdo fictício para validação.', 'servings' => 2, 'instructions' => 'Misture os ingredientes e divida em duas porções.', 'visibility' => 'clinic', 'status' => 'published'], [['food_id' => 101, 'amount' => 200, 'measure_unit' => 'g']], [['instruction' => 'Divida em duas porções.']]);
    $prescription = $service->prescribeRecipe(1, 1, 1, 1, $recipe['id'], 1, 'Consumir uma porção.', 'Almoço');
    if ($index === 1) $db->prepare('UPDATE recipe_versions SET payload_json = ? WHERE id = ?')->execute(['{"instructions":"Histórico incompleto de teste"}', $db->query('SELECT recipe_version_id FROM recipe_prescriptions ORDER BY id DESC LIMIT 1')->fetchColumn()]);
}
