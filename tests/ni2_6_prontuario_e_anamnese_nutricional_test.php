<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Food\FoodCatalogService;

echo "====================================================================\n";
echo "   CLYONUTRI TEST SUITE: ÉPICO NI2-6 (PRONTUÁRIO & ANAMNESE)\n";
echo "   Histórias: NI2-60, NI2-61, NI2-62, NI2-63, NI2-64\n";
echo "====================================================================\n\n";

// 1. Setup Banco SQLite in-memory & Migrations
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

// 2. Setup Tenants e Profissionais
$auth = new AuthService($db);
$tenantA = $auth->registerNutritionist('Dra. Clara Mendes', 'clara@clinica-a.test', 'SenhaForte@1', 'Clínica Alpha Nutrição', '11111111', 'CRN-1111');
$clinicAId = (int)$tenantA['clinic_id'];
$profAId = (int)$tenantA['user_id'];

$tenantB = $auth->registerNutritionist('Dr. Bruno Rocha', 'bruno@clinica-b.test', 'SenhaForte@2', 'Clínica Beta Saúde', '22222222', 'CRN-2222');
$clinicBId = (int)$tenantB['clinic_id'];
$profBId = (int)$tenantB['user_id'];

TenantContext::set($clinicAId, ['id' => $profAId, 'name' => 'Dra. Clara Mendes']);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Lucas Ferreira', 'email' => 'lucas@ferreira.test']);
$patientId = (int)$patient['id'];

$clinicalRecordService = new ClinicalRecordService($db);
$anamnesisService = new AnamnesisService($db);

echo "✓ Infraestrutura, bancos isolados e contas profissionais inicializados com sucesso.\n\n";

// -----------------------------------------------------------------------------
// [1/5] NI2-60: [Prontuário] Registro nutricional longitudinal append-only
// -----------------------------------------------------------------------------
echo "[1/5] Validando NI2-60: Registro nutricional longitudinal...\n";

// 1.1 Inserção do primeiro registro
$rec1 = $clinicalRecordService->appendRecord($patientId, $profAId, 'Consulta de Avaliação Inicial', [
    'queixa_principal' => 'Alergia alimentar suspeita e cansaço',
    'conduta' => 'Solicitado recordatório alimentar e investigação de intolerâncias',
]);
assert($rec1['version'] === 1, "Versão inicial deve ser 1");
assert($rec1['previous_hash'] === ClinicalRecordService::GENESIS_HASH, "Primeiro hash deve apontar para GENESIS_HASH");
assert(strlen($rec1['record_hash']) === 64, "Hash deve ser SHA-256 canônico de 64 caracteres");

// Verificar persistência no banco
$stmtRec1 = $db->prepare("SELECT * FROM clinical_records WHERE id = :id");
$stmtRec1->execute(['id' => $rec1['id']]);
$dbRec1 = $stmtRec1->fetch();
assert($dbRec1['title'] === 'Consulta de Avaliação Inicial', "Título deve ser gravado fielmente");
assert((int)$dbRec1['clinic_id'] === $clinicAId, "Deve respeitar o tenant clinic_id");
assert((int)$dbRec1['is_retracted'] === 0, "Registro inicial não deve ser superado");

// Verificar auditoria sem PII
$stmtAudit = $db->prepare("SELECT * FROM audit_logs WHERE resource = 'clinical_records' AND action = 'CLINICAL_RECORD_APPENDED' AND resource_id = :id");
$stmtAudit->execute(['id' => (string)$rec1['id']]);
$auditEntry = $stmtAudit->fetch();
assert(!empty($auditEntry), "Auditoria formal deve ser registrada");
$auditDetails = json_decode($auditEntry['details'], true);
assert((int)$auditDetails['patient_id'] === $patientId, "Auditoria deve conter patient_id");
assert($auditDetails['hash'] === $rec1['record_hash'], "Auditoria deve conter o hash criptográfico");

