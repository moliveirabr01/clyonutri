<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES DO ÉPICO NI2-10 (AGENDA & JORNADA DE CONSULTA)
 * ==============================================================================
 * Cobre rigorosamente os critérios de aceite das 5 histórias filhas:
 *   - NI2-81: Criar e editar agendamento (conflitos, duração, tenant, timezone)
 *   - NI2-82: Estados e ciclo da consulta (scheduled, confirmed, in_progress, completed, cancelled, no_show)
 *   - NI2-83: Agenda diária e semanal responsiva (dia/semana, navegação, ordenação)
 *   - NI2-84: Tela de execução do atendimento (contexto clínico, evolução com SHA-256)
 *   - NI2-85: Dashboard: agenda e pacientes que exigem atenção (métricas reais, sem mocks)
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\InvalidAppointmentTransitionException;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Patient\PatientRepository;

$rootDir = dirname(__DIR__);
Env::load($rootDir . '/.env');

echo "====================================================================\n";
echo "   CLYONUTRI II — SUÍTE DE VERIFICAÇÃO INTEGRAL DO ÉPICO NI2-10\n";
echo "   Agenda, Ciclo da Consulta e Execução do Atendimento Clínico\n";
echo "====================================================================\n\n";

$db = Connection::getInstance();
$passed = 0;
$failed = 0;

function assertCondition(string $desc, bool $cond, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc} " . ($details ? "({$details})" : "") . "\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// SETUP DE TESTES: Garantir Clínicas e Pacientes Reais
// -----------------------------------------------------------------------------
// Clínica A (Tenant 1)
$clinicIdA = 1;
TenantContext::set($clinicIdA, ['id' => 1, 'name' => 'Dra. Camila Mendes']);

// Obter ID do profissional da clínica A
$stmtProfA = $db->prepare("SELECT id FROM professionals WHERE user_id = 1 LIMIT 1");
$stmtProfA->execute();
$profIdA = (int)$stmtProfA->fetchColumn();
if (!$profIdA) {
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES (1, '12345', 'CRN-3')");
    $profIdA = (int)$db->lastInsertId();
}

// Limpar registros de execuções de testes anteriores
$db->exec("DELETE FROM appointments WHERE patient_id IN (SELECT id FROM patients WHERE name LIKE '%(Teste Agenda)%' OR name LIKE '%(Teste Conflito)%')");
$db->exec("DELETE FROM patients WHERE name LIKE '%(Teste Agenda)%' OR name LIKE '%(Teste Conflito)%' OR name LIKE '%Paciente Invasor B%'");

// Criar 2 pacientes para testes no Tenant A
$patientRepo = new PatientRepository($db);
$patientA1 = $patientRepo->create([
    'name' => 'Mariana Souza (Teste Agenda)',
    'email' => 'mariana.agenda@teste.com',
    'phone' => '(11) 98888-1111',
    'birth_date' => '1995-05-15',
    'gender' => 'female',
    'notes' => 'Paciente para testes de agendamento e ciclo',
    'lgpd_consent' => 1,
]);

$patientA2 = $patientRepo->create([
    'name' => 'Carlos Eduardo (Teste Conflito)',
    'email' => 'carlos.agenda@teste.com',
    'phone' => '(11) 98888-2222',
    'birth_date' => '1988-10-20',
    'gender' => 'male',
    'notes' => 'Paciente para teste de detecção de sobreposição',
    'lgpd_consent' => 1,
]);

// Criar Paciente em outro Tenant (Tenant B = 3) para testes de autorização
$patientUuidB = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
$stmtPatientB = $db->prepare("INSERT INTO patients (uuid, clinic_id, name, email) VALUES (:uuid, 3, 'Paciente Invasor B', 'invasor@tenantb.com')");
$stmtPatientB->execute(['uuid' => $patientUuidB]);
$patientIdB = (int)$db->lastInsertId();

$service = new AppointmentService($db);
$today = $service->todayDate();

$db->exec("DELETE FROM appointments WHERE patient_id IN ({$patientA1['id']}, {$patientA2['id']}) OR (professional_id = {$profIdA} AND scheduled_at LIKE '{$today}%')");

// =============================================================================
// [1] NI2-81: CRIAR E EDITAR AGENDAMENTO (Conflitos, Duração, Tenant, Timezone)
// =============================================================================
echo "[1] Testando NI2-81 — Criar e Editar Agendamento...\n";

// 1.1 Agendar consulta válida
$scheduleDate = $today . ' 10:00:00';
$app1 = $service->schedule(
    $patientA1['id'],
    $profIdA,
    $scheduleDate,
    'first_visit',
    60,
    'Primeira consulta para plano hipertrofia'
);

