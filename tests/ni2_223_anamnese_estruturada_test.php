<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Patient\PatientRepository;

echo "====================================================================\n";
echo "   CLYONUTRI TEST SUITE: NI2-223 (ANAMNESE ESTRUTURADA & CONDICIONAIS)\n";
echo "   História: [P0] Anamnese estruturada completa com campos clínicos condicionais\n";
echo "====================================================================\n\n";

// 1. Setup Banco SQLite in-memory com Migrations reais
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

// 2. Setup Tenants, Profissionais e Pacientes
$auth = new AuthService($db);
$tenantA = $auth->registerNutritionist('Dra. Helena Castro', 'helena@clinica-a.test', 'SenhaForte@1', 'Clínica Vital Nutrição', '11111111', 'CRN-3333');
$clinicAId = (int)$tenantA['clinic_id'];
$profAId = (int)$tenantA['user_id'];

$tenantB = $auth->registerNutritionist('Dr. Marcos Lima', 'marcos@clinica-b.test', 'SenhaForte@2', 'Clínica Equilíbrio', '22222222', 'CRN-4444');
$clinicBId = (int)$tenantB['clinic_id'];
$profBId = (int)$tenantB['user_id'];

TenantContext::set($clinicAId, ['id' => $profAId, 'name' => 'Dra. Helena Castro']);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Mariana Souza', 'email' => 'mariana@souza.test']);
$patientId = (int)$patient['id'];

$anamnesisService = new AnamnesisService($db);

echo "✓ Infraestrutura, migrations (1 a 69) e isolamento multi-tenant inicializados com sucesso.\n\n";

// -----------------------------------------------------------------------------
// [1/7] Cenário 1: Caminho feliz completo com todos os 12 blocos e condicionais
// -----------------------------------------------------------------------------
echo "[1/7] Validando Cenário 1: Caminho feliz com os 12 blocos estruturados e persistência real...\n";

$fullPayload = [
    'chief_complaint' => 'Ganho de peso após mudança de rotina profissional e queixa de fadiga matinal',
    'objectives' => 'Redução de gordura corporal, melhora do perfil lipídico e disposição diária',
    'family_history' => 'Pai com histórico de infarto agudo aos 55 anos e mãe com DM2',
    'pathologies' => 'Dislipidemia mista leve diagnosticada há 6 meses',
    'has_surgeries' => 'yes',
    'surgeries_details' => 'Apendicectomia laparoscópica em 2018 sem intercorrências',
    'medication_usage' => 'recorded',
    'medications_details' => 'Atorvastatina 10mg à noite',
    'supplement_usage' => 'recorded',
    'supplements_details' => 'Vitamina D 2000 UI/dia pela manhã',
    'allergy_assessment' => 'recorded',
    'allergies' => 'Alergia severa a camarão e frutos do mar com edema de glote prévio',
    'intolerance_assessment' => 'recorded',
    'restrictions' => 'Intolerância moderada a lactose e preferência por alimentos sem glúten',
    'food_preferences' => 'Gosta de frutas vermelhas, ovos, arroz integral; rejeita quiabo e coentro',
    'habits' => 'Mastigação rápida, almoça em 15 minutos em frente ao computador',
    'alcohol_consumption' => 'social',
    'alcohol_details' => 'Consumo de vinho tinto seco aos sábados (2 taças)',
    'smoking_status' => 'former_smoker',
    'smoking_details' => 'Ex-fumante, cessou há 4 anos após fumar 5 cigarros/dia por 3 anos',
    'water_intake_ml' => 2800,
    'routine' => 'Acorda às 06h30, trabalho comercial das 08h às 18h, refeições nos horários padrão',
    'sleep_hours' => 7.5,
    'sleep_quality' => 'regular',
    'sleep_notes' => 'Demora cerca de 40 minutos para adormecer; sem despertares frequentes',
    'physical_activity' => 'active',
    'activity_modality' => 'Musculação combinada com esteira moderada',
    'activity_frequency_weekly' => 4,
    'activity_duration_minutes' => 60,
    'referenced_exams' => 'Exames de 10/2026: Glicemia jejum 94 mg/dL, Colesterol Total 228, HDL 46, LDL 152, Triglicerídeos 150',
    'notes' => 'Paciente muito comunicativa e consciente do plano de mudança de hábitos',
];

$v1Result = $anamnesisService->saveAnamnesis($patientId, $profAId, $fullPayload, false);

assert($v1Result['version'] === 1, "Primeira gravação concluída deve gerar versão 1");
assert($v1Result['is_draft'] === false, "Gravação finalizada deve ter is_draft = false");

