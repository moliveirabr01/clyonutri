-- NI2-886 (v54.76) — migração 99: colunas da revisão das medidas caseiras estimadas. MySQL 5.7+, idempotente.
-- Selecione o banco do sistema no phpMyAdmin antes de executar. Pode rodar mais de uma vez.
SET @t := 'food_household_measures';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'review_status');
SET @sql := IF(@c = 0, 'ALTER TABLE food_household_measures ADD COLUMN review_status VARCHAR(20) NULL', 'SELECT ''review_status já existe'' AS ni2_886');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'reviewed_by');
SET @sql := IF(@c = 0, 'ALTER TABLE food_household_measures ADD COLUMN reviewed_by INT NULL', 'SELECT ''reviewed_by já existe'' AS ni2_886');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'reviewer_name');
SET @sql := IF(@c = 0, 'ALTER TABLE food_household_measures ADD COLUMN reviewer_name VARCHAR(160) NULL', 'SELECT ''reviewer_name já existe'' AS ni2_886');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'reviewed_at');
SET @sql := IF(@c = 0, 'ALTER TABLE food_household_measures ADD COLUMN reviewed_at DATETIME NULL', 'SELECT ''reviewed_at já existe'' AS ni2_886');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'grams_before_review');
SET @sql := IF(@c = 0, 'ALTER TABLE food_household_measures ADD COLUMN grams_before_review DECIMAL(7,2) NULL', 'SELECT ''grams_before_review já existe'' AS ni2_886');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO migrations (migration)
SELECT '2026_11_17_000099_ni2_886_household_measure_review' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_17_000099_ni2_886_household_measure_review');

-- Conferência: 5 linhas; e a contagem atual de medidas estimadas ainda sem revisão.
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures'
  AND COLUMN_NAME IN ('review_status','reviewed_by','reviewer_name','reviewed_at','grams_before_review');
SELECT COUNT(*) AS medidas_estimadas_sem_revisao FROM food_household_measures
WHERE source LIKE '%revisar%' AND (review_status IS NULL OR review_status = '');
