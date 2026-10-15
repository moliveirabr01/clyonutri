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
            $nutritionTotals = $this->calculateIngredientsNutrition($ingredients, $clinicId);

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
                $amt = (float)($ing['amount'] ?? $ing['quantity'] ?? 0);
                $unit = trim((string)($ing['measure_unit'] ?? $ing['unit'] ?? 'g'));
                $iStmt->execute([
                    'recipe_id' => $recipeId,
                    'food_id' => (int)$ing['food_id'],
                    'amount' => $amt,
                    'measure_unit' => $unit,
                    'gross_w' => $calc['weight_grams'] ?? $amt,
                    'net_w' => $calc['weight_grams'] ?? $amt,
                    'fc' => (float)($ing['correction_factor'] ?? 1.0),
                    'fcook' => (float)($ing['cooking_factor'] ?? 1.0),
                    'notes' => $ing['notes'] ?? null,
                    'ordering' => $idx + 1,
                ]);

                $ingSnapshot[] = [
                    'food_id' => $ing['food_id'],
                    'name' => $calc['food_name'] ?? '',
                    'amount' => $amt,
                    'measure_unit' => $unit,
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
            $allAllergens = $this->resolveRecipeAllergens($ingredients, $allergens, $clinicId);
            if (!empty($allAllergens)) {
                $alStmt = $this->db->prepare("
                    INSERT INTO recipe_allergens (recipe_id, allergen_group, risk_level, verified_by_professional, notes)
                    VALUES (:recipe_id, :group, :level, :verified, :notes)
                ");
                foreach ($allAllergens as $al) {
                    $alStmt->execute([
                        'recipe_id' => $recipeId,
                        'verified' => !empty($data['allergens_verified'] ?? true) ? 1 : 0,
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
                'summary' => $data['summary'] ?? null,
                'prep_time_minutes' => (int)($data['prep_time_minutes'] ?? 15),
                'cook_time_minutes' => (int)($data['cook_time_minutes'] ?? 0),
                'photo_path' => $data['photo_path'] ?? null,
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
     * Edição versionada de receita culinária no runtime (PX-27 / NI2-320).
     * Preserva imutabilidade de versões anteriores e prescrições já emitidas.
     */
    public function updateRecipe(int $recipeId, array $data, ?int $clinicId = null, ?int $userId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $authorId = $userId ?? TenantContext::getUserId();

        // 1. Verificar se a receita existe e pertence à clínica (receitas públicas não podem ser alteradas diretamente)
        $stmt = $this->db->prepare("SELECT * FROM recipes WHERE id = :id");
        $stmt->execute(['id' => $recipeId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            throw new \InvalidArgumentException("Receita não encontrada.");
        }
        if ((int)$existing['clinic_id'] !== $clinicId) {
            throw new \ClyoNutri\Core\Security\TenantSecurityException("Acesso negado: você não tem permissão para editar esta receita.");
        }

        $cleanTitle = trim((string)($data['title'] ?? $existing['title']));
        if ($cleanTitle === '') {
            throw new \InvalidArgumentException("O título da receita é obrigatório.");
        }

        $servings = max(1, (int)($data['servings'] ?? $existing['servings']));
        $prepTime = max(1, (int)($data['prep_time_minutes'] ?? $existing['prep_time_minutes']));
        $cookTime = max(0, (int)($data['cook_time_minutes'] ?? $existing['cook_time_minutes']));
        $instructions = trim((string)($data['instructions'] ?? $existing['instructions']));
        $mealType = (string)($data['meal_type'] ?? $existing['meal_type']);
        $difficulty = (string)($data['difficulty'] ?? $existing['difficulty']);
        $summary = isset($data['summary']) ? trim((string)$data['summary']) : $existing['summary'];
        $servingSizeName = isset($data['serving_size_name']) ? trim((string)$data['serving_size_name']) : $existing['serving_size_name'];
        $servingSizeG = isset($data['serving_size_g']) && $data['serving_size_g'] !== '' ? (float)$data['serving_size_g'] : (float)$existing['serving_size_g'];

        // Se novos ingredientes foram fornecidos, recalcula nutrição; senão mantém os atuais
        $ingredients = $data['ingredients'] ?? null;
        if ($ingredients === null) {
            $iStmt = $this->db->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY ordering ASC, id ASC");
            $iStmt->execute([$recipeId]);
            $ingredients = $iStmt->fetchAll();
        }

        $nutritionTotals = $this->calculateIngredientsNutrition($ingredients, $clinicId ?? (TenantContext::hasClinic() ? TenantContext::getClinicId() : null));
        $kcalPerServing = round($nutritionTotals['energy_kcal'] / $servings, 2);
        $proteinPerServing = round($nutritionTotals['protein_g'] / $servings, 2);
        $carbsPerServing = round(($nutritionTotals['carbohydrate_g'] ?? $nutritionTotals['carbs_g'] ?? 0) / $servings, 2);
        $fatPerServing = round(($nutritionTotals['lipid_g'] ?? $nutritionTotals['fat_g'] ?? 0) / $servings, 2);
        $fiberPerServing = round($nutritionTotals['fiber_g'] / $servings, 2);
        $sodiumPerServing = round($nutritionTotals['sodium_mg'] / $servings, 2);

        $this->db->beginTransaction();
        try {
            // 2. Atualizar tabela principal de receitas
            $upStmt = $this->db->prepare("
                UPDATE recipes
                SET title = :title,
                    servings = :servings,
                    prep_time_minutes = :prep,
                    cook_time_minutes = :cook,
                    instructions = :instructions,
                    meal_type = :meal_type,
                    difficulty = :difficulty,
                    summary = :summary,
                    serving_size_name = :serving_name,
                    serving_size_g = :serving_g,
                    energy_kcal_per_serving = :kcal,
                    protein_g_per_serving = :protein,
                    carbs_g_per_serving = :carbs,
                    fat_g_per_serving = :fat,
                    fiber_g_per_serving = :fiber,
                    sodium_mg_per_serving = :sodium,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $upStmt->execute([
                'title' => $cleanTitle,
                'servings' => $servings,
                'prep' => $prepTime,
                'cook' => $cookTime,
                'instructions' => $instructions,
                'meal_type' => $mealType,
                'difficulty' => $difficulty,
                'summary' => $summary,
                'serving_name' => $servingSizeName,
                'serving_g' => $servingSizeG,
                'kcal' => $kcalPerServing,
                'protein' => $proteinPerServing,
                'carbs' => $carbsPerServing,
                'fat' => $fatPerServing,
                'fiber' => $fiberPerServing,
                'sodium' => $sodiumPerServing,
                'id' => $recipeId,
                'clinic_id' => $clinicId,
            ]);

            // Se ingredientes foram atualizados, recriar os ingredientes da receita
            if (isset($data['ingredients'])) {
                $this->db->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = ?")->execute([$recipeId]);
                $insIng = $this->db->prepare("
                    INSERT INTO recipe_ingredients (
                        recipe_id, food_id, amount, measure_unit,
                        gross_weight_g, net_weight_g, correction_factor, cooking_factor, notes, ordering
                    ) VALUES (
                        :recipe_id, :food_id, :amount, :measure_unit,
                        :gross_w, :net_w, :fc, :fcook, :notes, :ordering
                    )
                ");
                $idx = 0;
                foreach ($ingredients as $ing) {
                    $idx++;
                    $calc = $ing['_calc'] ?? [];
                    $amt = (float)($ing['amount'] ?? $ing['quantity'] ?? 100);
                    $insIng->execute([
                        'recipe_id' => $recipeId,
                        'food_id' => (int)($ing['food_id'] ?? 0),
                        'amount' => $amt,
                        'measure_unit' => trim((string)($ing['measure_unit'] ?? $ing['unit'] ?? 'g')),
                        'gross_w' => $calc['weight_grams'] ?? $amt,
                        'net_w' => $calc['weight_grams'] ?? $amt,
                        'fc' => (float)($ing['correction_factor'] ?? 1.0),
                        'fcook' => (float)($ing['cooking_factor'] ?? 1.0),
                        'notes' => $ing['notes'] ?? null,
                        'ordering' => $idx,
                    ]);
                }
            }

            // 3. Obter próximo version_number
            $vNumStmt = $this->db->prepare("SELECT COALESCE(MAX(version_number), 1) + 1 FROM recipe_versions WHERE recipe_id = ?");
            $vNumStmt->execute([$recipeId]);
            $nextVersion = (int)$vNumStmt->fetchColumn();

            // Marcar versões anteriores como 'superseded'
            $this->db->prepare("UPDATE recipe_versions SET status = 'superseded' WHERE recipe_id = ?")->execute([$recipeId]);

            // 4. Inserir nova versão imutável
            $currentDetails = $this->getRecipeDetails($recipeId, $clinicId);
            $versionSnapshot = [
                'recipe_id' => $recipeId,
                'version' => $nextVersion,
                'summary' => $currentDetails['summary'],
                'prep_time_minutes' => $currentDetails['prep_time_minutes'],
                'cook_time_minutes' => $currentDetails['cook_time_minutes'],
                'photo_path' => $currentDetails['photo_path'],
                'ingredients' => $currentDetails['ingredients'],
                'allergens' => $currentDetails['allergens'],
                'title' => $cleanTitle,
                'servings' => $servings,
                'instructions' => $instructions,
                'nutrition_per_serving' => [
                    'energy_kcal' => $kcalPerServing,
                    'protein_g' => $proteinPerServing,
                    'carbs_g' => $carbsPerServing,
                    'fat_g' => $fatPerServing,
                    'fiber_g' => $fiberPerServing,
                    'sodium_mg' => $sodiumPerServing,
                ],
                'updated_at' => date('c'),
            ];

            $vStmt = $this->db->prepare("
                INSERT INTO recipe_versions (
                    recipe_id, version_number, title, servings, serving_size_g,
                    energy_kcal_per_serving, protein_g_per_serving, carbs_g_per_serving,
                    fat_g_per_serving, fiber_g_per_serving, sodium_mg_per_serving,
                    payload_json, status, published_at, published_by_user_id
                ) VALUES (
                    :recipe_id, :vnum, :title, :servings, :serving_g,
                    :kcal, :protein, :carbs, :fat, :fiber, :sodium,
                    :payload, 'published', CURRENT_TIMESTAMP, :user_id
                )
            ");
            $vStmt->execute([
                'recipe_id' => $recipeId,
                'vnum' => $nextVersion,
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

            $this->db->commit();

            return [
                'id' => $recipeId,
                'version_number' => $nextVersion,
                'title' => $cleanTitle,
                'servings' => $servings,
                'energy_kcal_per_serving' => $kcalPerServing,
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
    public function getRecipeDetails(int $recipeId, ?int $clinicId = null, ?int $versionId = null): array
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

        // Se uma versão específica foi solicitada (ex: prescrição histórica - MOB-UX-002), recuperar snapshot imutável
        if ($versionId !== null) {
            $targetVStmt = $this->db->prepare("SELECT * FROM recipe_versions WHERE id = :vid AND recipe_id = :rid LIMIT 1");
            $targetVStmt->execute(['vid' => $versionId, 'rid' => $recipeId]);
            $targetVersion = $targetVStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetVersion) {
                throw new PrescriptionContentUnavailable('Versão prescrita não encontrada.');
            }
            if ($targetVersion) {
                if (!empty($targetVersion['title'])) {
                    $recipe['title'] = $targetVersion['title'];
                }
                if (!empty($targetVersion['servings'])) {
                    $recipe['servings'] = (float)$targetVersion['servings'];
                }
                if (!empty($targetVersion['serving_size_g'])) {
                    $recipe['serving_size_g'] = (float)$targetVersion['serving_size_g'];
                }
                if ($targetVersion['energy_kcal_per_serving'] !== null) {
                    $recipe['energy_kcal_per_serving'] = (float)$targetVersion['energy_kcal_per_serving'];
                }
                if ($targetVersion['protein_g_per_serving'] !== null) {
                    $recipe['protein_g_per_serving'] = (float)$targetVersion['protein_g_per_serving'];
                }
                if ($targetVersion['carbs_g_per_serving'] !== null) {
                    $recipe['carbs_g_per_serving'] = (float)$targetVersion['carbs_g_per_serving'];
                }
                if ($targetVersion['fat_g_per_serving'] !== null) {
                    $recipe['fat_g_per_serving'] = (float)$targetVersion['fat_g_per_serving'];
                }
                if ($targetVersion['fiber_g_per_serving'] !== null) {
                    $recipe['fiber_g_per_serving'] = (float)$targetVersion['fiber_g_per_serving'];
                }
                if ($targetVersion['sodium_mg_per_serving'] !== null) {
                    $recipe['sodium_mg_per_serving'] = (float)$targetVersion['sodium_mg_per_serving'];
                }

                if (empty($targetVersion['payload_json'])) {
                    throw new PrescriptionContentUnavailable('Conteúdo da versão prescrita indisponível.');
                }
                if (!empty($targetVersion['payload_json'])) {
                    $payload = json_decode($targetVersion['payload_json'], true);
                    if (!is_array($payload) || !array_key_exists('ingredients', $payload) || !is_array($payload['ingredients']) || !isset($payload['allergens']) || !is_array($payload['allergens']) || !array_key_exists('instructions', $payload)) {
                        throw new PrescriptionContentUnavailable('Esta versão histórica não possui conteúdo completo. Solicite uma nova prescrição ao nutricionista.');
                    }
                    if (is_array($payload)) {
                        foreach (['summary', 'prep_time_minutes', 'cook_time_minutes', 'photo_path'] as $field) {
                            $recipe[$field] = $payload[$field] ?? null;
                        }
                        if (array_key_exists('instructions', $payload)) {
                            $recipe['instructions'] = is_array($payload['instructions'])
                                ? implode("\n", array_map(fn($st) => is_array($st) ? ($st['instruction'] ?? '') : (string)$st, $payload['instructions']))
                                : (string)$payload['instructions'];
                            $instructionLines = preg_split('/\r?\n/', $recipe['instructions']);
                            $steps = array_map(fn($instruction, $idx) => ['instruction' => $instruction, 'ordering' => $idx + 1], $instructionLines, array_keys($instructionLines));
                        }
                        if (is_array($payload['ingredients'])) {
                            $ingredients = array_map(function($ing, $idx) {
                                return [
                                    'id' => $ing['id'] ?? ($idx + 1),
                                    'food_id' => $ing['food_id'] ?? 0,
                                    'food_name' => $ing['food_name'] ?? ($ing['name'] ?? 'Ingrediente'),
                                    'amount' => $ing['amount'] ?? 0,
                                    'measure_unit' => $ing['measure_unit'] ?? 'g',
                                    'weight_grams' => $ing['weight_grams'] ?? $ing['net_weight_g'] ?? ($ing['amount'] ?? 0),
                                    'notes' => $ing['notes'] ?? null,
                                    'energy_kcal' => $ing['energy_kcal'] ?? 0,
                                    'protein_g' => $ing['protein_g'] ?? 0,
                                    'carbohydrate_g' => $ing['carbohydrate_g'] ?? 0,
                                    'lipid_g' => $ing['lipid_g'] ?? 0,
                                ];
                            }, $payload['ingredients'], array_keys($payload['ingredients']));
                        }
                        if (is_array($payload['allergens'])) {
                            $allergens = $payload['allergens'];
                        }
                    }
                }
                $recipe['prescribed_version_id'] = (int)$targetVersion['id'];
                $recipe['prescribed_version_number'] = (int)$targetVersion['version_number'];
            }
        }

        return array_merge($recipe, [
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tags' => $tags,
            'allergens' => $allergens,
            'versions' => $versions,
            'current_version' => $versions ? (int)$versions[0]['version_number'] : 1,
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
     * F01 (auditoria 14/10/2026): fluxo completo de prescrição a partir do formulário web.
     *
     * 1. Valida paciente e profissional na clínica da sessão ANTES de qualquer leitura/escrita de plano.
     * 2. Busca o plano por clinic_id + patient_id (nunca só por patient_id).
     * 3. Criação do plano (se necessária) + prescrição numa única transação, com rollback em qualquer erro.
     *
     * @throws TenantSecurityException (403) paciente/profissional/plano de outra clínica ou inexistente
     */
    public function prescribeRecipeWithPlan(
        int $clinicId,
        int $professionalId,
        int $patientId,
        int $mealPlanId,
        int $recipeId,
        float $servings = 1.0,
        ?string $instructionsOverride = null,
        ?string $mealName = null
    ): array {
        if ($clinicId <= 0 || $patientId <= 0) {
            throw new TenantSecurityException("Acesso negado: paciente inválido para a clínica ativa.", 403);
        }
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        if ($professionalId > 0) {
            TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        } else {
            throw new TenantSecurityException("Acesso negado: profissional sem vínculo com a clínica ativa.", 403);
        }

        if ($mealPlanId > 0) {
            $chk = $this->db->prepare("SELECT id FROM meal_plans WHERE id = :id AND clinic_id = :cid AND patient_id = :pid");
            $chk->execute(['id' => $mealPlanId, 'cid' => $clinicId, 'pid' => $patientId]);
            if (!$chk->fetchColumn()) {
                throw new TenantSecurityException("Acesso negado: plano alimentar não pertence a este paciente/clínica.", 403);
            }
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            if ($mealPlanId <= 0) {
                $stmt = $this->db->prepare("SELECT id FROM meal_plans WHERE clinic_id = :cid AND patient_id = :pid ORDER BY id DESC LIMIT 1");
                $stmt->execute(['cid' => $clinicId, 'pid' => $patientId]);
                $mealPlanId = (int)$stmt->fetchColumn();

                if ($mealPlanId <= 0) {
                    $cStmt = $this->db->prepare("
                        INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status, created_at, updated_at)
                        VALUES (:cid, :pid, :prid, 'Plano Alimentar Individualizado', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $cStmt->execute(['cid' => $clinicId, 'pid' => $patientId, 'prid' => $professionalId]);
                    $mealPlanId = (int)$this->db->lastInsertId();
                }
            }

            $result = $this->prescribeRecipe($clinicId, $mealPlanId, null, $patientId, $recipeId, $servings, $instructionsOverride, $mealName);
            if ($ownTx) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
        $conflicts = $this->checkPatientAllergenConflicts($patientId, $recipe['allergens'], $clinicId);

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
            SELECT rp.*, 
                   COALESCE(rv.title, r.title) AS recipe_title, 
                   r.summary AS recipe_summary,
                   COALESCE(rv.energy_kcal_per_serving, r.energy_kcal_per_serving) AS energy_kcal_per_serving,
                   COALESCE(rv.protein_g_per_serving, r.protein_g_per_serving) AS protein_g_per_serving,
                   COALESCE(rv.carbs_g_per_serving, r.carbs_g_per_serving) AS carbs_g_per_serving,
                   COALESCE(rv.fat_g_per_serving, r.fat_g_per_serving) AS fat_g_per_serving,
                   COALESCE(rv.servings, r.servings) AS recipe_servings,
                   r.prep_time_minutes, r.cook_time_minutes, 
                   r.instructions, r.photo_path,
                   rv.payload_json AS prescribed_payload,
                   rv.version_number AS recipe_version_number
            FROM recipe_prescriptions rp
            JOIN recipes r ON r.id = rp.recipe_id
            LEFT JOIN recipe_versions rv ON rv.id = rp.recipe_version_id
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
            $payload = json_decode($p['prescribed_payload'] ?? '{}', true) ?: [];
            $p['recipe_summary'] = $payload['summary'] ?? null;
            foreach (['prep_time_minutes', 'cook_time_minutes', 'instructions', 'photo_path'] as $field) {
                $p[$field] = $payload[$field] ?? null;
            }
            unset($p['prescribed_payload']);
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
        // Reauditoria (varredura): o paciente só pode favoritar receita visível à própria clínica
        // (da clínica ou pública). Receita privada de outra clínica ou inexistente: mesma exceção.
        $pc = $this->db->prepare("SELECT clinic_id FROM patients WHERE id = :pid");
        $pc->execute(['pid' => $patientId]);
        $patientClinic = $pc->fetchColumn();
        $vis = $this->db->prepare("SELECT id FROM recipes WHERE id = :rid AND (clinic_id = :cid OR visibility = 'public')");
        $vis->execute(['rid' => $recipeId, 'cid' => (int)$patientClinic]);
        if ($patientClinic === false || !$vis->fetchColumn()) {
            throw new TenantSecurityException('Receita não encontrada.', 404);
        }

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
            JOIN patients p ON p.id = f.patient_id
            WHERE f.patient_id = :pid AND (r.clinic_id = p.clinic_id OR r.visibility = 'public')
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
     * Lista de alimentos visíveis para a clínica: catálogo global (clinic_id NULL)
     * + alimentos particulares da própria clínica. Mesma regra do FoodCatalogService (F02).
     */
    public function listFoodsForClinic(?int $clinicId, int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->db->prepare("SELECT id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods WHERE (clinic_id IS NULL OR clinic_id = :clinic_id) ORDER BY name ASC LIMIT {$limit}");
        $stmt->execute(['clinic_id' => $clinicId ?? 0]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rejeita IDs de alimentos que não sejam globais nem da clínica informada (F02).
     * Não diferencia "inexistente" de "de outra clínica" para não revelar catálogos alheios.
     */
    public function assertFoodsAvailableForClinic(array $foodIds, ?int $clinicId): void
    {
        foreach (array_unique($foodIds) as $foodId) {
            $foodId = (int)$foodId;
            $stmt = $this->db->prepare("SELECT 1 FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :clinic_id)");
            $stmt->execute(['id' => $foodId, 'clinic_id' => $clinicId ?? 0]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException("Alimento ID #{$foodId} não encontrado no catálogo disponível para esta clínica.");
            }
        }
    }

    /**
     * Calcula a composição nutricional dos ingredientes usando alimentos oficiais (TACO).
     */
    private function calculateIngredientsNutrition(array &$ingredients, ?int $clinicId = null): array
    {
        // F02 (auditoria 14/10/2026): todos os IDs são autorizados ANTES de qualquer cálculo.
        $this->assertFoodsAvailableForClinic(array_map(static fn($i) => (int)($i['food_id'] ?? 0), $ingredients), $clinicId);

        $totalWeightG = 0.0;
        $totalKcal = 0.0;
        $totalProt = 0.0;
        $totalCarb = 0.0;
        $totalLip = 0.0;
        $totalFib = 0.0;
        $totalSodium = 0.0;

        foreach ($ingredients as &$ing) {
            $foodId = (int)$ing['food_id'];
            $amount = (float)($ing['amount'] ?? $ing['quantity'] ?? 0);
            $unit = trim((string)($ing['measure_unit'] ?? $ing['unit'] ?? 'g'));

            $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :clinic_id)");
            $fStmt->execute(['id' => $foodId, 'clinic_id' => $clinicId ?? 0]);
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
    private function resolveRecipeAllergens(array $ingredients, array $explicitAllergens = [], ?int $clinicId = null): array
    {
        $resolved = [];
        $seen = [];

        foreach ($ingredients as $ing) {
            $fStmt = $this->db->prepare("SELECT allergens FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :clinic_id)");
            $fStmt->execute(['id' => (int)$ing['food_id'], 'clinic_id' => $clinicId ?? 0]);
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
     * Contexto clínico do paciente para o formulário de prescrição (reauditoria V2-01).
     * Exige tenant: valida o paciente contra a clínica informada (TenantGuard) antes de ler
     * planos e anamnese, e as consultas filtram clinic_id como segunda barreira.
     *
     * @return array{plans: list<array{id:int,title:string}>, allergies: list<string>}
     * @throws TenantSecurityException paciente inexistente ou de outra clínica
     */
    public function getPrescriptionPatientContext(int $patientId, int $clinicId): array
    {
        if ($patientId <= 0 || $clinicId <= 0) {
            throw new TenantSecurityException('Paciente não encontrado.', 404);
        }
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $pl = $this->db->prepare("SELECT id, title FROM meal_plans WHERE patient_id = :pid AND clinic_id = :cid ORDER BY id DESC");
        $pl->execute(['pid' => $patientId, 'cid' => $clinicId]);
        $plans = $pl->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $an = $this->db->prepare("SELECT allergies, restrictions FROM anamneses WHERE patient_id = :pid AND clinic_id = :cid ORDER BY id DESC LIMIT 1");
        $an->execute(['pid' => $patientId, 'cid' => $clinicId]);
        $row = $an->fetch(PDO::FETCH_ASSOC);
        $allergies = [];
        if ($row) {
            $raw = trim((string)($row['allergies'] ?? '') . ' ' . (string)($row['restrictions'] ?? ''));
            if ($raw !== '') {
                $allergies = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $raw))));
            }
        }
        return ['plans' => $plans, 'allergies' => $allergies];
    }

    /**
     * Verifica possíveis incompatibilidades entre alergênicos da receita e anamnese do paciente.
     */
    private function checkPatientAllergenConflicts(int $patientId, array $recipeAllergens, int $clinicId): array
    {
        $stmt = $this->db->prepare("SELECT allergies, restrictions FROM anamneses WHERE patient_id = :pid AND clinic_id = :cid ORDER BY id DESC LIMIT 1");
        $stmt->execute(['pid' => $patientId, 'cid' => $clinicId]);
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
