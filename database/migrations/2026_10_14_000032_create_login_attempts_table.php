<?php

declare(strict_types=1);

/**
 * Migration 32: limitador persistente de tentativas de login (auditoria de segurança 14/10/2026, F03).
 * Compatível com SQLite, MySQL 5.7/8 e MariaDB. Nenhum dado existente é alterado.
 *
 * Modelo (v6, igual ao staging): bucket_key é a CHAVE PRIMÁRIA, sem coluna id.
 * O limitador sempre tenta INSERT e ignora a duplicidade; com id AUTO_INCREMENT cada INSERT recusado
 * consumiria um número (~2,5 por tentativa de login), e o esgotamento do INT bloquearia todos os logins.
 * bucket_key/scope em ascii_bin: hash hexadecimal, comparação binária, 64 bytes no índice.
 * Bancos já criados com o modelo antigo (com id) são convertidos pela migration 33.
 */
class CreateLoginAttemptsTable
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $ascii = $mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            bucket_key CHAR(64){$ascii} NOT NULL,
            scope VARCHAR(20){$ascii} NOT NULL,
            failures INTEGER NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            last_attempt_at DATETIME NOT NULL,
            PRIMARY KEY (bucket_key)
        ){$engine}");
        if (!$mysql) {
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
