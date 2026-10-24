<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

/** NI2-74: quantidade prescrita como foi digitada (até 2 casas, vírgula): 0.5 → "0,5"; 2 → "2"; 33.33 → "33,33". */
final class QuantityFormatter
{
    public static function amount(mixed $value): string
    {
        $n = round((float)$value, 2);
        return fmod($n, 1.0) === 0.0 ? number_format($n, 0, ',', '.') : rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
