<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use RuntimeException;

/**
 * Exceção lançada quando ocorre qualquer tentativa de violação de isolamento multi-tenant
 * ou acesso não autorizado a recurso de outra organização (IDOR).
 * Mapeada para HTTP 403 (Forbidden) ou 404 (Not Found).
 */
class TenantSecurityException extends RuntimeException
{
    private int $httpStatusCode;

    public function __construct(string $message = "Acesso negado: violação de isolamento multi-tenant.", int $httpStatusCode = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatusCode, $previous);
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
