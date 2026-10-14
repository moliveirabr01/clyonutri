<?php
namespace ClyoNutri\Core\Security;
use PDO;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Audit\AuditLogger;

/** Shared request boundary. Roles are read from current membership, never trusted from session. */
final class ClinicalAuthorization
{
    public static function isClinical(array $actor): bool
    {
        return in_array($actor['user_role']??'', ['nutritionist','admin','owner'], true)
            && in_array($actor['member_role']??'', ['owner','admin','nutritionist'], true);
    }

    public static function assertWebAccess(array $actor, string $path, string $method, PDO $db): void
    {
        // Rotas do console administrativo da plataforma SaaS (/admin/...) (NI2-347)
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            $isPlatformAdmin = in_array($actor['user_role'] ?? '', [
                'superadmin',
                'admin_operacional',
                'admin',
                'operator',
                'support_agent',
                'finance_admin',
                'security_auditor'
            ], true);
            if (!$isPlatformAdmin) {
                self::deny($db);
            }
            return;
        }

        // Rotas operacionais do Service Desk / Backoffice (/suporte/...) exigem perfil de suporte canônico (NI2-256 / F01)
        if (str_starts_with($path, '/suporte/')) {
            $isSupport = in_array($actor['user_role'] ?? '', [
                'superadmin',
                'admin_operacional',
                'admin',
                'operator',
                'support_agent'
            ], true);
            if (!$isSupport) {
                self::deny($db);
            }
            return;
        }

        if (self::isClinical($actor)) return;
        if (in_array($actor['user_role']??'', ['operator','support_agent'],true)
            && in_array($actor['member_role']??'', ['operator','support_agent','admin'],true)
            && str_starts_with($path,'/ajuda')) return;
        // Administrative staff can operate their clinic's agenda, contacts, payments and help center.
        $reception = ($actor['user_role']??'') === 'receptionist' && ($actor['member_role']??'') === 'receptionist';
        $safe = $reception && (
            ($path==='/pacientes' && $method==='GET')
            || ($path==='/agenda' && in_array($method,['GET','POST'],true))
            || (preg_match('#^/agenda/\d+/status$#',$path) && $method==='POST')
            || ($path==='/financeiro' && $method==='GET')
            || (preg_match('#^/financeiro/recebiveis/\d+/pagar$#',$path) && $method==='POST')
            || str_starts_with($path,'/ajuda')
        );
        if (!$safe) self::deny($db);
    }

    public static function deny(PDO $db): never
    {
        try { (new AuditLogger($db))->log('SECURITY_AUTHORIZATION_BLOCKED','authorization',null,['reason'=>'role_not_permitted']); }
        catch (\Throwable $e) { error_log('SECURITY_AUTHORIZATION_BLOCKED audit persistence unavailable'); }
        throw new TenantSecurityException('Acesso não autorizado para este perfil.',403);
    }
}
