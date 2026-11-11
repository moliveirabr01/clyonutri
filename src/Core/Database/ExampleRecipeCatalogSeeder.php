<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Recipe\RecipeService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use PDO;

/** Installs only the curated examples; never republishes a clinic's recipes. */
final class ExampleRecipeCatalogSeeder
{
    public function __construct(private PDO $db) {}

    public function run(): int
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;
        $user = TenantContext::getUser();
        TenantContext::clear();
        $created = 0;
        $locked = false;
        try {
            // MySQL serializes first requests from different clinics during installation.
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $locked = (int)$this->db->query("SELECT GET_LOCK('clyonutri_example_recipes', 15)")->fetchColumn() === 1;
                if (!$locked) { throw new \RuntimeException('Catálogo em preparação. Tente novamente.'); }
            }
            $service = new RecipeService($this->db);
            foreach (require __DIR__ . '/../../../database/seeds/fit_example_recipes.php' as $sample) {
                $exists = $this->db->prepare("SELECT id FROM recipes WHERE clinic_id IS NULL AND is_custom = 0 AND title = ? AND visibility = 'public' AND status = 'published'");
                $exists->execute([$sample['title']]);
                if ($exists->fetchColumn()) { continue; }
                $ingredients = [];
                foreach ($sample['ingredients'] as [$name, $amount]) {
                    $food = $this->db->prepare('SELECT id FROM foods WHERE name = ? AND clinic_id IS NULL ORDER BY id LIMIT 1');
                    $food->execute([$name]);
                    $id = $food->fetchColumn();
                    if (!$id) {
                        (new FoodCatalogService($this->db))->seedTacoReference();
                        $food->execute([$name]);
                        $id = $food->fetchColumn();
                        if (!$id) { throw new \RuntimeException('Ingrediente de catálogo ausente: ' . $name); }
                    }
                    $ingredients[] = ['food_id' => (int)$id, 'amount' => $amount, 'measure_unit' => 'g'];
                }
                $service->createRecipe([
                    'title' => $sample['title'],
                    'summary' => 'Receita de exemplo da Clyo Nutri. Nutrientes estimados pelo catálogo; rendimento aproximado. Conferir alergênicos e rótulos antes de prescrever.',
                    'meal_type' => $sample['meal_type'], 'category' => $sample['category'],
                    'prep_time_minutes' => $sample['prep'], 'cook_time_minutes' => $sample['cook'],
                    'servings' => $sample['servings'], 'visibility' => 'public', 'allergens_verified' => false,
                ], $ingredients, $sample['steps'], ['Exemplo', 'Preparação simples'], array_map(
                    static fn($group) => ['group' => $group, 'notes' => 'Conferir rótulos e possibilidade de contato cruzado antes de prescrever.'],
                    $sample['allergens']
                ));
                $created++;
            }
            return $created;
        } finally {
            if ($locked) { $this->db->query("SELECT RELEASE_LOCK('clyonutri_example_recipes')"); }
            TenantContext::clear();
            if ($clinicId !== null) { TenantContext::set($clinicId, $user ?? []); }
            elseif ($user !== null && isset($user['id'])) { TenantContext::setUserId((int)$user['id']); }
        }
    }
}
