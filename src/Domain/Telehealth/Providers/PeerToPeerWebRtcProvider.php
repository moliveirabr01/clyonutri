<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Providers;

use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Telehealth\Contracts\VerifiableCredentialProviderInterface;
use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use PDO;

/**
 * WebRTC nativo ponto a ponto — sem custo e sem fornecedor de mídia.
 *
 * - Áudio/vídeo trafegam direto entre os navegadores (DTLS-SRTP); nada de mídia
 *   passa pelo servidor do ClyoNutri e nada é gravado.
 * - A "sala" é apenas um canal de sinalização (offer/answer/ICE) no backend.
 * - Credencial = token HMAC (derivado do APP_KEY) com sala, papel, participante
 *   e expiração; nunca persistido. O estado da sessão é revalidado a cada uso.
 * - STUN público gratuito por padrão. Sem TURN, parte das conexões em redes
 *   restritivas (CGNAT simétrico, firewalls corporativos) não se estabelece;
 *   TURN é opcional via TELECONSULT_TURN_* no .env.
 */
class PeerToPeerWebRtcProvider implements VideoConferenceProviderInterface, VerifiableCredentialProviderInterface
{
    private const DEFAULT_STUN = 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302';

    private ?string $signingKey;

    public function __construct(private PDO $db, ?string $appKey = null)
    {
        $appKey = $appKey ?? self::env('APP_KEY');
        $this->signingKey = ($appKey !== null && strlen($appKey) >= 16)
            ? hash_hmac('sha256', 'clyonutri-teleconsulta-media-v1', $appKey, true)
            : null;
    }

    public function getProviderName(): string
    {
        return 'webrtc_p2p';
    }

    public function isConfigured(): bool
    {
        return $this->signingKey !== null;
    }

    public function createRoom(string $roomName, int $expiresAtUnix): array
    {
        $this->assertConfigured();
        $this->assertRoomName($roomName);
        return ['room_id' => $roomName, 'room_name' => $roomName, 'room_url' => '', 'expires_at_unix' => $expiresAtUnix];
    }

    public function issueMeetingToken(string $roomName, string $participantRole, string $displayName, string $participantRef, int $expiresAtUnix): array
    {
        $this->assertConfigured();
        $this->assertRoomName($roomName);
        if (!in_array($participantRole, ['professional', 'patient'], true)) {
            throw new \InvalidArgumentException("Papel de participante inválido: {$participantRole}");
        }
        $claims = [
            'room' => $roomName,
            'role' => $participantRole,
            'ref' => mb_substr($participantRef, 0, 64),
            'exp' => $expiresAtUnix,
            'n' => bin2hex(random_bytes(8)),
        ];
        $body = self::b64(json_encode($claims));
        return ['token' => $body . '.' . self::b64(hash_hmac('sha256', $body, (string)$this->signingKey, true)), 'expires_at_unix' => $expiresAtUnix];
    }

    public function verifyMeetingToken(string $roomName, string $token, int $nowUnix): array
    {
        $this->assertConfigured();
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($token) > 600) {
            throw new TenantSecurityException('Credencial de mídia inválida.');
        }
        [$body, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $body, (string)$this->signingKey, true));
        if (!hash_equals($expected, $signature)) {
            throw new TenantSecurityException('Credencial de mídia inválida.');
        }
        $claims = json_decode((string)self::unb64($body), true);
        if (!is_array($claims) || ($claims['room'] ?? null) !== $roomName
            || !in_array($claims['role'] ?? null, ['professional', 'patient'], true)) {
            throw new TenantSecurityException('Credencial de mídia não pertence a esta sala.');
        }
        if ((int)($claims['exp'] ?? 0) < $nowUnix) {
            throw new TenantSecurityException('Credencial de mídia expirada. Recarregue a página.');
        }
        return ['room' => $roomName, 'role' => (string)$claims['role'], 'ref' => (string)($claims['ref'] ?? ''), 'exp' => (int)$claims['exp']];
    }

    /** Liberar a sala apaga a sinalização (SDP e candidatos ICE contêm IPs). */
    public function deleteRoom(string $roomName): bool
    {
        if ($roomName === '') {
            return false;
        }
        $this->db->prepare('DELETE FROM teleconsultation_signals WHERE room_name = :r')->execute(['r' => $roomName]);
        return true;
    }

    public function iceServers(): array
    {
        $servers = [];
        $stun = self::env('TELECONSULT_STUN_URLS') ?? self::DEFAULT_STUN;
        $stunUrls = array_values(array_filter(array_map('trim', explode(',', $stun)), static fn($u) => str_starts_with($u, 'stun:')));
        if ($stunUrls !== []) {
            $servers[] = ['urls' => $stunUrls];
        }
        $turn = self::env('TELECONSULT_TURN_URLS');
        if ($turn !== null) {
            $turnUrls = array_values(array_filter(array_map('trim', explode(',', $turn)), static fn($u) => preg_match('/^turns?:/', $u) === 1));
            if ($turnUrls !== []) {
                $servers[] = [
                    'urls' => $turnUrls,
                    'username' => (string)(self::env('TELECONSULT_TURN_USERNAME') ?? ''),
                    'credential' => (string)(self::env('TELECONSULT_TURN_CREDENTIAL') ?? ''),
                ];
            }
        }
        return $servers;
    }

    public function hasTurn(): bool
    {
        return count($this->iceServers()) > 1;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new ExternalMediaProviderBlockedException('WebRTC BLOCKED: APP_KEY ausente ou curta (mín. 16 caracteres) para assinar credenciais de mídia.');
        }
    }

    private function assertRoomName(string $roomName): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $roomName)) {
            throw new \InvalidArgumentException('Nome de sala inválido.');
        }
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
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
