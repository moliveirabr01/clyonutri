<?php
declare(strict_types=1);
/**
 * v54.37 · Migração 88: Financeiro com Entrada/Saída e autor da baixa.
 *  1. finance_expenses: saídas da clínica (sem paciente): categoria, descrição, valor, data, situação, forma, pago em.
 *  2. receivables.category (NULL): categoria opcional da entrada (Consulta, Plano, Outros).
 *  3. payments.recorded_by_user_id (NULL, sem FK): quem deu a baixa, para o CRN do recibo.
 * Aditiva e idempotente. FK de finance_expenses.clinic_id no mesmo tipo de clinics.id (INT local, BIGINT na HostGator).
 */
final class FinanceEntriesExpenses
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $fk = 'INT';
        if ($mysql) {
            $type = (string)$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id'")->fetchColumn();
            $fk = str_starts_with(strtolower($type), 'bigint') ? 'BIGINT' : 'INT';
            if (str_contains(strtolower($type), 'unsigned')) $fk .= ' UNSIGNED';
        }
        $db->exec("CREATE TABLE IF NOT EXISTS finance_expenses (
            id $id, clinic_id $fk NOT NULL, category VARCHAR(60) NOT NULL, description VARCHAR(255) NOT NULL,
            amount_cents INT NOT NULL, due_date DATE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(50) NULL, paid_at DATETIME NULL, created_by_user_id $fk NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT
        )$engine");
        if (!$this->hasIndex($db, $mysql, 'finance_expenses', 'idx_finance_expenses_clinic_date')) {
            $db->exec('CREATE INDEX idx_finance_expenses_clinic_date ON finance_expenses (clinic_id, due_date)');
        }
        if (!$this->hasColumn($db, $mysql, 'receivables', 'category')) {
            $db->exec('ALTER TABLE receivables ADD COLUMN category VARCHAR(60) NULL');
        }
        if (!$this->hasColumn($db, $mysql, 'payments', 'recorded_by_user_id')) {
            $db->exec("ALTER TABLE payments ADD COLUMN recorded_by_user_id $fk NULL");
        }
    }

    private function hasColumn(PDO $db, bool $mysql, string $t, string $c): bool
    {
        if ($mysql) {
            $s = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $s->execute([$t, $c]); return (int)$s->fetchColumn() > 0;
        }
        foreach ($db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $r) if ($r['name'] === $c) return true;
        return false;
    }

    private function hasIndex(PDO $db, bool $mysql, string $t, string $i): bool
    {
        if ($mysql) {
            $s = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $s->execute([$t, $i]); return (int)$s->fetchColumn() > 0;
        }
        $s = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $s->execute([$i]); return (int)$s->fetchColumn() > 0;
    }
}
