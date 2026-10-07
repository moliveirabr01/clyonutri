<?php

declare(strict_types=1);

class CreateAdvancedPrescriptionAndRecipesTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Templates de Planos Alimentares
        $db->exec("CREATE TABLE IF NOT EXISTS meal_plan_templates (
            id {$pk},
            clinic_id INTEGER NULL, -- null = template global do sistema
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL, -- Low Carb, Hipertrofia, Vegetariano, Equilíbrio
            description TEXT NULL,
            target_energy_kcal DECIMAL(7,2) NULL,
            payload_json TEXT NOT NULL, -- Estrutura de refeições, porções e horários
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");

        // 2. Módulo de Receitas Culinárias
        $db->exec("CREATE TABLE IF NOT EXISTS recipes (
            id {$pk},
            clinic_id INTEGER NULL, -- null = receita padrão da biblioteca
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            prep_time_minutes INTEGER NOT NULL DEFAULT 15,
            servings INTEGER NOT NULL DEFAULT 1,
            instructions TEXT NOT NULL,
            energy_kcal_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            protein_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            carbs_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            fat_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            is_custom INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");

        // 3. Ingredientes da Receita
        $db->exec("CREATE TABLE IF NOT EXISTS recipe_ingredients (
            id {$pk},
            recipe_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            notes VARCHAR(255) NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ){$engine};");

        // 4. Grupos de Equivalência Nutricional
        $db->exec("CREATE TABLE IF NOT EXISTS food_equivalence_groups (
            id {$pk},
            clinic_id INTEGER NULL,
            group_name VARCHAR(100) NOT NULL, -- Carboidratos Complexos, Proteínas Magras, Frutas
            reference_description VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        $db->exec("CREATE TABLE IF NOT EXISTS food_equivalence_items (
            id {$pk},
            group_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            equivalent_amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            FOREIGN KEY (group_id) REFERENCES food_equivalence_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ){$engine};");
    }
}
