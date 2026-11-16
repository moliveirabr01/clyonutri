-- ==============================================================================
-- ClyoNutri v54.63 · ATUALIZAÇÃO COMPLETA DO BANCO (migrations 091 a 095)
-- ==============================================================================
SET NAMES utf8mb4;
-- Para produção na v54.60. Rode DEPOIS do backup e do preflight, no phpMyAdmin (aba SQL), de uma vez.
-- Idempotente: pode rodar de novo sem duplicar nada. Compatível com MySQL 5.7.44.
-- Sem ADD COLUMN IF NOT EXISTS, sem JSON, sem CTE, sem função de janela; índices guardados por information_schema.
-- Seções 091, 092 e 093 são os mesmos scripts manuais já revisados das versões v54.52, v54.60 e v54.61.
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- 089 · Asaas (checkout, clientes, webhooks, recibos). No banco de produção de 15/11/2026 as tabelas já existem,
-- mas a migration não estava registrada. Registra só se as 4 tabelas existirem; não cria nada.
-- ------------------------------------------------------------------------------
INSERT IGNORE INTO migrations (migration, executed_at)
SELECT '2026_11_05_000089_asaas_billing_checkout', NOW() FROM DUAL
WHERE (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('asaas_customers', 'asaas_checkouts', 'asaas_webhook_events', 'asaas_payment_receipts')) = 4;

-- ------------------------------------------------------------------------------
-- 091 · production_events / production_alerts (v54.52, NI2-767)
-- ------------------------------------------------------------------------------
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

