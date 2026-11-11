-- ============================================================================
-- ClyoNutri v39: ATUALIZAÇÃO DO BANCO — "Plano e conta" no perfil (migração 60, CORRIGIDA)
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do
-- atualizacao-v39-preflight.sql, e ANTES de subir os arquivos novos.
-- Substitui o atualizacao-v37-completa.sql, que falhou com #1215 (chave estrangeira). Esta versão NÃO usa
-- chave estrangeira nenhuma (o app não depende delas) e funciona em qualquer um destes estados:
--   • banco sem nenhuma das duas tabelas (caso de quem rodou a v37 e levou o erro #1215);
--   • banco com só uma das duas tabelas (estado parcial): cria a que falta;
--   • banco com as duas (só registra a migração 60 se faltar).
-- Idempotente: pode rodar de novo sem efeito (CREATE TABLE IF NOT EXISTS; registro só se ainda não existir). MySQL 5.7.
--   60 · subscription_plan_changes  (histórico de trocas de plano; cobrança manual, sem fatura)
--      · account_deletion_requests  (pedidos de exclusão de conta e dados, LGPD; só registro, nada é apagado)
-- Só cria duas tabelas novas e vazias; não altera nem apaga nenhum dado existente.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `subscription_plan_changes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `subscription_id` INT NOT NULL,
  `protocol` VARCHAR(40) NOT NULL,
  `change_type` VARCHAR(20) NOT NULL,
  `from_plan_id` INT NOT NULL,
  `to_plan_id` INT NOT NULL,
  `from_plan_code` VARCHAR(50) NOT NULL,
  `to_plan_code` VARCHAR(50) NOT NULL,
  `from_price_cents` INT NOT NULL,
  `to_price_cents` INT NOT NULL,
  `subscription_status` VARCHAR(30) NOT NULL,
  `billing_mode` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `usage_json` TEXT NULL,
  `idempotency_key` VARCHAR(64) NULL,
  `requested_by` INT NOT NULL,
  `effective_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_plan_changes_protocol` UNIQUE (`protocol`),
  CONSTRAINT `uq_plan_changes_idem` UNIQUE (`clinic_id`, `idempotency_key`),
  KEY `idx_plan_changes_clinic` (`clinic_id`, `created_at`),
  KEY `idx_plan_changes_recent` (`created_at`),
  KEY `idx_plan_changes_subscription` (`subscription_id`),
  KEY `idx_plan_changes_from_plan` (`from_plan_id`),
  KEY `idx_plan_changes_to_plan` (`to_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sem chave estrangeira em nenhuma das duas tabelas, de propósito. A v37 declarava FKs para clinics, clinic_subscriptions e
-- saas_plans e o MySQL 5.7 da produção recusou (#1215: a coluna referenciada precisa ter exatamente o mesmo tipo, sinal, engine
-- e índice; em produção id e clinic_id são bigint(20) e as colunas novas da v37 eram INT). O app valida clínica, assinatura e plano em PHP antes de gravar; os índices ficam. O registro também sobrevive se
-- uma clínica for removida depois (protocolo, datas, quem pediu).
-- `open_flag` vale 1 enquanto o pedido está aberto e NULL depois; UNIQUE (clinic_id, open_flag) permite um pedido aberto por clínica.
CREATE TABLE IF NOT EXISTS `account_deletion_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `clinic_name` VARCHAR(150) NOT NULL,
  `protocol` VARCHAR(40) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `open_flag` INT NULL,
  `requested_by` INT NOT NULL,
  `requester_name` VARCHAR(120) NOT NULL,
  `requester_role` VARCHAR(20) NOT NULL,
  `reason_text` VARCHAR(500) NULL,
  `confirmation_method` VARCHAR(30) NOT NULL DEFAULT 'password_and_phrase',
  `legal_notice_version` VARCHAR(30) NOT NULL,
  `requested_at` DATETIME NOT NULL,
  `deadline_at` DATETIME NOT NULL,
  `in_review_at` DATETIME NULL,
  `canceled_at` DATETIME NULL,
  `canceled_by` INT NULL,
  `resolved_at` DATETIME NULL,
  `resolved_by` INT NULL,
  `resolution_note` VARCHAR(1000) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_account_deletion_protocol` UNIQUE (`protocol`),
  CONSTRAINT `uq_account_deletion_open` UNIQUE (`clinic_id`, `open_flag`),
  KEY `idx_account_deletion_status` (`status`, `deadline_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a migração 60 (para o instalador não tentar rodá-la de novo), só se as duas tabelas existem.
INSERT INTO `migrations` (`migration`, `executed_at`)
SELECT '2026_10_28_000060_create_plan_changes_and_account_deletion_requests', CURRENT_TIMESTAMP FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests')
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('subscription_plan_changes','account_deletion_requests')) = 2;

-- Conferência (resultado esperado: as duas tabelas = 1 e migração registrada = 1).
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plan_changes') AS tabela_trocas_de_plano,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_deletion_requests') AS tabela_pedidos_de_exclusao,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') AS migracao_60_registrada;
