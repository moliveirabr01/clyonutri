<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

/**
 * Recusa de uma ação da paciente na consulta (NI2-137), já com o código e o status HTTP da API.
 *
 * Códigos: APPOINTMENT_NOT_FOUND (404), REQUEST_NOT_FOUND (404), ACTION_NOT_ALLOWED (403),
 * INVALID_STATE (409), SLOT_CONFLICT (409), REQUEST_PENDING (409), POLICY_VIOLATION (422), VALIDATION_ERROR (422).
 */
final class PatientAppointmentActionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Consulta não encontrada.', 'APPOINTMENT_NOT_FOUND', 404);
    }

    public static function invalidState(string $message, string $currentStatus): self
    {
        return new self($message, 'INVALID_STATE', 409, ['current_status' => $currentStatus]);
    }

    public function toArray(): array
    {
        return ['error' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;
    }
}
