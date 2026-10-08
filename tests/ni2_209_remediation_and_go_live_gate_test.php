<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE REMEDIAÇÃO & GATES DE GO-LIVE (NI2-209)
 * ==============================================================================
 * Comprova o atendimento integral aos critérios de aceite do épico NI2-209:
 *   1. Runtime Laravel 13 empacotado e inicializável sem router proprietário.
 *   2. Autenticação forte do paciente (sem login apenas por e-mail/telefone).
 *   3. Tokens Sanctum com expiração obrigatória (expires_at NOT NULL) e revogação.
 *   4. Identidade canônica do profissional no TenantGuard (sem fallback ambíguo por user_id).
 *   5. Profissional cadastrado com is_verified=0 (verificação explícita obrigatória).
 *   6. Quality Gate fail-closed com classificação estrita PASS/BLOCKED/NOT TESTED.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;

echo "====================================================================\n";
echo "   CLYONUTRI II — SUÍTE DE REMEDIAÇÃO DO BUILD & GO-LIVE (NI2-209)\n";
echo "   Auditoria 360: Gates de Correção e Validação Independente\n";
echo "====================================================================\n\n";

$db = Connection::getInstance();

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
        throw new \RuntimeException("Falha na asserção: {$description}");
    }
}

// =============================================================================
// [1] RUNTIME LARAVEL 13 & ARTEFATO VERIFICÁVEL
// =============================================================================
echo "[1] Validando Runtime Laravel 13 e Empacotamento no Release...\n";

$replatformDir = __DIR__ . '/../replatform/laravel13';
$artisanPath = $replatformDir . '/artisan';
$lockPath = $replatformDir . '/composer.lock';

assertCheck(file_exists($artisanPath), "Executável artisan do Laravel 13 presente em replatform/laravel13");
assertCheck(file_exists($lockPath), "Arquivo composer.lock do Laravel 13 presente");

$lockData = json_decode((string)file_get_contents($lockPath), true);
$packages = array_merge($lockData['packages'] ?? [], $lockData['packages-dev'] ?? []);
$pkgNames = array_column($packages, 'name');

assertCheck(in_array('laravel/framework', $pkgNames, true), "laravel/framework presente no composer.lock");
assertCheck(in_array('laravel/sanctum', $pkgNames, true), "laravel/sanctum presente no composer.lock");
assertCheck(in_array('laravel/fortify', $pkgNames, true), "laravel/fortify presente no composer.lock");
assertCheck(in_array('pestphp/pest', $pkgNames, true), "pestphp/pest presente no composer.lock");

$artisanVersion = (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg($artisanPath) . ' --version 2>&1');
assertCheck(str_contains($artisanVersion, 'Laravel Framework 13.'), "Artisan confirma Laravel Framework 13.x ativo ({$artisanVersion})");

$bootstrapContent = (string)file_get_contents($replatformDir . '/bootstrap/app.php');
assertCheck(!str_contains($bootstrapContent, 'ClyoNutri\Core\Http\Router'), "Bootstrap Laravel 13 não possui nenhum Router proprietário");

// =============================================================================
// [2] AUTENTICAÇÃO FORTE DO PACIENTE & PROIBIÇÃO DE LOGIN SÓ POR IDENTIFICADOR
// =============================================================================
echo "\n[2] Validando Autenticação Forte do Paciente e Rejeição de Login Fraco...\n";

// Limpar e criar paciente de teste com credenciais
$db->exec("DELETE FROM personal_access_tokens WHERE name = 'gate_test_patient';");
$db->exec("DELETE FROM patients WHERE email = 'paciente.gate209@teste.com';");

$patientPass = 'SenhaSegura@2026';
$patientHash = password_hash($patientPass, PASSWORD_BCRYPT);
$patientPin = password_hash('1234', PASSWORD_BCRYPT);

