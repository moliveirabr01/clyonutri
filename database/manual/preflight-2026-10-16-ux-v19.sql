-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura Avançado (v19 — V16-01, V16-02, V17-01, V19-P01..P13)
-- Execute este script no phpMyAdmin (aba SQL) ANTES de rodar a migração.
-- Ele NÃO faz nenhuma alteração no banco (zero DDL, zero INSERT/UPDATE/DELETE).
-- Verifica pré-condições estritas de tabelas base, engines (InnoDB), primary keys,
-- compatibilidade exata de tipos/signedness, duplicidades, rascunhos órfãos,
-- contagem inicial de dados e integridade de FKs (ON DELETE RESTRICT).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();

-- 1. Presença de tabelas base obrigatórias
SET @has_clinics = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics');
SET @has_appointments = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments');
SET @has_users = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users');
SET @has_table = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions');
SET @has_terms = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consent_terms');
SET @has_40 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts');

-- 2. Verificação de Storage Engine (InnoDB obrigatório para FKs no MySQL 5.7)
SET @bad_engines = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('clinics', 'appointments', 'users', 'teleconsultation_sessions', 'consent_terms', 'consultation_note_drafts')
    AND UPPER(ENGINE) <> 'INNODB');

-- 3. Verificação de Primary Keys e tipos exatos nas tabelas pai
SET @has_pk_clinics = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');
SET @has_pk_appts = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');
SET @has_pk_users = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');

SET @t_clinic_id = (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id' LIMIT 1);
SET @t_appt_id = (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id' LIMIT 1);
SET @t_user_id = (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id' LIMIT 1);

-- 4. Duplicidades em teleconsultation_sessions
SET @has_uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'appointment_id' AND NON_UNIQUE = 0);
SET @dups = 0;
SET @q = IF(@has_table = 1 AND @has_uq = 0, CONCAT('SELECT COUNT(*) FROM (SELECT appointment_id FROM `', @db, '`.`teleconsultation_sessions` GROUP BY appointment_id HAVING COUNT(*) > 1) d INTO @dups'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Se consultation_note_drafts já existe (v14/v15/v16): estado de integridade, órfãos e tipos
SET @orphans_40_clinic = 0;
SET @orphans_40_appt = 0;
SET @q = IF(@has_40 = 1 AND @has_clinics = 1, 'SELECT COUNT(*) FROM `consultation_note_drafts` d LEFT JOIN `clinics` c ON c.`id` = d.`clinic_id` WHERE c.`id` IS NULL INTO @orphans_40_clinic', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@has_40 = 1 AND @has_appointments = 1, 'SELECT COUNT(*) FROM `consultation_note_drafts` d LEFT JOIN `appointments` a ON a.`id` = d.`appointment_id` WHERE a.`id` IS NULL INTO @orphans_40_appt', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @orphans_40 = @orphans_40_clinic + @orphans_40_appt;

SET @c40_clinic_type = IF(@has_40 = 1, (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND COLUMN_NAME = 'clinic_id' LIMIT 1), NULL);
SET @c40_appt_type = IF(@has_40 = 1, (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND COLUMN_NAME = 'appointment_id' LIMIT 1), NULL);
SET @types_match_40 = IF(@has_40 = 1 AND @c40_clinic_type = @t_clinic_id AND @c40_appt_type = @t_appt_id, 1, IF(@has_40 = 0, 1, 0));

SET @fks_40_restrict = (SELECT COUNT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts'
    AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE = 'RESTRICT');

SET @fks_40_non_restrict = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts'
    AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE <> 'RESTRICT');

SET @drafts_count_before = IF(@has_40 = 1, (SELECT COUNT(*) FROM `consultation_note_drafts`), 0);

SET @init_state_40 = CASE
  WHEN @has_40 = 0 THEN 'CLEAN_INSTALL_039'
  WHEN @fks_40_restrict = 2 AND @fks_40_non_restrict = 0 THEN 'ALREADY_RESTRICT_V17'
  WHEN @fks_40_non_restrict > 0 THEN 'LEGACY_CASCADE_V15'
  ELSE 'PARTIAL_MISSING_FK'
END;

-- Gate global Fail-Fast: avalia todos os critérios de aptidão antes de qualquer DDL
SET @preflight_ok = (@has_clinics = 1 AND @has_appointments = 1 AND @has_users = 1 AND @has_table = 1 AND @has_terms = 1
  AND @bad_engines = 0 AND @has_pk_clinics = 1 AND @has_pk_appts = 1 AND @has_pk_users = 1
  AND (@has_uq > 0 OR @dups = 0) AND @orphans_40 = 0);

SELECT CASE
  WHEN @has_clinics = 0 OR @has_appointments = 0 OR @has_users = 0 THEN 'BLOQUEADO: tabelas base (clinics/appointments/users) ausentes.'
  WHEN @has_table = 0 THEN 'BLOQUEADO: tabela teleconsultation_sessions não existe.'
  WHEN @has_terms = 0 THEN 'BLOQUEADO: tabela consent_terms não existe.'
  WHEN @bad_engines > 0 THEN 'BLOQUEADO: tabela não-InnoDB detectada (InnoDB obrigatório para FKs no MySQL 5.7).'
  WHEN @has_pk_clinics = 0 OR @has_pk_appts = 0 OR @has_pk_users = 0 THEN 'BLOQUEADO: chave primária ausente em clinics, appointments ou users.'
  WHEN @has_uq = 0 AND @dups > 0 THEN CONCAT('BLOQUEADO: ', @dups, ' agendamento(s) com sessão de teleconsulta duplicada.')
  WHEN @orphans_40 > 0 THEN CONCAT('BLOQUEADO: ', @orphans_40, ' rascunho(s) órfão(s) em consultation_note_drafts (clínica=', @orphans_40_clinic, ', consulta=', @orphans_40_appt, '). Inspecione e faça quarentena manual antes de migrar.')
  ELSE 'OK — PRONTO PARA MIGRAR'
END AS preflight_status,
@preflight_ok AS pronto_para_migrar,
@init_state_40 AS estado_inicial_040,
@drafts_count_before AS rascunhos_existentes_preservar,
@has_40 AS tabela_40_ja_existe,
@types_match_40 AS tipos_colunas_40_compativeis,
@orphans_40 AS rascunhos_orfaos_40,
@fks_40_restrict AS fks_40_com_on_delete_restrict,
@fks_40_non_restrict AS fks_40_com_outra_regra,
@dups AS sessoes_teleconsulta_duplicadas;
