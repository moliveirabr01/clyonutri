<?php
declare(strict_types=1);

namespace ClyoNutri\Core\Time;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * NI2-413 (UX-01): cálculo único das datas de navegação da agenda.
 * Semana: âncora normalizada para a segunda-feira e passo de 7 dias.
 * Dia: passo de 1 dia. Datas sempre em calendário (sem horário/fuso), já validadas pela ClinicCalendar.
 */
final class AgendaNavigation
{
    private const MONTHS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /** @return array{view:string,start:string,end:string,prev:string,next:string,prev_label:string,next_label:string,today_label:string,period_label:string,is_current:bool} */
    public static function forView(string $view, string $date, string $today): array
    {
        $d = self::parse($date);
        $t = self::parse($today);
        if ($view === 'week') {
            $start = $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
            $end = $start->modify('+6 days');
            $tStart = $t->modify('-' . ((int)$t->format('N') - 1) . ' days');
            $prev = $start->modify('-7 days');
            $next = $start->modify('+7 days');
            $period = 'Semana de ' . self::human($start) . ' a ' . self::human($end);
            return [
                'view' => 'week', 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                'prev' => $prev->format('Y-m-d'), 'next' => $next->format('Y-m-d'),
                'prev_label' => 'Semana anterior (' . self::human($prev) . ' a ' . self::human($prev->modify('+6 days')) . ')',
                'next_label' => 'Próxima semana (' . self::human($next) . ' a ' . self::human($next->modify('+6 days')) . ')',
                'today_label' => 'Hoje: voltar para a semana atual',
                'period_label' => $period,
                'is_current' => $start == $tStart,
            ];
        }
        $prev = $d->modify('-1 day');
        $next = $d->modify('+1 day');
        return [
            'view' => 'day', 'start' => $d->format('Y-m-d'), 'end' => $d->format('Y-m-d'),
            'prev' => $prev->format('Y-m-d'), 'next' => $next->format('Y-m-d'),
            'prev_label' => 'Dia anterior (' . self::human($prev) . ')',
            'next_label' => 'Próximo dia (' . self::human($next) . ')',
            'today_label' => 'Hoje: voltar para o dia atual',
            'period_label' => 'Dia ' . self::human($d),
            'is_current' => $d == $t,
        ];
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Data inválida.');
        }
        return $d;
    }

    private static function human(DateTimeImmutable $d): string
    {
        return (int)$d->format('j') . ' de ' . self::MONTHS[(int)$d->format('n')] . ' de ' . $d->format('Y');
    }
}