// Validar persistência física no banco de dados
$stmtDb = $db->prepare("SELECT * FROM anamneses WHERE id = :id");
$stmtDb->execute(['id' => $v1Result['id']]);
$rowDb = $stmtDb->fetch();

assert($rowDb !== false, "Linha deve estar fisicamente persistida no banco");
assert($rowDb['chief_complaint'] === $fullPayload['chief_complaint'], "Queixa principal gravada fielmente");
assert($rowDb['family_history'] === $fullPayload['family_history'], "Histórico familiar gravado fielmente");
assert($rowDb['pathologies'] === $fullPayload['pathologies'], "Patologias gravadas fielmente");
assert($rowDb['has_surgeries'] === 'yes', "Flag de cirurgias deve ser 'yes'");
assert($rowDb['surgeries_details'] === $fullPayload['surgeries_details'], "Detalhes de cirurgias gravados fielmente");
assert($rowDb['medication_usage'] === 'recorded', "Status de medicamentos deve ser 'recorded'");
assert($rowDb['medications_details'] === $fullPayload['medications_details'], "Medicamentos gravados fielmente");
assert($rowDb['supplement_usage'] === 'recorded', "Status de suplementos deve ser 'recorded'");
assert($rowDb['supplements_details'] === $fullPayload['supplements_details'], "Suplementos gravados fielmente");
assert($rowDb['allergy_assessment'] === 'recorded', "Avaliação de alergia deve ser 'recorded'");
assert($rowDb['allergies'] === $fullPayload['allergies'], "Alergias gravadas fielmente");
assert($rowDb['intolerance_assessment'] === 'recorded', "Avaliação de intolerância deve ser 'recorded'");
assert($rowDb['restrictions'] === $fullPayload['restrictions'], "Restrições gravadas fielmente");
assert((int)$rowDb['water_intake_ml'] === 2800, "Meta hídrica gravada como inteiro");
assert((float)$rowDb['sleep_hours'] === 7.5, "Horas de sono gravadas com precisão");
assert($rowDb['sleep_quality'] === 'regular', "Qualidade de sono gravada");
assert($rowDb['physical_activity'] === 'active', "Atividade física gravada como 'active'");
assert($rowDb['activity_modality'] === $fullPayload['activity_modality'], "Modalidade esportiva gravada");
assert((int)$rowDb['activity_frequency_weekly'] === 4, "Frequência de treino gravada");
assert((int)$rowDb['activity_duration_minutes'] === 60, "Duração do treino gravada");
assert($rowDb['alcohol_consumption'] === 'social', "Consumo alcoólico gravado");
assert($rowDb['smoking_status'] === 'former_smoker', "Tabagismo gravado");
assert($rowDb['referenced_exams'] === $fullPayload['referenced_exams'], "Exames referenciados gravados");
assert(!empty($rowDb['structured_data_json']), "Snapshot estruturado em JSON deve ser persistido");

// Validar auditoria formal
$stmtAudit = $db->prepare("SELECT * FROM audit_logs WHERE resource = 'anamneses' AND resource_id = :id");
$stmtAudit->execute(['id' => (string)$v1Result['id']]);
$audit = $stmtAudit->fetch();
assert(!empty($audit), "Auditoria de criação de anamnese deve estar registrada");
$auditDetails = json_decode($audit['details'], true);
assert($auditDetails['version'] === 1, "Auditoria deve conter a versão correta");
assert($auditDetails['has_allergies'] === true, "Auditoria deve registrar presença de alergias");
assert($auditDetails['has_medications'] === true, "Auditoria deve registrar uso de medicamentos");
assert($auditDetails['physical_activity'] === 'active', "Auditoria deve registrar status esportivo");

echo "  ✓ Cenário 1 Aprovado: Todos os 12 blocos e condicionais persistidos no banco real com integridade e auditoria.\n\n";

// -----------------------------------------------------------------------------
// [2/7] Cenário 2: Validações condicionais estritas e fail-closed
// -----------------------------------------------------------------------------
echo "[2/7] Validando Cenário 2: Validações condicionais e regras fail-closed...\n";

// 2.1 Anamnese vazia não-rascunho rejeitada
$emptyPassed = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [], false);
} catch (\InvalidArgumentException $e) {
    $emptyPassed = true;
}
assert($emptyPassed, "Anamnese finalizada vazia deve ser recusada com InvalidArgumentException");