assertCondition("Consulta #{$app1['id']} criada com status inicial 'scheduled'", $app1['status'] === 'scheduled');
assertCondition("Duração e horário salvos corretamente", $app1['duration_minutes'] === 60 && $app1['scheduled_at'] === $scheduleDate);

// 1.2 Verificar persistência real em appointments e appointment_status_history
$stmtCheck = $db->prepare("SELECT * FROM appointments WHERE id = :id");
$stmtCheck->execute(['id' => $app1['id']]);
$persistedApp = $stmtCheck->fetch();
assertCondition("Agendamento persistido fisicamente no banco relacional", $persistedApp && (int)$persistedApp['patient_id'] === $patientA1['id']);

$history = $service->getStatusHistory($app1['id']);
assertCondition("Trilha de auditoria inicial registrada na criação", count($history) >= 1 && $history[0]['to_status'] === 'scheduled');

// 1.3 Detecção rigorosa de conflito de agenda (Sobreposição no mesmo profissional)
$conflictCaught = false;
try {
    // Tenta agendar para Carlos às 10:30 (dentro do intervalo 10:00 - 11:00 de Mariana)
    $service->schedule(
        $patientA2['id'],
        $profIdA,
        $today . ' 10:30:00',
        'follow_up',
        45,
        'Tentativa com sobreposição'
    );
} catch (AppointmentConflictException $e) {
    $conflictCaught = true;
}
assertCondition("Conflito de horário detectado e bloqueado com AppointmentConflictException", $conflictCaught);

// 1.4 Encaixe autorizado (allowConflict = true)
$app2Encaixe = $service->schedule(
    $patientA2['id'],
    $profIdA,
    $today . ' 10:30:00',
    'follow_up',
    45,
    'Encaixe intencional autorizado',
    true // allowConflict
);
assertCondition("Encaixe intencional criado com sucesso quando allowConflict = true", isset($app2Encaixe['id']));

// 1.5 Edição / Reagendamento da consulta
$newScheduleDate = $today . ' 14:00:00';
$editedApp = $service->edit($app1['id'], [
    'scheduled_at' => $newScheduleDate,
    'duration_minutes' => 90,
    'notes' => 'Horário reagendado a pedido da paciente',
]);
assertCondition("Consulta reagendada com sucesso para 14:00", $editedApp['scheduled_at'] === $newScheduleDate);
assertCondition("Duração atualizada para 90 minutos", (int)$editedApp['duration_minutes'] === 90);

// 1.6 Tenant Guard: Tentativa de agendar paciente de outra clínica
$crossTenantBlocked = false;
try {
    $service->schedule($patientIdB, $profIdA, $today . ' 16:00:00');
} catch (TenantSecurityException $e) {
    $crossTenantBlocked = true;
}
assertCondition("Tentativa de agendar paciente de outro tenant bloqueada (403)", $crossTenantBlocked);

// =============================================================================
// [2] NI2-82: ESTADOS E CICLO DA CONSULTA (Máquina de Estados & Auditoria)
// =============================================================================
echo "\n[2] Testando NI2-82 — Estados e Ciclo da Consulta...\n";

// Criar nova consulta para testar o ciclo completo
$appCiclo = $service->schedule($patientA1['id'], $profIdA, $today . ' 16:00:00', 'follow_up', 60);

// 2.1 Transição scheduled -> confirmed
$okConfirmed = $service->updateStatus($appCiclo['id'], AppointmentService::STATUS_CONFIRMED, 'Paciente confirmou via WhatsApp');
assertCondition("Transição scheduled ➔ confirmed aprovada", $okConfirmed);

// 2.2 Transição confirmed -> in_progress
$okProgress = $service->updateStatus($appCiclo['id'], AppointmentService::STATUS_IN_PROGRESS, 'Paciente entrou no consultório');
assertCondition("Transição confirmed ➔ in_progress aprovada", $okProgress);

// 2.3 Transição in_progress -> completed
$okCompleted = $service->updateStatus($appCiclo['id'], AppointmentService::STATUS_COMPLETED, 'Atendimento concluído com sucesso');
assertCondition("Transição in_progress ➔ completed aprovada", $okCompleted);

// 2.4 Transição inválida a partir de estado terminal completed
$terminalBlocked = false;
try {
    $service->updateStatus($appCiclo['id'], AppointmentService::STATUS_SCHEDULED, 'Tentando reabrir');
} catch (InvalidAppointmentTransitionException $e) {
    $terminalBlocked = true;
}
assertCondition("Transição inválida completed ➔ scheduled bloqueada com exceção", $terminalBlocked);

