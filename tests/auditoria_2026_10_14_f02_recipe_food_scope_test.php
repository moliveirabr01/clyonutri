<?php

declare(strict_types=1);

// F02 — catálogo particular de alimentos restrito à clínica no módulo de receitas (auditoria 14/10/2026).
session_start();
ob_start();
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Application\Controllers\RecipeController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Recipe\RecipeService;

echo "F02 — alimentos particulares de outra clínica\n";
$fx = aud_fixture();
$db = $fx['db'];
$svc = new RecipeService($db);
$recipes = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
$asA = static function () use ($fx) {
    $_SESSION['user_id'] = $fx['userA'];
    $_SESSION['clinic_id'] = $fx['clinicA'];
    TenantContext::set($fx['clinicA'], ['id' => $fx['userA'], 'name' => 'Dra. Alfa', 'clinic_id' => $fx['clinicA']]);
};
$render = static function (string $method, array $params = []) use ($fx, $asA): string {
    $asA();
    ob_start();
    (new RecipeController($fx['db']))->{$method}($params);
    return (string)ob_get_clean();
};

// Formulários
foreach (['create' => [], 'edit' => ['id' => (string)$fx['recipeA']]] as $m => $p) {
    $html = $render($m, $p);
    aud_check(!str_contains($html, 'Segredo particular da Beta'), "formulário {$m}: alimento particular da clínica B não aparece");
    if ($m === 'create') { // o formulário de edição não lista ingredientes (só metadados), apenas não pode vazar os de B
        // NI2-73: os ingredientes vêm da busca do catálogo (/catalogo/alimentos/busca, filtro global + própria clínica,
        // coberto por tests/ni2_73_food_search_real_test.cjs e ni2_73_recipe_ingredients_browser_test.cjs); o formulário
        // não embute mais nenhum alimento.
        aud_check(str_contains($html, 'src="/assets/js/food-picker.js"') && !str_contains($html, 'Arroz global de teste') && !str_contains($html, 'Pão particular da Alfa'), "formulário {$m}: ingredientes pela busca do catálogo, sem lista embutida");
    }
}
$list = array_column($svc->listFoodsForClinic($fx['clinicA']), 'id');
aud_check(!in_array($fx['foodB'], array_map('intval', $list), true), 'listFoodsForClinic(A) não contém o ID de B');
aud_check(in_array($fx['foodGlobal'], array_map('intval', $list), true) && in_array($fx['foodA'], array_map('intval', $list), true), 'listFoodsForClinic(A) contém global e próprio');

// Criação com ID estrangeiro submetido manualmente
$asA();
$before = $recipes();
$rejected = false;
try {
    $svc->createRecipe(['title' => 'Tentativa', 'servings' => 1], [['food_id' => $fx['foodB'], 'amount' => 50, 'measure_unit' => 'g']]);
} catch (\InvalidArgumentException) {
    $rejected = true;
}
aud_check($rejected && $recipes() === $before, 'criação com alimento de B rejeitada sem criar receita');

// Criação mista: global + próprio passa
$ok = $svc->createRecipe(['title' => 'Receita própria', 'servings' => 2], [
    ['food_id' => $fx['foodGlobal'], 'amount' => 100, 'measure_unit' => 'g'],
    ['food_id' => $fx['foodA'], 'amount' => 50, 'measure_unit' => 'g'],
]);
aud_check((int)$ok['id'] > 0 && $recipes() === $before + 1, 'criação com alimento global + próprio funciona');

// Edição com ingrediente estrangeiro
$ingBefore = (int)$db->query("SELECT COUNT(*) FROM recipe_ingredients WHERE recipe_id = {$fx['recipeA']}")->fetchColumn();
$rejected = false;
try {
    $svc->updateRecipe($fx['recipeA'], ['title' => 'Receita Alfa editada', 'servings' => 1, 'ingredients' => [['food_id' => $fx['foodB'], 'amount' => 10, 'measure_unit' => 'g']]], $fx['clinicA'], $fx['userA']);
} catch (\InvalidArgumentException) {
    $rejected = true;
}
$title = (string)$db->query("SELECT title FROM recipes WHERE id = {$fx['recipeA']}")->fetchColumn();
aud_check($rejected && $title === 'Receita Alfa', 'edição com alimento de B rejeitada e receita inalterada');
aud_check((int)$db->query("SELECT COUNT(*) FROM recipe_ingredients WHERE recipe_id = {$fx['recipeA']} AND food_id = {$fx['foodB']}")->fetchColumn() === 0 && $ingBefore >= 1, 'nenhum ingrediente de B gravado na edição');

// Duplicação com override estrangeiro
$before = $recipes();
$rejected = false;
try {
    $svc->duplicateAsVariant($fx['recipeA'], 'Variante', [['food_id' => $fx['foodB'], 'amount' => 10, 'measure_unit' => 'g']]);
} catch (\InvalidArgumentException) {
    $rejected = true;
}
aud_check($rejected && $recipes() === $before, 'duplicação com alimento de B rejeitada sem criar variante');

// Duplicação de receita pública de B que usa alimento particular de B
TenantContext::set($fx['clinicB'], ['id' => $fx['userB'], 'name' => 'Dr. Beta', 'clinic_id' => $fx['clinicB']]);
$recipeB = (int)$svc->createRecipe(['title' => 'Pública Beta', 'servings' => 1, 'status' => 'published', 'visibility' => 'public'], [['food_id' => $fx['foodB'], 'amount' => 30, 'measure_unit' => 'g']])['id'];
$asA();
$before = $recipes();
$rejected = false;
try {
    $svc->duplicateAsVariant($recipeB, 'Cópia na Alfa');
} catch (\InvalidArgumentException) {
    $rejected = true;
}
aud_check($rejected && $recipes() === $before, 'duplicar receita pública de B não importa o alimento particular de B para A');

// Duplicação legítima continua funcionando
$v = $svc->duplicateAsVariant($fx['recipeA'], 'Variante Alfa');
aud_check((int)$v['id'] > 0 && $recipes() === $before + 1, 'duplicação de receita própria com alimento global funciona');

echo "F02: OK\n";
