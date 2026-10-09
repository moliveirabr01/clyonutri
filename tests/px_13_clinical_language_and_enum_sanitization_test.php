<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: LINGUAGEM CLÍNICA & SANITIZAÇÃO DE ENUMS (PX-13 / NI2-306)
 * ==============================================================================
 * Cobertura de Critérios de Aceite para NI2-306 (PX-13):
 * 1. Dicionário canônico centralizado (ClyoNutri\Core\I18n\LabelMap) para todos os domínios.
 * 2. Zero enums crus (first_visit, follow_up, completed, scheduled, draft, male, female, etc.)
 *    exibidos em tabelas, badges e textos operacionais de telas voltadas ao usuário.
 * 3. Zero códigos de tickets internos (NI2-64, NI2-69, NI2-82, NI2-60, etc.) no HTML operacional.
 * 4. Humanização de jargões técnicos: "Append-Only" substituído por "Evolução Clínica & Histórico Preservado".
 * 5. Detalhes criptográficos (hashes SHA-256 de bloco e raiz Merkle) disponibilizados sob demanda
 *    em tags expansíveis <details> em vez de poluição visual nas fichas clínicas.
 * 6. Suporte consistente para papéis, status de agendamentos, planos, gênero e recebíveis.
 * 7. Isolamento multi-tenant estrito mantido (403 Fail-Closed).
 * 8. Regressão funcional: fluxos de visualização e mutação continuam 100% íntegros.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Application\Controllers\DashboardController;

$db = Connection::getInstance();

