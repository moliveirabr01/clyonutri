<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use RuntimeException;

/**
 * Erro de domínio da teleconsulta com status HTTP e mensagem PÚBLICA genérica (NI2-221 F15/F20).
 * A mensagem nunca contém IDs, tenant ou detalhes internos.
 */
final class TeleconsultationException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $publicMessage)
    {
        parent::__construct($publicMessage, $httpStatus);
    }

    public static function notFound(): self
    {
        return new self(404, 'Teleconsulta não encontrada.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'Somente o(a) profissional responsável por esta consulta pode fazer isso.');
    }

    public static function conflict(string $msg = 'Esta ação não é permitida no estado atual da teleconsulta.'): self
    {
        return new self(409, $msg);
    }

    public static function invalidLink(): self
    {
        return new self(404, 'Link de teleconsulta inválido ou expirado.');
    }
}
