<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use InvalidArgumentException;
use PDO;

class HouseholdMeasureConverter
{
    private ?PDO $db;

    /**
     * Medidas caseiras de referência padrão do sistema (em gramas)
     */
    private const STANDARD_MEASURES = [
        'g' => 1.0,
        'grama' => 1.0,
        'gramas' => 1.0,
        'mg' => 0.001,
        'kg' => 1000.0,
        'ml' => 1.0,
        'mililitro' => 1.0,
        'mililitros' => 1.0,
        'l' => 1000.0,
        'litro' => 1000.0,
        'litros' => 1000.0,
        'colher de sopa' => 15.0,
        'colher sopa' => 15.0,
        'colher de sobremesa' => 10.0,
        'colher de chá' => 5.0,
        'colher cha' => 5.0,
        'colher de café' => 2.0,
        'colher cafe' => 2.0,
        'xícara de chá' => 150.0,
        'xicara de cha' => 150.0,
        'xícara' => 150.0,
        'xicara' => 150.0,
        'copo americano' => 200.0,
        'copo' => 200.0,
        'concha' => 130.0,
        'concha média' => 130.0,
        'fatia' => 30.0,
        'fatia média' => 30.0,
        'unidade' => 100.0,
        'unidade média' => 100.0,
        'porção' => 100.0,
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * Converte uma quantidade e unidade de medida em gramas.
     *
     * @param float $amount Quantidade (ex: 2.0)
     * @param string $unit Unidade (ex: 'colher de sopa', 'g')
     * @param int|null $foodId ID do alimento para buscar medida caseira específica
     * @return float Quantidade convertida em gramas
     * @throws InvalidArgumentException Se quantidade <= 0 ou unidade desconhecida
     */
    /**
     * Unidades exatas (massa e volume). As demais medidas da tabela padrão são genéricas:
     * o peso real depende do alimento, então o resultado é marcado como estimado.
     */
    private const EXACT_UNITS = ['g', 'grama', 'gramas', 'mg', 'kg', 'ml', 'mililitro', 'mililitros', 'l', 'litro', 'litros'];

    /**
     * Converte uma quantidade e unidade de medida em gramas.
     *
     * @param float $amount Quantidade (ex: 2.0)
     * @param string $unit Unidade (ex: 'colher de sopa', 'g')
     * @param int|null $foodId ID do alimento para buscar medida caseira específica
     * @return float Quantidade convertida em gramas
     * @throws InvalidArgumentException Se quantidade <= 0 ou unidade desconhecida
     */
    public function convertToGrams(float $amount, string $unit, ?int $foodId = null): float
    {
        return $this->convertDetailed($amount, $unit, $foodId)['grams'];
    }

    /**
     * Igual a convertToGrams, mas informa a origem do peso.
     *
     * @return array{grams: float, estimated: bool, source: string}
     *   source: 'food_measure' (medida cadastrada para o alimento), 'exact' (g/ml/kg...) ou
     *   'generic' (medida caseira padrão, igual para qualquer alimento: estimated = true).
     */
    public function convertDetailed(float $amount, string $unit, ?int $foodId = null): array
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException("A quantidade prescrita deve ser maior que zero (recebido: {$amount}).");
        }

        $normalizedUnit = mb_strtolower(trim($unit), 'UTF-8');

        // 1. Medida caseira específica do alimento cadastrada no banco
        if ($foodId !== null && $this->db !== null) {
            $stmt = $this->db->prepare("
                SELECT grams_equivalent 
                FROM food_household_measures 
                WHERE food_id = :food_id AND LOWER(measure_name) = :measure
                LIMIT 1
            ");
            $stmt->execute([
                'food_id' => $foodId,
                'measure' => $normalizedUnit,
            ]);
            $gramsPerMeasure = $stmt->fetchColumn();

            if ($gramsPerMeasure !== false) {
                return ['grams' => round($amount * (float)$gramsPerMeasure, 4), 'estimated' => false, 'source' => 'food_measure'];
            }
        }

        // 2. Tabela de medidas padrão
        if (array_key_exists($normalizedUnit, self::STANDARD_MEASURES)) {
            $exact = in_array($normalizedUnit, self::EXACT_UNITS, true);
            return [
                'grams' => round($amount * self::STANDARD_MEASURES[$normalizedUnit], 4),
                'estimated' => !$exact,
                'source' => $exact ? 'exact' : 'generic',
            ];
        }

        throw new InvalidArgumentException("Unidade de medida não reconhecida ou inválida: '{$unit}'. Use unidades do padrão TACO/IBGE ou medidas caseiras cadastradas.");
    }
}
