-- Somente leitura. O cursor pode estar avançado se o cron já estiver ativo.
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='booking_waitlist_worker';
SELECT * FROM `maic6824_Clyonutri2`.`booking_waitlist_worker` WHERE id=1;
SELECT INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='booking_waitlist' AND INDEX_NAME='idx_waitlist_matching_fifo' ORDER BY SEQ_IN_INDEX;
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000070_bound_waitlist_processing';
-- Esperado: tabela InnoDB, uma linha de estado id=1, índice de cinco colunas e registro da migração 70.
