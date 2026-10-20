<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

/**
 * Cálculo determinístico de densidade corporal e % de gordura por dobras cutâneas (NI2-439).
 * Mesmas entradas → mesmo resultado; nenhum valor é inventado ou extrapolado.
 */
final class SkinfoldCalculator
{
    /**
     * @param array<string, list<float|int|string|null>|float|int|string|null> $readings
     *        Por dobra: um valor (mm) ou até 3 leituras repetidas.
     * @return array{
     *   protocol: string, catalog_version: string, sex: string, age: int,
     *   sites: array<string, array{readings: list<float>, value_mm: float}>,
     *   sum_mm: float, body_density: float, body_fat_pct: float,
     *   fat_mass_kg: ?float, lean_mass_kg: ?float, warnings: list<array{code: string, message: string}>,
     *   provenance: array
     * }
     * @throws InvalidAnthropometricDataException
     */
    public static function calculate(string $protocolCode, ?string $sex, ?int $age, array $readings, ?float $weightKg = null): array
    {
        $p = SkinfoldProtocolCatalog::protocol($protocolCode);
        $reason = SkinfoldProtocolCatalog::ineligibilityReason($protocolCode, $sex, $age);
        if ($reason !== null) {
            throw new InvalidAnthropometricDataException($reason);
        }

        $warnings = [];
        $sites = [];
        foreach ($p['sites'] as $site) {
            $values = self::readingsFor($readings[$site] ?? null);
            $label = SkinfoldProtocolCatalog::SITES[$site]['label'];
            if ($values === []) {
                throw new InvalidAnthropometricDataException("Informe a dobra {$label} (mm): ela faz parte deste protocolo.");
            }
            if (count($values) > 3) {
                throw new InvalidAnthropometricDataException("Dobra {$label}: informe no máximo 3 leituras.");
            }
            foreach ($values as $v) {
                if ($v < SkinfoldProtocolCatalog::SITE_MIN_MM || $v > SkinfoldProtocolCatalog::SITE_MAX_MM) {
                    throw new InvalidAnthropometricDataException(sprintf(
                        'Dobra %s: %s mm está fora do que um plicômetro mede (%s a %s mm). Confira o valor.',
                        $label, self::fmt($v), self::fmt(SkinfoldProtocolCatalog::SITE_MIN_MM), self::fmt(SkinfoldProtocolCatalog::SITE_MAX_MM)
                    ));
                }
            }
            $value = self::representative($values);
            $sites[$site] = ['readings' => $values, 'value_mm' => $value];

            if (count($values) >= 2) {
                $spread = (max($values) - min($values)) / $value;
                if ($spread > SkinfoldProtocolCatalog::READING_TOLERANCE) {
                    $warnings[] = ['code' => 'reading_spread', 'site' => $site, 'message' => sprintf(
                        'Dobra %s: leituras diferem %s%% (tolerância de 5%%). Repita a medida.', $label, self::fmt($spread * 100, 1)
                    )];
                }
            }
            if ($value < SkinfoldProtocolCatalog::SITE_WARN_LOW_MM || $value > SkinfoldProtocolCatalog::SITE_WARN_HIGH_MM) {
                $warnings[] = ['code' => 'site_unusual', 'site' => $site, 'message' => sprintf(
                    'Dobra %s: %s mm é um valor incomum (faixa usual de %s a %s mm). Confira a medida.',
                    $label, self::fmt($value), self::fmt(SkinfoldProtocolCatalog::SITE_WARN_LOW_MM), self::fmt(SkinfoldProtocolCatalog::SITE_WARN_HIGH_MM)
                )];
            }
        }

        $sum = array_sum(array_column($sites, 'value_mm'));
        [$density, $coefUsed] = self::density($p, (string)$sex, (int)$age, $sum);
        $fatPct = self::siri($density);

        if ($fatPct <= 0.0 || $fatPct >= 70.0) {
            throw new InvalidAnthropometricDataException(sprintf(
                'O resultado (%s%% de gordura, densidade %s g/mL) não é fisiologicamente possível. Confira as dobras informadas.',
                self::fmt($fatPct, 1), self::fmt($density, 4)
            ));
        }
        $essential = $sex === 'male' ? 5.0 : 12.0;
        if ($fatPct < $essential) {
            $warnings[] = ['code' => 'fat_below_essential', 'site' => null, 'message' => sprintf(
                'Resultado de %s%% está abaixo da gordura essencial esperada (cerca de %s%%). Confira as medidas.', self::fmt($fatPct, 1), self::fmt($essential, 0)
            )];
        }
        if ($fatPct > 50.0) {
            $warnings[] = ['code' => 'fat_very_high', 'site' => null, 'message' => 'Resultado acima de 50%: equações de dobras perdem precisão em adiposidade muito alta. Considere outro método.'];
        }

        $fatMass = null;
        $leanMass = null;
        if ($weightKg !== null && $weightKg > 0) {
            $fatMass = round($weightKg * $fatPct / 100, 2);
            $leanMass = round($weightKg - $fatMass, 2);
        }

        $conv = SkinfoldProtocolCatalog::CONVERSIONS[$p['conversion']];
        return [
            'protocol' => $protocolCode,
            'catalog_version' => SkinfoldProtocolCatalog::CATALOG_VERSION,
            'sex' => (string)$sex,
            'age' => (int)$age,
            'sites' => $sites,
            'sum_mm' => round($sum, 2),
            'body_density' => round($density, 5),
            'body_fat_pct' => round($fatPct, 2),
            'fat_mass_kg' => $fatMass,
            'lean_mass_kg' => $leanMass,
            'warnings' => $warnings,
            'provenance' => [
                'protocol_name' => $p['name'],
                'catalog_version' => SkinfoldProtocolCatalog::CATALOG_VERSION,
                'equation' => $p['equation_text'],
                'coefficients_used' => $coefUsed,
                'reference' => $p['reference'],
                'coefficients_source' => $p['coefficients_source'],
                'population' => $p['population'],
                'see_density' => $p['see_density'],
                'conversion' => $conv['name'] . ': ' . $conv['equation_text'],
                'conversion_reference' => $conv['reference'],
                'representative_value' => 'Mediana de 3 leituras ou média de 2; com 1 leitura, o próprio valor.',
            ],
        ];
    }

