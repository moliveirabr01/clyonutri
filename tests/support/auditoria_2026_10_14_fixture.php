<?php

declare(strict_types=1);

/**
 * Fixture compartilhada dos testes da auditoria de segurança 14/10/2026.
 * Banco SQLite ISOLADO em arquivo temporário (nunca o banco de desenvolvimento), migrations oficiais
 * e duas clínicas sintéticas (A e B).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Recipe\RecipeService;

function aud_check(bool $cond, string $label): void
{
    assert($cond, $label);
    echo "  ✔ {$label}\n";
}

function aud_fixture(): array
{
    $path = sys_get_temp_dir() . '/clyonutri_aud_' . bin2hex(random_bytes(6)) . '.sqlite';
    register_shutdown_function(static function () use ($path) {
        foreach ([$path, "{$path}-wal", "{$path}-shm"] as $f) {
            @unlink($f);
        }
    });
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 30000;');
    Connection::setInstance($db);
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();

    $auth = new AuthService($db);
    $a = $auth->registerNutritionist('Dra. Alfa', 'alfa@aud.test', 'SenhaAlfa@2026', 'Clínica Alfa', '11111', 'SP');
    $b = $auth->registerNutritionist('Dr. Beta', 'beta@aud.test', 'SenhaBeta@2026', 'Clínica Beta', '22222', 'RJ');
    $prof = static function (int $uid) use ($db): int {
        $s = $db->prepare('SELECT id FROM professionals WHERE user_id = ?');
        $s->execute([$uid]);
        return (int)$s->fetchColumn();
    };

    $food = static function (?int $clinicId, string $name) use ($db): int {
        $db->prepare("INSERT INTO foods (clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, is_custom) VALUES (?, ?, 'Teste', 100, 5, 10, 2, ?)")
            ->execute([$clinicId, $name, $clinicId === null ? 0 : 1]);
        return (int)$db->lastInsertId();
    };
    $foodGlobal = $food(null, 'Arroz global de teste');
    $foodA = $food((int)$a['clinic_id'], 'Pão particular da Alfa');
    $foodB = $food((int)$b['clinic_id'], 'Segredo particular da Beta');

    $repo = new PatientRepository($db);
    TenantContext::set((int)$a['clinic_id'], ['id' => (int)$a['user_id'], 'name' => 'Dra. Alfa', 'clinic_id' => (int)$a['clinic_id']]);
    $patientA = (int)$repo->create(['name' => 'Paciente Alfa', 'email' => 'paciente.alfa@aud.test'])['id'];
    $recipeA = (int)(new RecipeService($db))->createRecipe(
        ['title' => 'Receita Alfa', 'servings' => 1, 'status' => 'published', 'visibility' => 'clinic'],
        [['food_id' => $foodGlobal, 'amount' => 100, 'measure_unit' => 'g']],
        [['instruction' => 'Misture.']]
    )['id'];
    TenantContext::set((int)$b['clinic_id'], ['id' => (int)$b['user_id'], 'name' => 'Dr. Beta', 'clinic_id' => (int)$b['clinic_id']]);
    $patientB = (int)$repo->create(['name' => 'Paciente Beta', 'email' => 'paciente.beta@aud.test'])['id'];
    TenantContext::set((int)$a['clinic_id'], ['id' => (int)$a['user_id'], 'name' => 'Dra. Alfa', 'clinic_id' => (int)$a['clinic_id']]);

    return [
        'db' => $db, 'path' => $path,
        'clinicA' => (int)$a['clinic_id'], 'userA' => (int)$a['user_id'], 'profA' => $prof((int)$a['user_id']),
        'clinicB' => (int)$b['clinic_id'], 'userB' => (int)$b['user_id'], 'profB' => $prof((int)$b['user_id']),
        'patientA' => $patientA, 'patientB' => $patientB,
        'foodGlobal' => $foodGlobal, 'foodA' => $foodA, 'foodB' => $foodB, 'recipeA' => $recipeA,
    ];
}

/** Sobe o front-controller real (public/index.php) com php -S apontando para o banco isolado. */
function aud_http_server(string $dbPath, int $port, array $extraEnv = []): array
{
    $root = realpath(__DIR__ . '/../..');
    $env = array_merge(getenv(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbPath, 'APP_ENV' => 'testing', 'APP_URL' => "http://127.0.0.1:{$port}"], $extraEnv);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$root}/public", "{$root}/public/index.php"], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) {
            break;
        }
        usleep(100000);
    }
    register_shutdown_function(static function () use ($proc) {
        @proc_terminate($proc);
    });
    return ['proc' => $proc, 'base' => "http://127.0.0.1:{$port}"];
}

/** Requisição HTTP simples com cookie jar. Retorna [status, headers(lower), body]. */
function aud_http(string $method, string $url, ?string $body = null, array $headers = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, strtolower(substr($raw, 0, $hsize)), substr($raw, $hsize)];
}
