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
