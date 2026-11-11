<?php

declare(strict_types=1);

/**
 * Migration 65 (v46, NI2-529): pedido de exames com revisão e PDF gerado no servidor.
 *
 *  - Não mexe em exam_regulatory_policies. A emissão não depende de liberação nem de aprovação (v46); as colunas
 *    is_approved_clinical e is_approved_legal da 64 ficam como estão e o sistema não as lê.
 *  - exam_requests: reviewed_at, issued_by_user_id e o PDF guardado fora de public/ (pdf_path, pdf_size,
 *    pdf_generated_at). pdf_hash (já existente) passa a ser o SHA-256 dos bytes do PDF. Ciência do aviso de
 *    cobertura com padrão 0 (o valor real vem do formulário).
 *
 * Idempotente (coluna e índice só se faltarem), sem chave estrangeira, MySQL 5.7/8 e SQLite.
 */
class ExamRequestsPdfAndReview
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';

        $this->columns($db, $mysql, 'exam_requests', [
            'reviewed_at' => 'DATETIME NULL',
            'issued_by_user_id' => "{$big} NULL",
            'pdf_path' => 'VARCHAR(255) NULL',
            'pdf_size' => 'INT NULL',
            'pdf_generated_at' => 'DATETIME NULL',
        ]);

        if ($mysql) {
            $db->exec('ALTER TABLE exam_requests ALTER COLUMN coverage_disclaimer_acknowledged SET DEFAULT 0');
        }

        $this->index($db, $mysql, 'exam_audit_logs', 'idx_exam_audit_entity', '(entity_type, entity_id)');
    }

    public function down(PDO $db): void
    {
        // Sem volta automática: as colunas guardam a trilha do documento emitido.
    }

    private function columns(PDO $db, bool $mysql, string $table, array $columns): void
    {
        foreach ($columns as $name => $definition) {
            if (!$this->hasColumn($db, $mysql, $table, $name)) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
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

    private function index(PDO $db, bool $mysql, string $table, string $name, string $cols): void
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $name]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE {$table} ADD INDEX {$name} {$cols}");
            }
        } else {
            $db->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} {$cols}");
        }
    }
}
