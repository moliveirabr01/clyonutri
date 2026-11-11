-- =============================================================================
-- ClyoNutri II — ATUALIZAÇÃO v54.12: Migração 78 (NI2-635, motor de protocolos clínicos)
-- Pré-requisito: v54.1 em produção (atualizacao-v54-completa.sql já aplicada).
--
-- O QUE FAZ: cria 7 tabelas novas e vazias e a chave feature_flag_clinical_protocols = 0 (desligada).
-- O QUE NÃO FAZ: não altera nenhuma tabela existente e não grava nenhuma pergunta clínica.
--
-- COMO RODAR (phpMyAdmin da HostGator):
--   1. Selecione o banco maic6824_Clyonutri2 na coluna da esquerda.
--   2. Aba "SQL", cole este arquivo inteiro e execute.
-- Compatível com MySQL 5.7+, MySQL 8.0+ e MariaDB 10.3+. Pode rodar duas vezes: a segunda não altera nada.
-- Rollback: ver o fim do arquivo (só se as tabelas estiverem vazias).
-- =============================================================================

CREATE TABLE IF NOT EXISTS clinical_protocols (id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'condition',
  catalog_phase CHAR(1) NOT NULL DEFAULT 'A',
  requires_specialist_review INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinical_protocol_versions (id INT AUTO_INCREMENT PRIMARY KEY,
  protocol_id INT NOT NULL,
  version INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  definition_json LONGTEXT NOT NULL,
  content_sha256 CHAR(64) NOT NULL,
  references_text LONGTEXT NULL,
  authored_by_user_id INT NULL,
  submitted_at DATETIME NULL,
  approved_by_user_id INT NULL,
  approved_by_name VARCHAR(160) NULL,
  approved_by_crn VARCHAR(30) NULL,
  specialist_review_note LONGTEXT NULL,
  approved_at DATETIME NULL,
  review_due_at DATE NULL,
  retired_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE(protocol_id,
  version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinical_protocol_events (id INT AUTO_INCREMENT PRIMARY KEY,
  protocol_version_id INT NOT NULL,
  action VARCHAR(30) NOT NULL,
  actor_user_id INT NULL,
  note LONGTEXT NULL,
  created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_protocol_settings (id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  protocol_id INT NOT NULL,
  enabled INT NOT NULL DEFAULT 0,
  updated_by_user_id INT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE(clinic_id,
  protocol_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_conditions (id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  patient_id INT NOT NULL,
  protocol_code VARCHAR(60) NOT NULL,
  status VARCHAR(20) NOT NULL,
  provenance VARCHAR(20) NOT NULL,
  evidence_text VARCHAR(500) NULL,
  noted_at DATE NULL,
  valid_until DATE NULL,
  created_by_user_id INT NULL,
  confirmed_by_user_id INT NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_condition_events (id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  condition_id INT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  provenance VARCHAR(20) NULL,
  actor_user_id INT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protocol_responses (id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  patient_id INT NOT NULL,
  protocol_version_id INT NOT NULL,
  anamnesis_id INT NULL,
  answers_json LONGTEXT NOT NULL,
  provenance VARCHAR(20) NOT NULL,
  is_draft INT NOT NULL DEFAULT 1,
  answered_by_user_id INT NULL,
  created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinical_protocol_versions' AND INDEX_NAME = 'idx_cpv_status');
SET @c = IF(@idx = 0, 'CREATE INDEX `idx_cpv_status` ON `clinical_protocol_versions` (protocol_id,status,version)', 'SELECT ''índice idx_cpv_status já existe'' AS aviso');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinical_protocol_events' AND INDEX_NAME = 'idx_cpe_version');
SET @c = IF(@idx = 0, 'CREATE INDEX `idx_cpe_version` ON `clinical_protocol_events` (protocol_version_id,id)', 'SELECT ''índice idx_cpe_version já existe'' AS aviso');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_conditions' AND INDEX_NAME = 'idx_pc_patient');
SET @c = IF(@idx = 0, 'CREATE INDEX `idx_pc_patient` ON `patient_conditions` (clinic_id,patient_id,status)', 'SELECT ''índice idx_pc_patient já existe'' AS aviso');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_condition_events' AND INDEX_NAME = 'idx_pce_condition');
SET @c = IF(@idx = 0, 'CREATE INDEX `idx_pce_condition` ON `patient_condition_events` (clinic_id,condition_id,id)', 'SELECT ''índice idx_pce_condition já existe'' AS aviso');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'protocol_responses' AND INDEX_NAME = 'idx_pr_patient');
SET @c = IF(@idx = 0, 'CREATE INDEX `idx_pr_patient` ON `protocol_responses` (clinic_id,patient_id,protocol_version_id,is_draft,id)', 'SELECT ''índice idx_pr_patient já existe'' AS aviso');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO platform_settings (setting_key, setting_value, description)
SELECT 'feature_flag_clinical_protocols', '0', 'NI2-635: anamnese modular por protocolos clínicos aprovados (0 = desligado)'
WHERE NOT EXISTS (SELECT 1 FROM platform_settings WHERE setting_key = 'feature_flag_clinical_protocols');

INSERT INTO migrations (migration)
SELECT '2026_11_03_000078_create_clinical_protocols'
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_03_000078_create_clinical_protocols');

SELECT 'Migração 78 (NI2-635) concluída: 7 tabelas, chave desligada.' AS resultado, DATABASE() AS banco;

-- -----------------------------------------------------------------------------
-- ROLLBACK (não execute junto). Só remove se nada foi gravado:
--   SELECT (SELECT COUNT(*) FROM clinical_protocols) + (SELECT COUNT(*) FROM patient_conditions)
--        + (SELECT COUNT(*) FROM protocol_responses) AS linhas;   -- tem de dar 0
--   DROP TABLE protocol_responses, patient_condition_events, patient_conditions, clinic_protocol_settings,
--              clinical_protocol_events, clinical_protocol_versions, clinical_protocols;
--   DELETE FROM platform_settings WHERE setting_key = 'feature_flag_clinical_protocols';
--   DELETE FROM migrations WHERE migration = '2026_11_03_000078_create_clinical_protocols';
-- Com dados gravados, basta deixar a chave em 0: os módulos somem e nada é perdido.
-- -----------------------------------------------------------------------------
