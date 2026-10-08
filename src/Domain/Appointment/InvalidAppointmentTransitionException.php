<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

use RuntimeException;

class InvalidAppointmentTransitionException extends RuntimeException
{
    private string $fromStatus;
    private string $toStatus;

    public function __construct(string $fromStatus, string $toStatus, string $message = '', int $code = 422)
    {
        $msg = $message ?: "Transição de status inválida para a consulta: não é permitido transicionar de '{$fromStatus}' para '{$toStatus}'.";
        parent::__construct($msg, $code);
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }
}