// 1.2 Retificação versionada com política formal (NI2-60)
$rec2 = $clinicalRecordService->reviseRecord(
    $rec1['id'],
    $patientId,
    $profAId,
    'Retificação de conduta: inclusão de solicitação de exames laboratoriais específicos',
    [
        'queixa_principal' => 'Alergia alimentar suspeita e cansaço',
        'conduta' => 'Solicitado recordatório alimentar e exames de IgE específica',
    ]
);
assert($rec2['version'] === 2, "Versão deve ser incrementada para 2");
assert($rec2['supersedes_id'] === $rec1['id'], "supersedes_id deve apontar para v1");
assert($rec2['previous_hash'] === $rec1['record_hash'], "previous_hash da v2 deve ser o hash da v1");

// Validar que v1 foi marcado como superado mas permanece intacto no histórico
$stmtRec1After = $db->prepare("SELECT is_retracted, content_json FROM clinical_records WHERE id = :id");
$stmtRec1After->execute(['id' => $rec1['id']]);
$dbRec1After = $stmtRec1After->fetch();
assert((int)$dbRec1After['is_retracted'] === 1, "v1 deve ser marcado como superado (retracted)");
$c1 = json_decode($dbRec1After['content_json'], true);
assert($c1['conduta'] === 'Solicitado recordatório alimentar e investigação de intolerâncias', "Conteúdo original da v1 NUNCA pode ser alterado");

// 1.3 Rejeição de retificação sem justificativa adequada (< 10 caracteres)
$shortReasonRejected = false;
try {
    $clinicalRecordService->reviseRecord($rec2['id'], $patientId, $profAId, 'ajuste', ['texto' => 'novo']);
} catch (\InvalidArgumentException $e) {
    $shortReasonRejected = true;
}
assert($shortReasonRejected === true, "Justificativa curta (< 10 caracteres) deve ser rejeitada");

// 1.4 Barreira multi-tenant (Tenant B tentando gravar no paciente do Tenant A)
TenantContext::set($clinicBId, ['id' => $profBId, 'name' => 'Dr. Bruno Rocha']);
$crossTenantBlocked = false;
try {
    $clinicalRecordService->appendRecord($patientId, $profBId, 'Tentativa invasora', ['data' => 123]);
} catch (\RuntimeException $e) {
    $crossTenantBlocked = true;
}
assert($crossTenantBlocked === true, "Acesso cross-tenant no prontuário deve ser sumariamente bloqueado (403)");
TenantContext::set($clinicAId, ['id' => $profAId, 'name' => 'Dra. Clara Mendes']); // Restaura Tenant A

echo "  ✓ NI2-60 Aprovado: registros datados, autoria rastreável, histórico imutável, retificação versionada e isolamento multi-tenant comprovados.\n\n";

// -----------------------------------------------------------------------------
// [2/5] NI2-61: [Anamnese] Criar anamnese nutricional estruturada
// -----------------------------------------------------------------------------
echo "[2/5] Validando NI2-61: Anamnese nutricional estruturada...\n";

// 2.1 Salvamento Parcial / Rascunho (isDraft = true)
$draftAnamnesis = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'objectives' => 'Paciente busca hipertrofia e melhora da disposição matinal',
    'routine' => 'Acorda às 06h, treino às 07h, trabalho de escritório até 18h',
], true);
assert($draftAnamnesis['version'] === 1, "Primeira versão deve ser 1");
assert($draftAnamnesis['is_draft'] === true, "Deve ser registrado como rascunho");

// Verificar persistência
$latestDraft = $anamnesisService->getLatestAnamnesis($patientId);
assert((int)$latestDraft['is_draft'] === 1, "Banco deve persistir flag is_draft = 1");
assert($latestDraft['objectives'] === 'Paciente busca hipertrofia e melhora da disposição matinal', "Objetivos persistidos com integridade");
assert($latestDraft['allergies'] === null, "Campos não preenchidos devem ser null no salvamento parcial");

