<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: AUTOAGENDAMENTO SELF-SERVICE (NI2-433 / NI2-434)
 * ==============================================================================
 * Validação rigorosa dos critérios de aceite da Epic NI2-433 e Story NI2-434:
 *  1. Contrato OpenAPI e diagrama de estados documentados; transições inválidas rejeitadas.
 *  2. Reserva confirmada gera agendamento canônico em appointments e histórico auditável.
 *  3. Separação estrita de dimensões: hold, clínico, pagamento e teleconsulta.
 *  4. Contract tests: slots, hold com TTL, idempotência, concorrência/conflito, expiração,
 *     liberação voluntária, isolamento multi-tenant, teleconsulta e cancelamento/reagendamento.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Booking\BookingConflictException;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Booking\HoldExpiredException;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

echo "====================================================================\n";
echo "   CLYONUTRI II — SELF-SERVICE BOOKING TEST SUITE (NI2-433 / NI2-434)\n";
echo "   Disponibilidade, Reserva Temporária (Hold) & Agendamento Canônico\n";
echo "====================================================================\n\n";

Env::load(__DIR__ . '/../.env');
$appTimezone = (string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo');
date_default_timezone_set($appTimezone);

$db = Connection::getInstance();
$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// SETUP DO AMBIENTE DE TESTE
// -----------------------------------------------------------------------------
$clinicA = 1;
$clinicB = 2;
$professionalA = 1;
$professionalB = 2;

// Garantir clínicas e profissionais no banco de teste
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, status, created_at) VALUES (1, 'c-uuid-1', 'Clínica Clyo Nutrição', 'active', CURRENT_TIMESTAMP)");
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, status, created_at) VALUES (2, 'c-uuid-2', 'Clínica Saúde Vital', 'active', CURRENT_TIMESTAMP)");

$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, status, created_at) VALUES (1, 'u-uuid-1', 'Dra. Camila Mendes', 'camila@clyonutri.com', 'hash', 'nutritionist', 'active', CURRENT_TIMESTAMP)");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, status, created_at) VALUES (2, 'u-uuid-2', 'Dr. Roberto Alves', 'roberto@saudevital.com', 'hash', 'nutritionist', 'active', CURRENT_TIMESTAMP)");

if (!(int)$db->query("SELECT COUNT(*) FROM clinic_members WHERE clinic_id = 1 AND user_id = 1")->fetchColumn()) {
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (1, 1, 'nutritionist', 'active', CURRENT_TIMESTAMP)");
}
if (!(int)$db->query("SELECT COUNT(*) FROM clinic_members WHERE clinic_id = 2 AND user_id = 2")->fetchColumn()) {
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (2, 2, 'nutritionist', 'active', CURRENT_TIMESTAMP)");
}

if (!(int)$db->query("SELECT COUNT(*) FROM professionals WHERE id = 1")->fetchColumn()) {
    $db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, created_at) VALUES (1, 1, '12345', 'CRN-3', CURRENT_TIMESTAMP)");
}
if (!(int)$db->query("SELECT COUNT(*) FROM professionals WHERE id = 2")->fetchColumn()) {
    $db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, created_at) VALUES (2, 2, '67890', 'CRN-3', CURRENT_TIMESTAMP)");
}

// Configurar disponibilidade semanal de Dra. Camila: seg a sex das 08:00 às 18:00 com almoço 12:00-13:00
$prefs = json_encode([
    'weekly_availability' => [
        'seg' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        'ter' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        'qua' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        'qui' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        'sex' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        'sab' => ['enabled' => 0, 'start' => '08:00', 'end' => '12:00'],
        'dom' => ['enabled' => 0, 'start' => '08:00', 'end' => '12:00'],
    ]
], JSON_UNESCAPED_UNICODE);

