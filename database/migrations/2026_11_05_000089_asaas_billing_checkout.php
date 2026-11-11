<?php

declare(strict_types=1);

/**
 * Migration 89 — NI2-727 slice 1: Asaas Sandbox (checkout hospedado, cobrança recorrente em cartão).
 *
 * MySQL 5.7 compatível (sem JSON nativo / sem CHECK). Também roda em SQLite nos testes locais.
 * Nenhum dado de cartão é armazenado: o pagamento acontece na página hospedada do Asaas.
 */
class AsaasBillingCheckout
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $uq = $driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';

        // Cliente Asaas por clínica (1:1) — somente o id do gateway, sem dados de cartão.
        $db->exec("CREATE TABLE IF NOT EXISTS asaas_customers (
            id {$pk},
            clinic_id INTEGER NOT NULL UNIQUE,
            asaas_customer_id VARCHAR(60) NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");

        // Tentativa de contratação: persistida ANTES da chamada ao gateway.
        // Criar checkout NÃO é pagamento: plan_id da assinatura só muda com evento financeiro confirmado.
        $db->exec("CREATE TABLE IF NOT EXISTS asaas_checkouts (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            plan_id INTEGER NOT NULL,
            requested_by INTEGER NOT NULL,
            idempotency_key {$uq} NOT NULL,
            external_reference VARCHAR(100) NOT NULL UNIQUE,
            asaas_customer_id VARCHAR(60) NULL,
            asaas_checkout_id VARCHAR(60) NULL UNIQUE,
            hosted_url VARCHAR(500) NULL,
            amount_cents INTEGER NOT NULL,
            billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            from_plan_id INTEGER NULL,
            activated_at DATETIME NULL,
            last_event_id VARCHAR(120) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES clinic_subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (plan_id) REFERENCES saas_plans(id),
            UNIQUE (clinic_id, idempotency_key)
        ){$engine};");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_asaas_checkouts_clinic ON asaas_checkouts (clinic_id, status)');

        // Eventos de webhook: idempotência + auditoria sanitizada (sem payload bruto com PII).
        $db->exec("CREATE TABLE IF NOT EXISTS asaas_webhook_events (
            id {$pk},
            event_id VARCHAR(120) NOT NULL UNIQUE,
            event_type VARCHAR(60) NOT NULL,
            external_reference VARCHAR(100) NULL,
            clinic_id INTEGER NULL,
            checkout_id INTEGER NULL,
            payment_id VARCHAR(60) NULL,
            processing_status VARCHAR(20) NOT NULL DEFAULT 'processed',
            detail VARCHAR(255) NULL,
            received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL
        ){$engine};");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_asaas_events_ref ON asaas_webhook_events (external_reference)');

        // Fatura real nasce SOMENTE de evento financeiro (NI2-450: nada de fatura fictícia).
        $db->exec("CREATE TABLE IF NOT EXISTS asaas_payment_receipts (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            checkout_id INTEGER NOT NULL,
            invoice_id INTEGER NOT NULL,
            asaas_payment_id VARCHAR(60) NOT NULL UNIQUE,
            asaas_subscription_id VARCHAR(60) NULL,
            event_type VARCHAR(60) NOT NULL,
            amount_cents INTEGER NOT NULL,
            paid_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (checkout_id) REFERENCES asaas_checkouts(id) ON DELETE CASCADE,
            FOREIGN KEY (invoice_id) REFERENCES subscription_invoices(id) ON DELETE CASCADE
        ){$engine};");
    }
}
