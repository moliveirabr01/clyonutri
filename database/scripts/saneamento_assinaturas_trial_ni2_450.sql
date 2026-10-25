-- ==============================================================================
-- ClyoNutri II — Script de Saneamento Contábil e Ativação do Trial (NI2-450)
-- HostGator / phpMyAdmin / MySQL 5.7+
-- ==============================================================================
-- Finalidade:
-- 1. Aplicar a coluna trial_ends_at e índice caso não existam no MySQL 5.7.
-- 2. Anular faturas fictícias geradas pelo código legado (status 'paid' sem gateway real).
-- 3. Converter assinaturas que nunca pagaram para 'trialing' (se < 14 dias) ou 'expired' (se > 14 dias).
-- ==============================================================================

-- [1] Estrutura DDL segura e idempotente para MySQL 5.7 (sem ADD COLUMN IF NOT EXISTS)
SET @dbname = DATABASE();
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname 
      AND TABLE_NAME = 'clinic_subscriptions' 
      AND COLUMN_NAME = 'trial_ends_at'
);

SET @sql_add_col = IF(
    @col_exists = 0,
    'ALTER TABLE `clinic_subscriptions` ADD COLUMN `trial_ends_at` DATETIME NULL AFTER `current_period_end`',
    'SELECT "Coluna trial_ends_at já existe em clinic_subscriptions" AS status'
);
PREPARE stmt_add_col FROM @sql_add_col;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

-- Índice para performance do cron scheduler
SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = @dbname 
      AND TABLE_NAME = 'clinic_subscriptions' 
      AND INDEX_NAME = 'idx_clinic_sub_trial'
);

SET @sql_add_idx = IF(
    @idx_exists = 0,
    'CREATE INDEX `idx_clinic_sub_trial` ON `clinic_subscriptions` (`status`, `trial_ends_at`)',
    'SELECT "Índice idx_clinic_sub_trial já existe" AS status'
);
PREPARE stmt_add_idx FROM @sql_add_idx;
EXECUTE stmt_add_idx;
DEALLOCATE PREPARE stmt_add_idx;

-- [2] Saneamento Contábil das Faturas Fictícias
-- Faturas legadas geradas automaticamente pelo código legado sem webhook ou gateway real
UPDATE `subscription_invoices`
SET `status` = 'void'
WHERE `status` = 'paid';

-- [3] Saneamento das Assinaturas Sem Pagamento Real
-- A: Assinaturas recentes com menos de 14 dias de cadastro -> 'trialing'
UPDATE `clinic_subscriptions`
SET `status` = 'trialing',
    `trial_ends_at` = DATE_ADD(`started_at`, INTERVAL 14 DAY),
    `current_period_end` = DATE_ADD(`started_at`, INTERVAL 14 DAY),
    `updated_at` = NOW()
WHERE `status` = 'active'
  AND `started_at` >= DATE_SUB(NOW(), INTERVAL 14 DAY);

-- B: Assinaturas antigas com mais de 14 dias de cadastro sem pagamento -> 'expired'
UPDATE `clinic_subscriptions`
SET `status` = 'expired',
    `trial_ends_at` = DATE_ADD(`started_at`, INTERVAL 14 DAY),
    `updated_at` = NOW()
WHERE `status` = 'active'
  AND `started_at` < DATE_SUB(NOW(), INTERVAL 14 DAY);

-- [4] Relatório de Auditoria Pós-Saneamento
SELECT 
    `status`, 
    COUNT(*) AS total_assinaturas,
    MIN(`trial_ends_at`) AS menor_trial_fim,
    MAX(`trial_ends_at`) AS maior_trial_fim
FROM `clinic_subscriptions`
GROUP BY `status`;

SELECT 
    `status`, 
    COUNT(*) AS total_faturas,
    SUM(`amount_cents`) / 100 AS montante_reais
FROM `subscription_invoices`
GROUP BY `status`;
