-- =============================================================================
-- ClyoNutri II — PREFLIGHT (SOMENTE LEITURA)
-- Atualização Consolidada v53 (NI2-567):
--   - Migração 69: Calendário, Feriados, Bloqueios, Buffers e Lista de Espera (v50 Agenda Fluxo)
--   - Migração 70: Processador Delimitado e Resumível da Fila de Espera (v50 Fila Worker)
--   - Migração 71: Prescrições Nutricionais, Fitoterápicos, Manipulados e Assinatura Digital (NI2-554)
--   - Migração 72: Autoagendamento Completo e Gestão do Paciente (NI2-433)
--   - Migração 73: Anamnese Estruturada com 12 Blocos e Campos Condicionais (NI2-223)
-- Banco: maic6824_Clyonutri2 (substitua se o nome do schema for diferente)
-- =============================================================================

SELECT 'maic6824_Clyonutri2' AS banco_selecionado, VERSION() AS versao_servidor;

-- 1. Conferência das tabelas base existentes
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME IN (
    'migrations', 'clinics', 'patients', 'professionals', 'appointments',
    'appointment_status_history', 'booking_settings', 'booking_holds',
    'notification_events', 'notification_deliveries', 'anamneses'
)
ORDER BY TABLE_NAME;

-- 2. Conferência de tabelas que serão criadas (devem retornar 0 se ainda não aplicadas)
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME IN (
    'booking_calendar_policies', 'booking_calendar_exceptions', 'booking_waitlist',
    'booking_waitlist_worker', 'booking_self_service_policies', 'booking_self_service_operations',
    'booking_daily_metrics', 'rx_policy_versions', 'rx_ingredients', 'rx_ingredient_rules',
    'rx_qualifications', 'rx_prescriptions', 'rx_signatures', 'rx_audit_events',
    'rx_deliveries', 'rx_templates', 'rx_followups'
)
ORDER BY TABLE_NAME;

-- 3. Conferência de colunas existentes em anamneses
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'maic6824_Clyonutri2' AND TABLE_NAME = 'anamneses'
  AND COLUMN_NAME IN ('chief_complaint', 'family_history', 'pathologies', 'has_surgeries', 'structured_data_json')
ORDER BY ORDINAL_POSITION;

-- 4. Conferência de migrações registradas (68 a 73)
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN (
    '2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda',
    '2026_11_01_000069_create_booking_calendar_and_waitlist',
    '2026_11_01_000070_bound_waitlist_processing',
    '2026_11_01_000071_create_nutritional_prescriptions',
    '2026_11_01_000072_complete_patient_self_service',
    '2026_11_01_000073_add_structured_conditional_fields_to_anamneses'
)
ORDER BY migration;
