<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Security\RoleModel;

/**
 * Política de senha definida pelo próprio usuário (redefinição e primeiro acesso).
 *
 * Usuários clínicos mantêm a regra existente (mínimo de 8 caracteres). Quem acessa o console da
 * plataforma (superadmin, operação, suporte, financeiro, auditoria) precisa de uma senha mais forte,
 * porque a conta enxerga dados de várias clínicas.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH_DEFAULT = 8;
    public const MIN_LENGTH_STAFF = 12;

    public static function isStaffRole(string $role): bool
    {
        return RoleModel::isPlatformConsole($role);
    }

    public static function minLengthForRole(string $role): int
    {
        return self::isStaffRole($role) ? self::MIN_LENGTH_STAFF : self::MIN_LENGTH_DEFAULT;
    }

    /** Mensagem de erro em pt-BR, ou null se a senha serve. */
    public static function violation(string $password, string $role, string $email = ''): ?string
    {
        $min = self::minLengthForRole($role);
        if (mb_strlen($password) < $min) {
            return 'A nova senha deve possuir pelo menos ' . $min . ' caracteres.';
        }
        if (!self::isStaffRole($role)) {
            return null;
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/[\p{N}\p{P}\p{S}]/u', $password)) {
            return 'Use letras e também números ou símbolos na senha.';
        }
        $local = strtolower((string)strstr(strtolower(trim($email)), '@', true));
        if (strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
            return 'A senha não pode conter o seu e-mail.';
        }
        if (preg_match('/^(.)\1+$/u', $password) === 1) {
            return 'Escolha uma senha menos previsível.';
        }
        return null;
    }
}
