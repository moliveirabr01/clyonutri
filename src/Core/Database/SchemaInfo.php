<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use PDO;

/**
 * Consulta de estrutura com cache por conexão. Usada onde o código novo precisa conviver com um banco
 * que ainda não recebeu a migração manual (deploy na HostGator: código e SQL sobem em passos separados).
 */
final class SchemaInfo
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $key = spl_object_id($db) . ':' . $table . '.' . $column;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $found = false;
                foreach ($db->query('PRAGMA table_info(' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ')')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if (($col['name'] ?? '') === $column) {
                        $found = true;
                        break;
                    }
                }
            } else {
                $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
                $st->execute(['t' => $table, 'c' => $column]);
                $found = (int)$st->fetchColumn() > 0;
            }
        } catch (\Throwable) {
            $found = false;
        }
        return self::$cache[$key] = $found;
    }

    public static function hasTable(PDO $db, string $table): bool
    {
        $key = spl_object_id($db) . ':' . $table;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t");
                $st->execute(['t' => $table]);
                $found = (bool)$st->fetchColumn();
            } else {
                $st = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
                $st->execute(['t' => $table]);
                $found = (int)$st->fetchColumn() > 0;
            }
        } catch (\Throwable) {
            $found = false;
        }
        return self::$cache[$key] = $found;
    }

    /** Testes que migram no meio do processo. */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
