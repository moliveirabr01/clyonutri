-- =============================================================================
-- ClyoNutri II — VERIFICAÇÃO v54.1 (SOMENTE LEITURA). Banco: maic6824_Clyonutri2.
-- =============================================================================

-- 1. Migrações registradas (esperado: 73, 75 e 77).
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN (
    '2026_11_01_000073_add_structured_conditional_fields_to_anamneses',
    '2026_11_01_000075_add_allergies_to_patients',
    '2026_11_02_000077_add_booking_slug_and_public_address'
) ORDER BY migration;

-- 2. Colunas novas (esperado: 4 linhas).
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND (
      (TABLE_NAME = 'patients' AND COLUMN_NAME IN ('allergies', 'allergy_assessment'))
   OR (TABLE_NAME = 'professionals' AND COLUMN_NAME = 'booking_slug')
   OR (TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'public_show_address')
) ORDER BY TABLE_NAME, COLUMN_NAME;

-- 3. Anamnese estruturada (esperado: 23 colunas).
SELECT COUNT(*) AS colunas_anamnese_estruturada FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME = 'anamneses' AND COLUMN_NAME IN (
    'chief_complaint', 'family_history', 'pathologies', 'has_surgeries', 'surgeries_details', 'medication_usage',
    'medications_details', 'supplement_usage', 'supplements_details', 'intolerance_assessment', 'alcohol_consumption',
    'alcohol_details', 'smoking_status', 'smoking_details', 'sleep_hours', 'sleep_quality', 'sleep_notes',
    'physical_activity', 'activity_modality', 'activity_frequency_weekly', 'activity_duration_minutes',
    'referenced_exams', 'structured_data_json');

-- 4. Índice único do apelido (esperado: 1 linha, NON_UNIQUE = 0).
SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME = 'professionals' AND INDEX_NAME = 'uq_professionals_booking_slug';

-- 5. Nada foi preenchido nem convertido (esperado: 0, 0 e 0).
SELECT
  (SELECT COUNT(*) FROM `maic6824_Clyonutri2`.`professionals` WHERE booking_slug IS NOT NULL) AS apelidos_gerados,
  (SELECT COUNT(*) FROM `maic6824_Clyonutri2`.`booking_settings` WHERE public_show_address <> 0) AS enderecos_publicos,
  (SELECT COUNT(*) FROM `maic6824_Clyonutri2`.`patients` WHERE allergies IS NOT NULL OR allergy_assessment IS NOT NULL) AS alergias_cadastro;

-- 6. Contagens (devem ser iguais às do preflight).
SELECT 'patients' AS tabela, COUNT(*) AS linhas FROM `maic6824_Clyonutri2`.`patients`
UNION ALL SELECT 'professionals', COUNT(*) FROM `maic6824_Clyonutri2`.`professionals`
UNION ALL SELECT 'booking_settings', COUNT(*) FROM `maic6824_Clyonutri2`.`booking_settings`
UNION ALL SELECT 'anamneses', COUNT(*) FROM `maic6824_Clyonutri2`.`anamneses`
UNION ALL SELECT 'onboarding_progress', COUNT(*) FROM `maic6824_Clyonutri2`.`onboarding_progress`;
