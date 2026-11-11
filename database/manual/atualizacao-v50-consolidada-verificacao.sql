-- =============================================================================
-- ClyoNutri II — VERIFICAÇÃO PÓS-DEPLOY (SOMENTE LEITURA)
-- Atualização Consolidada v50:
--   - Migração 69: Calendário, Feriados/Exceções, Buffers e Lista de Espera
--   - Migração 70: Anamnese Estruturada e Campos Condicionais (NI2-223)
-- Banco: maic6824_Clyonutri2 (substitua se o nome do schema for diferente)
-- =============================================================================

SELECT 'maic6824_Clyonutri2' AS banco_selecionado, VERSION() AS versao_servidor;

-- 1. Conferência das novas tabelas da Migração 69
SELECT TABLE_NAME, ENGINE, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2'
  AND TABLE_NAME IN ('booking_calendar_policies', 'booking_calendar_exceptions', 'booking_waitlist')
ORDER BY TABLE_NAME;

-- 2. Conferência de colunas da Migração 69
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2'
  AND TABLE_NAME IN ('booking_calendar_policies', 'booking_calendar_exceptions', 'booking_waitlist')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 3. Conferência das novas colunas de anamneses da Migração 70
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME = 'anamneses'
  AND COLUMN_NAME IN (
    'chief_complaint', 'family_history', 'pathologies', 'has_surgeries', 'surgeries_details',
    'medication_usage', 'medications_details', 'supplement_usage', 'supplements_details',
    'intolerance_assessment', 'alcohol_consumption', 'alcohol_details', 'smoking_status',
    'smoking_details', 'sleep_hours', 'sleep_quality', 'sleep_notes', 'physical_activity',
    'activity_modality', 'activity_frequency_weekly', 'activity_duration_minutes',
    'referenced_exams', 'structured_data_json'
  )
ORDER BY ORDINAL_POSITION;

-- 4. Conferência dos índices da Migração 69
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2'
  AND TABLE_NAME IN ('booking_calendar_exceptions', 'booking_waitlist')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 5. Conferência dos registros de migração 69 e 70
SELECT migration, executed_at
FROM `maic6824_Clyonutri2`.`migrations`
WHERE migration IN (
  '2026_11_01_000069_create_booking_calendar_and_waitlist',
  '2026_11_01_000070_add_structured_conditional_fields_to_anamneses'
)
ORDER BY migration;