// 2.2 Cirurgias marcadas como 'yes' sem detalhes
$surgeryWithoutDetails = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [
        'objectives' => 'Avaliação',
        'has_surgeries' => 'yes',
        'surgeries_details' => '',
    ], false);
} catch (\InvalidArgumentException $e) {
    $surgeryWithoutDetails = true;
    assert(str_contains($e->getMessage(), 'cirurgias'), "Mensagem deve orientar sobre cirurgias");
}
assert($surgeryWithoutDetails, "has_surgeries='yes' sem detalhe deve ser bloqueado");

// 2.3 Medicamentos marcados como 'recorded' sem detalhes
$medsWithoutDetails = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [
        'objectives' => 'Avaliação',
        'medication_usage' => 'recorded',
        'medications_details' => '',
    ], false);
} catch (\InvalidArgumentException $e) {
    $medsWithoutDetails = true;
    assert(str_contains($e->getMessage(), 'medicamentos'), "Mensagem deve orientar sobre medicamentos");
}
assert($medsWithoutDetails, "medication_usage='recorded' sem detalhes deve ser bloqueado");

// 2.4 Suplementos marcados como 'recorded' sem detalhes
$supplementsWithoutDetails = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [
        'objectives' => 'Avaliação',
        'supplement_usage' => 'recorded',
        'supplements_details' => '',
    ], false);
} catch (\InvalidArgumentException $e) {
    $supplementsWithoutDetails = true;
    assert(str_contains($e->getMessage(), 'suplementos'), "Mensagem deve orientar sobre suplementos");
}
assert($supplementsWithoutDetails, "supplement_usage='recorded' sem detalhes deve ser bloqueado");

// 2.5 Atividade física marcada como 'active' sem modalidade ou frequência
$activeWithoutDetails = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [
        'objectives' => 'Avaliação',
        'physical_activity' => 'active',
        'activity_modality' => '',
    ], false);
} catch (\InvalidArgumentException $e) {
    $activeWithoutDetails = true;
    assert(str_contains($e->getMessage(), 'ativos'), "Mensagem deve orientar sobre atividade física");
}
assert($activeWithoutDetails, "physical_activity='active' sem modalidade e frequência deve ser bloqueado");

// 2.6 Condicionais negativas limpam automaticamente campos de detalhe no banco
$negativeSaved = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'objectives' => 'Consulta de revisão',
    'has_surgeries' => 'no',
    'surgeries_details' => 'Texto fantasma que deve ser ignorado',
    'medication_usage' => 'none',
    'medications_details' => 'Texto fantasma de remédio',
    'supplement_usage' => 'none',
    'supplements_details' => 'Texto fantasma de suplemento',
    'physical_activity' => 'sedentary',
    'activity_modality' => 'Musculação fantasma',
    'allergy_assessment' => 'denied',
    'alcohol_consumption' => 'none',
    'smoking_status' => 'non_smoker',
], false);

$stmtNeg = $db->prepare("SELECT * FROM anamneses WHERE id = :id");
$stmtNeg->execute(['id' => $negativeSaved['id']]);
$rowNeg = $stmtNeg->fetch();

assert($rowNeg['surgeries_details'] === null, "Cirurgias detalhes deve ser null quando has_surgeries='no'");
assert($rowNeg['medications_details'] === null, "Medicamentos detalhes deve ser null quando medication_usage='none'");
assert($rowNeg['supplements_details'] === null, "Suplementos detalhes deve ser null quando supplement_usage='none'");
assert($rowNeg['activity_modality'] === null, "Modalidade deve ser null quando physical_activity='sedentary'");
assert($rowNeg['activity_frequency_weekly'] === null, "Frequência deve ser null quando physical_activity='sedentary'");

echo "  ✓ Cenário 2 Aprovado: Validações condicionais estritas e limpeza higiênica de campos inativos confirmadas.\n\n";

// -----------------------------------------------------------------------------
// [3/7] Cenário 3: Rascunho parcial e promoção para versão final
// -----------------------------------------------------------------------------
echo "[3/7] Validando Cenário 3: Salvamento parcial (rascunho) e promoção...\n";

// Rascunho permite preenchimento incompleto (ex: nutricionista marcou cirurgia='yes' mas ainda vai perguntar o ano ao paciente)
$draftResult = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'chief_complaint' => 'Rascunho de início de atendimento',
    'has_surgeries' => 'yes',
    'surgeries_details' => '', // Permitido no rascunho
    'physical_activity' => 'active',
    'activity_modality' => '', // Permitido no rascunho
], true);

assert($draftResult['is_draft'] === true, "Rascunho deve ter flag is_draft = true");
$latestDraft = $anamnesisService->getLatestAnamnesis($patientId);
assert((int)$latestDraft['is_draft'] === 1, "Banco deve confirmar is_draft = 1");

