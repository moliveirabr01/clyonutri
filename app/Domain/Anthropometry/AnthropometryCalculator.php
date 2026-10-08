<?php

declare(strict_types=1);

namespace App\Domain\Anthropometry;

/**
 * Calculadora pura de Antropometria.
 * Lógica pura sem dependência de framework ou infraestrutura.
 */
class AnthropometryCalculator
{
    /**
     * Calcula o Índice de Massa Corporal (IMC = peso / altura^2).
     *
     * @param float|null $weightKg Peso em quilogramas
     * @param float|null $heightM Altura em metros
     * @return float|null Retorna null se dados forem ausentes ou inválidos (<= 0)
     */
    public static function calculateBmi(?float $weightKg, ?float $heightM): ?float
    {
        if ($weightKg === null || $heightM === null || $weightKg <= 0 || $heightM <= 0) {
            return null;
        }

        return round($weightKg / ($heightM ** 2), 2);
    }

    /**
     * Classifica a faixa de IMC segundo a OMS (Adultos).
     */
    public static function classifyBmi(?float $bmi): ?string
    {
        if ($bmi === null) {
            return null;
        }

        if ($bmi < 18.5) {
            return 'Abaixo do peso';
        }
        if ($bmi < 24.9) {
            return 'Eutrofia (Peso adequado)';
        }
        if ($bmi < 29.9) {
            return 'Sobrepeso';
        }
        if ($bmi < 34.9) {
            return 'Obesidade Grau I';
        }
        if ($bmi < 39.9) {
            return 'Obesidade Grau II';
        }

        return 'Obesidade Grau III';
    }

    /**
     * Calcula a Relação Cintura-Quadril (RCQ).
     */
    public static function calculateWhr(?float $waistCm, ?float $hipCm): ?float
    {
        if ($waistCm === null || $hipCm === null || $waistCm <= 0 || $hipCm <= 0) {
            return null;
        }

        return round($waistCm / $hipCm, 2);
    }
}
