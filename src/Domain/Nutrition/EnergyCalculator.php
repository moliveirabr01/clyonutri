<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use InvalidArgumentException;

/**
 * Cálculo de gasto energético (TMB, GET e VET) e das metas de macronutrientes (NI2-514, Fase 1).
 *
 * Função pura, sem banco. Cada fórmula traz a referência, de modo que a prescrição guarda o que foi usado.
 * Isto NÃO é validação clínica: as fórmulas, as faixas de atividade e as tolerâncias abaixo precisam ser
 * conferidas pelo nutricionista responsável antes do uso em pacientes. O profissional escolhe a fórmula,
 * o fator de atividade e o ajuste do objetivo; o sistema só calcula, registra e avisa.
 *
 * Fórmulas (TMB em kcal/dia; peso em kg, altura em cm, idade em anos completos):
 *   Mifflin-St Jeor (Mifflin et al., Am J Clin Nutr 1990;51:241-247)
 *     homens:   10*peso + 6,25*altura - 5*idade + 5
 *     mulheres: 10*peso + 6,25*altura - 5*idade - 161
 *   Harris-Benedict revisada (Roza e Shizgal, Am J Clin Nutr 1984;40:168-182)
 *     homens:   88,362 + 13,397*peso + 4,799*altura - 5,677*idade
 *     mulheres: 447,593 + 9,247*peso + 3,098*altura - 4,330*idade
 *   Cunningham (Cunningham, Am J Clin Nutr 1991;54:963-969, a partir de 1980): 500 + 22*massa livre de gordura (kg)
 *
 * GET = TMB * fator de atividade (NAF/PAL). Faixas de referência do relatório FAO/OMS/UNU de 2001
 * (Human energy requirements, Roma, 2004): estilo de vida sedentário ou com atividade leve 1,40 a 1,69;
 * ativo ou moderadamente ativo 1,70 a 1,99; vigoroso 2,00 a 2,40.
 * VET = GET + ajuste do objetivo (kcal ou % do GET, com sinal), decidido pelo profissional.
 *
 * Macros: kcal por grama pelos fatores gerais de Atwater (proteína 4, carboidrato 4, lipídio 9).
 */
final class EnergyCalculator
{
    public const FORMULA_VERSION = 'v41-1';
    public const KCAL_PER_G = ['protein' => 4.0, 'carbohydrate' => 4.0, 'lipid' => 9.0];
    /** Diferença aceita entre o VET e a soma dos macros, e entre o plano e a meta. É escolha do app, não norma. */
    public const TOLERANCE_PCT = 5.0;

    public const FORMULAS = [
        'mifflin_st_jeor' => [
            'label' => 'Mifflin-St Jeor (1990)',
            'reference' => 'Mifflin et al., Am J Clin Nutr 1990;51:241-247',
            'needs_ffm' => false,
        ],
        'harris_benedict_revised' => [
            'label' => 'Harris-Benedict revisada (1984)',
            'reference' => 'Roza e Shizgal, Am J Clin Nutr 1984;40:168-182',
            'needs_ffm' => false,
        ],
        'cunningham' => [
            'label' => 'Cunningham (massa livre de gordura)',
            'reference' => 'Cunningham, Am J Clin Nutr 1991;54:963-969',
            'needs_ffm' => true,
        ],
    ];

    /** Valores de referência dentro das faixas FAO/OMS/UNU 2001. O profissional pode digitar outro valor. */
    public const ACTIVITY_PRESETS = [
        '1.40' => 'Sedentário (1,40; faixa 1,40 a 1,69)',
        '1.55' => 'Atividade leve (1,55; faixa 1,40 a 1,69)',
        '1.75' => 'Moderadamente ativo (1,75; faixa 1,70 a 1,99)',
        '2.00' => 'Muito ativo (2,00; faixa 2,00 a 2,40)',
    ];
    public const ACTIVITY_FAO_MIN = 1.40;
    public const ACTIVITY_FAO_MAX = 2.40;
    public const ACTIVITY_SOURCE = 'FAO/OMS/UNU 2001 (Human energy requirements), faixas de NAF';

    public const GOALS = [
        'maintenance' => 'Manutenção do peso',
        'loss' => 'Redução de peso',
        'gain' => 'Ganho de peso ou de massa',
        'other' => 'Outro objetivo',
    ];

