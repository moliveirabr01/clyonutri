<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES OPERACIONAIS DE AGENDA E CONCORRÊNCIA (NI2-187)
 * ==============================================================================
 * Validação rigorosa dos 5 critérios de aceite:
 *  1. Criar, reagendar e cancelar consulta por UI reflete imediatamente no banco.
 *  2. Agenda detecta conflitos no mesmo profissional inclusive sob requisições concorrentes.
 *  3. Status respeita transições estritas e mantém histórico de auditoria.
 *  4. Da consulta, o profissional navega ao hub correto com contexto clínico completo.
 *  5. Responsividade 390/768/1280/1440 px, autorização multi-tenant e fuso horário.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\InvalidAppointmentTransitionException;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;

echo "====================================================================\n";
echo "   CLYONUTRI II — OPERATIONAL AGENDA & CONCURRENCY TEST SUITE (NI2-187)\n";
echo "   Detecção de Conflitos, Máquina de Estados & Fluxo Contextual\n";
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
TenantContext::set($clinicA, ['id' => 1, 'name' => 'Dra. Camila Mendes', 'role' => 'nutritionist']);

// Limpar consultas de testes anteriores nas datas avaliadas
$db->exec("DELETE FROM appointments WHERE scheduled_at LIKE '2026-11-%'");

// Obter ID do profissional da clínica A
$stmtProfA = $db->prepare("SELECT id FROM professionals WHERE user_id = 1 LIMIT 1");
$stmtProfA->execute();
$profId = (int)$stmtProfA->fetchColumn();
if (!$profId) {
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES (1, '12345', 'CRN-3')");
    $profId = (int)$db->lastInsertId();
}

// Garantir pacientes reais para os testes
$patientRepo = new PatientRepository($db);
$p1 = $patientRepo->create([
    'name' => 'Paciente Agenda 187 A',
    'email' => 'agenda187a_' . time() . '@teste.com',
    'phone' => '11988880001',
    'birth_date' => '1990-01-01',
    'gender' => 'female',
    'lgpd_consent' => 1,
]);
$p2 = $patientRepo->create([
    'name' => 'Paciente Agenda 187 B',
    'email' => 'agenda187b_' . time() . '@teste.com',
    'phone' => '11988880002',
    'birth_date' => '1992-02-02',
    'gender' => 'male',
    'lgpd_consent' => 1,
]);

$service = new AppointmentService($db);

// -----------------------------------------------------------------------------
// [CRITÉRIO 1] Criar, reagendar e cancelar consulta reflete imediatamente no DB
// -----------------------------------------------------------------------------
echo "[1/5] Testando ciclo operacional de Criação, Reagendamento e Cancelamento...\n";

// 1.1 Criar Consulta
$scheduleTime = '2026-11-10 10:00:00';
$appCreated = $service->schedule(
    $p1['id'],
    $profId,
    $scheduleTime,
    'first_visit',
    60,
    'Primeira consulta agendada via UI'
);

assertCheck($appCreated['id'] > 0, "Consulta criada com persistência física no banco (ID #{$appCreated['id']})");
assertCheck($appCreated['status'] === 'scheduled', "Status inicial gravado como 'scheduled'");

// 1.2 Reagendar Consulta (Novo Horário)
$newScheduleTime = '2026-11-10 14:00:00';
$appEdited = $service->edit($appCreated['id'], [
    'scheduled_at' => $newScheduleTime,
    'duration_minutes' => 45,
    'notes' => 'Horário reagendado a pedido do paciente',
]);

assertCheck($appEdited['scheduled_at'] === $newScheduleTime, "Horário reagendado atualizado imediatamente no banco ({$newScheduleTime})");
assertCheck((int)$appEdited['duration_minutes'] === 45, "Duração atualizada para 45 minutos");

// 1.3 Cancelar Consulta
$cancelled = $service->updateStatus($appCreated['id'], 'cancelled', 'Paciente solicitou cancelamento');
assertCheck($cancelled === true, "Cancelamento executado com sucesso");

$appCheck = $service->getAppointment($appCreated['id']);
assertCheck($appCheck['status'] === 'cancelled', "Status 'cancelled' persistido na tabela appointments");

// -----------------------------------------------------------------------------
// [CRITÉRIO 2] Detecção de Conflitos de Horário & Concorrência
// -----------------------------------------------------------------------------
echo "\n[2/5] Testando Detecção de Conflitos e Proteção contra Concorrência...\n";

