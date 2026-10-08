<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Contracts;

/**
 * Contrato canônico para provedores de videoconferência no ClyoNutri II.
 * Suporta provedores gerenciados WebRTC / SFU (Daily.co, LiveKit Cloud, Jitsi as a Service).
 */
interface VideoConferenceProviderInterface
{
    /**
     * Retorna o identificador do provedor (ex: daily_co, livekit).
     */
    public function getProviderName(): string;

    /**
     * Cria uma sala privada e efêmera no provedor externo.
     * Retorna array com ['room_id' => ..., 'room_url' => ..., 'expires_at' => ...].
     * Se as credenciais reais não estiverem configuradas, DEVE lançar ExternalMediaProviderBlockedException.
     * NUNCA deve gerar URLs fictícias ou de demonstração.
     */
    public function createRoom(string $roomName, int $expiresInSeconds = 7200): array;

    /**
     * Emite um token temporário escopado para um participante (nutricionista ou paciente).
     * Retorna array com ['token' => ..., 'expires_at' => ...].
     */
    public function issueMeetingToken(string $roomName, string $participantRole, string $userName, int $expiresInSeconds = 7200): array;

    /**
     * Encerra a sala e desconecta os participantes no provedor externo.
     */
    public function deleteRoom(string $roomName): bool;
}
