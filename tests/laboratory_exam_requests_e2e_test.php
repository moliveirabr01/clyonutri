<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\LaboratoryExam\ExamCatalogService;
use ClyoNutri\Domain\LaboratoryExam\ExamRegulatoryPolicy;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;
use ClyoNutri\Domain\LaboratoryExam\ProfessionalEligibilityValidator;

echo "========================================================================\n";
echo "   CLYONUTRI II — SUÍTE DE TESTES E2E: EXAMES LABORATORIAIS (NI2-529)\n";
echo "   Histórias Cobertas: NI2-530, NI2-531, NI2-532, NI2-533\n";
echo "========================================================================\n\n";

// 1. Inicializa banco de dados de teste isolado em memória
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

// 2. Executa toda a cadeia de migrações até a migration 64
echo "[TEST 1/6] Executando Migrations (1 a 64)...\n";
$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$executed = $migrator->run();
echo "  ✓ Migrações executadas com sucesso (" . count($executed) . " migrações aplicadas).\n";

// 3. Monta fixtures reais de Clínicas, Nutricionistas, Secretária e Pacientes
echo "\n[TEST 2/6] Configurando Fixtures de Multi-Tenancy e Usuários...\n";
$now = date('Y-m-d H:i:s');

// Clínica 1
$db->exec("INSERT INTO clinics (id, uuid, name, phone, created_at, updated_at) 
    VALUES (1, 'uuid-clinic-1', 'Clínica Nutrição Integrada Alfa', '(11) 3333-4444', '{$now}', '{$now}')");
// Clínica 2 (Tenant isolado)
$db->exec("INSERT INTO clinics (id, uuid, name, phone, created_at, updated_at) 
    VALUES (2, 'uuid-clinic-2', 'Consultório Nutricional Beta', '(21) 2222-3333', '{$now}', '{$now}')");

// Nutricionista Habilitado (Clínica 1)
$db->exec("INSERT INTO users (id, uuid, name, email, phone, password_hash, status, role, created_at, updated_at) 
    VALUES (1, 'uuid-user-1', 'Dra. Gabriela Vasconcelos', 'gabriela@alfa.com', '(11) 98888-7777', 'hash', 'active', 'nutritionist', '{$now}', '{$now}')");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) 
    VALUES (1, 1, 'nutritionist', 'active', '{$now}')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty, bio, created_at, updated_at) 
    VALUES (101, 1, '12345', 'CRN-3', 'Nutrição Clínica Funcional', 'Especialista em metabolismo', '{$now}', '{$now}')");

// Nutricionista Sem CRN (Clínica 1) - Para teste de bloqueio
$db->exec("INSERT INTO users (id, uuid, name, email, phone, password_hash, status, role, created_at, updated_at) 
    VALUES (2, 'uuid-user-2', 'Dr. Roberto Sem Registro', 'roberto@alfa.com', '(11) 97777-6666', 'hash', 'active', 'nutritionist', '{$now}', '{$now}')");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) 
    VALUES (1, 2, 'nutritionist', 'active', '{$now}')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty, created_at, updated_at) 
    VALUES (102, 2, '', 'CRN-3', 'Nutrição Geral', '{$now}', '{$now}')");

// Secretária / Administrativo (Clínica 1) - Para teste de bloqueio RBAC
$db->exec("INSERT INTO users (id, uuid, name, email, phone, password_hash, status, role, created_at, updated_at) 
    VALUES (3, 'uuid-user-3', 'Mariana Secretária', 'mariana@alfa.com', '(11) 96666-5555', 'hash', 'active', 'secretary', '{$now}', '{$now}')");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) 
    VALUES (1, 3, 'secretary', 'active', '{$now}')");

// Nutricionista da Clínica 2
$db->exec("INSERT INTO users (id, uuid, name, email, phone, password_hash, status, role, created_at, updated_at) 
    VALUES (4, 'uuid-user-4', 'Dra. Carla Beta', 'carla@beta.com', '(21) 95555-4444', 'hash', 'active', 'nutritionist', '{$now}', '{$now}')");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) 
    VALUES (2, 4, 'nutritionist', 'active', '{$now}')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty, created_at, updated_at) 
    VALUES (104, 4, '54321', 'CRN-4', 'Nutrição Esportiva', '{$now}', '{$now}')");

// Paciente da Clínica 1
$db->exec("INSERT INTO patients (id, uuid, clinic_id, name, email, phone, birth_date, gender, created_at, updated_at) 
    VALUES (10, 'uuid-patient-10', 1, 'Lucas Fernandes Silva', 'lucas@gmail.com', '(11) 91111-2222', '1990-05-15', 'male', '{$now}', '{$now}')");

// Paciente da Clínica 2 (Isolamento de Tenant)
$db->exec("INSERT INTO patients (id, uuid, clinic_id, name, email, phone, birth_date, gender, created_at, updated_at) 
    VALUES (20, 'uuid-patient-20', 2, 'Beatriz Mendes Costa', 'beatriz@gmail.com', '(21) 93333-4444', '1995-10-20', 'female', '{$now}', '{$now}')");

echo "  ✓ Fixtures inseridas: Clínicas 1 e 2, 3 profissionais, 1 secretária e 2 pacientes.\n";

// 4. Teste de Validação Regulatória e de Políticas (NI2-530)
echo "\n[TEST 3/6] Validando NI2-530 (Escopo Regulatório e Política CFN/CRN)...\n";

// A. Consulta da política ativa
$policy = ExamRegulatoryPolicy::getActivePolicy($db);
assert($policy['policy_version'] === '2026.1', 'Versão da política deve ser 2026.1');
assert((int)$policy['is_approved_clinical'] === 1, 'Aprovação clínica deve estar registrada');
assert((int)$policy['is_approved_legal'] === 1, 'Aprovação jurídica deve estar registrada');
echo "  ✓ Política regulatória v2026.1 ativa e auditável.\n";

// B. Bloqueio estrito de procedimentos invasivos / não laboratoriais (atos médicos)
$nonLabProcedures = [
    'Tomografia computadorizada de abdome total',
    'Ressonância magnética de crânio',
    'Endoscopia digestiva alta com biópsia',
    'Radiografia de tórax PA e perfil',
    'Colonoscopia completa',
    'Ecocardiograma transtorácico com doppler',
    'Cintilografia miocárdica',
];

foreach ($nonLabProcedures as $proc) {
    $blocked = false;
    try {
        ExamRegulatoryPolicy::assertLaboratorialScope($proc);
    } catch (\DomainException $e) {
        $blocked = true;
        assert(str_contains($e->getMessage(), 'laboratoriais'), 'Mensagem deve citar restrição a exames laboratoriais');
    }
    assert($blocked, "Procedimento médico invasivo '{$proc}' deveria ser bloqueado categoricamente.");
}
echo "  ✓ " . count($nonLabProcedures) . " procedimentos não laboratoriais (imagem/endoscopia/biópsia) bloqueados com sucesso.\n";

// C. Justificativa clínica: checagem de tamanho mínimo
$justificationBlocked = false;
try {
    ExamRegulatoryPolicy::assertNutritionalJustification('Muito curto');
} catch (\InvalidArgumentException $e) {
    $justificationBlocked = true;
}
assert($justificationBlocked, 'Justificativa com menos de 15 caracteres deve ser rejeitada.');
echo "  ✓ Justificativa insuficiente (<15 chars) rejeitada corretamente.\n";

// D. Disclaimer de operadoras / ANS não confirmado
$disclaimerBlocked = false;
try {
    ExamRegulatoryPolicy::assertCoverageDisclaimerAcknowledged(false);
} catch (\DomainException $e) {
    $disclaimerBlocked = true;
}
assert($disclaimerBlocked, 'Disclaimer de cobertura de operadoras não confirmado deve ser rejeitado.');
echo "  ✓ Trava de disclaimer de cobertura de convênios/ANS validada.\n";

// 5. Teste de Habilitação Profissional e RBAC (NI2-531)
echo "\n[TEST 4/6] Validando NI2-531 (Habilitação Profissional e Identidade)...\n";

// A. Nutricionista habilitado com CRN válido
$snapshot = ProfessionalEligibilityValidator::validateEligibility(1, 1, $db);
assert($snapshot['name'] === 'Dra. Gabriela Vasconcelos', 'Nome do profissional deve bater');
assert($snapshot['crn'] === '12345', 'CRN do profissional deve bater');
assert($snapshot['crn_formatted'] === 'CRN-3/12345', 'Formatação canônica deve ser CRN-3/12345');
assert($snapshot['clinic_name'] === 'Clínica Nutrição Integrada Alfa', 'Clínica deve bater');
echo "  ✓ Nutricionista habilitada validada e snapshot canônico gerado: " . $snapshot['crn_formatted'] . ".\n";

// B. Secretária tentando emitir solicitação (RBAC)
$secretaryBlocked = false;
try {
    ProfessionalEligibilityValidator::validateEligibility(3, 1, $db);
} catch (\DomainException $e) {
    $secretaryBlocked = true;
    assert(str_contains($e->getMessage(), 'Acesso negado'), 'Erro deve acusar acesso negado');
}
assert($secretaryBlocked, 'Secretária/administrativo não pode ter habilitação para emitir pedidos.');
echo "  ✓ Bloqueio RBAC para usuário não profissional (secretária) comprovado.\n";

// C. Nutricionista sem CRN
$noCrnBlocked = false;
try {
    ProfessionalEligibilityValidator::validateEligibility(2, 1, $db);
} catch (\DomainException $e) {
    $noCrnBlocked = true;
    assert(str_contains($e->getMessage(), 'CRN'), 'Erro deve informar ausência de CRN');
}
assert($noCrnBlocked, 'Profissional sem CRN deve ser bloqueado na emissão.');
echo "  ✓ Bloqueio de profissional com CRN ausente/irregular comprovado com orientação corretiva.\n";

// 6. Teste de Catálogo Curado e Sinônimos (NI2-532)
echo "\n[TEST 5/6] Validando NI2-532 (Catálogo Curado de Exames e Sinônimos)...\n";
$catalog = new ExamCatalogService($db);

// A. Busca por sinônimo clínico "cianocobalamina" (sinônimo de Vitamina B12)
$resB12 = $catalog->search('cianocobalamina');
assert(!empty($resB12), 'Busca por cianocobalamina deve retornar resultado');
$firstB12 = $resB12[0];
assert($firstB12['code'] === 'VITAMINA_B12', 'Primeiro resultado de cianocobalamina deve ser Vitamina B12');
assert($firstB12['matched_synonym'] === 'cianocobalamina', 'matched_synonym deve registrar cianocobalamina');
echo "  ✓ Busca por sinônimo 'cianocobalamina' retornou: '" . $firstB12['canonical_name'] . "' com aviso de sinônimo '" . $firstB12['matched_synonym'] . "'.\n";

// B. Busca tolerante a acentuação "acucar"
$resAcucar = $catalog->search('acucar');
assert(!empty($resAcucar), 'Busca por acucar sem cedilha deve localizar Glicemia');
$foundGlicemia = false;
foreach ($resAcucar as $item) {
    if ($item['code'] === 'GLICEMIA_JEJUM') {
        $foundGlicemia = true;
        break;
    }
}
assert($foundGlicemia, "Busca por 'acucar' deve encontrar Glicemia de jejum.");
echo "  ✓ Busca tolerante a acentos ('acucar') encontrou Glicemia de jejum com sucesso.\n";

// C. Inativação de exame e ocultação em novas buscas
$db->exec("UPDATE exam_catalog SET is_active = 0 WHERE code = 'ZINCO_SERICO'");
$resZinco = $catalog->search('zinco', null, true);
assert(empty($resZinco), 'Exame inativo não deve aparecer em busca com onlyActive=true');

$zincoEligibleBlocked = false;
$zincoRow = $catalog->getByCode('ZINCO_SERICO', false);
try {
    $catalog->assertExamEligible((int)$zincoRow['id']);
} catch (\DomainException $e) {
    $zincoEligibleBlocked = true;
    assert(str_contains($e->getMessage(), 'inativo'), 'Mensagem deve indicar que exame está inativo');
}
assert($zincoEligibleBlocked, 'assertExamEligible deve lançar exceção para exame inativo.');
echo "  ✓ Exame inativado no catálogo é ocultado de novos pedidos e bloqueado na seleção.\n";

// 7. Teste de Fluxo Completo de Solicitação, Persistência e Auditoria (NI2-533)
echo "\n[TEST 6/6] Validando NI2-533 (Persistência, Ciclo de Vida, Hash SHA256 e Auditoria)...\n";

// Configura contexto do tenant da Clínica 1
TenantContext::setTenant(1, ['id' => 1, 'name' => 'Dra. Gabriela Vasconcelos', 'role' => 'professional', 'email' => 'gabriela@alfa.com']);
$requestSvc = new ExamRequestService($db);

// A. Obter IDs válidos de exames (Hemograma, Glicemia, Lipidograma)
$examHemograma = $catalog->getByCode('HEMOGRAMA_COMPLETO');
$examGlicemia = $catalog->getByCode('GLICEMIA_JEJUM');
$examLipidios = $catalog->getByCode('PERFIL_LIPIDICO');

$examIds = [(int)$examHemograma['id'], (int)$examGlicemia['id'], (int)$examLipidios['id']];
$justification = 'Solicitação de exames para investigação e acompanhamento dietoterápico de paciente com queixa de fadiga pós-prandial e histórico familiar de dislipidemia mista.';
$diagnostic = 'Risco cardiometabólico / Suspeita de resistência à insulina';

// Emite a solicitação
$issued = $requestSvc->issueRequest(
    10, // Paciente Lucas (Clínica 1)
    1,  // Profissional Gabriela (Clínica 1)
    $examIds,
    $justification,
    $diagnostic,
    true,
    [(int)$examLipidios['id'] => 'Jejum prévio obrigatório de 12 horas. Evitar álcool nas 72h anteriores.']
);

assert(!empty($issued['id']), 'Solicitação deve retornar ID persistido');
assert($issued['status'] === 'issued', 'Status deve ser issued');
assert(str_starts_with($issued['request_number'], 'EXAM-'), 'Número da solicitação deve começar com EXAM-');
assert(!empty($issued['pdf_hash']) && strlen($issued['pdf_hash']) === 64, 'Hash SHA256 de integridade deve ter 64 caracteres hexadecimais');
assert(count($issued['items']) === 3, 'A solicitação deve conter 3 itens de exames persistidos');
assert($issued['professional_snapshot']['crn_formatted'] === 'CRN-3/12345', 'Snapshot do profissional deve ser registrado');
assert($issued['patient_snapshot']['name'] === 'Lucas Fernandes Silva', 'Snapshot do paciente deve ser registrado');

echo "  ✓ Solicitação criada e persistida com sucesso: Pedido {$issued['request_number']} (ID: {$issued['id']}).\n";
echo "    Hash SHA256 de Integridade: {$issued['pdf_hash']}\n";

// B. Listagem pelo paciente
$patientRequests = $requestSvc->listByPatient(10, 1);
assert(count($patientRequests) === 1, 'Deve haver 1 solicitação listada para o paciente Lucas');
assert((int)$patientRequests[0]['total_items'] === 3, 'Total de itens deve ser 3');
echo "  ✓ Listagem no prontuário do paciente verificada com 1 pedido e 3 itens.\n";

// C. Teste de Violação Multi-tenant: Nutricionista da Clínica 1 tentando emitir para paciente da Clínica 2
$crossTenantBlocked = false;
try {
    $requestSvc->issueRequest(20, 1, $examIds, $justification, $diagnostic, true);
} catch (\Throwable $e) {
    $crossTenantBlocked = true;
    assert(str_contains($e->getMessage(), 'clínica') || str_contains($e->getMessage(), 'Acesso negado'), 'Erro deve acusar tenant cruzado');
}
assert($crossTenantBlocked, 'Emissão cross-tenant entre clínicas distintas deve ser impedida categoricamente.');
echo "  ✓ Tentativa de violação cross-tenant bloqueada categoricamente.\n";

// D. Teste de Cancelamento de Solicitação com Motivo Obrigatório
$cancelled = $requestSvc->cancelRequest(
    (int)$issued['id'],
    1,
    'Substituição por novo plano dietoterápico após reavaliação clínica presencial.'
);
assert($cancelled['status'] === 'cancelled', 'Status após cancelamento deve ser cancelled');
assert(!empty($cancelled['cancelled_at']), 'Data de cancelamento deve estar preenchida');
assert(str_contains($cancelled['cancellation_reason'], 'Substituição por novo plano'), 'Motivo de cancelamento deve estar gravado');
echo "  ✓ Cancelamento da solicitação testado com preservação do histórico e motivo.\n";

// E. Verificação da Trilha de Auditoria Imutável (exam_audit_logs)
$stmtAudit = $db->prepare("SELECT * FROM exam_audit_logs WHERE entity_id = ? ORDER BY id ASC");
$stmtAudit->execute([(int)$issued['id']]);
$audits = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
assert(count($audits) === 2, 'Devem existir 2 registros de auditoria para o pedido (emissão e cancelamento)');
assert($audits[0]['action'] === 'EXAM_REQUEST_ISSUED', 'Primeira ação de auditoria deve ser EXAM_REQUEST_ISSUED');
assert($audits[1]['action'] === 'EXAM_REQUEST_CANCELLED', 'Segunda ação de auditoria deve ser EXAM_REQUEST_CANCELLED');
echo "  ✓ Trilha de auditoria verificada: " . count($audits) . " eventos gravados (ISSUED e CANCELLED).\n";

echo "\n========================================================================\n";
echo "   TODOS OS TESTES DA SUÍTE DE EXAMES LABORATORIAIS PASSARAM! (6/6) ✓\n";
echo "========================================================================\n";
