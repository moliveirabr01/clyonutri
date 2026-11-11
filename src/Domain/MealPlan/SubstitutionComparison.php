<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

/**
 * Comparação aritmética entre um item do plano e uma substituição (NI2-519).
 *
 * Não é tabela de equivalência: só mostra a diferença de energia e macronutrientes já calculados pelo sistema e,
 * para ajudar a ajustar a quantidade, a porção que daria a mesma energia do item. Essa sugestão considera só kcal;
 * a regra de exibição está pendente de revisão por nutricionista (ver docs/benchmark-webdiet/ROTEIRO-AVALIACAO-CLINICA-V42.md).
 */
final class SubstitutionComparison
{
    public const KEYS = ['energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g'];

    /** Diferença substituição menos item; null quando um dos lados não é número finito. */
    public static function delta(array $item, array $sub): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $a = self::num($item[$k] ?? null);
            $b = self::num($sub[$k] ?? null);
            $out[$k] = ($a === null || $b === null) ? null : round($b - $a, 1);
        }
        return $out;
    }

    /**
     * Gramas do alimento substituto que dariam a mesma energia do item. Null quando falta dado, quando a energia
     * do substituto é zero (água, chá) ou quando o resultado não é finito.
     */
    public static function sameEnergyGrams(float|int|string|null $itemKcal, float|int|string|null $subKcalPer100g): ?float
    {
        $target = self::num($itemKcal);
        $per100 = self::num($subKcalPer100g);
        if ($target === null || $per100 === null || $target <= 0 || $per100 <= 0) {
            return null;
        }
        $g = $target / $per100 * 100;
        if (!is_finite($g) || $g <= 0 || $g > 5000) {
            return null;
        }
        return round($g, 0);
    }

    /** Texto curto, ex.: "+12 kcal · P -1,5 g · C +3,0 g · G 0,0 g". */
    public static function label(array $delta): string
    {
        $parts = [];
        $names = ['energy_kcal' => ['', ' kcal', 0], 'protein_g' => ['P ', ' g', 1], 'carbohydrate_g' => ['C ', ' g', 1], 'lipid_g' => ['G ', ' g', 1]];
        foreach ($names as $k => [$prefix, $suffix, $dec]) {
            if (!array_key_exists($k, $delta) || $delta[$k] === null) {
                continue;
            }
            $v = (float)$delta[$k];
            $sign = $v > 0 ? '+' : ($v < 0 ? '-' : '');
            $parts[] = $prefix . $sign . number_format(abs($v), $dec, ',', '.') . $suffix;
        }
        return implode(' · ', $parts);
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $f = (float)$v;
        return is_finite($f) ? $f : null;
    }
}
