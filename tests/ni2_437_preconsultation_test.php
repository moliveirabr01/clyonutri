<?php

declare(strict_types=1);

// NI2-437 — Pré-consulta e anamnese adaptativa por objetivo clínico (testes de domínio).
// Três perfis (clínico, esportivo, comportamental), edição/ignorar sugestão, link e identidade,
// rascunho/retomada, envio com consentimento, exposição cruzada, importação e auditoria.
session_start();
ob_start();
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\PreConsultation\PreConsultationException;
use ClyoNutri\Domain\PreConsultation\PreConsultationService;
use ClyoNutri\Domain\PreConsultation\QuestionBank;
use ClyoNutri\Domain\PreConsultation\QuestionnaireSuggester;

$passed = 0;
$failed = 0;
$log = [];
function ok(bool $c, string $m): void
{
    global $passed, $failed, $log;
    $c ? $passed++ : $failed++;
    $log[] = ($c ? '  [PASS] ' : '  [FAIL] ') . $m;
}
function fails(callable $f, string $needle = '', ?int $status = null): bool
{
    try {
        $f();
        return false;
    } catch (PreConsultationException $e) {
        return ($needle === '' || str_contains($e->getMessage(), $needle)) && ($status === null || $e->httpStatus() === $status);
    } catch (Throwable $e) {
        return $needle === '' && $status === null;
    }
}
register_shutdown_function(function () {
    global $passed, $failed, $log;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo "NI2-437 PRÉ-CONSULTA E ANAMNESE ADAPTATIVA\n" . implode("\n", $log) . "\n  PASSOU: {$passed}\n  FALHOU: {$failed}\n";
    if ($failed > 0) {
        exit(1);
    }
});

$db = Connection::getInstance();
(new ClyoNutri\Core\Database\Migrator($db, __DIR__ . '/../database/migrations'))->run();

// ---------- Fixture: duas clínicas, três pacientes (um por perfil) ----------
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (981,'c981','Clínica Pré','981'),(982,'c982','Clínica Vizinha','982')");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, status) VALUES (981,'u981','Dra. Bia','bia981@x','x','nutritionist','active'),(982,'u982','Dr. Caio','caio982@x','x','nutritionist','active')");
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role, status) VALUES (981,981,'nutritionist','active'),(982,982,'nutritionist','active')");
$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (981,981,'CRN-981','CRN-3'),(982,982,'CRN-982','CRN-3')");
$db->exec("DELETE FROM patients WHERE uuid IN ('p981','p982','p983','p984')");
$mk = function (int $clinic, string $uuid, string $name, ?string $birth) use ($db): int {
    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status, birth_date) VALUES (?, ?, ?, ?, 'active', ?)")->execute([$clinic, $uuid, $name, $uuid . '@paciente.test', $birth]);
    return (int)$db->lastInsertId();
};
$pClin = $mk(981, 'p981', 'Clara Glicemia', '1975-04-12');
$pSport = $mk(981, 'p982', 'Rui Corredor', '1994-08-30');
$pBeh = $mk(981, 'p983', 'Bel Comportamento', null);
$pOther = $mk(982, 'p984', 'Outra Clínica', '1980-01-01');
$db->exec("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (981, $pClin, 981, '" . date('Y-m-d H:i:s', time() + 3 * 86400) . "', 60, 'scheduled', 'first_visit')");
$appt = (int)$db->lastInsertId();

TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$svc = new PreConsultationService($db);
$ids = fn(array $q) => array_column($q['questions'], 'id');

