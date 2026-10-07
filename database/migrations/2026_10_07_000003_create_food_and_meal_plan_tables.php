<?php

declare(strict_types=1);

class CreateFoodAndMealPlanTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Catálogo de Alimentos (Global ou Custom por Tenant)
        $db->exec("CREATE TABLE IF NOT EXISTS foods (
            id {$pk},
            clinic_id INTEGER NULL,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'TACO',
            energy_kcal DECIMAL(7,2) NOT NULL DEFAULT 0,
            protein_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            carbohydrate_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            lipid_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            fiber_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            serving_size_g DECIMAL(7,2) NOT NULL DEFAULT 100,
            serving_unit VARCHAR(50) NOT NULL DEFAULT 'g',
            is_custom INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");

        // Planos Alimentares
        $db->exec("CREATE TABLE IF NOT EXISTS meal_plans (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, published, archived
            daily_energy_kcal DECIMAL(7,2) NULL,
            notes TEXT NULL,
            published_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");

        // Refeições do Plano
        $db->exec("CREATE TABLE IF NOT EXISTS meal_plan_meals (
            id {$pk},
            meal_plan_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            time_of_day VARCHAR(10) NULL,
            ordering INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE
        ){$engine};");

        // Itens/Alimentos da Refeição
        $db->exec("CREATE TABLE IF NOT EXISTS meal_plan_items (
            id {$pk},
            meal_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            notes TEXT NULL,
            ordering INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (meal_id) REFERENCES meal_plan_meals(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ){$engine};");

        // Substituições de Alimentos
        $db->exec("CREATE TABLE IF NOT EXISTS meal_plan_substitutions (
            id {$pk},
            item_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES meal_plan_items(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ){$engine};");
    }
}