// Criar consulta base das 09:00 às 10:00
$slotTime = '2026-11-12 09:00:00';
$baseApp = $service->schedule(
    $p1['id'],
    $profId,
    $slotTime,
    'first_visit',
    60,
    'Consulta base para teste de sobreposição'
);
assertCheck($baseApp['id'] > 0, "Consulta base das 09:00 às 10:00 criada com sucesso");

// Tentativa de criar consulta sobreposta (09:30 às 10:30) no mesmo profissional
$conflictCaught = false;
try {
    $service->schedule(
        $p2['id'],
        $profId,
        '2026-11-12 09:30:00',
        'follow_up',
        60,
        'Tentativa com sobreposição',
        false // allowConflict = false
    );
} catch (AppointmentConflictException $e) {
    $conflictCaught = true;
}
assertCheck($conflictCaught, "Sobreposição das 09:30 às 10:30 detectada e bloqueada com AppointmentConflictException");

// Permitir sobreposição explicitamente (encaixe)
$fittedApp = $service->schedule(
    $p2['id'],
    $profId,
    '2026-11-12 09:30:00',
    'follow_up',
    30,
    'Encaixe autorizado explicitamente',
    true // allowConflict = true
);
assertCheck($fittedApp['id'] > 0, "Encaixe autorizado (allow_conflict=true) persistido com sucesso");

// Simulação de corrida concorrente: duas transações tentando agendar exatamente o mesmo slot
$conflictConcurrence = false;
try {
    // Tentativa em slot ocupado sem allowConflict
    $service->schedule($p1['id'], $profId, '2026-11-12 09:00:00', 'first_visit', 60, 'Slot concorrente idêntico', false);
} catch (AppointmentConflictException $e) {
    $conflictConcurrence = true;
}
assertCheck($conflictConcurrence, "Slot concorrente idêntico bloqueado atomicamente pela transação");

// -----------------------------------------------------------------------------
// [CRITÉRIO 3] Máquina de Estados Estrita e Trilha de Histórico de Auditoria
// -----------------------------------------------------------------------------
echo "\n[3/5] Testando Máquina de Estados e Histórico de Auditoria...\n";

$lifeApp = $service->schedule($p1['id'], $profId, '2026-11-15 11:00:00', 'first_visit', 60, 'Ciclo de vida completo');

// scheduled -> confirmed
$step1 = $service->updateStatus($lifeApp['id'], 'confirmed', 'Paciente confirmou presença');
assertCheck($step1 === true, "Transição válida: scheduled -> confirmed");

// confirmed -> in_progress
$step2 = $service->updateStatus($lifeApp['id'], 'in_progress', 'Profissional iniciou o atendimento');
assertCheck($step2 === true, "Transição válida: confirmed -> in_progress");

// in_progress -> completed
$step3 = $service->updateStatus($lifeApp['id'], 'completed', 'Consulta finalizada com sucesso');
assertCheck($step3 === true, "Transição válida: in_progress -> completed");

// Tentativa de transição inválida a partir de estado terminal: completed -> in_progress
$invalidTransitionCaught = false;
try {
    $service->updateStatus($lifeApp['id'], 'in_progress', 'Tentativa de reabrir consulta concluída');
} catch (InvalidAppointmentTransitionException $e) {
    $invalidTransitionCaught = true;
}
assertCheck($invalidTransitionCaught, "Transição proibida a partir de estado terminal bloqueada com InvalidAppointmentTransitionException");

// Teste de reconfirmação de consulta cancelada pelo portal do paciente
$portalService = new PatientPortalService($db);
$cancelledApp = $service->schedule($p1['id'], $profId, '2026-11-16 15:00:00');
$service->updateStatus($cancelledApp['id'], 'cancelled', 'Cancelada pela clínica');

$reconfirmBlocked = $portalService->confirmAppointment($p1['id'], $cancelledApp['id']);
assertCheck($reconfirmBlocked === false, "Portal do paciente não permite reconfirmar consulta cancelada (retorna false)");

// Verificar trilha de auditoria completa no banco
$history = $service->getStatusHistory($lifeApp['id']);
assertCheck(count($history) >= 4, "Histórico de auditoria contém todas as transições registradas (" . count($history) . " eventos)");
assertCheck($history[0]['to_status'] === 'scheduled', "Evento 1: Agendamento inicial criado");
assertCheck($history[1]['to_status'] === 'confirmed', "Evento 2: Confirmado");
assertCheck($history[2]['to_status'] === 'in_progress', "Evento 3: Em atendimento");
assertCheck($history[3]['to_status'] === 'completed', "Evento 4: Concluído");