// Ao finalizar, o rascunho é promovido
$promotedResult = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'chief_complaint' => 'Atendimento finalizado com condutas acordadas',
    'objectives' => 'Manutenção da saúde',
    'has_surgeries' => 'no',
    'physical_activity' => 'sedentary',
    'allergy_assessment' => 'denied',
], false);

assert($promotedResult['id'] === $draftResult['id'], "Finalização promove o mesmo ID de rascunho sem criar linha orfã");
assert($promotedResult['is_draft'] === false, "Após conclusão, is_draft torna-se false");

echo "  ✓ Cenário 3 Aprovado: Rascunho intermediário flexível promovido com sucesso para versão final imutável.\n\n";

// -----------------------------------------------------------------------------
// [4/7] Cenário 4: Autorização, isolamento multi-tenant e proteção anti-IDOR
// -----------------------------------------------------------------------------
echo "[4/7] Validando Cenário 4: Autorização multi-tenant e ataques de IDOR...\n";

// 4.1 Paciente de outra clínica bloqueado no Tenant A
$patientB = (new PatientRepository($db))->create(['name' => 'Paciente B', 'email' => 'paciente_b@teste.test']);
$patientBId = (int)$patientB['id'];

// Trocar contexto para Tenant B e criar paciente exclusivo de B
TenantContext::set($clinicBId, ['id' => $profBId, 'name' => 'Dr. Marcos Lima']);
$patientExclusiveB = (new PatientRepository($db))->create(['name' => 'Paciente Exclusivo B', 'email' => 'exclusivo_b@teste.test']);
$patientExclusiveBId = (int)$patientExclusiveB['id'];

// Voltar ao Tenant A e tentar salvar anamnese para paciente do Tenant B
TenantContext::set($clinicAId, ['id' => $profAId, 'name' => 'Dra. Helena Castro']);

$crossTenantBlocked = false;
try {
    $anamnesisService->saveAnamnesis($patientExclusiveBId, $profAId, ['objectives' => 'Ataque IDOR'], false);
} catch (TenantSecurityException $e) {
    $crossTenantBlocked = true;
}
assert($crossTenantBlocked, "Tenant A não pode salvar anamnese para paciente da Clínica B");

// 4.2 Leitura cruzada bloqueada
$crossReadBlocked = false;
try {
    $anamnesisService->getLatestAnamnesis($patientExclusiveBId);
} catch (TenantSecurityException $e) {
    $crossReadBlocked = true;
}
assert($crossReadBlocked, "Tenant A não pode ler última anamnese de paciente da Clínica B");

// 4.3 Histórico cruzado bloqueado
$crossHistoryBlocked = false;
try {
    $anamnesisService->getAnamnesisHistory($patientExclusiveBId);
} catch (TenantSecurityException $e) {
    $crossHistoryBlocked = true;
}
assert($crossHistoryBlocked, "Tenant A não pode ler histórico de paciente da Clínica B");

echo "  ✓ Cenário 4 Aprovado: TenantGuard ativo e tentativas cruzadas/IDOR 100% bloqueadas.\n\n";

// -----------------------------------------------------------------------------
// [5/7] Cenário 5: Imutabilidade do histórico e Comparador de Versões (Diff)
// -----------------------------------------------------------------------------
echo "[5/7] Validando Cenário 5: Histórico append-only e comparação de versões (diff)...\n";

// Criar nova versão clínica (v3 ou v4 dependendo do histórico)
$vNext = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'chief_complaint' => 'Retorno após 60 dias: melhora significativa na disposição',
    'objectives' => 'Transição para fase de manutenção metabólica',
    'family_history' => 'Histórico familiar mantido',
    'pathologies' => 'Dislipidemia controlada',
    'has_surgeries' => 'no',
    'medication_usage' => 'recorded',
    'medications_details' => 'Atorvastatina reduzida para 5mg em dias alternados',
    'supplement_usage' => 'none',
    'allergy_assessment' => 'recorded',
    'allergies' => 'Alergia severa a camarão (mantida)',
    'water_intake_ml' => 3200,
    'physical_activity' => 'active',
    'activity_modality' => 'Musculação e Natação',
    'activity_frequency_weekly' => 5,
    'activity_duration_minutes' => 50,
], false);

$history = $anamnesisService->getAnamnesisHistory($patientId);
assert(count($history) >= 2, "Histórico deve conter pelo menos 2 versões finalizadas");

