-- ============================================================================
-- ClyoNutri v32: VERIFICAÇÃO (somente leitura) — assinaturas das clínicas
-- Rode DEPOIS do assinaturas-faltantes-completa.sql (ou quando quiser conferir). Não altera nada.
-- Compatível com MySQL 5.7.
-- Resultado esperado: ativas_sem_assinatura = 0 e assinaturas_duplicadas = 0.
-- ============================================================================
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM clinics c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)) = 0
      THEN 'OK — todas as clínicas ativas têm assinatura'
    ELSE 'ATENÇÃO — ainda há clínica ativa sem assinatura (rode o preflight para ver quais)'
  END AS situacao,
  (SELECT COUNT(*) FROM clinics) AS clinicas_total,
  (SELECT COUNT(*) FROM clinics WHERE status = 'active') AS clinicas_ativas,
  (SELECT COUNT(*) FROM clinic_subscriptions) AS assinaturas_total,
  (SELECT COUNT(*) FROM clinics c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)) AS ativas_sem_assinatura,
  (SELECT COUNT(*) FROM (SELECT clinic_id FROM clinic_subscriptions GROUP BY clinic_id HAVING COUNT(*) > 1) d) AS assinaturas_duplicadas,
  (SELECT COUNT(*) FROM clinic_subscriptions WHERE status = 'trialing' AND trial_ends_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR)) AS em_teste_com_prazo_vencido_o_cron_ajusta;

SELECT status, COUNT(*) AS clinicas FROM clinic_subscriptions GROUP BY status ORDER BY status;
