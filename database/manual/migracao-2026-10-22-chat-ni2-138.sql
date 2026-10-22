-- ============================================================================
-- ClyoNutri — Migração Manual 48 (NI2-138 · Canal seguro de mensagens: vínculo, guarda e notificações)
-- Equivale a database/migrations/2026_10_22_000048_create_chat_links_and_retention.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-chat-ni2-138.sql)
-- e da migração 47. Idempotente.
-- Efeito nos dados:
--  * cria o vínculo paciente–profissional ATIVO para cada par com consulta não cancelada, plano alimentar
--    ou resposta da profissional no chat (só paciente ativa e profissional ativa na clínica);
--    sem vínculo o chat fica somente leitura;
--  * mensagens repetidas com a mesma chave de envio na mesma conversa (se houver) recebem o sufixo
--    "-dup-<id>" na chave — o texto das mensagens não muda;
--  * prazo de guarda das mensagens: 20 anos (cada clínica muda em Configurações > Mensagens).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_attachments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_attachments' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','clinic_members','appointments','meal_plans','chat_conversations','chat_messages','chat_attachments','chat_notifications_log')
  AND UPPER(ENGINE) = 'INNODB') = 10);

-- 1. Vínculo paciente–profissional.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_professional_links` (
  `id` {T_PATIENTS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''active'',
  `source` VARCHAR(30) NOT NULL DEFAULT ''manual'',
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME NULL,
  `ended_reason` VARCHAR(200) NULL,
  `created_by_user_id` INT NULL,
  `ended_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `idx_links_patient` (`clinic_id`, `patient_id`, `status`),
  KEY `idx_links_professional` (`clinic_id`, `professional_id`, `status`),
  CONSTRAINT `fk_links_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Configurações de mensagens da clínica (tempo de resposta e prazo de guarda).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `chat_clinic_settings` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `response_time_label` VARCHAR(60) NULL,
  `retention_years` INT NOT NULL DEFAULT 20,
  `updated_by_user_id` INT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_chat_clinic_settings` (`clinic_id`),
  CONSTRAINT `fk_chat_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Colunas novas.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'attachment_id');
SET @q = IF(@ok_base = 1 AND @has = 0, CONCAT('ALTER TABLE `chat_messages` ADD COLUMN `attachment_id` ', IFNULL(@t_attachments, 'INT'), ' NULL'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_notifications_log' AND COLUMN_NAME = 'failure_reason');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `chat_notifications_log` ADD COLUMN `failure_reason` VARCHAR(60) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Anexo de cada mensagem (o link de download passa a usar o id do anexo).
SET @q = IF(@ok_base = 1, 'UPDATE `chat_messages` m JOIN (SELECT `message_id`, MIN(`id`) AS aid FROM `chat_attachments` WHERE `message_id` IS NOT NULL GROUP BY `message_id`) a
  ON a.`message_id` = m.`id` SET m.`attachment_id` = a.aid WHERE m.`attachment_id` IS NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Chave de envio única por conversa: repetições antigas ganham sufixo antes do índice único.
SET @q = IF(@ok_base = 1, 'UPDATE `chat_messages` m JOIN (SELECT `conversation_id`, `idempotency_key`, MIN(`id`) AS keep_id FROM `chat_messages`
  WHERE `idempotency_key` IS NOT NULL GROUP BY `conversation_id`, `idempotency_key` HAVING COUNT(*) > 1) d
  ON d.`conversation_id` = m.`conversation_id` AND d.`idempotency_key` = m.`idempotency_key`
  SET m.`idempotency_key` = CONCAT(LEFT(m.`idempotency_key`, 40), ''-dup-'', m.`id`) WHERE m.`id` <> d.keep_id', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE UNIQUE INDEX `uq_chat_messages_idemp` ON `chat_messages` (`conversation_id`, `idempotency_key`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'idx_chat_messages_sender_time');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_chat_messages_sender_time` ON `chat_messages` (`conversation_id`, `sender_type`, `created_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
-- O índice antigo (não único) sai só depois que o único existe.
SET @uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp');
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'idx_chat_messages_idemp');
SET @q = IF(@uq > 0 AND @has > 0, 'ALTER TABLE `chat_messages` DROP INDEX `idx_chat_messages_idemp`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. Vínculos iniciais pelo histórico de cuidado.
SET @links = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_professional_links');
SET @q = IF(@links = 1, 'INSERT INTO `patient_professional_links` (`clinic_id`, `patient_id`, `professional_id`, `status`, `source`, `started_at`, `created_at`, `updated_at`)
  SELECT DISTINCT x.clinic_id, x.patient_id, x.professional_id, ''active'', ''migration_care_history'', NOW(), NOW(), NOW() FROM (
      SELECT `clinic_id`, `patient_id`, `professional_id` FROM `appointments` WHERE `status` NOT IN (''cancelled'')
      UNION SELECT `clinic_id`, `patient_id`, `professional_id` FROM `meal_plans`
      UNION SELECT c.`clinic_id`, c.`patient_id`, c.`professional_id` FROM `chat_conversations` c
        WHERE EXISTS (SELECT 1 FROM `chat_messages` m WHERE m.`conversation_id` = c.`id` AND m.`sender_type` = ''professional'')
  ) x
  JOIN `patients` pa ON pa.`id` = x.patient_id AND pa.`clinic_id` = x.clinic_id AND pa.`status` = ''active''
  JOIN `professionals` pr ON pr.`id` = x.professional_id
  JOIN `clinic_members` cm ON cm.`user_id` = pr.`user_id` AND cm.`clinic_id` = x.clinic_id AND cm.`status` = ''active''
  WHERE NOT EXISTS (SELECT 1 FROM (SELECT `clinic_id`, `patient_id`, `professional_id` FROM `patient_professional_links` WHERE `status` = ''active'') l
      WHERE l.clinic_id = x.clinic_id AND l.patient_id = x.patient_id AND l.professional_id = x.professional_id)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (@links = 1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_clinic_settings') = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'attachment_id') = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_notifications_log' AND COLUMN_NAME = 'failure_reason') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000048_create_chat_links_and_retention'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000048_create_chat_links_and_retention'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 48 aplicada (vínculo do chat, guarda das mensagens e notificações)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `patient_professional_links` WHERE `status` = 'active') AS vinculos_ativos;
