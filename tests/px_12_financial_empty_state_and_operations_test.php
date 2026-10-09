<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: ESTADO VAZIO FINANCEIRO & OPERAÇÕES REAIS (PX-12 / NI2-305)
 * ==============================================================================
 * Cobertura de Critérios de Aceite para NI2-305 (PX-12):
 * 1. Estado vazio explica origem dos lançamentos (consultas na agenda ou cobranças manuais).
 * 2. CTA operacional leva a fluxo real de cadastro de recebíveis (+ Novo Lançamento).
 * 3. Link de ação secundária direciona para a Agenda de Atendimentos (/agenda).
 * 4. Persistência real em banco: criação, atualização e exclusão/cancelamento de recebíveis.
 * 5. Baixa de pagamento real: gera registro em `payments`, código de recibo comprobatório (REC-...) e atualiza status.
 * 6. Suporte a pagamentos parciais e controle de saldo remanescente.
 * 7. Tradução completa de status ('Pendente', 'Recebido', 'Cancelado', 'Vencido', 'Parcial') e formatação de moeda BRL (R$ X,XX).
 * 8. Isolamento multi-tenant estrito: recusa acesso e mutações cross-tenant com 403 Fail-Closed.
 * 9. Validação estrita de entradas: rejeita valores <= 0, campos vazios e pacientes inválidos.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Finance\FinanceService;
use ClyoNutri\Application\Controllers\DashboardController;

$db = Connection::getInstance();

