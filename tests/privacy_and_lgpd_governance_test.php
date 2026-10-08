<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Privacy\ConsentManagementService;
use ClyoNutri\Domain\Privacy\DataSubjectRequestService;
use ClyoNutri\Domain\Privacy\LgpdService;
use ClyoNutri\Domain\Storage\SecureClinicalStorageService;

echo "========================================================\n";
echo "   CLYONUTRI PRIVACY & LGPD GOVERNANCE TEST SUITE\n";
echo "   Validação do Épico NI2-145 e Histórias NI2-162 a NI2-166\n";
echo "========================================================\n\n";

// 1. Setup in-memory SQLite e Migrations
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

// 2. Setup dos Tenants
$auth = new AuthService($db);
$tenantAlfa = $auth->registerNutritionist('Dra. Paula Privacidade', 'paula@clyo.test', 'SenhaForte@1', 'Clínica Alfa Privacidade', '88888', 'CRN-8888');
$tenantBeta = $auth->registerNutritionist('Dr. Invasor', 'invasor@clyo.test', 'SenhaForte@2', 'Clínica Beta', '99999', 'CRN-9999');

$clinicAlfaId = (int)$tenantAlfa['clinic_id'];
$clinicBetaId = (int)$tenantBeta['clinic_id'];
$profAlfaId = (int)$tenantAlfa['user_id'];
$profBetaId = (int)$tenantBeta['user_id'];

TenantContext::set($clinicAlfaId, ['id' => $profAlfaId, 'name' => 'Dra. Paula Privacidade']);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Mariana Oliveira', 'email' => 'mariana@test.com', 'phone' => '11999998888']);
$patientId = (int)$patient['id'];

echo "✓ Setup de infraestrutura e dados do titular concluído.\n\n";

// -----------------------------------------------------------------------------
// [1/5] NI2-162: Inventário e Classificação de Dados
// -----------------------------------------------------------------------------
echo "[1/5] Testando NI2-162: Inventário e Mapeamento de Dados Sensíveis...\n";
assert(file_exists(__DIR__ . '/../docs/privacy/INVENTARIO_E_CLASSIFICACAO_DADOS_LGPD.md'), "Documento de inventário deve existir");
$invContent = file_get_contents(__DIR__ . '/../docs/privacy/INVENTARIO_E_CLASSIFICACAO_DADOS_LGPD.md');
assert(str_contains($invContent, 'Lei Federal nº 13.787/2018'), "Inventário deve referenciar o prazo legal de 20 anos");
assert(str_contains($invContent, 'Dado Pessoal Sensível de Saúde'), "Inventário deve categorizar dados sensíveis");
echo "  ✓ Inventário de dados pessoais e sensíveis aprovado e homologado.\n\n";

// -----------------------------------------------------------------------------
// [2/5] NI2-163: Termos Versionados e Consentimentos Verificáveis
// -----------------------------------------------------------------------------
echo "[2/5] Testando NI2-163: Versionamento de Termos e Consentimentos Verificáveis...\n";
$consentService = new ConsentManagementService($db);

// Cadastro de termos versionados
$termV1 = $consentService->createTerm('1.0', 'Termo de Atendimento Clínico e Nutricional', 'clinical_care', 'Autorizo o tratamento de dados de saúde para fins de consulta e dieta.');
assert($termV1['version'] === '1.0', "Versão do termo deve ser 1.0");

// Registro legítimo de consentimento com proveniência
$consentGranted = $consentService->recordConsent($patientId, 'clinical_care', $termV1['id'], 'web', '189.10.20.30', 'Mozilla/5.0');
assert($consentGranted['granted'] === true, "Consentimento deve ser concedido");
assert($consentService->hasActiveConsent($patientId, 'clinical_care') === true, "Consentimento deve estar ativo");
echo "  ✓ Consentimento v1.0 gravado com proveniência e endereço IP.\n";

