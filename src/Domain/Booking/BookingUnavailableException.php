<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

use RuntimeException;

/**
 * O profissional não aceita autoagendamento (inexistente, inativo, de outra clínica ou com o
 * autoagendamento desligado). A API responde 404 sem dizer qual dos casos é, para não permitir
 * descobrir profissionais de outras clínicas.
 */
class BookingUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Agendamento online indisponível para este profissional.')
    {
        parent::__construct($message, 404);
    }
}
