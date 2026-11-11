-- ============================================================================
-- ClyoNutri v27: PREFLIGHT (somente leitura). Não altera nada.
-- Rode no phpMyAdmin DEPOIS do backup e ANTES de atualizacao-v27-completa.sql. MySQL 5.7.
-- Leia a coluna "situacao": OK, ATENÇÃO ou FALHA, com o motivo.
-- ============================================================================
SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 1048576;
SET @db = DATABASE();
SET @tem_migrations = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations');
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('users', 'clinic_subscriptions', 'patient_consents', 'consent_terms', 'migrations') AND UPPER(ENGINE) = 'INNODB');
SET @m55 = 0; SET @m56 = 0; SET @m57 = 0; SET @outras = NULL;
SET @q = IF(@tem_migrations = 1, 'SELECT COALESCE(SUM(`migration` = ''2026_10_24_000055_update_help_center_copy_v21''), 0), COALESCE(SUM(`migration` = ''2026_10_26_000056_create_user_legal_acceptances''), 0), COALESCE(SUM(`migration` = ''2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions''), 0) INTO @m55, @m56, @m57 FROM `migrations`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@tem_migrations = 1, 'SELECT GROUP_CONCAT(`migration` SEPARATOR '', '') INTO @outras FROM `migrations` WHERE `migration` > ''2026_10_24_000055_update_help_center_copy_v21'' AND `migration` NOT IN (''2026_10_26_000056_create_user_legal_acceptances'', ''2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @t56 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances');
SET @c57 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND COLUMN_NAME = 'trial_ends_at');
SELECT CAST(CASE
    WHEN @base < 5 THEN 'FALHA — faltam tabelas base (users, clinic_subscriptions, patient_consents, consent_terms ou migrations) ou não são InnoDB'
    WHEN @m55 = 0 THEN 'FALHA — a migração 55 (v24) não está registrada; aplique a v24 antes'
    WHEN @outras IS NOT NULL THEN CONCAT('ATENÇÃO — há migrações depois da 55 que esta versão não conhece: ', @outras)
    WHEN @m56 > 0 AND @m57 > 0 THEN 'OK — migrações 56 e 57 já aplicadas; rodar a atualização não muda nada'
    ELSE 'OK — PRONTO PARA MIGRAR' END AS CHAR(400) CHARACTER SET utf8mb4) AS situacao,
  CAST(@m56 AS UNSIGNED) AS migracao_56_registrada, @t56 AS tem_user_legal_acceptances,
  CAST(@m57 AS UNSIGNED) AS migracao_57_registrada, @c57 AS tem_trial_ends_at,
  (SELECT COUNT(*) FROM `users`) AS usuarios_cadastrados,
  (SELECT COUNT(*) FROM `clinic_subscriptions` WHERE `status` = 'active') AS assinaturas_ativas;
