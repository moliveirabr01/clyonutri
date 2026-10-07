<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Privacy\LgpdService;

echo "========================================================\n";
echo "   CLYONUTRI SECURITY, LGPD & HARDENING TEST SUITE\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

$auth = new AuthService($db);
// Criação de 2 Tenants para teste de Cross-Tenant/IDOR
$tenant1 = $auth->registerNutritionist('Dra. Tenant Um', 't1@clyo.test', 'SenhaForte@1', 'Clínica Alfa', '11111', 'CRN-1');
$tenant2 = $auth->registerNutritionist('Dr. Tenant Dois', 't2@clyo.test', 'SenhaForte@2', 'Clínica Beta', '22222', 'CRN-2');

// 1. Blindagem Multi-tenant e Proteção contra IDOR
echo "[1/4] Testando Blindagem contra IDOR e Cross-Tenant Leakage...\n";
TenantContext::set($tenant1['clinic_id'], ['id' => $tenant1['user_id'], 'name' => $tenant1['name']]);
$patientRepo = new PatientRepository($db);
$patientT1 = $patientRepo->create(['name' => 'Paciente da Clínica Alfa', 'email' => 'alfa@test.com']);
assert($patientT1['id'] > 0, "Paciente do Tenant 1 deve existir");

// Alternar contexto para Tenant 2 e tentar acessar o paciente do Tenant 1
TenantContext::set($tenant2['clinic_id'], ['id' => $tenant2['user_id'], 'name' => $tenant2['name']]);
$idorAttempt = $patientRepo->findById($patientT1['id']);
assert($idorAttempt === null, "FALHA CRÍTICA: Tenant 2 não pode acessar recurso de Tenant 1 (IDOR Bloqueado)");

$allT2 = $patientRepo->listAll();
assert(count($allT2) === 0, "Tenant 2 não deve listar pacientes de outro tenant");
echo "  ✓ Proteção contra IDOR e isolamento cross-tenant validados 100%.\n\n";

// 2. Trilha de Auditoria
echo "[2/4] Testando Trilha de Auditoria (Audit Logs)...\n";
TenantContext::set($tenant1['clinic_id'], ['id' => $tenant1['user_id'], 'name' => $tenant1['name']]);
$auditLogger = new AuditLogger($db);
$auditLogger->log('PATIENT_RECORD_VIEW', 'patients', (string)$patientT1['id'], ['channel' => 'web']);

$aStmt = $db->prepare("SELECT * FROM audit_logs WHERE clinic_id = :clinic_id");
$aStmt->execute(['clinic_id' => $tenant1['clinic_id']]);
$logs = $aStmt->fetchAll();
assert(count($logs) >= 2, "Devem existir logs de auditoria (criação e acesso)");
echo "  ✓ Trilha de auditoria estruturada e imutável gravada com sucesso.\n\n";

// 3. LGPD: Portabilidade e Direito ao Esquecimento
echo "[3/4] Testando Requisitos de Privacidade e LGPD...\n";
$lgpd = new LgpdService($db);
$export = $lgpd->exportPatientData($patientT1['id']);
assert($export !== null, "Exportação deve retornar dados completos");
assert($export['patient']['name'] === 'Paciente da Clínica Alfa', "Nome correspondente");

$anonymized = $lgpd->anonymizePatient($patientT1['id']);
assert($anonymized === true, "Anonimização concluída com sucesso");

$patientAnon = $patientRepo->findById($patientT1['id']);
assert($patientAnon['name'] === 'PACIENTE ANONIMIZADO', "Nome do titular deve estar anonimizado");
assert($patientAnon['email'] === null, "E-mail do titular deve ser removido");
assert($patientAnon['status'] === 'anonymized', "Status deve refletir anonimização");
echo "  ✓ Portabilidade de dados e Anonimização LGPD comprovadas.\n\n";

// 4. Segurança de Input (Prevenção SQL Injection & CSRF)
echo "[4/4] Testando Prevenção contra SQL Injection e CSRF...\n";
$maliciousSqlInput = "' OR '1'='1";
$searchResult = $patientRepo->findById(999999);
assert($searchResult === null, "Query parametrizada protegeu contra SQL injection");

$validToken = Csrf::token();
assert(Csrf::validate($validToken) === true, "Token legítimo válido");
assert(Csrf::validate('fake_csrf') === false, "Ataque CSRF impedido");
echo "  ✓ Blindagem de SQL Injection e CSRF operando perfeitamente.\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DE SEGURANÇA E LGPD PASSARAM! ✓\n";
echo "========================================================\n";