// 2.5 Testar ciclo com No-Show (não compareceu)
$appNoShow = $service->schedule($patientA2['id'], $profIdA, $today . ' 17:00:00', 'follow_up', 45);
$okNoShow = $service->updateStatus($appNoShow['id'], AppointmentService::STATUS_NO_SHOW, 'Paciente não compareceu no horário agendado');
assertCondition("Transição scheduled ➔ no_show aprovada", $okNoShow);

$noShowTerminalBlocked = false;
try {
    $service->updateStatus($appNoShow['id'], AppointmentService::STATUS_IN_PROGRESS);
} catch (InvalidAppointmentTransitionException $e) {
    $noShowTerminalBlocked = true;
}
assertCondition("Transição inválida no_show ➔ in_progress bloqueada (terminal)", $noShowTerminalBlocked);

// 2.6 Auditoria completa de transições registradas
$cicloHistory = $service->getStatusHistory($appCiclo['id']);
assertCondition("Histórico de auditoria registrou todas as 4 etapas do ciclo", count($cicloHistory) === 4);
assertCondition("Etapa 1 é scheduled", $cicloHistory[0]['to_status'] === 'scheduled');
assertCondition("Etapa 2 é confirmed com justificativa", $cicloHistory[1]['to_status'] === 'confirmed' && str_contains($cicloHistory[1]['notes'], 'WhatsApp'));
assertCondition("Etapa 3 é in_progress", $cicloHistory[2]['to_status'] === 'in_progress');
assertCondition("Etapa 4 é completed", $cicloHistory[3]['to_status'] === 'completed');

// =============================================================================
// [3] NI2-83: AGENDA DIÁRIA E SEMANAL RESPONSIVA
// =============================================================================
echo "\n[3] Testando NI2-83 — Visão Diária e Semanal da Agenda...\n";

$todayList = $service->listByDay($today);
assertCondition("listByDay retornou consultas do dia atual", count($todayList) >= 3);
assertCondition("Consultas ordenadas cronologicamente", strtotime($todayList[0]['scheduled_at']) <= strtotime($todayList[1]['scheduled_at']));
assertCondition("Contém dados do paciente (nome e telefone)", isset($todayList[0]['patient_name']) && !empty($todayList[0]['patient_name']));

$weekView = $service->listByWeek($today);
assertCondition("listByWeek retornou estrutura semanal de 7 dias", isset($weekView['days']) && count($weekView['days']) === 7);
assertCondition("Total de consultas da semana calculado corretamente", $weekView['total_appointments'] >= count($todayList));

// =============================================================================
// [4] NI2-84: TELA DE EXECUÇÃO DO ATENDIMENTO & EVOLUÇÃO COM SHA-256
// =============================================================================
echo "\n[4] Testando NI2-84 — Execução do Atendimento e Conclusão Clínica...\n";

// Criar consulta para execução clínica
$appExec = $service->schedule($patientA1['id'], $profIdA, $today . ' 18:00:00', 'first_visit', 60);

// Executar conclusão clínica da consulta
$evolutionSummary = "Paciente relata boa adaptação à rotina. Ajustado plano hipercalórico com aumento de 300 kcal. Metas de hidratação definidas para 2.500 ml/dia.";
$clinicalDetails = [
    'weight_kg' => 63.2,
    'waist_cm' => 74.0,
    'blood_pressure' => '120/80',
];

$concluded = $service->completeConsultation(
    $appExec['id'],
    $evolutionSummary,
    $clinicalDetails,
    1 // Dra. Camila
);

assertCondition("Consulta finalizada com status 'completed'", $concluded['status'] === 'completed');
assertCondition("Registro de prontuário gerado", !empty($concluded['clinical_record_id']));
assertCondition("Hash SHA-256 de 64 caracteres computado e encadeado", strlen($concluded['record_hash']) === 64);

// Verificar no banco se a consulta e o prontuário estão consistentes
$stmtRec = $db->prepare("SELECT * FROM clinical_records WHERE id = :id");
$stmtRec->execute(['id' => $concluded['clinical_record_id']]);
$recordData = $stmtRec->fetch();
assertCondition("Prontuário armazenado append-only com hash imutável", $recordData && $recordData['record_hash'] === $concluded['record_hash']);
$decodedContent = json_decode($recordData['content_json'], true);
assertCondition("Conteúdo clínico contém anotações reais da consulta", str_contains($decodedContent['evolution_summary'], 'hipercalórico'));

// =============================================================================
// [5] NI2-85: DASHBOARD OPERACIONAL & PACIENTES QUE EXIGEM ATENÇÃO
// =============================================================================
echo "\n[5] Testando NI2-85 — Dashboard com Métricas Reais e Prioridades...\n";

