<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Providers;

use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use RuntimeException;

/**
 * Driver de produção para integração com a Daily Video API (Daily.co).
 * Responsável por gerenciar salas privadas e tokens de acesso escopados sem persistir mídia.
 */
class DailyVideoProvider implements VideoConferenceProviderInterface
{
    private ?string $apiKey;
    private ?string $domain;
    private string $apiBaseUrl;

    public function __construct(?string $apiKey = null, ?string $domain = null, string $apiBaseUrl = 'https://api.daily.co/v1')
    {
        $this->apiKey = $apiKey ?? (getenv('DAILY_API_KEY') ?: ($_ENV['DAILY_API_KEY'] ?? null));
        $this->domain = $domain ?? (getenv('DAILY_DOMAIN') ?: ($_ENV['DAILY_DOMAIN'] ?? null));
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

    public function createRoom(string $roomName, int $expiresInSeconds = 7200): array
    {
        if (!$this->isConfigured()) {
            throw new ExternalMediaProviderBlockedException(
                "Integração WebRTC Daily.co BLOCKED: DAILY_API_KEY ou DAILY_DOMAIN não configurados no ambiente. " .
                "Proibido criar sala mock ou link falso para conformidade com o critério de aceite NI2-234/NI2-242."
            );
        }

        $expTimestamp = time() + $expiresInSeconds;
        $payload = [
            'name' => $roomName,
            'privacy' => 'private', // Somente participantes com meeting token autorizado
            'properties' => [
                'exp' => $expTimestamp,
                'eject_at_room_exp' => true,
                'enable_recording' => false, // Política estrita: gravação desabilitada por padrão
                'enable_screenshare' => true,
                'enable_chat' => false, // Chat de saúde deve usar o canal auditado interno do ClyoNutri
                'start_video_off' => false,
                'start_audio_off' => false,
            ],
        ];

        $response = $this->executeHttpRequest('POST', '/rooms', $payload);

        return [
            'room_id' => $response['id'] ?? $roomName,
            'room_url' => $response['url'] ?? "https://{$this->domain}.daily.co/{$roomName}",
            'expires_at' => date('Y-m-d H:i:s', $expTimestamp),
        ];
    }

    public function issueMeetingToken(string $roomName, string $participantRole, string $userName, int $expiresInSeconds = 7200): array
    {
        if (!$this->isConfigured()) {
            throw new ExternalMediaProviderBlockedException(
                "Emissão de token Daily.co BLOCKED: Credenciais do provedor ausentes. Proibido emitir token simulado."
            );
        }

        $expTimestamp = time() + $expiresInSeconds;
        $isOwner = ($participantRole === 'professional');

        $payload = [
            'properties' => [
                'room_name' => $roomName,
                'user_name' => $userName,
                'is_owner' => $isOwner,
                'exp' => $expTimestamp,
                'enable_recording' => false,
                'start_video_off' => false,
                'start_audio_off' => false,
            ],
        ];

        $response = $this->executeHttpRequest('POST', '/meeting-tokens', $payload);

        return [
            'token' => $response['token'] ?? '',
            'expires_at' => date('Y-m-d H:i:s', $expTimestamp),
        ];
    }

    public function deleteRoom(string $roomName): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->executeHttpRequest('DELETE', "/rooms/{$roomName}");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function executeHttpRequest(string $method, string $endpoint, ?array $payload = null): array
    {
        $url = $this->apiBaseUrl . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new ExternalMediaProviderBlockedException("Erro de conexão de rede com provedor Daily.co: " . $curlError);
        }

        $decoded = json_decode((string)$rawResponse, true);

        if ($httpCode >= 400) {
            $errorMsg = $decoded['info'] ?? ($decoded['error'] ?? "HTTP Status {$httpCode}");
            throw new ExternalMediaProviderBlockedException("Falha na chamada da API Daily.co ({$httpCode}): {$errorMsg}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