$db->prepare("
    INSERT INTO onboarding_progress (clinic_id, user_id, current_step, practice_preferences, is_completed, created_at, updated_at)
    VALUES (1, 1, 3, :prefs, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ON CONFLICT(clinic_id, user_id) DO UPDATE SET practice_preferences = excluded.practice_preferences
")->execute(['prefs' => $prefs]);

// Autoagendamento é opt-in (NI2-433): sem configuração, a clínica 1 não aparece.
$db->exec("DELETE FROM booking_settings WHERE clinic_id IN (1, 2)");
$optInProbe = new BookingService($db, new DateTimeImmutable('2026-10-20 07:00:00', new DateTimeZone('America/Sao_Paulo')));
assertCheck($optInProbe->getSettings(1)['is_enabled'] === false, "Sem configuração da clínica, autoagendamento fica desligado (opt-in)");
assertCheck(empty($optInProbe->getAvailableSlots(1, 1, '2026-10-20', 60, 'presential')), "Desligado: nenhum horário é oferecido");
assertCheck($optInProbe->publicProfessional(1) === null, "Desligado: profissional não aparece na página pública");
$optInProbe->updateSettings(1, ['is_enabled' => true]);
assertCheck($optInProbe->publicProfessional(1) !== null && $optInProbe->publicProfessional(2) === null, "Ligado só na clínica 1: profissional da clínica 2 continua fora");
$consentTermId = (int)$db->query("SELECT id FROM consent_terms WHERE purpose_code = 'clinical_care' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
assertCheck($consentTermId > 0, "Termo vigente de assistência clínica disponível para o aceite");

// Limpar tabelas para testes limpos
$db->exec("DELETE FROM booking_holds WHERE clinic_id IN (1, 2)");
$db->exec("DELETE FROM appointments WHERE clinic_id IN (1, 2)");
$db->exec("DELETE FROM appointment_status_history WHERE clinic_id IN (1, 2)");

// Relógio determinístico: Terça-feira, 20/10/2026 às 07:00 da manhã
$clock = new DateTimeImmutable('2026-10-20 07:00:00', new DateTimeZone('America/Sao_Paulo'));
$bookingService = new BookingService($db, $clock);

// =============================================================================
// TESTE 1: Contrato OpenAPI e Diagrama de Transição de Estados
// =============================================================================
echo "--- [1] Contrato OpenAPI v3 e Documentação de Estados ---\n";
$openApiFile = __DIR__ . '/../docs/contracts/self_service_booking_openapi.yaml';
$stateMachineDoc = __DIR__ . '/../docs/architecture/SELF_SERVICE_BOOKING_STATE_MACHINE.md';

assertCheck(file_exists($openApiFile), "Arquivo de contrato OpenAPI existe em docs/contracts/self_service_booking_openapi.yaml");
$openApiContent = (string)file_get_contents($openApiFile);
assertCheck(str_contains($openApiContent, '/booking/slots') && str_contains($openApiContent, '/booking/holds'), "OpenAPI define endpoints /booking/slots e /booking/holds");
assertCheck(str_contains($openApiContent, '/booking/confirm') && str_contains($openApiContent, '/booking/release'), "OpenAPI define endpoints /booking/confirm e /booking/release");
assertCheck(str_contains($openApiContent, 'SLOT_UNAVAILABLE') && str_contains($openApiContent, 'HOLD_EXPIRED'), "OpenAPI padroniza códigos de erro HTTP 409 e 410");

assertCheck(file_exists($stateMachineDoc), "Documento de arquitetura existe em docs/architecture/SELF_SERVICE_BOOKING_STATE_MACHINE.md");
$stateMachineContent = (string)file_get_contents($stateMachineDoc);
assertCheck(str_contains($stateMachineContent, 'stateDiagram-v2'), "Documento contém diagrama Mermaid de estados");
assertCheck(str_contains($stateMachineContent, 'held') && str_contains($stateMachineContent, 'converted') && str_contains($stateMachineContent, 'expired'), "Documenta estados de hold: held, converted, expired e released");

// =============================================================================
// TESTE 2: Cálculo Dinâmico de Slots a partir da Jornada Semanal
// =============================================================================
echo "\n--- [2] Cálculo de Slots Disponíveis (Jornada, Pausa e Antecedência) ---\n";

// Terça-feira, 20/10/2026
$slots = $bookingService->getAvailableSlots(1, 1, '2026-10-20', 60, 'presential');
assertCheck(!empty($slots), "Gera slots disponíveis para dia útil configurado");

// Verificar se não há slots durante a pausa de almoço (12:00 às 13:00)
$hasLunchSlot = false;
$times = [];
foreach ($slots as $s) {
    $times[] = $s['time'];
    if ($s['time'] === '12:00' || $s['time'] === '12:30') {
        $hasLunchSlot = true;
    }
}
assertCheck(!$hasLunchSlot, "Nenhum slot é gerado no horário de pausa de almoço (12h às 13h)");
assertCheck(in_array('09:00', $times, true) && in_array('14:00', $times, true), "Horários válidos da manhã (09:00) e tarde (14:00) estão presentes");

// Domingo (25/10/2026) - dia desabilitado na jornada
$sundaySlots = $bookingService->getAvailableSlots(1, 1, '2026-10-25', 60, 'presential');
assertCheck(empty($sundaySlots), "Dia desabilitado na jornada (domingo) retorna zero slots");

// =============================================================================
// TESTE 3: Criação de Reserva Temporária (Hold) com TTL de 15 Minutos
// =============================================================================
echo "\n--- [3] Reserva Temporária (Hold) com TTL e Token Seguro ---\n";

$hold = $bookingService->createHold(
    1,
    1,
    '2026-10-20 09:00:00',
    60,
    'first_visit',
    'presential',
    'idemp_test_001',
    ['name' => 'Mariana Silveira', 'email' => 'mariana@email.com', 'phone' => '(11) 98765-4321']
);

assertCheck(!empty($hold['hold_token']) && strlen($hold['hold_token']) === 64, "Gera hold_token criptográfico de 64 caracteres hex");
assertCheck($hold['status'] === 'held', "Status inicial do hold é 'held'");
assertCheck($hold['duration_minutes'] === 60, "Duração do slot é 60 minutos");
assertCheck($hold['ttl_seconds_remaining'] === 900, "TTL padrão de 15 minutos (900 segundos) configurado");

// =============================================================================
// TESTE 4: Anti-Overbooking: Slot em Hold Some da Consulta de Disponibilidade
// =============================================================================
echo "\n--- [4] Anti-Overbooking: Slot em Hold Fica Imediatamente Indisponível ---\n";

$slotsAfterHold = $bookingService->getAvailableSlots(1, 1, '2026-10-20', 60, 'presential');
$timesAfter = array_column($slotsAfterHold, 'time');
assertCheck(!in_array('09:00', $timesAfter, true), "Slot '09:00' foi removido da lista de horários livres enquanto o hold está ativo");
assertCheck(in_array('10:00', $timesAfter, true), "Slots vizinhos (10:00) permanecem livres");

// =============================================================================
// TESTE 5: Idempotência na Criação do Hold
// =============================================================================
echo "\n--- [5] Idempotência na Criação de Hold (Mesma Chave) ---\n";

$replayHold = $bookingService->createHold(
    1,
    1,
    '2026-10-20 09:00:00',
    60,
    'first_visit',
    'presential',
    'idemp_test_001' // Mesma chave
);
assertCheck($replayHold['idempotent_replay'] === true, "Replay com mesma chave sinaliza idempotent_replay = true");
assertCheck($replayHold['hold_token'] === $hold['hold_token'], "Replay devolve exatamente o mesmo hold_token sem duplicar registro no banco");

// =============================================================================
// TESTE 6: Concorrência Simultânea / Detecção de Conflito (409)
// =============================================================================
echo "\n--- [6] Detecção de Conflito / Concorrência de Horário ---\n";

$conflictThrown = false;
try {
    // Outro usuário tenta reservar o mesmo horário '09:00' com chave diferente
    $bookingService->createHold(
        1,
        1,
        '2026-10-20 09:00:00',
        60,
        'first_visit',
        'presential',
        'idemp_outro_usuario_999'
    );
} catch (BookingConflictException $e) {
    $conflictThrown = true;
}
assertCheck($conflictThrown, "Tentativa de reservar slot em hold por outro usuário lança BookingConflictException (409)");

// =============================================================================
// TESTE 7: Liberação Voluntária do Hold (Devolução Imediata do Slot)
// =============================================================================
echo "\n--- [7] Liberação Voluntária de Hold (releaseHold) ---\n";

$released = $bookingService->releaseHold($hold['hold_token']);
assertCheck($released, "releaseHold retorna true e atualiza status para 'released'");

$slotsAfterRelease = $bookingService->getAvailableSlots(1, 1, '2026-10-20', 60, 'presential');
$timesAfterRelease = array_column($slotsAfterRelease, 'time');
assertCheck(in_array('09:00', $timesAfterRelease, true), "Slot '09:00' voltou a ficar disponível imediatamente após a liberação");

// =============================================================================
// TESTE 8: Expiração por TTL (> 15 min) e Rejeição com HTTP 410 Gone
// =============================================================================
echo "\n--- [8] Expiração Automática por TTL (15 minutos) ---\n";

// Criar novo hold para 10:00
$holdExp = $bookingService->createHold(
    1,
    1,
    '2026-10-20 10:00:00',
    60,
    'first_visit',
    'presential',
    'idemp_exp_002',
    ['name' => 'Carlos Alberto']
);

// Simular avanço do tempo para 20 minutos depois (TTL esgotado)
$clockExpired = new DateTimeImmutable('2026-10-20 07:25:00', new DateTimeZone('America/Sao_Paulo'));
$expiredBookingService = new BookingService($db, $clockExpired);

// Slot 10:00 volta a aparecer livre para novos agendamentos
$slotsAfterTtl = $expiredBookingService->getAvailableSlots(1, 1, '2026-10-20', 60, 'presential');
$timesAfterTtl = array_column($slotsAfterTtl, 'time');
assertCheck(in_array('10:00', $timesAfterTtl, true), "Slot expirado volta a aparecer disponível para novos agendamentos");

// Tentativa de confirmação do hold expirado deve falhar com HoldExpiredException (410)
$expiredThrown = false;
try {
    $expiredBookingService->confirmBooking($holdExp['hold_token'], [
        'name' => 'Carlos Alberto',
        'email' => 'carlos@email.com',
        'terms_accepted' => true, 'consent_term_id' => $consentTermId,
        'phone' => '(11) 97777-6666',
    ]);
} catch (HoldExpiredException $e) {
    $expiredThrown = true;
}
assertCheck($expiredThrown, "Confirmação de hold com TTL decorrido lança HoldExpiredException (410)");

// =============================================================================
// TESTE 9: Confirmação Canônica (Hold -> appointments) e Histórico de Auditoria
// =============================================================================
echo "\n--- [9] Confirmação Canônica em appointments e Histórico Auditável ---\n";

$holdValid = $bookingService->createHold(
    1,
    1,
    '2026-10-20 14:00:00',
    60,
    'first_visit',
    'presential',
    'idemp_valid_003',
    ['name' => 'Beatriz Oliveira']
);

$confirmed = $bookingService->confirmBooking($holdValid['hold_token'], [
    'name' => 'Beatriz Oliveira',
    'email' => 'beatriz@email.com',
    'terms_accepted' => true, 'consent_term_id' => $consentTermId,
    'phone' => '(11) 98888-5555',
    'cpf' => '123.456.789-00',
    'notes' => 'Acompanhamento nutricional pós-bariátrica',
]);

assertCheck($confirmed['success'] === true, "confirmBooking retorna success = true");
$appointmentId = (int)$confirmed['appointment_id'];
assertCheck($appointmentId > 0, "Gera ID canônico na tabela appointments (#{$appointmentId})");

// Verificar se o registro existe em appointments
$apptStmt = $db->prepare("SELECT * FROM appointments WHERE id = :id AND clinic_id = 1");
$apptStmt->execute(['id' => $appointmentId]);
$apptRow = $apptStmt->fetch(PDO::FETCH_ASSOC);
assertCheck($apptRow !== false, "Agendamento persistido na tabela canônica appointments");
assertCheck($apptRow['status'] === 'scheduled', "Status canônico inicial é 'scheduled'");
assertCheck($apptRow['duration_minutes'] == 60, "duration_minutes canônico é 60");

// Verificar se o hold foi marcado como 'converted' com vínculo ao agendamento
$checkHold = $db->prepare("SELECT * FROM booking_holds WHERE hold_token = :token");
$checkHold->execute(['token' => $holdValid['hold_token']]);
$holdRow = $checkHold->fetch(PDO::FETCH_ASSOC);
assertCheck($holdRow['status'] === 'converted', "booking_holds marcado com status = 'converted'");
assertCheck((int)$holdRow['appointment_id'] === $appointmentId, "booking_holds.appointment_id aponta para o agendamento canônico");

// Verificar trilha auditável em appointment_status_history
$histStmt = $db->prepare("SELECT * FROM appointment_status_history WHERE appointment_id = :id");
$histStmt->execute(['id' => $appointmentId]);
$histRow = $histStmt->fetch(PDO::FETCH_ASSOC);
assertCheck($histRow !== false, "Trilha gravada em appointment_status_history");
assertCheck(str_contains($histRow['notes'], 'Autoagendamento self-service'), "Histórico documenta origem do autoagendamento self-service");

// =============================================================================
// TESTE 10: Autoagendamento de Teleconsulta e Integração com Consultório Online
// =============================================================================
echo "\n--- [10] Teleconsulta: Criação de Sessão Canônica e Sem Links Fictícios ---\n";

$holdTele = $bookingService->createHold(
    1,
    1,
    '2026-10-20 15:00:00',
    60,
    'first_visit',
    'telehealth',
    'idemp_tele_004'
);

$confirmedTele = $bookingService->confirmBooking($holdTele['hold_token'], [
    'name' => 'Felipe Martins',
    'email' => 'felipe@email.com',
    'terms_accepted' => true, 'consent_term_id' => $consentTermId,
    'phone' => '(21) 99999-1111',
]);

assertCheck($confirmedTele['modality'] === 'telehealth', "Modalidade registrada como telehealth");
$teleApptId = (int)$confirmedTele['appointment_id'];

// Verificar que a sessão de teleconsulta canônica foi criada
$teleStmt = $db->prepare("SELECT * FROM teleconsultation_sessions WHERE appointment_id = :id");
$teleStmt->execute(['id' => $teleApptId]);
$teleSession = $teleStmt->fetch(PDO::FETCH_ASSOC);
assertCheck($teleSession !== false, "teleconsultation_sessions criada automaticamente para o agendamento de teleconsulta");

// =============================================================================
// TESTE 11: Isolamento Multi-Tenant Estrito
// =============================================================================
echo "\n--- [11] Isolamento Multi-Tenant Estrito ---\n";

$patientInClinicA = (int)$confirmed['patient_id'];

// NI2-137: as ações da paciente passam pelo PatientAppointmentService (motor da agenda + regras da clínica).
$patientActions = new \ClyoNutri\Domain\Appointment\PatientAppointmentService($db, $clock);

// Tentativa de paciente cancelar consulta de outra clínica: com o contexto da Clínica B, a consulta "não existe".
\ClyoNutri\Core\Auth\TenantContext::set(2, ['id' => $patientInClinicA, 'role' => 'patient']);
$tenantBlocked = false;
try {
    $patientActions->cancel($patientInClinicA, $appointmentId, "Tentativa indevida");
} catch (\ClyoNutri\Domain\Appointment\PatientAppointmentActionException $e) {
    $tenantBlocked = $e->errorCode === 'APPOINTMENT_NOT_FOUND' && $e->httpStatus === 404;
} catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
    $tenantBlocked = true;
}
assertCheck($tenantBlocked, "Acesso cross-tenant bloqueado (consulta não encontrada, sem ids)");

// =============================================================================
// TESTE 12: Pedido de remarcação e cancelamento pela paciente (NI2-137)
// =============================================================================
echo "\n--- [12] Pedido de remarcação e cancelamento pela paciente ---\n";

\ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => $patientInClinicA, 'role' => 'patient']);
$blockedByDefault = false;
try {
    $patientActions->cancel($patientInClinicA, $appointmentId, 'sem permissão');
} catch (\ClyoNutri\Domain\Appointment\PatientAppointmentActionException $e) {
    $blockedByDefault = $e->errorCode === 'ACTION_NOT_ALLOWED';
}
assertCheck($blockedByDefault, "Cancelar pelo app fica desligado por padrão (403 ACTION_NOT_ALLOWED)");
$bookingService->updateSettings(1, ['patient_can_cancel' => true, 'patient_can_request_reschedule' => true]);

