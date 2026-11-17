<?php

declare(strict_types=1);

/**
 * Migração 97 (NI2-878):
 *  - Tabela subscription_activation_requests para pedidos de ativação manual
 *    quando o gateway Asaas não está configurado.
 */
final class Ni2SubscriptionActivation
{
    public function up(PDO $db): void
    {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS subscription_activation_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    clinic_id INTEGER NOT NULL,
                    plan_id INTEGER NOT NULL,
                    plan_code TEXT NOT NULL,
                    requested_by_user_id INTEGER NOT NULL,
                    notes TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    processed_by_user_id INTEGER NULL,
                    processed_at TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $db->exec('CREATE INDEX IF NOT EXISTS idx_sar_clinic_created ON subscription_activation_requests (clinic_id, created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_sar_status ON subscription_activation_requests (status, created_at)');
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS subscription_activation_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                clinic_id BIGINT UNSIGNED NOT NULL,
                plan_id BIGINT UNSIGNED NOT NULL,
                plan_code VARCHAR(40) NOT NULL,
                requested_by_user_id BIGINT UNSIGNED NOT NULL,
                notes VARCHAR(500) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                processed_by_user_id BIGINT UNSIGNED NULL,
                processed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sar_clinic_created (clinic_id, created_at),
                INDEX idx_sar_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
