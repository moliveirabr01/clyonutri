<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth\Contracts;

/**
 * Provedor cujas credenciais são verificadas pelo próprio ClyoNutri
 * (ex.: WebRTC P2P com sinalização no backend).
 */
interface VerifiableCredentialProviderInterface
{
    /**
     * Verifica assinatura, sala e validade. Lança TenantSecurityException se inválida.
     * @return array{room: string, role: string, ref: string, exp: int}
     */
    public function verifyMeetingToken(string $roomName, string $token, int $nowUnix): array;

    /** Servidores ICE (STUN/TURN) entregues ao navegador. */
    public function iceServers(): array;
}
