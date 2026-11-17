<?php

declare(strict_types=1);
namespace ClyoNutri\Domain\MealPlan;

final class PlanTitle
{
    public static function forPatient(string $title): string
    {
        $clean = trim((string)preg_replace('/^(?:\s*\[cópia\]\s*)+/iu', '', $title));
        return $clean !== '' ? $clean : 'Plano alimentar';
    }

    public static function needsConfirmation(string $title): bool
    {
        return preg_match('/^\s*\[cópia\]/iu', $title) === 1;
    }
}
