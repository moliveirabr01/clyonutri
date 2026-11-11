-- =============================================================================
-- ClyoNutri II — PREFLIGHT v54.1 (SOMENTE LEITURA)
-- Rode antes da atualização e guarde o resultado. Banco: maic6824_Clyonutri2.
-- =============================================================================

SELECT 'maic6824_Clyonutri2' AS banco_selecionado, VERSION() AS versao_servidor;

-- 1. A v53 precisa estar aplicada (esperado: 69, 70, 71 e 72 presentes). Se faltar alguma, pare e aplique antes
--    database/manual/atualizacao-v53-consolidada-completa.sql.
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN (
    '2026_11_01_000069_create_booking_calendar_and_waitlist',
    '2026_11_01_000070_bound_waitlist_processing',
    '2026_11_01_000071_create_nutritional_prescriptions',
    '2026_11_01_000072_complete_patient_self_service',
    '2026_11_01_000073_add_structured_conditional_fields_to_anamneses',
    '2026_11_01_000075_add_allergies_to_patients',
    '2026_11_02_000077_add_booking_slug_and_public_address'
) ORDER BY migration;

-- 2. Migrações que NÃO devem existir (vinham do pacote v54 rejeitado). Esperado: 0 linhas.
--    Se aparecer alguma, o pacote rejeitado chegou a rodar: avise antes de continuar.
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN (
    '2026_11_01_000074_migrate_working_days_to_weekly_availability',
    '2026_11_02_000076_seed_common_food_household_measures'
);

-- 3. Colunas que esta atualização cria (antes: esperado 0 linhas; se já existirem, a atualização só as mantém).
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND (
      (TABLE_NAME = 'patients' AND COLUMN_NAME IN ('allergies', 'allergy_assessment'))
   OR (TABLE_NAME = 'professionals' AND COLUMN_NAME = 'booking_slug')
   OR (TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'public_show_address')
   OR (TABLE_NAME = 'anamneses' AND COLUMN_NAME IN ('chief_complaint', 'structured_data_json'))
) ORDER BY TABLE_NAME, COLUMN_NAME;

-- 4. Contagens para comparar depois (não podem mudar).
SELECT 'patients' AS tabela, COUNT(*) AS linhas FROM `maic6824_Clyonutri2`.`patients`
UNION ALL SELECT 'professionals', COUNT(*) FROM `maic6824_Clyonutri2`.`professionals`
UNION ALL SELECT 'booking_settings', COUNT(*) FROM `maic6824_Clyonutri2`.`booking_settings`
UNION ALL SELECT 'anamneses', COUNT(*) FROM `maic6824_Clyonutri2`.`anamneses`
UNION ALL SELECT 'onboarding_progress', COUNT(*) FROM `maic6824_Clyonutri2`.`onboarding_progress`;