function logPx12Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx12(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO PX-12 (NI2-305): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-12: FINANCEIRO VAZIO & OPERAÇÕES (NI2-305) <<<\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$patientAlfaId = 0;
$patientBetaId = 0;
$nutriIdAlfa = 0;
$nutriIdBeta = 0;
$appointmentId = 0;

try {
    // -------------------------------------------------------------------------
    // SETUP: Provisionando Tenants Reais (Alfa & Beta)
    // -------------------------------------------------------------------------
    echo "--- SETUP: Provisionando Ambiente Multi-Tenant ---\n";

    $suffix = bin2hex(random_bytes(4));

    // Clínica Alfa
    $uAlfa = "clinic-alfa-{$suffix}";
    $stmt = $db->prepare("INSERT INTO clinics (uuid, name, email, status, created_at, updated_at) VALUES (:u, :name, :email, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $uAlfa, 'name' => "Clínica Alfa Financeiro {$suffix}", 'email' => "alfa.{$suffix}@clinica.test"]);
    $clinicIdAlfa = (int)$db->lastInsertId();

    // Nutricionista Alfa
    $userUuidAlfa = "user-alfa-{$suffix}";
    $stmt = $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status, created_at, updated_at) VALUES (:u, :name, :email, 'hash', 'nutritionist', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $userUuidAlfa, 'name' => "Dra. Alfa {$suffix}", 'email' => "alfa.fin.{$suffix}@clyonutri.test"]);
    $nutriIdAlfa = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (:c, :u, 'admin', 'active', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicIdAlfa, 'u' => $nutriIdAlfa]);

    $stmt = $db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (:u, 'CRN-1111', 'CRN-3')");
    $stmt->execute(['u' => $nutriIdAlfa]);
    $profIdAlfa = (int)$db->lastInsertId();

    // Paciente Alfa
    $patUuidAlfa = "pat-alfa-{$suffix}";
    $stmt = $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status, created_at, updated_at) VALUES (:c, :u, :n, :e, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicIdAlfa, 'u' => $patUuidAlfa, 'n' => "Paciente Alfa {$suffix}", 'e' => "pac.alfa.{$suffix}@test.com"]);
    $patientAlfaId = (int)$db->lastInsertId();

    // Agendamento para Alfa
    $stmt = $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status, notes, created_at) VALUES (:c, :p, :prof, '2026-10-15 10:00:00', 'scheduled', 'Consulta Inicial', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicIdAlfa, 'p' => $patientAlfaId, 'prof' => $profIdAlfa]);
    $appointmentId = (int)$db->lastInsertId();

    // Clínica Beta (Segundo Tenant)
    $uBeta = "clinic-beta-{$suffix}";
    $stmt = $db->prepare("INSERT INTO clinics (uuid, name, email, status, created_at, updated_at) VALUES (:u, :name, :email, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $uBeta, 'name' => "Clínica Beta Financeiro {$suffix}", 'email' => "beta.{$suffix}@clinica.test"]);
    $clinicIdBeta = (int)$db->lastInsertId();

    $userUuidBeta = "user-beta-{$suffix}";
    $stmt = $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status, created_at, updated_at) VALUES (:u, :name, :email, 'hash', 'nutritionist', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['u' => $userUuidBeta, 'name' => "Dr. Beta {$suffix}", 'email' => "beta.fin.{$suffix}@clyonutri.test"]);
    $nutriIdBeta = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status, created_at) VALUES (:c, :u, 'admin', 'active', CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicIdBeta, 'u' => $nutriIdBeta]);

    $stmt = $db->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (:u, 'CRN-2222', 'CRN-3')");
    $stmt->execute(['u' => $nutriIdBeta]);
    $profIdBeta = (int)$db->lastInsertId();

    $patUuidBeta = "pat-beta-{$suffix}";
    $stmt = $db->prepare("INSERT INTO patients (clinic_id, uuid, name, email, status, created_at, updated_at) VALUES (:c, :u, :n, :e, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute(['c' => $clinicIdBeta, 'u' => $patUuidBeta, 'n' => "Paciente Beta {$suffix}", 'e' => "pac.beta.{$suffix}@test.com"]);
    $patientBetaId = (int)$db->lastInsertId();

    logPx12Pass('SETUP', "Tenants Alfa (#{$clinicIdAlfa}) e Beta (#{$clinicIdBeta}) provisionados com dados reais.");

    // -------------------------------------------------------------------------
    // GATE 1: ESTADO VAZIO EXPLICATIVO COM CTA OPERACIONAL (FINDING PX-12)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 1: Validação do Estado Vazio Explicativo (/financeiro) ---\n";

    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'role' => 'nutritionist', 'name' => 'Dra. Alfa']);
    $_SESSION['clinic_id'] = $clinicIdAlfa;
    $_SESSION['user_id'] = $nutriIdAlfa;
    $_SESSION['user_role'] = 'nutritionist';
    $_SESSION['clinic_name'] = "Clínica Alfa Financeiro {$suffix}";
    $_SESSION['user_name'] = "Dra. Alfa {$suffix}";

    $controller = new DashboardController();

    ob_start();
    $controller->finance();
    $htmlEmpty = ob_get_clean();

    // Verificações do texto explicativo exigido no finding PX-12
    assertPx12(str_contains($htmlEmpty, 'Nenhum recebível financeiro registrado'), "Título de estado vazio ausente.");
    assertPx12(str_contains($htmlEmpty, 'Os recebíveis financeiros representam honorários de consultas clínicas'), "Explicação da origem dos recebíveis ausente.");
    assertPx12(str_contains($htmlEmpty, '+ Novo Lançamento Financeiro'), "CTA de novo lançamento ausente no estado vazio.");
    assertPx12(str_contains($htmlEmpty, 'Ver Agenda de Atendimentos'), "Link secundário de direcionamento para a agenda ausente.");
    assertPx12(str_contains($htmlEmpty, 'href="/agenda"'), "Link para /agenda ausente.");
    assertPx12(str_contains($htmlEmpty, 'Como funciona o fluxo financeiro do ClyoNutri'), "Guia operacional dos passos do fluxo ausente.");
    assertPx12(!str_contains($htmlEmpty, '<tr><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhum recebível financeiro registrado.</td></tr>'), "Tabela crua antiga sem CTA ainda presente.");

    // Verificação de KPIs zerados de forma legítima
    assertPx12(str_contains($htmlEmpty, 'Total a Receber (Pendente)'), "Card de indicador de pendentes ausente.");
    assertPx12(str_contains($htmlEmpty, 'R$ 0,00'), "Valor zerado não formatado em padrão BRL.");

    logPx12Pass('GATE 1', "Estado vazio em /financeiro exibe explicação da origem, CTAs operacionais e guia didático.");

    // -------------------------------------------------------------------------
    // GATE 2: CRIAÇÃO REAL DE RECEBÍVEL E PERSISTÊNCIA NO BANCO
    // -------------------------------------------------------------------------
    echo "\n--- GATE 2: Lançamento Financeiro Real com Persistência em Banco ---\n";

    $financeService = new FinanceService($db);
    $created = $financeService->createReceivable(
        $patientAlfaId,
        'Consulta Nutricional Inicial & Bioimpedância',
        25000, // R$ 250,00
        '2026-10-20',
        $appointmentId
    );

    assertPx12($created['id'] > 0, "ID do recebível gerado deve ser maior que 0.");
    assertPx12($created['status'] === 'pending', "Status inicial do recebível deve ser 'pending'.");
    assertPx12($created['amount_cents'] === 25000, "Valor do recebível deve ser 25000 centavos.");

    // Verificação direta no banco de dados SQLite
    $stmtCheck = $db->prepare("SELECT * FROM receivables WHERE id = :id");
    $stmtCheck->execute(['id' => $created['id']]);
    $recDb = $stmtCheck->fetch();

    assertPx12($recDb !== false, "Recebível não encontrado na tabela receivables.");
    assertPx12((int)$recDb['clinic_id'] === $clinicIdAlfa, "Clinic ID gravado incorretamente.");
    assertPx12((int)$recDb['patient_id'] === $patientAlfaId, "Patient ID gravado incorretamente.");
    assertPx12((int)$recDb['appointment_id'] === $appointmentId, "Appointment ID não associado corretamente.");
    assertPx12($recDb['description'] === 'Consulta Nutricional Inicial & Bioimpedância', "Descrição gravada incorretamente.");

    // Sumário financeiro
    $summaryAfterCreate = $financeService->getReceivablesSummary();
    assertPx12($summaryAfterCreate['total_count'] === 1, "Total de lançamentos deve ser 1.");
    assertPx12($summaryAfterCreate['total_pending_cents'] === 25000, "Total pendente deve ser 25000 centavos.");
    assertPx12($summaryAfterCreate['total_paid_cents'] === 0, "Total pago deve ser 0 centavos.");

    $receivableAlfa1Id = (int)$created['id'];
    logPx12Pass('GATE 2', "Recebível criado e persistido no banco com sucesso (#{$receivableAlfa1Id}, R$ 250,00).");

    // -------------------------------------------------------------------------
    // GATE 3: BAIXA DE PAGAMENTO REAL COM CÓDIGO DE RECIBO (REC-...)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 3: Liquidação/Baixa Real com Geração de Recibo Comprobatório ---\n";

    $paymentResult = $financeService->recordPayment($receivableAlfa1Id, 25000, 'pix');

    assertPx12($paymentResult['status'] === 'paid', "Status após quitação total deve ser 'paid'.");
    assertPx12(str_starts_with($paymentResult['receipt_code'], 'REC-'), "Código do recibo deve iniciar com 'REC-'.");
    assertPx12(strlen($paymentResult['receipt_code']) === 12, "Código do recibo deve ter formato REC-XXXXXXXX.");

    // Verificação de persistência na tabela payments
    $stmtPay = $db->prepare("SELECT * FROM payments WHERE receivable_id = :id");
    $stmtPay->execute(['id' => $receivableAlfa1Id]);
    $paymentDb = $stmtPay->fetch();

    assertPx12($paymentDb !== false, "Pagamento não encontrado na tabela payments.");
    assertPx12((int)$paymentDb['clinic_id'] === $clinicIdAlfa, "Clinic ID em payments gravado incorretamente.");
    assertPx12((int)$paymentDb['amount_cents'] === 25000, "Valor do pagamento incorreto.");
    assertPx12($paymentDb['payment_method'] === 'pix', "Método de pagamento gravado incorretamente.");
    assertPx12($paymentDb['receipt_code'] === $paymentResult['receipt_code'], "Código do recibo divergente.");

    // Verificação da geração de recibo formatado
    $receiptData = $financeService->generateReceipt($paymentResult['receipt_code']);
    assertPx12($receiptData !== null, "Recibo gerado não pôde ser recuperado pelo código.");
    assertPx12($receiptData['patient_name'] === "Paciente Alfa {$suffix}", "Nome do paciente divergente no recibo.");
    assertPx12((int)$receiptData['total_amount_cents'] === 25000, "Valor total do recebível incorreto no recibo.");

    // Sumário financeiro após quitação
    $summaryAfterPay = $financeService->getReceivablesSummary();
    assertPx12($summaryAfterPay['total_pending_cents'] === 0, "Total pendente deve ser 0 após quitação.");
    assertPx12($summaryAfterPay['total_paid_cents'] === 25000, "Total pago deve ser 25000 centavos.");

    logPx12Pass('GATE 3', "Baixa registrada em payments e recebível atualizado para 'paid' com recibo {$paymentResult['receipt_code']}.");

    // -------------------------------------------------------------------------
    // GATE 4: PAGAMENTO PARCIAL E SALDO REMANESCENTE
    // -------------------------------------------------------------------------
    echo "\n--- GATE 4: Pagamento Parcial & Atualização de Status Dinâmico ---\n";

    $recParcial = $financeService->createReceivable(
        $patientAlfaId,
        'Plano de Acompanhamento Trimestral (3x)',
        60000, // R$ 600,00
        '2026-11-01'
    );
    $recParcialId = (int)$recParcial['id'];

    // 1ª Parcela: R$ 200,00
    $p1 = $financeService->recordPayment($recParcialId, 20000, 'credit_card');
    assertPx12($p1['status'] === 'partially_paid', "Status após primeira parcela deve ser 'partially_paid'.");

    // 2ª Parcela: R$ 200,00
    $p2 = $financeService->recordPayment($recParcialId, 20000, 'credit_card');
    assertPx12($p2['status'] === 'partially_paid', "Status após segunda parcela deve ser 'partially_paid'.");

    // 3ª Parcela final: R$ 200,00
    $p3 = $financeService->recordPayment($recParcialId, 20000, 'credit_card');
    assertPx12($p3['status'] === 'paid', "Status após quitação da terceira parcela deve ser 'paid'.");

    logPx12Pass('GATE 4', "Fluxo de pagamentos parciais evoluiu de 'pending' -> 'partially_paid' -> 'paid' com integridade.");

    // -------------------------------------------------------------------------
    // GATE 5: CANCELAMENTO E VALIDAÇÕES DE ESTADO
    // -------------------------------------------------------------------------
    echo "\n--- GATE 5: Cancelamento de Recebíveis e Invariantes de Negócio ---\n";

    $recCancel = $financeService->createReceivable(
        $patientAlfaId,
        'Consulta Desmarcada com Cancelamento de Cobrança',
        18000, // R$ 180,00
        '2026-10-30'
    );
    $recCancelId = (int)$recCancel['id'];

    // Cancelar recebível
    $canceledResult = $financeService->cancelReceivable($recCancelId);
    assertPx12($canceledResult['status'] === 'canceled', "Status após cancelamento deve ser 'canceled'.");

    $stmtCheckCancel = $db->prepare("SELECT status FROM receivables WHERE id = :id");
    $stmtCheckCancel->execute(['id' => $recCancelId]);
    assertPx12($stmtCheckCancel->fetchColumn() === 'canceled', "Status no banco deve ser 'canceled'.");

    // Tentativa de pagar recebível cancelado -> deve lançar exceção
    $payCanceledCaught = false;
    try {
        $financeService->recordPayment($recCancelId, 18000, 'pix');
    } catch (\InvalidArgumentException $e) {
        $payCanceledCaught = true;
    }
    assertPx12($payCanceledCaught, "Sistema permitiu pagamento em recebível cancelado indevidamente.");

    // Tentativa de cancelar recebível já pago -> deve lançar exceção
    $cancelPaidCaught = false;
    try {
        $financeService->cancelReceivable($receivableAlfa1Id);
    } catch (\InvalidArgumentException $e) {
        $cancelPaidCaught = true;
    }
    assertPx12($cancelPaidCaught, "Sistema permitiu cancelar um recebível já quitado indevidamente.");

    logPx12Pass('GATE 5', "Invariantes de cancelamento e integridade de status validadas com sucesso.");

    // -------------------------------------------------------------------------
    // GATE 6: ISOLAMENTO MULTI-TENANT E SEGURANÇA FAIL-CLOSED (403)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 6: Isolamento Multi-Tenant Estrito (Alfa vs Beta) ---\n";

    // Mudança de contexto para a Clínica Beta
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'role' => 'nutritionist', 'name' => 'Dr. Beta']);
    $_SESSION['clinic_id'] = $clinicIdBeta;

    $financeServiceBeta = new FinanceService($db);

    // 1. Beta tenta criar recebível associando paciente de Alfa
    $crossPatientCaught = false;
    try {
        $financeServiceBeta->createReceivable($patientAlfaId, 'Tentativa Fraude Tenant', 10000, '2026-10-31');
    } catch (TenantSecurityException $e) {
        $crossPatientCaught = true;
        assertPx12($e->getCode() === 403, "Código da exceção deve ser 403 Forbidden.");
    }
    assertPx12($crossPatientCaught, "Beta conseguiu criar recebível para paciente de Alfa sem autorização.");

    // 2. Beta tenta dar baixa em recebível pertencente a Alfa
    $crossPaymentCaught = false;
    try {
        $financeServiceBeta->recordPayment($receivableAlfa1Id, 25000, 'pix');
    } catch (TenantSecurityException $e) {
        $crossPaymentCaught = true;
        assertPx12($e->getCode() === 403, "Código da exceção deve ser 403 Forbidden.");
    }
    assertPx12($crossPaymentCaught, "Beta conseguiu dar baixa em recebível pertencente a Alfa.");

    // 3. Beta tenta cancelar recebível de Alfa
    $crossCancelCaught = false;
    try {
        $financeServiceBeta->cancelReceivable($recCancelId);
    } catch (TenantSecurityException $e) {
        $crossCancelCaught = true;
        assertPx12($e->getCode() === 403, "Código da exceção deve ser 403 Forbidden.");
    }
    assertPx12($crossCancelCaught, "Beta conseguiu cancelar recebível de Alfa.");

    // 4. Beta tenta acessar recibo emitido por Alfa
    $crossReceipt = $financeServiceBeta->generateReceipt($paymentResult['receipt_code']);
    assertPx12($crossReceipt === null, "Beta conseguiu acessar dados de recibo de Alfa.");

    // 5. Sumário de Beta não deve conter dados de Alfa
    $betaSummary = $financeServiceBeta->getReceivablesSummary();
    assertPx12($betaSummary['total_count'] === 0, "Sumário de Beta exibiu lançamentos de Alfa.");
    assertPx12($betaSummary['total_paid_cents'] === 0, "Sumário de Beta exibiu receitas de Alfa.");

    // 6. Registro de violação de segurança gerado no banco (audit_logs)
    $secStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED' AND resource IN ('patients', 'receivables')");
    $secStmt->execute();
    $violationsCount = (int)$secStmt->fetchColumn();
    assertPx12($violationsCount >= 3, "Tentativas de violação não foram registradas na tabela audit_logs.");

    logPx12Pass('GATE 6', "Isolamento multi-tenant comprovado: todas as 3 operações cruzadas recusadas com 403 e logadas.");

    // -------------------------------------------------------------------------
    // GATE 7: VALIDAÇÃO DE ENTRADAS E LIMITES
    // -------------------------------------------------------------------------
    echo "\n--- GATE 7: Validação Estrita de Entradas e Regras de Negócio ---\n";

    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'role' => 'nutritionist', 'name' => 'Dra. Alfa']);

    // Valor zero ou negativo
    $zeroAmountCaught = false;
    try {
        $financeService->createReceivable($patientAlfaId, 'Valor Zerado', 0, '2026-10-31');
    } catch (\InvalidArgumentException $e) {
        $zeroAmountCaught = true;
    }
    assertPx12($zeroAmountCaught, "Sistema permitiu recebível com valor 0.");

    // Descrição vazia
    $emptyDescCaught = false;
    try {
        $financeService->createReceivable($patientAlfaId, '   ', 10000, '2026-10-31');
    } catch (\InvalidArgumentException $e) {
        $emptyDescCaught = true;
    }
    assertPx12($emptyDescCaught, "Sistema permitiu recebível com descrição vazia.");

    // Vencimento vazio
    $emptyDueDateCaught = false;
    try {
        $financeService->createReceivable($patientAlfaId, 'Sem Vencimento', 10000, '');
    } catch (\InvalidArgumentException $e) {
        $emptyDueDateCaught = true;
    }
    assertPx12($emptyDueDateCaught, "Sistema permitiu recebível sem data de vencimento.");

    logPx12Pass('GATE 7', "Validações de entrada rejeitam valores inválidos e campos em branco com sucesso.");

    // -------------------------------------------------------------------------
    // GATE 8: RENDERIZAÇÃO DA INTERFACE COM DADOS, TRADUÇÃO E FORMATAÇÃO BRL
    // -------------------------------------------------------------------------
    echo "\n--- GATE 8: Renderização Web (/financeiro) com Badges e Moeda BRL ---\n";

    $_SESSION['clinic_id'] = $clinicIdAlfa;
    $_SESSION['user_id'] = $nutriIdAlfa;

    ob_start();
    $controller->finance();
    $htmlWithData = ob_get_clean();

    // Verificações na tabela renderizada
    assertPx12(str_contains($htmlWithData, 'R$ 250,00'), "Valor R$ 250,00 não renderizado em formato brasileiro.");
    assertPx12(str_contains($htmlWithData, 'R$ 600,00'), "Valor R$ 600,00 não renderizado em formato brasileiro.");
    assertPx12(str_contains($htmlWithData, 'Recebido'), "Status 'Recebido' traduzido não encontrado na tabela.");
    assertPx12(str_contains($htmlWithData, 'Cancelado'), "Status 'Cancelado' traduzido não encontrado na tabela.");
    assertPx12(str_contains($htmlWithData, 'badge-success'), "Badge de sucesso não encontrada para recebível pago.");
    assertPx12(str_contains($htmlWithData, 'badge-secondary'), "Badge secundária não encontrada para recebível cancelado.");
    assertPx12(str_contains($htmlWithData, 'Recibo: REC-'), "Código de recibo não renderizado para recebível pago.");
    assertPx12(str_contains($htmlWithData, 'modal-novo-recebivel'), "Modal de novo lançamento ausente no DOM.");
    assertPx12(str_contains($htmlWithData, 'modal-dar-baixa'), "Modal de dar baixa ausente no DOM.");

    // Inspecionar ausência de strings cruas de banco nos badges
    assertPx12(!str_contains($htmlWithData, 'class="badge badge-success">paid<'), "String crua 'paid' renderizada em badge.");
    assertPx12(!str_contains($htmlWithData, 'class="badge badge-warning">pending<'), "String crua 'pending' renderizada em badge.");

    logPx12Pass('GATE 8', "Interface web renderiza tabela com moeda brasileira, datas, badges traduzidos e modais operacionais.");

    // -------------------------------------------------------------------------
    // TEARDOWN: Limpeza dos dados de teste
    // -------------------------------------------------------------------------
    echo "\n--- TEARDOWN: Limpeza do Ambiente de Testes ---\n";

    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM payments WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM receivables WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM appointments WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM patients WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM professionals WHERE user_id = {$nutriIdAlfa}");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM users WHERE id = {$nutriIdAlfa}");
        $db->exec("DELETE FROM clinics WHERE id = {$clinicIdAlfa}");
    }
    if ($clinicIdBeta > 0) {
        $db->exec("DELETE FROM payments WHERE clinic_id = {$clinicIdBeta}");
        $db->exec("DELETE FROM receivables WHERE clinic_id = {$clinicIdBeta}");
        $db->exec("DELETE FROM patients WHERE clinic_id = {$clinicIdBeta}");
        $db->exec("DELETE FROM professionals WHERE user_id = {$nutriIdBeta}");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdBeta}");
        $db->exec("DELETE FROM users WHERE id = {$nutriIdBeta}");
        $db->exec("DELETE FROM clinics WHERE id = {$clinicIdBeta}");
    }

    logPx12Pass('TEARDOWN', "Dados de teste higienizados sem poluição residual.");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 8 GATES DE HOMOLOGAÇÃO PX-12 (NI2-305) PASSARAM COM SUCESSO! <<<\n";
    echo "========================================================================\n\n";
    exit(0);

} catch (\Throwable $e) {
    echo "\n[ERRO CRÍTICO] " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";

    // Teardown de emergência
    try {
        if ($clinicIdAlfa > 0) {
            $db->exec("DELETE FROM payments WHERE clinic_id = {$clinicIdAlfa}");
            $db->exec("DELETE FROM receivables WHERE clinic_id = {$clinicIdAlfa}");
            $db->exec("DELETE FROM appointments WHERE clinic_id = {$clinicIdAlfa}");
            $db->exec("DELETE FROM patients WHERE clinic_id = {$clinicIdAlfa}");
            $db->exec("DELETE FROM professionals WHERE user_id = {$nutriIdAlfa}");
            $db->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdAlfa}");
            $db->exec("DELETE FROM users WHERE id = {$nutriIdAlfa}");
            $db->exec("DELETE FROM clinics WHERE id = {$clinicIdAlfa}");
        }
        if ($clinicIdBeta > 0) {
            $db->exec("DELETE FROM payments WHERE clinic_id = {$clinicIdBeta}");
            $db->exec("DELETE FROM receivables WHERE clinic_id = {$clinicIdBeta}");
            $db->exec("DELETE FROM patients WHERE clinic_id = {$clinicIdBeta}");
            $db->exec("DELETE FROM professionals WHERE user_id = {$nutriIdBeta}");
            $db->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdBeta}");
            $db->exec("DELETE FROM users WHERE id = {$nutriIdBeta}");
            $db->exec("DELETE FROM clinics WHERE id = {$clinicIdBeta}");
        }
    } catch (\Throwable $te) {
        // Ignorar erro no teardown secundário
    }

    exit(1);
}
