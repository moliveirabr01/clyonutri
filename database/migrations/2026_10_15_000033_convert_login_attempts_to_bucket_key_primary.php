<?php

declare(strict_types=1);

/**
 * Migration 33: alinha login_attempts ao modelo do staging (bucket_key como chave primária, sem id).
 *
 * Motivo: o limitador tenta INSERT e ignora a duplicidade; com id AUTO_INCREMENT cada INSERT recusado
 * consome um número (medido: ~2,5 por tentativa de login). Esgotado o INT, nenhuma linha nova é criada
 * e o limitador passa a bloquear todos os logins, inclusive os corretos.
 *
 * Idempotente e conservadora:
 * - Tabela já no modelo novo (caso do staging): não faz nada.
 * - Tabela antiga (com id): converte preservando as linhas (contadores, bloqueios e IPs conhecidos).
 * - Se houver bucket_key repetido (não deveria, havia UNIQUE), para com erro em vez de descartar dados.
 */
class ConvertLoginAttemptsToBucketKeyPrimary
{
    public function up(PDO $db): void
    {
        $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? $this->mysql($db) : $this->sqlite($db);
    }

    private function mysql(PDO $db): void
    {
        $col = static fn(string $c): ?array => ($r = $db->query("SELECT COLUMN_NAME, COLLATION_NAME, COLUMN_KEY FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = " . $db->quote($c))->fetch(PDO::FETCH_ASSOC)) ? $r : null;
        $bucket = $col('bucket_key');
        if ($bucket === null) {
            throw new RuntimeException('Tabela login_attempts ausente: rode antes a migration 32.');
        }
        $this->assertNoDuplicates($db);

        $parts = [];
        if ($col('id') !== null) {
            // Remover a coluna id remove também a PRIMARY KEY antiga.
            $parts[] = 'DROP COLUMN id';
        }
        $uniqueOnBucket = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'bucket_key'")->fetchColumn();
        if ($uniqueOnBucket > 0) {
            $parts[] = 'DROP INDEX bucket_key';
        }
        if (($bucket['COLLATION_NAME'] ?? '') !== 'ascii_bin') {
            $parts[] = 'MODIFY bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        }
        if ((($col('scope') ?? [])['COLLATION_NAME'] ?? '') !== 'ascii_bin') {
            $parts[] = 'MODIFY scope VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        }
        // Depois de DROP COLUMN id a chave primária some; confere no estado ATUAL se bucket_key já é PRI.
        if ($bucket['COLUMN_KEY'] !== 'PRI' || $col('id') !== null) {
            $parts[] = 'ADD PRIMARY KEY (bucket_key)';
        }
        if ($parts !== []) {
            $db->exec('ALTER TABLE login_attempts ' . implode(', ', $parts));
        }

        $idx = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_last'")->fetchColumn();
        if ($idx === 0) {
            $db->exec('CREATE INDEX idx_login_attempts_last ON login_attempts (last_attempt_at)');
        }
    }

    private function sqlite(PDO $db): void
    {
        $cols = $db->query('PRAGMA table_info(login_attempts)')->fetchAll(PDO::FETCH_ASSOC);
        if ($cols === []) {
            throw new RuntimeException('Tabela login_attempts ausente: rode antes a migration 32.');
        }
        $pk = array_column(array_filter($cols, static fn($c) => (int)$c['pk'] > 0), 'name');
        if ($pk === ['bucket_key']) {
            return; // já no modelo novo
        }
        $this->assertNoDuplicates($db);
        $db->exec('CREATE TABLE login_attempts_new (
            bucket_key CHAR(64) NOT NULL,
            scope VARCHAR(20) NOT NULL,
            failures INTEGER NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            last_attempt_at DATETIME NOT NULL,
            PRIMARY KEY (bucket_key)
        )');
        $db->exec('INSERT INTO login_attempts_new (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at)
            SELECT bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at FROM login_attempts');
        $db->exec('DROP TABLE login_attempts');
        $db->exec('ALTER TABLE login_attempts_new RENAME TO login_attempts');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_last ON login_attempts (last_attempt_at)');
    }

    private function assertNoDuplicates(PDO $db): void
    {
        $dup = (int)$db->query('SELECT COUNT(*) FROM (SELECT bucket_key FROM login_attempts GROUP BY bucket_key HAVING COUNT(*) > 1) d')->fetchColumn();
        if ($dup > 0) {
            throw new RuntimeException("login_attempts tem {$dup} bucket_key repetido(s); nada foi alterado. Revise antes de converter.");
        }
    }

    public function down(PDO $db): void
    {
        // Sem volta: o modelo antigo (com id AUTO_INCREMENT) é justamente o defeito corrigido.
    }
}
