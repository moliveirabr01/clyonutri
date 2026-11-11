-- Selecionar e conferir o banco. Script direcionado explicitamente a maic6824_Clyonutri2.
-- Requer a migração 69; pausar cron durante a atualização. Não altera pedidos ou ofertas.
CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_waitlist_worker` (
 id INT PRIMARY KEY,
 cursor_created VARCHAR(19) NOT NULL DEFAULT '1000-01-01 00:00:00',
 cursor_id INT NOT NULL DEFAULT 0,
 pass_max_id INT NOT NULL DEFAULT 0,
 resume_id INT NULL,
 resume_date VARCHAR(10) NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `maic6824_Clyonutri2`.`booking_waitlist_worker` (id,updated_at) VALUES (1,CURRENT_TIMESTAMP);
SET @waitlist_index_sql=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='booking_waitlist' AND INDEX_NAME='idx_waitlist_matching_fifo')=0,
 'CREATE INDEX idx_waitlist_matching_fifo ON `maic6824_Clyonutri2`.`booking_waitlist` (clinic_id,professional_id,status,created_at,id)',
 'SELECT 1');
PREPARE waitlist_index_stmt FROM @waitlist_index_sql;
EXECUTE waitlist_index_stmt;
DEALLOCATE PREPARE waitlist_index_stmt;
INSERT INTO `maic6824_Clyonutri2`.`migrations` (migration)
SELECT '2026_11_01_000070_bound_waitlist_processing'
WHERE NOT EXISTS (SELECT 1 FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000070_bound_waitlist_processing');
SELECT 'Migração 70 registrada' AS resultado;
