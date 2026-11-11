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
        if (!self::allowsWeb($actor, $path, $method)) {
            self::deny($db);
        }
    }

    /**
     * v54.57 (UX-104): a mesma regra de assertWebAccess, sem efeito colateral. Usada também para não oferecer no menu
     * ou numa página de erro um destino que o perfil não pode abrir.
     */
    public static function allowsWeb(array $actor, string $path, string $method): bool
    {
        // Rotas do console administrativo da plataforma SaaS (/admin/...) (NI2-347)
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            // users.role (plataforma) — nunca member_role (clínica). Ver RoleModel.
            return RoleModel::isPlatformConsole($actor['user_role'] ?? '');
        }

        // Rotas operacionais do Service Desk / Backoffice (/suporte/...) exigem perfil de suporte canônico (NI2-256 / F01)
        if (str_starts_with($path, '/suporte/')) {
            return RoleModel::isPlatformSupport($actor['user_role'] ?? '');
        }

        if (self::isClinical($actor)) return true;
        if (in_array($actor['user_role']??'', ['operator','support_agent'],true)
            && in_array($actor['member_role']??'', ['operator','support_agent','admin'],true)
            && str_starts_with($path,'/ajuda')) return true;
        // Administrative staff can operate their clinic's agenda, contacts, payments and help center.
        $reception = ($actor['user_role']??'') === 'receptionist' && ($actor['member_role']??'') === 'receptionist';
        return $reception && (
            ($path==='/pacientes' && $method==='GET')
            || ($path==='/agenda' && in_array($method,['GET','POST'],true))
            || (preg_match('#^/agenda/\d+/status$#',$path) && $method==='POST')
            || (preg_match('#^/agenda/\d+/previa-confirmacao$#',$path) && $method==='GET')
            || (preg_match('#^/agenda/\d+/confirmacao-autorizada$#',$path) && $method==='POST')
            || ($path==='/financeiro' && $method==='GET')
            || (preg_match('#^/financeiro/recebiveis/\d+/pagar$#',$path) && $method==='POST')
            || (preg_match('#^/financeiro/recibos/REC-[A-Za-z0-9]+(/pdf)?$#',$path) && $method==='GET') // v54.36: recepção imprime o recibo da baixa
            || str_starts_with($path,'/ajuda')
        );
    }

    public static function deny(PDO $db): never
    {
        try { (new AuditLogger($db))->log('SECURITY_AUTHORIZATION_BLOCKED','authorization',null,['reason'=>'role_not_permitted']); }
        catch (\Throwable $e) { error_log('SECURITY_AUTHORIZATION_BLOCKED audit persistence unavailable'); }
        throw new TenantSecurityException('Acesso não autorizado para este perfil.',403);
    }
}
