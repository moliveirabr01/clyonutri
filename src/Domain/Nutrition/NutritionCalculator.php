<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use InvalidArgumentException;
use PDO;

/**
 * Nutrientes de uma porção a partir da composição por 100 g (NI2-74).
 * Regras e arredondamento em docs/clinical/CONVERSAO_DE_PORCOES_NI2-74.md:
 *   gramas = conversão da unidade (HouseholdMeasureConverter); nutriente = valor por 100 g × gramas / 100;
 *   cada nutriente do item é arredondado em 2 casas (meio para cima); o total soma os itens já arredondados.
 * Nutriente sem análise na TACO (NA, *, em branco) entra como 0 e é listado em `unknown_nutrients`.
 */
class NutritionCalculator
{
    public const NUTRIENTS = ['energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g'];

    private HouseholdMeasureConverter $converter;
    private ?PDO $db;

    public function __construct(?PDO $db = null, ?HouseholdMeasureConverter $converter = null)
    {
        $this->db = $db;
        $this->converter = $converter ?? new HouseholdMeasureConverter($db);
    }

    public function converter(): HouseholdMeasureConverter
    {
        return $this->converter;
    }

    /**
     * @param array $foodBase linha de foods: nutrientes por 100 g, name, serving_unit, serving_size_g, nutrient_flags
     * @param bool $confirmEstimated true = a nutricionista confirmou usar o peso padrão quando não há medida do alimento
     * @throws UnitConfirmationRequiredException|InvalidArgumentException
     */
    public function calculateForPortion(array $foodBase, float $amount, string $unit = 'g', ?int $foodId = null, bool $confirmEstimated = false): array
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException("A quantidade consumida/prescrita deve ser estritamente positiva.");
        }

        $conversion = $this->converter->convertDetailed($amount, $unit, $foodId, $foodBase, $confirmEstimated);
        $grams = $conversion['grams'];
        $factor = $grams / 100.0;

        $out = [
            'amount_input' => $amount,
            'unit_input' => $unit,
            'weight_grams' => round($grams, 2),
            'weight_is_estimated' => $conversion['estimated'],
            'weight_source' => $conversion['source'],
            'grams_per_unit' => $conversion['grams_per_unit'],
        ];
        foreach (self::NUTRIENTS as $n) {
            $out[$n] = round((float)($foodBase[$n] ?? 0) * $factor, 2);
        }
        $out['unknown_nutrients'] = self::unknownNutrients($foodBase['nutrient_flags'] ?? null);
        return $out;
    }

    /**
     * Nutrientes sem valor analisado na TACO (NA = não analisado, * = não aplicável/dúvida, em branco).
     * "Tr" (traços) é considerado zero de fato e não entra.
     * @return list<string>
     */
    public static function unknownNutrients(?string $flags): array
    {
        $out = [];
        foreach (explode(';', (string)$flags) as $f) {
            [$k, $v] = array_pad(explode('=', trim($f), 2), 2, '');
            if (in_array($k, self::NUTRIENTS, true) && in_array($v, ['NA', '*', 'em_branco'], true)) {
                $out[] = $k;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Total de um conjunto de itens: soma dos valores de cada item já arredondados (2 casas), arredondada de novo.
     * Itens: ['food' => linha de foods, 'amount', 'measure_unit', 'food_id', 'confirm_estimated' (opcional)].
     */
    public function calculateMealTotals(array $itemsWithFoodBases): array
    {
        $tot = array_fill_keys(self::NUTRIENTS, 0.0);
        $weight = 0.0;
        $unknown = [];
        foreach ($itemsWithFoodBases as $item) {
            $calc = $this->calculateForPortion($item['food'], (float)$item['amount'], $item['measure_unit'] ?? 'g',
                isset($item['food_id']) ? (int)$item['food_id'] : null, !empty($item['confirm_estimated']));
            $weight += $calc['weight_grams'];
            foreach (self::NUTRIENTS as $n) {
                $tot[$n] += $calc[$n];
            }
            foreach ($calc['unknown_nutrients'] as $u) {
                $unknown[$u] = true;
            }
        }
        $out = ['total_weight_grams' => round($weight, 2)];
        foreach (self::NUTRIENTS as $n) {
            $out[$n] = round($tot[$n], 2);
        }
        $out['unknown_nutrients'] = array_keys($unknown);
        return $out;
    }
}
