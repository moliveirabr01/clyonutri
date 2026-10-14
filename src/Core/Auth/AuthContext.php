<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Database\Connection;
use PDO;

final class AuthContext
{
    private static ?AuthUser $currentUser = null;

    public static function set(AuthUser $user): void
    {
        self::$currentUser = $user;
        TenantContext::set($user->getClinicId(), $user->toArray());
    }

    public static function get(): ?AuthUser
    {
        return self::$currentUser;
    }

    public static function clear(): void
    {
        self::$currentUser = null;
        TenantContext::clear();
    }

    /**
     * Resolve o contexto de autenticação a partir da sessão corrente,
     * validando canonicamente contra o banco de dados. Nunca confia em claims de cliente.
     */
    public static function resolveFromSession(?PDO $db = null): ?AuthUser
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
        $sessionAuthVersion = (int)($_SESSION['auth_version'] ?? 0);

        if ($userId <= 0 || $clinicId <= 0) {
            self::clear();
            return null;
        }

        $db = $db ?? Connection::getInstance();

        $stmt = $db->prepare("
            SELECT u.id, u.uuid, u.name, u.email, u.auth_version, u.role AS user_role, u.status AS user_status,
                   cm.clinic_id, cm.role AS member_role, cm.status AS member_status,
                   c.name AS clinic_name, c.status AS clinic_status
            FROM users u
            JOIN clinic_members cm ON cm.user_id = u.id AND cm.clinic_id = :clinic_id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE u.id = :user_id AND u.status = 'active' AND cm.status = 'active' AND c.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'clinic_id' => $clinicId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se o usuário/vínculo não existe, foi desativado ou auth_version divergiu (revogação): fail-closed
        if (!$row || (int)$row['auth_version'] !== $sessionAuthVersion) {
            $_SESSION = [];
            self::clear();
            return null;
        }

        // Atualiza claims de sessão a partir do banco canônico
        $_SESSION['user_role'] = $row['user_role'];
        $_SESSION['user_name'] = $row['name'];
        $_SESSION['user_email'] = $row['email'];
        $_SESSION['clinic_name'] = $row['clinic_name'];

        $authUser = new AuthUser(
            id: (int)$row['id'],
            uuid: (string)$row['uuid'],
            name: (string)$row['name'],
            email: (string)$row['email'],
            userRole: (string)$row['user_role'],
            authVersion: (int)$row['auth_version'],
            clinicId: (int)$row['clinic_id'],
            memberRole: (string)$row['member_role'],
            clinicName: (string)$row['clinic_name']
        );

        self::set($authUser);

        return $authUser;
    }
}
