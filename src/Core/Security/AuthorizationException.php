<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use RuntimeException;

/**
 * Exceção lançada quando ocorre violação de autorização de participante ou papel.
 * Mapeada para HTTP 403 Forbidden.
 */
class AuthorizationException extends RuntimeException
{
    private int $httpStatusCode;

    public function __construct(string $message = "Acesso não autorizado ao recurso solicitado.", int $httpStatusCode = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatusCode, $previous);
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
