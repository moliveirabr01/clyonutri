<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Security\Env;
use PDO;
use RuntimeException;

class DemoSeeder
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Executa o seeder de demonstração exclusivamente em ambientes não-produtivos.
     * Nega estritamente a execução caso APP_ENV seja 'production', 'prod' ou 'staging'.
     *
     * @throws RuntimeException se executado em ambiente de produção ou staging.
     */
    public function run(): array
    {
        $appEnv = strtolower(trim((string)(Env::get('APP_ENV') ?? getenv('APP_ENV') ?? 'production')));
        if ($appEnv === '') {
            $appEnv = 'production';
        }

        $blockedEnvs = ['production', 'prod', 'staging'];
        if (in_array($appEnv, $blockedEnvs, true) || !in_array($appEnv, ['local', 'development', 'testing'], true)) {
            throw new RuntimeException(
                "SEGURANÇA BLOQUEADA (NI2-172): DemoSeeder é estritamente proibido em ambiente '{$appEnv}'. " .
                "Contas demo e credenciais fixas não podem ser provisionadas em produção ou staging."
            );
        }

        $auth = new AuthService($this->db);
        $created = [];

        $email = 'demo.local@clyonutri.local';
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $auth->registerNutritionist(
                'Nutricionista Demo Local',
                $email,
                'DevLocalPass@2026',
                'Consultório de Desenvolvimento Local',
                '99999',
                'CRN-3'
            );
            $created[] = $email;
        }

        return [
            'status' => 'success',
            'env' => $appEnv,
            'created' => $created,
        ];
    }
}
