-- v54.17 / migração 79. Execute somente após backup e preflight.
-- Não contém dados clínicos nem ativa protocolos. Compatível com MySQL 5.7/8 e MariaDB.
SET @db = DATABASE();
SET @base_ok = (SELECT COUNT(*) = 8 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('clinical_protocols','clinical_protocol_versions','protocol_responses','patient_conditions','preconsultation_questionnaires','preconsultation_answer_snapshots','consent_terms','migrations'));
SET @engine_ok = (SELECT COUNT(*)=0 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('clinical_protocols','clinical_protocol_versions','protocol_responses','patient_conditions','preconsultation_questionnaires','preconsultation_answer_snapshots','consent_terms') AND ENGINE <> 'InnoDB');
SET @ok = @base_ok AND @engine_ok;
SELECT IF(@ok, 'PRECHECK OK', 'BLOQUEADO: base incompleta ou tabelas fora de InnoDB. Nenhuma alteração será aplicada.') AS preflight;
SET @q = IF(@ok, 'CREATE TABLE IF NOT EXISTS clinic_protocol_shares (id INT AUTO_INCREMENT PRIMARY KEY, protocol_version_id INT NOT NULL, source_clinic_id INT NOT NULL, target_clinic_id INT NOT NULL, shared_by_user_id INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(protocol_version_id,target_clinic_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', 'SELECT ''BLOQUEADO'' AS resultado');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clinical_protocols' AND COLUMN_NAME='clinic_id');
SET @q = IF(@ok AND NOT @exists, 'ALTER TABLE clinical_protocols ADD COLUMN clinic_id INT NULL', 'SELECT ''Sem alteração de clinical_protocols'' AS resultado');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='preconsultation_questionnaires' AND COLUMN_NAME='access_hash');
SET @q = IF(@ok AND NOT @exists, 'ALTER TABLE preconsultation_questionnaires ADD COLUMN access_hash VARCHAR(64) NULL', 'SELECT ''Sem alteração de access_hash'' AS resultado');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='preconsultation_questionnaires' AND COLUMN_NAME='answer_revision');
SET @q = IF(@ok AND NOT @exists, 'ALTER TABLE preconsultation_questionnaires ADD COLUMN answer_revision INT NOT NULL DEFAULT 0', 'SELECT ''Sem alteração de answer_revision'' AS resultado');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ready = @ok AND (SELECT COUNT(*)=1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clinical_protocols' AND COLUMN_NAME='clinic_id') AND (SELECT COUNT(*)=2 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='preconsultation_questionnaires' AND COLUMN_NAME IN ('access_hash','answer_revision')) AND (SELECT COUNT(*)=1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clinic_protocol_shares');
SET @q = IF(@ready, 'INSERT IGNORE INTO migrations (migration) VALUES (''2026_11_03_000079_extend_adaptive_consultation'')', 'SELECT ''BLOQUEADO: migração incompleta'' AS resultado');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT IF(@ready, 'MIGRAÇÃO 79 OK. Feature flag permanece como estava.', 'BLOQUEADO: não liberar o tráfego.') AS resultado;
