-- Candidato v54.19. Aplicar apenas após backup e migração 80. Sem remoção de dados.
SET @db=DATABASE();
SET @base=(SELECT COUNT(*)=3 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('appointments','workflow_write_receipts','migrations') AND ENGINE='InnoDB');
SET @q=IF(@base,'CREATE TABLE IF NOT EXISTS care_progress_events (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, appointment_id INT NOT NULL, user_id INT NOT NULL, step_key VARCHAR(24) NOT NULL, decision VARCHAR(24) NOT NULL, reason_code VARCHAR(40) NOT NULL, evidence_sha256 CHAR(64) NOT NULL, request_key VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,appointment_id,request_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','SELECT ''BLOQUEADO: exige migração 80 e InnoDB'' AS resultado');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base,'CREATE TABLE IF NOT EXISTS care_workflow_policies (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, user_id INT NOT NULL, consultation_type VARCHAR(24) NOT NULL, policy_json TEXT NOT NULL, request_key VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,request_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','SELECT ''BLOQUEADO'' AS resultado');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ready=@base AND (SELECT COUNT(*)=2 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('care_progress_events','care_workflow_policies') AND ENGINE='InnoDB');
SET @q=IF(@ready,'INSERT IGNORE INTO migrations(migration) VALUES(''2026_11_03_000081_create_care_progress_events'')','SELECT ''BLOQUEADO: não liberar tráfego'' AS resultado');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SELECT IF(@ready,'MIGRAÇÃO 81 OK','BLOQUEADO: não liberar tráfego') AS resultado;
