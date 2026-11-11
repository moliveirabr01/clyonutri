-- Clyo Nutri v54.21 candidato · Migração 83: materiais educativos (biblioteca da clínica e envio para pacientes).
-- Aplicar só após backup, com a base na migração 82 (v54.20). Idempotente; aditiva; não apaga dados.
-- Os arquivos ficam em storage/app/materials/<clínica>/ (fora da pasta pública); o banco guarda metadados, hash e envios.
SET @db=DATABASE();
SET @base=(SELECT COUNT(*)=2 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('academy_articles','migrations') AND ENGINE='InnoDB');
SELECT IF(@base,'Base 82 encontrada: aplicando a 83','BLOQUEADO: exige a migração 82 (v54.20) e InnoDB') AS preflight;
SET @q=IF(@base,'CREATE TABLE IF NOT EXISTS educational_materials (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, title VARCHAR(160) NOT NULL, description TEXT NULL, category VARCHAR(40) NOT NULL, file_name VARCHAR(160) NOT NULL, mime_type VARCHAR(60) NOT NULL, size_bytes INT NOT NULL, sha256 CHAR(64) NOT NULL, storage_name VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT ''active'', uploaded_by_user_id INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base,'CREATE TABLE IF NOT EXISTS educational_material_shares (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, material_id INT NOT NULL, patient_id INT NOT NULL, shared_by_user_id INT NOT NULL, note VARCHAR(500) NULL, shared_at DATETIME NOT NULL, revoked_at DATETIME NULL, revoked_by_user_id INT NULL, first_opened_at DATETIME NULL, UNIQUE KEY material_id (material_id, patient_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='educational_materials' AND INDEX_NAME='idx_edm_clinic')=0,'CREATE INDEX idx_edm_clinic ON educational_materials (clinic_id,status,category)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='educational_material_shares' AND INDEX_NAME='idx_edms_patient')=0,'CREATE INDEX idx_edms_patient ON educational_material_shares (clinic_id,patient_id,revoked_at)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ready=@base
  AND (SELECT COUNT(*)=2 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('educational_materials','educational_material_shares') AND ENGINE='InnoDB')
  AND (SELECT COUNT(*)=2 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND INDEX_NAME IN ('idx_edm_clinic','idx_edms_patient') AND SEQ_IN_INDEX=1);
SET @q=IF(@ready,'INSERT IGNORE INTO migrations(migration) VALUES(''2026_11_03_000083_create_educational_materials'')','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SELECT IF(@ready,'MIGRAÇÃO 83 OK (Materiais educativos)','BLOQUEADO: migração 83 incompleta, não liberar o tráfego') AS resultado;
