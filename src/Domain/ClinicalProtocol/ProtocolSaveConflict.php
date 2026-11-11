<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

/** NI2-652: gravação concorrente das respostas dos módulos (a tela estava desatualizada). */
final class ProtocolSaveConflict extends \DomainException
{
}
