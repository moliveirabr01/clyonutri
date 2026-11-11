-- Somente leitura. Esperadas dez tabelas InnoDB, índices e registro da migração 71.
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('rx_policy_versions','rx_ingredients','rx_ingredient_rules','rx_qualifications','rx_prescriptions','rx_signatures','rx_audit_events','rx_deliveries','rx_templates','rx_followups') ORDER BY TABLE_NAME;
SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('rx_policy_versions','rx_ingredients','rx_ingredient_rules','rx_qualifications','rx_prescriptions','rx_signatures','rx_audit_events','rx_deliveries','rx_templates','rx_followups') GROUP BY TABLE_NAME,INDEX_NAME;
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000071_create_nutritional_prescriptions';
SELECT COUNT(*) AS politicas_habilitadas FROM `maic6824_Clyonutri2`.`rx_policy_versions` WHERE enabled=1;
-- Zero políticas e catálogo vazio são esperados na primeira instalação.