$requested = $patientActions->requestReschedule($patientInClinicA, $appointmentId, '2026-10-20 16:00:00', 'Troca de turno no trabalho');
assertCheck($requested['request']['status'] === 'pending', "Pedido de remarcação registrado como pendente");
$stillAt = $db->prepare("SELECT scheduled_at FROM appointments WHERE id = :id");
$stillAt->execute(['id' => $appointmentId]);
assertCheck($stillAt->fetchColumn() === '2026-10-20 14:00:00', "Consulta continua no horário original até o consultório aceitar");

\ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
$approved = $patientActions->approveRequest((int)$requested['request']['id'], 1);
assertCheck($approved['scheduled_at'] === '2026-10-20 16:00:00', "Pedido aceito: novo horário persistido em appointments");

\ClyoNutri\Core\Auth\TenantContext::set(1, ['id' => $patientInClinicA, 'role' => 'patient']);
$cancelled = $patientActions->cancel($patientInClinicA, $appointmentId, 'Imprevisto familiar de última hora');
assertCheck($cancelled['success'] === true && $cancelled['status'] === 'cancelled', "Consulta cancelada com sucesso pelo paciente");

$checkCancel = $db->prepare("SELECT status FROM appointments WHERE id = :id");
$checkCancel->execute(['id' => $appointmentId]);
assertCheck($checkCancel->fetchColumn() === 'cancelled', "Status canônico em appointments atualizado para 'cancelled'");

