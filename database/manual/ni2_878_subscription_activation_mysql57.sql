-- NI2-878: Pedidos de ativacao manual de assinatura quando o gateway Asaas nao esta configurado.
-- Compativel com MySQL 5.7+ e idempotente. Migração 97 (2026_11_16_000097_ni2_878_subscription_activation).
-- v54.76 (CR875-15): agora também registra a migração 97, como os demais scripts manuais; sem o registro,
-- o executor de migrações a considerava pendente. Selecione o banco do sistema no phpMyAdmin antes de executar.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration)
SELECT '2026_11_16_000097_ni2_878_subscription_activation' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_16_000097_ni2_878_subscription_activation');

-- Conferência: 1 tabela e a migração registrada 1 vez
SET @t_sar := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_activation_requests');
SELECT @t_sar AS tabela_subscription_activation_requests,
       (SELECT COUNT(*) FROM migrations WHERE migration = '2026_11_16_000097_ni2_878_subscription_activation') AS migracao_97_registrada;
