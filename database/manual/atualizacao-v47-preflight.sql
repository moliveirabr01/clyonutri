-- ============================================================================
-- Clyo Nutri v47: PREFLIGHT (somente leitura), migração 66 (solicitação de exames: catálogo, favoritos, versões e atendimento)
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou. Revisado na v49.
-- Não altera nada. Rode ANTES do atualizacao-v47-completa.sql. MySQL 5.7 e 8.
-- Em produção o esperado é: "OK: PRONTO PARA MIGRAR (migração 66)".
-- ============================================================================
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());
SET @t64 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs'));
SET @c65 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME IN ('reviewed_at','issued_by_user_id','pdf_path','pdf_size','pdf_generated_at'));
SET @c66 = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_regulatory_policies' AND COLUMN_NAME IN ('change_summary')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME IN ('clinic_id', 'origin', 'validation_status', 'submitted_by_user_id', 'validated_by_user_id', 'validated_at', 'validation_note', 'standard_code_system', 'standard_code', 'clinical_review_status', 'clinical_reviewed_by', 'clinical_reviewed_at')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME IN ('appointment_id', 'supersedes_request_id', 'root_request_id', 'version_number')));
SET @t66 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_favorites');
SET @c = CONCAT('SELECT COUNT(*) INTO @m65 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000065_exam_requests_pdf_and_review''');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = CONCAT('SELECT COUNT(*) INTO @m66 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000066_exam_catalog_favorites_versions_and_links''');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

SET @c = CONCAT('SELECT
  @db AS banco,
  CASE
    WHEN @t64 < 5 THEN ''FALHA: faltam tabelas de exames (migração 64); rode antes o SQL da v46''
    WHEN @c65 < 5 OR @m65 = 0 THEN ''FALHA: migração 65 (v46) não aplicada; rode antes o atualizacao-v46-completa.sql''
    WHEN @c66 = 17 AND @t66 = 1 AND @m66 = 1 THEN ''OK: v47 já aplicada (rodar a completa de novo não muda nada)''
    WHEN @c66 > 0 OR @t66 = 1 THEN ''OK: ESTADO PARCIAL (parte da 66 já existe); a completa cria só o que falta''
    ELSE ''OK: PRONTO PARA MIGRAR (migração 66)''
  END AS situacao,
  @c66 AS colunas_da_66_de_17,
  @t66 AS tabela_favoritos,
  @m65 AS migracao_65, @m66 AS migracao_66,
  (SELECT COUNT(*) FROM `', @db, '`.`exam_requests`) AS solicitacoes_existentes,
  (SELECT COUNT(*) FROM `', @db, '`.`exam_catalog`) AS exames_no_catalogo');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
