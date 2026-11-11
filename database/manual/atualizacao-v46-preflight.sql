-- ============================================================================
-- ClyoNutri II v46: PREFLIGHT (somente leitura), migrações 64 e 65 (pedido de exames, NI2-529)
-- Não altera nada. Rode ANTES do atualizacao-v46-completa.sql. MySQL 5.7 e 8.
-- Em produção o esperado é: "migração 64 já aplicada (como em produção); a completa acrescenta a 65".
-- ============================================================================
SET @db = DATABASE();
SET @t64 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs'));
SET @c65 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME IN ('reviewed_at','issued_by_user_id','pdf_path','pdf_size','pdf_generated_at'));
SET @m63 = (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets');
SET @m64 = (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000064_create_laboratory_exam_requests_and_catalog');
SET @m65 = (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000065_exam_requests_pdf_and_review');

SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','users','patients','professionals','migrations')) < 5
      THEN 'FALHA: faltam tabelas base do sistema; aplique as atualizações anteriores primeiro'
    WHEN @m63 = 0 THEN 'FALHA: migração 63 (v43) não registrada; rode a v43 antes'
    WHEN @t64 = 5 AND @m64 = 1 AND @c65 = 5 AND @m65 = 1 THEN 'OK: v46 já aplicada (rodar a completa de novo não muda nada)'
    WHEN @t64 = 5 AND @m64 = 1 THEN 'OK: migração 64 já aplicada (como em produção); a completa acrescenta a 65'
    WHEN @t64 = 5 THEN 'OK: tabelas da 64 existem, mas a 64 não está registrada; a completa só registra e acrescenta a 65'
    WHEN @t64 > 0 THEN 'OK: ESTADO PARCIAL (algumas tabelas de exames já existem); a completa cria só o que falta'
    ELSE 'OK: PRONTO PARA MIGRAR (a completa cria a 64 e a 65)'
  END AS situacao,
  @t64 AS tabelas_exames_de_5,
  @c65 AS colunas_da_65_de_5,
  @m63 AS migracao_63, @m64 AS migracao_64, @m65 AS migracao_65;

-- Política regulatória (só informativo: a v46 não lê is_approved_* e a completa não mexe nessa tabela)
SET @q = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_regulatory_policies') = 1, 'SELECT policy_version, is_approved_clinical, is_approved_legal, ''não usado pela v46'' AS observacao FROM exam_regulatory_policies ORDER BY id', 'SELECT ''sem tabela de políticas: a completa cria'' AS politica'));
PREPARE stmt_q FROM @q; EXECUTE stmt_q; DEALLOCATE PREPARE stmt_q;

-- Pedidos já gravados (a v46 não muda nenhum; emitidos antes da 65 ficam sem PDF guardado e continuam imprimíveis)
SET @q = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests') = 1, 'SELECT status, COUNT(*) AS pedidos FROM exam_requests GROUP BY status ORDER BY status', 'SELECT ''sem tabela de pedidos'' AS pedidos'));
PREPARE stmt_q FROM @q; EXECUTE stmt_q; DEALLOCATE PREPARE stmt_q;