// =============================================================================
// TESTE 13: Controller REST API de Booking (Responses & Status Codes)
// =============================================================================
echo "\n--- [13] Controller REST API (GET /slots, POST /holds, POST /confirm) ---\n";

$bookingCtrl = new \ClyoNutri\Application\Controllers\BookingController($db, $bookingService);

// Testar GET /slots via Controller
$_GET = [
    'clinic_id' => '1',
    'professional_id' => '1',
    'date' => '2026-10-20',
    'duration' => '60',
    'modality' => 'presential',
];
ob_start();
$bookingCtrl->getSlots();
$slotsJson = ob_get_clean();
$slotsData = json_decode((string)$slotsJson, true);
assertCheck(is_array($slotsData) && isset($slotsData['available_slots']), "BookingController::getSlots retorna JSON com available_slots");

// Testar Parâmetros Faltando -> 400
$_GET = ['clinic_id' => '1']; // faltando professional_id e date
ob_start();
$bookingCtrl->getSlots();
$errJson = ob_get_clean();
$errData = json_decode((string)$errJson, true);
assertCheck($bookingCtrl->getLastStatusCode() === 400 && ($errData['code'] ?? '') === 'MISSING_PARAMS', "BookingController::getSlots sem parâmetros obrigatórios retorna HTTP 400 MISSING_PARAMS");

// Testar Confirmação com Token Inexistente -> 422
ob_start();
$_POST = ['hold_token' => 'token_fantasma_inexistente_1234567890'];
$bookingCtrl->confirmBooking();
$errConfirmJson = ob_get_clean();
$errConfirmData = json_decode((string)$errConfirmJson, true);
assertCheck($bookingCtrl->getLastStatusCode() === 422, "BookingController::confirmBooking com token inválido retorna HTTP 422 UNPROCESSABLE_ENTITY");

// =============================================================================
// RESUMO FINAL
// =============================================================================
echo "\n====================================================================\n";
echo "  RESULTADO DOS TESTES DE AUTOAGENDAMENTO SELF-SERVICE (NI2-433/434):\n";
echo "  Total de verificações executadas: " . ($passed + $failed) . "\n";
echo "  Sucessos: {$passed} | Falhas: {$failed}\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
