<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use PDO;
use PDOStatement;

/**
 * Conexão MySQL com tradução mínima de construções do dialeto SQLite usadas nas migrations.
 *
 * As migrations foram escritas primeiro para SQLite. O MySQL 8 não aceita
 * "CREATE INDEX IF NOT EXISTS" nem "PRAGMA table_info(...)"; esta classe traduz
 * apenas esses padrões e repassa todo o resto sem alteração.
 *
 * Também fixa a collation das tabelas novas em utf8mb4_unicode_ci: sem isso o MySQL 8
 * usa utf8mb4_0900_ai_ci, divergindo do SQL de instalação e quebrando comparações de
 * texto entre tabelas ("Illegal mix of collations").
 */
class MySqlCompatPdo extends ProfiledPdo
{
    private const INDEX_IF_NOT_EXISTS = '/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s+ON\s+`?(\w+)`?/i';
    public const COLLATION = 'utf8mb4_unicode_ci';
    private const CREATE_TABLE_CHARSET = '/^(\s*CREATE\s+TABLE\b.*\bCHARSET\s*=\s*utf8mb4\b)(?![^;]*\bCOLLATE\b)/is';
    private const PRAGMA_TABLE_INFO = '/^\s*PRAGMA\s+table_info\(\s*[`"\']?(\w+)[`"\']?\s*\)\s*;?\s*$/i';

    public function exec(string $statement): int|false
    {
        if (preg_match(self::INDEX_IF_NOT_EXISTS, $statement, $m)) {
            if ($this->indexExists($m[3], $m[2])) {
                return 0;
            }
            $statement = (string)preg_replace('/\s+IF\s+NOT\s+EXISTS/i', '', $statement, 1);
        }
        $statement = (string)preg_replace(self::CREATE_TABLE_CHARSET, '$1 COLLATE=' . self::COLLATION, $statement, 1);
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match(self::PRAGMA_TABLE_INFO, $query, $m)) {
            $stmt = $this->prepare(
                "SELECT ORDINAL_POSITION - 1 AS cid, COLUMN_NAME AS name, COLUMN_TYPE AS type,
                        CASE WHEN IS_NULLABLE = 'NO' THEN 1 ELSE 0 END AS notnull,
                        COLUMN_DEFAULT AS dflt_value,
                        CASE WHEN COLUMN_KEY = 'PRI' THEN 1 ELSE 0 END AS pk
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION"
            );
            $stmt->execute([$m[1]]);
            if ($fetchMode !== null) {
                $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
            }
            return $stmt;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->prepare(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
}
