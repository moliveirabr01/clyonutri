-- ============================================================================
-- ClyoNutri — Migração Manual Cumulativa (v14–v18): teleconsulta (v11–v13) + UX (v14) + Auditoria 360° (v15–v18)
-- Rodar no phpMyAdmin com o banco do sistema selecionado (aba SQL ou Importar), DEPOIS do backup e do preflight.
-- Cumulativa: serve para quem está na v10 (nunca rodou a SQL da v11) e para quem já rodou a da v11, v12, v13, v14, v15, v16 ou v17.
-- Idempotente e Fail-Fast (V16-01): avalia todas as pré-condições ANTES de qualquer DDL/DML; se houver órfãos ou
-- inconsistências, aborta 100% das alterações (DO 0 em todas as etapas) e exibe o diagnóstico final.
-- Integridade Referencial Zero-Gap (V16-02, V17-01): consultation_note_drafts utiliza ON DELETE RESTRICT em clinic_id
-- e appointment_id com rotação segura Add-Before-Drop e autocura de estados parciais, impedindo exclusão em cascata.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t = CONCAT('`', @db, '`.`teleconsultation_sessions`');

-- ---------------------------------------------------------------------------- PREFLIGHT FAIL-FAST (V16-01 & V17-01: antes de qualquer DDL/DML)
SET @has_clinics = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics');
SET @has_appointments = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments');
SET @has_users = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users');
SET @has_table = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions');
SET @has_terms = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consent_terms');
SET @has_40 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts');

-- Verificação de Storage Engine (InnoDB obrigatório no MySQL 5.7 para FKs)
SET @bad_engines = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('clinics', 'appointments', 'users', 'teleconsultation_sessions', 'consent_terms', 'consultation_note_drafts')
    AND UPPER(ENGINE) <> 'INNODB');

-- Verificação de Primary Keys
SET @has_pk_clinics = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');
SET @has_pk_appts = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');
SET @has_pk_users = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id' AND COLUMN_KEY = 'PRI');

-- Duplicidades em teleconsultation_sessions
SET @has_uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'appointment_id' AND NON_UNIQUE = 0);
SET @dups = 0;
SET @q = IF(@has_table = 1 AND @has_uq = 0, CONCAT('SELECT COUNT(*) FROM (SELECT appointment_id FROM ', @t, ' GROUP BY appointment_id HAVING COUNT(*) > 1) d INTO @dups'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Órfãos em consultation_note_drafts
SET @orphans_40_clinic = 0;
SET @orphans_40_appt = 0;
SET @q = IF(@has_40 = 1 AND @has_clinics = 1, 'SELECT COUNT(*) FROM `consultation_note_drafts` d LEFT JOIN `clinics` c ON c.`id` = d.`clinic_id` WHERE c.`id` IS NULL INTO @orphans_40_clinic', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@has_40 = 1 AND @has_appointments = 1, 'SELECT COUNT(*) FROM `consultation_note_drafts` d LEFT JOIN `appointments` a ON a.`id` = d.`appointment_id` WHERE a.`id` IS NULL INTO @orphans_40_appt', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @orphans_40 = @orphans_40_clinic + @orphans_40_appt;

-- Gate global Fail-Fast: se @preflight_ok = 0, NENHUMA instrução DDL/DML abaixo é executada
SET @preflight_ok = (@has_clinics = 1 AND @has_appointments = 1 AND @has_users = 1 AND @has_table = 1 AND @has_terms = 1
  AND @bad_engines = 0 AND @has_pk_clinics = 1 AND @has_pk_appts = 1 AND @has_pk_users = 1
  AND (@has_uq > 0 OR @dups = 0) AND @orphans_40 = 0);

-- ---------------------------------------------------------------------------- 035
SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_generation');
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_issued_at');
SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_expires_at');
SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_revoked_at');
SET @c5 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'scheduled_at_snapshot');
SET @c6 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'external_room_name');
SET @c7 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'consent_term_id');

