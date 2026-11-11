-- ============================================================================
-- ClyoNutri II v46: VERIFICAÇÃO (somente leitura), depois do atualizacao-v46-completa.sql
-- Cada linha deve dar OK. Não altera nada. MySQL 5.7 e 8.
-- ============================================================================
SET @db = DATABASE();
SELECT 'tabelas de exames' AS item,
  IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) = 5, 'OK', 'FALHA') AS resultado
UNION ALL SELECT 'colunas da migração 65',
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME IN ('reviewed_at','issued_by_user_id','pdf_path','pdf_size','pdf_generated_at')) = 5, 'OK', 'FALHA')
UNION ALL SELECT 'emissor do pedido em BIGINT(20)',
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'issued_by_user_id' AND COLUMN_TYPE = 'bigint(20)') = 1, 'OK', 'FALHA')
UNION ALL SELECT 'padrão 0 na ciência do aviso de cobertura',
  IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND COLUMN_DEFAULT = '0' AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'coverage_disclaimer_acknowledged') = 1, 'OK', 'FALHA')
UNION ALL SELECT 'índice idx_exam_audit_entity',
  IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_audit_logs' AND INDEX_NAME = 'idx_exam_audit_entity') > 0, 'OK', 'FALHA')
UNION ALL SELECT 'política 2026.1',
  IF((SELECT COUNT(*) FROM `exam_regulatory_policies` WHERE `policy_version` = '2026.1') = 1, 'OK', 'FALHA')
UNION ALL SELECT 'catálogo com os 22 exames',
  IF((SELECT COUNT(*) FROM `exam_catalog` WHERE `code` IN ('HEMOGRAMA_COMPLETO','GLICEMIA_JEJUM','HEMOGLOBINA_GLICADA','INSULINA_JEJUM','PERFIL_LIPIDICO','FERRITINA_SERICA','FERRO_SERICO','VITAMINA_B12','VITAMINA_D_25OH','ACIDO_FOLICO','UREIA','CREATININA_SERICA','TGO_AST','TGP_ALT','GAMA_GT','ACIDO_URICO','PROTEINAS_TOTAIS_FRACIONADAS','SODIO_POTASSIO','MAGNESIO_SERICO','ZINCO_SERICO','TSH_T4_LIVRE','URINA_TIPO_1_EAS')) = 22, 'OK', 'FALHA')
UNION ALL SELECT 'migração 64 registrada uma vez',
  IF((SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000064_create_laboratory_exam_requests_and_catalog') = 1, 'OK', 'FALHA')
UNION ALL SELECT 'migração 65 registrada uma vez',
  IF((SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000065_exam_requests_pdf_and_review') = 1, 'OK', 'FALHA')
UNION ALL SELECT 'sem chave estrangeira nas tabelas de exames',
  IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) = 0, 'OK', 'FALHA');

-- Só informativo: a v46 não lê estas colunas para emitir, gerar o PDF ou imprimir.
SELECT policy_version, is_approved_clinical, is_approved_legal, 'não usado pela v46' AS observacao
FROM `exam_regulatory_policies` ORDER BY id;
