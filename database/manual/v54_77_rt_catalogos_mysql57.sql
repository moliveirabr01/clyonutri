-- v54.77 — migração 102: nutricionista responsável técnico (RT) dos catálogos (medidas caseiras e exames). MySQL 5.7+, idempotente.
-- Selecione o banco do sistema no phpMyAdmin antes de executar. Pode rodar mais de uma vez: não troca um valor já preenchido.
-- Grava só o NOME do RT (decisão do Maicon em 17/11/2026). CRN e e-mail ficam em branco e são preenchidos pela tela
-- Admin > Medidas caseiras > "Responsável técnico (RT) dos catálogos". Sem CRN, o sistema não grava revisão.
INSERT INTO platform_settings (setting_key, setting_value, description)
SELECT 'catalog_rt_name', 'Raphael Lombardi', 'Nutricionista responsável técnico (RT) pela revisão dos catálogos de medidas caseiras e exames (v54.77)' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM platform_settings WHERE setting_key = 'catalog_rt_name');

INSERT INTO platform_settings (setting_key, setting_value, description)
SELECT 'catalog_rt_crn', '', 'CRN do RT dos catálogos, formato CRN-<região> <número> (v54.77)' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM platform_settings WHERE setting_key = 'catalog_rt_crn');

INSERT INTO platform_settings (setting_key, setting_value, description)
SELECT 'catalog_rt_email', '', 'E-mail da conta do RT no console (opcional) (v54.77)' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM platform_settings WHERE setting_key = 'catalog_rt_email');

INSERT INTO migrations (migration)
SELECT '2026_11_17_000102_v5477_catalog_rt_reviewer' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_17_000102_v5477_catalog_rt_reviewer');

-- Conferência: 3 linhas (nome = Raphael Lombardi; CRN e e-mail vazios até a plataforma preencher).
SELECT setting_key, setting_value FROM platform_settings
WHERE setting_key IN ('catalog_rt_name', 'catalog_rt_crn', 'catalog_rt_email') ORDER BY setting_key;
