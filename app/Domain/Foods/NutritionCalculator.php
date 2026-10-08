<?php

declare(strict_types=1);

namespace App\Domain\Foods;

use InvalidArgumentException;

/**
 * Calculadora Nutricional de Precisão Clínica (ClyoNutri II).
 *
 * Regras estritas:
 * 1. Base canônica de referência nutricional é SEMPRE 100 g (TACO / USDA).
 * 2. Conversão proporcional exata: valor = (quantidade_g / 100.0) * valor_por_100g.
 * 3. Rejeita unidades negativas ou incompatíveis.
 */
class NutritionCalculator
{
    /**
     * Calcula a porção consumida a partir de um valor de referência por 100g.
     *
     * @param float $amountG Quantidade do alimento em gramas
     * @param float $valuePer100g Valor nutricional do nutriente por 100g
     * @return float Valor proporcional calculado
     */
    public static function calculatePortion(float $amountG, float $valuePer100g): float
    {
        if ($amountG < 0 || $valuePer100g < 0) {
            throw new InvalidArgumentException("Quantidades e valores nutricionais não podem ser negativos.");
        }

        if ($amountG === 0.0 || $valuePer100g === 0.0) {
            return 0.0;
        }

        return round(($amountG / 100.0) * $valuePer100g, 2);
    }

    /**
     * Calcula o total de macronutrientes e calorias de uma porção.
     *
     * @param float $amountG Gramas consumidas
     * @param array $nutrientsPer100g Array com 'calories', 'protein_g', 'carbs_g', 'fats_g', 'fiber_g'
     * @return array
     */
    public static function calculateNutrientTotals(float $amountG, array $nutrientsPer100g): array
    {
        $factor = $amountG / 100.0;

        return [
            'amount_g' => $amountG,
            'calories' => round(($nutrientsPer100g['calories'] ?? 0.0) * $factor, 1),
            'protein_g' => round(($nutrientsPer100g['protein_g'] ?? 0.0) * $factor, 2),
            'carbs_g' => round(($nutrientsPer100g['carbs_g'] ?? 0.0) * $factor, 2),
            'fats_g' => round(($nutrientsPer100g['fats_g'] ?? 0.0) * $factor, 2),
            'fiber_g' => round(($nutrientsPer100g['fiber_g'] ?? 0.0) * $factor, 2),
        ];
    }
}
