-- ============================================================================
-- Clyo Nutri v48: VERIFICAÇÃO (somente leitura), depois do atualizacao-v48-completa.sql
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou. Revisado na v49.
-- Cada linha deve dar OK. Não altera nada. MySQL 5.7 e 8.
-- ============================================================================
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());
SET @c = CONCAT('SELECT ''banco'' AS item, @db AS resultado
UNION ALL SELECT ''tabelas de laudos (2)'', IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN (''exam_result_documents'',''exam_result_document_items'')) = 2, ''OK'', ''FALHA'')
UNION ALL SELECT ''colunas de exam_result_documents (26)'', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'') = 26, ''OK'', ''FALHA'')
UNION ALL SELECT ''colunas de exam_result_document_items (4)'', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'') = 4, ''OK'', ''FALHA'')
UNION ALL SELECT ''índices (5)'', IF(((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_request'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_patient'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND INDEX_NAME = ''idx_exam_result_docs_supersedes'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''uq_exam_result_doc_item'') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''idx_exam_result_doc_item_item'')) = 5, ''OK'', ''FALHA'')
UNION ALL SELECT ''exame coberto uma vez por laudo (índice único)'', IF((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_document_items'' AND INDEX_NAME = ''uq_exam_result_doc_item'' AND NON_UNIQUE = 0) = 1, ''OK'', ''FALHA'')
UNION ALL SELECT ''ids em BIGINT(20)'', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''exam_result_documents'' AND COLUMN_NAME IN (''id'',''clinic_id'',''patient_id'',''exam_request_id'',''uploaded_by_user_id'',''uploaded_by_patient_id'',''reviewed_by_user_id'',''supersedes_document_id'',''root_document_id'') AND COLUMN_TYPE = ''bigint(20)'') = 9, ''OK'', ''FALHA'')
UNION ALL SELECT ''migração 67 registrada uma vez'', IF((SELECT COUNT(*) FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000067_exam_result_documents'') = 1, ''OK'', ''FALHA'')
UNION ALL SELECT ''sem chave estrangeira nas tabelas de laudos'', IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME IN (''exam_result_documents'',''exam_result_document_items'')) = 0, ''OK'', ''FALHA'')');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
