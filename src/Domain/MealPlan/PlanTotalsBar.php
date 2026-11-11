<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

/**
 * Dados da barra fixa de totais do editor de plano (NI2-521): total do dia contra a meta gravada (VET e macros).
 * Adequação = realizado / meta x 100, calculada só quando a meta existe e é maior que zero; nunca divide por zero
 * nem mostra NaN. Faixas: dentro da tolerância da meta, acima ou abaixo. A tolerância é a mesma do comparativo da v41
 * (EnergyCalculator::TOLERANCE_PCT, 5%) e está pendente de revisão por nutricionista.
 */
final class PlanTotalsBar
{
    public const TOLERANCE_PCT = \ClyoNutri\Domain\Nutrition\EnergyCalculator::TOLERANCE_PCT;

    /**
     * @param array $plan  linha do plano (daily_energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g)
     * @param ?array $target meta da v41 (vet_kcal, protein_g, carbohydrate_g, lipid_g) ou null
     */
    public static function build(array $plan, ?array $target): array
    {
        $map = [
            'energy' => ['label' => 'Energia', 'unit' => 'kcal', 'actual' => 'daily_energy_kcal', 'target' => 'vet_kcal', 'dec' => 0],
            'carbohydrate' => ['label' => 'CHO', 'unit' => 'g', 'actual' => 'carbohydrate_g', 'target' => 'carbohydrate_g', 'dec' => 1],
            'protein' => ['label' => 'PTN', 'unit' => 'g', 'actual' => 'protein_g', 'target' => 'protein_g', 'dec' => 1],
            'lipid' => ['label' => 'LIP', 'unit' => 'g', 'actual' => 'lipid_g', 'target' => 'lipid_g', 'dec' => 1],
        ];
        $rows = [];
        foreach ($map as $key => $m) {
            $actual = self::num($plan[$m['actual']] ?? 0) ?? 0.0;
            $goal = $target !== null ? self::num($target[$m['target']] ?? null) : null;
            $pct = self::adequacy($actual, $goal);
            $rows[$key] = [
                'label' => $m['label'],
                'unit' => $m['unit'],
                'actual' => $actual,
                'target' => ($goal !== null && $goal > 0) ? $goal : null,
                'pct' => $pct,
                'status' => self::status($pct),
                'text' => self::text($actual, ($goal !== null && $goal > 0) ? $goal : null, $pct, $m['unit'], $m['dec']),
            ];
        }
        $fiber = self::num($plan['fiber_g'] ?? 0) ?? 0.0;
        return [
            'rows' => $rows,
            'fiber_g' => $fiber,
            'has_target' => $target !== null && (self::num($target['vet_kcal'] ?? null) ?? 0) > 0,
        ];
    }

    public static function adequacy(float $actual, ?float $target): ?float
    {
        if ($target === null || !is_finite($target) || $target <= 0 || !is_finite($actual)) {
            return null;
        }
        $pct = $actual / $target * 100;
        return is_finite($pct) ? round($pct, 0) : null;
    }

    public static function status(?float $pct): string
    {
        if ($pct === null) {
            return 'sem-meta';
        }
        if ($pct > 100 + self::TOLERANCE_PCT) {
            return 'acima';
        }
        if ($pct < 100 - self::TOLERANCE_PCT) {
            return 'abaixo';
        }
        return 'ok';
    }

    private static function text(float $actual, ?float $goal, ?float $pct, string $unit, int $dec): string
    {
        $a = number_format($actual, $dec, ',', '.');
        if ($goal === null) {
            return $a . ' ' . $unit;
        }
        return $a . ' de ' . number_format($goal, $dec, ',', '.') . ' ' . $unit . ($pct !== null ? ' (' . number_format($pct, 0, ',', '.') . '%)' : '');
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
