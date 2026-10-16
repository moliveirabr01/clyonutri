<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Exceptions;

use RuntimeException;

/** Limite de tentativas excedido em endpoint da teleconsulta (HTTP 429). */
class TeleconsultationRateLimitException extends RuntimeException
{
}
