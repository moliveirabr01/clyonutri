<?php

declare(strict_types=1);

/**
 * Suíte NI2-882 (minuta de transcrição por regras e locutor, zero frases inventadas)
 * e NI2-883 (meta + liberação de autorrelato + compartilhamento de avaliações em 1 ação).
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\NutritionalSummaryGenerator;
use ClyoNutri\Domain\Evolution\PatientEvolutionService;
use ClyoNutri\Domain\Evolution\SelfMetricNotEnabledException;
use ClyoNutri\Domain\Patient\PatientRepository;

/** @var PDO $pdo */
$db = $pdo;

// =========================================================================
// PART 1: NI2-882 — NutritionalSummaryGenerator (10+ diálogos sintéticos)
// =========================================================================
$gen = new NutritionalSummaryGenerator();

// Diálogo 1: sessão vazia
$empty = $gen->generateDraftFromSegments([]);
assert($empty['chief_complaint'] === NutritionalSummaryGenerator::NOT_MENTIONED, 'Sessão vazia deve retornar Não mencionado na consulta');
assert($empty['clinical_symptoms'] === NutritionalSummaryGenerator::NOT_MENTIONED, 'Sintomas vazios não podem ter texto inventado');
assert($empty['agreed_conduct'] === NutritionalSummaryGenerator::NOT_MENTIONED, 'Conduta vazia não pode ter texto inventado');

// Diálogo 2: caso de 3 falas do E2E-IA (pergunta da nutricionista, dor de cabeça do paciente, conduta da nutricionista)
$d2 = $gen->generateDraftFromSegments([
    ['id' => 10, 'sequence_number' => 1, 'speaker' => 'professional', 'text' => 'Você tem sentido dor de cabeça?'],
    ['id' => 11, 'sequence_number' => 2, 'speaker' => 'patient', 'text' => 'Tenho sentido dor de cabeça no fim da tarde.'],
    ['id' => 12, 'sequence_number' => 3, 'speaker' => 'professional', 'text' => 'Vamos ajustar o fracionamento e beber mais água.'],
]);
assert(!str_contains($d2['agreed_conduct'], 'Você tem sentido dor de cabeça?'), 'Pergunta do profissional não pode ir para Conduta');
assert(str_contains($d2['agreed_conduct'], 'Vamos ajustar o fracionamento e beber mais água.'), 'Conduta do profissional deve ir para Conduta');
assert(str_contains($d2['clinical_symptoms'], 'Tenho sentido dor de cabeça no fim da tarde.'), 'Dor de cabeça do paciente deve ir para Sintomas');
assert($d2['nutritional_goals'] === NutritionalSummaryGenerator::NOT_MENTIONED, 'Objetivos não citados deve ser Não mencionado na consulta');
assert(($d2['segment_sources']['clinical_symptoms'][0]['id'] ?? 0) === 11, 'Rastreabilidade deve guardar id do segmento');

// Diálogos 3 a 12 (10 diálogos sintéticos + propriedade: zero frases sem origem)
$syntheticDialogues = [
    [['id' => 1, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'Meu objetivo é ganhar massa muscular.']],
    [['id' => 2, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'No café da manhã como pão com ovo.']],
    [['id' => 3, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'Estou pesando 74 kg na balança de casa.']],
    [['id' => 4, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'Sinto cansaço e azia depois do almoço.']],
    [['id' => 5, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'Tive dificuldade nos finais de semana por ansiedade.']],
    [['id' => 6, 'sequence_number' => 1, 'speaker' => 'professional', 'text' => 'Marcaremos o retorno em 30 dias.']],
    [['id' => 7, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'Viajo a trabalho toda quinta-feira.']], // Outros relatos
    [['id' => 8, 'sequence_number' => 1, 'speaker' => 'professional', 'text' => 'Como está seu intestino?'], ['id' => 9, 'sequence_number' => 2, 'speaker' => 'patient', 'text' => 'Tenho prisão de ventre há meses.']],
    [['id' => 10, 'sequence_number' => 1, 'speaker' => 'patient', 'text' => 'A minha queixa principal é falta de disposição.']],
    [['id' => 11, 'sequence_number' => 1, 'speaker' => 'professional', 'text' => 'Combinamos incluir duas frutas por dia.']],
];

foreach ($syntheticDialogues as $idx => $segs) {
    $out = $gen->generateDraftFromSegments($segs);
    $allSourceText = implode(' ', array_column($segs, 'text'));
    foreach (['chief_complaint', 'nutritional_goals', 'dietary_routine', 'reported_anthropometry', 'clinical_symptoms', 'adherence_notes', 'agreed_conduct', 'next_appointment_notes', 'other_notes'] as $field) {
        $val = (string)($out[$field] ?? '');
        if ($val !== NutritionalSummaryGenerator::NOT_MENTIONED) {
            assert(str_contains($allSourceText, $val), "Diálogo {$idx} campo {$field} contém frase sem origem: {$val}");
        }
    }
}

