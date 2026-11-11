<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

/**
 * @deprecated NI2-221 — substituído por TeleconsultationService. Mantido só para leitura legada.
 */
class TelehealthService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** @throws \RuntimeException sempre: não há mais geração de link fictício. */
    public function generateSession(int $appointmentId, string $provider = 'clyo_meet'): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        // Descontinuado (NI2-221): gerava URL fictícia de videochamada (subdomínio "meet" inexistente) sem sala real.
        // Fail-closed: a teleconsulta usa TeleconsultationService + provedor real.
        throw new \LogicException('TelehealthService::generateSession foi descontinuado; use o Consultório Online (TeleconsultationService).');
    }

    /** Sempre null: o modelo legado não é mais lido (evita exibir URL fictícia). */
    public function getSessionByAppointment(int $appointmentId): ?array
    {
        return null;
    }
}