// 2.2 Salvamento Finalizado Completo Estruturado
$finalAnamnesis = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'objectives' => 'Hipertrofia com foco em percentual de gordura sub-14%',
    'routine' => 'Rotina comercial padrão 08h-18h, sono de 7h/noite',
    'food_preferences' => 'Frutas cítricas, ovos, arroz integral, peixes',
    'restrictions' => 'Intolerância moderada a glúten. Dieta hipofodmap quando sintomático',
    'allergies' => 'Alergia severa a amendoim e nozes. Risco de choque anafilático',
    'symptoms' => 'Distensão pós-prandial ao consumir derivados de trigo',
    'habits' => 'Não fuma, consome álcool socialmente aos fins de semana',
    'clinical_history' => 'Histórico familiar de DM2. Sem medicações contínuas',
    'water_intake_ml' => 3000,
    'notes' => 'Paciente muito motivado. Prescrever plano hiperproteico limpo',
], false);
assert($finalAnamnesis['version'] === 2, "Versão finalizada deve ser incrementada para 2");
assert($finalAnamnesis['is_draft'] === false, "Deve ser finalizada (is_draft = false)");

// 2.3 Validação fail-closed: tentativa de salvar anamnese finalizada 100% vazia
$emptyRejected = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [], false);
} catch (\InvalidArgumentException $e) {
    $emptyRejected = true;
}
assert($emptyRejected === true, "Anamnese finalizada vazia deve ser rejeitada com InvalidArgumentException");

// 2.4 Validação de limite e sanitização contra DoS
$hugeText = str_repeat('A', 8000);
$sanitizedRes = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'objectives' => $hugeText,
], true);
$checkSanitized = $anamnesisService->getAnamnesisVersion($patientId, $sanitizedRes['version']);
assert(mb_strlen($checkSanitized['objectives'], 'UTF-8') === AnamnesisService::MAX_FIELD_LENGTH, "Texto excessivo deve ser truncado com segurança em MAX_FIELD_LENGTH");

echo "  ✓ NI2-61 Aprovado: seções clínicas completas, salvamento parcial (rascunho), validação fail-closed e sanitização comprovadas.\n\n";

// -----------------------------------------------------------------------------
// [3/5] NI2-62: [Anamnese] Versionar e preservar histórico
// -----------------------------------------------------------------------------
echo "[3/5] Validando NI2-62: Versionar e preservar histórico da anamnese...\n";

// 3.1 Criar uma 4ª versão com atualização de alergias relatadas em consulta posterior
$anamnesisV4 = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'objectives' => 'Hipertrofia com foco em percentual de gordura sub-14%',
    'routine' => 'Rotina comercial padrão 08h-18h, sono de 8h/noite',
    'food_preferences' => 'Frutas cítricas, ovos, arroz integral, peixes, carne vermelha',
    'restrictions' => 'Intolerância moderada a glúten. Dieta hipofodmap quando sintomático',
    'allergies' => 'Alergia severa a amendoim, nozes e camarão.',
    'symptoms' => 'Sem queixas gastrointestinais após corte do glúten',
    'habits' => 'Abstêmio de álcool no último mês',
    'clinical_history' => 'Histórico familiar de DM2. Sem medicações contínuas',
    'water_intake_ml' => 3500,
], false);
assert($anamnesisV4['version'] === 4, "Versão deve ser 4");

// 3.2 Listar histórico completo e verificar imutabilidade
$history = $anamnesisService->getAnamnesisHistory($patientId);
assert(count($history) === 4, "Deve conter exatamente 4 versões no histórico");
assert($history[0]['version'] === 4, "Primeira da lista reversa deve ser a versão 4");
assert($history[3]['version'] === 1, "Última da lista reversa deve ser a versão 1");

// Validar que a versão 2 não foi alterada pelas novas versões
$v2Original = $anamnesisService->getAnamnesisVersion($patientId, 2);
assert($v2Original['allergies'] === 'Alergia severa a amendoim e nozes. Risco de choque anafilático', "v2 não pode ter 'camarão'");
assert($history[0]['allergies'] === 'Alergia severa a amendoim, nozes e camarão.', "v4 contém a nova alergia registrada");

