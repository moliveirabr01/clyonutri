<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * SUÍTE E2E: TRANSCRIÇÃO NUTRICIONAL EM TEMPO REAL & ASSISTENTE CLÍNICO (NI2-820)
 * ==============================================================================
 * Validação rigorosa e reproduzível dos critérios de aceite:
 *  1. Execução de Migration 92 (estruturas relacionais compatíveis com SQLite e MySQL)
 *  2. Consentimento LGPD prévio obrigatório (bloqueio sem autorização, revogação)
 *  3. Sessão em tempo real e alternância dinâmica entre modo ao vivo e discreto
 *  4. Pausa que interrompe captação e retomada resiliente
 *  5. Ingestão sequencial de segmentos com idempotência contra duplicação
 *  6. Recuperação de segmentos acumulados pós-refresh
 *  7. Geração da Minuta Estruturada (8 tópicos) sem alucinações de condutas/prescrições
 *  8. Revisão humana obrigatória e aprovação explícita vinculando ao prontuário
 *  9. Isolamento multi-tenant (Anti-IDOR) entre Clínicas A e B
 * 10. Trilha de auditoria sem vazamento de dados de saúde nos logs
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\EvolutionApprovalService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\NutritionalSummaryGenerator;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\RealtimeTranscriptionService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentService;

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $ev = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
        if ($ev !== '') echo "         Evidência: {$ev}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}\n";
        if ($ev !== '') echo "         Erro: {$ev}\n";
    }
}

echo "===============================================================================\n";
echo "   CLYONUTRI II — SUÍTE E2E: TRANSCRIÇÃO EM TEMPO REAL (NI2-820)\n";
echo "===============================================================================\n\n";

$db = Connection::getInstance();

// -----------------------------------------------------------------------------
// 1. Execução da Migration 92
// -----------------------------------------------------------------------------
echo "--> [1/10] Executando Migration 92 e Validando Esquema Relacional...\n";

require_once __DIR__ . '/../database/migrations/2026_11_11_000092_create_realtime_transcription_tables.php';
$migration = new CreateRealtimeTranscriptionTables();
$migration->up($db);
// NI2-830: o aceite verificável do paciente vive na migration 93
require_once __DIR__ . '/../database/migrations/2026_11_13_000093_create_transcription_consent_requests.php';
(new CreateTranscriptionConsentRequests())->up($db);

$tables = ['consultation_ai_consents', 'consultation_transcription_sessions', 'consultation_transcription_segments', 'consultation_evolution_drafts'];
$allTablesExist = true;
foreach ($tables as $t) {
    $st = $db->query("SELECT 1 FROM {$t} LIMIT 1");
    if (!$st) {
        $allTablesExist = false;
        break;
    }
}
assertTest(
    "Migration 92 aplicada com sucesso (4 tabelas criadas no banco)",
    $allTablesExist,
    "Tabelas: " . implode(', ', $tables)
);

// -----------------------------------------------------------------------------
// Fixture de Teste (Clínica A e Paciente 1 vs Clínica B e Paciente B)
// -----------------------------------------------------------------------------
$clinicA = 1;
$clinicB = 2;
$professionalA = 1;
$professionalB = 2;
$patientA = 3;
$patientB = 208;

