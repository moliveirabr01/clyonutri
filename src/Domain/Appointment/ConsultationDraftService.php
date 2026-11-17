<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

use ClyoNutri\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * UX-03 (v14): rascunho das anotações em /consulta/{id}, por consulta + usuário.
 * Mesmo modelo do rascunho da teleconsulta (NI2-249): versão otimista, não é prontuário,
 * apagado ao concluir. A consulta é sempre buscada no escopo da clínica atual.
 */
class ConsultationDraftService
{
    private const MAX_NOTES = 60000;
    private PDO $db;

    public function __construct(?PDO $db = null, private ?DateTimeImmutable $fixedNow = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function get(int $appointmentId, int $userId, int $clinicId): array
    {
        $this->assertAppointment($appointmentId, $clinicId);
        return $this->row($appointmentId, $userId, $clinicId)
            ?? ['evolution_summary' => null, 'weight_kg' => null, 'waist_cm' => null, 'version' => 0, 'updated_at_utc' => null];
    }

    public function save(int $appointmentId, int $userId, int $clinicId, int $baseVersion, array $fields): array
    {
        $this->assertWriteAllowed();
        $status = $this->assertAppointment($appointmentId, $clinicId);
        if (in_array($status, ['completed', 'cancelled', 'no_show'], true)) {
            throw new InvalidArgumentException('Esta consulta não está mais em atendimento; o rascunho não foi salvo.');
        }
        $notes = (string)($fields['evolution_summary'] ?? '');
        if (mb_strlen($notes) > self::MAX_NOTES) {
            throw new InvalidArgumentException('A anotação passou do tamanho máximo do rascunho.');
        }
        $weight = mb_substr(trim((string)($fields['weight_kg'] ?? '')), 0, 20);
        $waist = mb_substr(trim((string)($fields['waist_cm'] ?? '')), 0, 20);
        $now = ($this->fixedNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $encryptedNotes = $notes !== '' ? \ClyoNutri\Core\Security\ClinicalEncryptionService::encrypt($notes) : '';
        $p = ['n' => $encryptedNotes, 'w' => $weight, 'x' => $waist, 'at' => $now, 'a' => $appointmentId, 'u' => $userId, 'c' => $clinicId];

        $current = $this->row($appointmentId, $userId, $clinicId);
        if ($current === null) {
            if ($baseVersion !== 0) {
                return ['status' => 'conflict', 'version' => 0, 'server' => null];
            }
            try {
                $this->db->prepare('INSERT INTO consultation_note_drafts (clinic_id, appointment_id, user_id, evolution_summary, weight_kg, waist_cm, version, updated_at_utc)
                    VALUES (:c, :a, :u, :n, :w, :x, 1, :at)')->execute($p);
            } catch (\PDOException $e) {
                if (self::isUniqueConstraintViolation($e)) {
                    $existing = $this->row($appointmentId, $userId, $clinicId);
                    return [
                        'status' => 'conflict',
                        'version' => (int)($existing['version'] ?? 0),
                        'server' => $existing,
                    ];
                }
                throw $e;
            }
            return ['status' => 'saved', 'version' => 1, 'updated_at_utc' => $now];
        }
        $stmt = $this->db->prepare('UPDATE consultation_note_drafts SET evolution_summary = :n, weight_kg = :w, waist_cm = :x,
            version = version + 1, updated_at_utc = :at WHERE appointment_id = :a AND user_id = :u AND clinic_id = :c AND version = :v');
        $stmt->execute($p + ['v' => $baseVersion]);
        if ($stmt->rowCount() !== 1) {
            $latest = $this->row($appointmentId, $userId, $clinicId) ?? $current;
            return ['status' => 'conflict', 'version' => (int)$latest['version'], 'server' => $latest];
        }
        return ['status' => 'saved', 'version' => $baseVersion + 1, 'updated_at_utc' => $now];
    }

    public static function isUniqueConstraintViolation(\PDOException $e): bool
    {
        $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        $msg = $e->getMessage();

        // MySQL 1062 (ER_DUP_ENTRY), SQLite 2067/1555 (SQLITE_CONSTRAINT_UNIQUE / PRIMARYKEY), ANSI 23000/23505
        if ($driverCode === 1062 || in_array($driverCode, [1555, 2067], true) || $sqlState === '23505') {
            return true;
        }
        if ($sqlState === '23000') {
            return stripos($msg, 'Duplicate entry') !== false
                || stripos($msg, 'UNIQUE constraint failed') !== false;
        }
        return stripos($msg, 'Duplicate entry') !== false
            || stripos($msg, 'UNIQUE constraint failed') !== false;
    }

    public function discard(int $appointmentId, int $userId, int $clinicId, string $reason = 'consultation_completed'): void
    {
        $existing = $this->row($appointmentId, $userId, $clinicId);
        $this->db->prepare('DELETE FROM consultation_note_drafts WHERE appointment_id = :a AND user_id = :u AND clinic_id = :c')
            ->execute(['a' => $appointmentId, 'u' => $userId, 'c' => $clinicId]);

        if ($existing !== null) {
            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                     VALUES (:clinic_id, :user_id, :action, :resource, :resource_id, :ip, :ua, :details)'
                );
                $stmt->execute([
                    'clinic_id' => $clinicId,
                    'user_id' => $userId,
                    'action' => 'CONSULTATION_DRAFT_DISCARDED',
                    'resource' => 'consultation_note_draft',
                    'resource_id' => (string)$appointmentId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                    'details' => json_encode([
                        'appointment_id' => $appointmentId,
                        'version' => (int)$existing['version'],
                        'reason' => $reason,
                        'summary_length' => mb_strlen((string)($existing['evolution_summary'] ?? '')),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable) {
                // Bancos de teste parciais sem tabela audit_logs ignoram silenciosamente
            }
        }
    }

    private function assertAppointment(int $appointmentId, int $clinicId): string
    {
        $stmt = $this->db->prepare('SELECT status FROM appointments WHERE id = :a AND clinic_id = :c');
        $stmt->execute(['a' => $appointmentId, 'c' => $clinicId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new InvalidArgumentException('Consulta não encontrada.');
        }
        return (string)$status;
    }

    private function row(int $appointmentId, int $userId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare('SELECT evolution_summary, weight_kg, waist_cm, version, updated_at_utc FROM consultation_note_drafts
            WHERE appointment_id = :a AND user_id = :u AND clinic_id = :c');
        $stmt->execute(['a' => $appointmentId, 'u' => $userId, 'c' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (isset($row['evolution_summary']) && is_string($row['evolution_summary']) && $row['evolution_summary'] !== '') {
            $row['evolution_summary'] = \ClyoNutri\Core\Security\ClinicalEncryptionService::decrypt($row['evolution_summary']);
        }
        $row['version'] = (int)$row['version'];
        return $row;
    }

    private function assertWriteAllowed(): void
    {
        if (getenv('MIGRATION_WRITE_LOCK') === '1' || is_file(dirname(__DIR__, 3) . '/storage/maintenance_migration.lock')) {
            throw new \PDOException('Gravação de rascunho temporariamente bloqueada durante janela de migração/manutenção.');
        }

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $fks = (int)$this->db->query("SELECT COUNT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_note_drafts'
                  AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE = 'RESTRICT'")->fetchColumn();
            if ($fks < 2) {
                throw new \PDOException('Integridade referencial de consultation_note_drafts pendente de validação (ON DELETE RESTRICT ausente).');
            }
        } elseif ($driver === 'sqlite' && getenv('DRAFT_ENFORCE_FK_CHECK') === '1') {
            $fks = $this->db->query("PRAGMA foreign_key_list('consultation_note_drafts')")->fetchAll(PDO::FETCH_ASSOC);
            $fkRestrictCount = 0;
            foreach ($fks as $fk) {
                if (in_array($fk['table'] ?? '', ['clinics', 'appointments'], true) && strtoupper((string)($fk['on_delete'] ?? '')) === 'RESTRICT') {
                    $fkRestrictCount++;
                }
            }
            if ($fkRestrictCount < 2) {
                throw new \PDOException('Integridade referencial de consultation_note_drafts pendente de validação (ON DELETE RESTRICT ausente).');
            }
        }
    }
}

