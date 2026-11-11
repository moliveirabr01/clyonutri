<?php

declare(strict_types=1);

/**
 * Migration 60 (v37, "Plano e conta" no perfil do nutricionista): duas tabelas novas, nenhuma coluna alterada.
 *
 *   subscription_plan_changes   histórico de trocas de plano (upgrade/downgrade). Não é fatura: a cobrança é manual
 *                               (billing_mode = 'manual') e a equipe combina a diferença com o cliente.
 *   account_deletion_requests   pedido de exclusão da conta e dos dados (LGPD). O pedido só é REGISTRADO; nada é
 *                               apagado pelo sistema. Fica pendente para o super admin tratar. Sem FK para clinics
 *                               de propósito: o registro do pedido (protocolo, datas, quem pediu) precisa sobreviver
 *                               se a clínica for removida depois. Texto jurídico associado: rascunho para advogado.
 *
 * v39: SEM chave estrangeira em subscription_plan_changes (nem para clinics, clinic_subscriptions ou saas_plans). A v37 as
 * declarava e o CREATE TABLE falhou em produção com #1215 (MySQL 5.7 recusa a FK quando a coluna referenciada não tem
 * exatamente o mesmo tipo/sinal/índice/engine da coluna nova; em produção os id são bigint(20) e as colunas novas eram INT). O app não depende delas: valida clínica, assinatura e planos
 * em PHP antes de gravar e nada apaga clínicas ou planos. Os índices das colunas ficam, então as consultas não perdem nada.
 * O histórico também sobrevive à remoção de uma clínica ou assinatura, como o registro de exclusão de conta.
 *
 * `open_flag` vale 1 enquanto o pedido está aberto (pending/in_review) e NULL depois. Como o MySQL 5.7 não tem índice
 * único parcial, UNIQUE (clinic_id, open_flag) garante no máximo um pedido aberto por clínica (NULLs não colidem).
 *
 * Idempotente (CREATE TABLE IF NOT EXISTS; índices só se faltarem) e compatível com MySQL 5.7 e SQLite.
 */
class CreatePlanChangesAndAccountDeletionRequests
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS subscription_plan_changes (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            protocol VARCHAR(40) NOT NULL,
            change_type VARCHAR(20) NOT NULL,
            from_plan_id INTEGER NOT NULL,
            to_plan_id INTEGER NOT NULL,
            from_plan_code VARCHAR(50) NOT NULL,
            to_plan_code VARCHAR(50) NOT NULL,
            from_price_cents INTEGER NOT NULL,
            to_price_cents INTEGER NOT NULL,
            subscription_status VARCHAR(30) NOT NULL,
            billing_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
            usage_json TEXT NULL,
            idempotency_key VARCHAR(64) NULL,
            requested_by INTEGER NOT NULL,
            effective_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uq_plan_changes_protocol UNIQUE (protocol),
            CONSTRAINT uq_plan_changes_idem UNIQUE (clinic_id, idempotency_key)
        ){$engine}");

        foreach ([
            'idx_plan_changes_clinic' => '(clinic_id, created_at)',
            'idx_plan_changes_recent' => '(created_at)',
            'idx_plan_changes_subscription' => '(subscription_id)',
            'idx_plan_changes_from_plan' => '(from_plan_id)',
            'idx_plan_changes_to_plan' => '(to_plan_id)',
        ] as $index => $columns) {
            if (!$this->hasIndex($db, $mysql, 'subscription_plan_changes', $index)) {
                $db->exec("CREATE INDEX {$index} ON subscription_plan_changes {$columns}");
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS account_deletion_requests (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            clinic_name VARCHAR(150) NOT NULL,
            protocol VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            open_flag INTEGER NULL,
            requested_by INTEGER NOT NULL,
            requester_name VARCHAR(120) NOT NULL,
            requester_role VARCHAR(20) NOT NULL,
            reason_text VARCHAR(500) NULL,
            confirmation_method VARCHAR(30) NOT NULL DEFAULT 'password_and_phrase',
            legal_notice_version VARCHAR(30) NOT NULL,
            requested_at DATETIME NOT NULL,
            deadline_at DATETIME NOT NULL,
            in_review_at DATETIME NULL,
            canceled_at DATETIME NULL,
            canceled_by INTEGER NULL,
            resolved_at DATETIME NULL,
            resolved_by INTEGER NULL,
            resolution_note VARCHAR(1000) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uq_account_deletion_protocol UNIQUE (protocol),
            CONSTRAINT uq_account_deletion_open UNIQUE (clinic_id, open_flag)
        ){$engine}");

        if (!$this->hasIndex($db, $mysql, 'account_deletion_requests', 'idx_account_deletion_status')) {
            $db->exec('CREATE INDEX idx_account_deletion_status ON account_deletion_requests (status, deadline_at)');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS account_deletion_requests');
        $db->exec('DROP TABLE IF EXISTS subscription_plan_changes');
    }

    private function hasIndex(PDO $db, bool $mysql, string $table, string $index): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $index]);
            return (int)$st->fetchColumn() > 0;
        }

        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$index]);
        return (int)$st->fetchColumn() > 0;
    }
}
