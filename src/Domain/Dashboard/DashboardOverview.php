<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * v54.56: dados do topo do Painel geral (saudação, comparação com o período anterior e agenda da semana).
 *
 * Regras:
 *  - tudo vem do banco do consultório atual (clinic_id sempre no WHERE); nada é estimado;
 *  - comparação só quando existe base anterior real; sem base, o selo é neutro ("sem base anterior");
 *  - horários seguem o contrato de armazenamento local (wall-clock) do ClinicCalendar;
 *  - consultas canceladas não entram na agenda da semana (mesma regra do card "Consultas na semana").
 */
final class DashboardOverview
{
    public const WEEK_DAY_VISIBLE_LIMIT = 4;

    private const WEEKDAYS = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];
    private const WEEKDAYS_SHORT = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
    private const MONTHS = [1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'];
    private const MONTHS_SHORT = [1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'];

    private DateTimeZone $timezone;

    public function __construct(private PDO $db, private ?DateTimeImmutable $now = null)
    {
        $this->timezone = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
    }

    private function now(): DateTimeImmutable
    {
        return ($this->now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
    }

    /** "Bom dia" até 11:59, "Boa tarde" até 17:59, "Boa noite" depois (fuso do consultório). */
    public function greeting(): string
    {
        $h = (int)$this->now()->format('G');
        return $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
    }

    /** "Terça-feira, 10 de novembro de 2026". */
    public function dateLabel(?string $date = null): string
    {
        $d = $date !== null ? new DateTimeImmutable($date, $this->timezone) : $this->now();
        return self::WEEKDAYS[(int)$d->format('N')] . ', ' . (int)$d->format('j') . ' de ' . self::MONTHS[(int)$d->format('n')] . ' de ' . $d->format('Y');
    }

    /** Primeiro nome em caixa de título; vazio quando não há nome (a saudação fica sem vocativo). */
    public static function firstName(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '') return '';
        $first = (string)(preg_split('/\s+/u', $name)[0] ?? '');
        return mb_convert_case(mb_strtolower($first, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Selo de comparação. $previous === null significa "não há base anterior medida".
     * Diferença absoluta (contagens pequenas ficam mais claras assim do que em %).
     * @return array{tone:string,label:string,sr:string}
     */
    public static function compareCount(int $current, ?int $previous, string $periodLabel): array
    {
        if ($previous === null || ($previous === 0 && $current > 0)) {
            return ['tone' => 'neutral', 'label' => 'sem base anterior', 'sr' => "Sem base de comparação $periodLabel."];
        }
        if ($current === $previous) {
            return ['tone' => 'neutral', 'label' => 'sem variação', 'sr' => "Igual $periodLabel."];
        }
        $diff = $current - $previous;
        $abs = abs($diff);
        return [
            'tone' => $diff > 0 ? 'up' : 'down',
            'label' => ($diff > 0 ? '↑ ' : '↓ ') . $abs . ' ' . $periodLabel,
            'sr' => $abs . ($diff > 0 ? ' a mais ' : ' a menos ') . $periodLabel . '.',
        ];
    }

    /**
     * Adesão em pontos percentuais. Sem dado em um dos períodos, o selo é neutro.
     * @return array{tone:string,label:string,sr:string}
     */
    public static function comparePoints(?float $current, ?float $previous, string $periodLabel): array
    {
        if ($current === null || $previous === null) {
            return ['tone' => 'neutral', 'label' => 'sem base anterior', 'sr' => "Sem base de comparação $periodLabel."];
        }
        $diff = round($current - $previous, 1);
        if (abs($diff) < 0.05) {
            return ['tone' => 'neutral', 'label' => 'sem variação', 'sr' => "Igual $periodLabel."];
        }
        $txt = number_format(abs($diff), 1, ',', '.');
        return [
            'tone' => $diff > 0 ? 'up' : 'down',
            'label' => ($diff > 0 ? '↑ ' : '↓ ') . $txt . ' p.p. ' . $periodLabel,
            'sr' => $txt . ' pontos percentuais ' . ($diff > 0 ? 'acima ' : 'abaixo ') . $periodLabel . '.',
        ];
    }

    /** Pacientes cadastrados nos últimos $days dias e nos $days anteriores (mesma clínica). */
    public function newPatients(int $clinicId, int $days = 30): array
    {
        $now = $this->now();
        $start = $now->modify("-{$days} days")->format('Y-m-d H:i:s');
        $prevStart = $now->modify('-' . (2 * $days) . ' days')->format('Y-m-d H:i:s');
        $st = $this->db->prepare('SELECT
                SUM(CASE WHEN created_at >= :s1 THEN 1 ELSE 0 END) AS cur,
                SUM(CASE WHEN created_at >= :p1 AND created_at < :s2 THEN 1 ELSE 0 END) AS prev
            FROM patients WHERE clinic_id = :cid AND created_at >= :p2');
        $st->execute(['s1' => $start, 's2' => $start, 'p1' => $prevStart, 'p2' => $prevStart, 'cid' => $clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['current' => (int)($r['cur'] ?? 0), 'previous' => (int)($r['prev'] ?? 0)];
    }

    /**
     * Média de adesão (check-ins com meal_adherence_pct) na janela [hoje-2N, hoje-N), para comparar com os últimos N dias.
     * Mesmo critério de EngagementService::getClinicAdherenceMetrics (checkin_date em dias corridos).
     */
    public function previousAdherence(int $clinicId, int $days = 30): ?float
    {
        $end = date('Y-m-d', strtotime("-{$days} days"));
        $start = date('Y-m-d', strtotime('-' . (2 * $days) . ' days'));
        $st = $this->db->prepare('SELECT COUNT(meal_adherence_pct) AS n, AVG(meal_adherence_pct) AS avg_pct FROM checkins
            WHERE clinic_id = :cid AND checkin_date >= :start AND checkin_date < :end AND meal_adherence_pct IS NOT NULL');
        $st->execute(['cid' => $clinicId, 'start' => $start, 'end' => $end]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ((int)($r['n'] ?? 0) > 0 && $r['avg_pct'] !== null) ? round((float)$r['avg_pct'], 1) : null;
    }

    /**
     * Agenda da semana corrente (segunda a domingo) do consultório, sem canceladas.
     * Sábado e domingo só aparecem se tiverem consulta ou se forem hoje.
     * @return array{start:string,end:string,label:string,total:int,days:list<array<string,mixed>>}
     */
    public function weekAgenda(int $clinicId, string $today): array
    {
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $today, $this->timezone) ?: $this->now()->setTime(0, 0);
        $monday = $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days');
        $next = $monday->modify('+7 days');
        $st = $this->db->prepare("SELECT a.id, a.patient_id, a.scheduled_at, a.type, a.status, p.name AS patient_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
            WHERE a.clinic_id = :cid AND a.scheduled_at >= :start AND a.scheduled_at < :end AND a.status <> 'cancelled'
            ORDER BY a.scheduled_at ASC, a.id ASC");
        $st->execute(['cid' => $clinicId, 'start' => $monday->format('Y-m-d H:i:s'), 'end' => $next->format('Y-m-d H:i:s')]);
        $byDay = [];
        $total = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byDay[substr((string)$row['scheduled_at'], 0, 10)][] = $row;
            $total++;
        }
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+{$i} days");
            $key = $d->format('Y-m-d');
            $items = $byDay[$key] ?? [];
            $isToday = $key === $today;
            if ($i >= 5 && !$items && !$isToday) continue;
            $days[] = [
                'date' => $key,
                'weekday' => self::WEEKDAYS_SHORT[(int)$d->format('N')],
                'weekday_long' => self::WEEKDAYS[(int)$d->format('N')],
                'day_label' => $d->format('d') . ' ' . self::MONTHS_SHORT[(int)$d->format('n')],
                'is_today' => $isToday,
                'is_past' => $key < $today,
                'count' => count($items),
                'items' => array_slice($items, 0, self::WEEK_DAY_VISIBLE_LIMIT),
                'hidden' => max(0, count($items) - self::WEEK_DAY_VISIBLE_LIMIT),
            ];
        }
        $sunday = $next->modify('-1 day');
        return [
            'start' => $monday->format('Y-m-d'),
            'end' => $sunday->format('Y-m-d'),
            'label' => $monday->format('d') . ($monday->format('n') !== $sunday->format('n') ? ' ' . self::MONTHS_SHORT[(int)$monday->format('n')] : '')
                . ' – ' . $sunday->format('d') . ' ' . self::MONTHS_SHORT[(int)$sunday->format('n')],
            'total' => $total,
            'days' => $days,
        ];
    }
}