// 3.3 Ferramenta de Comparação / Diff Clínico entre Versões
$diff = $anamnesisService->compareVersions($patientId, 2, 4);
assert($diff['patient_id'] === $patientId, "Diff deve referenciar o paciente correto");
assert($diff['diff']['allergies']['changed'] === true, "Alergias devem estar marcadas como alteradas no diff");
assert($diff['diff']['allergies']['v1'] === 'Alergia severa a amendoim e nozes. Risco de choque anafilático', "v1 do diff deve conter texto original");
assert($diff['diff']['allergies']['v2'] === 'Alergia severa a amendoim, nozes e camarão.', "v2 do diff deve conter novo texto");
assert($diff['diff']['clinical_history']['changed'] === false, "Histórico clínico não mudou e deve estar marcado como changed=false");

echo "  ✓ NI2-62 Aprovado: versionamento estritamente imutável, comparação side-by-side e histórico cronológico íntegro.\n\n";

// -----------------------------------------------------------------------------
// [4/5] NI2-63: [Prontuário] Observações clínicas seguras
// -----------------------------------------------------------------------------
echo "[4/5] Validando NI2-63: Observações clínicas seguras e exportação auditada...\n";

// 4.1 Adicionar anotação clínica segura
$obsRecord = $clinicalRecordService->addClinicalObservation(
    $patientId,
    $profAId,
    'Nota de Acompanhamento Intermediário',
    'Paciente relata excelente adesão à meta hídrica de 3500ml e melhora do sono.',
    'observacao'
);
assert($obsRecord['version'] === 3, "Prontuário incrementou para versão 3");
assert(strlen($obsRecord['record_hash']) === 64, "Hash SHA-256 gerado e encadeado");

// 4.2 Rejeição de observação acima do limite de caracteres (10.000)
$hugeNoteRejected = false;
try {
    $clinicalRecordService->addClinicalObservation($patientId, $profAId, 'Nota Inválida', str_repeat('X', 10001));
} catch (\InvalidArgumentException $e) {
    $hugeNoteRejected = true;
}
assert($hugeNoteRejected === true, "Observação acima do limite permitido (10.000 caracteres) deve ser rejeitada com InvalidArgumentException");

// 4.3 Exportação auditada da linha do tempo com validação de integridade criptográfica
$exportedTimeline = $clinicalRecordService->exportRecordTimelineSafe($patientId, $profAId);
assert($exportedTimeline['patient_id'] === $patientId, "ID do paciente deve coincidir");
assert($exportedTimeline['integrity']['is_valid'] === true, "Integridade deve ser validada antes de exportar");
assert(count($exportedTimeline['records']) === 3, "Total de 3 registros exportados na linha do tempo");

// Verificar se o evento de exportação foi auditado
$stmtExportAudit = $db->prepare("SELECT * FROM audit_logs WHERE resource = 'clinical_records' AND action = 'CLINICAL_TIMELINE_EXPORTED'");
$stmtExportAudit->execute();
$exportAuditLog = $stmtExportAudit->fetch();
assert(!empty($exportAuditLog), "Exportação de prontuário DEVE ser formalmente auditada");

// 4.4 Simular detecção de corrupção/adulteração no banco bloqueando a exportação fail-closed
$db->exec("UPDATE clinical_records SET content_json = '{\"forjado\":true}' WHERE id = {$rec1['id']}");
$tamperBlocked = false;
try {
    $clinicalRecordService->exportRecordTimelineSafe($patientId, $profAId);
} catch (\RuntimeException $e) {
    $tamperBlocked = true;
}
assert($tamperBlocked === true, "Exportação de prontuário com adulteração detectada DEVE ser bloqueada (fail-closed)");

// Restaurar integridade
$db->prepare("UPDATE clinical_records SET content_json = :json WHERE id = :id")
   ->execute(['json' => $dbRec1After['content_json'], 'id' => $rec1['id']]);
$restoredCheck = $clinicalRecordService->verifyIntegrity($patientId);
assert($restoredCheck['is_valid'] === true, "Integridade restabelecida com sucesso");

echo "  ✓ NI2-63 Aprovado: limites de segurança, sanitização, exportação auditada e fail-closed contra adulteração validados.\n\n";

