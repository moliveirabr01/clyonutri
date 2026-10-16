<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Contracts;

/**
 * Contrato canônico para provedores de videoconferência do ClyoNutri.
 *
 * Regras (NI2-234 / NI2-242 / NI2-244):
 * - Salas são privadas: ninguém entra sem credencial emitida pelo backend.
 * - Credenciais de participante são de curta duração e NUNCA persistidas.
 * - Sem credenciais reais configuradas, toda operação de mídia lança
 *   ExternalMediaProviderBlockedException; nunca gerar URL ou token fictício.
 */
interface VideoConferenceProviderInterface
{
    /** Identificador do provedor (ex.: daily_co). */
    public function getProviderName(): string;

    /** Indica se as credenciais do provedor estão configuradas no ambiente. */
    public function isConfigured(): bool;

    /**
     * Cria uma sala privada que expira em $expiresAtUnix (segundos, UTC).
     *
     * @return array{room_id: string, room_name: string, room_url: string, expires_at_unix: int}
     */
    public function createRoom(string $roomName, int $expiresAtUnix): array;

    /**
     * Emite credencial de participante escopada a uma sala.
     * $participantRole: 'professional' (moderador) ou 'patient'.
     *
     * @return array{token: string, expires_at_unix: int}
     */
    public function issueMeetingToken(
        string $roomName,
        string $participantRole,
        string $displayName,
        string $participantRef,
        int $expiresAtUnix
    ): array;

    /** Remove a sala pelo nome, desconectando participantes. */
    public function deleteRoom(string $roomName): bool;
}