-- ------------------------------------------------------------------------------
-- 092 · transcrição em tempo real (v54.60, NI2-820)
-- ------------------------------------------------------------------------------
-- ==============================================================================
-- ATUALIZAÇÃO v54.60 — NI2-820: TRANSCRIÇÃO EM TEMPO REAL E MINUTA NUTRICIONAL
-- ==============================================================================
-- Compatível com MySQL 5.7+ e MySQL 8.0+ (HostGator).
-- Executar no banco de produção/homologação.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS consultation_ai_consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'granted',
    terms_version VARCHAR(50) NOT NULL DEFAULT 'v1.0',
    consented_at_utc DATETIME NOT NULL,
    revoked_at_utc DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_consent_clinic_appt (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_transcription_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_uuid VARCHAR(64) NOT NULL UNIQUE,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    professional_id INT NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'live',
    status VARCHAR(30) NOT NULL DEFAULT 'connected',
    started_at_utc DATETIME NOT NULL,
    paused_at_utc DATETIME NULL,
    resumed_at_utc DATETIME NULL,
    completed_at_utc DATETIME NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_transcription_sess_clinic (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_transcription_segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    clinic_id INT NOT NULL,
    sequence_number INT NOT NULL,
    speaker VARCHAR(50) NOT NULL DEFAULT 'speaker_uncertain',
    text TEXT NOT NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 1,
    confidence DECIMAL(4,3) NULL,
    start_seconds DECIMAL(8,2) NULL,
    end_seconds DECIMAL(8,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transcription_seg_seq (session_id, sequence_number),
    KEY idx_transcription_seg_clinic (clinic_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_evolution_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    professional_id INT NOT NULL,
    chief_complaint TEXT NULL,
    nutritional_goals TEXT NULL,
    dietary_routine TEXT NULL,
    reported_anthropometry TEXT NULL,
    clinical_symptoms TEXT NULL,
    adherence_notes TEXT NULL,
    agreed_conduct TEXT NULL,
    next_appointment_notes TEXT NULL,
    full_evolution_text MEDIUMTEXT NOT NULL,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by INT NULL,
    approved_at_utc DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_evolution_drafts_clinic_appt (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_11_000092_create_realtime_transcription_tables', NOW());

-- ------------------------------------------------------------------------------
-- 093 · consentimento verificável do paciente (v54.61, NI2-830)
-- ------------------------------------------------------------------------------
-- ==============================================================================
-- ATUALIZAÇÃO v54.61 — NI2-830 (SEC-001): CONSENTIMENTO VERIFICÁVEL DO PACIENTE
-- ==============================================================================
-- Migration 93 (2026_11_13_000093_create_transcription_consent_requests).
-- Compatível com MySQL 5.7+ e 8.0 (HostGator). Idempotente: pode rodar mais de uma vez.
-- Sem JSON, sem CTE, sem função de janela. Índices criados junto da tabela (inline).
--
-- PRÉ-REQUISITO: as tabelas da migration 92 (v54.60 / NI2-820) já devem existir:
--   consultation_ai_consents, consultation_transcription_sessions,
--   consultation_transcription_segments, consultation_evolution_drafts.
-- Se faltarem, rode antes database/manual/atualizacao-v54-60-ni2-820-realtime-transcription.sql.
-- (Atenção: a migration 091 — production_events/production_alerts — NÃO está no banco de produção;
--  ela não é exigida por esta atualização, mas segue pendente: database/manual/atualizacao-v54-52-ni2-767-*.sql.)
--
-- A tabela consultation_ai_consents (migration 92) deixa de ser fonte de autorização.
-- Linhas antigas "granted" criadas só pelo profissional NÃO valem como aceite do paciente.
-- Nenhuma linha antiga é alterada ou apagada.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS consultation_ai_consent_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    requested_by_user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    revoke_token_hash CHAR(64) NULL,
    terms_version VARCHAR(50) NOT NULL,
    terms_sha256 CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    request_origin VARCHAR(40) NOT NULL DEFAULT 'professional_session',
    expires_at_utc DATETIME NOT NULL,
    decided_at_utc DATETIME NULL,
    decision_mechanism VARCHAR(40) NULL,
    decision_ip_hash CHAR(64) NULL,
    decision_user_agent VARCHAR(120) NULL,
    valid_until_utc DATETIME NULL,
    revoked_at_utc DATETIME NULL,
    revoked_by VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_consent_req_token (token_hash),
    UNIQUE KEY uq_ai_consent_req_revoke_token (revoke_token_hash),
    KEY idx_ai_consent_req_appt (clinic_id, appointment_id, patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificação (precisa devolver 3 linhas: os 3 índices). Se devolver menos, a tabela
-- já existia sem os índices: NÃO ignore; crie o que faltar com ALTER TABLE ... ADD ... e confira de novo.
SELECT DISTINCT INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'consultation_ai_consent_requests'
  AND INDEX_NAME IN ('uq_ai_consent_req_token', 'uq_ai_consent_req_revoke_token', 'idx_ai_consent_req_appt');

-- Registro da migration (para o Migrator não tentar rodar de novo; mesmo padrão do SQL manual da v54.60).
INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_13_000093_create_transcription_consent_requests', NOW());

-- ------------------------------------------------------------------------------
-- 094 · medidas caseiras estimadas para alimentos frequentes (v54.62 NI2-845, regras da v54.63)
-- Cada linha entra marcada como "Estimativa ClyoNutri (revisar)". INSERT IGNORE: medida que o alimento já tem
-- (mesmo nome de medida) fica como está. Alimentos de clínica (clinic_id preenchido) não são tocados.
-- Grãos, leguminosas e massas crus não recebem medida de alimento pronto.
-- ------------------------------------------------------------------------------
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 200.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abacate, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abacaxi, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abacaxi, polpa, congelada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abobrinha, italiana, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abobrinha, italiana, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abobrinha, italiana, refogada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abobrinha, paulista, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abóbora, cabotian, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abóbora, cabotian, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abóbora, menina brasileira, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abóbora, moranga, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abóbora, moranga, refogada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Abóbora, pescoço, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Acelga, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Acerola, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Acerola, polpa, congelada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Agrião, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Alface americana crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Alface, crespa, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Alface, lisa, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Alface, roxa, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Almeirão, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Almeirão, refogado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ameixa, calda, enlatada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ameixa, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ameixa, em calda, enlatada, drenada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Amendoim, grão, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Amendoim, torrado, salgado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 10.00 AS g UNION ALL SELECT 'fatia' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Amêndoa, torrada, salgada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz Branco Cozido TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz Integral Cateto Cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz Integral Cozido TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz branco cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz carreteiro';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz integral cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Arroz, tipo 2, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 20.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'unidade' AS m, 40.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Atum, conserva em óleo';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 20.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'unidade' AS m, 40.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Atum, fresco, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Aveia em flocos';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Azeite de oliva extravirgem';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Azeite, de dendê';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Bacalhau, salgado, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Bacalhau, salgado, refogado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Baião de dois, arroz e feijão-de-corda';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana prata crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, da terra, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, doce em barra';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, figo, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, maçã, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, nanica, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, ouro, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Banana, pacova, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata Doce Cozida TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, baroa, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, baroa, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, doce, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, doce, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, frita, tipo chips, industrializada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, inglesa, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, inglesa, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, inglesa, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Batata, inglesa, sauté';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Bebida láctea, pêssego';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Berinjela, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Berinjela, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Beterraba, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Beterraba, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Bife à cavalo, com contra filé';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, doce, maisena';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, doce, recheado com chocolate';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, doce, recheado com morango';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, doce, wafer, recheado de chocolate';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, doce, wafer, recheado de morango';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, polvilho doce';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 7.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Biscoito, salgado, cream cracker';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Bolinho de arroz';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Brócolis, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Brócolis, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Café, infusão 10%';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Café, pó, torrado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Caju, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Caju, polpa, congelada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Caju, suco concentrado, envasado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cajá-Manga, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Caldo de carne, tablete';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Canjica, com leite integral';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Caqui, chocolate, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, acém, moído, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, acém, moído, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, acém, sem gordura, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, acém, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, almôndegas, cruas';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, almôndegas, fritas';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, bucho, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, bucho, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, capa de contra-filé, com gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, capa de contra-filé, com gordura, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, capa de contra-filé, sem gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, capa de contra-filé, sem gordura, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, charque, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, charque, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé de costela, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé de costela, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé, com gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé, com gordura, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé, sem gordura, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, contra-filé, à milanesa';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, costela, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, costela, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, coxão duro, sem gordura, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, coxão duro, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, coxão mole, sem gordura, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, coxão mole, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, cupim, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, cupim, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, filé mingnon, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, filé mingnon, sem gordura, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, flanco, sem gordura, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, flanco, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, fraldinha, com gordura, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, fraldinha, com gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, fígado, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, fígado, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, lagarto, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, lagarto, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, língua, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, língua, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, maminha, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, maminha, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, miolo de alcatra, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, miolo de alcatra, sem gordura, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, músculo, sem gordura, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, músculo, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, paleta, com gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, paleta, sem gordura, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, paleta, sem gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, patinho, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, patinho, sem gordura, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, peito, sem gordura, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, peito, sem gordura, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, picanha, com gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, picanha, com gordura, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, picanha, sem gordura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, picanha, sem gordura, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, seca, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Carne, bovina, seca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cará, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cará, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 10.00 AS g UNION ALL SELECT 'fatia' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Castanha-de-caju, torrada, salgada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Castanha-do-Pará';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cação, posta, com farinha de trigo, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cação, posta, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cação, posta, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cebola, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cenoura, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cenoura, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Cereais, mistura para vitamina, trigo, cevada e aveia';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Charuto, de repolho';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chicória, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chocolate, ao leite';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chocolate, ao leite, com castanha do Pará';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chocolate, ao leite, dietético';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chuchu, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chuchu, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chá, erva-doce, infusão 5%';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chá, mate, infusão 5%';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Chá, preto, infusão 5%';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Couve, manteiga, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Couve, manteiga, refogada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Couve-flor, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Couve-flor, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Coxinha de frango, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Creme de Leite';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Creme de arroz, pó';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Croquete, de carne, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Croquete, de carne, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Doce, de abóbora, cremoso';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Doce, de leite, cremoso';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Empada de frango, pré-cozida, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Empada, de frango, pré-cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ervilha, em vagem';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ervilha, enlatada, drenada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Espinafre, Nova Zelândia, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Espinafre, Nova Zelândia, refogado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Estrogonofe de carne';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Estrogonofe de frango';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'escumadeira' AS m, 85.00 AS g UNION ALL SELECT 'concha' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de arroz, enriquecida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de centeio, integral';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de mandioca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 10.00 AS g UNION ALL SELECT 'fatia' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de mandioca, torrada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de mesocarpo de babaçu, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de milho, amarela';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de puba';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de rosca';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, de trigo';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Farinha, láctea, de cereais';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão Carioca Cozido TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão carioca cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão tropeiro mineiro';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão, fradinho, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão, jalo, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão, preto, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão, rajado, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão, rosinha, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Feijão, roxo, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Filé de Peito de Frango Grelhado TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Filé de Salmão Grelhado TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, asa, com pele, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, caipira, inteiro, com pele, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, caipira, inteiro, sem pele, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, com açafrão';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, coração, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, coração, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, coxa, com pele, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, coxa, com pele, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, coxa, sem pele, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, coxa, sem pele, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, filé, à milanesa';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, fígado, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, inteiro, com pele, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, inteiro, sem pele, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, inteiro, sem pele, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, inteiro, sem pele, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, peito, com pele, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, peito, com pele, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, peito, sem pele, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, peito, sem pele, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, sobrecoxa, com pele, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, sobrecoxa, com pele, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, sobrecoxa, sem pele, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Frango, sobrecoxa, sem pele, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Fruta-pão, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Fécula, de mandioca';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'porção' AS m, 12.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Gergelim, semente';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Goiaba, branca, com casca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Goiaba, doce em pasta';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Goiaba, doce, cascão';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Goiaba, vermelha, com casca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Inhame, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 170.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Iogurte, natural';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 170.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Iogurte, natural, desnatado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 170.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Iogurte, sabor morango';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 170.00 AS g UNION ALL SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Iogurte, sabor pêssego';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Kiwi, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, baía, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, baía, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, da terra, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, da terra, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, lima, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, lima, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, pêra, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, pêra, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, valência, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Laranja, valência, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite Desnatado TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite desnatado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, condensado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, de cabra';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, de coco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, de vaca, achocolatado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, de vaca, desnatado, pó';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, de vaca, integral, pó';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'copo' AS m, 200.00 AS g UNION ALL SELECT 'xícara' AS m, 200.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Leite, fermentado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lentilha Cozida TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'concha' AS m, 86.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 86.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lentilha, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Limão, cravo, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Limão, galego, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Limão, tahiti, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lingüiça, frango, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lingüiça, frango, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lingüiça, frango, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lingüiça, porco, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lingüiça, porco, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Lingüiça, porco, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'porção' AS m, 12.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Linhaça, semente';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'pegador' AS m, 100.00 AS g UNION ALL SELECT 'escumadeira' AS m, 90.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Macarrão, instantâneo';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'pegador' AS m, 100.00 AS g UNION ALL SELECT 'escumadeira' AS m, 90.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Macarrão, molho bolognesa';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mamão verde, doce em calda, drenado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mamão, Formosa, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mamão, Papaia, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mamão, doce em calda, drenado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mandioca, cozida';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mandioca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mandioca, farofa, temperada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mandioca, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manga, Haden, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manga, Palmer, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manga, Tommy Atkins, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manga, polpa, congelada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manjuba, com farinha de trigo, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manteiga, com sal';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Manteiga, sem sal';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Maracujá, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Maracujá, polpa, congelada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Maracujá, suco concentrado, envasado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Margarina, com óleo hidrogenado, com sal (65% de lipídeos)';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Margarina, com óleo hidrogenado, sem sal (80% de lipídeos)';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Margarina, com óleo interesterificado, com sal (65%de lipídeos)';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Margarina, com óleo interesterificado, sem sal (65% de lipídeos)';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Maçã, Argentina, com casca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Maçã, Fuji, com casca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Melancia, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'unidade' AS m, 250.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Melão, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Merluza, filé, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Merluza, filé, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Merluza, filé, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mexerica, Murcote, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Mexerica, Rio, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Milho, fubá, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Morango, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 60.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Nhoque, batata, cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Noz, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Omelete, de queijo';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ovo de galinha cozido';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ovo, de codorna, inteiro, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ovo, de galinha, clara, cozida/10minutos';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ovo, de galinha, gema, cozida/10minutos';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ovo, de galinha, inteiro, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'porção' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Ovo, de galinha, inteiro, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pastel, de carne, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pastel, de carne, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pastel, de queijo, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pastel, de queijo, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Paçoca, amendoim';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Peito de Frango Grelhado TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Peito de frango grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pepino, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Peru, congelado, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Peru, congelado, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pescada, branca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pescada, branca, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pescada, filé, com farinha de trigo, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pescada, filé, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pescada, filé, frito';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pescada, filé, molho escabeche';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pimentão, amarelo, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pimentão, verde, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pimentão, vermelho, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pintado, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pintado, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pintado, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pipoca, com óleo de soja, sem sal';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, bisteca, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, bisteca, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, bisteca, grelhada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, costela, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, costela, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, lombo, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, lombo, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, orelha, salgada, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, pernil, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, pernil, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'bife' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Porco, rabo, salgado, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, aveia, forma';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 40.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, de queijo, assado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 40.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, de queijo, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, de soja';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, glúten, forma';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, milho, forma';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, trigo, forma, integral';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'fatia' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, trigo, francês';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 50.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pão, trigo, sovado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pé-de-moleque, amendoim';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pêra, Park, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pêra, Williams, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pêssego, Aurora, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Pêssego, enlatado, em calda';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, minas, frescal';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, minas, meia cura';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, mozarela';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, parmesão';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, pasteurizado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, petit suisse, morango';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, prato';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 30.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 15.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, requeijão, cremoso';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Queijo, ricota';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Quiabo, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Refrigerante, tipo laranja';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Refrigerante, tipo limão';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Repolho, branco, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Repolho, roxo, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Repolho, roxo, refogado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'porção' AS m, 50.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 40.00 AS g UNION ALL SELECT 'unidade' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Rúcula, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Salmão, filé, com pele, fresco, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Salmão, sem pele, fresco, cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Salmão, sem pele, fresco, grelhado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Salpicão, de frango';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 20.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'unidade' AS m, 40.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Sardinha, assada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 20.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'unidade' AS m, 40.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Sardinha, conserva em óleo';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 20.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'unidade' AS m, 40.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Sardinha, frita';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 20.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g UNION ALL SELECT 'unidade' AS m, 40.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Sardinha, inteira, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 15.00 AS g UNION ALL SELECT 'xícara' AS m, 90.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 10.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Soja, farinha';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'fatia' AS m, 30.00 AS g UNION ALL SELECT 'porção' AS m, 30.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'unidade' AS m, 30.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Soja, queijo (tofu)';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tangerina, Poncã, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'fatia' AS m, 40.00 AS g UNION ALL SELECT 'xícara' AS m, 140.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tangerina, Poncã, suco';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de chá' AS m, 5.00 AS g UNION ALL SELECT 'colher de sopa' AS m, 12.00 AS g UNION ALL SELECT 'porção' AS m, 10.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tapioca, com manteiga';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'filé' AS m, 120.00 AS g UNION ALL SELECT 'posta' AS m, 120.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tilápia Grelhada TACO';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tomate com semente cru';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tomate, extrato';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tomate, molho industrializado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tomate, purê';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Tomate, salada';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'unidade' AS m, 50.00 AS g UNION ALL SELECT 'fatia' AS m, 25.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Torrada, pão francês';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Uva, Itália, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Uva, Rubi, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'xícara' AS m, 120.00 AS g UNION ALL SELECT 'porção' AS m, 100.00 AS g UNION ALL SELECT 'unidade' AS m, 15.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Uva, suco concentrado, envasado';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 25.00 AS g UNION ALL SELECT 'fatia' AS m, 20.00 AS g UNION ALL SELECT 'unidade' AS m, 100.00 AS g UNION ALL SELECT 'xícara' AS m, 100.00 AS g UNION ALL SELECT 'porção' AS m, 80.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Vagem, crua';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Óleo, de babaçu';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Óleo, de canola';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Óleo, de girassol';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Óleo, de milho';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Óleo, de pequi';
INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)
  SELECT f.id, v.m, v.g, 'Estimativa ClyoNutri (revisar)', 'v54.63' FROM foods f JOIN (SELECT 'colher de sopa' AS m, 13.00 AS g UNION ALL SELECT 'colher de sobremesa' AS m, 8.00 AS g UNION ALL SELECT 'colher de chá' AS m, 4.00 AS g) v
  WHERE f.clinic_id IS NULL AND f.name = 'Óleo, de soja';
INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_15_000094_ni2_845_seed_common_household_measures', NOW());

-- ------------------------------------------------------------------------------
-- 095 · limpeza do catálogo (v54.63, reteste UX R-004)
-- Tira da busca a cópia interna do TACO 410, se existir (não apaga: planos podem usá-la).
-- Em banco que nunca rodou a v54.62 (preflight P6 = 0 e 0) não há mais nada a limpar.
-- ------------------------------------------------------------------------------
SET @taco410 := (SELECT id FROM foods WHERE clinic_id IS NULL AND taco_code = 410 LIMIT 1);
UPDATE foods SET is_current = 0
WHERE @taco410 IS NOT NULL AND clinic_id IS NULL AND taco_code IS NULL
  AND name = 'Frango, peito, sem pele, grelhado' AND COALESCE(is_current, 1) = 1;
INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_15_000095_reteste_ux_catalog_cleanup', NOW());