// -----------------------------------------------------------------------------
// [5/5] NI2-64: [Prontuário] Resumo clínico para prescrição
// -----------------------------------------------------------------------------
echo "[5/5] Validando NI2-64: Resumo clínico para prescrição estruturada...\n";

// 5.1 Paciente novo sem anamnese: nunca inventa dados
$newPatient = $patientRepo->create(['name' => 'Mariana Costa', 'email' => 'mariana@costa.test']);
$summaryNew = $anamnesisService->getClinicalSummaryForPrescription((int)$newPatient['id']);
assert($summaryNew['has_data'] === false, "has_data deve ser false");
assert($summaryNew['allergies'] === null, "Alergias devem ser estritamente null");
assert($summaryNew['restrictions'] === null, "Restrições devem ser estritamente null");
assert(empty($summaryNew['critical_alerts']), "Sem alertas críticos inventados");

// 5.2 Paciente com anamnese ativa: dados rastreáveis destacados
$summaryActive = $anamnesisService->getClinicalSummaryForPrescription($patientId);
assert($summaryActive['has_data'] === true, "has_data deve ser true");
assert(!empty($summaryActive['allergies']), "Alergias devem estar presentes");
assert(str_contains($summaryActive['allergies'], 'amendoim'), "Deve listar amendoim");
assert(str_contains($summaryActive['allergies'], 'camarão'), "Deve listar camarão");
assert(count($summaryActive['critical_alerts']) === 2, "Deve destacar 2 alertas críticos (Alergia e Restrição)");
assert($summaryActive['critical_alerts'][0]['type'] === 'ALERGIA', "Alerta 1 deve ser ALERGIA");
assert($summaryActive['critical_alerts'][0]['severity'] === 'CRITICAL', "Severidade de alergia deve ser CRITICAL");
assert($summaryActive['source']['version'] === 4, "Origem deve rastrear versão exata 4");
assert($summaryActive['source']['url'] === "/pacientes/{$patientId}/anamnese?version=4", "Link da fonte deve ser rastreável");

// 5.3 Integração com segurança prescritiva: Validador de alergênicos contra prescrição
$foodCatalog = new FoodCatalogService($db);
$foodCatalog->seedTacoReference();
$mealPlanService = new MealPlanService($db);

// Tentar prescrever camarão para paciente com alergia a camarão na anamnese
$stmtCamarao = $db->prepare("SELECT * FROM foods WHERE name LIKE '%Camarão%' OR name LIKE '%camarão%' LIMIT 1");
$stmtCamarao->execute();
$camarao = $stmtCamarao->fetch();

if (!$camarao) {
    // Insere alimento com grupo alergênico de frutos do mar
    $db->prepare("INSERT INTO foods (name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, allergens) VALUES ('Camarão cozido', 'Pescados', 90, 20, 0, 1, 'frutos do mar, crustáceos')")->execute();
    $camaraoId = (int)$db->lastInsertId();
} else {
    $camaraoId = (int)$camarao['id'];
}

$planWithAllergen = $mealPlanService->createPlan($patientId, $profAId, 'Cardápio Incompatível com Anamnese', [
    [
        'name' => 'Jantar',
        'time_of_day' => '20:00',
        'items' => [
            ['food_id' => $camaraoId, 'amount' => 150, 'measure_unit' => 'g'],
        ],
    ],
]);

$conflictDetected = false;
try {
    $mealPlanService->publishPlan($planWithAllergen['plan_id']);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'conflito de alergênicos')) {
        $conflictDetected = true;
    }
}
assert($conflictDetected === true, "Publicação de plano alimentar com ingrediente conflitante com a anamnese DEVE ser interceptada");

echo "  ✓ NI2-64 Aprovado: resumo sem dados inferidos, links de rastreabilidade e integração ativa com segurança de prescrição comprovadas.\n\n";

echo "====================================================================\n";
echo "   TODOS OS CRITÉRIOS DE ACEITE DO ÉPICO NI2-6 FORAM ATENDIDOS!\n";
echo "   NI2-60, NI2-61, NI2-62, NI2-63 e NI2-64 VALIDADOS COM 100% PASS.\n";
echo "====================================================================\n";
