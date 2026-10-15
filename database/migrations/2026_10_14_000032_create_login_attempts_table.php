<?php

declare(strict_types=1);

/**
 * Migration 32: limitador persistente de tentativas de login (auditoria de segurança 14/10/2026, F03).
 * Compatível com SQLite, MySQL 5.7/8 e MariaDB (chave única em CHAR(64) cabe no limite de índice do 5.7).
 * Nenhum dado existente é alterado.
 */
class CreateLoginAttemptsTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id {$pk},
            bucket_key CHAR(64) NOT NULL,
            scope VARCHAR(20) NOT NULL,
            failures INTEGER NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            last_attempt_at DATETIME NOT NULL,
            UNIQUE (bucket_key)
        ){$engine}");
        if ($driver !== 'mysql') {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_last ON login_attempts (last_attempt_at)");
        } else {
            $exists = (int)$db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND index_name = 'idx_login_attempts_last'")->fetchColumn();
            if ($exists === 0) {
                $db->exec("CREATE INDEX idx_login_attempts_last ON login_attempts (last_attempt_at)");
            }
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS login_attempts");
    }
}
