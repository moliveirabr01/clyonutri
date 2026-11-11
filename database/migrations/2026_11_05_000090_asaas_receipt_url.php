<?php
declare(strict_types=1);
/**
 * v54.51 · Migração 90: asaas_payment_receipts.receipt_url (NULL) — link do recibo/fatura no Asaas.
 * Aditiva e idempotente; MySQL 5.7 compatível (VARCHAR, sem JSON nativo).
 */
final class AsaasReceiptUrl
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (!$this->hasColumn($db, $mysql, 'asaas_payment_receipts', 'receipt_url')) {
            $db->exec('ALTER TABLE asaas_payment_receipts ADD COLUMN receipt_url VARCHAR(500) NULL');
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
}
