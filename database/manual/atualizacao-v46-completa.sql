-- ============================================================================
-- ClyoNutri II v46: ATUALIZAÇÃO COMPLETA DO BANCO (migrações 64 e 65, pedido de exames, NI2-529)
-- Parte do banco de produção, onde a migração 64 JÁ foi rodada (u3).
--  1. Cria só as tabelas de exames que faltarem (bancos que ainda não têm a 64).
--  2. Acrescenta só as colunas da 65 que faltarem: revisão, emissor e o PDF guardado fora de public/
--     (caminho, tamanho, data). pdf_hash passa a ser o SHA-256 do PDF.
--  3. Padrão 0 na ciência do aviso de cobertura.
--  4. Política 2026.1 e os 22 exames do catálogo: só entram se faltarem (nada é sobrescrito).
--  5. Registra as migrações 64 e 65 uma vez só.
-- Não mexe em is_approved_clinical/is_approved_legal: desde a v46 a emissão, o PDF e a impressão não
-- dependem de liberação, e o sistema não lê essas colunas.
-- Idempotente (pode rodar de novo), sem chave estrangeira, sem DROP, sem DELETE e sem UPDATE. MySQL 5.7 e 8.
-- Rode ANTES o atualizacao-v46-preflight.sql e DEPOIS o atualizacao-v46-verificacao.sql.
-- ============================================================================
SET NAMES utf8mb4;

