<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

/** NI2-635: dois protocolos ativos com a mesma pergunta compartilhada em formatos diferentes. */
final class ProtocolConflict extends \InvalidArgumentException
{
}
