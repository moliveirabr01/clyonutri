<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Providers;

use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;

/**
 * Driver da Daily REST API (https://docs.daily.co/reference/rest-api).
 *
 * - Sala privada, expiração com ejeção, sem gravação (enable_recording é omitido:
 *   a API só aceita valores de string que LIGAM gravação; omitir = desligado).
 * - Sem chat do provedor e sem armazenamento de transcrição.
 * - Token de participante com exp curto e ejeção ao expirar; somente o
 *   profissional é owner (moderador).
 */
class DailyVideoProvider implements VideoConferenceProviderInterface
{
    private ?string $apiKey;
    private ?string $domain;
    private string $apiBaseUrl;

    public function __construct(?string $apiKey = null, ?string $domain = null, string $apiBaseUrl = 'https://api.daily.co/v1')
    {
        $this->apiKey = $apiKey ?? self::env('DAILY_API_KEY');
        $this->domain = $domain ?? self::env('DAILY_DOMAIN');
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }

    public function getProviderName(): string
    {
        return 'daily_co';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->domain);
    }

    public function createRoom(string $roomName, int $expiresAtUnix): array
    {
        $this->assertConfigured('criação de sala');
        $this->assertRoomName($roomName);

        $response = $this->request('POST', '/rooms', [
            'name' => $roomName,
            'privacy' => 'private',
            'properties' => [
                'exp' => $expiresAtUnix,
                'eject_at_room_exp' => true,
                'enable_chat' => false,
                'enable_screenshare' => true,
                'enable_prejoin_ui' => true,
                'enable_knocking' => false,
                'enable_transcription_storage' => false,
                'start_video_off' => false,
                'start_audio_off' => false,
            ],
        ]);

        if (empty($response['name']) || empty($response['url'])) {
            throw new ExternalMediaProviderBlockedException('Resposta da Daily sem nome/URL da sala; provisionamento recusado (fail-closed).');
        }

        return [
            'room_id' => (string)($response['id'] ?? ''),
            'room_name' => (string)$response['name'],
            'room_url' => (string)$response['url'],
            'expires_at_unix' => $expiresAtUnix,
        ];
    }

    public function issueMeetingToken(
        string $roomName,
        string $participantRole,
        string $displayName,
        string $participantRef,
        int $expiresAtUnix
    ): array {
        $this->assertConfigured('emissão de credencial');
        $this->assertRoomName($roomName);
        if (!in_array($participantRole, ['professional', 'patient'], true)) {
            throw new \InvalidArgumentException("Papel de participante inválido: {$participantRole}");
        }

        $response = $this->request('POST', '/meeting-tokens', [
            'properties' => [
                'room_name' => $roomName,
                'user_name' => mb_substr($displayName, 0, 60),
                'user_id' => mb_substr($participantRef, 0, 36),
                'is_owner' => $participantRole === 'professional',
                'exp' => $expiresAtUnix,
                'eject_at_token_exp' => true,
                'start_video_off' => false,
            ],
        ]);

        if (empty($response['token'])) {
            throw new ExternalMediaProviderBlockedException('Daily não retornou credencial de participante (fail-closed).');
        }

        return ['token' => (string)$response['token'], 'expires_at_unix' => $expiresAtUnix];
    }

    public function deleteRoom(string $roomName): bool
    {
        if (!$this->isConfigured() || $roomName === '') {
            return false;
        }
        $this->assertRoomName($roomName);

        try {
            $this->request('DELETE', '/rooms/' . rawurlencode($roomName));
            return true;
        } catch (ExternalMediaProviderBlockedException $e) {
            return false;
        }
    }

    private function assertConfigured(string $operation): void
    {
        if (!$this->isConfigured()) {
            throw new ExternalMediaProviderBlockedException(
                "Daily BLOCKED na {$operation}: DAILY_API_KEY/DAILY_DOMAIN ausentes no ambiente. Nenhuma sala ou credencial simulada é gerada."
            );
        }
    }

    private function assertRoomName(string $roomName): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $roomName)) {
            throw new \InvalidArgumentException('Nome de sala inválido para o provedor.');
        }
    }

    private function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $ch = curl_init($this->apiBaseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new ExternalMediaProviderBlockedException('Falha de rede com a Daily: ' . $error);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400) {
            $info = is_array($decoded) ? (string)($decoded['info'] ?? $decoded['error'] ?? '') : '';
            // Mensagem sem payload, token ou dados do paciente.
            throw new ExternalMediaProviderBlockedException("Daily recusou {$method} {$this->safeEndpoint($endpoint)} (HTTP {$status}) {$info}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function safeEndpoint(string $endpoint): string
    {
        return preg_replace('#^/rooms/.+$#', '/rooms/{name}', $endpoint) ?? $endpoint;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($value === null || $value === '') ? null : (string)$value;
    }
}
