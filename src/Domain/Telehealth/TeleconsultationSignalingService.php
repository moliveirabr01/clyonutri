<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Sinalização do WebRTC P2P por HTTP (compatível com hospedagem compartilhada,
 * sem WebSocket). Cada envio/leitura revalida credencial E estado da sessão
 * (TeleconsultationService::authorizeMediaAccess): encerrar ou cancelar corta
 * a troca imediatamente. Mensagens só são entregues ao outro participante.
 */
class TeleconsultationSignalingService
{
    private const TYPES = ['hello', 'offer', 'answer', 'candidate', 'bye', 'screen', 'state'];
    private const MEDIA_STATES = ['connected', 'reconnecting', 'failed', 'gave_up'];
    private const MAX_PAYLOAD_BYTES = 16384;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?TeleconsultationService $teleconsultation = null, private ?DateTimeImmutable $fixedNow = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->teleconsultation = $teleconsultation ?? new TeleconsultationService($this->db);
    }

    public function send(string $roomName, string $credential, string $type, mixed $payload): array
    {
        $access = $this->teleconsultation->authorizeMediaAccess($roomName, $credential);
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de sinal inválido.');
        }
        if ($type === 'state') {
            // NI2-432: telemetria de conexão (sem conteúdo): vira evento de auditoria, não sinal.
            $state = is_array($payload) ? (string)($payload['state'] ?? '') : '';
            if (!in_array($state, self::MEDIA_STATES, true)) {
                throw new InvalidArgumentException('Estado de mídia inválido.');
            }
            $actorId = $access['role'] === 'professional' ? (int)$access['session']['professional_id'] : (int)$access['session']['patient_id'];
            $this->teleconsultation->recordSystemEvent((int)$access['session']['id'], (int)$access['session']['clinic_id'], 'media_' . $state, $access['role'], $actorId);
            return ['id' => 0];
        }
        if ($type === 'screen') {
            // NI2-430: só o nutricionista compartilha tela; o aviso ao paciente passa por aqui e fica auditado.
            if ($access['role'] !== 'professional') {
                throw new \ClyoNutri\Core\Security\TenantSecurityException('Somente o nutricionista pode compartilhar a tela.');
            }
            $payload = ['on' => !empty($payload['on'])];
            $this->teleconsultation->recordSystemEvent(
                (int)$access['session']['id'], (int)$access['session']['clinic_id'],
                $payload['on'] ? 'screen_share_started' : 'screen_share_stopped',
                'professional', (int)$access['session']['professional_id']
            );
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Sinal inválido ou grande demais.');
        }
        (new TeleconsultationRateLimiter($this->db, $this->fixedNow))->hit('signal', $roomName . '|' . $access['role'], 600, 60);

        $session = $access['session'];
        // Gravação condicionada ao estado no próprio INSERT: um envio autorizado
        // instantes antes do encerramento não grava depois da limpeza da sala.
        $stmt = $this->db->prepare("
            INSERT INTO teleconsultation_signals (clinic_id, session_id, room_name, sender_role, signal_type, payload, created_at_utc)
            SELECT :c, :s, :r, :role, :t, :p, :at
            WHERE EXISTS (
                SELECT 1 FROM teleconsultation_sessions
                WHERE id = :s2 AND clinic_id = :c2 AND external_room_name = :r2 AND provider_status = 'provisioned'
                  AND status IN ('scheduled', 'device_checked', 'waiting_room', 'admitted', 'in_progress')
            )
        ");
        $stmt->execute([
            'c' => (int)$session['clinic_id'], 'c2' => (int)$session['clinic_id'],
            's' => (int)$session['id'], 's2' => (int)$session['id'],
            'r' => $roomName, 'r2' => $roomName,
            'role' => $access['role'],
            't' => $type,
            'p' => $encoded,
            'at' => $this->now()->format('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \ClyoNutri\Core\Security\TenantSecurityException('A teleconsulta foi encerrada.');
        }
        return ['id' => (int)$this->db->lastInsertId()];
    }

    /** Mensagens do outro participante após $afterId, mais o estado da sessão. */
    public function poll(string $roomName, string $credential, int $afterId): array
    {
        $access = $this->teleconsultation->authorizeMediaAccess($roomName, $credential);
        $stmt = $this->db->prepare("
            SELECT id, signal_type, payload FROM teleconsultation_signals
            WHERE room_name = :r AND session_id = :s AND sender_role <> :role AND id > :after
            ORDER BY id ASC LIMIT 100
        ");
        $stmt->execute([
            'r' => $roomName,
            's' => (int)$access['session']['id'],
            'role' => $access['role'],
            'after' => max(0, $afterId),
        ]);
        $messages = array_map(static fn(array $row) => [
            'id' => (int)$row['id'],
            'type' => $row['signal_type'],
            'payload' => json_decode((string)$row['payload'], true),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['status' => $access['session']['status'], 'messages' => $messages];
    }

    private function now(): DateTimeImmutable
    {
        return $this->fixedNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
