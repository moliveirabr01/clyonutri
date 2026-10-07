<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Recipe;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class RecipeService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function createRecipe(array $data, array $ingredients): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;

        $this->db->beginTransaction();
        try {
            $servings = max(1, (int)($data['servings'] ?? 1));

            // Calcular calorias e macros totais a partir dos ingredientes
            $totalKcal = 0.0;
            $totalProtein = 0.0;
            $totalCarbs = 0.0;
            $totalFat = 0.0;

            foreach ($ingredients as $ing) {
                $fStmt = $this->db->prepare("SELECT energy_kcal, protein_g, carbohydrate_g, lipid_g, serving_size_g FROM foods WHERE id = :id");
                $fStmt->execute(['id' => $ing['food_id']]);
                $f = $fStmt->fetch();
                if ($f && (float)$f['serving_size_g'] > 0) {
                    $ratio = (float)$ing['amount'] / (float)$f['serving_size_g'];
                    $totalKcal += $ratio * (float)$f['energy_kcal'];
                    $totalProtein += $ratio * (float)$f['protein_g'];
                    $totalCarbs += $ratio * (float)$f['carbohydrate_g'];
                    $totalFat += $ratio * (float)$f['lipid_g'];
                }
            }

            $stmt = $this->db->prepare("
                INSERT INTO recipes (
                    clinic_id, title, category, prep_time_minutes, servings, instructions,
                    energy_kcal_per_serving, protein_g_per_serving, carbs_g_per_serving, fat_g_per_serving, is_custom
                ) VALUES (
                    :clinic_id, :title, :category, :prep_time, :servings, :instructions,
                    :kcal, :protein, :carbs, :fat, :is_custom
                )
            ");

            $stmt->execute([
                'clinic_id' => $clinicId,
                'title' => trim($data['title']),
                'category' => $data['category'] ?? 'Prato Principal',
                'prep_time' => (int)($data['prep_time_minutes'] ?? 20),
                'servings' => $servings,
                'instructions' => trim($data['instructions']),
                'kcal' => round($totalKcal / $servings, 2),
                'protein' => round($totalProtein / $servings, 2),
                'carbs' => round($totalCarbs / $servings, 2),
                'fat' => round($totalFat / $servings, 2),
                'is_custom' => $clinicId !== null ? 1 : 0,
            ]);

            $recipeId = (int)$this->db->lastInsertId();

            $iStmt = $this->db->prepare("
                INSERT INTO recipe_ingredients (recipe_id, food_id, amount, measure_unit, notes)
                VALUES (:recipe_id, :food_id, :amount, :measure_unit, :notes)
            ");

            foreach ($ingredients as $ing) {
                $iStmt->execute([
                    'recipe_id' => $recipeId,
                    'food_id' => $ing['food_id'],
                    'amount' => $ing['amount'],
                    'measure_unit' => $ing['measure_unit'] ?? 'g',
                    'notes' => $ing['notes'] ?? null,
                ]);
            }

            $this->db->commit();

            return [
                'id' => $recipeId,
                'title' => $data['title'],
                'servings' => $servings,
                'energy_kcal_per_serving' => round($totalKcal / $servings, 2),
                'protein_g_per_serving' => round($totalProtein / $servings, 2),
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function listRecipes(): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;
        $stmt = $this->db->prepare("
            SELECT * FROM recipes
            WHERE is_custom = 0 OR clinic_id = :clinic_id
            ORDER BY is_custom DESC, title ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll();
    }
}
