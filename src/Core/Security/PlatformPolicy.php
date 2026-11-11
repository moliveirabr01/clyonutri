<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use ClyoNutri\Core\Auth\AuthUser;

/**
 * Matriz de Permissões do Console Administrativo da Plataforma (NI2-403 / AUD-01)
 * Segregação estrita de privilégios para superadmin, admin_operacional, support_agent, finance_admin e security_auditor.
 */
final class PlatformPolicy
{
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN_OPERACIONAL = 'admin_operacional';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_SUPPORT_AGENT = 'support_agent';
    public const ROLE_FINANCE_ADMIN = 'finance_admin';
    public const ROLE_SECURITY_AUDITOR = 'security_auditor';

    /**
     * Mapeamento canônico: papel => lista de permissões granulares
     */
    private const ROLE_PERMISSIONS = [
        self::ROLE_SUPERADMIN => [
            'dashboard.read',
            'analytics.read', // NI2-96: métricas de adesão agregadas
            'clinics.read',
            'clinics.suspend',
            'clinics.activate',
            'users.read',
            'users.suspend',
            'users.activate',
            'users.manage', // v33: criar equipe, redefinir senha, bloquear e liberar (só o superadmin)
            'sessions.read',
            'sessions.revoke',
            'tickets.read',
            'tickets.manage',
            'billing.read',
            'billing.manage',
            'settings.read',
            'settings.manage',
            'audit.read',
            'search.read',
            'events.read',
            'events.manage',
            'events.export',
        ],
        self::ROLE_ADMIN_OPERACIONAL => [
            'dashboard.read',
            'analytics.read', // NI2-96: métricas de adesão agregadas
            'clinics.read',
            'clinics.suspend',
            'clinics.activate',
            'users.read',
            'users.suspend',
            'users.activate',
            'sessions.read',
            'sessions.revoke',
            'tickets.read',
            'tickets.manage',
            'billing.read',
            'audit.read',
            'search.read',
            'events.read',
            'events.manage',
            'events.export',
        ],
        self::ROLE_OPERATOR => [
            'dashboard.read',
            'analytics.read', // NI2-96: métricas de adesão agregadas
            'clinics.read',
            'clinics.suspend',
            'clinics.activate',
            'users.read',
            'users.suspend',
            'users.activate',
            'sessions.read',
            'sessions.revoke',
            'tickets.read',
            'tickets.manage',
            'billing.read',
            'audit.read',
            'search.read',
            'events.read',
            'events.manage',
            'events.export',
        ],
        self::ROLE_SUPPORT_AGENT => [
            'dashboard.read',
            'clinics.read',
            'users.read',
            'tickets.read',
            'tickets.manage',
            'search.read',
            // Suporte NÃO PODE: clinics.suspend, users.suspend, sessions.revoke, settings.manage, billing.manage, audit.read, events.read
        ],
        self::ROLE_FINANCE_ADMIN => [
            'dashboard.read',
            'clinics.read',
            'billing.read',
            'billing.manage',
            'search.read',
            // Financeiro NÃO PODE: clinics.suspend, users.suspend, sessions.revoke, settings.manage, tickets.manage, events.read
        ],
        self::ROLE_SECURITY_AUDITOR => [
            'dashboard.read',
            'clinics.read',
            'users.read',
            'sessions.read',
            'audit.read',
            'search.read',
            'events.read',
            'events.export',
            // Auditor é estritamente LEITURA: nenhuma mutação permitida
        ],
    ];

    /**
     * Verifica se o usuário da plataforma possui a permissão requerida.
     */
    public static function can(AuthUser|string $userOrRole, string $permission): bool
    {
        $role = $userOrRole instanceof AuthUser ? $userOrRole->getUserRole() : $userOrRole;
        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];

        return in_array($permission, $permissions, true);
    }

    /**
     * Lança exceção de segurança se o usuário não possuir a permissão.
     */
    public static function authorize(AuthUser $user, string $permission): void
    {
        if (!self::can($user, $permission)) {
            throw new TenantSecurityException("Acesso negado: o perfil '{$user->getUserRole()}' não possui a permissão administrativa '{$permission}'.");
        }
    }

    /**
     * Retorna a lista de todas as permissões concedidas ao papel.
     */
    public static function getPermissionsForRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }
}
