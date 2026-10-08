<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Recipe;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Nutrition\NutritionCalculator;
use ClyoNutri\Domain\ShoppingList\ShoppingListService;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class RecipeService
{
    private PDO $db;
    private NutritionCalculator $calculator;
    private ShoppingListService $shoppingListService;

    public function __construct(
        ?PDO $db = null,
        ?NutritionCalculator $calculator = null,
        ?ShoppingListService $shoppingListService = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->calculator = $calculator ?? new NutritionCalculator($this->db);
        $this->shoppingListService = $shoppingListService ?? new ShoppingListService($this->db);
    }

    /**
     * Cria receita culinária com cálculo nutricional rastreável pela tabela TACO,
     * passos ordenados, tags, rastreamento de alergênicos e snapshot imutável v1 (NI2-269 a NI2-274).
     */
    public function createRecipe(
        array $data,
        array $ingredients,
        array $steps = [],
        array $tags = [],
        array $allergens = []
    ): array {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;
        $authorId = TenantContext::getUser()['id'] ?? ($data['author_user_id'] ?? null);

        $cleanTitle = trim((string)($data['title'] ?? ''));
        if ($cleanTitle === '') {
            throw new InvalidArgumentException("O título da receita é obrigatório.");
        }

        if (empty($ingredients)) {
            throw new InvalidArgumentException("A receita deve conter pelo menos um ingrediente com referência nutricional.");
        }

        $servings = max(1, (int)($data['servings'] ?? 1));

        $this->db->beginTransaction();
        try {
            // 1. Calcular Composição Nutricional Rastreável
            $nutritionTotals = $this->calculateIngredientsNutrition($ingredients);

            $kcalPerServing = round($nutritionTotals['energy_kcal'] / $servings, 2);
            $proteinPerServing = round($nutritionTotals['protein_g'] / $servings, 2);
            $carbsPerServing = round($nutritionTotals['carbohydrate_g'] / $servings, 2);
            $fatPerServing = round($nutritionTotals['lipid_g'] / $servings, 2);
            $fiberPerServing = round($nutritionTotals['fiber_g'] / $servings, 2);
            $sodiumPerServing = round($nutritionTotals['sodium_mg'] / $servings, 2);
            $servingSizeG = round($nutritionTotals['total_weight_g'] / $servings, 1);

            $instructions = trim((string)($data['instructions'] ?? ''));
            if ($instructions === '' && !empty($steps)) {
                $instructions = implode("\n", array_map(fn($s, $idx) => ($idx + 1) . ". " . (is_array($s) ? ($s['instruction'] ?? '') : $s), $steps, array_keys($steps)));
            }

            // 2. Persistir Receita na Tabela recipes
            $stmt = $this->db->prepare("
                INSERT INTO recipes (
                    clinic_id, author_user_id, title, summary, category, meal_type,
                    difficulty, prep_time_minutes, cook_time_minutes, servings,
                    serving_size_name, serving_size_g, instructions,
                    energy_kcal_per_serving, protein_g_per_serving, carbs_g_per_serving, fat_g_per_serving,
                    fiber_g_per_serving, sodium_mg_per_serving,
                    status, visibility, is_custom, parent_recipe_id, photo_path, created_at, updated_at
                ) VALUES (
                    :clinic_id, :author_id, :title, :summary, :category, :meal_type,
                    :difficulty, :prep_time, :cook_time, :servings,
                    :serving_size_name, :serving_size_g, :instructions,
                    :kcal, :protein, :carbs, :fat, :fiber, :sodium,
                    :status, :visibility, :is_custom, :parent_id, :photo_path, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");

            $stmt->execute([
                'clinic_id' => $clinicId,
                'author_id' => $authorId,
                'title' => $cleanTitle,
                'summary' => $data['summary'] ?? null,
                'category' => $data['category'] ?? 'Prato Principal',
                'meal_type' => $data['meal_type'] ?? null,
                'difficulty' => $data['difficulty'] ?? 'fácil',
                'prep_time' => (int)($data['prep_time_minutes'] ?? 15),
                'cook_time' => (int)($data['cook_time_minutes'] ?? 0),
                'servings' => $servings,
                'serving_size_name' => $data['serving_size_name'] ?? 'porção',
                'serving_size_g' => $servingSizeG,
                'instructions' => $instructions,
                'kcal' => $kcalPerServing,
                'protein' => $proteinPerServing,
                'carbs' => $carbsPerServing,
                'fat' => $fatPerServing,
                'fiber' => $fiberPerServing,
                'sodium' => $sodiumPerServing,
                'status' => $data['status'] ?? 'published',
                'visibility' => $data['visibility'] ?? ($clinicId !== null ? 'clinic' : 'public'),
                'is_custom' => $clinicId !== null ? 1 : 0,
                'parent_id' => $data['parent_recipe_id'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
            ]);

            $recipeId = (int)$this->db->lastInsertId();

            // 3. Persistir Ingredientes Detalhados
            $iStmt = $this->db->prepare("
                INSERT INTO recipe_ingredients (
                    recipe_id, food_id, amount, measure_unit, gross_weight_g, net_weight_g,
                    correction_factor, cooking_factor, notes, ordering
                ) VALUES (
                    :recipe_id, :food_id, :amount, :measure_unit, :gross_w, :net_w,
                    :fc, :fcook, :notes, :ordering
                )
            ");

            $ingSnapshot = [];
            foreach ($ingredients as $idx => $ing) {
                $calc = $ing['_calc'] ?? [];
                $iStmt->execute([
                    'recipe_id' => $recipeId,
                    'food_id' => (int)$ing['food_id'],
                    'amount' => (float)$ing['amount'],
                    'measure_unit' => trim((string)($ing['measure_unit'] ?? 'g')),
                    'gross_w' => $calc['weight_grams'] ?? (float)$ing['amount'],
                    'net_w' => $calc['weight_grams'] ?? (float)$ing['amount'],
                    'fc' => (float)($ing['correction_factor'] ?? 1.0),
                    'fcook' => (float)($ing['cooking_factor'] ?? 1.0),
                    'notes' => $ing['notes'] ?? null,
                    'ordering' => $idx + 1,
                ]);

                $ingSnapshot[] = [
                    'food_id' => $ing['food_id'],
                    'name' => $calc['food_name'] ?? '',
                    'amount' => $ing['amount'],
                    'measure_unit' => $ing['measure_unit'] ?? 'g',
                    'weight_grams' => $calc['weight_grams'] ?? 0,
                    'energy_kcal' => $calc['energy_kcal'] ?? 0,
                    'protein_g' => $calc['protein_g'] ?? 0,
                    'carbohydrate_g' => $calc['carbohydrate_g'] ?? 0,
                    'lipid_g' => $calc['lipid_g'] ?? 0,
                ];
            }

            // 4. Persistir Passos Ordenados
            if (!empty($steps)) {
                $stepStmt = $this->db->prepare("
                    INSERT INTO recipe_steps (recipe_id, step_number, instruction, tip)
                    VALUES (:recipe_id, :step_number, :instruction, :tip)
                ");
                foreach ($steps as $idx => $st) {
                    $instruction = is_array($st) ? ($st['instruction'] ?? '') : (string)$st;
                    $tip = is_array($st) ? ($st['tip'] ?? null) : null;
                    $stepStmt->execute([
                        'recipe_id' => $recipeId,
                        'step_number' => $idx + 1,
                        'instruction' => trim($instruction),
                        'tip' => $tip,
                    ]);
                }
            }

            // 5. Persistir Tags
            if (!empty($tags)) {
                $tagStmt = $this->db->prepare("INSERT INTO recipe_tags (recipe_id, tag_name) VALUES (:recipe_id, :tag_name)");
                foreach ($tags as $t) {
                    $tagClean = trim((string)$t);
                    if ($tagClean !== '') {
                        $tagStmt->execute(['recipe_id' => $recipeId, 'tag_name' => $tagClean]);
                    }
                }
            }

            // 6. Persistir Alergênicos (Derivados do catálogo TACO + especificados)
            $allAllergens = $this->resolveRecipeAllergens($ingredients, $allergens);
            if (!empty($allAllergens)) {
                $alStmt = $this->db->prepare("
                    INSERT INTO recipe_allergens (recipe_id, allergen_group, risk_level, verified_by_professional, notes)
                    VALUES (:recipe_id, :group, :level, 1, :notes)
                ");
                foreach ($allAllergens as $al) {
                    $alStmt->execute([
                        'recipe_id' => $recipeId,
                        'group' => $al['group'],
                        'level' => $al['level'] ?? 'contains',
                        'notes' => $al['notes'] ?? null,
                    ]);
                }
            }

            // 7. Criar Versão Imutável v1 (Snapshot)
            $versionSnapshot = [
                'recipe_id' => $recipeId,
                'version' => 1,
                'title' => $cleanTitle,
                'servings' => $servings,
                'ingredients' => $ingSnapshot,
                'instructions' => $instructions,
                'nutrition_per_serving' => [
                    'energy_kcal' => $kcalPerServing,
                    'protein_g' => $proteinPerServing,
                    'carbs_g' => $carbsPerServing,
                    'fat_g' => $fatPerServing,
                    'fiber_g' => $fiberPerServing,
                    'sodium_mg' => $sodiumPerServing,
                ],
                'allergens' => $allAllergens,
                'created_at' => date('c'),
            ];

            $vStmt = $this->db->prepare("
                INSERT INTO recipe_versions (
                    recipe_id, version_number, title, servings, serving_size_g,
                    energy_kcal_per_serving, protein_g_per_serving, carbs_g_per_serving,
                    fat_g_per_serving, fiber_g_per_serving, sodium_mg_per_serving,
                    payload_json, status, published_at, published_by_user_id
                ) VALUES (
                    :recipe_id, 1, :title, :servings, :serving_g,
                    :kcal, :protein, :carbs, :fat, :fiber, :sodium,
                    :payload, 'published', CURRENT_TIMESTAMP, :user_id
                )
            ");
            $vStmt->execute([
                'recipe_id' => $recipeId,
                'title' => $cleanTitle,
                'servings' => $servings,
                'serving_g' => $servingSizeG,
                'kcal' => $kcalPerServing,
                'protein' => $proteinPerServing,
                'carbs' => $carbsPerServing,
                'fat' => $fatPerServing,
                'fiber' => $fiberPerServing,
                'sodium' => $sodiumPerServing,
                'payload' => json_encode($versionSnapshot, JSON_UNESCAPED_UNICODE),
                'user_id' => $authorId,
            ]);

            $versionId = (int)$this->db->lastInsertId();

            $this->db->commit();

            return [
                'id' => $recipeId,
                'recipe_id' => $recipeId,
                'parent_recipe_id' => $data['parent_recipe_id'] ?? null,
                'version_id' => $versionId,
                'version_number' => 1,
                'title' => $cleanTitle,
                'servings' => $servings,
                'serving_size_g' => $servingSizeG,
                'energy_kcal_per_serving' => $kcalPerServing,
                'protein_g_per_serving' => $proteinPerServing,
                'carbs_g_per_serving' => $carbsPerServing,
                'fat_g_per_serving' => $fatPerServing,
                'fiber_g_per_serving' => $fiberPerServing,
                'sodium_mg_per_serving' => $sodiumPerServing,
                'totals' => $nutritionTotals,
                'allergens' => $allAllergens,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Consulta detalhes completos da receita assegurando governança e isolamento multi-tenant (NI2-269).
     */
    public function getRecipeDetails(int $recipeId, ?int $clinicId = null): array
    {
        $tenantClinicId = $clinicId ?? (TenantContext::hasClinic() ? TenantContext::getClinicId() : null);

        $stmt = $this->db->prepare("
            SELECT r.*, u.name AS author_name, pr.crn AS author_crn
            FROM recipes r
            LEFT JOIN users u ON u.id = r.author_user_id
            LEFT JOIN professionals pr ON pr.user_id = u.id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $recipeId]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$recipe) {
            throw new InvalidArgumentException("Receita #{$recipeId} não encontrada.");
        }

        // Validação estrita de visibilidade multi-tenant
        if ($recipe['clinic_id'] !== null && $tenantClinicId !== null && (int)$recipe['clinic_id'] !== $tenantClinicId) {
            if ($recipe['visibility'] !== 'public') {
                throw new TenantSecurityException("Acesso negado: a receita #{$recipeId} é privada de outra clínica (403 Forbidden).", 403);
            }
        }

        // Ingredientes com informações do catálogo oficial foods
        $iStmt = $this->db->prepare("
            SELECT ri.*, f.name AS food_name, f.category AS food_category, f.source AS food_source,
                   f.energy_kcal AS base_kcal, f.protein_g AS base_protein, f.carbohydrate_g AS base_carbs, f.lipid_g AS base_fat
            FROM recipe_ingredients ri
            JOIN foods f ON f.id = ri.food_id
            WHERE ri.recipe_id = :id
            ORDER BY ri.ordering ASC, ri.id ASC
        ");
        $iStmt->execute(['id' => $recipeId]);
        $ingredients = $iStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Passos ordenados
        $sStmt = $this->db->prepare("SELECT * FROM recipe_steps WHERE recipe_id = :id ORDER BY step_number ASC");
        $sStmt->execute(['id' => $recipeId]);
        $steps = $sStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Tags
        $tStmt = $this->db->prepare("SELECT tag_name FROM recipe_tags WHERE recipe_id = :id");
        $tStmt->execute(['id' => $recipeId]);
        $tags = $tStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Alergênicos
        $aStmt = $this->db->prepare("SELECT * FROM recipe_allergens WHERE recipe_id = :id");
        $aStmt->execute(['id' => $recipeId]);
        $allergens = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Versões
        $vStmt = $this->db->prepare("SELECT id, version_number, status, published_at FROM recipe_versions WHERE recipe_id = :id ORDER BY version_number DESC");
        $vStmt->execute(['id' => $recipeId]);
        $versions = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_merge($recipe, [
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tags' => $tags,
            'allergens' => $allergens,
            'versions' => $versions,
        ]);
    }

    /**
     * Busca e filtragem avançada de receitas com isolamento multi-tenant (NI2-273).
     */
    public function searchRecipes(array $filters = [], ?int $clinicId = null): array
    {
        $tenantClinicId = $clinicId ?? (TenantContext::hasClinic() ? TenantContext::getClinicId() : null);

        $sql = "
            SELECT r.*, 
                   (SELECT COUNT(*) FROM recipe_ingredients WHERE recipe_id = r.id) AS ingredients_count
            FROM recipes r
            WHERE r.status = 'published'
        ";
        $params = [];

        // Filtro multi-tenant: vê receitas da própria clínica ou receitas públicas/institucionais
        if ($tenantClinicId !== null) {
            $sql .= " AND (r.clinic_id = :clinic_id OR (r.clinic_id IS NULL AND r.visibility = 'public') OR r.visibility = 'public')";
            $params['clinic_id'] = $tenantClinicId;
        } else {
            $sql .= " AND (r.clinic_id IS NULL OR r.visibility = 'public')";
        }

        // Filtro por termo de busca
        if (!empty($filters['query'])) {
            $term = '%' . trim($filters['query']) . '%';
            $sql .= " AND (r.title LIKE :term OR r.summary LIKE :term OR r.instructions LIKE :term)";
            $params['term'] = $term;
        }

        // Filtro por categoria
        if (!empty($filters['category'])) {
            $sql .= " AND r.category = :category";
            $params['category'] = trim($filters['category']);
        }

        // Filtro por dificuldade
        if (!empty($filters['difficulty'])) {
            $sql .= " AND r.difficulty = :difficulty";
            $params['difficulty'] = trim($filters['difficulty']);
        }

        // Filtro por tempo máximo de preparo
        if (!empty($filters['max_prep_time'])) {
            $sql .= " AND r.prep_time_minutes <= :max_prep";
            $params['max_prep'] = (int)$filters['max_prep_time'];
        }

        // Filtro por calorias máximas por porção
        if (!empty($filters['max_calories'])) {
            $sql .= " AND r.energy_kcal_per_serving <= :max_kcal";
            $params['max_kcal'] = (float)$filters['max_calories'];
        }

        $sql .= " ORDER BY r.is_custom DESC, r.title ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Filtragem por tag em memória se solicitado
        if (!empty($filters['tag'])) {
            $tagFilter = mb_strtolower(trim($filters['tag']), 'UTF-8');
            $recipes = array_values(array_filter($recipes, function($r) use ($tagFilter) {
                $tStmt = $this->db->prepare("SELECT tag_name FROM recipe_tags WHERE recipe_id = :id");
                $tStmt->execute(['id' => $r['id']]);
                $tags = array_map(fn($t) => mb_strtolower($t, 'UTF-8'), $tStmt->fetchAll(PDO::FETCH_COLUMN));
                return in_array($tagFilter, $tags, true);
            }));
        }

        return $recipes;
    }

    /**
     * Duplica uma receita como variante rastreada, permitindo substituição de ingredientes (NI2-276).
     */
    public function duplicateAsVariant(int $recipeId, string $newTitle, ?array $ingredientOverrides = null): array
    {
        $original = $this->getRecipeDetails($recipeId);

        $newIngredients = [];
        if ($ingredientOverrides !== null) {
            $newIngredients = $ingredientOverrides;
        } else {
            foreach ($original['ingredients'] as $ing) {
                $newIngredients[] = [
                    'food_id' => $ing['food_id'],
                    'amount' => $ing['amount'],
                    'measure_unit' => $ing['measure_unit'],
                    'notes' => $ing['notes'],
                ];
            }
        }

        $variantData = [
            'title' => trim($newTitle),
            'summary' => "Variante adaptada a partir de '{$original['title']}'. " . ($original['summary'] ?? ''),
            'category' => $original['category'],
            'meal_type' => $original['meal_type'],
            'difficulty' => $original['difficulty'],
            'prep_time_minutes' => $original['prep_time_minutes'],
            'cook_time_minutes' => $original['cook_time_minutes'],
            'servings' => $original['servings'],
            'serving_size_name' => $original['serving_size_name'],
            'instructions' => $original['instructions'],
            'parent_recipe_id' => $recipeId,
            'status' => 'published',
            'visibility' => 'clinic',
        ];

        return $this->createRecipe($variantData, $newIngredients, $original['steps'], $original['tags']);
    }

    /**
     * Prescreve uma receita em uma refeição do plano alimentar do paciente (NI2-275).
     */
    public function prescribeRecipe(
        int $clinicId,
        int $mealPlanId,
        ?int $mealId,
        int $patientId,
        int $recipeId,
        float $servings = 1.0,
        ?string $instructionsOverride = null,
        ?string $mealName = null
    ): array {
        TenantGuard::assertMealPlanBelongsToClinic($mealPlanId, $clinicId, $this->db);
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $recipe = $this->getRecipeDetails($recipeId, $clinicId);

        if ($servings <= 0) {
            throw new InvalidArgumentException("O número de porções prescritas deve ser estritamente maior que zero.");
        }

        // Buscar versão publicada mais recente
        $vStmt = $this->db->prepare("
            SELECT id, version_number FROM recipe_versions 
            WHERE recipe_id = :id AND status = 'published'
            ORDER BY version_number DESC LIMIT 1
        ");
        $vStmt->execute(['id' => $recipeId]);
        $version = $vStmt->fetch(PDO::FETCH_ASSOC);
        $versionId = $version ? (int)$version['id'] : null;

        // Verificar conflitos com alergias do paciente
        $conflicts = $this->checkPatientAllergenConflicts($patientId, $recipe['allergens']);

        $stmt = $this->db->prepare("
            INSERT INTO recipe_prescriptions (
                clinic_id, meal_plan_id, meal_id, patient_id, recipe_id, recipe_version_id,
                prescribed_servings, meal_name, instructions_override, created_at
            ) VALUES (
                :clinic_id, :meal_plan_id, :meal_id, :patient_id, :recipe_id, :version_id,
                :servings, :meal_name, :instructions_override, CURRENT_TIMESTAMP
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'meal_plan_id' => $mealPlanId,
            'meal_id' => $mealId,
            'patient_id' => $patientId,
            'recipe_id' => $recipeId,
            'version_id' => $versionId,
            'servings' => $servings,
            'meal_name' => $mealName ?? ($recipe['category'] ?? 'Refeição'),
            'instructions_override' => $instructionsOverride,
        ]);

        $prescriptionId = (int)$this->db->lastInsertId();

        // Escalonar nutrientes pelas porções prescritas
        $scaledKcal = round((float)$recipe['energy_kcal_per_serving'] * $servings, 2);
        $scaledProtein = round((float)$recipe['protein_g_per_serving'] * $servings, 2);
        $scaledCarbs = round((float)$recipe['carbs_g_per_serving'] * $servings, 2);
        $scaledFat = round((float)$recipe['fat_g_per_serving'] * $servings, 2);

        return [
            'id' => $prescriptionId,
            'prescription_id' => $prescriptionId,
            'recipe_id' => $recipeId,
            'recipe_title' => $recipe['title'],
            'patient_id' => $patientId,
            'meal_plan_id' => $mealPlanId,
            'prescribed_servings' => $servings,
            'scaled_energy_kcal' => $scaledKcal,
            'scaled_nutrition' => [
                'energy_kcal' => $scaledKcal,
                'protein_g' => $scaledProtein,
                'carbohydrate_g' => $scaledCarbs,
                'lipid_g' => $scaledFat,
            ],
            'allergen_conflicts' => $conflicts,
            'allergen_warnings' => $conflicts,
        ];
    }

    /**
     * Lista receitas prescritas para o paciente (NI2-277).
     */
    public function listPrescriptionsForPatient(int $patientId, ?int $mealPlanId = null): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;

        $sql = "
            SELECT rp.*, r.title AS recipe_title, r.summary AS recipe_summary,
                   r.energy_kcal_per_serving, r.protein_g_per_serving, r.carbs_g_per_serving, r.fat_g_per_serving,
                   r.prep_time_minutes, r.cook_time_minutes, r.instructions, r.photo_path
            FROM recipe_prescriptions rp
            JOIN recipes r ON r.id = rp.recipe_id
            WHERE rp.patient_id = :patient_id
        ";
        $params = ['patient_id' => $patientId];

        if ($clinicId !== null) {
            $sql .= " AND rp.clinic_id = :clinic_id";
            $params['clinic_id'] = $clinicId;
        }

        if ($mealPlanId !== null) {
            $sql .= " AND rp.meal_plan_id = :meal_plan_id";
            $params['meal_plan_id'] = $mealPlanId;
        }

        $sql .= " ORDER BY rp.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($prescriptions as &$p) {
            $servings = (float)$p['prescribed_servings'];
            $p['scaled_energy_kcal'] = round((float)$p['energy_kcal_per_serving'] * $servings, 2);
            $p['scaled_protein_g'] = round((float)$p['protein_g_per_serving'] * $servings, 2);
            $p['scaled_carbs_g'] = round((float)$p['carbs_g_per_serving'] * $servings, 2);
            $p['scaled_fat_g'] = round((float)$p['fat_g_per_serving'] * $servings, 2);
        }

        return $prescriptions;
    }

    /**
     * Consolida lista de compras a partir de receitas prescritas (NI2-278).
     */
    public function generateShoppingListFromRecipes(int $patientId, array $prescriptionIds = [], int $daysMultiplier = 7): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $sql = "
            SELECT rp.id AS prescription_id, rp.prescribed_servings, r.title AS recipe_title,
                   ri.food_id, ri.amount, ri.measure_unit, f.name AS food_name, f.category AS food_category
            FROM recipe_prescriptions rp
            JOIN recipes r ON r.id = rp.recipe_id
            JOIN recipe_ingredients ri ON ri.recipe_id = r.id
            JOIN foods f ON f.id = ri.food_id
            WHERE rp.patient_id = :patient_id AND rp.clinic_id = :clinic_id
        ";
        $params = [
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
        ];

        if (!empty($prescriptionIds)) {
            $inClause = implode(',', array_map('intval', $prescriptionIds));
            $sql .= " AND rp.id IN ({$inClause})";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Agregar por alimento e unidade
        $aggregated = [];
        foreach ($items as $it) {
            $key = $it['food_id'] . '_' . $it['measure_unit'];
            $totalAmount = (float)$it['amount'] * (float)$it['prescribed_servings'] * $daysMultiplier;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'food_id' => (int)$it['food_id'],
                    'name' => $it['food_name'],
                    'category' => $it['food_category'] ?? 'Geral',
                    'aisle' => $this->mapAisle($it['food_category'] ?? ''),
                    'measure_unit' => $it['measure_unit'],
                    'total_amount' => 0.0,
                    'recipes_source' => [],
                ];
            }

            $aggregated[$key]['total_amount'] += $totalAmount;
            if (!in_array($it['recipe_title'], $aggregated[$key]['recipes_source'], true)) {
                $aggregated[$key]['recipes_source'][] = $it['recipe_title'];
            }
        }

        // Agrupar por gôndolas
        $byAisle = [];
        $itemsList = [];
        foreach ($aggregated as $item) {
            $aisle = $item['aisle'];
            if (!isset($byAisle[$aisle])) {
                $byAisle[$aisle] = [];
            }
            $formattedItem = [
                'food_id' => $item['food_id'],
                'name' => $item['name'],
                'aisle' => $item['aisle'],
                'category' => $item['category'],
                'amount' => round($item['total_amount'], 1),
                'total_grams' => round($item['total_amount'], 1),
                'unit' => $item['measure_unit'],
                'recipes' => $item['recipes_source'],
            ];
            $byAisle[$aisle][] = $formattedItem;
            $itemsList[] = $formattedItem;
        }

        return [
            'patient_id' => $patientId,
            'days_multiplier' => $daysMultiplier,
            'total_items_count' => count($aggregated),
            'items' => $itemsList,
            'aisles' => $byAisle,
            'by_aisle' => $byAisle,
        ];
    }

    /**
     * Alterna favorito do paciente em uma receita (NI2-277).
     */
    public function togglePatientFavorite(int $patientId, int $recipeId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM recipe_patient_favorites WHERE patient_id = :pid AND recipe_id = :rid");
        $stmt->execute(['pid' => $patientId, 'rid' => $recipeId]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $del = $this->db->prepare("DELETE FROM recipe_patient_favorites WHERE id = :id");
            $del->execute(['id' => $existing]);
            return false;
        }

        $ins = $this->db->prepare("INSERT INTO recipe_patient_favorites (patient_id, recipe_id) VALUES (:pid, :rid)");
        $ins->execute(['pid' => $patientId, 'rid' => $recipeId]);
        return true;
    }

    /**
     * Lista receitas favoritas do paciente.
     */
    public function listPatientFavorites(int $patientId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.* FROM recipes r
            JOIN recipe_patient_favorites f ON f.recipe_id = r.id
            WHERE f.patient_id = :pid
            ORDER BY f.created_at DESC
        ");
        $stmt->execute(['pid' => $patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Método de compatibilidade para listar receitas públicas e da clínica.
     */
    public function listRecipes(): array
    {
        return $this->searchRecipes();
    }

    /**
     * Calcula a composição nutricional dos ingredientes usando alimentos oficiais (TACO).
     */
    private function calculateIngredientsNutrition(array $ingredients): array
    {
        $totalWeightG = 0.0;
        $totalKcal = 0.0;
        $totalProt = 0.0;
        $totalCarb = 0.0;
        $totalLip = 0.0;
        $totalFib = 0.0;
        $totalSodium = 0.0;

        foreach ($ingredients as &$ing) {
            $foodId = (int)$ing['food_id'];
            $amount = (float)$ing['amount'];
            $unit = trim((string)($ing['measure_unit'] ?? 'g'));

            $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
            $fStmt->execute(['id' => $foodId]);
            $food = $fStmt->fetch(PDO::FETCH_ASSOC);

            if (!$food) {
                throw new InvalidArgumentException("Alimento ID #{$foodId} não encontrado no catálogo nutricional oficial.");
            }

            $calc = $this->calculator->calculateForPortion($food, $amount, $unit, $foodId);
            $calc['food_name'] = $food['name'];

            $ing['_calc'] = $calc;

            $totalWeightG += (float)$calc['weight_grams'];
            $totalKcal += (float)$calc['energy_kcal'];
            $totalProt += (float)$calc['protein_g'];
            $totalCarb += (float)$calc['carbohydrate_g'];
            $totalLip += (float)$calc['lipid_g'];
            $totalFib += (float)$calc['fiber_g'];
        }

        return [
            'total_weight_g' => round($totalWeightG, 2),
            'energy_kcal' => round($totalKcal, 2),
            'protein_g' => round($totalProt, 2),
            'carbohydrate_g' => round($totalCarb, 2),
            'lipid_g' => round($totalLip, 2),
            'fiber_g' => round($totalFib, 2),
            'sodium_mg' => round($totalSodium, 2),
        ];
    }

    /**
     * Mapeia categoria do alimento para o corredor de supermercado.
     */
    private function mapAisle(string $category): string
    {
        $cat = mb_strtolower(trim($category), 'UTF-8');
        if (str_contains($cat, 'verdura') || str_contains($cat, 'fruta') || str_contains($cat, 'legum') || str_contains($cat, 'hortifrúti')) {
            return 'Hortifrúti & Feira';
        }
        if (str_contains($cat, 'carne') || str_contains($cat, 'ave') || str_contains($cat, 'ovo') || str_contains($cat, 'peixe') || str_contains($cat, 'frango')) {
            return 'Açougue & Ovos';
        }
        if (str_contains($cat, 'leite') || str_contains($cat, 'iogurte') || str_contains($cat, 'queijo') || str_contains($cat, 'laticínio')) {
            return 'Laticínios & Refrigerados';
        }
        if (str_contains($cat, 'cereal') || str_contains($cat, 'cereais') || str_contains($cat, 'arroz') || str_contains($cat, 'aveia') || str_contains($cat, 'grão')) {
            return 'Cereais & Grãos';
        }
        if (str_contains($cat, 'óleo') || str_contains($cat, 'oleo') || str_contains($cat, 'azeite') || str_contains($cat, 'gordura')) {
            return 'Óleos & Condimentos';
        }
        return 'Mercearia & Diversos';
    }

    /**
     * Identifica alergênicos nos ingredientes cadastrados.
     */
    private function resolveRecipeAllergens(array $ingredients, array $explicitAllergens = []): array
    {
        $resolved = [];
        $seen = [];

        foreach ($ingredients as $ing) {
            $fStmt = $this->db->prepare("SELECT allergens FROM foods WHERE id = :id");
            $fStmt->execute(['id' => (int)$ing['food_id']]);
            $raw = (string)$fStmt->fetchColumn();

            if (!empty($raw)) {
                $groups = array_map('trim', explode(',', $raw));
                foreach ($groups as $g) {
                    if ($g !== '' && !isset($seen[mb_strtolower($g, 'UTF-8')])) {
                        $seen[mb_strtolower($g, 'UTF-8')] = true;
                        $resolved[] = [
                            'group' => $g,
                            'level' => 'contains',
                            'notes' => 'Detectado via tabela oficial de alimentos',
                        ];
                    }
                }
            }
        }

        foreach ($explicitAllergens as $ea) {
            $group = is_array($ea) ? ($ea['group'] ?? '') : (string)$ea;
            $groupClean = trim($group);
            if ($groupClean !== '' && !isset($seen[mb_strtolower($groupClean, 'UTF-8')])) {
                $seen[mb_strtolower($groupClean, 'UTF-8')] = true;
                $resolved[] = [
                    'group' => $groupClean,
                    'level' => is_array($ea) ? ($ea['level'] ?? 'contains') : 'contains',
                    'notes' => is_array($ea) ? ($ea['notes'] ?? 'Declarado pelo nutricionista') : 'Declarado pelo nutricionista',
                ];
            }
        }

        return $resolved;
    }

    /**
     * Verifica possíveis incompatibilidades entre alergênicos da receita e anamnese do paciente.
     */
    private function checkPatientAllergenConflicts(int $patientId, array $recipeAllergens): array
    {
        $stmt = $this->db->prepare("SELECT allergies, restrictions FROM anamneses WHERE patient_id = :pid ORDER BY id DESC LIMIT 1");
        $stmt->execute(['pid' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [];
        }

        $rawText = ($row['allergies'] ?? '') . ' ' . ($row['restrictions'] ?? '');
        $normPatient = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $rawText) ?: mb_strtolower($rawText, 'UTF-8');
        $conflicts = [];

        foreach ($recipeAllergens as $ra) {
            $rawGroup = (string)($ra['allergen_group'] ?? ($ra['group'] ?? ''));
            if ($rawGroup === '') {
                continue;
            }
            $normGroup = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $rawGroup) ?: mb_strtolower($rawGroup, 'UTF-8');

            if (str_contains($normPatient, $normGroup)) {
                $conflicts[] = [
                    'allergen' => $rawGroup,
                    'warning' => "Atenção clínica: o paciente possui registro de incompatibilidade para '{$rawGroup}'.",
                ];
            }
        }

        return $conflicts;
    }
}
