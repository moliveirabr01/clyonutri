-- NI2-703 / v54.32 (corrigido em v54.33b): fallback HostGator quando o id das tabelas-base é BIGINT.
-- Preferir o trio /workspace/release/v54-33b-sql (detecta INT ou BIGINT e registra a migração 087).
-- Executar SOMENTE após confirmar a linhagem até 086 e backup testado.
-- Idempotente. Não insere protocolos, aprovações ou dados de pacientes.
CREATE TABLE IF NOT EXISTS dietary_confirmations (
 id INT AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT NOT NULL, patient_id BIGINT NOT NULL, plan_id BIGINT NOT NULL,
 user_id BIGINT NOT NULL, professional_id BIGINT NOT NULL, request_key VARCHAR(80) NOT NULL,
 context_sha256 CHAR(64) NOT NULL, suggestions_json LONGTEXT NOT NULL,
 rationale TEXT NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE(clinic_id,user_id,request_key),
 FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT,
 FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT,
 FOREIGN KEY (plan_id) REFERENCES meal_plans(id) ON DELETE RESTRICT,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