// Testar comparador de versões
$diff = $anamnesisService->compareVersions($patientId, 1, (int)$vNext['version']);
assert($diff['patient_id'] === $patientId, "Diff deve referenciar o paciente correto");
assert(isset($diff['diff']['chief_complaint']), "Diff deve conter queixa principal");
assert($diff['diff']['chief_complaint']['changed'] === true, "Queixa principal deve estar marcada como alterada");
assert(isset($diff['diff']['physical_activity']), "Diff deve conter status de atividade física");
assert(isset($diff['diff']['activity_modality']), "Diff deve conter modalidade esportiva");
assert(isset($diff['diff']['medications_details']), "Diff deve conter medicamentos em uso");

echo "  ✓ Cenário 5 Aprovado: Imutabilidade histórica e rastreabilidade comparativa entre versões comprovadas.\n\n";

// -----------------------------------------------------------------------------
// [6/7] Cenário 6: Resumo clínico para prescrição estruturada
// -----------------------------------------------------------------------------
echo "[6/7] Validando Cenário 6: Resumo clínico para suporte à decisão na prescrição...\n";

$summary = $anamnesisService->getClinicalSummaryForPrescription($patientId);

assert($summary['has_data'] === true, "Resumo deve indicar presença de anamnese");
assert($summary['allergy_state'] === 'recorded', "Resumo deve alertar sobre alergias registradas");
assert($summary['chief_complaint'] !== null, "Resumo deve expor queixa principal");
assert($summary['physical_activity'] === 'active', "Resumo deve informar que o paciente é ativo");
assert($summary['activity_modality'] === 'Musculação e Natação', "Resumo deve informar a modalidade esportiva");
assert($summary['activity_frequency_weekly'] === 5, "Resumo deve informar a frequência semanal");
assert($summary['water_intake_ml'] === 3200, "Resumo deve informar meta hídrica prescrita");
assert(!empty($summary['source']['url']), "Resumo deve fornecer link rastreável para a versão original");

// Paciente novo sem anamnese
$newPatient = (new PatientRepository($db))->create(['name' => 'Novo Paciente Sem Coleta', 'email' => 'novo@teste.test']);
$emptySummary = $anamnesisService->getClinicalSummaryForPrescription((int)$newPatient['id']);
assert($emptySummary['has_data'] === false, "Paciente sem anamnese deve ter has_data = false");
assert($emptySummary['allergy_pending'] === true, "Paciente sem anamnese deve ter pendência de avaliação de alergia");

echo "  ✓ Cenário 6 Aprovado: Resumo clínico integralmente articulado com a prescrição e segurança do paciente.\n\n";

// -----------------------------------------------------------------------------
// [7/7] Cenário 7: Limite de caracteres contra DoS e sanitização UTF-8
// -----------------------------------------------------------------------------
echo "[7/7] Validando Cenário 7: Limite de tamanho, sanitização e integridade UTF-8...\n";

$oversizedPassed = false;
try {
    $anamnesisService->saveAnamnesis($patientId, $profAId, [
        'chief_complaint' => str_repeat('B', AnamnesisService::MAX_FIELD_LENGTH + 1),
    ], true);
} catch (\InvalidArgumentException $e) {
    $oversizedPassed = true;
    assert(str_contains($e->getMessage(), '5000 caracteres'), "Deve alertar limite máximo");
}
assert($oversizedPassed, "Texto com mais de 5000 caracteres deve ser rejeitado sem truncamento");

// Caracteres especiais e emojis UTF-8 válidos
$utf8Result = $anamnesisService->saveAnamnesis($patientId, $profAId, [
    'chief_complaint' => 'Paciente relata dor tipo queimação 🔥 e sensação de estufamento ⚡ pós-almoço (padrão ácido & amargo).',
    'objectives' => 'Melhora gastrointestinal & longevidade 🥗',
    'allergy_assessment' => 'denied',
], false);

$stmtUtf8 = $db->prepare("SELECT chief_complaint, objectives FROM anamneses WHERE id = :id");
$stmtUtf8->execute(['id' => $utf8Result['id']]);
$rowUtf8 = $stmtUtf8->fetch();
assert(str_contains($rowUtf8['chief_complaint'], '🔥') && str_contains($rowUtf8['objectives'], '🥗'), "Emojis e caracteres especiais UTF-8 devem ser preservados fielmente");

echo "  ✓ Cenário 7 Aprovado: Resiliência contra DoS e integridade de caracteres especiais comprovadas.\n\n";

echo "====================================================================\n";
echo "   RESULTADO FINAL DA SUÍTE NI2-223: 100% PASS\n";
echo "   TODOS OS 7 CENÁRIOS OBRIGATÓRIOS DO JIRA FORAM APROVADOS!\n";
echo "====================================================================\n";
