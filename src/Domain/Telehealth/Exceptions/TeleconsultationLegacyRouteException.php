<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Exceptions;

use RuntimeException;

/** Rota antiga do paciente com token na URL (POST/status): removida pelo F05 (convite em cookie). */
class TeleconsultationLegacyRouteException extends RuntimeException
{
}
