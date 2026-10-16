-- ============================================================================
-- ClyoNutri — Migração 2026-10-15 (v11): teleconsulta endurecida (NI2-221)
-- Rodar no phpMyAdmin com o banco do sistema selecionado (aba SQL ou Importar).
--
-- O que faz (só acrescenta; nenhum DROP, nenhum DELETE, nenhum UPDATE de dados existentes):
--   1. teleconsultation_sessions: colunas novas token_generation, token_issued_at, token_expires_at,
--      token_revoked_at, scheduled_at_snapshot, external_room_name, consent_term_id (só as que faltarem).
--   2. Confere o UNIQUE de appointment_id (uma sessão por agendamento); cria se faltar e não houver repetidos.
--   3. consent_terms: insere o termo de teleconsulta versão 2026.10-r1 se ainda não existir.
--   4. Registra a migração 2026_10_15_000035_harden_teleconsultation_sessions.
-- Efeito nos convites antigos: sessões criadas antes da v11 ficam com token_issued_at vazio, então
-- links antigos deixam de funcionar (o profissional gera um link novo na sala). Isso é intencional (F04).
-- Idempotente: pode rodar mais de uma vez. Compatível com MySQL 5.7/8 e MariaDB.
-- Rollback: ver LEIA-ME (as colunas novas podem ficar; a v10 as ignora).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t = CONCAT('`', @db, '`.`teleconsultation_sessions`');
SET @has_table = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions');
SET @has_terms = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consent_terms');

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
SET @q = IF(@has_table = 1 AND @parts <> '', CONCAT('ALTER TABLE ', @t, ' ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- UNIQUE(appointment_id): uma sessão por agendamento
SET @has_uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'appointment_id' AND NON_UNIQUE = 0);
SET @dups = 0;
SET @q = IF(@has_table = 1 AND @has_uq = 0, CONCAT('SELECT COUNT(*) FROM (SELECT appointment_id FROM ', @t, ' GROUP BY appointment_id HAVING COUNT(*) > 1) d INTO @dups'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@has_table = 1 AND @has_uq = 0 AND @dups = 0, CONCAT('ALTER TABLE ', @t, ' ADD UNIQUE INDEX `uq_tele_sessions_appointment` (`appointment_id`)'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Termo de consentimento da teleconsulta (texto pendente de aprovação jurídica — decisão do Maicon)
SET @q = IF(@has_terms = 1, CONCAT('INSERT INTO `', @db, '`.`consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT ''2026.10-r1'', ''Consentimento para teleconsulta nutricional'', ''telehealth'', ',
  '''Concordo em realizar esta consulta de nutrição a distância, por vídeo, com o(a) profissional indicado(a) nesta página. Entendo que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não há exame físico) e que o(a) profissional pode recomendar uma consulta presencial. A sessão não é gravada. As anotações clínicas ficam no meu prontuário na clínica. Posso recusar este termo; nesse caso a teleconsulta não acontece e posso pedir à clínica um atendimento presencial.'', 1 ',
  'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`consent_terms` WHERE `purpose_code` = ''telehealth'' AND `version` = ''2026.10-r1'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (@has_table = 1 AND @has_terms = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions'
       AND COLUMN_NAME IN ('token_generation','token_issued_at','token_expires_at','token_revoked_at','scheduled_at_snapshot','external_room_name','consent_term_id')) = 7);
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_15_000035_harden_teleconsultation_sessions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_15_000035_harden_teleconsultation_sessions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência: deve mostrar resultado = 'OK'
SELECT CASE
  WHEN @has_table = 0 THEN 'ERRO: tabela teleconsultation_sessions não existe. Nada foi alterado.'
  WHEN @has_terms = 0 THEN 'ERRO: tabela consent_terms não existe. Envie este resultado para análise.'
  WHEN @has_uq = 0 AND @dups > 0 THEN CONCAT('ATENÇÃO: ', @dups, ' agendamento(s) com sessão repetida; o UNIQUE não foi criado. Colunas OK. Envie este resultado.')
  WHEN @ok AND (SELECT COUNT(*) FROM consent_terms WHERE purpose_code = 'telehealth' AND version = '2026.10-r1') = 1 THEN 'OK'
  ELSE 'ERRO: conferência falhou; envie este resultado.'
END AS resultado,
(SELECT COUNT(*) FROM teleconsultation_sessions) AS sessoes_existentes,
(SELECT setting_value FROM platform_settings WHERE setting_key = 'feature_flag_telehealth') AS feature_flag_telehealth;
