<?php

declare(strict_types=1);

/**
 * Migration 62 (v42, NI2-514 fase 2): idempotente e compatível com MySQL 5.7 e SQLite.
 *
 *  1. meal_plan_templates (já existe desde a migração 5, sem uso na interface até a v41) ganha o ciclo de vida dos
 *     modelos da clínica: status (active/archived), quem criou, de qual plano veio, quando foi arquivado e se é a
 *     estrutura padrão de refeições da clínica. Nenhuma linha existente muda: status nasce 'active'.
 *  2. food_search_metrics: contagem diária de buscas e de buscas sem resultado por termo normalizado e por clínica
 *     (NI2-520, taxa de busca sem resultado). Guarda só o termo digitado no catálogo, sem dado de paciente.
 *  3. patient_import_batches e patient_import_rows: importação de pacientes por CSV com prévia, relatório por linha,
 *     idempotência pelo hash do arquivo (UNIQUE clinic_id + file_sha256) e desfazer (NI2-522).
 *
 * Tipos e chaves (lição do #1215): ids BIGINT(20) no MySQL e nenhuma chave estrangeira nova; clinic_id e os demais
 * ids têm índice e são validados em PHP, como nas migrações 60 e 61.
 */
class PlanTemplatesFoodSearchAndPatientImport
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $columns = [
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'source_plan_id' => "{$big} NULL",
            'created_by_user_id' => "{$big} NULL",
            'is_clinic_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'archived_at' => 'DATETIME NULL',
            'updated_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $name => $definition) {
            if (!$this->hasColumn($db, $mysql, 'meal_plan_templates', $name)) {
                $db->exec("ALTER TABLE meal_plan_templates ADD COLUMN {$name} {$definition}");
            }
        }
        $this->index($db, $mysql, 'meal_plan_templates', 'idx_plan_templates_clinic_status', '(clinic_id, status)');

        $db->exec("CREATE TABLE IF NOT EXISTS food_search_metrics (
            id {$pk},
            clinic_id {$big} NOT NULL,
            metric_date DATE NOT NULL,
            term VARCHAR(80) NOT NULL,
            searches INT NOT NULL DEFAULT 0,
            zero_results INT NOT NULL DEFAULT 0,
            last_result_count INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}");
        $this->index($db, $mysql, 'food_search_metrics', 'uq_food_search_metric', '(clinic_id, metric_date, term)', true);

        $db->exec("CREATE TABLE IF NOT EXISTS patient_import_batches (
            id {$pk},
            clinic_id {$big} NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            file_name VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'preview',
            mapping_json TEXT NULL,
            total_rows INT NOT NULL DEFAULT 0,
            valid_rows INT NOT NULL DEFAULT 0,
            created_rows INT NOT NULL DEFAULT 0,
            skipped_rows INT NOT NULL DEFAULT 0,
            error_rows INT NOT NULL DEFAULT 0,
            created_by_user_id {$big} NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            rolled_back_at DATETIME NULL,
            rolled_back_rows INT NOT NULL DEFAULT 0
        ){$engine}");
        $this->index($db, $mysql, 'patient_import_batches', 'uq_patient_import_file', '(clinic_id, file_sha256)', true);

        $db->exec("CREATE TABLE IF NOT EXISTS patient_import_rows (
            id {$pk},
            batch_id {$big} NOT NULL,
            clinic_id {$big} NOT NULL,
            line_no INT NOT NULL,
            raw_json TEXT NULL,
            outcome VARCHAR(20) NOT NULL DEFAULT 'pending',
            message VARCHAR(255) NULL,
            patient_id {$big} NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}");
        $this->index($db, $mysql, 'patient_import_rows', 'idx_patient_import_rows_batch', '(batch_id, line_no)');
        $this->index($db, $mysql, 'patient_import_rows', 'idx_patient_import_rows_clinic', '(clinic_id, patient_id)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS patient_import_rows');
        $db->exec('DROP TABLE IF EXISTS patient_import_batches');
        $db->exec('DROP TABLE IF EXISTS food_search_metrics');
        // As colunas de meal_plan_templates ficam: são opcionais e não atrapalham a versão anterior.
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    private function index(PDO $db, bool $mysql, string $table, string $name, string $columns, bool $unique = false): void
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $name]);
        } else {
            $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
            $st->execute([$name]);
        }
        if ((int)$st->fetchColumn() === 0) {
            $db->exec(sprintf('CREATE %sINDEX %s ON %s %s', $unique ? 'UNIQUE ' : '', $name, $table, $columns));
        }
    }
}
