<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use PDO;
use RuntimeException;

class Connection
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::init();
        }

        return self::$instance;
    }

    public static function setInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    private static function init(): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        if ($driver === 'sqlite') {
            $dbPath = getenv('DB_DATABASE') ?: __DIR__ . '/../../../storage/database.sqlite';
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = __DIR__ . '/../../../' . $dbPath;
            }
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$instance = new PDO('sqlite:' . $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA foreign_keys = ON;');
            return;
        }

        if ($driver === 'mysql') {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $db = getenv('DB_DATABASE') ?: 'clyonutri';
            $user = getenv('DB_USERNAME') ?: 'root';
            $pass = getenv('DB_PASSWORD') ?: '';
            $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return;
        }

        throw new RuntimeException("Driver de banco de dados não suportado: {$driver}");
    }
}
