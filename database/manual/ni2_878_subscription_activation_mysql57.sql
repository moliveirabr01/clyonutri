-- NI2-878: Pedidos de ativacao manual de assinatura quando o gateway Asaas nao esta configurado.
-- Compativel com MySQL 5.7+ e idempotente.

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
