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

    public function generateSession(int $appointmentId, string $provider = 'clyo_meet'): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        // Descontinuado (NI2-221): gerava URL fictícia (meet.clyonutri.com.br) sem sala real.
        // Fail-closed: a teleconsulta usa TeleconsultationService + provedor real.
        throw new \LogicException('TelehealthService::generateSession foi descontinuado; use o Consultório Online (TeleconsultationService).');
    }

    public function getSessionByAppointment(int $appointmentId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT * FROM telehealth_sessions WHERE appointment_id = :app_id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['app_id' => $appointmentId, 'clinic_id' => $clinicId]);
        $session = $stmt->fetch();
        return $session ?: null;
    }
}
