-- ============================================================================
-- ClyoNutri v32: PREFLIGHT (somente leitura) — clínicas sem assinatura (ex.: "Sem assinatura")
-- Rode ANTES do assinaturas-faltantes-completa.sql. Não altera nada.
-- Compatível com MySQL 5.7.
--
-- O que o completa vai fazer: criar UMA linha em clinic_subscriptions (plano de teste padrão,
-- 14 dias) para cada clínica ATIVA que ainda não tem nenhuma. Clínica que já tem assinatura,
-- de qualquer status, não é tocada. Clínica suspensa também não.
--
-- ESCOLHA DA DATA-BASE (mesma linha no completa):
--   'data_criacao' (padrão) = teste de 14 dias contado da data de criação da clínica
--                              (ainda dentro do prazo = "Em teste"; já passou = "Expirada").
--   'hoje'                  = teste de 14 dias a partir do momento em que o SQL rodar, para todas.
-- A prévia abaixo mostra os dois resultados lado a lado.
-- ============================================================================
SET @db = DATABASE();
SET @modo = 'data_criacao';          -- troque para 'hoje' se for o caso (e use o mesmo valor no completa)
SET @plano = 'PRO-02';               -- plano de teste padrão do sistema (SubscriptionService::DEFAULT_TRIAL_PLAN)
SET @dias = 14;
-- Horário de Brasília calculado a partir de UTC (não depende do fuso configurado no servidor MySQL).
SET @agora = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR);

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','clinic_subscriptions','saas_plans')) = 3);
SET @tem_trial = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND COLUMN_NAME = 'trial_ends_at');
SET @plano_id = (SELECT MIN(id) FROM saas_plans WHERE code = BINARY @plano);

SELECT
  CASE
    WHEN @ok_base = 0
      THEN 'FALHA — faltam tabelas base (clinics/clinic_subscriptions/saas_plans): rode as atualizações anteriores primeiro'
    WHEN @tem_trial = 0
      THEN 'FALHA — clinic_subscriptions.trial_ends_at não existe (migração 57): aplique a v27/v28 antes'
    WHEN @modo NOT IN ('data_criacao','hoje')
      THEN 'FALHA — @modo inválido: use data_criacao ou hoje'
    WHEN @plano_id IS NULL
      THEN CONCAT('FALHA — o plano ', @plano, ' não existe em saas_plans: o completa NÃO vai inserir nada')
    WHEN (SELECT COUNT(*) FROM clinics c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)) = 0
      THEN 'OK — nenhuma clínica ativa sem assinatura (rodar o completa não muda nada)'
    ELSE 'OK — PRONTO: há clínicas ativas sem assinatura para regularizar'
  END AS situacao,
  @modo AS modo,
  @plano AS plano_codigo,
  IF(@plano_id IS NULL, 0, 1) AS plano_existe,
  (SELECT name FROM saas_plans WHERE id = @plano_id) AS plano_nome,
  (SELECT price_cents FROM saas_plans WHERE id = @plano_id) AS plano_preco_centavos,
  (SELECT billing_cycle FROM saas_plans WHERE id = @plano_id) AS plano_ciclo,
  (SELECT COUNT(*) FROM clinics) AS clinicas_total,
  (SELECT COUNT(*) FROM clinics WHERE status = 'active') AS clinicas_ativas,
  (SELECT COUNT(*) FROM clinic_subscriptions) AS assinaturas_existentes,
  (SELECT COUNT(*) FROM clinics c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)) AS ativas_sem_assinatura,
  (SELECT COUNT(*) FROM clinics c WHERE c.status <> 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)) AS inativas_sem_assinatura_ignoradas;

-- Quantas ficariam "Em teste" e quantas "Expirada" em cada modo.
SELECT
  SUM(IF(DATE_ADD(COALESCE(c.created_at, t.agora), INTERVAL @dias DAY) >= t.agora, 1, 0)) AS modo_data_criacao_em_teste,
  SUM(IF(DATE_ADD(COALESCE(c.created_at, t.agora), INTERVAL @dias DAY) <  t.agora, 1, 0)) AS modo_data_criacao_expiradas,
  COUNT(*) AS modo_hoje_em_teste,
  0 AS modo_hoje_expiradas
FROM clinics c JOIN (SELECT CAST(@agora AS DATETIME) AS agora) t
WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id);

-- Prévia clínica a clínica (as 500 mais recentes; o completa trata todas).
SELECT
  c.id AS clinica_id,
  c.name AS clinica,
  c.created_at AS criada_em,
  IF(DATE_ADD(COALESCE(c.created_at, t.agora), INTERVAL @dias DAY) >= t.agora, 'trialing (Em teste)', 'expired (Expirada)') AS status_se_data_criacao,
  DATE_ADD(COALESCE(c.created_at, t.agora), INTERVAL @dias DAY) AS teste_ate_se_data_criacao,
  'trialing (Em teste)' AS status_se_hoje,
  DATE_ADD(t.agora, INTERVAL @dias DAY) AS teste_ate_se_hoje
FROM clinics c JOIN (SELECT CAST(@agora AS DATETIME) AS agora) t
WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)
ORDER BY c.id DESC
LIMIT 500;
