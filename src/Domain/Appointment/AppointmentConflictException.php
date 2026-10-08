<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

use RuntimeException;

class AppointmentConflictException extends RuntimeException
{
    private array $conflictingAppointment;

    public function __construct(string $message, array $conflictingAppointment = [], int $code = 409)
    {
        parent::__construct($message, $code);
        $this->conflictingAppointment = $conflictingAppointment;
    }

    public function getConflictingAppointment(): array
    {
        return $this->conflictingAppointment;
    }
}