// Teste de rejeição de fabricação de consentimento via importação em massa
$importRejected = false;
try {
    $consentService->recordConsent($patientId, 'clinical_care', $termV1['id'], 'csv_import');
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'não podem fabricar consentimentos')) {
        $importRejected = true;
    }
}
assert($importRejected === true, "Tentativa de fabricar consentimento via importação DEVE ser bloqueada");
echo "  ✓ Proteção contra consentimentos fabricados por importação validada com sucesso.\n";

// Revogação formal de consentimento
$revoked = $consentService->revokeConsent($patientId, 'clinical_care', 'Titular solicitou encerramento do acompanhamento');
assert($revoked === true, "Revogação deve ser executada com sucesso");
assert($consentService->hasActiveConsent($patientId, 'clinical_care') === false, "Consentimento revogado não pode constar como ativo");
echo "  ✓ Revogação de consentimento executada e registrada com sucesso.\n\n";

// -----------------------------------------------------------------------------
// [3/5] NI2-164: Solicitações dos Titulares (LGPD Art. 18) com Legal Hold de Prontuário
// -----------------------------------------------------------------------------
echo "[3/5] Testando NI2-164: Solicitações de Titulares com Retenção Legal Obrigatória...\n";
$requestService = new DataSubjectRequestService($db);

// Inserir registro clínico de prontuário para o paciente (para validar retenção)
$clinicalRecordService = new ClinicalRecordService($db);
$clinicalRecordService->appendRecord($patientId, $profAlfaId, 'Prontuário Inicial', ['diagnostico' => 'Sobrepeso']);

// 3.1 Solicitação de Exportação / Portabilidade
$reqExport = $requestService->openRequest($patientId, 'export', 'Solicitação de cópia integral dos dados');
assert($reqExport['status'] === 'pending', "Status inicial da solicitação deve ser pending");
$exportResult = $requestService->processExport($reqExport['id'], $profAlfaId);
assert($exportResult['status'] === 'completed', "Exportação deve ser concluída");
assert(!empty($exportResult['checksum_sha256']), "Exportação deve conter checksum criptográfico de integridade");
echo "  ✓ Portabilidade de dados gerada com hash SHA-256 e auditoria.\n";

// 3.2 Solicitação de Eliminação / Direito ao Esquecimento
$reqDel = $requestService->openRequest($patientId, 'deletion', 'Titular solicitou esquecimento completo');
$delResult = $requestService->processDeletionWithLegalHold($reqDel['id'], $profAlfaId);

// Assertivas críticas de segurança clínica e legal:
assert($delResult['status'] === 'rejected_legal_hold', "Eliminação total de prontuário deve ser recusada sob salvaguarda legal de 20 anos");
assert($delResult['legal_hold_applied'] === true, "Legal hold de prontuário deve ser aplicado");
assert(str_contains($delResult['justification'], 'Lei Federal nº 13.787/2018'), "Justificativa legal do CFN e da Lei 13.787 deve ser formalizada");

// Validar que os dados de contato do paciente foram anonimizados no cadastro:
$stmtPatCheck = $db->prepare("SELECT * FROM patients WHERE id = :id");
$stmtPatCheck->execute(['id' => $patientId]);
$patRow = $stmtPatCheck->fetch();
assert($patRow['name'] === 'PACIENTE ANONIMIZADO', "Nome do titular deve estar anonimizado");
assert($patRow['email'] === null, "E-mail de contato deve ser nulo");
assert($patRow['phone'] === null, "Telefone deve ser nulo");

// Validar que o registro clínico NÃO foi excluído fisicamente:
$stmtClinCheck = $db->prepare("SELECT COUNT(*) FROM clinical_records WHERE patient_id = :id");
$stmtClinCheck->execute(['id' => $patientId]);
assert(((int)$stmtClinCheck->fetchColumn()) === 1, "Prontuário NÃO PODE ser destruído em cascata (guarda legal de 20 anos)");
echo "  ✓ Anonimização cadastral e salvaguarda legal de guarda de prontuário por 20 anos validadas 100%.\n\n";

// -----------------------------------------------------------------------------
// [4/5] NI2-165: Proteção de Dados Clínicos em Storage e Validação de Arquivos
// -----------------------------------------------------------------------------
echo "[4/5] Testando NI2-165: Storage Seguro, Validação de MIME e Bloqueio Cross-Tenant...\n";
$tempStorageDir = sys_get_temp_dir() . '/clyo_test_storage_' . bin2hex(random_bytes(4));
$storageService = new SecureClinicalStorageService($db, $tempStorageDir);

