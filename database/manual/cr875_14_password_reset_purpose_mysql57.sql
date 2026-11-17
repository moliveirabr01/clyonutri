-- CR875-14 / NI2-890 (v54.76) — migração 101: tipo do link em password_resets ('reset' ou 'invite'). MySQL 5.7+, idempotente.
-- Selecione o banco do sistema no phpMyAdmin antes de executar. Pode rodar mais de uma vez.
-- Links já enviados (purpose NULL) continuam valendo pela regra anterior até expirarem.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'purpose');
SET @sql := IF(@c = 0, 'ALTER TABLE password_resets ADD COLUMN purpose VARCHAR(10) NULL', 'SELECT ''purpose já existe'' AS cr875_14');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO migrations (migration)
SELECT '2026_11_17_000101_cr875_14_password_reset_purpose' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_17_000101_cr875_14_password_reset_purpose');

-- Conferência: 1 linha (purpose varchar(10)).
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'purpose';
