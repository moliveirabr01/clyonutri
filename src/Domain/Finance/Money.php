<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Finance;

/**
 * v54.36: leitura única de valores em reais digitados ou vindos de <input type="number">.
 * Aceita "400", "400.00" (campo numérico / padrão US), "400,00", "1.400,00", "R$ 1.250,50", "1,400.00".
 * Regra: o ÚLTIMO separador ("," ou ".") seguido de 1 ou 2 dígitos é o decimal; os demais são milhar.
 */
final class Money
{
    public static function parseToCents(string $raw): int
    {
        $s = trim(str_ireplace('R$', '', $raw));
        $s = preg_replace('/[\s\x{00A0}]+/u', '', $s) ?? '';
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '+-');
        if ($s === '' || !preg_match('/^[0-9.,]+$/', $s)) return 0;
        $int = $s; $dec = '';
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $s, $m)) { $int = $m[1]; $dec = $m[2]; }
        $int = str_replace(['.', ','], '', $int);
        if ($int === '') $int = '0';
        $cents = (int)$int * 100 + (int)str_pad($dec, 2, '0');
        return $neg ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }
}
