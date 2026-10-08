<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

/**
 * Calculadora Canônica de Antropometria e Indicadores Clínicos (NI2-66).
 * Fórmulas estritas segundo padrões da Organização Mundial da Saúde (OMS).
 */
class AnthropometryCalculator
{
    /**
     * Calcula o Índice de Massa Corporal (IMC = peso / altura_m^2).
     * Ausência ou dados inválidos retornam estritamente null (sem valores inventados).
     */
    public static function calculateBmi(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || $weightKg <= 0 || $heightCm <= 0) {
            return null;
        }

        // Se altura foi passada em metros (ex: 1.75), converte para cm primeiro
        $heightM = $heightCm > 3.0 ? $heightCm / 100.0 : $heightCm;
        if ($heightM <= 0) {
            return null;
        }

        return round($weightKg / ($heightM ** 2), 2);
    }

    /**
     * Classifica o IMC conforme diretrizes da OMS para adultos.
     */
    public static function classifyBmi(?float $bmi): ?string
    {
        if ($bmi === null) {
            return null;
        }

        if ($bmi < 18.5) {
            return 'Abaixo do peso';
        }
        if ($bmi <= 24.99) {
            return 'Eutrofia (Peso adequado)';
        }
        if ($bmi <= 29.99) {
            return 'Sobrepeso';
        }
        if ($bmi <= 34.99) {
            return 'Obesidade Grau I';
        }
        if ($bmi <= 39.99) {
            return 'Obesidade Grau II';
        }

        return 'Obesidade Grau III';
    }

    /**
     * Calcula a Relação Cintura-Quadril (RCQ = cintura / quadril).
     */
    public static function calculateWhr(?float $waistCm, ?float $hipCm): ?float
    {
        if ($waistCm === null || $hipCm === null || $waistCm <= 0 || $hipCm <= 0) {
            return null;
        }

        return round($waistCm / $hipCm, 2);
    }

    /**
     * Classifica o risco cardiovascular baseado na RCQ e sexo biológico (OMS).
     */
    public static function classifyWhr(?float $whr, ?string $gender = null): ?array
    {
        if ($whr === null) {
            return null;
        }

        $normGender = strtolower(trim($gender ?? ''));
        $isFemale = in_array($normGender, ['feminino', 'f', 'mulher', 'female'], true);
        $isMale = in_array($normGender, ['masculino', 'm', 'homem', 'male'], true);

        if ($isFemale) {
            if ($whr < 0.80) {
                return ['risk' => 'baixo', 'label' => 'Baixo risco cardiovascular', 'whr' => $whr];
            }
            if ($whr <= 0.84) {
                return ['risk' => 'moderado', 'label' => 'Risco cardiovascular moderado', 'whr' => $whr];
            }
            return ['risk' => 'alto', 'label' => 'Alto risco cardiovascular', 'whr' => $whr];
        }

        if ($isMale) {
            if ($whr < 0.90) {
                return ['risk' => 'baixo', 'label' => 'Baixo risco cardiovascular', 'whr' => $whr];
            }
            if ($whr <= 0.99) {
                return ['risk' => 'moderado', 'label' => 'Risco cardiovascular moderado', 'whr' => $whr];
            }
            return ['risk' => 'alto', 'label' => 'Alto risco cardiovascular', 'whr' => $whr];
        }

        // Padrão geral caso gênero não seja informado
        if ($whr < 0.85) {
            return ['risk' => 'baixo', 'label' => 'Baixo risco (Geral)', 'whr' => $whr];
        }
        return ['risk' => 'aumentado', 'label' => 'Risco aumentado (Geral)', 'whr' => $whr];
    }

    /**
     * Calcula a Relação Cintura-Estatura (RCE = cintura / altura).
     * Indicador de obesidade abdominal e risco cardiometabólico.
     */
    public static function calculateWhtr(?float $waistCm, ?float $heightCm): ?float
    {
        if ($waistCm === null || $heightCm === null || $waistCm <= 0 || $heightCm <= 0) {
            return null;
        }

        $heightInCm = $heightCm <= 3.0 ? $heightCm * 100.0 : $heightCm;
        return round($waistCm / $heightInCm, 2);
    }

    /**
     * Classifica a Relação Cintura-Estatura (RCE).
     */
    public static function classifyWhtr(?float $whtr): ?string
    {
        if ($whtr === null) {
            return null;
        }

        return $whtr < 0.50 ? 'Adequado (Baixo risco)' : 'Risco cardiometabólico aumentado';
    }

    /**
     * Calcula a faixa saudável de peso (Eutrofia: IMC 18.5 a 24.9).
     */
    public static function calculateIdealWeightRange(?float $heightCm): ?array
    {
        if ($heightCm === null || $heightCm <= 0) {
            return null;
        }

        $heightM = $heightCm > 3.0 ? $heightCm / 100.0 : $heightCm;
        $heightSq = $heightM ** 2;

        return [
            'min_kg' => round(18.5 * $heightSq, 2),
            'max_kg' => round(24.9 * $heightSq, 2),
            'height_cm' => round($heightM * 100, 1),
        ];
    }

    /**
     * Calcula derivados de composição corporal informada (Massa Gorda e Massa Magra em kg).
     */
    public static function calculateBodyComposition(?float $weightKg, ?float $bodyFatPct, ?float $muscleMassPct = null): array
    {
        $fatMassKg = null;
        $fatFreeMassKg = null;
        $muscleMassKg = null;

        if ($weightKg !== null && $weightKg > 0 && $bodyFatPct !== null && $bodyFatPct >= 0) {
            $fatMassKg = round($weightKg * ($bodyFatPct / 100.0), 2);
            $fatFreeMassKg = round($weightKg - $fatMassKg, 2);
        }

        if ($weightKg !== null && $weightKg > 0 && $muscleMassPct !== null && $muscleMassPct >= 0) {
            $muscleMassKg = round($weightKg * ($muscleMassPct / 100.0), 2);
        }

        return [
            'fat_mass_kg' => $fatMassKg,
            'fat_free_mass_kg' => $fatFreeMassKg,
            'muscle_mass_kg' => $muscleMassKg,
        ];
    }
}
