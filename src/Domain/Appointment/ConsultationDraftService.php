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
        $p = ['n' => $notes, 'w' => $weight, 'x' => $waist, 'at' => $now, 'a' => $appointmentId, 'u' => $userId, 'c' => $clinicId];

        $current = $this->row($appointmentId, $userId, $clinicId);
        if ($current === null) {
            if ($baseVersion !== 0) {
                return ['status' => 'conflict', 'version' => 0, 'server' => null];
            }
            try {
                $this->db->prepare('INSERT INTO consultation_note_drafts (clinic_id, appointment_id, user_id, evolution_summary, weight_kg, waist_cm, version, updated_at_utc)
                    VALUES (:c, :a, :u, :n, :w, :x, 1, :at)')->execute($p);
            } catch (\PDOException) {
                return ['status' => 'conflict', 'version' => 0, 'server' => $this->row($appointmentId, $userId, $clinicId)];
            }
            return ['status' => 'saved', 'version' => 1, 'updated_at_utc' => $now];
        }
        $stmt = $this->db->prepare('UPDATE consultation_note_drafts SET evolution_summary = :n, weight_kg = :w, waist_cm = :x,
            version = version + 1, updated_at_utc = :at WHERE appointment_id = :a AND user_id = :u AND clinic_id = :c AND version = :v');
        $stmt->execute($p + ['v' => $baseVersion]);
        if ($stmt->rowCount() !== 1) {
            return ['status' => 'conflict', 'version' => (int)$current['version'], 'server' => $current];
        }
        return ['status' => 'saved', 'version' => $baseVersion + 1, 'updated_at_utc' => $now];
    }

    public function discard(int $appointmentId, int $userId, int $clinicId): void
    {
        $this->db->prepare('DELETE FROM consultation_note_drafts WHERE appointment_id = :a AND user_id = :u AND clinic_id = :c')
            ->execute(['a' => $appointmentId, 'u' => $userId, 'c' => $clinicId]);
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
        $row['version'] = (int)$row['version'];
        return $row;
    }
}
