-- Banco explícito para evitar troca de contexto do phpMyAdmin para information_schema.
-- Para outro banco, substituir maic6824_Clyonutri2 em TODO este arquivo.
-- Somente leitura. Conferir no banco selecionado.
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2'
AND TABLE_NAME IN ('booking_calendar_policies','booking_calendar_exceptions','booking_waitlist') ORDER BY TABLE_NAME;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='maic6824_Clyonutri2'
AND TABLE_NAME IN ('booking_calendar_policies','booking_calendar_exceptions','booking_waitlist') ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2'
AND TABLE_NAME IN ('booking_calendar_exceptions','booking_waitlist') ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
SELECT migration,executed_at FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000069_create_booking_calendar_and_waitlist';
-- Esperado: três tabelas InnoDB, índices de calendário e fila, índice único (clinic_id,active_key), uma linha da migração 69.
-- Não são inseridos feriados, buffers ou inscrições fictícias. A lista de espera inicia desabilitada.
