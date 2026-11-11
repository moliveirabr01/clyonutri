<?php
declare(strict_types=1);
final class CreateDietaryConfirmations
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        // Produção HostGator (dump atual) usa BIGINT nas tabelas-base; instalações locais
        // a partir de instalacao_mysql8.sql usam INT. A FK exige o mesmo tipo do id referenciado.
        $fk = 'INT';
        if ($mysql) {
            $type = (string) $db->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id'"
            )->fetchColumn();
            if (str_starts_with(strtolower($type), 'bigint')) {
                $fk = 'BIGINT';
            }
            $parents = $db->query(
                "SELECT COUNT(DISTINCT COLUMN_TYPE) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('clinics','patients','meal_plans','users','professionals')
                   AND COLUMN_NAME = 'id'"
            )->fetchColumn();
            if ((int) $parents !== 1) {
                throw new RuntimeException(
                    'Migração 087: clinics/patients/meal_plans/users/professionals.id têm tipos misturados; não cria dietary_confirmations'
                );
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS dietary_confirmations (
            id $id, clinic_id $fk NOT NULL, patient_id $fk NOT NULL, plan_id $fk NOT NULL,
            user_id $fk NOT NULL, professional_id $fk NOT NULL, request_key VARCHAR(80) NOT NULL,
            context_sha256 CHAR(64) NOT NULL, suggestions_json $text NOT NULL, rationale TEXT NOT NULL,
            created_at DATETIME NOT NULL, UNIQUE(clinic_id,user_id,request_key),
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT,
            FOREIGN KEY (plan_id) REFERENCES meal_plans(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE RESTRICT
        )$engine");
    }
}
