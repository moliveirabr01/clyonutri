<?php

declare(strict_types=1);

class EnhanceMealPlansAndPrescriptionsTables
{
    public function up(PDO $db): void
    {
        // 1. Colunas adicionais em meal_plans para macros consolidados e rastreabilidade de duplicação
        $this->addColumnIfNotExists($db, 'meal_plans', 'parent_plan_id', 'INTEGER NULL');
        $this->addColumnIfNotExists($db, 'meal_plans', 'protein_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plans', 'carbohydrate_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plans', 'lipid_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plans', 'fiber_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');

        // 2. Coluna de orientações clínicas específicas da refeição em meal_plan_meals
        $this->addColumnIfNotExists($db, 'meal_plan_meals', 'notes', 'TEXT NULL');

        // 3. Colunas de macros calculados por porção em meal_plan_items
        $this->addColumnIfNotExists($db, 'meal_plan_items', 'energy_kcal', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_items', 'protein_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_items', 'carbohydrate_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_items', 'lipid_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_items', 'fiber_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');

        // 4. Colunas de macros calculados em meal_plan_substitutions
        $this->addColumnIfNotExists($db, 'meal_plan_substitutions', 'energy_kcal', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_substitutions', 'protein_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_substitutions', 'carbohydrate_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_substitutions', 'lipid_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists($db, 'meal_plan_substitutions', 'fiber_g', 'DECIMAL(7,2) NOT NULL DEFAULT 0');
    }

    private function addColumnIfNotExists(PDO $db, string $table, string $column, string $typeDef): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $exists = false;

        if ($driver === 'sqlite') {
            $stmt = $db->query("PRAGMA table_info({$table})");
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (strcasecmp($col['name'], $column) === 0) {
                    $exists = true;
                    break;
                }
            }
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :col
            ");
            $stmt->execute(['table' => $table, 'col' => $column]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
        }

        if (!$exists) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$typeDef};");
        }
    }

    public function down(PDO $db): void
    {
        // Alter table drop column é opcional e pode requerer reconstrução em SQLite antigos
    }
}
