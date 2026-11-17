<?php

declare(strict_types=1);

/**
 * Suíte de regressão: NI2-879 (data real no prontuário/linha do tempo e escolha ao iniciar fora do dia)
 * + CR360-DB-001/BE-002/PERF-001 (decifragem em getProfile360/search/paginate e agregação de payments).
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\ClinicalRecordContent;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Patient\PatientRepository;

/** @var PDO $pdo */
$db = $pdo;

// Seed básico: clínica, profissional com CRN e paciente
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica 879', 'c879@teste.local', 'active')");
$pwHash = password_hash('SenhaForte@2026', PASSWORD_BCRYPT, ['cost' => 10]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Dra. Helena', 'helena879@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '12345', 'CRN-3')");

TenantContext::set(1, ['user_id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Helena']);

$patientRepo = new PatientRepository($db);
$createdPatient = $patientRepo->create([
    'name' => 'Mariana Souza',
    'email' => 'mariana879@teste.local',
    'phone' => '11999998888',
    'notes' => 'Observação clínica sigilosa',
    'allergies' => 'Amendoim',
]);
$patientId = (int)$createdPatient['id'];

// 1) CR360-DB-001 / BE-002: anamnese + prontuário cifrados devem vir decifrados no getProfile360(), search() e paginate()
$anamnesisSvc = new AnamnesisService($db);
$anamnesisSvc->saveAnamnesis($patientId, 1, ['chief_complaint' => 'Queixa principal cifrada'], false);

$clinicalSvc = new ClinicalRecordService($db);
$clinicalSvc->appendRecord($patientId, 1, 'Evolução inicial', ['evolution_summary' => 'Paciente evoluiu bem']);

$searchRows = $patientRepo->search(1, 'Mariana');
assert(count($searchRows) === 1 && ($searchRows[0]['notes'] ?? '') === 'Observação clínica sigilosa', 'search() deve decifrar notes');

$pageRes = $patientRepo->paginate('Mariana');
assert(count($pageRes['patients']) === 1, 'paginate() deve retornar paciente');

// 2) CR360-PERF-001: agregação de payments em getProfile360()
$db->prepare("INSERT INTO receivables (id, clinic_id, patient_id, description, amount_cents, due_date, status, created_at) VALUES (1, 1, ?, 'Consulta', 20000, '2026-11-20', 'partial', '2026-11-01 10:00:00')")->execute([$patientId]);
$db->exec("INSERT INTO payments (id, clinic_id, receivable_id, amount_cents, payment_method, paid_at, receipt_code) VALUES (1, 1, 1, 8000, 'pix', '2026-11-02 10:00:00', 'REC-1'), (2, 1, 1, 4000, 'pix', '2026-11-03 10:00:00', 'REC-2')");

$profile = $patientRepo->getProfile360($patientId);
assert($profile !== null, 'getProfile360 deve retornar perfil');
assert(($profile['anamneses'][0]['chief_complaint'] ?? '') === 'Queixa principal cifrada', 'getProfile360 deve decifrar anamneses');
assert(is_array($profile['clinical_records'][0]['content']), 'getProfile360 deve decifrar content_json antes do json_decode');
$plainRec = ClinicalRecordContent::plainText($profile['clinical_records'][0]['content'] ?? ($profile['clinical_records'][0]['content_json'] ?? null));
assert(str_contains($plainRec, 'Paciente evoluiu bem') && !str_contains($plainRec, 'enc:v1:'), 'Resumo do prontuário no Perfil 360 não pode exibir enc:v1:');
assert((int)($profile['receivables'][0]['paid_cents'] ?? 0) === 12000, 'paid_cents agregado deve somar 12000');

// 3) NI2-879: Consulta marcada para data futura concluída hoje
$appSvc = new AppointmentService($db);
$futureDate = date('Y-m-d', strtotime('+30 days')) . ' 10:15:00';
$futureApp = $appSvc->schedule($patientId, 1, $futureDate, 'return', 50);
$futureId = (int)$futureApp['id'];
assert($appSvc->isDifferentDay($futureDate) === true, 'Consulta daqui 30 dias deve ser isDifferentDay=true');
assert($appSvc->isDifferentDay(date('Y-m-d H:i:s', time() + 7200)) === false || date('Y-m-d') !== date('Y-m-d', time() + 7200), 'Mesmo dia deve ser isDifferentDay=false');

// 3a) Opção "Criar atendimento de hoje e manter o agendamento futuro"
$todayAppId = $appSvc->createTodayWalkInKeepingFuture($futureId, 1);
$futureStill = $appSvc->getAppointment($futureId);
assert($futureStill['status'] === AppointmentService::STATUS_SCHEDULED, 'Agendamento futuro deve continuar scheduled');

$resToday = $appSvc->completeConsultation($todayAppId, 'Atendimento realizado hoje', [], 1);
$todayDateBr = date('d/m/Y');
$recRow = $db->prepare('SELECT title FROM clinical_records WHERE id = ?');
$recRow->execute([(int)$resToday['clinical_record_id']]);
$recTitle = (string)$recRow->fetchColumn();
assert(str_contains($recTitle, "({$todayDateBr})"), "Título do prontuário deve conter a data de hoje ({$todayDateBr}), obtido: {$recTitle}");
assert(!str_contains($recTitle, date('d/m/Y', strtotime($futureDate))), 'Título do prontuário não pode conter a data futura');

// 3b) Opção "Remarcar esta consulta para agora" + timeline com data real vs agendada
$futureApp2 = $appSvc->schedule($patientId, 1, date('Y-m-d', strtotime('+15 days')) . ' 14:00:00', 'return', 50);
$futureId2 = (int)$futureApp2['id'];
$appSvc->rescheduleForNowAndStart($futureId2, 1);
$afterResched = $appSvc->getAppointment($futureId2);
assert($afterResched['status'] === AppointmentService::STATUS_IN_PROGRESS, 'Consulta remarcada para agora deve ficar in_progress');
assert(substr((string)$afterResched['scheduled_at'], 0, 10) === date('Y-m-d'), 'scheduled_at deve ser atualizado para hoje');
$resResched = $appSvc->completeConsultation($futureId2, 'Concluída após remarcar para hoje', [], 1);
$recRow->execute([(int)$resResched['clinical_record_id']]);
assert(str_contains((string)$recRow->fetchColumn(), "({$todayDateBr})"), 'Prontuário após remarcar deve ter data de hoje');

// 3c) Simula consulta legada com scheduled_at futuro mas concluída hoje -> linha do tempo ordena/exibe pela data real
$db->prepare("UPDATE appointments SET scheduled_at = ? WHERE id = ?")->execute([$futureDate, $todayAppId]);
$profileAfter = $patientRepo->getProfile360($patientId);
$timelineMatch = null;
foreach (($profileAfter['timeline'] ?? []) as $t) {
    if ($t['type'] === 'Consulta' && (int)$t['id'] === $todayAppId) {
        $timelineMatch = $t;
        break;
    }
}
assert($timelineMatch !== null, 'Consulta concluída deve aparecer na linha do tempo');
assert(substr((string)$timelineMatch['date'], 0, 10) === date('Y-m-d'), 'Linha do tempo deve usar data real de conclusão');
assert(str_contains((string)$timelineMatch['title'], 'agendada para ' . date('d/m', strtotime($futureDate))), 'Linha do tempo deve indicar data agendada quando diferente da real');

echo "NI2-879 + CR360 OK: decifragem no Perfil 360, agregação payments e data real no prontuário/linha do tempo validados.\n";