    public const MACRO_MODES = [
        'percent' => 'Percentual do VET',
        'g_per_kg' => 'Gramas por kg de peso',
    ];

    public static function formulaLabel(string $code): string
    {
        return self::FORMULAS[$code]['label'] ?? $code;
    }

    /**
     * @param array{formula?:string,sex?:?string,age_years?:mixed,weight_kg?:mixed,height_cm?:mixed,ffm_kg?:mixed,
     *              activity_factor?:mixed,goal_type?:?string,adjust_mode?:?string,adjust_value?:mixed} $in
     * @return array{formula:string,tmb_kcal:float,get_kcal:float,vet_kcal:float,adjust_kcal:float,
     *               equation:string,warnings:list<string>}
     */
    public static function compute(array $in): array
    {
        $formula = (string)($in['formula'] ?? '');
        if (!isset(self::FORMULAS[$formula])) {
            throw new InvalidArgumentException('Escolha a fórmula de cálculo da TMB.');
        }
        $weight = self::number($in['weight_kg'] ?? null, 'peso', 2.0, 500.0);
        $warnings = [];

        $age = null;
        if (isset($in['age_years']) && $in['age_years'] !== '' && $in['age_years'] !== null) {
            $age = self::number($in['age_years'], 'idade', 1.0, 120.0);
        }

        if (self::FORMULAS[$formula]['needs_ffm']) {
            $ffm = self::number($in['ffm_kg'] ?? null, 'massa livre de gordura', 5.0, 300.0);
            if ($ffm >= $weight) {
                throw new InvalidArgumentException('A massa livre de gordura precisa ser menor que o peso.');
            }
            $tmb = 500.0 + 22.0 * $ffm;
            $equation = sprintf('500 + 22 x %s kg de massa livre de gordura', self::fmt($ffm));
        } else {
            $height = self::number($in['height_cm'] ?? null, 'altura', 40.0, 250.0);
            if ($age === null) {
                throw new InvalidArgumentException('Informe a idade.');
            }
            $sex = (string)($in['sex'] ?? '');
            if (!in_array($sex, ['male', 'female'], true)) {
                throw new InvalidArgumentException('Informe o sexo usado na equação (masculino ou feminino).');
            }
            if ($formula === 'mifflin_st_jeor') {
                $tmb = 10.0 * $weight + 6.25 * $height - 5.0 * $age + ($sex === 'male' ? 5.0 : -161.0);
                $equation = sprintf('10 x %s + 6,25 x %s - 5 x %s %s', self::fmt($weight), self::fmt($height), self::fmt($age), $sex === 'male' ? '+ 5' : '- 161');
            } else {
                $tmb = $sex === 'male'
                    ? 88.362 + 13.397 * $weight + 4.799 * $height - 5.677 * $age
                    : 447.593 + 9.247 * $weight + 3.098 * $height - 4.330 * $age;
                $equation = $sex === 'male'
                    ? sprintf('88,362 + 13,397 x %s + 4,799 x %s - 5,677 x %s', self::fmt($weight), self::fmt($height), self::fmt($age))
                    : sprintf('447,593 + 9,247 x %s + 3,098 x %s - 4,330 x %s', self::fmt($weight), self::fmt($height), self::fmt($age));
            }
        }

        if ($age !== null && $age < 18) {
            $warnings[] = 'Esta fórmula foi desenvolvida em adultos. Para menores de 18 anos, confira se ela é adequada.';
        }
        if ($age !== null && $age >= 65) {
            $warnings[] = 'Para idosos (65 anos ou mais), confira a perda de massa magra e avalie a adequação da equação e do aporte proteico.';
        }
        $goalNote = mb_strtolower((string)($in['goal_note'] ?? ''));
        if (str_contains($goalNote, 'gestan') || str_contains($goalNote, 'lactan') || !empty($in['is_pregnant']) || !empty($in['is_lactating'])) {
            $warnings[] = 'Em gestantes ou lactantes, confira o uso do peso pré-gestacional e o adicional energético do trimestre/lactação (FAO/OMS/UNU) no ajuste do GET.';
        }
        if ($tmb <= 0) {
            throw new InvalidArgumentException('Os dados informados resultam em uma TMB inválida. Confira peso, altura e idade.');
        }

        $factor = self::number($in['activity_factor'] ?? null, 'fator de atividade', 1.0, 2.6);
        if ($factor < self::ACTIVITY_FAO_MIN || $factor > self::ACTIVITY_FAO_MAX) {
            $warnings[] = 'O fator de atividade está fora da faixa de referência da FAO/OMS/UNU (1,40 a 2,40).';
        }
        $get = $tmb * $factor;

        $mode = (string)($in['adjust_mode'] ?? 'kcal');
        if (!in_array($mode, ['kcal', 'percent'], true)) {
            throw new InvalidArgumentException('O ajuste do objetivo deve ser em kcal ou em percentual do GET.');
        }
        $adjustValue = self::signed($in['adjust_value'] ?? 0, 'ajuste do objetivo');
        $adjustKcal = $mode === 'percent' ? $get * $adjustValue / 100.0 : $adjustValue;
        if (abs($adjustKcal) > 3000.0) {
            throw new InvalidArgumentException('O ajuste do objetivo passa de 3.000 kcal. Confira o valor.');
        }
        $vet = $get + $adjustKcal;
        if ($vet <= 0 || $vet > 10000) {
            throw new InvalidArgumentException('O VET resultante é inválido. Confira o ajuste do objetivo.');
        }
        if ($vet < $tmb) {
            $warnings[] = 'O VET ficou abaixo da TMB calculada. Confirme se isso é intencional.';
        }
        $goal = (string)($in['goal_type'] ?? 'maintenance');
        if ($goal === 'loss' && $adjustKcal > 0) {
            $warnings[] = 'O objetivo é redução de peso, mas o ajuste aumenta o VET.';
        }
        if ($goal === 'gain' && $adjustKcal < 0) {
            $warnings[] = 'O objetivo é ganho de peso ou de massa, mas o ajuste reduz o VET.';
        }

        return [
            'formula' => $formula,
            'tmb_kcal' => round($tmb, 1),
            'get_kcal' => round($get, 1),
            'vet_kcal' => round($vet, 1),
            'adjust_kcal' => round($adjustKcal, 1),
            'equation' => $equation,
            'warnings' => $warnings,
        ];
    }

