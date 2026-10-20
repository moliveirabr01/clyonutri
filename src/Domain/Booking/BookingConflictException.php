<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

use RuntimeException;

class BookingConflictException extends RuntimeException
{
    private ?array $conflictDetails;

    public function __construct(string $message = "Horário indisponível para agendamento.", ?array $conflictDetails = null)
    {
        parent::__construct($message, 409);
        $this->conflictDetails = $conflictDetails;
    }

    public function getConflictDetails(): ?array
    {
        return $this->conflictDetails;
    }
}
