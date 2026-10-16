<?php

declare(strict_types=1);

/**
 * Migration 39 (v13): código de acesso do app à teleconsulta de uso único.
 * Cada bilhete "q." é trocado uma única vez por um cookie preso ao navegador (browser_key_hash).
 * Sem chaves estrangeiras (produção usa BIGINT; mesmo padrão da 036). Linhas vencidas são limpas pela aplicação.
 */
class CreateTeleconsultationTicketRedemptions
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'mysql' ? 'BIGINT' : 'INTEGER';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS teleconsultation_ticket_redemptions (
            ticket_hash CHAR(64) NOT NULL PRIMARY KEY,
            session_id {$id} NOT NULL,
            clinic_id {$id} NOT NULL,
            browser_key_hash CHAR(64) NOT NULL,
            redeemed_at_utc DATETIME NOT NULL,
            expires_at_utc DATETIME NOT NULL
        ){$engine}");
        $exists = false;
        if ($driver === 'mysql') {
            $exists = (bool)$db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()
                AND table_name = 'teleconsultation_ticket_redemptions' AND index_name = 'idx_tele_ticket_red_expires'")->fetchColumn();
        }
        if (!$exists) {
            $db->exec($driver === 'mysql'
                ? 'CREATE INDEX idx_tele_ticket_red_expires ON teleconsultation_ticket_redemptions (expires_at_utc)'
                : 'CREATE INDEX IF NOT EXISTS idx_tele_ticket_red_expires ON teleconsultation_ticket_redemptions (expires_at_utc)');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS teleconsultation_ticket_redemptions');
    }
}
