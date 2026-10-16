<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Rascunho da evolução durante a chamada (NI2-249).
 *
 * - Visível só ao nutricionista responsável (mesma autorização da sessão).
 * - Controle otimista: cada gravação informa a versão que o editor tinha;
 *   se outra aba gravou antes, devolve conflito com a versão do servidor.
 * - Não é prontuário: só vira registro clínico na finalização explícita
 *   (TeleconsultationService::recordClinicalEvolution), que apaga o rascunho.
 */
class TeleconsultationDraftService
{
    private const MAX_NOTES = 60000;

    private PDO $db;
    private TeleconsultationService $service;

    public function __construct(?PDO $db = null, ?TeleconsultationService $service = null, private ?DateTimeImmutable $fixedNow = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->service = $service ?? new TeleconsultationService($this->db);
    }

    public function get(int $sessionId, int $professionalId, int $clinicId): array
    {
        $this->service->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        $row = $this->row($sessionId, $professionalId, $clinicId);
        return $row ?? ['title' => null, 'notes' => null, 'goals' => null, 'version' => 0, 'updated_at_utc' => null];
    }

    /**
     * @return array{status: string, version: int, updated_at_utc?: string, server?: array}
     */
    public function save(int $sessionId, int $professionalId, int $clinicId, int $baseVersion, array $fields): array
    {
        $session = $this->service->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        if (in_array($session['status'], ['cancelled', 'rejected'], true)) {
            throw new TeleconsultationStateException('Não há atendimento para registrar nesta sessão.');
        }
        $title = mb_substr(trim((string)($fields['title'] ?? '')), 0, 255);
        $notes = (string)($fields['notes'] ?? '');
        $goals = mb_substr((string)($fields['goals'] ?? ''), 0, 1000);
        if (mb_strlen($notes) > self::MAX_NOTES) {
            throw new InvalidArgumentException('A anotação passou do tamanho máximo do rascunho.');
        }
        $now = $this->now();

        $current = $this->row($sessionId, $professionalId, $clinicId);
        if ($current === null) {
            if ($baseVersion !== 0) {
                return ['status' => 'conflict', 'version' => 0, 'server' => null];
            }
            try {
                $this->db->prepare("
                    INSERT INTO teleconsultation_note_drafts (clinic_id, session_id, professional_id, title, notes, goals, version, updated_at_utc)
                    VALUES (:c, :s, :p, :t, :n, :g, 1, :at)
                ")->execute(['c' => $clinicId, 's' => $sessionId, 'p' => $professionalId, 't' => $title, 'n' => $notes, 'g' => $goals, 'at' => $now]);
            } catch (\PDOException $e) {
                // Outra aba criou o rascunho ao mesmo tempo.
                return ['status' => 'conflict', 'version' => 0, 'server' => $this->row($sessionId, $professionalId, $clinicId)];
            }
            return ['status' => 'saved', 'version' => 1, 'updated_at_utc' => $now];
        }

        $stmt = $this->db->prepare("
            UPDATE teleconsultation_note_drafts
            SET title = :t, notes = :n, goals = :g, version = version + 1, updated_at_utc = :at
            WHERE session_id = :s AND professional_id = :p AND clinic_id = :c AND version = :v
        ");
        $stmt->execute(['t' => $title, 'n' => $notes, 'g' => $goals, 'at' => $now, 's' => $sessionId, 'p' => $professionalId, 'c' => $clinicId, 'v' => $baseVersion]);
        if ($stmt->rowCount() !== 1) {
            return ['status' => 'conflict', 'version' => (int)$current['version'], 'server' => $current];
        }
        return ['status' => 'saved', 'version' => $baseVersion + 1, 'updated_at_utc' => $now];
    }

    public function discard(int $sessionId, int $professionalId, int $clinicId): void
    {
        $this->db->prepare('DELETE FROM teleconsultation_note_drafts WHERE session_id = :s AND professional_id = :p AND clinic_id = :c')
            ->execute(['s' => $sessionId, 'p' => $professionalId, 'c' => $clinicId]);
    }

    private function row(int $sessionId, int $professionalId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare('SELECT title, notes, goals, version, updated_at_utc FROM teleconsultation_note_drafts WHERE session_id = :s AND professional_id = :p AND clinic_id = :c');
        $stmt->execute(['s' => $sessionId, 'p' => $professionalId, 'c' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['version'] = (int)$row['version'];
        return $row;
    }

    private function now(): string
    {
        return ($this->fixedNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