SET @parts = CONCAT_WS(', ',
  IF(@c1 = 0, 'ADD COLUMN `token_generation` INT NOT NULL DEFAULT 1', NULL),
  IF(@c2 = 0, 'ADD COLUMN `token_issued_at` DATETIME NULL', NULL),
  IF(@c3 = 0, 'ADD COLUMN `token_expires_at` DATETIME NULL', NULL),
  IF(@c4 = 0, 'ADD COLUMN `token_revoked_at` DATETIME NULL', NULL),
  IF(@c5 = 0, 'ADD COLUMN `scheduled_at_snapshot` DATETIME NULL', NULL),
  IF(@c6 = 0, 'ADD COLUMN `external_room_name` VARCHAR(150) NULL', NULL),
  IF(@c7 = 0, 'ADD COLUMN `consent_term_id` INT NULL', NULL)
);
SET @q = IF(@preflight_ok = 1 AND @has_table = 1 AND @parts <> '', CONCAT('ALTER TABLE ', @t, ' ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- UNIQUE(appointment_id): uma sessão por agendamento
SET @q = IF(@preflight_ok = 1 AND @has_table = 1 AND @has_uq = 0 AND @dups = 0, CONCAT('ALTER TABLE ', @t, ' ADD UNIQUE INDEX `uq_tele_sessions_appointment` (`appointment_id`)'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Termo de consentimento da teleconsulta
SET @q = IF(@preflight_ok = 1 AND @has_terms = 1, CONCAT('INSERT INTO `', @db, '`.`consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT ''2026.10-r1'', ''Consentimento para teleconsulta nutricional'', ''telehealth'', ',
  '''Concordo em realizar esta consulta de nutrição a distância, por vídeo, com o(a) profissional indicado(a) nesta página. Entendo que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não há exame físico) e que o(a) profissional pode recomendar uma consulta presencial. A sessão não é gravada. As anotações clínicas ficam no meu prontuário na clínica. Posso recusar este termo; nesse caso a teleconsulta não acontece e posso pedir à clínica um atendimento presencial.'', 1 ',
  'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`consent_terms` WHERE `purpose_code` = ''telehealth'' AND `version` = ''2026.10-r1'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (@preflight_ok = 1 AND @has_table = 1 AND @has_terms = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions'
       AND COLUMN_NAME IN ('token_generation','token_issued_at','token_expires_at','token_revoked_at','scheduled_at_snapshot','external_room_name','consent_term_id')) = 7);
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_15_000035_harden_teleconsultation_sessions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_15_000035_harden_teleconsultation_sessions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------------- 036
SET @x0 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'provider_status');
SET @x1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'join_opens_at_utc');
SET @x2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'join_closes_at_utc');
SET @x3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'invite_generation');
SET @x4 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'invite_issued_at_utc');
SET @x5 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'invite_revoked_at_utc');
SET @x6 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'media_ended_at_utc');
SET @parts = CONCAT_WS(', ',
  IF(@x0 = 0, 'ADD COLUMN `provider_status` VARCHAR(30) NOT NULL DEFAULT ''pending''', NULL),
  IF(@x1 = 0, 'ADD COLUMN `join_opens_at_utc` DATETIME NULL', NULL),
  IF(@x2 = 0, 'ADD COLUMN `join_closes_at_utc` DATETIME NULL', NULL),
  IF(@x3 = 0, 'ADD COLUMN `invite_generation` INT NOT NULL DEFAULT 1', NULL),
  IF(@x4 = 0, 'ADD COLUMN `invite_issued_at_utc` DATETIME NULL', NULL),
  IF(@x5 = 0, 'ADD COLUMN `invite_revoked_at_utc` DATETIME NULL', NULL),
  IF(@x6 = 0, 'ADD COLUMN `media_ended_at_utc` DATETIME NULL', NULL)
);
SET @q = IF(@preflight_ok = 1 AND @has_table = 1 AND @parts <> '', CONCAT('ALTER TABLE `', @db, '`.`teleconsultation_sessions` ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1, 'CREATE TABLE IF NOT EXISTS `teleconsultation_access_attempts` (`id` BIGINT NOT NULL AUTO_INCREMENT, `bucket` VARCHAR(40) NOT NULL, `subject_hash` VARCHAR(64) NOT NULL, `attempted_at_utc` DATETIME NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1, 'CREATE TABLE IF NOT EXISTS `teleconsultation_signals` (`id` BIGINT NOT NULL AUTO_INCREMENT, `clinic_id` BIGINT NOT NULL, `session_id` BIGINT NOT NULL, `room_name` VARCHAR(150) NOT NULL, `sender_role` VARCHAR(20) NOT NULL, `signal_type` VARCHAR(20) NOT NULL, `payload` MEDIUMTEXT NOT NULL, `created_at_utc` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `idx_tele_signals_session` (`session_id`), KEY `idx_tele_signals_clinic` (`clinic_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @hi = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_signals' AND INDEX_NAME = 'idx_tele_signals_room');
SET @q = IF(@preflight_ok = 1 AND @hi = 0, 'CREATE INDEX `idx_tele_signals_room` ON `teleconsultation_signals` (`room_name`, `id`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @hi = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_access_attempts' AND INDEX_NAME = 'idx_tele_attempts_lookup');
SET @q = IF(@preflight_ok = 1 AND @hi = 0, 'CREATE INDEX `idx_tele_attempts_lookup` ON `teleconsultation_access_attempts` (`bucket`, `subject_hash`, `attempted_at_utc`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Convites anteriores à v12 (sem janela/geração registradas) deixam de valer.
SET @q = IF(@preflight_ok = 1 AND @has_table = 1, CONCAT('UPDATE `', @db, '`.`teleconsultation_sessions` SET `invite_revoked_at_utc` = UTC_TIMESTAMP() ',
  'WHERE `invite_revoked_at_utc` IS NULL AND `invite_issued_at_utc` IS NULL AND `join_closes_at_utc` IS NULL ',
  'AND `status` NOT IN (''ended'',''rejected'',''cancelled'',''expired'',''completed'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- 037
SET @q = IF(@preflight_ok = 1, 'CREATE TABLE IF NOT EXISTS `teleconsultation_note_drafts` (`id` BIGINT NOT NULL AUTO_INCREMENT, `clinic_id` BIGINT NOT NULL, `session_id` BIGINT NOT NULL, `professional_id` BIGINT NOT NULL, `title` VARCHAR(255) NULL, `notes` MEDIUMTEXT NULL, `goals` VARCHAR(1000) NULL, `version` INT NOT NULL DEFAULT 1, `updated_at_utc` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uq_tele_drafts_session_prof` (`session_id`, `professional_id`), KEY `idx_tele_drafts_clinic` (`clinic_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- 038
SET @q = IF(@preflight_ok = 1 AND @has_table = 1 AND @has_terms = 1, CONCAT(
  'INSERT INTO `consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT v.ver, CONCAT(''Consentimento para teleconsulta (versão anterior '', v.ver, '')''), ''telehealth'', ',
  '''Concordo em ser atendido(a) por teleconsulta, ciente de que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não permite exame físico) e de que o(a) nutricionista pode indicar consulta presencial quando necessário.

A chamada não é gravada. Meus dados de saúde são tratados para a prestação do atendimento nutricional, conforme a LGPD, e as anotações ficam no meu prontuário. Posso interromper a teleconsulta a qualquer momento.'', 0 FROM (',
  '  SELECT DISTINCT LEFT(`consent_term_version`, 20) AS ver FROM `teleconsultation_sessions` ',
  '   WHERE `consent_term_version` IS NOT NULL AND `consent_term_version` <> '''' AND `consent_term_id` IS NULL ',
  '  UNION SELECT DISTINCT LEFT(SUBSTRING(`consent_type`, 12), 20) FROM `patient_consents` WHERE `consent_type` LIKE ''telehealth:%'' AND LENGTH(`consent_type`) > 11',
  ') v WHERE NOT EXISTS (SELECT 1 FROM `consent_terms` t WHERE t.`purpose_code` = ''telehealth'' AND t.`version` = v.ver)'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1 AND @has_table = 1 AND @has_terms = 1,
  'UPDATE `teleconsultation_sessions` s JOIN `consent_terms` t ON t.`purpose_code` = ''telehealth'' AND t.`version` = LEFT(s.`consent_term_version`, 20) SET s.`consent_term_id` = t.`id` WHERE s.`consent_term_id` IS NULL AND s.`consent_term_version` IS NOT NULL AND s.`consent_term_version` <> ''''',
  'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1 AND @has_terms = 1,
  'UPDATE `patient_consents` c JOIN `consent_terms` t ON t.`purpose_code` = ''telehealth'' AND t.`version` = LEFT(SUBSTRING(c.`consent_type`, 12), 20) SET c.`term_id` = t.`id`, c.`consent_type` = ''telehealth'' WHERE c.`consent_type` LIKE ''telehealth:%''',
  'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- 039 (v13)
SET @q = IF(@preflight_ok = 1, 'CREATE TABLE IF NOT EXISTS `teleconsultation_ticket_redemptions` (`ticket_hash` CHAR(64) NOT NULL PRIMARY KEY, `session_id` BIGINT NOT NULL, `clinic_id` BIGINT NOT NULL, `browser_key_hash` CHAR(64) NOT NULL, `redeemed_at_utc` DATETIME NOT NULL, `expires_at_utc` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @i39 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_ticket_redemptions' AND INDEX_NAME = 'idx_tele_ticket_red_expires');
SET @q = IF(@preflight_ok = 1 AND @i39 = 0, 'CREATE INDEX `idx_tele_ticket_red_expires` ON `teleconsultation_ticket_redemptions` (`expires_at_utc`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- 040 (v14–v18: schema equivalente à migração PHP + Zero-Gap ON DELETE RESTRICT — V16-02, V17-01)
SET @t_clinic_id = COALESCE((SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id' LIMIT 1), 'BIGINT');
SET @t_appt_id = COALESCE((SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id' LIMIT 1), 'BIGINT');
SET @t_user_id = COALESCE((SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id' LIMIT 1), 'BIGINT');
SET @t_pk_40 = IF(LOCATE('BIGINT', @t_clinic_id) > 0 OR LOCATE('BIGINT', @t_appt_id) > 0, 'BIGINT', 'INT');

-- Contagem de rascunhos antes de qualquer DDL para conferência estrita pós-migração
SET @drafts_count_before = IF(@has_40 = 1, (SELECT COUNT(*) FROM `consultation_note_drafts`), 0);

-- Cenário A: Tabela não existe (Clean Install / upgrade de 039) -> Criação atômica já com RESTRICT
SET @q = IF(@preflight_ok = 1 AND @has_40 = 0, CONCAT(
  'CREATE TABLE `consultation_note_drafts` (',
  '  `id` ', @t_pk_40, ' NOT NULL AUTO_INCREMENT,',
  '  `clinic_id` ', @t_clinic_id, ' NOT NULL,',
  '  `appointment_id` ', @t_appt_id, ' NOT NULL,',
  '  `user_id` ', @t_user_id, ' NOT NULL,',
  '  `evolution_summary` MEDIUMTEXT NULL,',
  '  `weight_kg` VARCHAR(20) NULL,',
  '  `waist_cm` VARCHAR(20) NULL,',
  '  `version` INT NOT NULL DEFAULT 1,',
  '  `updated_at_utc` DATETIME NOT NULL,',
  '  PRIMARY KEY (`id`),',
  '  UNIQUE KEY `uq_consult_drafts_appt_user` (`appointment_id`, `user_id`),',
  '  KEY `idx_consult_drafts_clinic` (`clinic_id`),',
  '  CONSTRAINT `fk_consult_drafts_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT,',
  '  CONSTRAINT `fk_consult_drafts_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Cenário B: Tabela já existe (v14 sem FKs, v15/v16 com CASCADE, ou estado parcial de FK).
-- Zero-Gap Protection (V17-01): Nunca deixa a tabela desprotegida durante rotação.
SET @c40_clinic_type = IF(@has_40 = 1, (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND COLUMN_NAME = 'clinic_id' LIMIT 1), NULL);
SET @c40_appt_type = IF(@has_40 = 1, (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND COLUMN_NAME = 'appointment_id' LIMIT 1), NULL);
SET @c40_user_type = IF(@has_40 = 1, (SELECT UPPER(COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND COLUMN_NAME = 'user_id' LIMIT 1), NULL);

SET @fk40_clinic_rule = (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND REFERENCED_TABLE_NAME = 'clinics' LIMIT 1);
SET @fk40_appt_rule = (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND REFERENCED_TABLE_NAME = 'appointments' LIMIT 1);
SET @fks_40_restrict = (SELECT COUNT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE = 'RESTRICT');
SET @fks_40_non_restrict = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE <> 'RESTRICT');

-- Passo B.1: Se não houver FKs ativas e os tipos divergirem, ajusta tipos primeiro (compatibilidade de FK):
SET @need_modify = IF(@has_40 = 1 AND (@fks_40_restrict + @fks_40_non_restrict = 0) AND (@c40_clinic_type <> @t_clinic_id OR @c40_appt_type <> @t_appt_id OR @c40_user_type <> @t_user_id), 1, 0);
SET @q = IF(@preflight_ok = 1 AND @need_modify = 1, CONCAT(
  'ALTER TABLE `consultation_note_drafts` ',
  'MODIFY COLUMN `clinic_id` ', @t_clinic_id, ' NOT NULL, ',
  'MODIFY COLUMN `appointment_id` ', @t_appt_id, ' NOT NULL, ',
  'MODIFY COLUMN `user_id` ', @t_user_id, ' NOT NULL'
), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Passo B.2: Se houver FK não-RESTRICT (ex: CASCADE da v15/v16), adiciona constraint RESTRICT de guarda ANTES de remover a antiga
SET @has_clinic_guard = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_clinic_v17');
SET @has_appt_guard = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_appt_v17');

SET @q = IF(@preflight_ok = 1 AND @has_40 = 1 AND @orphans_40 = 0 AND @fk40_clinic_rule IS NOT NULL AND @fk40_clinic_rule <> 'RESTRICT' AND @has_clinic_guard = 0,
  'ALTER TABLE `consultation_note_drafts` ADD CONSTRAINT `fk_consult_drafts_clinic_v17` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1 AND @has_40 = 1 AND @orphans_40 = 0 AND @fk40_appt_rule IS NOT NULL AND @fk40_appt_rule <> 'RESTRICT' AND @has_appt_guard = 0,
  'ALTER TABLE `consultation_note_drafts` ADD CONSTRAINT `fk_consult_drafts_appt_v17` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Passo B.3: Agora com a constraint RESTRICT já ativa, remove a FK legada (CASCADE):
SET @fk40_legacy_clinic = (SELECT rc.CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc WHERE rc.CONSTRAINT_SCHEMA = @db AND rc.TABLE_NAME = 'consultation_note_drafts' AND rc.REFERENCED_TABLE_NAME = 'clinics' AND rc.DELETE_RULE <> 'RESTRICT' LIMIT 1);
SET @fk40_legacy_appt = (SELECT rc.CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc WHERE rc.CONSTRAINT_SCHEMA = @db AND rc.TABLE_NAME = 'consultation_note_drafts' AND rc.REFERENCED_TABLE_NAME = 'appointments' AND rc.DELETE_RULE <> 'RESTRICT' LIMIT 1);

SET @q = IF(@preflight_ok = 1 AND @has_40 = 1 AND @fk40_legacy_clinic IS NOT NULL,
  CONCAT('ALTER TABLE `consultation_note_drafts` DROP FOREIGN KEY `', @fk40_legacy_clinic, '`'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1 AND @has_40 = 1 AND @fk40_legacy_appt IS NOT NULL,
  CONCAT('ALTER TABLE `consultation_note_drafts` DROP FOREIGN KEY `', @fk40_legacy_appt, '`'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Passo B.4: Assegura os nomes canônicos fk_consult_drafts_clinic e fk_consult_drafts_appointment com ON DELETE RESTRICT
SET @has_canonical_clinic = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_clinic' AND DELETE_RULE = 'RESTRICT');
SET @has_canonical_appt = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_appointment' AND DELETE_RULE = 'RESTRICT');

SET @q = IF(@preflight_ok = 1 AND @has_40 = 1 AND @orphans_40 = 0 AND @has_canonical_clinic = 0,
  'ALTER TABLE `consultation_note_drafts` ADD CONSTRAINT `fk_consult_drafts_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1 AND @has_40 = 1 AND @orphans_40 = 0 AND @has_canonical_appt = 0,
  'ALTER TABLE `consultation_note_drafts` ADD CONSTRAINT `fk_consult_drafts_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Passo B.5: Limpeza de constraints temporárias de guarda (se as canônicas estiverem ativas)
SET @can_drop_guard_clinic = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_clinic_v17');
SET @can_drop_guard_appt = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_appt_v17');
SET @canonical_active_clinic = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_clinic' AND DELETE_RULE = 'RESTRICT');
SET @canonical_active_appt = (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts' AND CONSTRAINT_NAME = 'fk_consult_drafts_appointment' AND DELETE_RULE = 'RESTRICT');

SET @q = IF(@preflight_ok = 1 AND @can_drop_guard_clinic > 0 AND @canonical_active_clinic > 0,
  'ALTER TABLE `consultation_note_drafts` DROP FOREIGN KEY `fk_consult_drafts_clinic_v17`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@preflight_ok = 1 AND @can_drop_guard_appt > 0 AND @canonical_active_appt > 0,
  'ALTER TABLE `consultation_note_drafts` DROP FOREIGN KEY `fk_consult_drafts_appt_v17`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- artigo de ajuda (v14, UX-04)
SET @has_kb = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'helpdesk_articles');
SET @q = IF(@preflight_ok = 1 AND @has_kb = 1, 'INSERT INTO `helpdesk_articles` (`category`, `slug`, `title`, `summary`, `content_markdown`, `is_published`, `view_count`, `created_at`, `updated_at`) SELECT ''agenda'', ''como-reagendar-uma-consulta'', ''Como reagendar uma consulta?'', ''Mude o dia ou horário de uma consulta agendada sem perder o histórico nem os vínculos com o paciente.'', ''### Reagendar uma consulta

1. Na **Agenda**, na linha da consulta (agendada ou confirmada), clique em **Reagendar**. No perfil do paciente, use **Reagendar** no quadro *Próximo passo*.
2. O formulário já vem com o horário e a duração atuais. Escolha o novo dia e horário e, se quiser, informe o motivo.
3. Clique em **Confirmar reagendamento**.

#### O que acontece
- A consulta continua a mesma: paciente, tipo, observações, anamnese, avaliações e planos vinculados não mudam.
- A trilha da consulta registra o horário anterior, o novo horário, o motivo e quem reagendou.
- Se o novo horário conflitar com outra consulta, o formulário continua aberto e preenchido, com o horário destacado. Escolha outro horário ou marque **Permitir sobreposição (encaixe)**.
- Não é possível reagendar para um horário que já passou, nem reagendar consultas concluídas, canceladas ou com falta.
- Teleconsulta: o convite anterior deixa de valer; envie um novo convite ao paciente.'', 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `helpdesk_articles` WHERE `slug` = ''como-reagendar-uma-consulta'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- registro
SET @ok36 = (@preflight_ok = 1 AND @has_table = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions'
       AND COLUMN_NAME IN ('provider_status','join_opens_at_utc','join_closes_at_utc','invite_generation','invite_issued_at_utc','invite_revoked_at_utc','media_ended_at_utc','external_room_name')) = 8
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('teleconsultation_access_attempts','teleconsultation_signals')) = 2);
SET @ok37 = (@preflight_ok = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_note_drafts') = 1);
SET @ok38 = (@preflight_ok = 1 AND @has_terms = 1 AND (SELECT COUNT(*) FROM patient_consents WHERE consent_type LIKE 'telehealth:%') = 0);
SET @q = IF(@ok36, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000036_add_teleconsultation_webrtc_and_join_window'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000036_add_teleconsultation_webrtc_and_join_window'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@ok37, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000037_create_teleconsultation_note_drafts'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000037_create_teleconsultation_note_drafts'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@ok38, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok39 = (@preflight_ok = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_ticket_redemptions') = 1);
SET @q = IF(@ok39, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000039_create_teleconsultation_ticket_redemptions'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000039_create_teleconsultation_ticket_redemptions'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificação estrita de preservação de dados e integridade de FKs antes de marcar 040
SET @drafts_count_after = IF(@has_40 = 1, (SELECT COUNT(*) FROM `consultation_note_drafts`), (SELECT COUNT(*) FROM `consultation_note_drafts`));
SET @data_intact_40 = IF(@has_40 = 0 OR @drafts_count_after = @drafts_count_before, 1, 0);

SET @fks_40 = (SELECT COUNT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts'
    AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE = 'RESTRICT');
SET @ok40 = (@preflight_ok = 1 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts') = 1
  AND @fks_40 = 2 AND @orphans_40 = 0 AND @data_intact_40 = 1);
SET @q = IF(@ok40, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000040_create_consultation_note_drafts'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000040_create_consultation_note_drafts'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência: deve mostrar resultado = 'OK', rascunhos_orfaos_40 = 0 e fks_rascunho_consulta = 2 (com DELETE_RULE = RESTRICT)
SELECT CASE
  WHEN @has_clinics = 0 OR @has_appointments = 0 OR @has_users = 0 THEN 'ERRO (PREFLIGHT FAIL-FAST): tabelas base (clinics/appointments/users) ausentes. Nenhuma DDL/DML foi executada.'
  WHEN @has_table = 0 THEN 'ERRO (PREFLIGHT FAIL-FAST): tabela teleconsultation_sessions não existe. Nenhuma DDL/DML foi executada.'
  WHEN @has_terms = 0 THEN 'ERRO (PREFLIGHT FAIL-FAST): tabela consent_terms não existe. Nenhuma DDL/DML foi executada.'
  WHEN @bad_engines > 0 THEN 'ERRO (PREFLIGHT FAIL-FAST): tabelas não-InnoDB detectadas. Nenhuma DDL/DML foi executada.'
  WHEN @has_pk_clinics = 0 OR @has_pk_appts = 0 OR @has_pk_users = 0 THEN 'ERRO (PREFLIGHT FAIL-FAST): chaves primárias ausentes. Nenhuma DDL/DML foi executada.'
  WHEN @has_uq = 0 AND @dups > 0 THEN CONCAT('ATENÇÃO (PREFLIGHT FAIL-FAST): ', @dups, ' agendamento(s) com sessão repetida; o script foi interrompido antes de qualquer DDL.')
  WHEN @orphans_40 > 0 THEN CONCAT('ATENÇÃO (PREFLIGHT FAIL-FAST): ', @orphans_40, ' rascunho(s) órfão(s) em consultation_note_drafts (clínica=', @orphans_40_clinic, ', consulta=', @orphans_40_appt, '). O script foi interrompido ANTES de qualquer DDL/DML; nenhum dado foi apagado e a migração 040 não foi marcada como concluída. Siga o procedimento de quarentena manual do LEIA-ME-HOSTGATOR.md.')
  WHEN @preflight_ok = 1 AND @ok AND @ok36 AND @ok37 AND @ok38 AND @ok39 AND @ok40
       AND (SELECT COUNT(*) FROM consent_terms WHERE purpose_code = 'telehealth' AND version = '2026.10-r1' AND is_active = 1) = 1
       AND (SELECT COUNT(*) FROM migrations WHERE migration IN ('2026_10_15_000035_harden_teleconsultation_sessions','2026_10_15_000036_add_teleconsultation_webrtc_and_join_window','2026_10_15_000037_create_teleconsultation_note_drafts','2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms','2026_10_15_000039_create_teleconsultation_ticket_redemptions','2026_10_15_000040_create_consultation_note_drafts')) = 6
    THEN 'OK'
  ELSE 'ERRO: conferência falhou; envie este resultado.'
END AS resultado,
@preflight_ok AS preflight_ok,
(SELECT COUNT(*) FROM teleconsultation_sessions) AS sessoes_existentes,
(SELECT COUNT(*) FROM teleconsultation_sessions WHERE invite_revoked_at_utc IS NOT NULL) AS convites_revogados,
(SELECT COUNT(*) FROM migrations WHERE migration IN ('2026_10_14_000032_create_login_attempts_table','2026_10_15_000033_convert_login_attempts_to_bucket_key_primary','2026_10_15_000034_add_taco_code_to_foods')) AS migracoes_32_33_34_previas_de_3,
(SELECT setting_value FROM platform_settings WHERE setting_key = 'feature_flag_telehealth') AS feature_flag_telehealth,
(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consultation_note_drafts') AS tabela_rascunho_consulta,
@fks_40 AS fks_rascunho_consulta,
@orphans_40 AS rascunhos_orfaos_40,
@drafts_count_before AS rascunhos_antes_migracao,
@drafts_count_after AS rascunhos_depois_migracao,
IF(@has_kb = 1, (SELECT COUNT(*) FROM helpdesk_articles WHERE slug = 'como-reagendar-uma-consulta'), 'sem tabela de ajuda') AS artigo_reagendar;
