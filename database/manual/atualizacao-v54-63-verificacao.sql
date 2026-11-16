-- ==============================================================================
-- ClyoNutri v54.63 · VERIFICAÇÃO PÓS-ATUALIZAÇÃO (SOMENTE LEITURA)
-- ==============================================================================
SET NAMES utf8mb4;

-- V1. Migrations 091 a 095 registradas (esperado: 5 linhas)
SELECT migration FROM migrations
WHERE migration IN ('2026_11_07_000091_create_production_events_table', '2026_11_11_000092_create_realtime_transcription_tables',
  '2026_11_13_000093_create_transcription_consent_requests', '2026_11_15_000094_ni2_845_seed_common_household_measures',
  '2026_11_15_000095_reteste_ux_catalog_cleanup');

-- V2. Tabelas novas (esperado: 7)
SELECT COUNT(*) AS tabelas_ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
 ('production_events', 'production_alerts', 'consultation_ai_consents', 'consultation_transcription_sessions',
  'consultation_transcription_segments', 'consultation_evolution_drafts', 'consultation_ai_consent_requests');

-- V3. Índices do consentimento (esperado: 3)
SELECT COUNT(DISTINCT INDEX_NAME) AS indices_consentimento FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests'
  AND INDEX_NAME IN ('uq_ai_consent_req_token', 'uq_ai_consent_req_revoke_token', 'idx_ai_consent_req_appt');

-- V4. Medidas estimadas (esperado: centenas; compare medidas_total com o P5 do preflight)
SELECT COUNT(*) AS medidas_total,
       SUM(source = 'Estimativa ClyoNutri (revisar)') AS medidas_estimadas
FROM food_household_measures;

-- V5. Amostra: pão francês, mamão, arroz e frango com medida (esperado: linhas com unidade, fatia, colher de sopa, filé)
SELECT f.name, m.measure_name, m.grams_equivalent, m.source
FROM food_household_measures m JOIN foods f ON f.id = m.food_id
WHERE f.clinic_id IS NULL AND f.name IN ('Pão, trigo, francês', 'Mamão, Papaia, cru', 'Arroz branco cozido', 'Peito de frango grelhado', 'Frango, peito, sem pele, grelhado')
ORDER BY f.name, m.measure_name;

-- V6. Grãos crus sem medida de pronto (esperado: 0)
SELECT COUNT(*) AS cru_com_concha FROM food_household_measures m JOIN foods f ON f.id = m.food_id
WHERE m.measure_name IN ('concha', 'escumadeira', 'pegador') AND f.name LIKE '%cru%'
  AND (f.name LIKE 'Arroz%' OR f.name LIKE 'Feij%' OR f.name LIKE 'Macarr%' OR f.name LIKE 'Lentilha%');