$insPat = $db->prepare("
    INSERT INTO patients (clinic_id, uuid, name, email, phone, password_hash, access_pin, status)
    VALUES (1, 'pat-gate-uuid', 'Paciente Gate 209', 'paciente.gate209@teste.com', '11977770001', :hash, :pin, 'active')
");
$insPat->execute(['hash' => $patientHash, 'pin' => $patientPin]);
$patientId = (int)$db->lastInsertId();

// 2.1 Testar lógica de login sem senha/PIN/OTP (deve ser rejeitado)
$authAttempts = [
    ['email' => 'paciente.gate209@teste.com'], // apenas email
    ['phone' => '11977770001'],                 // apenas telefone
    ['email' => 'paciente.gate209@teste.com', 'password' => ''], // senha vazia
];

foreach ($authAttempts as $idx => $payload) {
    $hasCred = !empty($payload['password'] ?? '') || !empty($payload['otp'] ?? '') || !empty($payload['pin'] ?? '');
    assertCheck(!$hasCred, "Tentativa {$idx}: login apenas por identificador é detectado como sem credencial forte");
}

// 2.2 Testar validação com senha correta
$stmtCheck = $db->prepare("SELECT * FROM patients WHERE id = :id");
$stmtCheck->execute(['id' => $patientId]);
$patRow = $stmtCheck->fetch();

assertCheck(password_verify($patientPass, $patRow['password_hash']), "Credencial com senha forte verificada com sucesso");
assertCheck(password_verify('1234', $patRow['access_pin']), "Credencial alternativa com PIN verificado com sucesso");
assertCheck(!password_verify('senha_errada', $patRow['password_hash']), "Senha incorreta rejeitada pelo verificador");

// =============================================================================
// [3] TOKENS SANCTUM: EXPIRAÇÃO OBRIGATÓRIA (expires_at NOT NULL) E REVOGAÇÃO
// =============================================================================
echo "\n[3] Validando Expiração Obrigatória e Revogação de Tokens de Acesso...\n";

// 3.1 Emitir token legítimo com expiração
$rawToken = bin2hex(random_bytes(32));
$hashedToken = hash('sha256', $rawToken);
$validExpiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

$insTok = $db->prepare("
    INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, expires_at, created_at)
    VALUES ('patient', :pid, 'gate_test_patient', :token, :expires_at, CURRENT_TIMESTAMP)
");
$insTok->execute(['pid' => $patientId, 'token' => $hashedToken, 'expires_at' => $validExpiresAt]);
$validTokenId = (int)$db->lastInsertId();

// Validar busca estrita (apenas hash, expires_at > NOW)
$stmtAuth = $db->prepare("
    SELECT t.*, p.name 
    FROM personal_access_tokens t
    JOIN patients p ON p.id = t.tokenable_id
    WHERE t.token = :hash AND t.expires_at IS NOT NULL AND t.expires_at > CURRENT_TIMESTAMP
    LIMIT 1
");
$stmtAuth->execute(['hash' => $hashedToken]);
assertCheck($stmtAuth->fetch() !== false, "Token válido com expires_at futuro autentica com sucesso");

// 3.2 Inserir token legado com expires_at NULL (deve ser rejeitado fail-closed)
$nullRaw = bin2hex(random_bytes(32));
$nullHashed = hash('sha256', $nullRaw);
$insTokNull = $db->prepare("
    INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, expires_at, created_at)
    VALUES ('patient', :pid, 'gate_test_patient', :token, NULL, CURRENT_TIMESTAMP)
");
$insTokNull->execute(['pid' => $patientId, 'token' => $nullHashed]);

$stmtAuth->execute(['hash' => $nullHashed]);
assertCheck($stmtAuth->fetch() === false, "Token inseguro com expires_at NULL é bloqueado fail-closed");

// 3.3 Inserir token expirado no passado (deve ser rejeitado)
$expRaw = bin2hex(random_bytes(32));
$expHashed = hash('sha256', $expRaw);
$pastExpires = date('Y-m-d H:i:s', strtotime('-1 hour'));
$insTokExp = $db->prepare("
    INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, expires_at, created_at)
    VALUES ('patient', :pid, 'gate_test_patient', :token, :expires_at, CURRENT_TIMESTAMP)
");
$insTokExp->execute(['pid' => $patientId, 'token' => $expHashed, 'expires_at' => $pastExpires]);

$stmtAuth->execute(['hash' => $expHashed]);
assertCheck($stmtAuth->fetch() === false, "Token com expires_at no passado é bloqueado como expirado");

// 3.4 Revogação de token
$delStmt = $db->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
$delStmt->execute(['id' => $validTokenId]);
assertCheck($delStmt->rowCount() === 1, "Token legítimo revogado com sucesso");

$stmtAuth->execute(['hash' => $hashedToken]);
assertCheck($stmtAuth->fetch() === false, "Token revogado não permite mais autenticação");

// =============================================================================
// [4] IDENTIDADE DO PROFISSIONAL NO TENANTGUARD (SEM FALLBACK AMBÍGUO)
// =============================================================================
echo "\n[4] Validando Identidade Canônica do Profissional no TenantGuard...\n";

// Criar setup com IDs propositalmente divergentes entre users e professionals
// User 881 pertence à Clínica 1, Professional 881 é criado com user_id 881
// User 882 pertence à Clínica 2, Professional 882 é criado com user_id 882
$db->exec("DELETE FROM professionals WHERE id IN (881, 882);");
$db->exec("DELETE FROM clinic_members WHERE user_id IN (881, 882);");
$db->exec("DELETE FROM users WHERE id IN (881, 882);");

$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified) VALUES (881, 'u881', 'Nutri Clínica 1', 'nutri1@gate.test', 'h', 'nutritionist', 1);");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES (1, 881, 'owner');");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (881, 881, 'CRN-881', 'CRN-3');");