// ---------- 1. Três perfis clínicos: perguntas relevantes ----------
$base = array_keys(array_filter(QuestionBank::questions(), fn($q) => in_array('base', $q['tags'], true)));
$qClin = $svc->createProposal($pClin, 981, ['glycemic_control', 'weight_loss'], $appt, false, 981);
$qSport = $svc->createProposal($pSport, 981, ['performance', 'muscle_gain'], null, false, 981);
$qBeh = $svc->createProposal($pBeh, 981, ['food_relationship'], null, false, 981);
foreach ([['clínico', $qClin], ['esportivo', $qSport], ['comportamental', $qBeh]] as [$label, $q]) {
    ok(array_diff($base, $ids($q)) === [], "Perfil {$label}: inclui todas as perguntas gerais");
}
ok(array_intersect(['glucose_monitoring', 'hypoglycemia', 'recent_exams', 'weight_history', 'eat_without_hunger'], $ids($qClin)) === ['glucose_monitoring', 'hypoglycemia', 'recent_exams', 'weight_history', 'eat_without_hunger'] || count(array_intersect(['glucose_monitoring', 'hypoglycemia', 'recent_exams', 'weight_history', 'eat_without_hunger'], $ids($qClin))) === 5, 'Perfil clínico (glicemia + emagrecimento): perguntas de glicemia, exames e histórico de peso');
ok(array_intersect(['sport_modality', 'training_frequency', 'emotional_eating', 'pregnancy_stage'], $ids($qClin)) === [], 'Perfil clínico: sem perguntas de treino, comportamento emocional ou gestação');
ok(count(array_intersect(['sport_modality', 'training_frequency', 'competition', 'peri_workout', 'sport_supplements'], $ids($qSport))) === 5, 'Perfil esportivo: modalidade, frequência, prova, pré/pós-treino e suplementos');
ok(array_intersect(['glucose_monitoring', 'emotional_eating', 'bowel_frequency'], $ids($qSport)) === [], 'Perfil esportivo: sem glicemia, comportamento emocional ou intestino');
ok(count(array_intersect(['eat_without_hunger', 'emotional_eating', 'loss_of_control', 'food_feelings', 'previous_diets'], $ids($qBeh))) === 5, 'Perfil comportamental: fome, emoções, perda de controle, sentimentos e dietas anteriores');
ok(array_intersect(['sport_modality', 'glucose_monitoring', 'salt_use'], $ids($qBeh)) === [], 'Perfil comportamental: sem treino, glicemia ou sal');
$allReasons = implode(' ', array_column($qClin['suggestion'], 'reason'));
ok(str_contains($allReasons, 'Controle glicêmico') && !preg_match('/diagn[oó]stic|risco de|prov[aá]vel/i', $allReasons), 'Sugestão explica o motivo de cada pergunta e não faz diagnóstico');
ok(!preg_match('/diagn[oó]stic|transtorno|você (sofre|provavelmente)|risco de/i', implode(' ', array_column(QuestionBank::questions(), 'text'))), 'Banco de perguntas não sugere diagnóstico ao paciente');
ok(QuestionnaireSuggester::suggest(['glycemic_control']) === QuestionnaireSuggester::suggest(['glycemic_control']), 'Sugestão determinística');
ok(fails(fn() => $svc->createProposal($pClin, 981, [], null, false, 981), 'objetivo'), 'Sem objetivo e sem escolher ignorar: recusado');

// ---------- 2. Editar e ignorar a sugestão ----------
$qBlank = $svc->createProposal($pBeh, 981, ['food_relationship'], null, true, 981);
ok($ids($qBlank) === array_values(array_filter(array_keys(QuestionBank::questions()), fn($id) => in_array($id, $base, true))) && (int)$qBlank['suggestion_ignored'] === 1, 'Ignorar a sugestão: só perguntas gerais (sugestão guardada para comparação)');
$edit = array_values(array_diff($ids($qSport), ['competition', 'water']));
$edit[] = 'salt_use';
$edit = array_values(array_diff($edit, ['has_allergy']));   // a dependente (allergy_detail) continua: has_allergy volta junto
$edited = $svc->updateQuestions((int)$qSport['id'], $edit, ['main_goal', 'sport_modality', 'salt_use']);
$eIds = $ids($edited);
ok(!in_array('competition', $eIds, true) && in_array('salt_use', $eIds, true), 'Editar: retira pergunta sugerida e inclui pergunta fora da sugestão');
ok(in_array('has_allergy', $eIds, true) && array_search('has_allergy', $eIds) < array_search('allergy_detail', $eIds), 'Pergunta condicional traz junto aquela de que depende, na ordem certa');
$req = array_column(array_filter($edited['questions'], fn($q) => $q['required']), 'id');
ok(in_array('salt_use', $req, true) && !in_array('training_frequency', $req, true), 'Editar: obrigatoriedade definida pelo nutricionista');
$auditEdit = $db->query("SELECT details FROM audit_logs WHERE action = 'PRECONSULTATION_EDITED' AND resource_id = '{$qSport['id']}'")->fetchColumn();
ok(str_contains((string)$auditEdit, '"removed_from_suggestion"') && str_contains((string)$auditEdit, 'competition') && str_contains((string)$auditEdit, 'salt_use'), 'Auditoria registra o que foi retirado e incluído em relação à sugestão');

