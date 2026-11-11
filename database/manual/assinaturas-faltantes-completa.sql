-- ============================================================================
-- ClyoNutri v32: ASSINATURAS FALTANTES — cria o teste de 14 dias das clínicas sem assinatura
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do
-- assinaturas-faltantes-preflight.sql. SOMENTE COM O OK DO RESPONSÁVEL.
--
-- Por que existe: clínicas criadas antes da regra que cria o teste no cadastro ficaram sem
-- linha em clinic_subscriptions e aparecem como "Sem assinatura" no painel (Comercial e Clínicas).
--
-- O que faz: INSERE uma linha para cada clínica ATIVA que NÃO tem nenhuma assinatura.
--   * Plano: o de teste padrão (@plano = PRO-02), com preço e ciclo copiados de saas_plans
--     (igual ao SubscriptionService::initializeDefaultSubscription).
--   * Não cria fatura, não cobra, não manda e-mail.
--   * Clínica que já tem assinatura (active, trialing, expired ou canceled) NÃO é tocada.
--   * Clínica suspensa NÃO recebe (se for reativada, o sistema cria o teste sozinho na primeira
--     visita a Configurações > Assinatura).
--   * Não existe UPDATE nem DELETE neste arquivo.
-- Idempotente: rodar de novo não muda nada (a segunda vez insere 0 linhas).
-- Se o plano não existir em saas_plans, não insere nada e a situação final avisa.
-- Compatível com MySQL 5.7 (nenhum ADD COLUMN IF NOT EXISTS).
--
-- ESCOLHA DA DATA-BASE (altere só a linha SET @modo abaixo):
--   'data_criacao' (padrão): o teste de 14 dias conta da criação da clínica. Se ainda está no prazo,
--                            fica "trialing" (Em teste); se o prazo já passou, fica "expired" (Expirada).
--   'hoje'                 : o teste de 14 dias conta de agora, para todas ("trialing").
-- No código atual "expired" não bloqueia nada (o aviso da tela diz que o uso continua normal), mas
-- aparece o aviso vermelho em Configurações > Assinatura e a clínica sai do "Em teste" no Comercial.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @modo = 'data_criacao';          -- 'data_criacao' ou 'hoje'
SET @plano = 'PRO-02';               -- plano de teste padrão do sistema
SET @dias = 14;
-- Horário de Brasília calculado a partir de UTC (mesmo fuso gravado pelo sistema; não depende do fuso do MySQL).
SET @agora = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR);

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','clinic_subscriptions','saas_plans')) = 3);
SET @tem_trial = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND COLUMN_NAME = 'trial_ends_at');
SET @plano_id = IF(@ok_base = 1, (SELECT MIN(id) FROM saas_plans WHERE code = BINARY @plano), NULL);
SET @pode = (@ok_base = 1 AND @tem_trial = 1 AND @modo IN ('data_criacao','hoje') AND @plano_id IS NOT NULL);

SET @antes = IF(@ok_base = 1 AND @tem_trial = 1, (SELECT COUNT(*) FROM clinic_subscriptions), NULL);

-- Inserção (só roda se tudo acima estiver certo; senão vira "DO 0" e não faz nada).
SET @q = IF(@pode = 1, CONCAT(
  'INSERT INTO clinic_subscriptions ',
  '(clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end, trial_ends_at, created_at, updated_at) ',
  'SELECT c.id, p.id, ',
  'IF(DATE_ADD(IF(@modo = ''hoje'', t.agora, COALESCE(c.created_at, t.agora)), INTERVAL @dias DAY) >= t.agora, ''trialing'', ''expired''), ',
  'p.billing_cycle, p.price_cents, ',
  'IF(@modo = ''hoje'', t.agora, COALESCE(c.created_at, t.agora)), ',
  'IF(@modo = ''hoje'', t.agora, COALESCE(c.created_at, t.agora)), ',
  'DATE_ADD(IF(@modo = ''hoje'', t.agora, COALESCE(c.created_at, t.agora)), INTERVAL @dias DAY), ',
  'DATE_ADD(IF(@modo = ''hoje'', t.agora, COALESCE(c.created_at, t.agora)), INTERVAL @dias DAY), ',
  't.agora, t.agora ',
  'FROM clinics c JOIN saas_plans p ON p.id = @plano_id JOIN (SELECT CAST(@agora AS DATETIME) AS agora) t ',
  'WHERE c.status = ''active'' ',
  'AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)'
), 'DO 0');
PREPARE s FROM @q; EXECUTE s; SET @inseridas = ROW_COUNT(); DEALLOCATE PREPARE s;
SET @inseridas = IF(@pode = 1, @inseridas, 0);

-- Situação final e conferência.
SELECT
  CASE
    WHEN @ok_base = 0 THEN 'FALHA — faltam tabelas base (clinics/clinic_subscriptions/saas_plans); nada foi inserido'
    WHEN @tem_trial = 0 THEN 'FALHA — clinic_subscriptions.trial_ends_at não existe (migração 57); nada foi inserido'
    WHEN @modo NOT IN ('data_criacao','hoje') THEN 'FALHA — @modo inválido (use data_criacao ou hoje); nada foi inserido'
    WHEN @plano_id IS NULL THEN CONCAT('FALHA — o plano ', @plano, ' não existe em saas_plans; nada foi inserido')
    WHEN @inseridas = 0 THEN 'OK — nada a fazer: todas as clínicas ativas já têm assinatura'
    ELSE CONCAT('OK — ', @inseridas, ' assinatura(s) de teste criada(s)')
  END AS situacao,
  @modo AS modo,
  @inseridas AS assinaturas_criadas_agora,
  @antes AS assinaturas_antes,
  (SELECT COUNT(*) FROM clinic_subscriptions) AS assinaturas_depois,
  (SELECT COUNT(*) FROM clinics c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM clinic_subscriptions s WHERE s.clinic_id = c.id)) AS ativas_ainda_sem_assinatura_esperado_0;

-- Contagem por status (conferência final; o arquivo assinaturas-faltantes-verificacao.sql repete isto).
SELECT status, COUNT(*) AS clinicas FROM clinic_subscriptions GROUP BY status ORDER BY status;
