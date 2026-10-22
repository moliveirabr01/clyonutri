<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Chat;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;

/**
 * Vínculo paciente–profissional (NI2-138): é ele que libera o chat. Um vínculo ativo por par; encerrar guarda o
 * histórico (quem, quando e por quê). Consulta marcada e plano publicado com o profissional reativam o vínculo.
 */
final class PatientLinkService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'patient_professional_links');
    }

    public function activeLink(int $clinicId, int $patientId, int $professionalId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $st = $this->db->prepare("SELECT * FROM patient_professional_links WHERE clinic_id = ? AND patient_id = ? AND professional_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $st->execute([$clinicId, $patientId, $professionalId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Vínculos ativos da paciente com profissionais que seguem ativos na clínica. */
    public function activeLinksForPatient(int $patientId): array
    {
        if (!$this->available()) {
            return [];
        }
        $clinicId = TenantContext::getClinicId();
        $st = $this->db->prepare("
            SELECT l.*, u.name AS professional_name, pr.crn AS professional_crn
            FROM patient_professional_links l
            JOIN professionals pr ON pr.id = l.professional_id
            JOIN users u ON u.id = pr.user_id AND u.status = 'active'
            JOIN clinic_members cm ON cm.user_id = pr.user_id AND cm.clinic_id = l.clinic_id AND cm.status = 'active'
            WHERE l.clinic_id = ? AND l.patient_id = ? AND l.status = 'active'
            ORDER BY l.started_at DESC, l.id DESC
        ");
        $st->execute([$clinicId, $patientId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Todos os vínculos da paciente (tela do profissional). */
    public function linksForPatient(int $patientId): array
    {
        if (!$this->available()) {
            return [];
        }
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare("
            SELECT l.*, u.name AS professional_name, eu.name AS ended_by_name
            FROM patient_professional_links l
            JOIN professionals pr ON pr.id = l.professional_id
            JOIN users u ON u.id = pr.user_id
            LEFT JOIN users eu ON eu.id = l.ended_by_user_id
            WHERE l.clinic_id = ? AND l.patient_id = ?
            ORDER BY CASE WHEN l.status = 'active' THEN 0 ELSE 1 END, l.id DESC
        ");
        $st->execute([$clinicId, $patientId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Profissionais ativos da clínica (para liberar o chat). */
    public function clinicProfessionals(): array
    {
        $st = $this->db->prepare("
            SELECT pr.id, u.name FROM professionals pr
            JOIN users u ON u.id = pr.user_id AND u.status = 'active'
            JOIN clinic_members cm ON cm.user_id = pr.user_id AND cm.clinic_id = ? AND cm.status = 'active'
            WHERE cm.role IN ('owner', 'admin', 'nutritionist')
            ORDER BY u.name, pr.id
        ");
        $st->execute([TenantContext::getClinicId()]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activate(int $patientId, int $professionalId, ?int $userId, string $source = 'manual'): array
    {
        if (!$this->available()) {
            throw new ChatException('O vínculo de chat ainda não está disponível neste servidor (migração 48 pendente).', 'VALIDATION_ERROR', 422);
        }
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        if (!$this->professionalActive($clinicId, $professionalId)) {
            throw new ChatException('Profissional não encontrado ou inativo no consultório.', 'VALIDATION_ERROR', 422);
        }
        $existing = $this->activeLink($clinicId, $patientId, $professionalId);
        if ($existing !== null) {
            return $existing;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO patient_professional_links (clinic_id, patient_id, professional_id, status, source, started_at, created_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?)")->execute([$clinicId, $patientId, $professionalId, substr($source, 0, 30), $now, $userId, $now, $now]);
        $id = (int)$this->db->lastInsertId();
        (new AuditLogger($this->db))->log('CHAT_LINK_ACTIVATED', 'patient_professional_link', (string)$id, [
            'patient_id' => $patientId, 'professional_id' => $professionalId, 'source' => $source, 'actor_user_id' => $userId,
        ]);
        return $this->activeLink($clinicId, $patientId, $professionalId) ?? [];
    }

    /** Consulta marcada ou plano publicado: o cuidado está ativo, então o vínculo também (silencioso, nunca falha a operação). */
    public function ensureFromCare(int $clinicId, int $patientId, int $professionalId, string $source): void
    {
        try {
            if (!$this->available() || $this->activeLink($clinicId, $patientId, $professionalId) !== null
                || !$this->professionalActive($clinicId, $professionalId)) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            $this->db->prepare("INSERT INTO patient_professional_links (clinic_id, patient_id, professional_id, status, source, started_at, created_at, updated_at)
                VALUES (?, ?, ?, 'active', ?, ?, ?, ?)")->execute([$clinicId, $patientId, $professionalId, substr($source, 0, 30), $now, $now, $now]);
        } catch (\Throwable $e) {
            error_log('NI2-138 vínculo automático não criado: ' . $e->getMessage());
        }
    }

    public function end(int $linkId, ?int $userId, string $reason): array
    {
        $clinicId = TenantContext::getClinicId();
        $st = $this->db->prepare('SELECT * FROM patient_professional_links WHERE id = ? AND clinic_id = ?');
        $st->execute([$linkId, $clinicId]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            throw new ChatException('Vínculo não encontrado.', 'VALIDATION_ERROR', 404);
        }
        if ($link['status'] !== 'active') {
            throw new ChatException('Este vínculo já foi encerrado.', 'VALIDATION_ERROR', 409);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 200) {
            throw new ChatException('O motivo pode ter até 200 caracteres.', 'VALIDATION_ERROR', 422);
        }
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE patient_professional_links SET status = 'inactive', ended_at = ?, ended_reason = ?, ended_by_user_id = ?, updated_at = ?
            WHERE id = ? AND clinic_id = ? AND status = 'active'")->execute([$now, $reason !== '' ? $reason : null, $userId, $now, $linkId, $clinicId]);
        (new AuditLogger($this->db))->log('CHAT_LINK_ENDED', 'patient_professional_link', (string)$linkId, [
            'patient_id' => (int)$link['patient_id'], 'professional_id' => (int)$link['professional_id'], 'actor_user_id' => $userId, 'reason_given' => $reason !== '',
        ]);
        return $link;
    }

    public function professionalActive(int $clinicId, int $professionalId): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM professionals pr JOIN users u ON u.id = pr.user_id AND u.status = 'active'
            JOIN clinic_members cm ON cm.user_id = pr.user_id AND cm.clinic_id = ? AND cm.status = 'active' WHERE pr.id = ? LIMIT 1");
        $st->execute([$clinicId, $professionalId]);
        return (bool)$st->fetchColumn();
    }
}
