<?php

declare(strict_types=1);
namespace ClyoNutri\Core\Database;
use PDO;

/** Request-local schema snapshots. Weak keys prevent reuse across disposed connections. */
final class SchemaInfo
{
    private static ?\WeakMap $cache = null;

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        self::$cache ??= new \WeakMap();
        $entry = self::$cache[$db] ?? [];
        if (!array_key_exists($table, $entry['columns'] ?? [])) {
            $columns = [];
            try {
                if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $rows = $db->query('PRAGMA table_info(' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ')')->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) $columns[(string)$row['name']] = true;
                } else {
                    $st = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
                    $st->execute(['t' => $table]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) $columns[(string)$name] = true;
                }
            } catch (\Throwable) {
                // Compatibility probe: unavailable schema metadata means the optional feature is absent.
            }
            $entry['columns'][$table] = $columns;
            self::$cache[$db] = $entry;
        }
        return isset($entry['columns'][$table][$column]);
    }

    public static function hasTable(PDO $db, string $table): bool
    {
        self::$cache ??= new \WeakMap();
        $entry = self::$cache[$db] ?? [];
        if (!isset($entry['tables'])) {
            $names = [];
            try {
                $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                    ? "SELECT name FROM sqlite_master WHERE type = 'table'"
                    : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()';
                foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $name) $names[(string)$name] = true;
            } catch (\Throwable) {
                // Old deployments may not expose metadata; optional features must keep their fallback.
            }
            $entry['tables'] = $names;
            self::$cache[$db] = $entry;
        }
        return isset($entry['tables'][$table]);
    }

    /** Required after DDL/migrations in the same process. */
    public static function reset(): void { self::$cache = new \WeakMap(); }
}
