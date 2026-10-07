<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use RuntimeException;

class TenantContext
{
    private static ?int $currentClinicId = null;
    private static ?array $currentUser = null;

    public static function set(int $clinicId, array $user): void
    {
        self::$currentClinicId = $clinicId;
        self::$currentUser = $user;
    }

    public static function getClinicId(): int
    {
        if (self::$currentClinicId === null) {
            throw new RuntimeException("Violação de segurança: Operação sem TenantContext (clinic_id) definido.");
        }
        return self::$currentClinicId;
    }

    public static function hasClinic(): bool
    {
        return self::$currentClinicId !== null;
    }

    public static function getUser(): ?array
    {
        return self::$currentUser;
    }

    public static function clear(): void
    {
        self::$currentClinicId = null;
        self::$currentUser = null;
    }
}
