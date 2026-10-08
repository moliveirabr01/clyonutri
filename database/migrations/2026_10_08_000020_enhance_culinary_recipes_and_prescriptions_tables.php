<?php

declare(strict_types=1);

/**
 * Migration 20: Aperfeiçoamento do Módulo de Receitas Culinárias, Prescrição e Lista de Compras (NI2-268 a NI2-280)
 */
class EnhanceCulinaryRecipesAndPrescriptionsTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Adicionar colunas adicionais em recipes se não existirem
        $recipeCols = [];
        if ($driver === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(recipes)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $recipeCols[] = $col['name'];
            }
        } else {
            $stmt = $db->query("SHOW COLUMNS FROM recipes");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $recipeCols[] = $row['Field'];
            }
        }

        $addRecipeCols = [
            'summary' => 'TEXT NULL',
            'meal_type' => 'VARCHAR(60) NULL',
            'difficulty' => "VARCHAR(20) NOT NULL DEFAULT 'fácil'",
            'cook_time_minutes' => 'INTEGER NOT NULL DEFAULT 0',
            'serving_size_name' => 'VARCHAR(100) NULL',
            'serving_size_g' => 'DECIMAL(7,2) NULL',
            'fiber_g_per_serving' => 'DECIMAL(7,2) NOT NULL DEFAULT 0',
            'sodium_mg_per_serving' => 'DECIMAL(7,2) NOT NULL DEFAULT 0',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'published'",
            'visibility' => "VARCHAR(20) NOT NULL DEFAULT 'clinic'",
            'parent_recipe_id' => 'INTEGER NULL',
            'author_user_id' => 'INTEGER NULL',
            'photo_path' => 'VARCHAR(255) NULL',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        ];

        foreach ($addRecipeCols as $colName => $colDef) {
            if (!in_array($colName, $recipeCols, true)) {
                $db->exec("ALTER TABLE recipes ADD COLUMN {$colName} {$colDef};");
            }
        }

        // 2. Adicionar colunas adicionais em recipe_ingredients
        $ingCols = [];
        if ($driver === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(recipe_ingredients)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $ingCols[] = $col['name'];
            }
        } else {
            $stmt = $db->query("SHOW COLUMNS FROM recipe_ingredients");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ingCols[] = $row['Field'];
            }
        }

        $addIngCols = [
            'gross_weight_g' => 'DECIMAL(7,2) NULL',
            'net_weight_g' => 'DECIMAL(7,2) NULL',
            'correction_factor' => 'DECIMAL(5,2) NOT NULL DEFAULT 1.0',
            'cooking_factor' => 'DECIMAL(5,2) NOT NULL DEFAULT 1.0',
            'ordering' => 'INTEGER NOT NULL DEFAULT 1',
        ];

        foreach ($addIngCols as $colName => $colDef) {
            if (!in_array($colName, $ingCols, true)) {
                $db->exec("ALTER TABLE recipe_ingredients ADD COLUMN {$colName} {$colDef};");
            }
        }

        // 3. Versões Imutáveis de Receitas (NI2-269 / NI2-275)
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_versions (
            id {$pk},
            recipe_id INTEGER NOT NULL,
            version_number INTEGER NOT NULL DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            servings INTEGER NOT NULL DEFAULT 1,
            serving_size_g DECIMAL(7,2) NULL,
            energy_kcal_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            protein_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            carbs_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            fat_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            fiber_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            sodium_mg_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            payload_json TEXT NOT NULL, -- Snapshot imutável de ingredientes e passos
            status VARCHAR(20) NOT NULL DEFAULT 'published', -- draft, published, superseded
            published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            published_by_user_id INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ){$engine};");

        // 4. Passos Ordenados de Preparo (NI2-270)
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_steps (
            id {$pk},
            recipe_id INTEGER NOT NULL,
            step_number INTEGER NOT NULL,
            instruction TEXT NOT NULL,
            tip TEXT NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ){$engine};");

        // 5. Tags e Categorizações Alimentares (NI2-273)
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_tags (
            id {$pk},
            recipe_id INTEGER NOT NULL,
            tag_name VARCHAR(50) NOT NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ){$engine};");

        // 6. Alergênicos e Restrições Verificáveis (NI2-274)
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_allergens (
            id {$pk},
            recipe_id INTEGER NOT NULL,
            allergen_group VARCHAR(60) NOT NULL,
            risk_level VARCHAR(20) NOT NULL DEFAULT 'contains', -- contains, may_contain_traces, free_from
            verified_by_professional INTEGER NOT NULL DEFAULT 1,
            notes VARCHAR(255) NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ){$engine};");

        // 7. Prescrição de Receita no Plano Alimentar (NI2-275)
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_prescriptions (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            meal_plan_id INTEGER NOT NULL,
            meal_id INTEGER NULL,
            patient_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            recipe_version_id INTEGER NULL,
            prescribed_servings DECIMAL(4,2) NOT NULL DEFAULT 1.0,
            meal_name VARCHAR(100) NULL,
            instructions_override TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ){$engine};");

        // 8. Favoritos do Paciente (NI2-277)
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_patient_favorites (
            id {$pk},
            patient_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ){$engine};");

        // Criar índices de busca e performance
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_recipes_clinic ON recipes(clinic_id, status);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_recipe_prescriptions_patient ON recipe_prescriptions(patient_id, meal_plan_id);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_recipe_versions_recipe ON recipe_versions(recipe_id, version_number);");
        } catch (\Throwable $e) {
            // Ignora se já existir
        }
    }
}
