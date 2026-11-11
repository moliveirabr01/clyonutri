-- ============================================================================
-- ClyoNutri v28: ATUALIZAÇÃO DO BANCO (migração 58)
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do
-- atualizacao-v28-preflight.sql, e ANTES de subir os arquivos da v28.
-- Idempotente: pode rodar de novo sem efeito. Compatível com MySQL 5.7.
--   58 · NI2-481 · tabela patient_password_resets (recuperação de senha do paciente)
-- Só cria estrutura; não apaga nem altera dados existentes.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('patients', 'clinics', 'migrations')) = 3);

-- 1) Tabela de tokens de recuperação de senha do paciente (escopada por clínica; só o hash é guardado).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Índice de busca por paciente + validade (criado só se ainda não existir; CREATE INDEX não é idempotente no 5.7).
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_password_resets' AND INDEX_NAME = 'idx_patient_pwreset_patient');
SET @q = IF(@has_idx = 0, 'CREATE INDEX `idx_patient_pwreset_patient` ON `patient_password_resets` (`patient_id`, `expires_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Registra a migração 58 (para o controle de versões bater com o código; não altera comportamento em produção).
SET @q = IF(@ok_base = 1, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_26_000058_create_patient_password_resets'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_26_000058_create_patient_password_resets'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência (resultado esperado: tabela e índice = 1, migração registrada = 1).
SELECT
  CASE WHEN @ok_base = 1 THEN 'OK — base presente' ELSE 'FALHA — faltam tabelas base (patients/clinics/migrations)' END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_password_resets') AS tabela_58,
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_password_resets' AND INDEX_NAME = 'idx_patient_pwreset_patient') AS indice_58,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_26_000058_create_patient_password_resets') AS migracao_58_registrada;