// ---------- 3. Link: só hash no banco, validade pela consulta ----------
$sent = $svc->send((int)$qClin['id']);
$token = $sent['token'];
ok(preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1, 'Token de 256 bits (43 caracteres base64url)');
$rowRaw = $db->query("SELECT * FROM preconsultation_questionnaires WHERE id = {$qClin['id']}")->fetch(PDO::FETCH_ASSOC);
ok($rowRaw['token_hash'] === hash('sha256', $token) && !str_contains(json_encode($rowRaw), $token), 'Banco guarda só o hash do token');
ok(abs(strtotime($rowRaw['token_expires_at']) - (time() + 3 * 86400)) < 120, 'Com consulta vinculada, o link expira no horário da consulta');
ok(!str_contains((string)$db->query("SELECT GROUP_CONCAT(details) FROM audit_logs")->fetchColumn(), $token), 'Token não aparece na auditoria');
ok(fails(fn() => $svc->updateQuestions((int)$qClin['id'], ['main_goal'], []), 'Depois de enviado'), 'Questionário enviado não pode ser editado');

// ---------- 4. Paciente: acesso, identidade, rascunho e retomada ----------
TenantContext::clear();
ok(fails(fn() => $svc->redeem('x'), '', 404) && fails(fn() => $svc->redeem(str_repeat('A', 43)), '', 404), 'Token inválido ou inexistente: mesma resposta 404 (não revela nada)');
$row = $svc->redeem($token);
ok((int)$row['id'] === (int)$qClin['id'] && $svc->needsBirthDate($row), 'Link válido abre o questionário e pede data de nascimento');
for ($i = 1; $i <= 4; $i++) {
    $svc->verifyBirthDate($svc->forPatient((int)$row['id'], $row['token_hash']), '01/01/2000');
}
ok(fails(fn() => $svc->verifyBirthDate($svc->forPatient((int)$row['id'], $row['token_hash']), '01/01/2000'), 'Muitas tentativas', 429), '5ª data errada bloqueia o link por 30 minutos');
ok(fails(fn() => $svc->verifyBirthDate($svc->forPatient((int)$row['id'], $row['token_hash']), '12/04/1975'), 'Muitas tentativas', 429), 'Durante o bloqueio, nem a data certa passa');
$db->exec("UPDATE preconsultation_questionnaires SET locked_until = NULL WHERE id = {$qClin['id']}");
ok($svc->verifyBirthDate($svc->forPatient((int)$row['id'], $row['token_hash']), '12/04/1975') === true, 'Data correta (dd/mm/aaaa) confirma a identidade');

$row = $svc->forPatient((int)$qClin['id'], $row['token_hash']);
$draft = $svc->saveDraft($row, ['main_goal' => 'Controlar a glicemia e perder 5 kg', 'glucose_monitoring' => 'diario', 'has_allergy' => 'xxx', 'meals_per_day' => '4']);
ok(($draft['glucose_monitoring'] ?? null) === 'diario' && !isset($draft['has_allergy']) && $draft['meals_per_day'] === 4, 'Rascunho guarda respostas parciais válidas e descarta opção inexistente');
$resumed = $svc->patientContext($svc->forPatient((int)$qClin['id'], $row['token_hash']));
ok($resumed['status'] === 'in_progress' && $resumed['answers']['main_goal'] === 'Controlar a glicemia e perder 5 kg', 'Retomada: respostas salvas voltam ao reabrir o link');

