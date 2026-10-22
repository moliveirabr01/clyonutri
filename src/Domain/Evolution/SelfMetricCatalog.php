<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Evolution;

/**
 * Métricas que a paciente pode autorrelatar (NI2-135). Lista fechada: cada métrica tem unidade única e faixa
 * plausível para adultos. O profissional habilita, por paciente, quais delas aparecem no app.
 * Faixas largas de propósito: barram erro de digitação/unidade (ex.: 4.000 kg, peso em libras), não julgam o valor.
 */
final class SelfMetricCatalog
{
    public const VERSION = '2026.10-r1';

    public const METRICS = [
        'weight_kg' => ['label' => 'Peso', 'unit' => 'kg', 'min' => 20.0, 'max' => 400.0, 'decimals' => 1, 'clinical_column' => 'weight_kg'],
        'waist_cm' => ['label' => 'Circunferência da cintura', 'unit' => 'cm', 'min' => 30.0, 'max' => 250.0, 'decimals' => 1, 'clinical_column' => 'waist_cm'],
        'hip_cm' => ['label' => 'Circunferência do quadril', 'unit' => 'cm', 'min' => 40.0, 'max' => 250.0, 'decimals' => 1, 'clinical_column' => 'hip_cm'],
    ];

    public static function has(string $type): bool
    {
        return isset(self::METRICS[$type]);
    }

    public static function get(string $type): array
    {
        if (!self::has($type)) {
            throw new \InvalidArgumentException('Métrica não reconhecida.');
        }
        return ['type' => $type] + self::METRICS[$type];
    }

    /** Rótulo para exibição; registros antigos com tipos fora do catálogo continuam visíveis, identificados. */
    public static function label(string $type): string
    {
        return self::METRICS[$type]['label'] ?? 'Registro anterior (' . $type . ')';
    }

    /** Descrição pública (para API/app). */
    public static function describe(string $type): array
    {
        $m = self::get($type);
        return ['type' => $type, 'label' => $m['label'], 'unit' => $m['unit'], 'min' => $m['min'], 'max' => $m['max'], 'decimals' => $m['decimals']];
    }
}
