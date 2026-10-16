<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Settings;

use ClyoNutri\Core\Database\Connection;
use PDO;

/**
 * Gestor central de configurações globais e feature flags da plataforma SaaS (NI2-407 / NI2-411).
 * Faz com que as configurações persistidas em platform_settings afetem o runtime real em produção.
 */
class PlatformSettings
{
    private static ?array $cachedSettings = null;

    /**
     * Limpa o cache em memória (útil após mutações ou em testes).
     */
    public static function clearCache(): void
    {
        self::$cachedSettings = null;
    }

    /**
     * Carrega todas as configurações persistidas da tabela platform_settings.
     */
    public static function all(?PDO $db = null): array
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        $defaults = [
            'app_name' => 'ClyoNutri II',
            'maintenance_mode' => '0',
            'support_sla_p1_hours' => '4',
            'support_sla_p2_hours' => '24',
            'session_timeout_minutes' => '120',
            'security_max_login_attempts' => '5',
            'feature_flag_telehealth' => '0', // NI2-221 F12: sem a chave no banco, teleconsulta fica DESLIGADA
            'feature_flag_chat' => '1',
            'feature_flag_audit_immutable' => '1',
        ];

        try {
            $pdo = $db ?? Connection::getInstance();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM platform_settings");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            self::$cachedSettings = array_merge($defaults, $rows ?: []);
        } catch (\Throwable $e) {
            self::$cachedSettings = $defaults;
        }

        return self::$cachedSettings;
    }

    /**
     * Obtém o valor de uma configuração específica.
     */
    public static function get(string $key, mixed $default = null, ?PDO $db = null): mixed
    {
        $settings = self::all($db);
        return $settings[$key] ?? $default;
    }

    /**
     * Retorna se a plataforma está operando em modo de manutenção.
     */
    public static function isMaintenanceMode(?PDO $db = null): bool
    {
        return (string)self::get('maintenance_mode', '0', $db) === '1';
    }

    /**
     * Retorna o tempo limite de inatividade da sessão de usuários em minutos.
     */
    public static function getSessionTimeoutMinutes(?PDO $db = null): int
    {
        $val = (int)self::get('session_timeout_minutes', 120, $db);
        return ($val >= 15 && $val <= 1440) ? $val : 120;
    }

    /**
     * Retorna a quantidade de horas do SLA P1 (chamados críticos).
     */
    public static function getSupportSlaP1Hours(?PDO $db = null): int
    {
        $val = (int)self::get('support_sla_p1_hours', 4, $db);
        return ($val >= 1 && $val <= 48) ? $val : 4;
    }

    /**
     * Retorna a quantidade de horas do SLA P2 (chamados padrão).
     */
    public static function getSupportSlaP2Hours(?PDO $db = null): int
    {
        $val = (int)self::get('support_sla_p2_hours', 24, $db);
        return ($val >= 2 && $val <= 168) ? $val : 24;
    }

    /**
     * Retorna o número máximo de tentativas de login/OTP antes de bloqueio por rate limit.
     */
    public static function getMaxLoginAttempts(?PDO $db = null): int
    {
        $val = (int)self::get('security_max_login_attempts', 5, $db);
        return ($val >= 1 && $val <= 20) ? $val : 5;
    }

    /**
     * Retorna se o módulo de Teleconsulta está habilitado na plataforma.
     */
    public static function isTelehealthEnabled(?PDO $db = null): bool
    {
        // NI2-221 F12: fail-closed — só liga com a chave gravada explicitamente como '1'.
        return (string)self::get('feature_flag_telehealth', '0', $db) === '1';
    }

    /**
     * Retorna se o canal de mensagens e chat está habilitado na plataforma.
     */
    public static function isChatEnabled(?PDO $db = null): bool
    {
        return (string)self::get('feature_flag_chat', '1', $db) === '1';
    }

    /**
     * Retorna se a auditoria imutável WAL está forçada.
     */
    public static function isAuditImmutable(?PDO $db = null): bool
    {
        return (string)self::get('feature_flag_audit_immutable', '1', $db) === '1';
    }
}