// ---------- 5. Envio: obrigatórias, condicionais e consentimento ----------
$answers = [
    'main_goal' => 'Controlar a glicemia e perder 5 kg', 'has_allergy' => 'sim', 'health_conditions' => ['diabetes', 'hipertensao'],
    'uses_medication' => 'sim', 'medication_detail' => 'Metformina 850 mg', 'meals_per_day' => '4', 'physical_activity' => 'nao',
    'glucose_monitoring' => 'diario', 'hypoglycemia' => 'nao', 'eat_without_hunger' => 'as_vezes', 'pregnancy_weeks' => '10',
];
$r = $svc->submit($svc->forPatient((int)$qClin['id'], $row['token_hash']), $answers, true, '203.0.113.5', 'Teste');
ok(isset($r['errors']['allergy_detail']) && !isset($r['errors']['medication_detail']), 'Condicional obrigatória: "sim" para alergia exige detalhar');
$answers['allergy_detail'] = 'Camarão: urticária';
$r = $svc->submit($svc->forPatient((int)$qClin['id'], $row['token_hash']), $answers, false, '203.0.113.5', 'Teste');
ok(isset($r['errors']['_consent']), 'Sem consentimento: envio recusado (respostas mantidas em rascunho)');
$r = $svc->submit($svc->forPatient((int)$qClin['id'], $row['token_hash']), $answers, true, '203.0.113.5', 'Teste');
ok($r['errors'] === [] && !isset($r['answers']['pregnancy_weeks']), 'Envio aceito; resposta a pergunta fora do questionário é descartada');
$stored = $db->query("SELECT * FROM preconsultation_questionnaires WHERE id = {$qClin['id']}")->fetch(PDO::FETCH_ASSOC);
$consent = $db->query("SELECT * FROM patient_consents WHERE id = " . (int)$stored['consent_id'])->fetch(PDO::FETCH_ASSOC);
ok($stored['status'] === 'submitted' && $consent && $consent['consent_type'] === 'pre_consultation' && (int)$consent['patient_id'] === $pClin && $consent['ip_address'] === '203.0.113.5', 'Consentimento registrado com termo, paciente e IP');
$snaps = $db->query("SELECT kind, answers_sha256, answers_json FROM preconsultation_answer_snapshots WHERE questionnaire_id = {$qClin['id']} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok(count($snaps) >= 3 && end($snaps)['kind'] === 'submitted' && end($snaps)['answers_sha256'] === hash('sha256', end($snaps)['answers_json']), 'Cada salvamento gravado (append-only) com SHA-256 conferível');
ok(fails(fn() => $svc->saveDraft($svc->forPatient((int)$qClin['id'], $row['token_hash'], true), ['main_goal' => 'mudar']), 'já foi enviado', 409), 'Depois do envio, respostas não mudam');
ok($svc->forPatient((int)$qClin['id'], $row['token_hash'], true)['status'] === 'submitted', 'Link ainda abre a página de agradecimento');

// Perfil esportivo (sem data de nascimento cadastrada em outro paciente: comportamental)
TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$tBeh = $svc->send((int)$qBeh['id'])['token'];
TenantContext::clear();
$rowBeh = $svc->redeem($tBeh);
ok(!$svc->needsBirthDate($rowBeh), 'Paciente sem data de nascimento: link abre direto (documentado)');
$rBeh = $svc->submit($rowBeh, ['main_goal' => 'Comer com menos culpa', 'has_allergy' => 'nao', 'health_conditions' => ['nenhuma'], 'uses_medication' => 'nao',
    'meals_per_day' => '3', 'physical_activity' => '1_2', 'eat_without_hunger' => 'frequentemente', 'emotional_eating' => 'quase_sempre'], true, null, null);
ok($rBeh['errors'] === [], 'Perfil comportamental respondido e enviado');
ok(fails(fn() => $svc->submit($svc->forPatient((int)$qBeh['id'], $rowBeh['token_hash'], true), ['main_goal' => 'x'], true, null, null), '', 409), 'Reenvio do mesmo questionário recusado');
$rMulti = (new ReflectionMethod(PreConsultationService::class, 'normalize'));
$rMulti->setAccessible(true);
ok($rMulti->invoke(null, QuestionBank::question('health_conditions'), ['nenhuma', 'diabetes'])[1] !== null, '"Nenhuma" junto com outra condição é recusado');

// ---------- 6. Exposição cruzada ----------
TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$tSport = $svc->send((int)$qSport['id'])['token'];
TenantContext::clear();
$rowSport = $svc->redeem($tSport);
ok(fails(fn() => $svc->forPatient((int)$qClin['id'], $rowSport['token_hash']), '', 404), 'Token do paciente B não abre o questionário do paciente A');
$ctxSport = $svc->patientContext($rowSport);
ok($ctxSport['first_name'] === 'Rui' && !str_contains(json_encode($ctxSport), 'Clara') && !str_contains(json_encode($ctxSport), 'Metformina'), 'Contexto do paciente traz só os próprios dados');
TenantContext::set(982, ['id' => 982, 'role' => 'nutritionist', 'name' => 'Dr. Caio']);
ok(fails(fn() => $svc->get((int)$qClin['id']), '', 404), 'Profissional de outra clínica não acessa a pré-consulta (404)');
ok(fails(fn() => $svc->listForPatient($pClin)), 'Profissional de outra clínica não lista pré-consultas do paciente');
ok(fails(fn() => $svc->createProposal($pClin, 982, ['performance'], null, false, 982)), 'Não cria pré-consulta para paciente de outra clínica');
ok(fails(fn() => $svc->send((int)$qSport['id']), '', 404), 'Não reenvia link de outra clínica');

// Reenvio invalida o link antigo; revogação e expiração
TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$tSport2 = $svc->send((int)$qSport['id'])['token'];
TenantContext::clear();
ok(fails(fn() => $svc->redeem($tSport), '', 404) && (int)$svc->redeem($tSport2)['id'] === (int)$qSport['id'], 'Novo link invalida o anterior');
TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$svc->revoke((int)$qSport['id']);
TenantContext::clear();
ok(fails(fn() => $svc->redeem($tSport2), '', 404), 'Link revogado não abre');
TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$qExp = $svc->createProposal($pSport, 981, ['performance'], null, false, 981);
$tExp = $svc->send((int)$qExp['id'])['token'];
$db->exec("UPDATE preconsultation_questionnaires SET token_expires_at = '2020-01-01 00:00:00' WHERE id = {$qExp['id']}");
ok(fails(fn() => $svc->redeem($tExp), 'expirou', 410), 'Link expirado não abre');
ok($svc->get((int)$qExp['id'])['is_expired'] === true, 'Nutricionista vê o link como expirado');

// ---------- 7. Revisão e importação para a anamnese ----------
TenantContext::set(981, ['id' => 981, 'role' => 'nutritionist', 'name' => 'Dra. Bia']);
$svc->markReviewed((int)$qClin['id'], 981, 'Conferir exames na consulta.');
$db->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, objectives, is_draft) VALUES (981, ?, 981, 1, 'Anotação prévia da nutricionista', 1)")->execute([$pClin]);
$imp = $svc->importToAnamnesis((int)$qClin['id'], 981);
$an = $db->query("SELECT * FROM anamneses WHERE id = " . (int)$imp['anamnesis_id'])->fetch(PDO::FETCH_ASSOC);
ok((int)$an['is_draft'] === 1 && str_contains($an['objectives'], 'Anotação prévia da nutricionista') && str_contains($an['objectives'], 'Pré-consulta de'), 'Importação complementa o rascunho existente, identificada como relato da pré-consulta');
ok(str_contains($an['allergies'], 'Camarão') && $an['allergy_assessment'] === 'recorded' && str_contains($an['clinical_history'], 'Metformina'), 'Alergia, medicamentos e condições vão para os campos certos da anamnese');
ok(fails(fn() => $svc->importToAnamnesis((int)$qClin['id'], 981), 'já foram importadas'), 'Importação não se repete');
$impBeh = $svc->importToAnamnesis((int)$qBeh['id'], 981);
$anBeh = $db->query("SELECT allergies, allergy_assessment FROM anamneses WHERE id = " . (int)$impBeh['anamnesis_id'])->fetch(PDO::FETCH_ASSOC);
ok(trim((string)$anBeh['allergies']) === '' && $anBeh['allergy_assessment'] === 'denied', 'Paciente que nega alergia: rascunho com negativa e sem texto em alergias');
ok(fails(fn() => $svc->importToAnamnesis((int)$qExp['id'], 981), 'depois que o paciente enviar'), 'Não importa questionário sem respostas');

