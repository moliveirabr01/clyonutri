-- ==============================================================================
-- ClyoNutri v54.63 · PREFLIGHT (SOMENTE LEITURA)
-- Rode ANTES de atualizacao-v54-63-completa.sql, no phpMyAdmin, com o banco de produção selecionado.
-- Não altera nada. Copie o resultado de cada consulta para o registro do deploy.
-- Compatível com MySQL 5.7.44.
-- ==============================================================================
SET NAMES utf8mb4;

-- P1. Versão e banco selecionado (esperado: 5.7.x e o banco do ClyoNutri)
SELECT VERSION() AS versao_mysql, DATABASE() AS banco;

-- P2. Migrations recentes já registradas (dump de produção de 15/11/2026: 085 a 088, 090 e 091; a 089 e a 092 estão
--     aplicadas sem registro e são registradas pela atualização; 093, 094 e 095 faltam)
SELECT migration FROM migrations
WHERE migration >= '2026_11_03_000085' ORDER BY migration;

-- P3. Tabelas que esta atualização cria ou exige (1 = existe; esperado: consultation_ai_consent_requests = 0, demais = 1)
-- (consultas ao information_schema vão por variável: no phpMyAdmin, um SELECT direto nele muda o banco das consultas seguintes)
SET @t_pe := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events');
SET @t_pa := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_alerts');
SET @t_ac := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consents');
SET @t_ts := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_transcription_sessions');
SET @t_cr := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests');
SET @t_hm := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures');
SELECT @t_pe AS production_events, @t_pa AS production_alerts, @t_ac AS consultation_ai_consents,
       @t_ts AS consultation_transcription_sessions, @t_cr AS consultation_ai_consent_requests, @t_hm AS food_household_measures;

-- P4. Colunas usadas pelas migrations 94 e 95 (esperado: 4). Se vier menos, PARE: o banco está
--     anterior à migration 63 e precisa das atualizações antigas antes desta.
SET @cols := (SELECT COUNT(*) FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source', 'source_version'))
    OR (TABLE_NAME = 'foods' AND COLUMN_NAME IN ('is_current', 'taco_code'))));
SELECT @cols AS colunas_necessarias;

-- P5. Catálogo e medidas antes da atualização (anote os números para comparar na verificação)
SELECT
  (SELECT COUNT(*) FROM foods WHERE clinic_id IS NULL) AS alimentos_globais,
  (SELECT COUNT(*) FROM food_household_measures) AS medidas_total,
  (SELECT COUNT(*) FROM food_household_measures WHERE source = 'Estimativa ClyoNutri (revisar)') AS medidas_estimadas;

-- P6. Sinais de que a v54.62 já rodou neste banco (esperado: 0 e 0). Se der diferente de zero, PARE e avise:
--     a limpeza completa da migration 95 precisa do bin/migrate.php.
SELECT
  (SELECT COUNT(*) FROM food_household_measures WHERE source IS NULL AND created_at >= '2026-11-15 00:00:00') AS medidas_sem_origem_v5462,
  (SELECT COUNT(*) FROM foods WHERE clinic_id IS NULL AND taco_code IS NULL AND name = 'Frango, peito, sem pele, grelhado') AS copia_frango;
