<?php

declare(strict_types=1);

/**
 * Suíte NI2-880: Diário alimentar do paciente visível para o nutricionista na web,
 * com devolutiva, aviso, auditoria (FOOD_DIARY_VIEWED / FOOD_DIARY_FEEDBACK_SENT),
 * escape HTML, estado vazio e isolamento entre clínicas / RBAC recepção.
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\ClinicalAuthorization;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\FoodDiary\FoodDiaryService;
use ClyoNutri\Domain\Patient\PatientRepository;

/** @var PDO $pdo */
$db = $pdo;

// Seed: 2 clínicas, 1 nutricionista clínica 1, 1 recepcionista clínica 1, 1 nutricionista clínica 2
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica 1', 'c1@teste.local', 'active'), (2, 'c2', 'Clínica 2', 'c2@teste.local', 'active')");
$pwHash = password_hash('SenhaForte@2026', PASSWORD_BCRYPT, ['cost' => 10]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Dra. Helena', 'helena880@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (2, 'u2', 'Recepção', 'recep880@teste.local', ?, 'receptionist', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (3, 'u3', 'Dr. Outro', 'outro880@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active'), (1, 2, 'receptionist', 'active'), (2, 3, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, '12345', 'CRN-3'), (2, 3, '99999', 'CRN-3')");

TenantContext::set(1, ['user_id' => 1, 'clinic_id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Helena']);
$patientRepo = new PatientRepository($db);
$p1 = $patientRepo->create(['name' => 'Mariana Diário', 'email' => 'mari880@teste.local', 'phone' => '11988887777']);
$pid1 = (int)$p1['id'];
$pEmpty = $patientRepo->create(['name' => 'Paciente Sem Diário', 'email' => 'vazio880@teste.local', 'phone' => '11988886666']);
$pidEmpty = (int)$pEmpty['id'];

// Check-in de adesão no período
$db->prepare("INSERT INTO checkins (clinic_id, patient_id, energy_level, hunger_level, meal_adherence_pct, notes, checkin_date) VALUES (1, ?, 4, 3, 85, 'Bom dia', ?)")
    ->execute([$pid1, date('Y-m-d')]);

// 1) Paciente registra refeição com HTML potencialmente perigoso (para testar escape)
$diarySvc = new FoodDiaryService($db);
$logged = $diarySvc->logMeal($pid1, [
    'meal_name' => 'Almoço',
    'consumed_at' => date('Y-m-d H:i:s'),
    'description' => 'Arroz, feijão e <script>alert(1)</script> salada',
    'hunger_level' => 4,
    'satiety_level' => 5,
    'symptoms' => 'Nenhum',
    'idempotency_key' => 'ni2880_k1',
]);
$entryId = (int)$logged['id'];
assert($entryId > 0, 'Registro do diário deve ser criado');

// Aviso em notification_events para o profissional
$notifCount = (int)$db->query("SELECT COUNT(*) FROM notification_events WHERE clinic_id = 1 AND event_type = 'food_diary.entry_created' AND recipient_id = 1")->fetchColumn();
assert($notifCount >= 1, 'Deve gerar aviso em Avisos para o profissional');

// 2) Profissional visualiza o painel web -> registra FOOD_DIARY_VIEWED e renderiza HTML escapado
$diaryPanel = $diarySvc->professionalWebPanel($pid1, 1);
assert($diaryPanel['count'] === 1 && $diaryPanel['pending_count'] === 1, 'Painel deve listar 1 registro pendente');
assert($diaryPanel['avg_adherence_pct'] === 85, 'Adesão média dos check-ins deve ser 85%');

$viewedAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1 AND action = 'FOOD_DIARY_VIEWED' AND user_id = 1")->fetchColumn();
assert($viewedAudit >= 1, 'FOOD_DIARY_VIEWED deve ser gravado em audit_logs');

$pid = $pid1;
$csrfToken = Csrf::token();
ob_start();
require __DIR__ . '/../views/patients/_food_diary_block.php';
$html = ob_get_clean();
assert(str_contains($html, 'Arroz, feijão e &lt;script&gt;alert(1)&lt;/script&gt; salada'), 'Texto com HTML deve ser exibido escapado');
assert(!str_contains($html, '<script>alert(1)</script>'), 'Não pode renderizar tag <script> crua');
assert(str_contains($html, '85%'), 'Resumo deve exibir adesão média');

// 3) Profissional envia devolutiva -> paciente vê em listPatientEntries (GET /api/v1/patient/food-diary)
$updated = $diarySvc->submitProfessionalFeedback($entryId, 'Ótima escolha de almoço!');
assert($updated !== null && $updated['feedback_from_professional'] === 'Ótima escolha de almoço!', 'Devolutiva deve ser gravada');
$diarySvc->auditFeedbackSent(1, 1, $pid1, $entryId, false);

$fbAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1 AND action = 'FOOD_DIARY_FEEDBACK_SENT' AND user_id = 1")->fetchColumn();
assert($fbAudit >= 1, 'FOOD_DIARY_FEEDBACK_SENT deve ser gravado em audit_logs');

$patientView = $diarySvc->listPatientEntries($pid1);
assert(($patientView['entries'][0]['feedback_from_professional'] ?? '') === 'Ótima escolha de almoço!', 'Devolutiva deve aparecer na listagem da API do paciente');

// 4) Estado vazio sem registros
$diaryPanel = $diarySvc->professionalWebPanel($pidEmpty, 1);
$pid = $pidEmpty;
ob_start();
require __DIR__ . '/../views/patients/_food_diary_block.php';
$emptyHtml = ob_get_clean();
assert(str_contains($emptyHtml, 'Sem registros desde '), 'Estado vazio deve exibir Sem registros desde dd/mm');

// 5) RBAC: recepção não pode acessar (403)
assert(ClinicalAuthorization::isClinical(['user_role' => 'receptionist', 'member_role' => 'receptionist']) === false, 'Recepção não é perfil clínico');
$denied = false;
try {
    ClinicalAuthorization::assertWebAccess(['user_role' => 'receptionist', 'member_role' => 'receptionist'], "/pacientes/{$pid1}/diario", 'GET', $db);
} catch (TenantSecurityException $e) {
    $denied = ($e->getCode() === 403);
}
assert($denied, 'Recepção deve receber 403 ao acessar /pacientes/{id}/diario');

// 6) Isolamento entre clínicas: nutricionista da clínica 2 não lê nem envia devolutiva para paciente da clínica 1
TenantContext::set(2, ['user_id' => 3, 'clinic_id' => 2, 'role' => 'nutritionist', 'name' => 'Dr. Outro']);
$crossBlocked = false;
try {
    $diarySvc->professionalWebPanel($pid1, 3);
} catch (TenantSecurityException $e) {
    $crossBlocked = true;
}
assert($crossBlocked, 'Nutricionista de outra clínica deve ser bloqueado ao tentar ler diário');
assert($diarySvc->submitProfessionalFeedback($entryId, 'Tentativa indevida') === null, 'Nutricionista de outra clínica não pode enviar devolutiva');

echo "NI2-880 OK: diário web, devolutiva, aviso, auditoria, escape HTML, estado vazio, RBAC 403 e isolamento multi-tenant validados.\n";