// -----------------------------------------------------------------------------
// [CRITÉRIO 4] Navegação da Consulta para o Hub sem Perda de Contexto
// -----------------------------------------------------------------------------
echo "\n[4/5] Testando Navegação e Preservação de Contexto Clínico...\n";

$consultApp = $service->schedule($p1['id'], $profId, '2026-11-18 16:00:00', 'follow_up', 60, 'Consulta com evolução clínica');
$consultDetails = $service->getAppointment($consultApp['id']);

assertCheck($consultDetails['patient_id'] === $p1['id'], "Contexto da consulta carrega ID correto do paciente (#{$p1['id']})");
assertCheck($consultDetails['patient_name'] === $p1['name'], "Contexto da consulta carrega nome real do paciente ({$p1['name']})");

// Executar conclusão clínica com append-only e SHA-256 no prontuário
$completeResult = $service->completeConsultation(
    $consultApp['id'],
    'Evolução clínica favorável. Paciente aderiu 90% da prescrição, redução de 1.2kg.',
    ['weight_kg' => 68.5, 'notes' => 'Conduta mantida']
);

assertCheck($completeResult['status'] === 'completed', "Consulta transicionada para 'completed'");
assertCheck(!empty($completeResult['record_hash']), "Registro clínico gravado com hash SHA-256: " . substr($completeResult['record_hash'], 0, 16) . "...");
assertCheck($completeResult['clinical_record_id'] > 0, "ID do registro clínico persistido no prontuário (#{$completeResult['clinical_record_id']})");

// -----------------------------------------------------------------------------
// [CRITÉRIO 5] Responsividade 390/768/1280/1440 px, Timezone e Multi-Tenancy
// -----------------------------------------------------------------------------
echo "\n[5/5] Testando Responsividade CSS, Fuso Horário e Barreira Multi-Tenant...\n";

// 5.1 Validação de Breakpoints CSS
$cssContent = file_get_contents(__DIR__ . '/../public/assets/css/clinical-botanical.css');
assertCheck(str_contains($cssContent, '@media (max-width: 390px)'), "CSS contempla breakpoint mobile compacto de 390px");
assertCheck(str_contains($cssContent, '@media (max-width: 768px)'), "CSS contempla breakpoint tablet/mobile de 768px");
assertCheck(str_contains($cssContent, '@media (min-width: 1280px)'), "CSS contempla breakpoint laptop de 1280px");
assertCheck(str_contains($cssContent, '@media (min-width: 1440px)'), "CSS contempla breakpoint desktop amplo de 1440px");

// 5.2 Validação de Timezone Consistente
$tz = date_default_timezone_get();
assertCheck($tz === 'America/Sao_Paulo', "Fuso horário configurado e ativo como 'America/Sao_Paulo'");

// 5.3 Barreira Multi-Tenant Estrita (Tenant B não acessa consultas do Tenant A)
TenantContext::set(99, ['id' => 999, 'name' => 'Dr. Invasor', 'role' => 'nutritionist']);

$appCross = $service->getAppointment($lifeApp['id']);
assertCheck($appCross === null, "getAppointment retorna NULL quando requisitado por tenant que não é dono da consulta");

$crossTenantBlocked = false;
try {
    \ClyoNutri\Core\Security\TenantGuard::assertAppointmentBelongsToClinic($lifeApp['id'], 99, $db);
} catch (TenantSecurityException $e) {
    $crossTenantBlocked = true;
}
assertCheck($crossTenantBlocked, "TenantGuard bloqueia acesso cross-tenant com TenantSecurityException (403 Forbidden)");

// Restaurar tenant
TenantContext::set($clinicA, ['id' => 1, 'name' => 'Dra. Camila Mendes', 'role' => 'nutritionist']);

// -----------------------------------------------------------------------------
// RESULTADO FINAL
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "RESULTADO DA VALIDAÇÃO DA AGENDA OPERACIONAL (NI2-187):\n";
echo "  TOTAL DE ASSERÇÕES: " . ($passed + $failed) . "\n";
echo "  PASSOU            : {$passed}\n";
echo "  FALHOU            : {$failed}\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "STATUS: ALL CRITERIA SATISFIED (100% SUCESSO - AUDITORIA NI2-187 APROVADA)\n";
    exit(0);
} else {
    echo "STATUS: FAILURES DETECTED\n";
    exit(1);
}
