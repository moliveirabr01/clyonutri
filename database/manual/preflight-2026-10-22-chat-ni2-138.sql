-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-138 · Canal seguro de mensagens). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','clinic_members','appointments','meal_plans','chat_conversations','chat_messages','chat_attachments','chat_notifications_log')
  AND UPPER(ENGINE) = 'INNODB');
SET @links = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_professional_links');
SET @settings = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_clinic_settings');
SET @uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp');
SET @m47 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_change_requests');
SELECT
  CASE WHEN @links = 1 AND @settings = 1 AND @uq > 0 THEN 'OK — migração 48 já aplicada'
       WHEN @base = 10 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 10) AS pronto_para_migrar, (@links = 1 AND @settings = 1 AND @uq > 0) AS migracao_ja_aplicada, @m47 AS migracao_47_aplicada,
  (SELECT COUNT(*) FROM `chat_conversations`) AS conversas,
  (SELECT COUNT(*) FROM `chat_messages`) AS mensagens,
  (SELECT COUNT(*) FROM (SELECT 1 FROM `chat_messages` WHERE `idempotency_key` IS NOT NULL
      GROUP BY `conversation_id`, `idempotency_key` HAVING COUNT(*) > 1) d) AS chaves_de_envio_repetidas,
  (SELECT COUNT(*) FROM `chat_attachments`) AS anexos,
  (SELECT COUNT(*) FROM (
      SELECT DISTINCT x.clinic_id, x.patient_id, x.professional_id FROM (
        SELECT `clinic_id`, `patient_id`, `professional_id` FROM `appointments` WHERE `status` NOT IN ('cancelled')
        UNION SELECT `clinic_id`, `patient_id`, `professional_id` FROM `meal_plans`
        UNION SELECT c.`clinic_id`, c.`patient_id`, c.`professional_id` FROM `chat_conversations` c
          WHERE EXISTS (SELECT 1 FROM `chat_messages` m WHERE m.`conversation_id` = c.`id` AND m.`sender_type` = 'professional')
      ) x
      JOIN `patients` pa ON pa.`id` = x.patient_id AND pa.`clinic_id` = x.clinic_id AND pa.`status` = 'active'
      JOIN `professionals` pr ON pr.`id` = x.professional_id
      JOIN `clinic_members` cm ON cm.`user_id` = pr.`user_id` AND cm.`clinic_id` = x.clinic_id AND cm.`status` = 'active'
  ) v) AS vinculos_que_serao_criados,
  (SELECT COUNT(*) FROM `chat_conversations` c WHERE NOT EXISTS (
      SELECT 1 FROM `appointments` a WHERE a.`patient_id` = c.`patient_id` AND a.`professional_id` = c.`professional_id` AND a.`status` <> 'cancelled')
    AND NOT EXISTS (SELECT 1 FROM `meal_plans` mp WHERE mp.`patient_id` = c.`patient_id` AND mp.`professional_id` = c.`professional_id`)
    AND NOT EXISTS (SELECT 1 FROM `chat_messages` m WHERE m.`conversation_id` = c.`id` AND m.`sender_type` = 'professional')) AS conversas_que_ficarao_somente_leitura,
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id') AS tipo_das_chaves;
