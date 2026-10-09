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

    public static function setTenant(int $clinicId, array $user): void
    {
        self::set($clinicId, $user);
    }

    public static function setClinicId(int $clinicId): void
    {
        self::$currentClinicId = $clinicId;
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

    public static function setUserId(int $userId): void
    {
        if (self::$currentUser === null) {
            self::$currentUser = ['id' => $userId];
        } else {
            self::$currentUser['id'] = $userId;
        }
    }

    public static function getUser(): ?array
    {
        return self::$currentUser;
    }

    public static function getUserId(): ?int
    {
        if (isset(self::$currentUser['id'])) {
            return (int)self::$currentUser['id'];
        }
        if (!empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        return null;
    }

    public static function clear(): void
    {
        self::$currentClinicId = null;
        self::$currentUser = null;
    }
}
