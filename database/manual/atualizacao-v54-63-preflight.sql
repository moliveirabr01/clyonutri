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

-- P3. Tabelas que esta atualização cria ou exige (1 = existe)
SELECT 'production_events' AS tabela, COUNT(*) AS existe FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_events'
UNION ALL SELECT 'production_alerts', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_alerts'
UNION ALL SELECT 'consultation_ai_consents', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consents'
UNION ALL SELECT 'consultation_transcription_sessions', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_transcription_sessions'
UNION ALL SELECT 'consultation_ai_consent_requests', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests'
UNION ALL SELECT 'food_household_measures', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures';

-- P4. Colunas usadas pelas migrations 94 e 95 (esperado: 4 linhas). Se faltar alguma, PARE: o banco está
--     anterior à migration 63 e precisa das atualizações antigas antes desta.
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source', 'source_version'))
    OR (TABLE_NAME = 'foods' AND COLUMN_NAME IN ('is_current', 'taco_code')));

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
