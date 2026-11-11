<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Billing;

/** Erro do gateway com status HTTP; a mensagem nunca carrega a chave de API. */
final class AsaasException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}