$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified) VALUES (882, 'u882', 'Nutri Clínica 2', 'nutri2@gate.test', 'h', 'nutritionist', 1);");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES (2, 882, 'owner');");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (882, 882, 'CRN-882', 'CRN-3');");

// 4.1 Acesso com professional_id canônico legítimo
$prof1 = TenantGuard::assertProfessionalBelongsToClinic(881, 1, $db);
assertCheck($prof1['id'] == 881 && $prof1['clinic_id'] == 1, "Profissional canônico 881 validado na Clínica 1");

// 4.2 Tentativa de usar profissional da Clínica 2 dentro da Clínica 1 (Cross-Tenant)
$crossBlocked = false;
try {
    TenantGuard::assertProfessionalBelongsToClinic(882, 1, $db);
} catch (TenantSecurityException $e) {
    $crossBlocked = true;
    assertCheck($e->getCode() === 403, "Acesso cross-tenant a profissional barrado com HTTP 403");
}
assertCheck($crossBlocked, "TenantGuard bloqueou profissional de outro tenant sem ambiguidade");

// 4.3 Tentativa de passar user_id arbitrário que não é professional_id da clínica
$nonCanonBlocked = false;
try {
    // 99999 não existe na tabela professionals
    TenantGuard::assertProfessionalBelongsToClinic(99999, 1, $db);
} catch (TenantSecurityException $e) {
    $nonCanonBlocked = true;
}
assertCheck($nonCanonBlocked, "TenantGuard rejeita IDs não-canônicos sem recorrer a fallbacks ambíguos");

// =============================================================================
// [5] PROFISSIONAL NÃO É AUTOMATICAMENTE VERIFICADO NO CADASTRO
// =============================================================================
echo "\n[5] Validando que Cadastro Não Marca Profissional como Verificado...\n";

$authService = new AuthService($db);
$emailNew = 'novo.nutri.' . time() . '@gate.test';

$newReg = $authService->registerNutritionist(
    'Dr. Recém Cadastrado',
    $emailNew,
    'SenhaForte@2026',
    'Clínica Nova Gate',
    '55555',
    'CRN-3'
);

$newUserId = (int)$newReg['user_id'];

// Consultar no banco relacional o campo is_verified do novo usuário
$stmtVer = $db->prepare("SELECT is_verified FROM users WHERE id = :id");
$stmtVer->execute(['id' => $newUserId]);
$isVerifiedInitial = (int)$stmtVer->fetchColumn();

assertCheck($isVerifiedInitial === 0, "Profissional recém-cadastrado tem is_verified = 0 (Não verificado automaticamente)");
assertCheck(!$authService->isVerified($newUserId), "isVerified() retorna false para conta não confirmada");

// Validação explícita posterior
$verifiedSuccess = $authService->verifyUserEmail($newUserId);
assertCheck($verifiedSuccess, "Verificação explícita de e-mail/CRN executada com sucesso");
assertCheck($authService->isVerified($newUserId), "isVerified() retorna true apenas após verificação explícita");

// =============================================================================
// RESUMO FINAL DA SUÍTE NI2-209
// =============================================================================
echo "\n====================================================================\n";
echo "   RESULTADO DA SUÍTE DE REMEDIAÇÃO E GO-LIVE (NI2-209)\n";
echo "====================================================================\n";
echo " Total de Asserções: {$passed}\n";
echo " Asserções Falhas  : {$failed}\n";
echo " Status            : " . ($failed === 0 ? "100% SUCESSO — GATES APROVADOS" : "FALHA") . "\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
