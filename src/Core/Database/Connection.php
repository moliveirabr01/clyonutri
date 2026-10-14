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
            if ($dbPath !== ':memory:' && !str_starts_with($dbPath, '/')) {
                $dbPath = __DIR__ . '/../../../' . $dbPath;
            }
            $needsTestSchema = false;
            if ($dbPath !== ':memory:' && self::isTestScript() && self::isDevelopmentDatabase($dbPath)) {
                // Scripts em tests/ nunca escrevem no banco de desenvolvimento/demonstração.
                $dbPath = getenv('TEST_DB_DATABASE') ?: self::projectRoot() . '/storage/testing.sqlite';
                $needsTestSchema = !file_exists($dbPath) || filesize($dbPath) === 0;
                // Subprocessos disparados pelo teste (shell_exec/php -S) herdam o mesmo banco isolado.
                putenv('DB_CONNECTION=sqlite');
                putenv('DB_DATABASE=' . $dbPath);
                $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $dbPath;
            }
            if ($dbPath !== ':memory:') {
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }
            self::$instance = new PDO('sqlite:' . $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA foreign_keys = ON;');
            self::$instance->exec('PRAGMA journal_mode = WAL;');
            self::$instance->exec('PRAGMA synchronous = NORMAL;');
            self::$instance->exec('PRAGMA busy_timeout = 30000;');
            if ($needsTestSchema) {
                self::prepareTestDatabase(self::$instance);
            }
            return;
        }

        if ($driver === 'mysql') {
            if (self::isTestScript() && !preg_match('/test/i', (string)(getenv('DB_DATABASE') ?: 'clyonutri')) && getenv('ALLOW_TESTS_ON_DEV_DB') !== '1') {
                throw new RuntimeException('Testes recusados: DB_DATABASE MySQL não é um banco de testes (o nome deve conter "test").');
            }
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $db = getenv('DB_DATABASE') ?: 'clyonutri';
            $user = getenv('DB_USERNAME') ?: 'root';
            $pass = getenv('DB_PASSWORD') ?: '';
            $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            self::$instance = new MySqlCompatPdo($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Prepares emulados: o código reutiliza placeholders nomeados (ex.: :q duas vezes),
                // o que o MySQL nativo rejeita (HY093). Seguro com charset definido na DSN; no PHP 8.1+
                // inteiros e floats continuam retornando com tipo nativo.
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            // Alinha CURRENT_TIMESTAMP/NOW() do MySQL ao fuso da aplicação (APP_TIMEZONE).
            // Sem isso, em hospedagem compartilhada o MySQL usa o fuso do servidor e "hoje" diverge do PHP.
            $appTz = getenv('APP_TIMEZONE') ?: date_default_timezone_get();
            try {
                $offset = (new \DateTimeImmutable('now', new \DateTimeZone($appTz)))->format('P');
            } catch (\Throwable) {
                $offset = (new \DateTimeImmutable('now'))->format('P');
            }
            self::$instance->exec("SET time_zone = '{$offset}'");
            if ($charset === 'utf8mb4') {
                self::$instance->exec("SET NAMES utf8mb4 COLLATE " . MySqlCompatPdo::COLLATION);
            }
            return;
        }

        throw new RuntimeException("Driver de banco de dados não suportado: {$driver}");
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Verdadeiro quando o processo CLI foi iniciado por um script dentro de tests/. */
    private static function isTestScript(): bool
    {
        if (PHP_SAPI !== 'cli' || getenv('ALLOW_TESTS_ON_DEV_DB') === '1') {
            return false;
        }
        $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
        $testsDir = realpath(self::projectRoot() . '/tests') ?: self::projectRoot() . '/tests';
        return $script !== '' && str_starts_with($script, $testsDir . DIRECTORY_SEPARATOR);
    }

    private static function isDevelopmentDatabase(string $dbPath): bool
    {
        $devPath = self::projectRoot() . '/storage/database.sqlite';
        $resolved = realpath($dbPath) ?: $dbPath;
        return $resolved === (realpath($devPath) ?: $devPath);
    }

    /** Banco de testes novo: schema + catálogo + conta demo local (dados sintéticos). */
    private static function prepareTestDatabase(PDO $pdo): void
    {
        (new Migrator($pdo, self::projectRoot() . '/database/migrations'))->run();
        (new CatalogSeeder($pdo))->run();
        $previousEnv = getenv('APP_ENV');
        putenv('APP_ENV=testing');
        try {
            (new DemoSeeder($pdo))->run();
        } finally {
            $previousEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousEnv);
        }
    }
}
