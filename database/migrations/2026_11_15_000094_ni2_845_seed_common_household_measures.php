<?php
declare(strict_types=1);

/**
 * v54.62 · Migração 94 (NI2-845 / UX-001):
 * Popula medidas caseiras padrão para os alimentos mais frequentes do catálogo TACO
 * (pão francês, queijos, carnes/frango/peixes, arroz, feijão, frutas, ovos, laticínios,
 * tubérculos, verduras, aveia, azeite, oleaginosas), de forma aditiva e idempotente.
 */
final class Ni2SeedCommonHouseholdMeasures
{
    public function up(PDO $db): void
    {
        \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::ensureCommonMeasures($db);
    }
}
if (!class_exists('Ni2845SeedCommonHouseholdMeasures')) {
    class_alias(Ni2SeedCommonHouseholdMeasures::class, 'Ni2845SeedCommonHouseholdMeasures');
}
