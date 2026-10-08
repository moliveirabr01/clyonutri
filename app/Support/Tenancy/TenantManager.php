<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Gerenciador central de Tenancy (Multi-tenant isolation).
 * Garante que tenant_id venha estritamente da sessão/token autenticado,
 * nunca de parâmetros não confiáveis enviados pelo cliente.
 */
class TenantManager
{
    private static ?int $currentTenantId = null;

    public static function setTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new RuntimeException("tenant_id inválido.");
        }
        self::$currentTenantId = $tenantId;
    }

    public static function getTenantId(): int
    {
        if (self::$currentTenantId === null) {
            throw new RuntimeException("Nenhum tenant ativo no contexto da requisição.");
        }
        return self::$currentTenantId;
    }

    public static function hasTenant(): bool
    {
        return self::$currentTenantId !== null;
    }

    public static function clear(): void
    {
        self::$currentTenantId = null;
    }
}
