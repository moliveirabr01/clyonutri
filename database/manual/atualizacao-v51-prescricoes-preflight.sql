-- Somente leitura. Base existente v49/68 obrigatória; conferir migração 71 antes de aplicar.
SELECT DATABASE() AS contexto_atual,VERSION() AS servidor;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('migrations','patients','professionals','clinic_members','appointments','meal_plans','rx_policy_versions','rx_ingredients','rx_ingredient_rules','rx_qualifications','rx_prescriptions','rx_signatures','rx_audit_events','rx_deliveries','rx_templates','rx_followups');
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN ('2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda','2026_11_01_000071_create_nutritional_prescriptions');
