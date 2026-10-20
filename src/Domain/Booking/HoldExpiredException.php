<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

use RuntimeException;

class HoldExpiredException extends RuntimeException
{
    public function __construct(string $message = "A reserva temporária expirou. Por favor, selecione o horário novamente.")
    {
        parent::__construct($message, 410);
    }
}
