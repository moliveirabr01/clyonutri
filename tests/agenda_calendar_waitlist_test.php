<?php

declare(strict_types=1);

/**
 * Suíte de Testes Automatizada:
 * Calendário de Atendimento, Feriados Parciais/Totais, Exceções, Buffers e Lista de Espera
 * Validação de Produção dos Fluxos da Agenda e Autoagendamento (v50 Feriados & Fluxo)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Appointment\AppointmentScheduleInput;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Booking\BookingCalendarService;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Booking\BookingWaitlistService;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

(new Migrator($db, __DIR__ . '/../database/migrations'))->run();

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "====================================================================\n";
echo "   CLYONUTRI II — SUÍTE DE TESTES: CALENDÁRIO, FERIADOS E LISTA DE ESPERA\n";
echo "====================================================================\n\n";

// Setup Base: Clínicas 1 e 2
$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (1, 'c-1', 'Clínica Central', 'active')");
$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (2, 'c-2', 'Clínica Secundária', 'active')");

$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status, is_verified) VALUES
    (1, 'u-1', 'Dra. Gestora', 'gestora@teste.com', 'x', 'nutritionist', 'active', 1),
    (2, 'u-2', 'Dr. Nutricionista', 'nutri@teste.com', 'x', 'nutritionist', 'active', 1),
    (3, 'u-3', 'Paciente Ana', 'ana@paciente.com', 'x', 'patient', 'active', 1)");

$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES 
    (1, 1, 'owner', 'active'),
    (1, 2, 'nutritionist', 'active')");

$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES 
    (10, 1, '11111', 'CRN-3', 'Clínica Geral'),
    (20, 2, '22222', 'CRN-3', 'Esportiva')");

$db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, phone, status) VALUES 
    (100, 1, 'p-100', 'Ana Silva', 'ana@paciente.com', '11988887777', 'active')");

$avail = [
    'seg' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
    'ter' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
    'qua' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00'],
    'qui' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00'],
    'sex' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00'],
];
$prefJson = json_encode(['weekly_availability' => $avail]);
$db->exec("INSERT INTO onboarding_progress (user_id, clinic_id, current_step, practice_preferences, created_at, updated_at) VALUES 
    (1, 1, 4, '{$prefJson}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (2, 1, 4, '{$prefJson}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

$db->exec("INSERT INTO booking_settings (clinic_id, is_enabled, min_advance_hours, max_future_days) VALUES 
    (1, 1, 2, 30)");

$calendar = new BookingCalendarService($db);
TenantContext::set(1, ['id' => 1, 'role' => 'owner', 'name' => 'Dra. Gestora']);

// --- [1] AppointmentScheduleInput: Campos separados e robustez ---
echo "--- [1] AppointmentScheduleInput: Campos Separados e Formatos ---\n";
$inputNorm = AppointmentScheduleInput::fromArray(['scheduled_date' => '2026-11-05', 'scheduled_time' => '14:30']);
check($inputNorm === '2026-11-05T14:30', 'Combina scheduled_date e scheduled_time em formato ISO Y-m-d\TH:i');

$inputMidnight = AppointmentScheduleInput::fromArray(['scheduled_date' => '2026-11-05', 'scheduled_time' => '00:00']);
check($inputMidnight === '2026-11-05T00:00', 'Aceita meia-noite 00:00 como horário explícito válido');

$inputLegacy = AppointmentScheduleInput::fromArray(['scheduled_at' => '2026-11-05 10:00']);
check($inputLegacy === '2026-11-05 10:00', 'Mantém compatibilidade com formato legado scheduled_at');

$recDate = AppointmentScheduleInput::recoveryDate(['scheduled_date' => '2026-11-10', 'scheduled_time' => 'invalido']);
check($recDate === '2026-11-10', 'recoveryDate preserva a data para reabrir o formulário após erro');

$invalidRejected = false;
try {
    AppointmentScheduleInput::fromArray(['scheduled_date' => '2026-11-05', 'scheduled_time' => '25:99']);
} catch (\InvalidArgumentException $e) {
    $invalidRejected = true;
}
check($invalidRejected, 'Rejeita horário inválido com InvalidArgumentException');

// --- [2] Feriados e Bloqueios Parciais e Totais ---
echo "\n--- [2] Feriados e Bloqueios: Regras e Disponibilidade ---\n";

// 2.1 Cadastra feriado parcial (manhã: 08:00 às 12:00) para toda a clínica em 2026-11-04 (quarta)
$ruleId = $calendar->addRule(1, null, [
    'rule_date' => '2026-11-04',
    'kind' => 'holiday',
    'period' => 'partial',
    'start_time' => '08:00',
    'end_time' => '12:00',
    'label' => 'Feriado municipal',
]);
check($ruleId > 0, 'Cadastra feriado parcial na clínica com sucesso');

// 2.2 Verificação de bloqueio dentro do período do feriado
$reasonMorning = $calendar->blockingReason(1, 10, '2026-11-04 09:00:00', 60);
check($reasonMorning !== null && str_contains($reasonMorning, 'Feriado municipal'), 'Horário dentro do feriado parcial é bloqueado identificando o feriado');

// 2.3 Horário à tarde após o feriado parcial deve ser permitido
$allowsAfternoon = $calendar->allows(1, 10, '2026-11-04 14:00:00', 60, $avail);
check($allowsAfternoon === true, 'Horário após o feriado parcial (14:00) é permitido');

// 2.4 Edição da regra para alterar a data e o horário
$calendar->updateRule(1, $ruleId, null, [
    'rule_date' => '2026-11-05',
    'kind' => 'holiday',
    'period' => 'partial',
    'start_time' => '14:00',
    'end_time' => '18:00',
    'label' => 'Feriado estadual',
]);
$allowsOldDateMorning = $calendar->allows(1, 10, '2026-11-04 09:00:00', 60, $avail);
$reasonNewDateTarde = $calendar->blockingReason(1, 10, '2026-11-05 15:00:00', 60);
check($allowsOldDateMorning === true, 'Data anterior (04/11) volta a ficar disponível imediatamente após a edição');
check($reasonNewDateTarde !== null && str_contains($reasonNewDateTarde, 'Feriado estadual'), 'Nova data (05/11) passa a ser bloqueada no novo período');

// 2.5 Alternância para dia inteiro limpa os horários
$calendar->updateRule(1, $ruleId, null, [
    'rule_date' => '2026-11-05',
    'kind' => 'holiday',
    'period' => 'full',
    'label' => 'Feriado nacional',
]);
$storedRule = $db->query("SELECT * FROM booking_calendar_exceptions WHERE id = {$ruleId}")->fetch(PDO::FETCH_ASSOC);
check($storedRule['start_time'] === null && $storedRule['end_time'] === null, 'Alternância para dia inteiro limpa start_time e end_time no banco');

// 2.6 Multi-tenant: Clínica 2 não é afetada pelo feriado da Clínica 1
$clinic2Allows = $calendar->allows(2, 20, '2026-11-05 10:00:00', 60, $avail);
check($clinic2Allows === true, 'Isolamento multi-tenant: feriado da Clínica 1 não afeta Clínica 2');

// --- [3] Autorização e Políticas de Calendário ---
echo "\n--- [3] Autorização e Políticas de Calendário ---\n";
// Nutricionista comum tenta editar feriado da clínica (deve falhar se passar ownProfessional = 20)
$managerBlocked = false;
try {
    $calendar->updateRule(1, $ruleId, null, ['rule_date' => '2026-11-05', 'kind' => 'holiday', 'period' => 'full', 'label' => 'Feriado nacional'], 20);
} catch (\InvalidArgumentException $e) {
    $managerBlocked = str_contains($e->getMessage(), 'permissão');
}
check($managerBlocked, 'Nutricionista comum é impedido de editar regra geral da clínica');

// Nutricionista cria exceção para a própria agenda (kind = 'closed')
$ownRuleId = $calendar->addRule(1, 20, [
    'rule_date' => '2026-11-06',
    'kind' => 'closed',
    'period' => 'partial',
    'start_time' => '10:00',
    'end_time' => '12:00',
    'label' => 'Compromisso Externo',
]);
check($ownRuleId > 0, 'Nutricionista consegue criar bloqueio para sua própria agenda');

// Nutricionista pode editar sua própria exceção
$calendar->updateRule(1, $ownRuleId, 20, [
    'rule_date' => '2026-11-06',
    'kind' => 'closed',
    'period' => 'partial',
    'start_time' => '11:00',
    'end_time' => '12:00',
    'label' => 'Compromisso Ajustado',
], 20);
$updatedOwn = $db->query("SELECT * FROM booking_calendar_exceptions WHERE id = {$ownRuleId}")->fetch(PDO::FETCH_ASSOC);
check($updatedOwn['start_time'] === '11:00', 'Nutricionista edita com sucesso sua própria exceção');

// --- [4] Buffers entre Consultas (Intervalos de Preparo) ---
echo "\n--- [4] Buffers entre Consultas ---\n";
$calendar->savePolicy(1, [
    'buffer_before_minutes' => 15,
    'buffer_after_minutes' => 15,
    'waitlist_enabled' => 1,
    'offer_ttl_minutes' => 30,
]);
$pol = $calendar->policy(1);
check((int)$pol['buffer_before_minutes'] === 15 && (int)$pol['buffer_after_minutes'] === 15, 'Configura buffers de 15 min antes e depois das consultas');

// Profissional atende das 08:00 às 18:00 na sexta 06/11
$allows0800 = $calendar->allows(1, 10, '2026-11-06 08:00:00', 60, $avail);
$allows0815 = $calendar->allows(1, 10, '2026-11-06 08:15:00', 60, $avail);
check($allows0800 === false, 'Buffer_before de 15 min impede início colado às 08:00 sem janela de preparo');
check($allows0815 === true, 'Com início às 08:15 (preparo 08:00-08:15, término 09:15, resguardo 09:15-09:30), cabe na jornada');

$overlaps = $calendar->overlaps(1, '2026-11-06 10:00:00', 60, '2026-11-06 11:15:00', 60, $pol);
check($overlaps === true, 'Intervalo posterior (15m) + anterior (15m) detecta colisão de agendamentos próximos');

// --- [5] Lista de Espera (BookingWaitlistService) ---
echo "\n--- [5] Lista de Espera (BookingWaitlistService) ---\n";
TenantContext::set(1, ['id' => 100, 'role' => 'patient', 'name' => 'Ana Silva']);
$fixedNow = new DateTimeImmutable('2026-11-01 10:00:00', BookingCalendarService::tz());
$waitlistSvc = new BookingWaitlistService($db, $fixedNow);

// 5.1 Paciente autenticado entra na lista de espera
$wlEntry = $waitlistSvc->join([
    'professional_id' => 10,
    'date_from' => '2026-11-09',
    'date_to' => '2026-11-13',
    'time_from' => '09:00',
    'time_to' => '12:00',
    'modality' => 'presential',
    'service_type' => 'first_visit',
]);
check((int)$wlEntry['id'] > 0 && $wlEntry['status'] === 'waiting', 'Paciente autenticado entra na lista de espera com status waiting');

// 5.2 Tentativa de entrar duplicado na mesma janela retorna duplicate = true
$dupRes = $waitlistSvc->join([
    'professional_id' => 10,
    'date_from' => '2026-11-09',
    'date_to' => '2026-11-13',
    'time_from' => '09:00',
    'time_to' => '12:00',
    'modality' => 'presential',
    'service_type' => 'first_visit',
]);
check($dupRes['duplicate'] === true, 'Retorna duplicate=true para mesma janela sem duplicar registro no banco');

// 5.3 Consulta meus pedidos na lista
$mine = $waitlistSvc->mine();
check(count($mine) === 1 && (int)$mine[0]['id'] === (int)$wlEntry['id'], 'mine() retorna a solicitação ativa do paciente');

// 5.4 Paciente cancela pedido na lista
$waitlistSvc->cancel((int)$wlEntry['id']);
$afterCancel = $waitlistSvc->mine();
check(count($afterCancel) === 0, 'Paciente cancela com sucesso seu pedido na lista de espera');

// 5.5 Processamento da Fila (Cron): Conversão de waiting em offer
$wlOfferTest = $waitlistSvc->join([
    'professional_id' => 10,
    'date_from' => '2026-11-09',
    'date_to' => '2026-11-09',
    'time_from' => '09:00',
    'time_to' => '12:00',
    'modality' => 'presential',
    'service_type' => 'first_visit',
]);
check((int)$wlOfferTest['id'] > 0, 'Novo pedido cadastrado para teste de oferta agendada');

$procReport = $waitlistSvc->process();
check(($procReport['offered'] ?? 0) === 1, 'Cron BookingWaitlistService::process() encontra vaga e gera oferta (status offered)');

$offeredItem = $db->query("SELECT status, hold_id FROM booking_waitlist WHERE id = {$wlOfferTest['id']}")->fetch(PDO::FETCH_ASSOC);
check($offeredItem['status'] === 'offered' && (int)$offeredItem['hold_id'] > 0, 'Registro avança para status offered vinculado a um booking_hold ativo');

// 5.6 Expiração de Oferta após vencimento do TTL
$futureNow = new DateTimeImmutable('2026-11-01 12:00:00', BookingCalendarService::tz());
$waitlistExpiredSvc = new BookingWaitlistService($db, $futureNow);
$expReport = $waitlistExpiredSvc->process();
check(($expReport['expired'] ?? 0) === 1, 'Cron expira oferta cujo prazo TTL foi excedido');

$expiredItem = $db->query("SELECT status FROM booking_waitlist WHERE id = {$wlOfferTest['id']}")->fetch(PDO::FETCH_ASSOC);
check($expiredItem['status'] === 'expired', 'Status atualizado para expired liberando o hold para outros pacientes');

echo "\n====================================================================\n";
echo "  RESULTADO DA SUÍTE DE CALENDÁRIO, FERIADOS E LISTA DE ESPERA:\n";
echo "  Total de verificações executadas: {$passed} + {$failed}\n";
echo "  Sucessos: {$passed} | Falhas: {$failed}\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);
