<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Appointment timestamps follow the existing local wall-clock storage contract. */
final class ClinicCalendar
{
    private DateTimeZone $timezone;

    public function __construct(private ?DateTimeImmutable $now = null)
    {
        $this->timezone = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
    }

    public function today(): string
    {
        return ($this->now ?? new DateTimeImmutable('now', $this->timezone))
            ->setTimezone($this->timezone)->format('Y-m-d');
    }

    /** @return array{start: string, end: string} Half-open local interval [start, end). */
    public function day(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new InvalidArgumentException('Data inválida. Use uma data existente no formato AAAA-MM-DD.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        if (!$start || $start->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Data inválida. Use uma data existente no formato AAAA-MM-DD.');
        }
        return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $start->modify('+1 day')->format('Y-m-d H:i:s')];
    }
}
