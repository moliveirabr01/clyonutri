-- =============================================================================
-- ClyoNutri II — VERIFICAÇÃO PÓS-ATUALIZAÇÃO v53 (SOMENTE LEITURA)
-- Valida as Migrações 69, 70, 71, 72 e 73
-- Banco: maic6824_Clyonutri2
-- =============================================================================

SELECT 'maic6824_Clyonutri2' AS banco, 'VERIFICAÇÃO PÓS-MIGRAÇÃO v53' AS etapa;

-- 1. Conferência das tabelas criadas (esperado: 17 tabelas novas)
SELECT TABLE_NAME, ENGINE, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME IN (
    'booking_calendar_policies', 'booking_calendar_exceptions', 'booking_waitlist',
    'booking_waitlist_worker', 'booking_self_service_policies', 'booking_self_service_operations',
    'booking_daily_metrics', 'rx_policy_versions', 'rx_ingredients', 'rx_ingredient_rules',
    'rx_qualifications', 'rx_prescriptions', 'rx_signatures', 'rx_audit_events',
    'rx_deliveries', 'rx_templates', 'rx_followups'
)
ORDER BY TABLE_NAME;

-- 2. Conferência de colunas da anamnese estruturada (NI2-223 / migração 73)
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME = 'anamneses'
  AND COLUMN_NAME IN (
    'chief_complaint', 'family_history', 'pathologies', 'has_surgeries',
    'surgeries_details', 'medication_usage', 'medications_details',
    'supplement_usage', 'supplements_details', 'intolerance_assessment',
    'alcohol_consumption', 'alcohol_details', 'smoking_status', 'smoking_details',
    'sleep_hours', 'sleep_quality', 'sleep_notes', 'physical_activity',
    'activity_modality', 'activity_frequency_weekly', 'activity_duration_minutes',
    'referenced_exams', 'structured_data_json'
  )
ORDER BY ORDINAL_POSITION;

-- 3. Conferência de índices de performance
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND INDEX_NAME IN (
    'idx_waitlist_matching_fifo', 'idx_booking_calendar_day', 'idx_booking_waitlist_fifo',
    'idx_rx_author', 'idx_rx_patient', 'idx_rx_audit', 'idx_rx_qualification'
)
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- 4. Conferência de registro na tabela migrations (esperado: 69 a 73 presentes)
SELECT id, migration, batch
FROM `maic6824_Clyonutri2`.`migrations`
WHERE migration IN (
    '2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda',
    '2026_11_01_000069_create_booking_calendar_and_waitlist',
    '2026_11_01_000070_bound_waitlist_processing',
    '2026_11_01_000071_create_nutritional_prescriptions',
    '2026_11_01_000072_complete_patient_self_service',
    '2026_11_01_000073_add_structured_conditional_fields_to_anamneses'
)
ORDER BY migration;
