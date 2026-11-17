-- NI2-891 (v54.76) — migração 100: colunas da revisão do catálogo de exames. MySQL 5.7+, idempotente.
-- Selecione o banco do sistema no phpMyAdmin antes de executar. Pode rodar mais de uma vez.
-- A carga dos códigos TUSS revisados vem DEPOIS, em um segundo arquivo gerado por
-- `php bin/exam_catalog_review.php --sql` a partir da planilha revisada pelo nutricionista RT.
SET @t := 'exam_catalog';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'clinical_review_source');
SET @sql := IF(@c = 0, 'ALTER TABLE exam_catalog ADD COLUMN clinical_review_source VARCHAR(255) NULL', 'SELECT ''clinical_review_source já existe'' AS ni2_891');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'standard_code_note');
SET @sql := IF(@c = 0, 'ALTER TABLE exam_catalog ADD COLUMN standard_code_note VARCHAR(255) NULL', 'SELECT ''standard_code_note já existe'' AS ni2_891');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO migrations (migration)
SELECT '2026_11_17_000100_ni2_891_exam_catalog_review' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_17_000100_ni2_891_exam_catalog_review');

-- Conferência: 2 colunas; e quantos exames do catálogo geral ainda aguardam revisão (esperado hoje: 22).
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME IN ('clinical_review_source','standard_code_note');
SELECT COUNT(*) AS exames_sem_revisao FROM exam_catalog
WHERE clinic_id IS NULL AND is_active = 1 AND (clinical_review_status IS NULL OR clinical_review_status <> 'reviewed');
