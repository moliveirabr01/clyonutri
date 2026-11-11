<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

/**
 * NI2-615 (UX-R3-14): feriados nacionais do Brasil para importar no calendário da clínica, com revisão antes de
 * gravar. Só feriados (dias sem expediente por lei); pontos facultativos (Carnaval, Corpus Christi etc.) não entram.
 *
 * Fonte legal:
 *  - Lei nº 662/1949 (com a redação da Lei nº 10.607/2002): 1º/1, 21/4, 1º/5, 7/9, 2/11, 15/11 e 25/12;
 *  - Lei nº 6.802/1980: 12/10 (Nossa Senhora Aparecida);
 *  - Lei nº 14.759/2023: 20/11 (Dia Nacional de Zumbi e da Consciência Negra);
 *  - Sexta-feira da Paixão: data móvel (2 dias antes da Páscoa), listada como feriado pela portaria anual do
 *    Ministério da Gestão (para 2026, Portaria MGI nº 11.460, de 29/12/2025: 3/4/2026).
 * Conferido contra a Portaria MGI nº 11.460/2025 para 2026. Para anos seguintes, conferir a portaria do ano.
 */
final class NationalHolidays
{
    public const SOURCE = 'Leis nº 662/1949, 6.802/1980 e 14.759/2023; Sexta-feira da Paixão pela Páscoa (Portaria MGI nº 11.460/2025 para 2026)';

    private const FIXED = [
        '01-01' => ['Confraternização Universal', 'Lei nº 662/1949'],
        '04-21' => ['Tiradentes', 'Lei nº 662/1949'],
        '05-01' => ['Dia do Trabalho', 'Lei nº 662/1949'],
        '09-07' => ['Independência do Brasil', 'Lei nº 662/1949'],
        '10-12' => ['Nossa Senhora Aparecida', 'Lei nº 6.802/1980'],
        '11-02' => ['Finados', 'Lei nº 662/1949'],
        '11-15' => ['Proclamação da República', 'Lei nº 662/1949'],
        '11-20' => ['Dia Nacional de Zumbi e da Consciência Negra', 'Lei nº 14.759/2023'],
        '12-25' => ['Natal', 'Lei nº 662/1949'],
    ];

    /** @return list<array{date:string, name:string, basis:string}> em ordem de data */
    public static function forYear(int $year): array
    {
        if ($year < 2024 || $year > 2100) {
            return [];
        }
        $out = [];
        foreach (self::FIXED as $md => [$name, $basis]) {
            $out[] = ['date' => sprintf('%04d-%s', $year, $md), 'name' => $name, 'basis' => $basis];
        }
        $goodFriday = self::easter($year)->modify('-2 days')->format('Y-m-d');
        $out[] = ['date' => $goodFriday, 'name' => 'Sexta-feira da Paixão', 'basis' => 'Data móvel (Páscoa − 2 dias); portaria anual do Ministério da Gestão'];
        usort($out, static fn($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /**
     * v54.40: pontos facultativos federais de data móvel que muitas clínicas fecham. NÃO são feriados nacionais
     * (dependem da portaria anual do MGI, de lei estadual/municipal ou do consultório); na revisão aparecem
     * desmarcados e são gravados como "Ponto facultativo: ...". Carnaval = Páscoa − 48/−47; Corpus Christi = Páscoa + 60.
     * @return list<array{date:string, name:string, basis:string, optional:bool}>
     */
    public static function optionalForYear(int $year): array
    {
        if ($year < 2024 || $year > 2100) return [];
        $e = self::easter($year); $basis = 'Ponto facultativo federal (portaria anual do MGI); não é feriado nacional';
        return [
            ['date' => $e->modify('-48 days')->format('Y-m-d'), 'name' => 'Carnaval (segunda-feira)', 'basis' => $basis, 'optional' => true],
            ['date' => $e->modify('-47 days')->format('Y-m-d'), 'name' => 'Carnaval (terça-feira)', 'basis' => $basis, 'optional' => true],
            ['date' => $e->modify('+60 days')->format('Y-m-d'), 'name' => 'Corpus Christi', 'basis' => $basis, 'optional' => true],
        ];
    }

    /** Domingo de Páscoa (calendário gregoriano, algoritmo de Meeus/Jones/Butcher). */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** Anos oferecidos na importação: o atual e o seguinte. */
    public static function importYears(?int $now = null): array
    {
        $y = $now ?? (int)date('Y');
        return [$y, $y + 1];
    }
}