// ---------- 8. Auditoria ----------
$actions = $db->query("SELECT action FROM audit_logs WHERE resource = 'preconsultation_questionnaires' AND resource_id = '{$qClin['id']}' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach (['PRECONSULTATION_CREATED', 'PRECONSULTATION_LINK_ISSUED', 'PRECONSULTATION_OPENED', 'PRECONSULTATION_IDENTITY_FAILED', 'PRECONSULTATION_IDENTITY_CONFIRMED',
    'PRECONSULTATION_DRAFT_SAVED', 'PRECONSULTATION_SUBMITTED', 'PRECONSULTATION_REVIEWED', 'PRECONSULTATION_IMPORTED'] as $a) {
    ok(in_array($a, $actions, true), "Auditoria: {$a}");
}
$auditText = (string)$db->query("SELECT GROUP_CONCAT(details, ' ') FROM audit_logs WHERE resource = 'preconsultation_questionnaires'")->fetchColumn();
ok(!str_contains($auditText, 'Metformina') && !str_contains($auditText, 'Camarão') && !str_contains($auditText, '1975'), 'Auditoria não copia respostas nem a data de nascimento');
$clinicOfPatientEvents = $db->query("SELECT DISTINCT clinic_id FROM audit_logs WHERE action = 'PRECONSULTATION_SUBMITTED'")->fetchAll(PDO::FETCH_COLUMN);
ok($clinicOfPatientEvents == [981], 'Eventos do paciente ficam na clínica do questionário');
