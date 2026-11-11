<?php

declare(strict_types=1);

/**
 * Migration 91 — NI2-767: Central de Eventos de Produção no Backoffice.
 *
 * Tabela de eventos estruturados para observabilidade operacional, rastreabilidade de incidentes,
 * detecção de anomalias e auditoria da plataforma sem expor dados clínicos ou de saúde.
 * Compatível com MySQL 5.7+ / 8.0+ e SQLite (ambientes de teste local).
 */
class CreateProductionEventsTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Tabela central de eventos de produção
        $db->exec("CREATE TABLE IF NOT EXISTS production_events (
            id {$pk},
            event_id VARCHAR(36) NOT NULL UNIQUE,
            schema_version VARCHAR(10) NOT NULL DEFAULT 'v1',
            occurred_at DATETIME NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            environment VARCHAR(20) NOT NULL DEFAULT 'production',
            service VARCHAR(50) NOT NULL DEFAULT 'clyo_core',
            category VARCHAR(30) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            outcome VARCHAR(20) NOT NULL DEFAULT 'success',
            clinic_id INTEGER NULL,
            actor_type VARCHAR(30) NOT NULL DEFAULT 'system',
            actor_id VARCHAR(64) NULL,
            resource_type VARCHAR(50) NULL,
            resource_ref VARCHAR(64) NULL,
            correlation_id VARCHAR(64) NULL,
            request_id VARCHAR(64) NULL,
            duration_ms INTEGER NULL,
            retry_count INTEGER NOT NULL DEFAULT 0,
            metadata TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        $createIndex = function(string $table, string $name, string $cols) use ($db, $driver): void {
            if ($driver === 'mysql') {
                try {
                    $st = $db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$name}' LIMIT 1");
                    if (!$st || !$st->fetchColumn()) {
                        $db->exec("CREATE INDEX {$name} ON {$table} ({$cols})");
                    }
                } catch (\Throwable $e) {
                    // Fallback se information_schema não estiver disponível
                    try { $db->exec("CREATE INDEX {$name} ON {$table} ({$cols})"); } catch (\Throwable $ignored) {}
                }
            } else {
                $db->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
            }
        };

        // Índices de alta performance para busca e filtros no Backoffice
        $createIndex('production_events', 'idx_prod_events_occurred_cat', 'occurred_at, category');
        $createIndex('production_events', 'idx_prod_events_cat_sev', 'category, severity');
        $createIndex('production_events', 'idx_prod_events_clinic_date', 'clinic_id, occurred_at');
        $createIndex('production_events', 'idx_prod_events_correlation', 'correlation_id');
        $createIndex('production_events', 'idx_prod_events_type', 'event_type');

        // Tabela de alertas operacionais ativos e histórico de incidentes
        $db->exec("CREATE TABLE IF NOT EXISTS production_alerts (
            id {$pk},
            alert_id VARCHAR(36) NOT NULL UNIQUE,
            rule_name VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'warning',
            status VARCHAR(20) NOT NULL DEFAULT 'firing',
            description TEXT NOT NULL,
            context_json TEXT NULL,
            triggered_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        $createIndex('production_alerts', 'idx_prod_alerts_status', 'status, severity');
        $createIndex('production_alerts', 'idx_prod_alerts_rule', 'rule_name, status');
    }
}
