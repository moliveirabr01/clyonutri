-- v54.18, migração 80. Preflight bloqueante: exige a base v54.17 e tabelas InnoDB.
-- Sem conteúdo clínico, sem remoção de dados, sem alteração de flags.
SET @db=DATABASE();
SET @base=(SELECT COUNT(*)=8 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('patients','meal_plans','meal_plan_meals','meal_plan_items','anamneses','exam_requests','exam_request_items','migrations'));
SET @engine=(SELECT COUNT(*)=0 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('patients','meal_plans','meal_plan_meals','meal_plan_items','anamneses','exam_requests','exam_request_items') AND ENGINE<>'InnoDB');
SET @v79=(SELECT COUNT(*)=1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clinical_protocols' AND COLUMN_NAME='clinic_id') AND (SELECT COUNT(*)=1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='preconsultation_questionnaires' AND COLUMN_NAME='answer_revision');
SET @ok=@base AND @engine AND @v79;
SELECT IF(@ok,'PREFLIGHT OK','BLOQUEADO: confirme base v54.17/migração 79 e InnoDB. Nenhuma alteração será aplicada.') AS preflight;
SET @q=IF(@ok,'CREATE TABLE IF NOT EXISTS workflow_write_receipts (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, user_id INT NOT NULL, request_key VARCHAR(80) NOT NULL, request_sha256 CHAR(64) NOT NULL, result_json TEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,user_id,request_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','SELECT ''BLOQUEADO'' AS resultado');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ready=@ok AND (SELECT COUNT(*)=1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='workflow_write_receipts');
SET @q=IF(@ready,'INSERT IGNORE INTO migrations(migration) VALUES(''2026_11_03_000080_create_workflow_receipts'')','SELECT ''BLOQUEADO: não liberar tráfego'' AS resultado');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SELECT IF(@ready,'MIGRAÇÃO 80 OK','BLOQUEADO: não liberar tráfego') AS resultado;
