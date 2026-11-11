-- ============================================================================
-- Clyo Nutri v47: ATUALIZAÇÃO COMPLETA DO BANCO, migração 66 (NI2-530, NI2-532, NI2-533, NI2-537, NI2-542)
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou. Revisado na v49.
--  1. exam_regulatory_policies: change_summary (o que mudou em cada versão da política).
--  2. exam_catalog: exame da clínica com validação, código padronizado (vazio) e situação da revisão clínica.
--     Os 22 exames que já existem ficam como estão: catálogo geral, validados, revisão clínica "pending".
--  3. exam_favorites: favoritos por nutricionista (nova, vazia).
--  4. exam_requests: atendimento de origem e versões. Solicitações existentes ficam com versão 1 e sem vínculo.
--  5. Registra a migração 66 uma vez só.
-- Idempotente (pode rodar de novo), sem chave estrangeira, sem DROP, sem DELETE e sem UPDATE. MySQL 5.7 e 8.
-- Rode ANTES o atualizacao-v47-preflight.sql e DEPOIS o atualizacao-v47-verificacao.sql.
-- ============================================================================
SET NAMES utf8mb4;
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());

-- 1, 2 e 4. Colunas (só as que faltarem)
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_regulatory_policies` ADD COLUMN `change_summary` TEXT NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_regulatory_policies' AND COLUMN_NAME = 'change_summary');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `clinic_id` BIGINT(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'clinic_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `origin` VARCHAR(20) NOT NULL DEFAULT ''curated'''), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'origin');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `validation_status` VARCHAR(20) NOT NULL DEFAULT ''approved'''), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'validation_status');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `submitted_by_user_id` BIGINT(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'submitted_by_user_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `validated_by_user_id` BIGINT(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'validated_by_user_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `validated_at` DATETIME NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'validated_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `validation_note` VARCHAR(500) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'validation_note');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `standard_code_system` VARCHAR(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'standard_code_system');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `standard_code` VARCHAR(40) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'standard_code');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `clinical_review_status` VARCHAR(20) NOT NULL DEFAULT ''pending'''), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'clinical_review_status');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `clinical_reviewed_by` VARCHAR(255) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'clinical_reviewed_by');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD COLUMN `clinical_reviewed_at` DATETIME NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = 'clinical_reviewed_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_requests` ADD COLUMN `appointment_id` BIGINT(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'appointment_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_requests` ADD COLUMN `supersedes_request_id` BIGINT(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'supersedes_request_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_requests` ADD COLUMN `root_request_id` BIGINT(20) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'root_request_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_requests` ADD COLUMN `version_number` INT NOT NULL DEFAULT 1'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'version_number');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- 3. Favoritos
SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`exam_favorites` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `user_id` BIGINT(20) NOT NULL,
  `exam_id` BIGINT(20) NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Índices (só os que faltarem)
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_catalog` ADD INDEX `idx_exam_catalog_clinic_validation` (`clinic_id`, `validation_status`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND INDEX_NAME = 'idx_exam_catalog_clinic_validation');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_favorites` ADD UNIQUE INDEX `uq_exam_favorites_user_exam` (`clinic_id`, `user_id`, `exam_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_favorites' AND INDEX_NAME = 'uq_exam_favorites_user_exam');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_requests` ADD INDEX `idx_exam_requests_appointment` (`clinic_id`, `appointment_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND INDEX_NAME = 'idx_exam_requests_appointment');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`exam_requests` ADD INDEX `idx_exam_requests_root` (`root_request_id`)'), 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND INDEX_NAME = 'idx_exam_requests_root');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- 5. Registro da migração, só se tudo acima está no lugar
SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`)
SELECT ''2026_10_29_000066_exam_catalog_favorites_versions_and_links'' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000066_exam_catalog_favorites_versions_and_links'')
  AND ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_regulatory_policies'' AND COLUMN_NAME IN (''change_summary'')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_catalog'' AND COLUMN_NAME IN (''clinic_id'', ''origin'', ''validation_status'', ''submitted_by_user_id'', ''validated_by_user_id'', ''validated_at'', ''validation_note'', ''standard_code_system'', ''standard_code'', ''clinical_review_status'', ''clinical_reviewed_by'', ''clinical_reviewed_at'')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_requests'' AND COLUMN_NAME IN (''appointment_id'', ''supersedes_request_id'', ''root_request_id'', ''version_number''))) = 17
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_favorites'') = 1');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

SET @c = CONCAT('SELECT
  @db AS banco,
  ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_regulatory_policies'' AND COLUMN_NAME IN (''change_summary'')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_catalog'' AND COLUMN_NAME IN (''clinic_id'', ''origin'', ''validation_status'', ''submitted_by_user_id'', ''validated_by_user_id'', ''validated_at'', ''validation_note'', ''standard_code_system'', ''standard_code'', ''clinical_review_status'', ''clinical_reviewed_by'', ''clinical_reviewed_at'')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_requests'' AND COLUMN_NAME IN (''appointment_id'', ''supersedes_request_id'', ''root_request_id'', ''version_number''))) AS colunas_da_66_de_17,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_favorites'') AS tabela_favoritos,
  ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_catalog'' AND INDEX_NAME = ''idx_exam_catalog_clinic_validation'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_favorites'' AND INDEX_NAME = ''uq_exam_favorites_user_exam'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_requests'' AND INDEX_NAME = ''idx_exam_requests_appointment'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_requests'' AND INDEX_NAME = ''idx_exam_requests_root'')) AS indices_de_4,
  (SELECT COUNT(*) FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000066_exam_catalog_favorites_versions_and_links'') AS migracao_66_registrada');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
