<?php

declare(strict_types=1);

/**
 * Migration 61 (v41, NI2-514 fase 1): três mudanças, todas idempotentes e compatíveis com MySQL 5.7 e SQLite.
 *
 *  1. meal_plan_energy_targets: TMB/GET/VET e metas de macros de cada plano, com a fórmula e os dados de entrada
 *     usados (rastreáveis). Um registro por plano (UNIQUE meal_plan_id). Na publicação, o conteúdo vai para o
 *     snapshot da versão (meal_plan_versions.payload_json), então a versão publicada nunca muda.
 *  2. clinic_household_measures: medidas caseiras cadastradas pela clínica (colher, xícara, fatia...) com gramas e
 *     FONTE obrigatória. Fica separada de food_household_measures (global) para o isolamento por clínica.
 *  3. meal_plans.patient_id passa a aceitar NULL: prescrição sem paciente vinculado, atribuída depois.
 *
 * Tipos e chaves (lição do #1215 da v37): em produção os id são bigint(20). As colunas novas de id são BIGINT(20) no
 * MySQL e as tabelas novas NÃO declaram chave estrangeira; clinic_id, meal_plan_id e food_id têm índice e são validados
 * em PHP. A alteração de meal_plans.patient_id reaproveita o COLUMN_TYPE que a coluna já tem (bigint(20) em produção),
 * então a FK existente fk_meal_plans_1_patient_id continua válida.
 *
 * SQLite não remove NOT NULL com ALTER. Para não recriar meal_plans (o DROP dispararia o ON DELETE CASCADE dos filhos),
 * a restrição é retirada do texto do CREATE TABLE guardado no esquema (método descrito na documentação do SQLite, válido
 * quando a mudança não altera o formato dos dados).
 */
class PlanEnergyClinicMeasuresAndPlanlessPlans
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS meal_plan_energy_targets (
            id {$pk},
            clinic_id {$big} NOT NULL,
            meal_plan_id {$big} NOT NULL,
            patient_id {$big} NULL,
            formula VARCHAR(40) NOT NULL,
            formula_version VARCHAR(20) NOT NULL,
            sex VARCHAR(10) NULL,
            age_years DECIMAL(5,1) NULL,
            weight_kg DECIMAL(6,2) NOT NULL,
            height_cm DECIMAL(5,1) NULL,
            ffm_kg DECIMAL(6,2) NULL,
            inputs_source VARCHAR(255) NULL,
            activity_factor DECIMAL(4,2) NOT NULL,
            activity_label VARCHAR(80) NULL,
            goal_type VARCHAR(20) NOT NULL DEFAULT 'maintenance',
            goal_note VARCHAR(255) NULL,
            adjust_mode VARCHAR(10) NOT NULL DEFAULT 'kcal',
            adjust_value DECIMAL(7,2) NOT NULL DEFAULT 0,
            tmb_kcal DECIMAL(7,1) NOT NULL,
            get_kcal DECIMAL(7,1) NOT NULL,
            vet_kcal DECIMAL(7,1) NOT NULL,
            macro_mode VARCHAR(10) NOT NULL,
            protein_g DECIMAL(7,1) NOT NULL,
            carbohydrate_g DECIMAL(7,1) NOT NULL,
            lipid_g DECIMAL(7,1) NOT NULL,
            protein_pct DECIMAL(5,1) NOT NULL,
            carbohydrate_pct DECIMAL(5,1) NOT NULL,
            lipid_pct DECIMAL(5,1) NOT NULL,
            protein_g_kg DECIMAL(5,2) NULL,
            carbohydrate_g_kg DECIMAL(5,2) NULL,
            lipid_g_kg DECIMAL(5,2) NULL,
            warnings_json TEXT NULL,
            calculated_by_user_id {$big} NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}");
        $this->index($db, $mysql, 'meal_plan_energy_targets', 'uq_energy_targets_plan', '(meal_plan_id)', true);
        $this->index($db, $mysql, 'meal_plan_energy_targets', 'idx_energy_targets_clinic', '(clinic_id, patient_id)');

        $db->exec("CREATE TABLE IF NOT EXISTS clinic_household_measures (
            id {$pk},
            clinic_id {$big} NOT NULL,
            food_id {$big} NOT NULL,
            measure_name VARCHAR(100) NOT NULL,
            grams_equivalent DECIMAL(7,2) NOT NULL,
            source VARCHAR(255) NOT NULL,
            source_ref VARCHAR(255) NULL,
            created_by_user_id {$big} NULL,
            updated_by_user_id {$big} NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}");
        $this->index($db, $mysql, 'clinic_household_measures', 'uq_clinic_measure', '(clinic_id, food_id, measure_name)', true);
        $this->index($db, $mysql, 'clinic_household_measures', 'idx_clinic_measure_food', '(food_id)');

        $this->makePatientIdNullable($db, $mysql);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS clinic_household_measures');
        $db->exec('DROP TABLE IF EXISTS meal_plan_energy_targets');
        // meal_plans.patient_id continua aceitando NULL: voltar a NOT NULL falharia se já houver plano sem paciente.
    }

    private function makePatientIdNullable(PDO $db, bool $mysql): void
    {
        if ($mysql) {
            $st = $db->prepare("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id'");
            $st->execute();
            $col = $st->fetch(PDO::FETCH_ASSOC);
            if ($col && $col['IS_NULLABLE'] === 'NO') {
                $db->exec('ALTER TABLE meal_plans MODIFY patient_id ' . $col['COLUMN_TYPE'] . ' NULL');
            }
            return;
        }

        $row = $db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'meal_plans'")->fetch(PDO::FETCH_ASSOC);
        $sql = (string)($row['sql'] ?? '');
        if ($sql === '' || !preg_match('/\bpatient_id\s+INTEGER\s+NOT\s+NULL/i', $sql)) {
            return;
        }
        $newSql = preg_replace('/\b(patient_id\s+INTEGER)\s+NOT\s+NULL/i', '$1 NULL', $sql, 1);
        $version = (int)$db->query('PRAGMA schema_version')->fetchColumn();
        $db->exec('PRAGMA writable_schema = ON');
        $up = $db->prepare("UPDATE sqlite_master SET sql = :sql WHERE type = 'table' AND name = 'meal_plans'");
        $up->execute(['sql' => $newSql]);
        $db->exec('PRAGMA schema_version = ' . ($version + 1));
        $db->exec('PRAGMA writable_schema = OFF');
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
