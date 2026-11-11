-- =============================================================================
-- ClyoNutri II — PREFLIGHT (SOMENTE LEITURA)
-- Atualização Consolidada v50:
--   - Migração 69: Calendário, Feriados, Bloqueios, Buffers e Lista de Espera (v50 Agenda Fluxo)
--   - Migração 70: Anamnese Estruturada com 12 Blocos e Campos Condicionais (NI2-223)
-- Banco: maic6824_Clyonutri2 (substitua se o nome do schema for diferente)
-- =============================================================================

SELECT 'maic6824_Clyonutri2' AS banco_selecionado, VERSION() AS versao_servidor;

-- 1. Conferência das tabelas base
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME IN (
    'migrations', 'clinics', 'patients', 'professionals', 'appointments',
    'appointment_status_history', 'booking_settings', 'booking_holds',
    'notification_events', 'notification_deliveries', 'anamneses'
)
ORDER BY TABLE_NAME;

-- 2. Conferência de colunas existentes em anamneses
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME = 'anamneses'
  AND COLUMN_NAME IN ('chief_complaint', 'family_history', 'pathologies', 'has_surgeries', 'structured_data_json')
ORDER BY ORDINAL_POSITION;

-- 3. Conferência de migrações registradas
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN (
    '2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda',
    '2026_11_01_000069_create_booking_calendar_and_waitlist',
    '2026_11_01_000070_add_structured_conditional_fields_to_anamneses'
);