// Garante agendamento em andamento na Clínica A
$today = date('Y-m-d');
$db->exec("INSERT INTO appointments 
    (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, created_at, updated_at)
VALUES 
    (1, 3, 1, '{$today} 14:00:00', 45, 'in_progress', 'first_visit', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$apptA = (int)$db->lastInsertId();

// Agendamento na Clínica B
$db->exec("INSERT INTO appointments 
    (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, created_at, updated_at)
VALUES 
    (2, 208, 2, '{$today} 15:00:00', 45, 'in_progress', 'first_visit', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$apptB = (int)$db->lastInsertId();

TenantContext::setTenant($clinicA, ['id' => $professionalA, 'clinic_id' => $clinicA, 'role' => 'owner']);

$consentService = new TranscriptionConsentService($db);
$sttService = new RealtimeTranscriptionService($db, $consentService);
$summaryGenerator = new NutritionalSummaryGenerator();
$approvalService = new EvolutionApprovalService($db);

// -----------------------------------------------------------------------------
// 2. Consentimento LGPD Obrigatório Antes da Captação
// -----------------------------------------------------------------------------
echo "\n--> [2/10] Validando Consentimento LGPD e Bloqueio Prévio à Captação...\n";

assertTest(
    "isConsentActive() retorna false antes do registro do termo",
    $consentService->isConsentActive($apptA, $patientA) === false,
    "Consentimento ausente"
);

$blockedWithoutConsent = false;
try {
    $sttService->startSession($apptA, $patientA, $professionalA, 'live');
} catch (DomainException $e) {
    $blockedWithoutConsent = true;
}
assertTest(
    "startSession() é estritamente BLOQUEADA com DomainException sem consentimento prévio",
    $blockedWithoutConsent,
    "Tentativa de iniciar captação sem autorização é barrada pelo backend"
);

// NI2-830: o profissional só PEDE o aceite; quem decide é o paciente (aqui, pelo mesmo serviço que a página pública usa)
$consentReq = $consentService->createRequest($apptA, $patientA, $professionalA);
assertTest(
    "createRequest() deixa o aceite pendente e NÃO libera a captação",
    ($consentReq['consent']['state'] ?? '') === 'pending' && $consentService->isConsentActive($apptA, $patientA) === false,
    "Pedido pendente aguardando o paciente"
);
$consentData = $consentService->decideByToken((string)$consentReq['token'], 'accept', \ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms::sha256(), '203.0.113.7', 'PHPUnit-like TESTE');
assertTest(
    "decideByToken() registra o aceite do paciente e só então a captação é liberada",
    ($consentData['state'] ?? '') === 'accepted' && $consentService->isConsentActive($apptA, $patientA) === true,
    "Aceite do paciente registrado"
);

// -----------------------------------------------------------------------------
// 3. Ciclo de Vida da Sessão e Alternância de Modo (Ao Vivo / Discreto)
// -----------------------------------------------------------------------------
echo "\n--> [3/10] Validando Início de Sessão e Alternância Dinâmica de Modos...\n";

$session = $sttService->startSession($apptA, $patientA, $professionalA, 'live');
assertTest(
    "startSession() cria sessão com status 'capturing' e modo 'live'",
    ($session['status'] ?? '') === 'capturing' && ($session['mode'] ?? '') === 'live' && !empty($session['session_uuid']),
    "UUID da sessão: {$session['session_uuid']}"
);
$sessionId = (int)$session['id'];

// Alterna para modo discreto (tela oculta, captação ativa)
$sessionDiscrete = $sttService->switchMode($sessionId, 'discrete');
assertTest(
    "switchMode('discrete') altera modo para discreto sem reiniciar a transmissão",
    $sessionDiscrete['mode'] === 'discrete' && $sessionDiscrete['status'] === 'capturing',
    "Modo atual: {$sessionDiscrete['mode']} | Status: {$sessionDiscrete['status']}"
);

// Alterna de volta para modo ao vivo
$sessionLive = $sttService->switchMode($sessionId, 'live');
assertTest(
    "switchMode('live') restabelece modo ao vivo instantaneamente",
    $sessionLive['mode'] === 'live' && $sessionLive['status'] === 'capturing',
    "Modo restaurado para 'live'"
);

// -----------------------------------------------------------------------------
// 4. Pausa e Retomada da Captação
// -----------------------------------------------------------------------------
echo "\n--> [4/10] Validando Pausa e Retomada da Sessão...\n";

$paused = $sttService->pauseSession($sessionId);
assertTest(
    "pauseSession() altera status para 'paused' e registra timestamp",
    $paused['status'] === 'paused' && !empty($paused['paused_at_utc']),
    "Pausa registrada em: {$paused['paused_at_utc']}"
);

// Durante a pausa, envio de novos segmentos deve ser recusado
$blockedDuringPause = false;
try {
    $sttService->ingestSegment($sessionId, 1, "Fala dita durante a pausa");
} catch (DomainException $e) {
    $blockedDuringPause = true;
}
assertTest(
    "ingestSegment() rejeita novos segmentos enquanto a sessão estiver pausada",
    $blockedDuringPause,
    "Áudio e segmentos não são gravados durante a pausa"
);

// Retomada da sessão
$resumed = $sttService->resumeSession($sessionId);
assertTest(
    "resumeSession() restabelece status 'capturing' e timestamp de retomada",
    $resumed['status'] === 'capturing' && !empty($resumed['resumed_at_utc']),
    "Retomada em: {$resumed['resumed_at_utc']}"
);

// -----------------------------------------------------------------------------
// 5. Ingestão Incremental com Idempotência
// -----------------------------------------------------------------------------
echo "\n--> [5/10] Validando Ingestão Incremental de Segmentos com Idempotência...\n";

$speechSegments = [
    1 => "Boa tarde, doutora. Eu procurei a consulta porque sinto muito cansaço e desconforto abdominal após as refeições.",
    2 => "Meu objetivo principal é emagrecer 6 quilos e ter mais disposição para treinar.",
    3 => "No café da manhã eu costumo comer dois pães franceses com margarina e café açucarado. No almoço como arroz, feijão e carne frita.",
    4 => "Eu me pesei hoje de manhã na balança da farmácia e estava com 78 kg e medi a cintura com 86 cm.",
    5 => "Tenho sentido muita azia, refluxo e constipação intestinal frequente nos últimos dois meses.",
    6 => "No plano anterior tive dificuldade de manter a dieta nos finais de semana por causa de ansiedade e vontade de doces.",
    7 => "Combinamos de aumentar a ingestão de água para 2,5 litros por dia e incluir aveia e frutas no café da manhã.",
    8 => "Ficou combinado nosso retorno em quatro semanas para avaliar os resultados e ajustar o plano.",
];

$ingestSuccess = true;
foreach ($speechSegments as $seq => $text) {
    $res = $sttService->ingestSegment($sessionId, $seq, $text, 'speaker_patient');
    if (($res['status'] ?? '') !== 'ingested') {
        $ingestSuccess = false;
        break;
    }
}
assertTest(
    "ingestSegment() grava 8 segmentos sequenciais com status 'ingested'",
    $ingestSuccess,
    "Total de 8 segmentos de fala gravados com sucesso"
);

// Teste de Idempotência: reenvio do segmento 3 (retentativa de rede)
$dupRes = $sttService->ingestSegment($sessionId, 3, $speechSegments[3]);
assertTest(
    "Idempotência: reenvio do segmento #3 é detectado como 'duplicate' sem duplicar no banco",
    ($dupRes['status'] ?? '') === 'duplicate',
    "Resultado: " . ($dupRes['status'] ?? 'falha')
);

// -----------------------------------------------------------------------------
// 6. Resiliência e Recuperação de Segmentos Pós-Refresh
// -----------------------------------------------------------------------------
echo "\n--> [6/10] Validando Recuperação de Segmentos pós-Refresh...\n";

$recovered = $sttService->getSegments($sessionId, 0);
assertTest(
    "getSegments() recupera exatamente os 8 segmentos únicos confirmados",
    count($recovered) === 8 && (int)$recovered[0]['sequence_number'] === 1 && (int)$recovered[7]['sequence_number'] === 8,
    "Recuperados: " . count($recovered) . " segmentos ordenados"
);

$allText = $sttService->getAllSegmentsText($sessionId);
assertTest(
    "getAllSegmentsText() concatena o texto integral preservando o diálogo clínico",
    str_contains($allText, "desconforto abdominal") && str_contains($allText, "78 kg") && str_contains($allText, "quatro semanas"),
    "Tamanho do texto acumulado: " . mb_strlen($allText) . " caracteres"
);

// -----------------------------------------------------------------------------
// 7. Geração de Minuta Estruturada (8 Campos) sem Alucinações
// -----------------------------------------------------------------------------
echo "\n--> [7/10] Validando Geração da Minuta de Evolução Nutricional...\n";

$completedSession = $sttService->completeSession($sessionId);
assertTest(
    "completeSession() finaliza sessão com status 'completed' e duração computada",
    $completedSession['status'] === 'completed' && isset($completedSession['duration_seconds']),
    "Status: completed | Duração: {$completedSession['duration_seconds']}s"
);

$draftFields = $summaryGenerator->generateDraft($allText);

assertTest(
    "Queixa Principal extraída fidedignamente do diálogo",
    str_contains(mb_strtolower($draftFields['chief_complaint']), 'cansaço') || str_contains(mb_strtolower($draftFields['chief_complaint']), 'desconforto'),
    $draftFields['chief_complaint']
);

assertTest(
    "Objetivos Nutricionais extraídos fidedignamente",
    str_contains(mb_strtolower($draftFields['nutritional_goals']), 'emagrecer') || str_contains(mb_strtolower($draftFields['nutritional_goals']), '6 quilos'),
    $draftFields['nutritional_goals']
);

assertTest(
    "Rotina Alimentar extraída fidedignamente",
    str_contains(mb_strtolower($draftFields['dietary_routine']), 'café da manhã') || str_contains(mb_strtolower($draftFields['dietary_routine']), 'almoço'),
    $draftFields['dietary_routine']
);

assertTest(
    "Medidas Antropométricas informadas extraídas fidedignamente",
    str_contains($draftFields['reported_anthropometry'], '78') && str_contains($draftFields['reported_anthropometry'], '86'),
    $draftFields['reported_anthropometry']
);

assertTest(
    "Sintomas Clínicos extraídos fidedignamente",
    str_contains(mb_strtolower($draftFields['clinical_symptoms']), 'azia') || str_contains(mb_strtolower($draftFields['clinical_symptoms']), 'refluxo'),
    $draftFields['clinical_symptoms']
);

assertTest(
    "Minuta gerada NÃO inventa medicamentos, exames laboratoriais ou dietas automáticas",
    !str_contains(mb_strtolower($draftFields['full_evolution_text']), 'omeprazol') &&
    !str_contains(mb_strtolower($draftFields['full_evolution_text']), 'hemograma') &&
    !str_contains(mb_strtolower($draftFields['full_evolution_text']), 'creatina 5g'),
    "Zero alucinações de condutas ou prescrições"
);

// -----------------------------------------------------------------------------
// 8. Revisão Humana Obrigatória e Aprovação Explícita no Prontuário
// -----------------------------------------------------------------------------
echo "\n--> [8/10] Validando Revisão Humana e Aprovação Explícita no Prontuário...\n";

$draftRecord = $approvalService->createOrUpdateDraft($sessionId, $draftFields);
assertTest(
    "createOrUpdateDraft() persiste minuta com status inicial 'draft' (NÃO entra no prontuário)",
    $draftRecord['approval_status'] === 'draft' && !empty($draftRecord['id']),
    "Minuta ID: {$draftRecord['id']} | Status: {$draftRecord['approval_status']}"
);

// Nutricionista edita a conduta e aprova formalmente
$editedEvolution = $draftFields['full_evolution_text'] . "\n\n[Observação Clínica Adicional]: Paciente orientado a mastigar devagar e manter diário alimentar.";
$approvalResult = $approvalService->approveAndIntegrate((int)$draftRecord['id'], $professionalA, $editedEvolution);

assertTest(
    "approveAndIntegrate() aprova minuta com status 'approved' e autor responsável",
    $approvalResult['status'] === 'approved' && (int)$approvalResult['approved_by'] === $professionalA,
    "Aprovado por Professional ID: {$approvalResult['approved_by']}"
);

// Verifica se o texto aprovado foi integrado diretamente em consultation_note_drafts
$draftInDbStmt = $db->prepare("SELECT evolution_summary FROM consultation_note_drafts WHERE appointment_id = :id");
$draftInDbStmt->execute(['id' => $apptA]);
$summaryInProntuario = (string)$draftInDbStmt->fetchColumn();

assertTest(
    "Texto aprovado está 100% integrado ao rascunho oficial da consulta no prontuário",
    str_contains($summaryInProntuario, "EVOLUÇÃO NUTRICIONAL") && str_contains($summaryInProntuario, "Observação Clínica Adicional"),
    "Prontuário atualizado com o texto aprovado pelo nutricionista"
);

// -----------------------------------------------------------------------------
// 9. Segurança Multi-tenant e Prevenção Anti-IDOR (Clínica A vs Clínica B)
// -----------------------------------------------------------------------------
echo "\n--> [9/10] Validando Isolamento Multi-tenant e Proteção Anti-IDOR...\n";

// Muda contexto para Clínica B
TenantContext::setTenant($clinicB, ['id' => $professionalB, 'clinic_id' => $clinicB, 'role' => 'nutritionist']);

$idorSessionBlocked = false;
try {
    // Clínica B tenta acessar os segmentos da sessão da Clínica A
    $sttService->getSegments($sessionId, 0);
} catch (DomainException $e) {
    $idorSessionBlocked = true;
}
assertTest(
    "Clínica B é sumariamente BLOQUEADA ao tentar ler transcrição da Clínica A (IDOR)",
    $idorSessionBlocked,
    "Tentativa de acesso inter-tenant barrada com sucesso"
);

$idorApprovalBlocked = false;
try {
    // Clínica B tenta aprovar a minuta da Clínica A
    $approvalService->approveAndIntegrate((int)$draftRecord['id'], $professionalB, "Injeção não autorizada");
} catch (DomainException $e) {
    $idorApprovalBlocked = true;
}
assertTest(
    "Clínica B é BLOQUEADA ao tentar aprovar minuta pertencente à Clínica A",
    $idorApprovalBlocked,
    "Integridade do prontuário isolada por tenant"
);

// Restaura contexto para Clínica A
TenantContext::setTenant($clinicA, ['id' => $professionalA, 'clinic_id' => $clinicA, 'role' => 'owner']);

// -----------------------------------------------------------------------------
// 10. Revogação de Consentimento e Trilha de Auditoria LGPD
// -----------------------------------------------------------------------------
echo "\n--> [10/10] Validando Revogação de Consentimento e Trilha de Auditoria...\n";

$revoked = $consentService->revokeConsent($apptA, $patientA, $professionalA);
assertTest(
    "revokeConsent() marca status como 'revoked' e data de revogação",
    $revoked && $consentService->isConsentActive($apptA, $patientA) === false,
    "Consentimento revogado com sucesso"
);

// Tentar iniciar nova sessão após revogação é bloqueado
$blockedAfterRevoke = false;
try {
    $sttService->startSession($apptA, $patientA, $professionalA, 'live');
} catch (DomainException $e) {
    $blockedAfterRevoke = true;
}
assertTest(
    "Nova sessão de transcrição é BLOQUEADA após revogação do consentimento",
    $blockedAfterRevoke,
    "Revogação impede qualquer nova captação conforme LGPD"
);

// Trilha de auditoria: confere que os eventos foram gravados em audit_logs sem expor dados clínicos crus
$auditStmt = $db->prepare("SELECT action, resource, resource_id, details FROM audit_logs WHERE resource = 'appointment' AND resource_id = :id ORDER BY id ASC");
$auditStmt->execute(['id' => (string)$apptA]);
$auditEntries = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

$actions = array_column($auditEntries, 'action');
assertTest(
    "AuditLogger registrou eventos clínicos de transcrição e consentimento",
    in_array('AI_TRANSCRIPTION_CONSENT_GRANTED', $actions, true) &&
    in_array('AI_TRANSCRIPTION_SESSION_STARTED', $actions, true) &&
    in_array('AI_TRANSCRIPTION_SESSION_PAUSED', $actions, true) &&
    in_array('AI_TRANSCRIPTION_SESSION_RESUMED', $actions, true) &&
    in_array('AI_TRANSCRIPTION_SESSION_COMPLETED', $actions, true) &&
    in_array('AI_EVOLUTION_APPROVED_AND_INTEGRATED', $actions, true) &&
    in_array('AI_TRANSCRIPTION_CONSENT_REVOKED', $actions, true),
    "Ações auditadas: " . implode(', ', array_unique($actions))
);

$noHealthLeakInAudit = true;
foreach ($auditEntries as $entry) {
    $meta = (string)($entry['details'] ?? '');
    if (str_contains($meta, 'desconforto abdominal') || str_contains($meta, 'refluxo') || str_contains($meta, 'azia')) {
        $noHealthLeakInAudit = false;
        break;
    }
}
assertTest(
    "Trilha de auditoria NÃO vaza texto transcrito ou dados clínicos em metadata de logs",
    $noHealthLeakInAudit,
    "Metadados de auditoria contêm apenas identificadores e contagens estruturadas"
);

echo "\n===============================================================================\n";
echo "RESULTADO DA VALIDAÇÃO E2E DE TRANSCRIÇÃO (NI2-820):\n";
echo "  Total de verificações: " . ($passed + $failed) . " | Passou: {$passed} | Falhou: {$failed}\n";
echo "===============================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] Falha na validação E2E da transcrição em tempo real.\n";
    exit(1);
} else {
    echo "\n[SUCESSO] 100% dos critérios de aceite do NI2-820 comprovados ponta a ponta!\n";
    exit(0);
}
