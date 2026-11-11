-- Clyo Nutri v54.26: SQL COMPLETO e cumulativo das migrações 84, 85 e 86 (v54.22, v54.23 e v54.25).
-- A v54.26 não traz migração nova: para sair da v54.23 de produção (84 e 85 já aplicadas) falta só a 86.
-- Aplicar só após backup, com a base na migração 83 (v54.21). Cada bloco confere a própria base, só faz o que falta e
-- termina com uma linha OK ou BLOQUEADO. Rodar duas vezes não muda nada. MySQL 5.7 e 8. Sem chave estrangeira.
-- A v54.24 (design system e NI2-233) e a v54.26 (NI2-684, NI2-685 e item Consulta do contexto clínico) não têm migração.
-- Se a 84 e a 85 já estiverem aplicadas (v54.23 em produção), os dois primeiros blocos só conferem e registram OK.

-- ===== Migração 84 (v54.22) =====
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

-- ===== Migração 85 (v54.23) =====
-- Clyo Nutri v54.23 · Migração 85: lâminas para pacientes na Clyo Academy.
-- Aplicar só após backup, com a base na migração 84 (v54.22). Idempotente; aditiva; sem chave estrangeira; não apaga dados; não grava lâmina nenhuma.
--  1. academy_handouts: uma lâmina (PDF de uma página) com prévia opcional, título, resumo, etiquetas, fonte e situação (draft, published, archived).
--     Os arquivos ficam em storage/app/academy/laminas/, fora da pasta pública; o banco guarda nome sorteado, tamanho e SHA-256.
--  2. academy_handout_favorites: favoritos das lâminas por pessoa e clínica (a tabela academy_favorites dos artigos não muda).
--  3. índices: slug único, situação, favorito único por pessoa e lâmina, lâmina.
SET @db=DATABASE();
SET @base=(SELECT COUNT(*)=3 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND ENGINE='InnoDB' AND TABLE_NAME IN ('academy_articles','academy_favorites','migrations'))
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_articles' AND COLUMN_NAME='status')=1;
SELECT IF(@base,'Base 84 encontrada: aplicando a 85','BLOQUEADO: exige a migração 84 (v54.22) e InnoDB') AS preflight;
SET @q=IF(@base,'CREATE TABLE IF NOT EXISTS academy_handouts (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(120) NOT NULL, title VARCHAR(200) NOT NULL, summary TEXT NULL, tags VARCHAR(500) NULL, source_citation TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT ''draft'', file_name VARCHAR(160) NOT NULL, mime_type VARCHAR(60) NOT NULL, size_bytes INT NOT NULL, sha256 CHAR(64) NOT NULL, storage_name VARCHAR(120) NOT NULL, preview_storage_name VARCHAR(120) NULL, preview_mime VARCHAR(60) NULL, preview_size INT NULL, preview_sha256 CHAR(64) NULL, created_by_user_id INT NOT NULL, updated_by_user_id INT NULL, published_at DATETIME NULL, published_by_user_id INT NULL, archived_reason VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base,'CREATE TABLE IF NOT EXISTS academy_handout_favorites (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, user_id INT NOT NULL, handout_id INT NOT NULL, collection_name VARCHAR(100) NOT NULL DEFAULT ''Geral'', created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_handouts' AND INDEX_NAME='uq_academy_handouts_slug')=0,'CREATE UNIQUE INDEX uq_academy_handouts_slug ON academy_handouts (slug)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_handouts' AND INDEX_NAME='idx_academy_handouts_status')=0,'CREATE INDEX idx_academy_handouts_status ON academy_handouts (status)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_handout_favorites' AND INDEX_NAME='uq_academy_hfav_user')=0,'CREATE UNIQUE INDEX uq_academy_hfav_user ON academy_handout_favorites (clinic_id, user_id, handout_id)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_handout_favorites' AND INDEX_NAME='idx_academy_hfav_handout')=0,'CREATE INDEX idx_academy_hfav_handout ON academy_handout_favorites (handout_id)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ready=@base
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND ENGINE='InnoDB' AND TABLE_NAME IN ('academy_handouts','academy_handout_favorites'))=2
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='academy_handouts' AND COLUMN_NAME IN ('slug','status','sha256','storage_name','preview_storage_name','published_at','archived_reason'))=7
  AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND INDEX_NAME IN ('uq_academy_handouts_slug','idx_academy_handouts_status','uq_academy_hfav_user','idx_academy_hfav_handout'))=4;