    /**
     * Metas de macronutrientes conferidas contra o VET.
     *
     * percent:  proteína + carboidrato + lipídio devem somar 100% (tolerância de 0,5 ponto).
     * g_per_kg: as três metas em g/kg de peso; a soma em kcal é comparada ao VET e a diferença é informada.
     *
     * @param array{protein?:mixed,carbohydrate?:mixed,lipid?:mixed} $values
     * @return array{mode:string,protein_g:float,carbohydrate_g:float,lipid_g:float,protein_pct:float,
     *               carbohydrate_pct:float,lipid_pct:float,protein_g_kg:?float,carbohydrate_g_kg:?float,
     *               lipid_g_kg:?float,macro_kcal:float,vet_diff_pct:float,warnings:list<string>}
     */
    public static function macros(float $vetKcal, string $mode, array $values, float $weightKg): array
    {
        if (!isset(self::MACRO_MODES[$mode])) {
            throw new InvalidArgumentException('Escolha como informar os macronutrientes (percentual ou g/kg).');
        }
        if ($vetKcal <= 0 || $weightKg <= 0) {
            throw new InvalidArgumentException('Calcule o VET e informe o peso antes de definir os macronutrientes.');
        }
        $k = self::KCAL_PER_G;
        $warnings = [];
        $per = ['protein' => null, 'carbohydrate' => null, 'lipid' => null];

        if ($mode === 'percent') {
            $pct = [];
            foreach (['protein' => 'proteína', 'carbohydrate' => 'carboidrato', 'lipid' => 'lipídio'] as $key => $name) {
                $pct[$key] = self::number($values[$key] ?? null, "percentual de {$name}", 0.0, 100.0);
            }
            $sum = array_sum($pct);
            if (abs($sum - 100.0) > 0.5) {
                throw new InvalidArgumentException(sprintf('Os percentuais somam %s%%. Eles precisam somar 100%%.', self::fmt($sum)));
            }
            $grams = [];
            foreach ($pct as $key => $p) {
                $grams[$key] = $vetKcal * $p / 100.0 / $k[$key];
                $per[$key] = $grams[$key] / $weightKg;
            }
        } else {
            $grams = [];
            foreach (['protein' => 'proteína', 'carbohydrate' => 'carboidrato', 'lipid' => 'lipídio'] as $key => $name) {
                $perKg = self::number($values[$key] ?? null, "g/kg de {$name}", 0.0, 30.0);
                $per[$key] = $perKg;
                $grams[$key] = $perKg * $weightKg;
            }
        }

        $macroKcal = 0.0;
        foreach ($grams as $key => $g) {
            $macroKcal += $g * $k[$key];
        }
        if ($macroKcal <= 0) {
            throw new InvalidArgumentException('Defina ao menos um macronutriente maior que zero.');
        }
        $diffPct = ($macroKcal - $vetKcal) / $vetKcal * 100.0;
        if ($mode === 'g_per_kg' && abs($diffPct) > self::TOLERANCE_PCT) {
            $warnings[] = sprintf(
                'A soma dos macros (%s kcal) difere %s%% do VET (%s kcal). Ajuste os g/kg ou o VET.',
                self::fmt($macroKcal, 0), self::fmt(abs($diffPct)), self::fmt($vetKcal, 0)
            );
        }

        $result = ['mode' => $mode];
        foreach (['protein', 'carbohydrate', 'lipid'] as $key) {
            $result["{$key}_g"] = round($grams[$key], 1);
            $result["{$key}_pct"] = round($grams[$key] * $k[$key] / $macroKcal * 100.0, 1);
            $result["{$key}_g_kg"] = round((float)$per[$key], 2);
        }
        $result['macro_kcal'] = round($macroKcal, 1);
        $result['vet_diff_pct'] = round($diffPct, 1);
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Compara os totais do plano com a meta. Status: ok (dentro da tolerância), abaixo ou acima.
     *
     * @param array{vet_kcal:mixed,protein_g:mixed,carbohydrate_g:mixed,lipid_g:mixed} $target
     * @param array{energy_kcal:mixed,protein_g:mixed,carbohydrate_g:mixed,lipid_g:mixed} $planTotals
     * @return array<string,array{target:float,actual:float,diff:float,diff_pct:?float,status:string}>
     */
    public static function comparePlan(array $target, array $planTotals, float $tolerancePct = self::TOLERANCE_PCT): array
    {
        $pairs = [
            'energy' => [(float)$target['vet_kcal'], (float)($planTotals['energy_kcal'] ?? 0)],
            'protein' => [(float)$target['protein_g'], (float)($planTotals['protein_g'] ?? 0)],
            'carbohydrate' => [(float)$target['carbohydrate_g'], (float)($planTotals['carbohydrate_g'] ?? 0)],
            'lipid' => [(float)$target['lipid_g'], (float)($planTotals['lipid_g'] ?? 0)],
        ];
        $out = [];
        foreach ($pairs as $key => [$t, $a]) {
            $diff = $a - $t;
            $pct = $t > 0 ? $diff / $t * 100.0 : null;
            $status = 'ok';
            if ($pct !== null && $pct > $tolerancePct) {
                $status = 'acima';
            } elseif ($pct !== null && $pct < -$tolerancePct) {
                $status = 'abaixo';
            }
            $out[$key] = [
                'target' => round($t, 1),
                'actual' => round($a, 1),
                'diff' => round($diff, 1),
                'diff_pct' => $pct === null ? null : round($pct, 1),
                'status' => $status,
            ];
        }
        return $out;
    }

    /** Aceita "70,5" e "70.5"; recusa texto, vazio e fora do intervalo. */
    private static function number(mixed $raw, string $label, float $min, float $max): float
    {
        $value = self::parse($raw, $label);
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('Confira o campo %s: o valor deve ficar entre %s e %s.', $label, self::fmt($min), self::fmt($max)));
        }
        return $value;
    }

    private static function signed(mixed $raw, string $label): float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }
        return self::parse($raw, $label);
    }

    private static function parse(mixed $raw, string $label): float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float)$raw;
        }
        $text = trim((string)$raw);
        if ($text === '') {
            throw new InvalidArgumentException("Informe o campo {$label}.");
        }
        $text = str_replace(',', '.', $text);
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException("O campo {$label} precisa ser um número.");
        }
        return (float)$text;
    }

    private static function fmt(float $v, int $decimals = 1): string
    {
        $s = number_format($v, $decimals, ',', '.');
        return $decimals > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }
}
