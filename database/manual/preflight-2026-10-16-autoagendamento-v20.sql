-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (v20 — Autoagendamento NI2-433 / NI2-434)
-- Execute este script no phpMyAdmin / MySQL CLI ANTES de rodar a migração.
-- Ele NÃO altera dados (zero DDL, zero UPDATE/DELETE/INSERT).
-- Verifica presença de tabelas base, integridade de storage engine (InnoDB),
-- primary keys e integridade para criação de booking_holds e booking_settings.
-- ============================================================================
SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 1048576; -- MySQL 5.7 corta GROUP_CONCAT em 1024 caracteres por padrão
SET @db = DATABASE();

-- 1. Presença de tabelas base obrigatórias
SET @has_clinics = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics');
SET @has_professionals = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals');
SET @has_patients = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients');
SET @has_appointments = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments');

-- 2. Presença das tabelas de autoagendamento (v20)
SET @has_holds = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds');
SET @has_settings = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings');

-- 3. Verificação de Storage Engine (InnoDB obrigatório para FKs com RESTRICT)
SET @bad_engines = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('clinics', 'professionals', 'patients', 'appointments')
    AND UPPER(ENGINE) <> 'INNODB');

-- 4. Verificação de Primary Keys nas tabelas pai
SET @has_pk_clinics = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');
SET @has_pk_profs = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');
SET @has_pk_appts = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');

-- 5. Tabelas criadas pela versão antiga deste pacote (colunas erradas)?
SET @old_settings = @has_settings = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME IN ('max_future_days', 'require_cpf')) < 2;
SET @old_holds = @has_holds = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds' AND COLUMN_NAME IN ('payment_status', 'notification_status')) < 2;
SET @tipos_id = (SELECT GROUP_CONCAT(DISTINCT COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics', 'professionals', 'patients', 'appointments') AND COLUMN_NAME = 'id');

-- 6. Relatório Preflight
SELECT
  CASE
    WHEN @old_settings OR @old_holds
    THEN 'PREFLIGHT_FAIL: tabelas de autoagendamento existem com a estrutura antiga; não rode a migração e fale com o suporte técnico'
    WHEN @has_clinics = 1 AND @has_professionals = 1 AND @has_appointments = 1 AND @bad_engines = 0 AND @has_pk_clinics = 1
    THEN 'PREFLIGHT_OK: Banco pronto para receber migracao-2026-10-16-autoagendamento-v20.sql'
    ELSE 'PREFLIGHT_FAIL: Pré-requisitos não atendidos'
  END AS status,
  @has_clinics AS has_clinics,
  @has_professionals AS has_professionals,
  @has_patients AS has_patients,
  @has_appointments AS has_appointments,
  @has_holds AS booking_holds_already_exists,
  @has_settings AS booking_settings_already_exists,
  @bad_engines AS bad_engines_count,
  (@old_settings OR @old_holds) AS estrutura_antiga,
  @tipos_id AS tipo_das_chaves;
