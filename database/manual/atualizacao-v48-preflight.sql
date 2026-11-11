-- ============================================================================
-- Clyo Nutri v48: PREFLIGHT (somente leitura), migração 67 (laudos anexados à solicitação de exames, NI2-539)
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou. Revisado na v49.
-- Não altera nada. Rode ANTES do atualizacao-v48-completa.sql. MySQL 5.7 e 8.
-- Em produção o esperado é: "OK: PRONTO PARA MIGRAR (migração 67)".
-- ============================================================================
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());
SET @c = CONCAT('SELECT COUNT(*) INTO @c66 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000066_exam_catalog_favorites_versions_and_links''');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = CONCAT('SELECT COUNT(*) INTO @m67 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000067_exam_result_documents''');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @t67 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_result_documents','exam_result_document_items'));
SET @c = CONCAT('SELECT
  @db AS banco,
  CASE
    WHEN @c66 = 0 THEN ''FALHA: migração 66 (v47) não registrada; rode antes o atualizacao-v47-completa.sql''
    WHEN @t67 = 2 AND @m67 = 1 THEN ''OK: v48 já aplicada (rodar a completa de novo não muda nada)''
    WHEN @t67 > 0 THEN ''OK: ESTADO PARCIAL (parte da 67 já existe); a completa cria só o que falta''
    ELSE ''OK: PRONTO PARA MIGRAR (migração 67)''
  END AS situacao,
  @t67 AS tabelas_de_laudos_de_2,
  @c66 AS migracao_66, @m67 AS migracao_67,
  (SELECT COUNT(*) FROM `', @db, '`.`exam_requests` WHERE `status` = ''issued'') AS solicitacoes_emitidas');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
