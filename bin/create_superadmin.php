<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Criação do primeiro superadmin da plataforma.
 *
 * Uso (Terminal do cPanel ou SSH):
 *   php bin/create_superadmin.php admin@seudominio.com.br "Nome do Administrador"
 *   php bin/create_superadmin.php suporte@seudominio.com.br "Nome" --role=support_agent
 *
 * --role aceita os papéis de equipe da plataforma (PlatformPolicy): superadmin (padrão),
 * admin_operacional, operator, support_agent, finance_admin, security_auditor.
 *
 * Gera uma senha forte aleatória e a exibe UMA única vez. Troque-a no primeiro acesso.
 * O superadmin é vinculado ao consultório interno "Operação da Plataforma" (criado na
 * primeira execução), pois toda sessão autenticada exige um vínculo ativo de consultório.
 * Recusa e-mails já cadastrados e só roda pela linha de comando.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');
date_default_timezone_set((string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo'));

$positional = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with($a, '--')));
$roleArg = array_values(array_filter(array_slice($argv, 1), static fn($a) => str_starts_with($a, '--role=')));
$email = strtolower(trim((string)($positional[0] ?? '')));
$name = trim((string)($positional[1] ?? ''));
$role = $roleArg ? substr($roleArg[0], 7) : \ClyoNutri\Core\Security\PlatformPolicy::ROLE_SUPERADMIN;
$allowedRoles = [
    \ClyoNutri\Core\Security\PlatformPolicy::ROLE_SUPERADMIN,
    \ClyoNutri\Core\Security\PlatformPolicy::ROLE_ADMIN_OPERACIONAL,
    \ClyoNutri\Core\Security\PlatformPolicy::ROLE_OPERATOR,
    \ClyoNutri\Core\Security\PlatformPolicy::ROLE_SUPPORT_AGENT,
    \ClyoNutri\Core\Security\PlatformPolicy::ROLE_FINANCE_ADMIN,
    \ClyoNutri\Core\Security\PlatformPolicy::ROLE_SECURITY_AUDITOR,
];

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !in_array($role, $allowedRoles, true)) {
    fwrite(STDERR, "Uso: php bin/create_superadmin.php <email> \"<nome>\" [--role=" . implode('|', $allowedRoles) . "]\n");
    exit(1);
}

try {
    $db = Connection::getInstance();

    $exists = $db->prepare('SELECT id FROM users WHERE LOWER(email) = :email');
    $exists->execute(['email' => $email]);
    if ($exists->fetchColumn()) {
        fwrite(STDERR, "Já existe um usuário com o e-mail {$email}. Nada foi alterado.\n");
        exit(1);
    }

    $uuid = static function (): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    };
    $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), '=');
    $platformClinicName = 'ClyoNutri — Operação da Plataforma';

    $db->beginTransaction();
    try {
        $find = $db->prepare('SELECT id FROM clinics WHERE name = :name ORDER BY id LIMIT 1');
        $find->execute(['name' => $platformClinicName]);
        $clinicId = (int)$find->fetchColumn();
        if ($clinicId === 0) {
            $db->prepare("INSERT INTO clinics (uuid, name, email, status) VALUES (:uuid, :name, :email, 'active')")
                ->execute(['uuid' => $uuid(), 'name' => $platformClinicName, 'email' => $email]);
            $clinicId = (int)$db->lastInsertId();
        }

        $db->prepare(
            "INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
             VALUES (:uuid, :name, :email, :hash, :role, 1, 'active')"
        )->execute([
            'role' => $role,
            'uuid' => $uuid(),
            'name' => $name,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);
        $userId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (:cid, :uid, 'admin', 'active')")
            ->execute(['cid' => $clinicId, 'uid' => $userId]);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo "Usuário de equipe ({$role}) criado com sucesso.\n";
    echo "  E-mail: {$email}\n";
    echo "  Senha : {$password}\n";
    echo "Guarde esta senha agora — ela não será exibida novamente. Acesse /login e depois /admin.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro ao criar superadmin: ' . $e->getMessage() . "\n");
    exit(1);
}
