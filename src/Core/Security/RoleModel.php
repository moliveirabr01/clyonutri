<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

/**
 * Modelo de papéis do ClyoNutri (confirmado por Maicon em 14/10/2026). Ver docs/delivery/MODELO_PAPEIS.md.
 *
 * Existem DOIS eixos independentes:
 *
 * 1. users.role — papel na PLATAFORMA (equipe ClyoNutri) ou tipo de conta.
 *    'admin' aqui significa ADMINISTRADOR DA PLATAFORMA (acesso global ao service desk e ao console /admin).
 *    Só a própria plataforma provisiona esses papéis (CLI bin/create_superadmin.php ou um admin de plataforma).
 *
 * 2. clinic_members.role — papel DENTRO de uma clínica ('owner', 'admin', 'nutritionist', 'receptionist'...).
 *    'admin' aqui é apenas gestor daquela clínica e NUNCA concede acesso de plataforma/suporte global.
 *
 * Toda decisão de suporte/plataforma deve usar users.role via este arquivo; nunca clinic_members.role.
 */
final class RoleModel
{
    /** users.role que operam o service desk global. */
    public const PLATFORM_SUPPORT_ROLES = ['superadmin', 'admin_operacional', 'admin', 'operator', 'support_agent'];

    /** users.role com acesso ao console /admin. */
    public const PLATFORM_CONSOLE_ROLES = ['superadmin', 'admin_operacional', 'admin', 'operator', 'support_agent', 'finance_admin', 'security_auditor'];

    /** users.role que podem conceder o papel de plataforma 'admin'/'superadmin'. */
    public const PLATFORM_ADMIN_GRANTORS = ['superadmin', 'admin'];

    /** users.role que podem conceder papéis de equipe de suporte (operator/support_agent). */
    public const PLATFORM_STAFF_GRANTORS = ['superadmin', 'admin', 'admin_operacional', 'operator'];

    /** users.role de plataforma (provisionados só pela plataforma). */
    public const PLATFORM_ROLES = ['superadmin', 'admin_operacional', 'admin', 'operator', 'support_agent', 'finance_admin', 'security_auditor'];

    public static function isPlatformSupport(?string $userRole): bool
    {
        return in_array((string)$userRole, self::PLATFORM_SUPPORT_ROLES, true);
    }

    public static function isPlatformConsole(?string $userRole): bool
    {
        return in_array((string)$userRole, self::PLATFORM_CONSOLE_ROLES, true);
    }

    /** Quem ($grantorUserRole, users.role) pode atribuir $targetUserRole em users.role. */
    public static function canGrantUserRole(?string $grantorUserRole, string $targetUserRole): bool
    {
        if (!in_array($targetUserRole, self::PLATFORM_ROLES, true)) {
            return true; // papéis de conta clínica (nutritionist, receptionist...) seguem a regra de quem chama
        }
        if (in_array($targetUserRole, ['admin', 'superadmin', 'admin_operacional', 'finance_admin', 'security_auditor'], true)) {
            return in_array((string)$grantorUserRole, self::PLATFORM_ADMIN_GRANTORS, true);
        }
        return in_array((string)$grantorUserRole, self::PLATFORM_STAFF_GRANTORS, true);
    }
}
