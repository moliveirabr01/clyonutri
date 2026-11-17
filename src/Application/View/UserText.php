<?php

declare(strict_types=1);
namespace ClyoNutri\Application\View;

final class UserText
{
    /** Forms include the whole phrase; {n} is replaced by the count. */
    public static function count(int $n, string $one, string $many, ?string $zero = null): string
    {
        return str_replace('{n}', (string)$n, $n === 0 && $zero !== null ? $zero : ($n === 1 ? $one : $many));
    }
}
