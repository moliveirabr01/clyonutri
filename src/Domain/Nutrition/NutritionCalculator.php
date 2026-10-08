<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use InvalidArgumentException;
use PDO;

class NutritionCalculator
{
    private HouseholdMeasureConverter $converter;
    private ?PDO $db;

    public function __construct(?PDO $db = null, ?HouseholdMeasureConverter $converter = null)
    {
        $this->db = $db;
        $this->converter = $converter ?? new HouseholdMeasureConverter($db);
    }

    /**
     * Calcula o valor nutricional proporcional a partir de uma base de 100g.
     *
     * @param array $foodBase Dados nutricionais por 100g (energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
     * @param float $amount Quantidade
     * @param string $unit Unidade de medida ('g', 'colher de sopa', etc.)
     * @param int|null $foodId ID do alimento para medidas caseiras específicas
     * @return array Nutrientes calculados com precisão
     */
    public function calculateForPortion(array $foodBase, float $amount, string $unit = 'g', ?int $foodId = null): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade consumida/prescrita deve ser estritamente positiva.");
        }

        // Converter para gramas
        $grams = $this->converter->convertToGrams($amount, $unit, $foodId);

        // Fator em relação à base de 100g
        $factor = $grams / 100.0;

        $kcal100g = (float)($foodBase['energy_kcal'] ?? 0);
        $prot100g = (float)($foodBase['protein_g'] ?? 0);
        $carb100g = (float)($foodBase['carbohydrate_g'] ?? 0);
        $lip100g  = (float)($foodBase['lipid_g'] ?? 0);
        $fib100g  = (float)($foodBase['fiber_g'] ?? 0);

        return [
            'amount_input' => $amount,
            'unit_input' => $unit,
            'weight_grams' => round($grams, 2),
            'energy_kcal' => round($kcal100g * $factor, 2),
            'protein_g' => round($prot100g * $factor, 2),
            'carbohydrate_g' => round($carb100g * $factor, 2),
            'lipid_g' => round($lip100g * $factor, 2),
            'fiber_g' => round($fib100g * $factor, 2),
        ];
    }

    /**
     * Calcula o total consolidado de um conjunto de itens de refeição
     */
    public function calculateMealTotals(array $itemsWithFoodBases): array
    {
        $totalWeight = 0.0;
        $totalKcal = 0.0;
        $totalProt = 0.0;
        $totalCarb = 0.0;
        $totalLip = 0.0;
        $totalFib = 0.0;

        foreach ($itemsWithFoodBases as $item) {
            $foodBase = $item['food'];
            $amount = (float)$item['amount'];
            $unit = $item['measure_unit'] ?? 'g';
            $foodId = isset($item['food_id']) ? (int)$item['food_id'] : null;

            $itemNutrition = $this->calculateForPortion($foodBase, $amount, $unit, $foodId);

            $totalWeight += $itemNutrition['weight_grams'];
            $totalKcal += $itemNutrition['energy_kcal'];
            $totalProt += $itemNutrition['protein_g'];
            $totalCarb += $itemNutrition['carbohydrate_g'];
            $totalLip += $itemNutrition['lipid_g'];
            $totalFib += $itemNutrition['fiber_g'];
        }

        return [
            'total_weight_grams' => round($totalWeight, 2),
            'energy_kcal' => round($totalKcal, 2),
            'protein_g' => round($totalProt, 2),
            'carbohydrate_g' => round($totalCarb, 2),
            'lipid_g' => round($totalLip, 2),
            'fiber_g' => round($totalFib, 2),
        ];
    }
}
