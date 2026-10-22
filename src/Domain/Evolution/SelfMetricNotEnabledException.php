<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Evolution;

/** Métrica fora das habilitadas pelo profissional para esta paciente (NI2-135). */
final class SelfMetricNotEnabledException extends \InvalidArgumentException
{
}
