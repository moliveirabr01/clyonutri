-- NI2-885 (v54.76): patients.allergies VARCHAR(500) -> TEXT (MySQL 5.7+). Idempotente: só altera se ainda for VARCHAR.
-- Motivo: a alergia é gravada cifrada (enc:v1); acima de ~340 caracteres o valor cifrado não cabia em 500.
-- Rode ANTES do script bin/encrypt_clinical_records.php. Selecione o banco do sistema no phpMyAdmin antes de executar.
SET @tipo := (SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'allergies');
SET @sql := IF(@tipo = 'varchar', 'ALTER TABLE patients MODIFY allergies TEXT NULL', 'SELECT ''patients.allergies já é TEXT (ou não existe): nada a fazer'' AS ni2_885');
PREPARE ni2_885_stmt FROM @sql;
EXECUTE ni2_885_stmt;
DEALLOCATE PREPARE ni2_885_stmt;
INSERT INTO migrations (migration)
SELECT '2026_11_17_000098_ni2_885_patients_allergies_text' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_17_000098_ni2_885_patients_allergies_text');
-- Conferência: deve mostrar DATA_TYPE = text
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'allergies';