SET @q=IF(@ready,'INSERT IGNORE INTO migrations(migration) VALUES(''2026_11_03_000085_create_academy_handouts'')','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SELECT IF(@ready,'MIGRAÇÃO 85 OK (lâminas para pacientes na Clyo Academy)','BLOQUEADO: migração 85 incompleta, não liberar o tráfego') AS resultado;

-- ===== Migração 86 (v54.25, NI2-220): resposta "não avaliada" na anamnese estruturada =====
-- Exige a 73 (colunas estruturadas da anamnese). Idempotente; sem chave estrangeira; não apaga linha nenhuma.
--  1. has_surgeries, medication_usage, supplement_usage, intolerance_assessment, alcohol_consumption, smoking_status e
--     physical_activity passam a aceitar NULL, com NULL como padrão (NULL = não avaliado). Antes eram NOT NULL com a
--     resposta negativa como padrão ("não", "nenhum", "não fumante", "sedentário").
--  2. Só na primeira aplicação (86 ainda não registrada): anamneses com structured_data_json NULL, gravadas antes do
--     formulário estruturado, receberam essas negativas apenas pelo padrão da 73. Essas voltam para NULL. As salvas
--     com o formulário estruturado ficam como estão (não dá para separar o que a nutricionista marcou do que veio
--     pré-selecionado). Nenhum texto livre é alterado.
--  3. anamneses.updated_at e anamneses.updated_by_professional_id: última edição do rascunho, sem trocar autor e data de início.
SET @db=DATABASE();
SET @base86=(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('anamneses','migrations'))=2
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME IN ('has_surgeries','medication_usage','supplement_usage','intolerance_assessment','alcohol_consumption','smoking_status','physical_activity','structured_data_json'))=8;
SELECT IF(@base86,'Base 73 encontrada: aplicando a 86','BLOQUEADO: exige a migração 73 (anamnese estruturada)') AS preflight;
SET @done86=0;
SET @q=IF(@base86,'SELECT COUNT(*) INTO @done86 FROM migrations WHERE migration=''2026_11_03_000086_anamnesis_unanswered_state''','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @first86=@base86 AND @done86=0;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='has_surgeries' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN has_surgeries VARCHAR(10) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='medication_usage' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN medication_usage VARCHAR(20) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='supplement_usage' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN supplement_usage VARCHAR(20) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='intolerance_assessment' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN intolerance_assessment VARCHAR(20) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='alcohol_consumption' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN alcohol_consumption VARCHAR(20) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='smoking_status' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN smoking_status VARCHAR(20) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='physical_activity' AND (IS_NULLABLE<>'YES' OR COLUMN_DEFAULT IS NOT NULL))=1,'ALTER TABLE anamneses MODIFY COLUMN physical_activity VARCHAR(20) NULL DEFAULT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@first86,'UPDATE anamneses SET has_surgeries=NULL, medication_usage=NULL, supplement_usage=NULL, intolerance_assessment=NULL, alcohol_consumption=NULL, smoking_status=NULL, physical_activity=NULL WHERE structured_data_json IS NULL AND (has_surgeries IS NOT NULL OR medication_usage IS NOT NULL OR supplement_usage IS NOT NULL OR intolerance_assessment IS NOT NULL OR alcohol_consumption IS NOT NULL OR smoking_status IS NOT NULL OR physical_activity IS NOT NULL)','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='updated_at')=0,'ALTER TABLE anamneses ADD COLUMN updated_at DATETIME NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @q=IF(@base86 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME='updated_by_professional_id')=0,'ALTER TABLE anamneses ADD COLUMN updated_by_professional_id INT NULL','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SET @ready86=@base86
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME IN ('has_surgeries','medication_usage','supplement_usage','intolerance_assessment','alcohol_consumption','smoking_status','physical_activity') AND IS_NULLABLE='YES' AND COLUMN_DEFAULT IS NULL)=7
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='anamneses' AND COLUMN_NAME IN ('updated_at','updated_by_professional_id'))=2;
SET @q=IF(@ready86,'INSERT IGNORE INTO migrations(migration) VALUES(''2026_11_03_000086_anamnesis_unanswered_state'')','DO 0');
PREPARE stmt FROM @q;EXECUTE stmt;DEALLOCATE PREPARE stmt;
SELECT IF(@ready86,'MIGRAÇÃO 86 OK (anamnese com resposta "não avaliado")','BLOQUEADO: migração 86 incompleta, não liberar o tráfego') AS resultado;