-- 1. Tabelas (só as que faltarem)
CREATE TABLE IF NOT EXISTS `exam_regulatory_policies` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `policy_version` VARCHAR(20) NOT NULL UNIQUE,
  `legal_basis` LONGTEXT NOT NULL,
  `statement` LONGTEXT NOT NULL,
  `disclaimer_coverage` LONGTEXT NOT NULL,
  `is_approved_clinical` TINYINT(1) NOT NULL DEFAULT 1,
  `is_approved_legal` TINYINT(1) NOT NULL DEFAULT 1,
  `effective_from` DATE NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_catalog` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(60) NOT NULL UNIQUE,
  `canonical_name` VARCHAR(255) NOT NULL,
  `short_name` VARCHAR(100) NULL,
  `category` VARCHAR(100) NOT NULL,
  `sample_type` VARCHAR(100) NOT NULL,
  `fasting_hours` INT NULL,
  `nutritional_indication` LONGTEXT NOT NULL,
  `synonyms` LONGTEXT NULL,
  `search_text` LONGTEXT NOT NULL,
  `is_laboratorial` TINYINT(1) NOT NULL DEFAULT 1,
  `is_nutritional_scope` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `idx_exam_catalog_category` (`category`),
  KEY `idx_exam_catalog_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_requests` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `patient_id` BIGINT(20) NOT NULL,
  `professional_id` BIGINT(20) NOT NULL,
  `policy_version` VARCHAR(20) NOT NULL,
  `request_number` VARCHAR(50) NOT NULL UNIQUE,
  `clinical_justification` LONGTEXT NOT NULL,
  `diagnostic_hypothesis_nutritional` VARCHAR(255) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
  `professional_snapshot` LONGTEXT NOT NULL,
  `patient_snapshot` LONGTEXT NOT NULL,
  `coverage_disclaimer_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `issued_at` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  `cancellation_reason` LONGTEXT NULL,
  `pdf_hash` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `reviewed_at` DATETIME NULL,
  `issued_by_user_id` BIGINT(20) NULL,
  `pdf_path` VARCHAR(255) NULL,
  `pdf_size` INT NULL,
  `pdf_generated_at` DATETIME NULL,
  KEY `idx_exam_requests_clinic_patient` (`clinic_id`, `patient_id`),
  KEY `idx_exam_requests_professional` (`professional_id`),
  KEY `idx_exam_requests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_request_items` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `exam_request_id` BIGINT(20) NOT NULL,
  `exam_id` BIGINT(20) NOT NULL,
  `exam_canonical_name` VARCHAR(255) NOT NULL,
  `exam_code` VARCHAR(60) NOT NULL,
  `sample_type` VARCHAR(100) NOT NULL,
  `specific_instructions` VARCHAR(500) NULL,
  `matched_synonym` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  KEY `idx_exam_request_items_req` (`exam_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_audit_logs` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `user_id` BIGINT(20) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` BIGINT(20) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `details` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_exam_audit_clinic_user` (`clinic_id`, `user_id`),
  KEY `idx_exam_audit_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Colunas e índice da migração 65 (só os que faltarem)
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `exam_requests` ADD COLUMN `reviewed_at` DATETIME NULL', 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'reviewed_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `exam_requests` ADD COLUMN `issued_by_user_id` BIGINT(20) NULL', 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'issued_by_user_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `exam_requests` ADD COLUMN `pdf_path` VARCHAR(255) NULL', 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'pdf_path');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `exam_requests` ADD COLUMN `pdf_size` INT NULL', 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'pdf_size');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `exam_requests` ADD COLUMN `pdf_generated_at` DATETIME NULL', 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME = 'pdf_generated_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `exam_audit_logs` ADD INDEX `idx_exam_audit_entity` (`entity_type`, `entity_id`)', 'DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_audit_logs' AND INDEX_NAME = 'idx_exam_audit_entity');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- 3. Padrão 0 na ciência do aviso de cobertura (o valor real vem do formulário)
ALTER TABLE `exam_requests` ALTER COLUMN `coverage_disclaimer_acknowledged` SET DEFAULT 0;

-- 4. Política e catálogo (só o que faltar)
INSERT IGNORE INTO `exam_regulatory_policies` (`policy_version`, `legal_basis`, `statement`, `disclaimer_coverage`, `is_approved_clinical`, `is_approved_legal`, `effective_from`, `created_at`)
VALUES ('2026.1', 'Lei Federal nº 8.234/1991 (art. 4º, inciso VIII) e Resolução CFN nº 306/2003.', 'O nutricionista é legalmente habilitado a solicitar exames laboratoriais estritamente necessários ao acompanhamento dietoterápico e avaliação nutricional, sendo vedada a solicitação de exames invasivos não laboratoriais (como exames de imagem, endoscopias e biópsias) e a realização de diagnóstico clínico médico.', 'Atenção: A cobertura de exames laboratoriais solicitados por nutricionistas depende das cláusulas contratuais de cada operadora de plano de saúde e das diretrizes da ANS/laboratórios locais. O ClyoNutri II não garante cobertura automática nem reembolso.', 1, 1, '2026-01-01', NOW());

INSERT IGNORE INTO `exam_catalog` (`code`, `canonical_name`, `short_name`, `category`, `sample_type`, `fasting_hours`, `nutritional_indication`, `synonyms`, `search_text`, `is_laboratorial`, `is_nutritional_scope`, `is_active`, `version`, `created_at`, `updated_at`) VALUES
('HEMOGRAMA_COMPLETO', 'Hemograma completo com contagem de plaquetas', 'Hemograma completo', 'Hematologia', 'Sangue total (EDTA)', 4, 'Avaliação de anemias nutricionais (ferropriva, megaloblástica por carência de B12/folato), estado imune e contagem celular de base.', 'hemograma, hemoglobina, hematocrito, leucograma, plaquetas, serie vermelha, contagem celular', 'hemograma completo com contagem de plaquetas hemograma completo hematologia hemograma, hemoglobina, hematocrito, leucograma, plaquetas, serie vermelha, contagem celular', 1, 1, 1, 1, NOW(), NOW()),
('GLICEMIA_JEJUM', 'Glicemia de jejum', 'Glicemia', 'Glicemia e Metabolismo', 'Plasma fluoretado', 8, 'Avaliação do metabolismo glicídico basal, rastreamento de resistência à insulina e monitoramento do plano dietético no diabetes/pré-diabetes.', 'glicose, acucar no sangue, glicemia basal, glicemia de jejum', 'glicemia de jejum glicemia glicemia e metabolismo glicose, acucar no sangue, glicemia basal, glicemia de jejum', 1, 1, 1, 1, NOW(), NOW()),
('HEMOGLOBINA_GLICADA', 'Hemoglobina glicada (HbA1c)', 'HbA1c', 'Glicemia e Metabolismo', 'Sangue total (EDTA)', 0, 'Controle glicêmico retrospectivo dos últimos 90 a 120 dias para ajuste na ingestão de carboidratos e índice glicêmico da dieta.', 'hba1c, a1c, hemoglobina glicosilada, media glicemica', 'hemoglobina glicada (hba1c) hba1c glicemia e metabolismo hba1c, a1c, hemoglobina glicosilada, media glicemica', 1, 1, 1, 1, NOW(), NOW()),
('INSULINA_JEJUM', 'Insulina basal em jejum', 'Insulina de jejum', 'Glicemia e Metabolismo', 'Soro', 8, 'Avaliação de hiperinsulinemia e cálculo de índices de resistência insulínica (HOMA-IR, HOMA-BETA) para adequação macronutricional.', 'insulina, homa ir, homa beta, insulinemia, curva de insulina', 'insulina basal em jejum insulina de jejum glicemia e metabolismo insulina, homa ir, homa beta, insulinemia, curva de insulina', 1, 1, 1, 1, NOW(), NOW()),
('PERFIL_LIPIDICO', 'Perfil lipídico (Colesterol total, HDL, LDL, VLDL e Triglicerídeos)', 'Lipidograma completo', 'Perfil Lipídico', 'Soro', 12, 'Diagnóstico dietoterápico de dislipidemias, estratificação de risco cardiometabólico e direcionamento na proporção de ácidos graxos (saturados, mono e poli-insaturados).', 'lipidograma, colesterol, triglicerideos, hdl, ldl, vldl, lipidios, gordura no sangue', 'perfil lipidico (colesterol total, hdl, ldl, vldl e triglicerideos) lipidograma completo perfil lipidico lipidograma, colesterol, triglicerideos, hdl, ldl, vldl, lipidios, gordura no sangue', 1, 1, 1, 1, NOW(), NOW()),
('FERRITINA_SERICA', 'Ferritina sérica', 'Ferritina', 'Micronutrientes e Vitaminas', 'Soro', 4, 'Avaliação das reservas orgânicas de ferro corporal e marcador inflamatório de fase aguda para prescrição e intervenção alimentar rica em ferro e facilitadores de absorção.', 'ferritina, reserva de ferro, estoque de ferro', 'ferritina serica ferritina micronutrientes e vitaminas ferritina, reserva de ferro, estoque de ferro', 1, 1, 1, 1, NOW(), NOW()),
('FERRO_SERICO', 'Ferro sérico', 'Ferro', 'Micronutrientes e Vitaminas', 'Soro', 8, 'Determinação da concentração de ferro circulante para cálculo de saturação de transferrina e diagnóstico diferencial nutricional.', 'ferro serico, ferro circulante, sideremia', 'ferro serico ferro micronutrientes e vitaminas ferro serico, ferro circulante, sideremia', 1, 1, 1, 1, NOW(), NOW()),
('VITAMINA_B12', 'Vitamina B12 (Cobalamina)', 'Vitamina B12', 'Micronutrientes e Vitaminas', 'Soro', 4, 'Monitoramento do estado nutricional em vegetarianos, veganos, pós-cirurgia bariátrica e idosos para prevenção de manifestações hematológicas e neurológicas.', 'b12, cobalamina, cianocobalamina, vit b12', 'vitamina b12 (cobalamina) vitamina b12 micronutrientes e vitaminas b12, cobalamina, cianocobalamina, vit b12', 1, 1, 1, 1, NOW(), NOW()),
('VITAMINA_D_25OH', '25-Hidroxivitamina D [25(OH)D]', 'Vitamina D', 'Micronutrientes e Vitaminas', 'Soro', 0, 'Avaliação do status corporal de colecalciferol, metabolismo ósseo de cálcio/fósforo e imunomodulação nutricional.', 'vitamina d, 25 hidroxivitamina d, vit d, 25-oh-d, calcidiol', '25-hidroxivitamina d [25(oh)d] vitamina d micronutrientes e vitaminas vitamina d, 25 hidroxivitamina d, vit d, 25-oh-d, calcidiol', 1, 1, 1, 1, NOW(), NOW()),
('ACIDO_FOLICO', 'Ácido fólico sérico (Folato)', 'Folato / Ácido fólico', 'Micronutrientes e Vitaminas', 'Soro', 4, 'Avaliação do metabolismo de um carbono, metilação e aporte dietético de vegetais folhosos escuros, gestação e risco de anemia macrocítica.', 'folato, vitamina b9, b9, folacina, acido folico', 'acido folico serico (folato) folato / acido folico micronutrientes e vitaminas folato, vitamina b9, b9, folacina, acido folico', 1, 1, 1, 1, NOW(), NOW()),
('UREIA', 'Ureia sérica', 'Ureia', 'Função Renal', 'Soro', 4, 'Avaliação do catabolismo proteico, equilíbrio de nitrogênio e tolerância à prescrição de dietas hiperproteicas ou hipoproteicas.', 'ureia, nitrogenio ureico, bun', 'ureia serica ureia funcao renal ureia, nitrogenio ureico, bun', 1, 1, 1, 1, NOW(), NOW()),
('CREATININA_SERICA', 'Creatinina sérica com estimativa da Taxa de Filtração Glomerular (eTFG)', 'Creatinina', 'Função Renal', 'Soro', 4, 'Avaliação da função de filtração renal, cálculo da depuração para dimensionamento seguro da carga proteica da dieta e hidratação.', 'creatinina, taxa de filtracao glomerular, tfg, clearence de creatinina', 'creatinina serica com estimativa da taxa de filtracao glomerular (etfg) creatinina funcao renal creatinina, taxa de filtracao glomerular, tfg, clearence de creatinina', 1, 1, 1, 1, NOW(), NOW()),
('TGO_AST', 'Transaminase Oxalacética (TGO / AST)', 'TGO / AST', 'Função Hepática', 'Soro', 4, 'Marcador de integridade hepática e muscular, monitorando intervenções nutricionais em esteatose hepática metabólica (EHNA).', 'tgo, ast, aspartato aminotransferase', 'transaminase oxalacetica (tgo / ast) tgo / ast funcao hepatica tgo, ast, aspartato aminotransferase', 1, 1, 1, 1, NOW(), NOW()),
('TGP_ALT', 'Transaminase Alanina (TGP / ALT)', 'TGP / ALT', 'Função Hepática', 'Soro', 4, 'Marcador específico de integridade hepatocelular para acompanhamento dietoterápico na perda de peso e controle de gordura no fígado.', 'tgp, alt, alanina aminotransferase, enzimas do figado', 'transaminase alanina (tgp / alt) tgp / alt funcao hepatica tgp, alt, alanina aminotransferase, enzimas do figado', 1, 1, 1, 1, NOW(), NOW()),
('GAMA_GT', 'Gama Glutamiltransferase (Gama-GT)', 'Gama-GT', 'Função Hepática', 'Soro', 4, 'Marcador de estresse oxidativo, sobrecarga metabólica hepática e consumo de etanol para conduta nutricional desintoxicante.', 'ggt, gamagt, gama glutamil transferase', 'gama glutamiltransferase (gama-gt) gama-gt funcao hepatica ggt, gamagt, gama glutamil transferase', 1, 1, 1, 1, NOW(), NOW()),
('ACIDO_URICO', 'Ácido úrico sérico', 'Ácido úrico', 'Glicemia e Metabolismo', 'Soro', 8, 'Avaliação do metabolismo das purinas e frutose, orientando a modulação dietética de alimentos ricos em purinas, frutos do mar e açúcares simples.', 'acido urico, urato, uricemia, gota', 'acido urico serico acido urico glicemia e metabolismo acido urico, urato, uricemia, gota', 1, 1, 1, 1, NOW(), NOW()),
('PROTEINAS_TOTAIS_FRACIONADAS', 'Proteínas totais e frações (Albumina e Globulinas)', 'Proteínas totais e frações', 'Nutrição e Proteínas', 'Soro', 4, 'Avaliação do estado de desnutrição proteico-calórica, pressão oncótica e síntese hepática para ajuste proteico dietético.', 'albumina, globulina, proteinas totais, relacao a g, fracoes proteicas', 'proteinas totais e fracoes (albumina e globulinas) proteinas totais e fracoes nutricao e proteinas albumina, globulina, proteinas totais, relacao a g, fracoes proteicas', 1, 1, 1, 1, NOW(), NOW()),
('SODIO_POTASSIO', 'Eletrólitos séricos (Sódio e Potássio)', 'Sódio e Potássio', 'Eletrólitos e Minerais', 'Soro', 0, 'Equilíbrio hidroeletrolítico, conduta dietética em hipertensão (DASH), doença renal ou atletas em desidratação.', 'sodio, potassio, eletrolitos, ionograma', 'eletrolitos sericos (sodio e potassio) sodio e potassio eletrolitos e minerais sodio, potassio, eletrolitos, ionograma', 1, 1, 1, 1, NOW(), NOW()),
('MAGNESIO_SERICO', 'Magnésio sérico', 'Magnésio', 'Eletrólitos e Minerais', 'Soro', 4, 'Avaliação de cofator enzimático essencial em vias energéticas e sensibilidade insulínica, orientando consumo de sementes, oleaginosas e folhas verdes.', 'magnesio, mg serico, magnesemia', 'magnesio serico magnesio eletrolitos e minerais magnesio, mg serico, magnesemia', 1, 1, 1, 1, NOW(), NOW()),
('ZINCO_SERICO', 'Zinco sérico', 'Zinco', 'Micronutrientes e Vitaminas', 'Soro', 8, 'Avaliação de oligoelemento antioxidante, função imunológica, cicatrização e paladar na conduta alimentar.', 'zinco, zn, zinco plasmatico', 'zinco serico zinco micronutrientes e vitaminas zinco, zn, zinco plasmatico', 1, 1, 1, 1, NOW(), NOW()),
('TSH_T4_LIVRE', 'Hormônio Tireoestimulante (TSH) e Tiroxina Livre (T4 Livre)', 'TSH e T4 Livre', 'Tireoide e Hormonal', 'Soro', 4, 'Avaliação do ritmo metabólico basal na determinação do gasto energético total e orientações quanto ao iodo, selênio e bociogênicos da dieta.', 'tsh, t4 livre, tireoide, perfil tireoidiano, tiroxina', 'hormonio tireoestimulante (tsh) e tiroxina livre (t4 livre) tsh e t4 livre tireoide e hormonal tsh, t4 livre, tireoide, perfil tireoidiano, tiroxina', 1, 1, 1, 1, NOW(), NOW()),
('URINA_TIPO_1_EAS', 'Urina tipo 1 (EAS / Elementos Anormais e Sedimento)', 'EAS / Urina tipo 1', 'Urinálise', 'Urina (jato médio)', 0, 'Rastreamento de glicosúria, proteinúria, densidade urinária para hidratação e presença de cristais urinários (uratos, oxalato de cálcio) para conduta preventiva de litíase.', 'eas, urina 1, urina tipo 1, sumario de urina, sedimento urinario', 'urina tipo 1 (eas / elementos anormais e sedimento) eas / urina tipo 1 urinalise eas, urina 1, urina tipo 1, sumario de urina, sedimento urinario', 1, 1, 1, 1, NOW(), NOW());

-- 5. Registro das migrações
INSERT INTO `migrations` (`migration`) SELECT '2026_10_29_000064_create_laboratory_exam_requests_and_catalog' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000064_create_laboratory_exam_requests_and_catalog');
INSERT INTO `migrations` (`migration`) SELECT '2026_10_29_000065_exam_requests_pdf_and_review' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000065_exam_requests_pdf_and_review');

SELECT
  'OK: banco de exames atualizado para a v46' AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) AS tabelas_exames_de_5,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_requests' AND COLUMN_NAME IN ('reviewed_at','issued_by_user_id','pdf_path','pdf_size','pdf_generated_at')) AS colunas_da_65_de_5,
  (SELECT COUNT(*) FROM `exam_catalog`) AS exames_no_catalogo,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` IN ('2026_10_29_000064_create_laboratory_exam_requests_and_catalog','2026_10_29_000065_exam_requests_pdf_and_review')) AS migracoes_64_65_registradas;
