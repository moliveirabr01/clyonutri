<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

use InvalidArgumentException;

/**
 * Exceção disparada quando dados antropométricos violam limites biológicos ou regras de consistência clínica.
 */
class InvalidAnthropometricDataException extends InvalidArgumentException
{
}
