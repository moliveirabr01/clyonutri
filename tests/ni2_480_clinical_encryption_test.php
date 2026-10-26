<?php

declare(strict_types=1);

/**
 * Teste Verificável de Homologação: NI2-480 (Onda 3 - SEC-02)
 * Criptografia Autenticada de Dados Clínicos em Repouso (AES-256-GCM)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\Patient\PatientRepository;

$passed = 0;
$failed = 0;

function check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE VERIFICÁVEL DE HOMOLOGAÇÃO: NI2-480 (ONDA 3 - SEC-02) <<<\n";
echo "    Criptografia em Repouso AES-256-GCM para Registros Clínicos\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// [1/5] Propriedades Criptográficas do AES-256-GCM
// -------------------------------------------------------------------------
echo "[1/5] Validação de propriedades criptográficas (AES-256-GCM e IV aleatório)...\n";

$plaintext = "Paciente relata choque anafilático prévio com amendoim e intolerância severa a lactose.";
$enc1 = ClinicalEncryptionService::encrypt($plaintext);
$enc2 = ClinicalEncryptionService::encrypt($plaintext);

check(str_starts_with($enc1, 'enc:v1:'), "Cifra prefixada com envelope de versão 'enc:v1:'");
check($enc1 !== $enc2, "IV aleatório de 12 bytes gera cifras distintas para o mesmo texto claro");
check(ClinicalEncryptionService::decrypt($enc1) === $plaintext, "Descriptografia da primeira cifra recupera texto original com 100% de fidelidade");
check(ClinicalEncryptionService::decrypt($enc2) === $plaintext, "Descriptografia da segunda cifra recupera texto original com 100% de fidelidade");

// Não há dupla cifra se chamado novamente
check(ClinicalEncryptionService::encrypt($enc1) === $enc1, "Chamada idempotente de encrypt em texto já cifrado preserva a cifra original");

// -------------------------------------------------------------------------
// [2/5] Resistência a Adulteração e Tamper-Proofing (Tag de Autenticação)
// -------------------------------------------------------------------------
echo "\n[2/5] Teste de autenticação de mensagem e detecção de adulteração...\n";

$rawPayload = base64_decode(substr($enc1, strlen(ClinicalEncryptionService::PREFIX)));
$tamperedRaw = $rawPayload;
// Corrompe um byte do ciphertext
$tamperedRaw[strlen($tamperedRaw) - 1] = chr(ord($tamperedRaw[strlen($tamperedRaw) - 1]) ^ 0xFF);
$tamperedCipher = ClinicalEncryptionService::PREFIX . base64_encode($tamperedRaw);

$caughtTamper = false;
try {
    ClinicalEncryptionService::decrypt($tamperedCipher);
} catch (\RuntimeException $e) {
    $caughtTamper = true;
}
check($caughtTamper, "Adulteração de 1 bit do texto cifrado rejeitada pela tag de autenticação GCM (falha de integridade)");

// -------------------------------------------------------------------------
// [3/5] Retrocompatibilidade Transparente com Dados Legados em Texto Claro
// -------------------------------------------------------------------------
echo "\n[3/5] Teste de retrocompatibilidade com dados legados...\n";

$legacyText = "Observação clínica legada gravada antes da ativação da criptografia.";
check(ClinicalEncryptionService::decrypt($legacyText) === $legacyText, "Texto claro legado retornado inalterado sem erros");
check(ClinicalEncryptionService::decrypt(null) === null, "Valor null tratado graciosamente");
check(ClinicalEncryptionService::decrypt('') === '', "String vazia tratada graciosamente");

// -------------------------------------------------------------------------
// [4/5] Persistência Real no Banco de Dados via AnamnesisService
// -------------------------------------------------------------------------
echo "\n[4/5] Teste de persistência e recuperação ponta-a-ponta no MySQL...\n";

$uniq = time() . '_' . rand(100, 999);

// Setup de clínica, profissional e paciente usando AuthService
$auth = new \ClyoNutri\Core\Auth\AuthService($db);
$registered = $auth->registerNutritionist(
    "Nutri Cripto {$uniq}",
    "nutri_{$uniq}@clyo.test",
    'SenhaForte@2026',
    "Clínica Cripto {$uniq}",
    '99999',
    'CRN-3'
);
$clinicId = (int)$registered['clinic_id'];
$userId = (int)$registered['user_id'];

$stProf = $db->prepare("SELECT id FROM professionals WHERE user_id = :u");
$stProf->execute(['u' => $userId]);
$professionalId = (int)$stProf->fetchColumn();

TenantContext::set($clinicId, ['id' => $userId, 'name' => "Nutri Cripto {$uniq}"]);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => "Paciente Cripto {$uniq}", 'email' => "paciente_{$uniq}@test.com"]);
$patientId = (int)$patient['id'];

// Salvar anamnese com dados clínicos sensíveis
$anamnesisService = new AnamnesisService($db);
$savedResult = $anamnesisService->saveAnamnesis(
    $patientId,
    $professionalId,
    [
        'allergies' => 'Alergia severa a frutos do mar e camarão',
        'restrictions' => 'Intolerância severa a lactose',
        'objectives' => 'Melhora da microbiota e redução de marcadores inflamatórios',
        'routine' => 'Acorda às 6h, dorme às 22h',
        'food_preferences' => 'Gosta de frutas e carnes brancas',
        'symptoms' => 'Distensão abdominal frequente',
        'habits' => 'Consome 2 cafés diários',
        'clinical_history' => 'Histórico familiar de diabetes mellitus tipo 2',
        'notes' => 'Paciente em acompanhamento conjunto com endocrinologista',
    ]
);

check(!empty($savedResult['id']) && (int)$savedResult['version'] >= 1, "Anamnese gravada com sucesso através do AnamnesisService (ID #{$savedResult['id']}, v{$savedResult['version']})");

// Inspecionar o registro CRU diretamente no MySQL (bypass na camada de serviço)
$rawStmt = $db->prepare("SELECT allergies, restrictions, objectives, notes FROM anamneses WHERE patient_id = :p AND clinic_id = :c ORDER BY version DESC LIMIT 1");
$rawStmt->execute(['p' => $patientId, 'c' => $clinicId]);
$rawDbRow = $rawStmt->fetch(\PDO::FETCH_ASSOC);

check(!empty($rawDbRow), "Registro de anamnese recuperado do banco");
check(str_starts_with($rawDbRow['allergies'], 'enc:v1:'), "Coluna 'allergies' no MySQL contém dados cifrados com 'enc:v1:' (NÃO texto claro)");
check(!str_contains($rawDbRow['allergies'], 'camarão'), "Texto sensível 'camarão' NÃO está legível no banco de dados");
check(str_starts_with($rawDbRow['objectives'], 'enc:v1:'), "Coluna 'objectives' no MySQL contém dados cifrados");
check(str_starts_with($rawDbRow['notes'], 'enc:v1:'), "Coluna 'notes' no MySQL contém dados cifrados");

// Recuperar via serviço (deve descriptografar transparentemente)
$latest = $anamnesisService->getLatestAnamnesis($patientId);
check($latest['allergies'] === 'Alergia severa a frutos do mar e camarão', "AnamnesisService::getLatestAnamnesis recupera texto descriptografado com sucesso");
check($latest['objectives'] === 'Melhora da microbiota e redução de marcadores inflamatórios', "Objetivos descriptografados com sucesso");
check($latest['notes'] === 'Paciente em acompanhamento conjunto com endocrinologista', "Notas clínicas descriptografadas com sucesso");

// Verificar se o validador de alergênicos consegue ler a anamnese criptografada
$allergenValidator = new AllergenSafetyValidator($db);
$checkResult = $allergenValidator->validateFoodsForPatient($patientId, []);
check($checkResult['allergy_recorded'] === true, "AllergenSafetyValidator lê com sucesso a anamnese criptografada");
check(in_array('crustaceos', $checkResult['patient_allergens'], true), "Taxonomia identificou grupo 'crustaceos' a partir do texto descriptografado");


// -------------------------------------------------------------------------
// [5/5] Teste do Script CLI de Migração (bin/encrypt_clinical_records.php)
// -------------------------------------------------------------------------
echo "\n[5/5] Teste de execução do script CLI de migração...\n";

$cliOutput = shell_exec('php bin/encrypt_clinical_records.php --dry-run 2>&1');
check(str_contains($cliOutput, 'CLYONUTRI II — MIGRAÇÃO DE CRIPTOGRAFIA EM REPOUSO'), "Script CLI bin/encrypt_clinical_records.php executado com sucesso");
check(str_contains($cliOutput, 'SIMULAÇÃO (DRY-RUN'), "Modo de simulação --dry-run respeitado sem erros");

// Limpar fixtures
$db->prepare("DELETE FROM anamneses WHERE clinic_id = :c")->execute(['c' => $clinicId]);
$db->prepare("DELETE FROM patients WHERE clinic_id = :c")->execute(['c' => $clinicId]);
$db->prepare("DELETE FROM professionals WHERE id = :p")->execute(['p' => $professionalId]);
$db->prepare("DELETE FROM clinic_members WHERE clinic_id = :c")->execute(['c' => $clinicId]);
$db->prepare("DELETE FROM users WHERE id = :u")->execute(['u' => $userId]);
$db->prepare("DELETE FROM clinics WHERE id = :c")->execute(['c' => $clinicId]);

echo "\n------------------------------------------------------------------------\n";
echo "RESULTADO: {$passed} testes passaram, {$failed} falharam.\n";
echo "========================================================================\n\n";

exit($failed === 0 ? 0 : 1);