    /** @return array{0: float, 1: array} */
    private static function density(array $p, string $sex, int $age, float $sum): array
    {
        if ($p['equation'] === 'quadratic') {
            $c = $p['coefficients'];
            return [$c['a'] - $c['b'] * $sum + $c['c'] * $sum * $sum - $c['d'] * $age, $c];
        }
        foreach ($p['coefficients'][$sex] as [$min, $max, $cc, $m]) {
            if ($age >= $min && $age <= $max) {
                return [$cc - $m * log10($sum), ['c' => $cc, 'm' => $m, 'age_band' => "{$min}-{$max}"]];
            }
        }
        throw new InvalidAnthropometricDataException('Faixa etária sem coeficientes neste protocolo.');
    }

    public static function siri(float $density): float
    {
        return (4.95 / $density - 4.50) * 100;
    }

    /** @return list<float> */
    private static function readingsFor(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : [$raw];
        $values = [];
        foreach ($list as $r) {
            if ($r === null || (is_string($r) && trim($r) === '')) {
                continue;
            }
            $s = is_string($r) ? str_replace(',', '.', trim($r)) : $r;
            if (!is_numeric($s) || !is_finite((float)$s)) {
                throw new InvalidAnthropometricDataException('As dobras devem ser números em milímetros (ex.: 12,5).');
            }
            $values[] = round((float)$s, 1);
        }
        return $values;
    }

    /** Mediana de 3 leituras, média de 2, ou o valor único. */
    private static function representative(array $values): float
    {
        sort($values);
        return match (count($values)) {
            1 => $values[0],
            2 => round(($values[0] + $values[1]) / 2, 2),
            default => $values[1],
        };
    }

    private static function fmt(float $v, int $dec = 1): string
    {
        return number_format($v, $dec, ',', '.');
    }
}
