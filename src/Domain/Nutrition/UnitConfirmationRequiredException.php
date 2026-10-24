<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use InvalidArgumentException;

/**
 * NI2-74: a unidade existe, mas não tem peso cadastrado para o alimento. O peso seria o padrão genérico
 * (ex.: 1 xícara = 150 g para qualquer alimento), então só pode ser usado se a nutricionista confirmar.
 * `conversion` traz o que seria usado (grams, grams_per_unit, source, unit_label) para a tela explicar.
 */
final class UnitConfirmationRequiredException extends InvalidArgumentException
{
    /** @param array{grams: float, grams_per_unit: float, source: string, unit_label: string} $conversion */
    public function __construct(string $message, public readonly array $conversion)
    {
        parent::__construct($message);
    }
}
