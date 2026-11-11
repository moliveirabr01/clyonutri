-- ==============================================================================
-- CLYONUTRI II / CLYOPSI — SCRIPT SQL DE MIGRAÇÃO MANUAL (v54.52 / NI2-767)
-- ==============================================================================
-- Migração 091: Central de Eventos de Produção no Backoffice & Alertas Operacionais
-- Compatibilidade: MySQL 5.7+ / 8.0+ (HostGator / AWS RDS / Bare Metal)
-- Característica: Idempotente (CREATE TABLE IF NOT EXISTS + verificação de índices)
-- Sem dados clínicos ou sensíveis (Anti-PHI e conformidade LGPD).
-- ==============================================================================

-- 1. Tabela central de eventos de produção
CREATE TABLE IF NOT EXISTS `production_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(36) NOT NULL UNIQUE,
    `schema_version` VARCHAR(10) NOT NULL DEFAULT 'v1',
    `occurred_at` DATETIME NOT NULL,
    `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `environment` VARCHAR(20) NOT NULL DEFAULT 'production',
    `service` VARCHAR(50) NOT NULL DEFAULT 'clyo_core',
    `category` VARCHAR(30) NOT NULL,
    `event_type` VARCHAR(80) NOT NULL,
    `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
    `outcome` VARCHAR(20) NOT NULL DEFAULT 'success',
    `clinic_id` INT NULL,
    `actor_type` VARCHAR(30) NOT NULL DEFAULT 'system',
    `actor_id` VARCHAR(64) NULL,
    `resource_type` VARCHAR(50) NULL,
    `resource_ref` VARCHAR(64) NULL,
    `correlation_id` VARCHAR(64) NULL,
    `request_id` VARCHAR(64) NULL,
    `duration_ms` INT NULL,
    `retry_count` INT NOT NULL DEFAULT 0,
    `metadata` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Índices de alta performance para a Central de Eventos
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events' AND INDEX_NAME = 'idx_prod_events_occurred_cat');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_events_occurred_cat ON production_events (occurred_at, category)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events' AND INDEX_NAME = 'idx_prod_events_cat_sev');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_events_cat_sev ON production_events (category, severity)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events' AND INDEX_NAME = 'idx_prod_events_clinic_date');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_events_clinic_date ON production_events (clinic_id, occurred_at)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events' AND INDEX_NAME = 'idx_prod_events_correlation');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_events_correlation ON production_events (correlation_id)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events' AND INDEX_NAME = 'idx_prod_events_type');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_events_type ON production_events (event_type)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Tabela de alertas operacionais ativos e histórico de incidentes
CREATE TABLE IF NOT EXISTS `production_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `alert_id` VARCHAR(36) NOT NULL UNIQUE,
    `rule_name` VARCHAR(80) NOT NULL,
    `severity` VARCHAR(20) NOT NULL DEFAULT 'warning',
    `status` VARCHAR(20) NOT NULL DEFAULT 'firing',
    `description` TEXT NOT NULL,
    `context_json` TEXT NULL,
    `triggered_at` DATETIME NOT NULL,
    `resolved_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_alerts' AND INDEX_NAME = 'idx_prod_alerts_status');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_alerts_status ON production_alerts (status, severity)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_alerts' AND INDEX_NAME = 'idx_prod_alerts_rule');
SET @sql_idx := IF(@idx_exist = 0, 'CREATE INDEX idx_prod_alerts_rule ON production_alerts (rule_name, status)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Registro no histórico de migrações do framework
INSERT INTO `migrations` (`migration`) 
SELECT '2026_11_07_000091_create_production_events_table' 
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_11_07_000091_create_production_events_table'
);