// =========================================================================
// PART 2: NI2-883 — PatientEvolutionService (meta + permissões + avaliações)
// =========================================================================
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica 883', 'c883@teste.local', 'active'), (2, 'c2', 'Clínica 2', 'c2@teste.local', 'active')");
$pwHash = password_hash('SenhaForte@2026', PASSWORD_BCRYPT, ['cost' => 10]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Dra. Helena', 'helena883@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (2, 'u2', 'Dr. B', 'b883@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active'), (2, 2, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '12345', 'CRN-3'), (2, 2, '54321', 'CRN-3')");

TenantContext::set(1, ['user_id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Helena']);
$repo = new PatientRepository($db);
$p1 = $repo->create(['name' => 'Mariana Evolução', 'email' => 'mari883@teste.local', 'phone' => '11977776666']);
$pid1 = (int)$p1['id'];
$p2 = $repo->create(['name' => 'Mariana Privada', 'email' => 'priv883@teste.local', 'phone' => '11977775555']);
$pid2 = (int)$p2['id'];

$anthro = new AnthropometryService($db);
$anthro->recordAssessment($pid1, 1, ['weight_kg' => '78,6', 'height_cm' => '168', 'notes' => 'Nota interna sigilosa', 'is_shared' => 0]);
$anthro->recordAssessment($pid1, 1, ['weight_kg' => '76,9', 'height_cm' => '168', 'notes' => 'Outra nota interna', 'is_shared' => 0]);

$evo = new PatientEvolutionService($db);

// Estado vazio inicial
$tlBefore = $evo->timeline($pid1);
assert($tlBefore['summary']['has_data'] === false, 'Antes de liberar, has_data deve ser false');
assert($tlBefore['summary']['empty_message'] === 'Seu nutricionista ainda não compartilhou medidas', 'Estado vazio deve ter mensagem clara');

// Criar meta com opções padrão (allow_self_report=true, share_clinical_assessments=true)
$evo->createGoal($pid1, 1, 1, [
    'metric_type' => 'weight_kg',
    'direction' => 'decrease',
    'target_value' => '72',
    'allow_self_report' => true,
    'share_clinical_assessments' => true,
]);

$tlAfter = $evo->timeline($pid1);
assert(count($tlAfter['clinical_records']) === 2, 'Devem aparecer os 2 pontos clínicos (78.6 e 76.9)');
assert(!array_key_exists('notes', $tlAfter['clinical_records'][0]), 'Notas internas não podem vazar na API do paciente');
assert(count($tlAfter['goals']) === 1, 'Meta ativa deve aparecer na timeline');

// Automedida de peso agora é aceita
$self = $evo->recordSelfMetric($pid1, 'weight_kg', '76.5');
assert((float)$self['value'] === 76.5, 'Autorrelato de peso deve funcionar após criar meta com liberação');

// Auditoria PATIENT_EVOLUTION_SHARED
$auditShared = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1 AND action = 'PATIENT_EVOLUTION_SHARED'")->fetchColumn();
assert($auditShared >= 1, 'PATIENT_EVOLUTION_SHARED deve ser gravado em audit_logs');
assert(PatientEvolutionService::hasActiveGoal(1, $pid1, $db) === true, 'hasActiveGoal deve ser true');

// Com opções desmarcadas, mantém comportamento opt-in
$anthro->recordAssessment($pid2, 1, ['weight_kg' => '80,0', 'height_cm' => '170', 'is_shared' => 0]);
$evo->createGoal($pid2, 1, 1, [
    'metric_type' => 'weight_kg',
    'direction' => 'decrease',
    'target_value' => '75',
    'allow_self_report' => false,
    'share_clinical_assessments' => false,
]);
$tl2 = $evo->timeline($pid2);
assert(count($tl2['clinical_records']) === 0, 'Com opção desmarcada, avaliações não devem ser compartilhadas');
$selfBlocked = false;
try {
    $evo->recordSelfMetric($pid2, 'weight_kg', '79.5');
} catch (SelfMetricNotEnabledException $e) {
    $selfBlocked = true;
}
assert($selfBlocked, 'Com opção desmarcada, autorrelato deve continuar bloqueado');

// Isolamento entre clínicas
TenantContext::set(2, ['user_id' => 2, 'clinic_id' => 2, 'role' => 'nutritionist', 'name' => 'Dr. B']);
$isoBlocked = false;
try {
    $evo->releaseAllForPatient($pid1, 2);
} catch (TenantSecurityException $e) {
    $isoBlocked = true;
}
assert($isoBlocked, 'Outra clínica não pode liberar evolução de paciente da clínica 1');

echo "NI2-882 + NI2-883 OK: minuta por locutor sem invenção e evolução unificada validados.\n";
