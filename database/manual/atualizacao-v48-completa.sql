-- ============================================================================
-- Clyo Nutri v48: ATUALIZAÇÃO COMPLETA DO BANCO, migração 67 (NI2-539: laudos e resultados anexados)
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou. Revisado na v49.
--  1. exam_result_documents: o laudo ORIGINAL (arquivo fora da pasta pública, SHA-256), laboratório, datas, quem enviou,
--     situação (aguardando revisão, aceito, recusado), revisão e versões de correção. Começa vazia.
--  2. exam_result_document_items: quais exames da solicitação cada laudo traz. Começa vazia.
--  3. Registra a migração 67 uma vez só.
-- Idempotente, sem chave estrangeira, sem DROP, DELETE ou UPDATE. Não mexe em nenhuma tabela existente. MySQL 5.7 e 8.
-- ============================================================================
SET NAMES utf8mb4;
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`exam_result_documents` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `patient_id` BIGINT(20) NOT NULL,
  `exam_request_id` BIGINT(20) NOT NULL,
  `uploaded_by_type` VARCHAR(20) NOT NULL,
  `uploaded_by_user_id` BIGINT(20) NULL,
  `uploaded_by_patient_id` BIGINT(20) NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `size_bytes` INT NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `security_check` VARCHAR(255) NOT NULL,
  `laboratory_name` VARCHAR(255) NULL,
  `collected_at` DATE NULL,
  `report_issued_at` DATE NULL,
  `notes` VARCHAR(1000) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''pending_review'',
  `reviewed_by_user_id` BIGINT(20) NULL,
  `reviewed_at` DATETIME NULL,
  `review_note` VARCHAR(1000) NULL,
  `version_number` INT NOT NULL DEFAULT 1,
  `supersedes_document_id` BIGINT(20) NULL,
  `root_document_id` BIGINT(20) NULL,
  `correction_reason` VARCHAR(1000) NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`exam_result_document_items` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT(20) NOT NULL,
  `exam_request_item_id` BIGINT(20) NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Índices (só os que faltarem)
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_result_documents` ADD INDEX `idx_exam_result_docs_request` (`clinic_id`, `exam_request_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_result_documents' AND INDEX_NAME = 'idx_exam_result_docs_request');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_result_documents` ADD INDEX `idx_exam_result_docs_patient` (`clinic_id`, `patient_id`, `status`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_result_documents' AND INDEX_NAME = 'idx_exam_result_docs_patient');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_result_documents` ADD INDEX `idx_exam_result_docs_supersedes` (`supersedes_document_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_result_documents' AND INDEX_NAME = 'idx_exam_result_docs_supersedes');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_result_document_items` ADD UNIQUE INDEX `uq_exam_result_doc_item` (`document_id`, `exam_request_item_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_result_document_items' AND INDEX_NAME = 'uq_exam_result_doc_item');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_result_document_items` ADD INDEX `idx_exam_result_doc_item_item` (`exam_request_item_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_result_document_items' AND INDEX_NAME = 'idx_exam_result_doc_item_item');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Registro da migração, só se tudo acima está no lugar
SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`)
SELECT ''2026_10_29_000067_exam_result_documents'' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000067_exam_result_documents'')
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN (''exam_result_documents'',''exam_result_document_items'')) = 2 AND ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_request'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_patient'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_supersedes'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''uq_exam_result_doc_item'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''idx_exam_result_doc_item_item'')) = 5');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

SET @c = CONCAT('SELECT @db AS banco, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN (''exam_result_documents'',''exam_result_document_items'')) AS tabelas_de_laudos_de_2, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'') AS colunas_laudos_de_26, ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_request'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_patient'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_supersedes'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''uq_exam_result_doc_item'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''idx_exam_result_doc_item_item'')) AS indices_de_5,
  (SELECT COUNT(*) FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000067_exam_result_documents'') AS migracao_67_registrada');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
