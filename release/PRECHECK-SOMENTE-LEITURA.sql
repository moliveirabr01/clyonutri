-- ClyoNutri II — Precheck Somente Leitura para Deploy HostGator (v50 Consolidado)
-- Selecionar o banco da aplicação no phpMyAdmin antes de executar.
-- Somente leitura: não altera nem remove nenhum dado.

SELECT DATABASE() AS banco_selecionado, VERSION() AS versao_servidor;

-- 1. Tabelas essenciais das histórias unificadas (NI2-433, NI2-487, NI2-223, NI2-514)
SELECT TABLE_NAME, ENGINE, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'appointments',
    'booking_holds',
    'booking_settings',
    'notification_events',
    'notification_deliveries',
    'anamneses',
    'meal_plans',
    'meal_plan_energy_targets',
    'clinic_household_measures',
    'patient_import_batches'
  )
ORDER BY TABLE_NAME;

-- 2. Índice único de deduplicação da fila de notificações transacionais (NI2-487)
SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'notification_events'
  AND INDEX_NAME = 'uq_notification_events_dedupe'
ORDER BY SEQ_IN_INDEX;
-- Esperado: NON_UNIQUE = 0 para clinic_id, recipient_type, recipient_id, dedupe_key.

-- 3. Verificação das colunas estruturadas da anamnese (NI2-223 / Migração 69)
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'anamneses'
  AND COLUMN_NAME IN (
    'chief_complaint', 'family_history', 'pathologies', 'has_surgeries',
    'surgeries_details', 'medication_usage', 'medications_details',
    'supplement_usage', 'supplements_details', 'intolerance_assessment',
    'alcohol_consumption', 'alcohol_details', 'smoking_status', 'smoking_details',
    'sleep_hours', 'sleep_quality', 'sleep_notes', 'physical_activity',
    'activity_modality', 'activity_frequency_weekly', 'activity_duration_minutes',
    'referenced_exams', 'structured_data_json'
  )
ORDER BY COLUMN_NAME;
-- Se as colunas acima não constarem, execute o script:
-- database/manual/atualizacao-v50-ni2-223-anamnese-estruturada.sql