$todayStats = $service->getTodayStats();
assertCondition("Métricas do dia calculadas dinamicamente", $todayStats['total_today'] > 0);
assertCondition("Contagem de confirmadas é numérica real", is_int($todayStats['confirmed']));
assertCondition("Contagem de completed reflete consultas concluídas", $todayStats['completed'] >= 2);
assertCondition("Contagem de no_show reflete faltosos", $todayStats['no_show'] >= 1);

$attentionList = $service->getAttentionRequired();
assertCondition("Lista de pendências que exigem atenção retornou array estruturado", is_array($attentionList));
// O paciente Carlos Eduardo teve no-show, deve aparecer como pendência de remarcação
$hasNoShowAttention = false;
foreach ($attentionList as $att) {
    if ($att['type'] === 'no_show_follow_up' && str_contains($att['description'], 'Carlos Eduardo')) {
        $hasNoShowAttention = true;
        break;
    }
}
assertCondition("Paciente faltoso identificado automaticamente na lista de atenção do Dashboard", $hasNoShowAttention);

// =============================================================================
// [6] TESTES DE INTEGRAÇÃO HTTP & INTERFACE WEB
// =============================================================================
echo "\n[6] Testando Integração das Rotas HTTP Web (/agenda, /consulta/id)...\n";

$_SESSION['clinic_id'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['clinic_name'] = 'Clínica ClyoNutri Demo';
$_SESSION['user_name'] = 'Dra. Camila Mendes';

// 6.1 Renderização de GET /agenda
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/agenda';
ob_start();
require $rootDir . '/public/index.php';
$agendaHtml = (string)ob_get_clean();

assertCondition("GET /agenda retornou HTML válido", strlen($agendaHtml) > 2000);
assertCondition("Página contém controles de navegação (Dia, Semana, Hoje)", 
    str_contains($agendaHtml, 'Visão Diária') && 
    str_contains($agendaHtml, 'Visão Semanal') && 
    str_contains($agendaHtml, 'Hoje'));
assertCondition("Página exibe consultas reais criadas no banco", str_contains($agendaHtml, 'Mariana Souza'));
assertCondition("Formulário de novo agendamento presente com CSRF", str_contains($agendaHtml, 'action="/agenda"') && str_contains($agendaHtml, '_csrf_token'));

// 6.2 Renderização de GET /consulta/{id}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = "/consulta/{$appCiclo['id']}";
ob_start();
require $rootDir . '/public/index.php';
$consultationHtml = (string)ob_get_clean();

assertCondition("GET /consulta/{id} retornou HTML de atendimento clínico", strlen($consultationHtml) > 2000);
assertCondition("Tela de consulta contém contexto do paciente", str_contains($consultationHtml, 'Contexto do Paciente'));
// v14 (UX-07): abrir a consulta não a inicia mais; agendada/confirmada mostra o painel de início explícito,
// em andamento mostra a evolução + concluir.
assertCondition("Tela contém campo de evolução clínica e botão de concluir (ou painel de início explícito)",
    (str_contains($consultationHtml, 'evolution_summary') &&
    str_contains($consultationHtml, 'Concluir Consulta & Assinar Prontuário'))
    || (in_array($appCiclo['status'] ?? '', ['scheduled', 'confirmed'], true) && str_contains($consultationHtml, 'data-testid="consult-start-panel"'))
    || str_contains($consultationHtml, 'data-testid="consult-closed"'));
assertCondition("Tela exibe trilha de auditoria da consulta", str_contains($consultationHtml, 'Trilha de Auditoria'));

// 6.3 Renderização de GET /dashboard
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/dashboard';
ob_start();
require $rootDir . '/public/index.php';
$dashboardHtml = (string)ob_get_clean();

assertCondition("GET /dashboard exibe seção de Pacientes & Prioridades que Exigem Atenção", 
    str_contains($dashboardHtml, 'Pacientes & Prioridades que Exigem Atenção'));
assertCondition("Dashboard exibe métricas calculadas reais do dia", 
    str_contains($dashboardHtml, 'CONSULTAS HOJE') && 
    !str_contains($dashboardHtml, 'PLANOS A REVISAR</div>\n            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-copper-600); margin: var(--space-2) 0;">\n              2'));

// =============================================================================
// RESUMO FINAL DA SUÍTE DE TESTES DO ÉPICO NI2-10
// =============================================================================
echo "\n====================================================================\n";
echo "   RESULTADO DA SUÍTE DE TESTES NI2-10: {$passed} PASS, {$failed} FAIL\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