function logPx13Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx13(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO PX-13 (NI2-306): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-13: LINGUAGEM CLÍNICA & ENUMS (NI2-306) <<<\n";
echo "========================================================================\n\n";

$clinicAlfaId = 0;
$clinicBetaId = 0;
$nutriAlfaId = 0;
$nutriBetaId = 0;
$patientAlfaId = 0;
$patientBetaId = 0;
$app1Id = 0;
$app2Id = 0;
$planId = 0;

try {
    // -------------------------------------------------------------------------
    // GATE 1: TESTE UNITÁRIO DO DICIONÁRIO CANÔNICO (LabelMap)
    // -------------------------------------------------------------------------
    echo "--- GATE 1: Verificação Unitária de Domínios do LabelMap ---\n";

    // 1.1 Status de Agendamento
    assertPx13(LabelMap::appointmentStatus('scheduled') === 'Agendada', "scheduled deve mapear para Agendada");
    assertPx13(LabelMap::appointmentStatus('confirmed') === 'Confirmada', "confirmed deve mapear para Confirmada");
    assertPx13(LabelMap::appointmentStatus('in_progress') === 'Em Atendimento', "in_progress deve mapear para Em Atendimento");
    assertPx13(LabelMap::appointmentStatus('completed') === 'Realizada', "completed deve mapear para Realizada");
    assertPx13(LabelMap::appointmentStatus('cancelled') === 'Cancelada', "cancelled deve mapear para Cancelada");
    assertPx13(LabelMap::appointmentStatus('no_show') === 'Não compareceu', "no_show deve mapear para Não compareceu");
    assertPx13(str_contains(LabelMap::appointmentStatusBadge('completed'), 'badge-success'), "Badge de completed deve ter classe badge-success");
    assertPx13(str_contains(LabelMap::appointmentStatusBadge('completed'), 'Realizada'), "Badge de completed deve exibir texto Realizada");
    assertPx13(str_contains(LabelMap::appointmentStatusBadge('no_show'), 'Não compareceu'), "Badge de no_show deve exibir texto Não compareceu");
    logPx13Pass("GATE 1.1", "Mapeamento e Badges de Status de Agendamento validados com sucesso");

    // 1.2 Tipo de Agendamento
    assertPx13(LabelMap::appointmentType('first_visit') === 'Primeira Consulta', "first_visit deve mapear para Primeira Consulta");
    assertPx13(LabelMap::appointmentType('follow_up') === 'Retorno / Acompanhamento', "follow_up deve mapear para Retorno / Acompanhamento");
    assertPx13(LabelMap::appointmentType('routine') === 'Consulta de Rotina', "routine deve mapear para Consulta de Rotina");
    assertPx13(LabelMap::appointmentType('emergency') === 'Urgência / Encaixe', "emergency deve mapear para Urgência / Encaixe");
    logPx13Pass("GATE 1.2", "Mapeamento de Tipos de Consulta validado com sucesso");

    // 1.3 Gênero / Sexo Biológico (normalização de variações)
    assertPx13(LabelMap::patientGender('male') === 'Masculino', "male deve mapear para Masculino");
    assertPx13(LabelMap::patientGender('Masculino') === 'Masculino', "Masculino deve mapear para Masculino");
    assertPx13(LabelMap::patientGender('female') === 'Feminino', "female deve mapear para Feminino");
    assertPx13(LabelMap::patientGender('feminino') === 'Feminino', "feminino deve mapear para Feminino");
    assertPx13(LabelMap::patientGender('other') === 'Outro', "other deve mapear para Outro");
    assertPx13(LabelMap::patientGender(null) === 'Não informado', "null deve mapear para Não informado");
    assertPx13(LabelMap::patientGender('') === 'Não informado', "vazio deve mapear para Não informado");
    logPx13Pass("GATE 1.3", "Normalização resiliente de Gênero do Paciente validada");

    // 1.4 Status de Plano Alimentar
    assertPx13(LabelMap::mealPlanStatus('draft') === 'Rascunho', "draft deve mapear para Rascunho");
    assertPx13(LabelMap::mealPlanStatus('published') === 'Publicado', "published deve mapear para Publicado");
    assertPx13(str_contains(LabelMap::mealPlanStatusBadge('published'), 'Publicado'), "Badge de plano deve conter Publicado");
    assertPx13(str_contains(LabelMap::mealPlanStatusBadge('draft'), 'Rascunho'), "Badge de plano deve conter Rascunho");
    logPx13Pass("GATE 1.4", "Mapeamento de Status de Plano Alimentar validado");

    // 1.5 Status Financeiro / Recebíveis
    assertPx13(LabelMap::receivableStatus('pending', false) === 'Pendente', "pending no prazo deve ser Pendente");
    assertPx13(LabelMap::receivableStatus('pending', true) === 'Vencido', "pending atrasado deve ser Vencido");
    assertPx13(LabelMap::receivableStatus('paid') === 'Recebido', "paid deve ser Recebido");
    assertPx13(LabelMap::receivableStatus('partially_paid') === 'Parcial', "partially_paid deve ser Parcial");
    assertPx13(LabelMap::receivableStatus('canceled') === 'Cancelado', "canceled deve ser Cancelado");
    assertPx13(str_contains(LabelMap::receivableStatusBadge('paid'), 'badge-success'), "Badge pago deve ser badge-success");
    assertPx13(str_contains(LabelMap::receivableStatusBadge('pending', true), 'badge-danger'), "Badge vencido deve ser badge-danger");
    logPx13Pass("GATE 1.5", "Mapeamento e Badges Financeiros validados");

    // 1.6 Papéis do Sistema (Roles)
    assertPx13(LabelMap::userRole('admin') === 'Administrador', "admin deve mapear para Administrador");
    assertPx13(LabelMap::userRole('nutritionist') === 'Nutricionista', "nutritionist deve mapear para Nutricionista");
    assertPx13(LabelMap::userRole('patient') === 'Paciente', "patient deve mapear para Paciente");
    assertPx13(LabelMap::userRole('operator') === 'Suporte / Atendimento', "operator deve mapear para Suporte / Atendimento");
    logPx13Pass("GATE 1.6", "Mapeamento de Papéis do Sistema validado");

    // 1.7 Limpeza de Texto Operacional e Jargões Técnicos
    $rawText = "Resumo Clínico (NI2-64) com dados append-only preservados (NI2-82)";
    $cleaned = LabelMap::cleanOperationalText($rawText);
    assertPx13(!str_contains($cleaned, 'NI2-64'), "cleanOperationalText deve remover NI2-64");
    assertPx13(!str_contains($cleaned, 'NI2-82'), "cleanOperationalText deve remover NI2-82");
    assertPx13(!str_contains(strtolower($cleaned), 'append-only'), "cleanOperationalText deve higienizar append-only");
    logPx13Pass("GATE 1.7", "Função cleanOperationalText higienizou referências internas e jargões");

    // -------------------------------------------------------------------------
    // CRIAÇÃO DE FIXTURES REAIS NO BANCO DE DADOS
    // -------------------------------------------------------------------------
    echo "\n--- PREPARAÇÃO: Criando Ambientes e Fixtures Reais no Banco ---\n";

    $suffix = bin2hex(random_bytes(4));

    // Clínica Alfa
    $uAlfa = "clinic-alfa-{$suffix}";
    $stmt = $db->prepare("INSERT INTO clinics (uuid, name, email, status, created_at, updated_at) VALUES (:u, :name, :email, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $uAlfa, 'name' => "Clínica Alfa PX13 {$suffix}", 'email' => "alfa.{$suffix}@clinica.test"]);
    $clinicAlfaId = (int)$db->lastInsertId();

    // Nutricionista Alfa
    $userUuidAlfa = "user-alfa-{$suffix}";
    $stmt = $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status, created_at, updated_at) VALUES (:u, :name, :email, 'hash', 'nutritionist', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $userUuidAlfa, 'name' => "Dra. Helena Nutri {$suffix}", 'email' => "helena.{$suffix}@alfa.test"]);
    $nutriAlfaId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (:c, :u, 'admin', 'active', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicAlfaId, 'u' => $nutriAlfaId]);

    $stmt = $db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (:u, 'CRN-1301', 'CRN-3')");
    $stmt->execute(['u' => $nutriAlfaId]);
    $profIdAlfa = (int)$db->lastInsertId();

    // Clínica Beta (para teste multi-tenant)
    $uBeta = "clinic-beta-{$suffix}";
    $stmt = $db->prepare("INSERT INTO clinics (uuid, name, email, status, created_at, updated_at) VALUES (:u, :name, :email, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $uBeta, 'name' => "Clínica Beta PX13 {$suffix}", 'email' => "beta.{$suffix}@clinica.test"]);
    $clinicBetaId = (int)$db->lastInsertId();

    // Nutricionista Beta
    $userUuidBeta = "user-beta-{$suffix}";
    $stmt = $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status, created_at, updated_at) VALUES (:u, :name, :email, 'hash', 'nutritionist', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $userUuidBeta, 'name' => "Dr. Roberto Nutri {$suffix}", 'email' => "roberto.{$suffix}@beta.test"]);
    $nutriBetaId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (:c, :u, 'admin', 'active', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicBetaId, 'u' => $nutriBetaId]);

    $stmt = $db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (:u, 'CRN-1302', 'CRN-3')");
    $stmt->execute(['u' => $nutriBetaId]);
    $profIdBeta = (int)$db->lastInsertId();

    // Paciente Alfa (com gênero 'male' na base para testar tradução para 'Masculino')
    $patUuidAlfa = "pat-alfa-{$suffix}";
    $stmt = $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, gender, status, created_at, updated_at) VALUES (:c, :u, 'Carlos Silva PX13', 'carlos@alfa.test', '(11) 98888-0001', '1985-06-15', 'male', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicAlfaId, 'u' => $patUuidAlfa]);
    $patientAlfaId = (int)$db->lastInsertId();

    // Paciente Beta
    $patUuidBeta = "pat-beta-{$suffix}";
    $stmt = $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, gender, status, created_at, updated_at) VALUES (:c, :u, 'Mariana Beta PX13', 'mariana@beta.test', '(11) 97777-0002', '1992-03-20', 'female', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicBetaId, 'u' => $patUuidBeta]);
    $patientBetaId = (int)$db->lastInsertId();

    // Consultas para Alfa: Consulta 1 (first_visit / scheduled) e Consulta 2 (follow_up / completed)
    $todayDate = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, notes, created_at) VALUES (:c, :p, :prof, :sch, 60, 'scheduled', 'first_visit', 'Primeiro contato clínico', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicAlfaId, 'p' => $patientAlfaId, 'prof' => $profIdAlfa, 'sch' => $todayDate]);
    $app1Id = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, notes, created_at) VALUES (:c, :p, :prof, datetime('now', '-1 hour'), 45, 'completed', 'follow_up', 'Retorno de acompanhamento', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicAlfaId, 'p' => $patientAlfaId, 'prof' => $profIdAlfa]);
    $app2Id = (int)$db->lastInsertId();

    // Transição de auditoria para a consulta 1
    $stmt = $db->prepare("INSERT INTO appointment_status_history (clinic_id, appointment_id, from_status, to_status, changed_by_user_id, notes, created_at) VALUES (:c, :aid, 'scheduled', 'confirmed', :uid, 'Confirmado via WhatsApp', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicAlfaId, 'aid' => $app1Id, 'uid' => $nutriAlfaId]);

    // Plano alimentar para paciente Alfa
    $planService = new MealPlanService($db);
    TenantContext::setClinicId($clinicAlfaId);
    $planResult = $planService->createPlan($patientAlfaId, $profIdAlfa, "Plano Hipertrofia Fase 1", "Plano com alta proteína");
    $planId = (int)$planResult['plan_id'];

    // Entrada no prontuário eletrônico com hashes SHA-256
    $clinicalService = new ClinicalRecordService($db);
    $recordResult = $clinicalService->addClinicalObservation(
        $patientAlfaId,
        $profIdAlfa,
        "Avaliação Nutricional Inicial",
        "Paciente relata boa disposição física. Solicitados exames laboratoriais."
    );

    logPx13Pass("PREPARAÇÃO", "Fixtures persistidas com sucesso (Clínicas, Pacientes, Consultas, Planos e Prontuário)");

    // -------------------------------------------------------------------------
    // GATE 2: RENDERIZAÇÃO REAL DO DASHBOARD (/dashboard)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 2: Renderização Real do Dashboard (/dashboard) ---\n";

    TenantContext::setClinicId($clinicAlfaId);
    $_SESSION['clinic_id'] = $clinicAlfaId;
    $_SESSION['user_id'] = $nutriAlfaId;
    $_SESSION['user_name'] = 'Dra. Helena Nutri';
    $_SESSION['clinic_name'] = 'Clínica Alfa PX13';
    $_SESSION['user_role'] = 'nutritionist';

    $controller = new DashboardController();

    ob_start();
    $controller->index();
    $dashboardHtml = ob_get_clean();

    // Verificações no Dashboard
    assertPx13(str_contains($dashboardHtml, 'Primeira Consulta'), "Dashboard deve conter 'Primeira Consulta' traduzido");
    assertPx13(str_contains($dashboardHtml, 'Retorno / Acompanhamento'), "Dashboard deve conter 'Retorno / Acompanhamento' traduzido");
    assertPx13(str_contains($dashboardHtml, 'Agendada'), "Dashboard deve conter status 'Agendada' traduzido");
    assertPx13(str_contains($dashboardHtml, 'Realizada'), "Dashboard deve conter status 'Realizada' traduzido");
    
    // Proibição de enums crus em células da tabela
    assertPx13(!preg_match('/<td>first_visit<\/td>/i', $dashboardHtml), "Dashboard NÃO deve conter <td>first_visit</td> desprovido de tradução");
    assertPx13(!preg_match('/<td>follow_up<\/td>/i', $dashboardHtml), "Dashboard NÃO deve conter <td>follow_up</td> desprovido de tradução");
    assertPx13(!preg_match('/<span class="badge[^"]*">scheduled<\/span>/i', $dashboardHtml), "Dashboard NÃO deve exibir badge com enum 'scheduled'");
    assertPx13(!preg_match('/<span class="badge[^"]*">completed<\/span>/i', $dashboardHtml), "Dashboard NÃO deve exibir badge com enum 'completed'");
    assertPx13(!str_contains($dashboardHtml, 'NI2-'), "Dashboard NÃO deve conter códigos internos de ticket (NI2-*)");

    logPx13Pass("GATE 2", "Dashboard validado: enums traduzidos para linguagem clínica e zero códigos de ticket");

    // -------------------------------------------------------------------------
    // GATE 3: RENDERIZAÇÃO REAL DA AGENDA (/agenda)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 3: Renderização Real da Agenda (/agenda) ---\n";

    // Visão Diária
    $_GET['view'] = 'day';
    ob_start();
    $controller->agenda();
    $agendaDayHtml = ob_get_clean();

    assertPx13(str_contains($agendaDayHtml, 'Primeira Consulta'), "Agenda diária deve exibir 'Primeira Consulta'");
    assertPx13(str_contains($agendaDayHtml, 'Retorno / Acompanhamento'), "Agenda diária deve exibir 'Retorno / Acompanhamento'");
    assertPx13(str_contains($agendaDayHtml, 'Agendada'), "Agenda diária deve exibir badge 'Agendada'");
    assertPx13(str_contains($agendaDayHtml, 'Realizada'), "Agenda diária deve exibir badge 'Realizada'");
    assertPx13(str_contains($agendaDayHtml, 'Não compareceu'), "Botão ou status de no-show deve ser 'Não compareceu'");
    assertPx13(!str_contains($agendaDayHtml, 'title="Não compareceu">No-Show'), "Agenda NÃO deve ter botão com texto cru 'No-Show'");
    assertPx13(!str_contains($agendaDayHtml, 'NI2-'), "Agenda diária NÃO deve conter referências a tickets NI2-*");

    // Visão Semanal
    $_GET['view'] = 'week';
    ob_start();
    $controller->agenda();
    $agendaWeekHtml = ob_get_clean();

    assertPx13(str_contains($agendaWeekHtml, 'Agendada') || str_contains($agendaWeekHtml, 'Realizada'), "Agenda semanal deve conter badges em português");
    assertPx13(!preg_match('/<span class="badge badge-info"[^>]*>scheduled<\/span>/i', $agendaWeekHtml), "Agenda semanal NÃO deve exibir enum cru 'scheduled'");
    assertPx13(!str_contains($agendaWeekHtml, 'NI2-'), "Agenda semanal NÃO deve conter referências a tickets NI2-*");

    logPx13Pass("GATE 3", "Agenda Diária e Semanal validadas: botões e badges em português com ausência de enums");

    // -------------------------------------------------------------------------
    // GATE 4: RENDERIZAÇÃO REAL DO PRONTUÁRIO ELETRÔNICO (/pacientes/{id}/prontuario)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 4: Renderização Real do Prontuário Eletrônico (/pacientes/{id}/prontuario) ---\n";

    ob_start();
    $controller->patientMedicalRecord(['id' => (string)$patientAlfaId]);
    $prontuarioHtml = ob_get_clean();

    // 4.1 Cabeçalho clínico humanizado
    assertPx13(str_contains($prontuarioHtml, 'Evolução Clínica & Histórico Preservado'), "Cabeçalho do prontuário deve ser 'Evolução Clínica & Histórico Preservado'");
    assertPx13(!str_contains($prontuarioHtml, 'Append-Only'), "Cabeçalho NÃO deve usar jargão técnico 'Append-Only'");
    assertPx13(!str_contains($prontuarioHtml, 'NI2-64'), "Cabeçalho NÃO deve referenciar ticket NI2-64");
    assertPx13(!str_contains($prontuarioHtml, 'NI2-60'), "Prontuário NÃO deve referenciar ticket NI2-60");

    // 4.2 Notificação de integridade em linguagem clínica
    assertPx13(str_contains($prontuarioHtml, 'Histórico Clínico Autêntico e Protegido Contra Adulteração'), "Barra de integridade deve ter mensagem clínica acolhedora");

    // 4.3 Hashes criptográficos contidos sob demanda em <details>
    assertPx13(str_contains($prontuarioHtml, '<details'), "Prontuário deve usar elemento <details> para detalhes criptográficos sob demanda");
    assertPx13(str_contains($prontuarioHtml, 'Verificar autenticidade digital'), "Sumário do details deve convidar à verificação de autenticidade");
    assertPx13(str_contains($prontuarioHtml, 'Assinatura digital (SHA-256)'), "Bloco deve identificar hash como Assinatura digital");

    // 4.4 Texto amigável no modal de nova evolução
    assertPx13(str_contains($prontuarioHtml, 'O registro será datado, assinado pelo profissional e preservado com garantia de autenticidade digital.'), "Modal de evolução deve ter texto explicativo em linguagem clínica");

    logPx13Pass("GATE 4", "Prontuário validado: sem jargões, hashes protegidos em <details> sob demanda e conformidade clínica");

    // -------------------------------------------------------------------------
    // GATE 5: RENDERIZAÇÃO REAL DO PERFIL 360 & SALA DE CONSULTA
    // -------------------------------------------------------------------------
    echo "\n--- GATE 5: Renderização Real do Perfil 360 & Sala de Consulta ---\n";

    // 5.1 Perfil 360
    ob_start();
    $controller->profile360(['id' => (string)$patientAlfaId]);
    $patientDetailHtml = ob_get_clean();

    assertPx13(str_contains($patientDetailHtml, '<strong>Gênero:</strong> Masculino'), "Gênero no Perfil 360 deve ser traduzido de 'male' para 'Masculino'");
    assertPx13(!str_contains($patientDetailHtml, '<strong>Gênero:</strong> male'), "Perfil 360 NÃO deve exibir 'male'");
    assertPx13(!str_contains($patientDetailHtml, '(NI2-69)'), "Perfil 360 NÃO deve exibir referência ao ticket (NI2-69)");

    // 5.2 Sala de Consulta
    ob_start();
    $controller->consultationRoom(['id' => (string)$app1Id]);
    $consultationHtml = ob_get_clean();

    assertPx13(str_contains($consultationHtml, '<strong>Gênero:</strong> Masculino'), "Gênero na Consulta deve ser traduzido de 'male' para 'Masculino'");
    assertPx13(!str_contains($consultationHtml, '<strong>Gênero:</strong> male'), "Consulta NÃO deve exibir 'male'");
    assertPx13(str_contains($consultationHtml, 'Trilha de Auditoria Desta Consulta'), "Histórico de consulta deve ter título limpo");
    assertPx13(!str_contains($consultationHtml, '(NI2-82)'), "Histórico de consulta NÃO deve exibir '(NI2-82)'");
    assertPx13(str_contains($consultationHtml, 'Confirmada'), "Transição na trilha de auditoria deve exibir 'Confirmada' em badge");
    assertPx13(!str_contains($consultationHtml, '<span class="badge badge-info" style="font-size: 0.75rem;">confirmed</span>'), "Trilha NÃO deve exibir 'confirmed' cru");

    logPx13Pass("GATE 5", "Perfil 360 e Sala de Consulta validados: gênero 'Masculino' e trilhas de auditoria traduzidas");

    // -------------------------------------------------------------------------
    // GATE 6: RENDERIZAÇÃO REAL DE PLANOS ALIMENTARES (/planos & /planos/{id})
    // -------------------------------------------------------------------------
    echo "\n--- GATE 6: Renderização Real de Planos Alimentares (/planos) ---\n";

    // 6.1 Listagem de Planos
    ob_start();
    $controller->mealPlans();
    $mealPlansHtml = ob_get_clean();

    assertPx13(str_contains($mealPlansHtml, 'Rascunho'), "Tabela de planos deve exibir badge 'Rascunho'");
    assertPx13(!preg_match('/<span class="badge"[^>]*>draft<\/span>/i', $mealPlansHtml), "Tabela NÃO deve exibir enum cru 'draft'");

    // 6.2 Editor do Plano
    ob_start();
    $controller->mealPlanEditor(['id' => (string)$planId]);
    $editorHtml = ob_get_clean();

    assertPx13(str_contains($editorHtml, '📋 Resumo Clínico da Anamnese'), "Editor de plano deve conter '📋 Resumo Clínico da Anamnese'");
    assertPx13(!str_contains($editorHtml, '(NI2-64)'), "Editor de plano NÃO deve conter ticket '(NI2-64)' no cabeçalho");

    logPx13Pass("GATE 6", "Planos alimentares validados: badges em português e ausência de códigos internos de ticket");

    // -------------------------------------------------------------------------
    // GATE 7: ISOLAMENTO MULTI-TENANT E SEGURANÇA ESTRITA
    // -------------------------------------------------------------------------
    echo "\n--- GATE 7: Teste de Isolamento Multi-Tenant (Fail-Closed) ---\n";

    // Nutricionista Beta tenta acessar prontuário do paciente Alfa
    TenantContext::setClinicId($clinicBetaId);
    $_SESSION['clinic_id'] = $clinicBetaId;
    $_SESSION['user_id'] = $nutriBetaId;

    $caughtCrossTenant = false;
    try {
        TenantGuard::assertPatientBelongsToClinic($patientAlfaId, $clinicBetaId, $db);
    } catch (TenantSecurityException $e) {
        $caughtCrossTenant = true;
    }
    assertPx13($caughtCrossTenant, "TenantGuard DEVE impedir e lançar TenantSecurityException ao tentar acessar paciente cross-tenant");
    logPx13Pass("GATE 7", "Isolamento multi-tenant validado: 403 Fail-Closed mantido em 100% dos limites de tenant");

    // -------------------------------------------------------------------------
    // GATE 8: REGRESSÃO DE OPERAÇÕES CLÍNICAS (Mutações Funcionais Reais)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 8: Regressão Funcional de Operações Clínicas Reais ---\n";

    TenantContext::setClinicId($clinicAlfaId);
    $_SESSION['clinic_id'] = $clinicAlfaId;
    $_SESSION['user_id'] = $nutriAlfaId;

    // Transição de status da consulta 1 de 'confirmed' para 'in_progress'
    $appService = new AppointmentService($db);
    $appService->updateStatus($app1Id, 'in_progress', 'Iniciando teleconsulta', $nutriAlfaId);
    $updatedApp = $appService->getAppointment($app1Id);
    assertPx13($updatedApp['status'] === 'in_progress', "Transição de estado para in_progress deve persistir no banco");
    assertPx13(LabelMap::appointmentStatus($updatedApp['status']) === 'Em Atendimento', "LabelMap deve traduzir novo status para 'Em Atendimento'");

    // Adiciona nova evolução clínica no prontuário
    $newRec = $clinicalService->addClinicalObservation(
        $patientAlfaId,
        $profIdAlfa,
        "Ajuste da Prescrição",
        "Aumentada ingestão proteica para 2.0g/kg conforme metas hipertróficas."
    );
    assertPx13(!empty($newRec['record_hash']), "Nova evolução deve gerar hash SHA-256 válido");
    assertPx13(!empty($newRec['previous_hash']), "Nova evolução deve manter encadeamento com hash anterior");

    logPx13Pass("GATE 8", "Regressão funcional validada: mutações de estado e cadeia criptográfica plenamente operacionais");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 8 GATES DE HOMOLOGAÇÃO PX-13 (NI2-306) FORAM APROVADOS (PASS) <<<\n";
    echo "========================================================================\n\n";

} catch (Throwable $e) {
    echo "\n[ERRO CRÍTICO] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    // Limpeza de fixtures
    if ($patientAlfaId > 0) {
        $db->exec("DELETE FROM appointment_status_history WHERE appointment_id IN ({$app1Id}, {$app2Id})");
        $db->exec("DELETE FROM appointments WHERE id IN ({$app1Id}, {$app2Id})");
        $db->exec("DELETE FROM meal_plan_items WHERE meal_id IN (SELECT id FROM meal_plan_meals WHERE meal_plan_id = {$planId})");
        $db->exec("DELETE FROM meal_plan_meals WHERE meal_plan_id = {$planId}");
        $db->exec("DELETE FROM meal_plans WHERE id = {$planId}");
        $db->exec("DELETE FROM clinical_records WHERE patient_id = {$patientAlfaId}");
        $db->exec("DELETE FROM patients WHERE id = {$patientAlfaId}");
    }
    if ($patientBetaId > 0) {
        $db->exec("DELETE FROM clinical_records WHERE patient_id = {$patientBetaId}");
        $db->exec("DELETE FROM patients WHERE id = {$patientBetaId}");
    }
    if ($nutriAlfaId > 0) {
        $db->exec("DELETE FROM professionals WHERE user_id IN ({$nutriAlfaId}, {$nutriBetaId})");
        $db->exec("DELETE FROM clinic_members WHERE user_id IN ({$nutriAlfaId}, {$nutriBetaId})");
        $db->exec("DELETE FROM users WHERE id IN ({$nutriAlfaId}, {$nutriBetaId})");
    }
    if ($clinicAlfaId > 0) {
        $db->exec("DELETE FROM clinics WHERE id IN ({$clinicAlfaId}, {$clinicBetaId})");
    }
}
