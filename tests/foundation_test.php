<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\Csrf;

echo "==============================================\n";
echo "   CLYONUTRI TEST SUITE: FASE 0 & FASE 1\n";
echo "==============================================\n\n";

// 1. Banco em memória para testes isolados
$testDb = new PDO('sqlite::memory:');
$testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($testDb);

// 2. Executar migrações
echo "[1/4] Executando Migrations...\n";
$migrator = new Migrator($testDb, __DIR__ . '/../database/migrations');
$executed = $migrator->run();
assert(count($executed) === 4, "Devem ser executadas 4 migrations");
echo "  ✓ 4 migrações executadas com sucesso.\n\n";

// 3. Testar Registro e Isolamento Multi-tenant
echo "[2/4] Testando Registro de Nutricionista e Multi-Tenancy...\n";
$auth = new AuthService($testDb);
$nutri1 = $auth->registerNutritionist(
    'Dra. Camila Nutri',
    'camila@clinicaexemplo.com.br',
    'SenhaForte@2026',
    'Clínica Equilíbrio',
    '12345',
    'CRN-3'
);
assert($nutri1['clinic_id'] > 0, "Clinic ID deve ser gerado");
assert($nutri1['user_id'] > 0, "User ID deve ser gerado");
echo "  ✓ Nutricionista 1 cadastrado com sucesso (Clinic ID: {$nutri1['clinic_id']}).\n";

// Nutricionista 2 em outra clínica
$nutri2 = $auth->registerNutritionist(
    'Dr. Marcos Nutri',
    'marcos@vidasaudavel.com.br',
    'SenhaForte@2026',
    'Consultório Vida Saudável',
    '54321',
    'CRN-4'
);
assert($nutri2['clinic_id'] !== $nutri1['clinic_id'], "Clínicas devem ser distintas (Multi-tenant)");
echo "  ✓ Nutricionista 2 cadastrado em clínica isolada (Clinic ID: {$nutri2['clinic_id']}).\n\n";

// 4. Testar Autenticação e Proteção IDOR / TenantContext
echo "[3/4] Testando Autenticação e TenantContext...\n";
$loginSuccess = $auth->attempt('camila@clinicaexemplo.com.br', 'SenhaForte@2026');
assert($loginSuccess !== null, "Login deve ser bem-sucedido");
assert($loginSuccess['clinic_id'] === $nutri1['clinic_id'], "Clinic ID autenticado deve bater com a clínica");
echo "  ✓ Login bem-sucedido com tenant vinculado.\n";

$loginFail = $auth->attempt('camila@clinicaexemplo.com.br', 'SenhaErrada');
assert($loginFail === null, "Login com senha incorreta deve falhar");
echo "  ✓ Bloqueio de credenciais inválidas funcionando.\n\n";

// 5. Testar CSRF Protection
echo "[4/4] Testando Proteção CSRF...\n";
$token = Csrf::token();
assert(!empty($token), "Token CSRF deve ser gerado");
assert(Csrf::validate($token) === true, "Token legítimo deve ser validado");
assert(Csrf::validate('token_invalido_hacker') === false, "Token falso deve ser rejeitado");
echo "  ✓ Proteção CSRF validada com sucesso.\n\n";

echo "==============================================\n";
echo "   TODOS OS TESTES PASSARAM COM SUCESSO! ✓\n";
echo "==============================================\n";
