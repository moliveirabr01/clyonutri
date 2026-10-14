<?php

declare(strict_types=1);

/**
 * Migration 30: Criação das tabelas de Assinaturas SaaS, Catálogo de Planos e Cancelamento Autosserviço (NI2-364, NI2-365, NI2-374)
 */
class CreateSaasSubscriptionsAndCancellationTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Catálogo Oficial de Planos SaaS
        $db->exec("CREATE TABLE IF NOT EXISTS saas_plans (
            id {$pk},
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            price_cents INTEGER NOT NULL,
            billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
            max_professionals INTEGER NOT NULL DEFAULT 1,
            max_patients INTEGER NOT NULL DEFAULT 50,
            storage_mb INTEGER NOT NULL DEFAULT 2048,
            features_json TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // Popular os 3 planos oficiais do catálogo
        $stmtCheck = $db->query("SELECT COUNT(*) FROM saas_plans");
        if ($stmtCheck && (int)$stmtCheck->fetchColumn() === 0) {
            $stmtInsert = $db->prepare("INSERT INTO saas_plans (code, name, price_cents, billing_cycle, max_professionals, max_patients, storage_mb, features_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            // START-01
            $stmtInsert->execute([
                'START-01',
                'ClyoNutri Start',
                9900,
                'monthly',
                1,
                50,
                2048,
                json_encode(['teleconsultation' => false, 'unlimited_patients' => false, 'priority_support' => false])
            ]);

            // PRO-02
            $stmtInsert->execute([
                'PRO-02',
                'ClyoNutri Profissional',
                18900,
                'monthly',
                3,
                0, // 0 = ilimitado
                15360,
                json_encode(['teleconsultation' => true, 'unlimited_patients' => true, 'priority_support' => true])
            ]);

            // ELITE-03
            $stmtInsert->execute([
                'ELITE-03',
                'ClyoNutri Clínica Elite',
                38900,
                'monthly',
                0, // 0 = ilimitado
                0, // 0 = ilimitado
                102400,
                json_encode(['teleconsultation' => true, 'unlimited_patients' => true, 'priority_support' => true, 'dedicated_manager' => true])
            ]);
        }

        // 2. Assinaturas das Clínicas / Tenants
        $db->exec("CREATE TABLE IF NOT EXISTS clinic_subscriptions (
            id {$pk},
            clinic_id INTEGER NOT NULL UNIQUE,
            plan_id INTEGER NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
            price_cents INTEGER NOT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            current_period_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            current_period_end DATETIME NOT NULL,
            canceled_at DATETIME NULL,
            cancellation_protocol VARCHAR(50) NULL,
            cancellation_reason_category VARCHAR(60) NULL,
            cancellation_feedback TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (plan_id) REFERENCES saas_plans(id)
        ){$engine};");

        // 3. Histórico de Faturas da Assinatura
        $db->exec("CREATE TABLE IF NOT EXISTS subscription_invoices (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            amount_cents INTEGER NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'paid',
            due_date DATE NOT NULL,
            paid_at DATETIME NULL,
            payment_method VARCHAR(50) NOT NULL DEFAULT 'credit_card',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES clinic_subscriptions(id) ON DELETE CASCADE
        ){$engine};");

        // 4. Pesquisas de Cancelamento / Voz do Cliente em Saída (Sem dados de saúde / PHI)
        $db->exec("CREATE TABLE IF NOT EXISTS cancellation_surveys (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            protocol VARCHAR(50) NOT NULL UNIQUE,
            reason_category VARCHAR(60) NULL,
            feedback_text TEXT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES clinic_subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS cancellation_surveys;");
        $db->exec("DROP TABLE IF EXISTS subscription_invoices;");
        $db->exec("DROP TABLE IF EXISTS clinic_subscriptions;");
        $db->exec("DROP TABLE IF EXISTS saas_plans;");
    }
}
