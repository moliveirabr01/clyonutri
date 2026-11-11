<?php
/**
 * Base dos testes da v47 (NI2-529). Banco SQLite isolado e novo a cada execução (storage/testing-v47.sqlite).
 * Dados só de TESTE. Os testes não vão no zip.
 */
declare(strict_types=1);

define('CLYONUTRI_TESTING', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$dbFile = $root . '/storage/testing-v47.sqlite';
putenv('APP_TIMEZONE=America/Sao_Paulo'); date_default_timezone_set('America/Sao_Paulo');
foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $f) {
    if (file_exists($f)) {
        unlink($f);
    }
}
putenv('DB_CONNECTION=sqlite');
putenv('TEST_DB_DATABASE=' . $dbFile);
putenv('DB_DATABASE=' . $dbFile);
putenv('APP_ENV=testing');
$pdfDir = sys_get_temp_dir() . '/clyonutri-v47-pdf-' . getmypid();
putenv('EXAM_PDF_STORAGE_DIR=' . $pdfDir);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once $root . '/vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;

if (getenv('V47_MYSQL_SOCKET')) {
    // Variante MySQL/MariaDB: banco clyonutri_test47 novo, carregado com a instalação da v46 (até a 65) e a 66 pela migração PHP.
    $sock = getenv('V47_MYSQL_SOCKET');
    $admin = new PDO("mysql:unix_socket={$sock};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('DROP DATABASE IF EXISTS clyonutri_test47');
    $admin->exec('CREATE DATABASE clyonutri_test47 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $base = getenv('V47_BASE_INSTALL') ?: '/home/claude/v46pkg/database/instalacao_mysql8.sql';
    passthru('mariadb -S ' . escapeshellarg($sock) . ' -uroot clyonutri_test47 < ' . escapeshellarg($base), $rc);
    if ($rc !== 0) { fwrite(STDERR, "falha ao carregar a instalação base\n"); exit(2); }
    $pdo = new \ClyoNutri\Core\Database\MySqlCompatPdo("mysql:unix_socket={$sock};dbname=clyonutri_test47;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $ran = (new \ClyoNutri\Core\Database\Migrator($pdo, $root . '/database/migrations'))->run();
    fwrite(STDOUT, '[MySQL] migrações aplicadas pelo PHP: ' . implode(', ', $ran) . "\n");
} else {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    (new \ClyoNutri\Core\Database\Migrator($pdo, $root . '/database/migrations'))->run();
}
Connection::setInstance($pdo);

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];

    public static function ok(bool $cond, string $label): void
    {
        if ($cond) {
            self::$pass++;
            fwrite(STDOUT, "  PASS  {$label}\n");
        } else {
            self::$fail++;
            self::$failures[] = $label;
            fwrite(STDOUT, "  FAIL  {$label}\n");
        }
    }

    /** Executa $fn e confere que lança exceção (opcionalmente com trecho da mensagem). */
    public static function throws(callable $fn, string $label, ?string $contains = null): void
    {
        try {
            $fn();
            self::ok(false, $label . ' (não lançou)');
        } catch (\Throwable $e) {
            $okMsg = $contains === null || str_contains($e->getMessage(), $contains);
            self::ok($okMsg, $label . ($okMsg ? '' : ' (mensagem: ' . $e->getMessage() . ')'));
        }
    }

    public static function done(): void
    {
        fwrite(STDOUT, "\n" . self::$pass . ' PASS, ' . self::$fail . " FAIL\n");
        foreach (self::$failures as $f) {
            echo "  - {$f}\n";
        }
        exit(self::$fail === 0 ? 0 : 1);
    }
}

final class Fx
{
    public static function clinic(PDO $db, string $name): int
    {
        $db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (?, ?, 'active')")->execute([bin2hex(random_bytes(8)), $name]);
        return (int)$db->lastInsertId();
    }

    /** Usuário + vínculo na clínica (+ perfil profissional se $crn não for null). */
    public static function user(PDO $db, int $clinicId, string $name, string $clinicRole, ?string $crn = '12345', ?string $region = 'CRN-3', string $globalRole = 'nutritionist'): array
    {
        $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, 'x', ?, 1, 'active')")
            ->execute([bin2hex(random_bytes(8)), $name, strtolower(preg_replace('/\W+/', '.', $name)) . '.' . bin2hex(random_bytes(2)) . '@teste.invalid', $globalRole]);
        $userId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, ?, 'active')")->execute([$clinicId, $userId, $clinicRole]);
        $profId = null;
        if ($crn !== null) {
            $db->prepare('INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, ?, ?)')->execute([$userId, $crn, (string)$region]);
            $profId = (int)$db->lastInsertId();
        }
        return ['user_id' => $userId, 'professional_id' => $profId, 'clinic_id' => $clinicId];
    }

    public static function patient(PDO $db, int $clinicId, string $name): int
    {
        $db->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (?, ?, ?, '1990-05-10', 'F', 'active')")
            ->execute([$clinicId, bin2hex(random_bytes(8)), $name]);
        return (int)$db->lastInsertId();
    }

    public static function appointment(PDO $db, int $clinicId, int $patientId, int $professionalId): int
    {
        $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status, type) VALUES (?, ?, ?, ?, 'in_progress', 'follow_up')")
            ->execute([$clinicId, $patientId, $professionalId, date('Y-m-d H:i:s')]);
        return (int)$db->lastInsertId();
    }

    public static function act(array $u): void
    {
        TenantContext::set($u['clinic_id'], ['id' => $u['user_id'], 'role' => 'nutritionist']);
    }

    public static function examId(PDO $db, string $code): int
    {
        $st = $db->prepare('SELECT id FROM exam_catalog WHERE code = ?');
        $st->execute([$code]);
        return (int)$st->fetchColumn();
    }
}
