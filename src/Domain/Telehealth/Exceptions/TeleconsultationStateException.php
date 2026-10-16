<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Exceptions;

use RuntimeException;

/** Ação incompatível com o estado atual da sessão de teleconsulta (HTTP 409). */
class TeleconsultationStateException extends RuntimeException
{
}
