<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Engagement;

/**
 * Recusa de uma operação de hidratação com código estável para a API (NI2-92).
 * Estende InvalidArgumentException para manter os pontos que já tratavam 422.
 */
final class HydrationException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'VALIDATION_ERROR',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