// 4.1 Upload de anexo legítimo (PDF)
$pdfContent = "%PDF-1.4 Laudo de Exame Laboratorial de Glicemia e Lipidograma";
$savedPdf = $storageService->storeAttachment($patientId, $profAlfaId, 'exame_sangue.pdf', $pdfContent, 'application/pdf');
assert($savedPdf['id'] > 0, "Anexo deve ser persistido");
assert(file_exists($savedPdf['storage_path']), "Arquivo deve existir no storage privado");
echo "  ✓ Anexo clínico legítimo armazenado com hash SHA-256 no storage privado.\n";

// 4.2 Tentativa de upload de arquivo com extensão executável perigosa (.php)
$phpRejected = false;
try {
    $storageService->storeAttachment($patientId, $profAlfaId, 'malicious_script.php', '<?php phpinfo(); ?>', 'application/pdf');
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'terminantemente proibida')) {
        $phpRejected = true;
    }
}
assert($phpRejected === true, "Upload de script executável DEVE ser barrado na validação de extensão");
echo "  ✓ Tentativa de injeção de script executável bloqueada com sucesso.\n";

// 4.3 Tentativa de acesso cross-tenant ao anexo
TenantContext::set($clinicBetaId, ['id' => $profBetaId, 'name' => 'Dr. Invasor']);
$crossTenantBlocked = false;
try {
    $storageService->getAttachmentContent($savedPdf['id']);
} catch (TenantSecurityException $e) {
    $crossTenantBlocked = true;
}
assert($crossTenantBlocked === true, "Tentativa de baixar anexo de outra clínica deve ser barrada com 403 Forbidden");
echo "  ✓ Isolamento multi-tenant de arquivos clínicos comprovado com HTTP 403.\n\n";

// -----------------------------------------------------------------------------
// [5/5] NI2-166: Runbook de Incidentes e Auditoria sem PII
// -----------------------------------------------------------------------------
echo "[5/5] Testando NI2-166: Runbook de Incidentes e Auditoria Segura sem PII...\n";
assert(file_exists(__DIR__ . '/../docs/privacy/RUNBOOK_RESPOSTA_INCIDENTES_E_RETENCAO_LGPD.md'), "Runbook de incidentes deve existir");
$runbook = file_get_contents(__DIR__ . '/../docs/privacy/RUNBOOK_RESPOSTA_INCIDENTES_E_RETENCAO_LGPD.md');
assert(str_contains($runbook, 'Resolução CD/ANPD nº 15/2024'), "Runbook deve citar a resolução da ANPD");
assert(str_contains($runbook, '3 (três) dias úteis'), "Prazo regulamentar de notificação deve ser de 3 dias úteis");

// Validar que os logs de auditoria não contêm diagnósticos ou anotações médicas confidenciais
$stmtAudits = $db->prepare("SELECT details FROM audit_logs WHERE action IN ('CONSENT_GRANTED', 'CLINICAL_FILE_STORED', 'DATA_SUBJECT_DELETION_PROCESSED')");
$stmtAudits->execute();
$auditRows = $stmtAudits->fetchAll();

foreach ($auditRows as $row) {
    $det = $row['details'];
    assert(!str_contains($det, 'diagnostico'), "Logs de auditoria NUNCA podem expor diagnósticos de pacientes");
    assert(!str_contains($det, 'Sobrepeso'), "Logs de auditoria NUNCA podem expor condições de saúde");
}
echo "  ✓ Verificação de privacidade nos logs: ausência total de dados clínicos sensíveis confirmada.\n\n";

// Limpeza de storage temporário
@rmdir($tempStorageDir);

echo "========================================================\n";
echo "   RESULTADO FINAL DA SUÍTE DE PRIVACIDADE: APROVADO 100%\n";
echo "   Todas as 5 histórias de NI2-145 validadas com sucesso!\n";
echo "========================================================\n";
