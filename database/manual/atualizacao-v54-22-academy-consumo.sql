-- Clyo Nutri v54.22 · Migração 84: Clyo Academy só para consumo nas clínicas, com curadoria da equipe Clyo Nutri.
-- Aplicar só após backup, com a base na migração 83 (v54.21). Idempotente; aditiva; sem chave estrangeira; não apaga dados; não grava conteúdo.
--  1. academy_articles.status (draft, published, archived). Os artigos que já existem ficam "published" e continuam na biblioteca.
--  2. índice idx_academy_articles_status.
--  3. liga feature_flag_academy = 1, só na primeira aplicação (se a 84 já estiver registrada, a chave fica como está).
SET @db=DATABASE();
SET @base=(SELECT COUNT(*)=5 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND ENGINE='InnoDB' AND TABLE_NAME IN ('academy_articles','educational_materials','educational_material_shares','platform_settings','migrations'));
SELECT IF(@base,'Base 83 encontrada: aplicando a 84','BLOQUEADO: exige a migração 83 (v54.21) e InnoDB') AS preflight;
SET @done84=0;
SET @q=IF(@base,'SELECT COUNT(*) INTO @done84 FROM migrations WHERE migration=''2026_11_03_000084_academy_platform_curation''','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @first=@base AND @done84=0;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_articles' AND COLUMN_NAME='status')=0,'ALTER TABLE academy_articles ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''published''','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_articles' AND INDEX_NAME='idx_academy_articles_status')=0,'CREATE INDEX idx_academy_articles_status ON academy_articles (status)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@first,'INSERT INTO platform_settings (setting_key, setting_value, description, created_at, updated_at) VALUES (''feature_flag_academy'', ''1'', ''Habilita a Clyo Academy para as clínicas (só leitura; curadoria pela equipe Clyo Nutri)'', NOW(), NOW()) ON DUPLICATE KEY UPDATE setting_value = ''1'', description = VALUES(description), updated_at = NOW()','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @flagrow=0;
SET @q=IF(@base,'SELECT COUNT(*) INTO @flagrow FROM platform_settings WHERE setting_key=''feature_flag_academy''','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ready=@base
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_articles' AND COLUMN_NAME='status' AND COLUMN_DEFAULT='published' AND IS_NULLABLE='NO')=1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_articles' AND INDEX_NAME='idx_academy_articles_status')>0
  AND @flagrow=1;
SET @q=IF(@ready,'INSERT IGNORE INTO migrations(migration) VALUES(''2026_11_03_000084_academy_platform_curation'')','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SELECT IF(@ready,'MIGRAÇÃO 84 OK (Clyo Academy só leitura nas clínicas)','BLOQUEADO: migração 84 incompleta, não liberar o tráfego') AS resultado;
