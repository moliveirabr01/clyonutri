<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

/**
 * LEGADO (tabela telehealth_sessions). Desativado na v11 (NI2-221 F02):
 * gerava a URL fictícia meet.clyonutri.com.br, que não existe. O modelo único é teleconsultation_sessions
 * (TeleconsultationService). Nada novo é gravado em telehealth_sessions; a tabela fica intacta (sem DROP).
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
        throw new \RuntimeException('Teleconsulta legada desativada: use a sala de teleconsulta da agenda (vídeo depende de provedor configurado).');
    }

    /** Sempre null: o modelo legado não é mais lido (evita exibir URL fictícia). */
    public function getSessionByAppointment(int $appointmentId): ?array
    {
        return null;
    }
}
