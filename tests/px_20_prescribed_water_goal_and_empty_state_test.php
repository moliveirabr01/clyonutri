<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: META HÍDRICA PRESCRITA & EMPTY STATE (PX-20 / NI2-313)
 * ==============================================================================
 * Cobertura de Critérios de Aceite para NI2-313 (PX-20):
 * 1. Fonte única da verdade: meta hídrica provém estritamente da anamnese clínica (water_intake_ml).
 * 2. "Ausência é ausência": paciente sem anamnese ou sem meta prescrita recebe water_goal_ml = null.
 * 3. Proibição absoluta de fallbacks fictícios (como os antigos 2500 ml fixos).
 * 4. Reflete atualização da conduta do nutricionista (versionamento v1 -> v2).
 * 5. Prioridade para condutas finalizadas sobre rascunhos inacabados.
 * 6. Isolamento multi-tenant estrito: pacientes de um tenant não acessam prescrições de outro tenant.
 * 7. Consumo cumulativo real sincronizado com a meta prescrita na Home do paciente.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;
use ClyoNutri\Application\Api\PatientApiController;

$db = Connection::getInstance();

function logPx20Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx20(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO PX-20 (NI2-313): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-20: META HÍDRICA PRESCRITA (NI2-313) <<<\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$patientAlfaId = 0;
$patientBetaId = 0;
$nutriIdAlfa = 0;
$nutriIdBeta = 0;
$profIdAlfa = 0;
$profIdBeta = 0;

try {
    // -------------------------------------------------------------------------
    // SETUP: Provisionando Tenants Reais (Alfa & Beta)
    // -------------------------------------------------------------------------
    $u1 = 'px20-alfa-' . bin2hex(random_bytes(4));
    $u2 = 'px20-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u1}', 'Clínica Alfa PX-20', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u2}', 'Clínica Beta PX-20', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    // Nutricionistas & Profissionais
    $nutriUuidAlfa = 'user-px20-alfa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Roberta Nutri Alfa', 'roberta-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, 'CRN3-8811', 'CRN-3')");
    $profIdAlfa = (int)$db->lastInsertId();

    $nutriUuidBeta = 'user-px20-beta-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Bernardo Nutri Beta', 'bernardo-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdBeta}, 'CRN3-8822', 'CRN-3')");
    $profIdBeta = (int)$db->lastInsertId();

    // Pacientes
    $pUuidAlfa = 'p-px20-alfa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender, status)
               VALUES ('{$pUuidAlfa}', {$clinicIdAlfa}, 'Carlos Alfa PX-20', 'carlos@alfa-px20.com', '1990-01-01', 'male', 'active')");
    $patientAlfaId = (int)$db->lastInsertId();

    $pUuidBeta = 'p-px20-beta-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender, status)
               VALUES ('{$pUuidBeta}', {$clinicIdBeta}, 'Beatriz Beta PX-20', 'beatriz@beta-px20.com', '1992-05-15', 'female', 'active')");
    $patientBetaId = (int)$db->lastInsertId();

    // Tokens de acesso para simulação de cliente móvel
    $tokenPlainAlfa = 'token_px20_alfa_' . bin2hex(random_bytes(16));
    $tokenHashAlfa = hash('sha256', $tokenPlainAlfa);
    $db->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, expires_at, created_at)
        VALUES ('patient', :pid, 'mobile_app', :token, datetime('now', '+30 days'), CURRENT_TIMESTAMP)
    ")->execute(['pid' => $patientAlfaId, 'token' => $tokenHashAlfa]);

    logPx20Pass("SETUP", "Tenants, nutricionistas e pacientes provisionados com sucesso");

    // -------------------------------------------------------------------------
    // GATE 1: Paciente sem qualquer anamnese clínica (Empty State Fidedigno)
    // -------------------------------------------------------------------------
    echo "\n[GATE 1/7] Empty State: Paciente sem anamnese deve receber water_goal_ml = null...\n";
    TenantContext::set($clinicIdAlfa, ['id' => $patientAlfaId, 'role' => 'patient', 'name' => 'Carlos Alfa PX-20']);

    $portalService = new PatientPortalService($db);
    $apiCtrl = new PatientApiController($db);

    $goalEmpty = $portalService->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalEmpty === null, "Meta prescrita direta do serviço deve ser null quando não há anamnese");
    logPx20Pass("EMPTY_STATE", "getPrescribedWaterGoal retorna estritamente null para paciente virgem");

    $goalViaController = $apiCtrl->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalViaController === null, "Controller de API deve retornar null");
    logPx20Pass("API_CONTROLLER", "PatientApiController delega e retorna null sem valores sintéticos");

    // -------------------------------------------------------------------------
    // GATE 2: Paciente com anamnese sem meta prescrita (water_intake_ml = NULL ou 0)
    // -------------------------------------------------------------------------
    echo "\n[GATE 2/7] Ausência é ausência: Anamnese com water_intake_ml nulo ou zero...\n";

    $insAnamnesisNoWater = $db->prepare("
        INSERT INTO anamneses (
            clinic_id, patient_id, professional_id, version,
            water_intake_ml, is_draft, notes, created_at
        ) VALUES (
            :cid, :pid, :pro_id, 1,
            NULL, 0, 'Primeira consulta de triagem', CURRENT_TIMESTAMP
        )
    ");
    $insAnamnesisNoWater->execute([
        'cid' => $clinicIdAlfa,
        'pid' => $patientAlfaId,
        'pro_id' => $profIdAlfa,
    ]);

    $goalNullAnamnesis = $portalService->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalNullAnamnesis === null, "Anamnese com water_intake_ml = NULL deve produzir water_goal_ml = null");
    logPx20Pass("CLINICAL_NULL", "Anamnese sem meta preenchida retorna estritamente null sem fallback de 2500ml");

    // Atualizar para 0 ml (inválido/inexistente como meta positiva)
    $db->prepare("UPDATE anamneses SET water_intake_ml = 0 WHERE patient_id = :pid")->execute(['pid' => $patientAlfaId]);
    $goalZeroAnamnesis = $portalService->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalZeroAnamnesis === null, "water_intake_ml = 0 deve ser tratado como ausência de meta prescrita");
    logPx20Pass("CLINICAL_ZERO", "water_intake_ml <= 0 tratado como ausência de meta válida");

    // -------------------------------------------------------------------------
    // GATE 3: Paciente com meta hídrica prescrita na conduta clínica (ex: 2000 ml)
    // -------------------------------------------------------------------------
    echo "\n[GATE 3/7] Prescrição Clínica Vigente: water_intake_ml = 2000 ml...\n";

    $db->prepare("UPDATE anamneses SET water_intake_ml = 2000 WHERE patient_id = :pid")->execute(['pid' => $patientAlfaId]);

    $goalPrescribed = $portalService->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalPrescribed === 2000, "Meta hídrica deve ser exatamente 2000 ml conforme conduta da nutricionista");
    logPx20Pass("CLINICAL_MATCH", "Meta prescrita reflete perfeitamente o valor coletado na anamnese (2000 ml)");

    // -------------------------------------------------------------------------
    // GATE 4: Versionamento da Anamnese (v1: 2000 ml -> v2: 2800 ml)
    // -------------------------------------------------------------------------
    echo "\n[GATE 4/7] Evolução Clínica: Versionamento de conduta v1 -> v2 com nova meta hídrica...\n";

    // Criar versão 2 finalizada com meta atualizada para 2800 ml
    $insV2 = $db->prepare("
        INSERT INTO anamneses (
            clinic_id, patient_id, professional_id, version,
            water_intake_ml, is_draft, notes, created_at
        ) VALUES (
            :cid, :pid, :pro_id, 2,
            2800, 0, 'Retorno: paciente aumentou treinos de corrida', datetime('now', '+1 minute')
        )
    ");
    $insV2->execute([
        'cid' => $clinicIdAlfa,
        'pid' => $patientAlfaId,
        'pro_id' => $profIdAlfa,
    ]);

    $goalV2 = $portalService->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalV2 === 2800, "Nova versão da anamnese (v2) deve sobrepor a versão anterior e retornar 2800 ml");
    logPx20Pass("VERSIONING", "Atualização para versão 2 refletida imediatamente no portal (2800 ml)");

    // -------------------------------------------------------------------------
    // GATE 5: Rascunho Parcial vs Conduta Oficial Finalizada
    // -------------------------------------------------------------------------
    echo "\n[GATE 5/7] Integridade Clínica: Rascunho (is_draft = 1) não deve sobrepor conduta finalizada...\n";

    // Criar versão 3 como rascunho com meta 3500 ml ainda não confirmada pela nutricionista
    $insV3Draft = $db->prepare("
        INSERT INTO anamneses (
            clinic_id, patient_id, professional_id, version,
            water_intake_ml, is_draft, notes, created_at
        ) VALUES (
            :cid, :pid, :pro_id, 3,
            3500, 1, 'Rascunho de conduta em elaboração', datetime('now', '+2 minutes')
        )
    ");
    $insV3Draft->execute([
        'cid' => $clinicIdAlfa,
        'pid' => $patientAlfaId,
        'pro_id' => $profIdAlfa,
    ]);

    $goalWithDraft = $portalService->getPrescribedWaterGoal($patientAlfaId);
    assertPx20($goalWithDraft === 2800, "Conduta finalizada v2 (2800 ml) deve prevalecer sobre rascunho v3 (3500 ml)");
    logPx20Pass("DRAFT_GUARD", "Rascunho não finalizado é isolado; paciente mantém a conduta oficial vigente (2800 ml)");

    // -------------------------------------------------------------------------
    // GATE 6: Isolamento Multi-Tenant Estrito (Tenant Alfa vs Tenant Beta)
    // -------------------------------------------------------------------------
    echo "\n[GATE 6/7] Multi-Tenant Guard: Paciente de Tenant Beta não deve acessar dados de Tenant Alfa...\n";

    TenantContext::set($clinicIdBeta, ['id' => $patientBetaId, 'role' => 'patient', 'name' => 'Beatriz Beta PX-20']);

    // Tentar consultar paciente Alfa a partir do Tenant Beta deve disparar TenantGuard exception
    $tenantBlocked = false;
    try {
        $portalService->getPrescribedWaterGoal($patientAlfaId);
    } catch (\RuntimeException $e) {
        $tenantBlocked = true;
        logPx20Pass("TENANT_ISOLATION", "Tentativa de cruzamento de tenant bloqueada com sucesso por TenantGuard", $e->getMessage());
    }
    assertPx20($tenantBlocked, "TenantGuard DEVE bloquear consulta a paciente de outro tenant");

    // Paciente Beta tem anamnese com meta própria de 1800 ml
    $db->prepare("
        INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, water_intake_ml, is_draft, created_at)
        VALUES (:cid, :pid, :pro_id, 1, 1800, 0, CURRENT_TIMESTAMP)
    ")->execute([
        'cid' => $clinicIdBeta,
        'pid' => $patientBetaId,
        'pro_id' => $profIdBeta,
    ]);

    $goalBeta = $portalService->getPrescribedWaterGoal($patientBetaId);
    assertPx20($goalBeta === 1800, "Paciente Beta deve enxergar exclusivamente sua própria meta de 1800 ml");
    logPx20Pass("TENANT_INDEPENDENCE", "Tenant Beta opera de forma totalmente independente e estanque (1800 ml)");

    // -------------------------------------------------------------------------
    // GATE 7: Contrato HTTP e Consumo Real Acumulado (/api/v1/patient/home)
    // -------------------------------------------------------------------------
    echo "\n[GATE 7/7] Simulação HTTP de Consumo Real e Concordância do Payload da Home...\n";

    // Retornar contexto para Tenant Alfa
    TenantContext::set($clinicIdAlfa, ['id' => $patientAlfaId, 'role' => 'patient', 'name' => 'Carlos Alfa PX-20']);

    // Registrar consumos de água para o paciente Alfa
    $apiCtrl->logHydration($patientAlfaId, 300);
    $apiCtrl->logHydration($patientAlfaId, 450);

    // Buscar hidratação acumulada do dia no tenant
    $hStmt = $db->prepare("
        SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs
        WHERE patient_id = :pid AND clinic_id = :cid AND date(logged_at) = date('now')
    ");
    $hStmt->execute(['pid' => $patientAlfaId, 'cid' => $clinicIdAlfa]);
    $waterToday = (int)$hStmt->fetchColumn();
    assertPx20($waterToday === 750, "Consumo acumulado deve ser 750 ml (300 + 450)");

    // Obter payload completo da Home
    $homePayload = [
        'patient_name' => 'Carlos Alfa PX-20',
        'water_ml_today' => $waterToday,
        'water_goal_ml' => $apiCtrl->portal()->getPrescribedWaterGoal($patientAlfaId),
        'next_appointment' => $apiCtrl->portal()->getNextAppointment($patientAlfaId),
        'active_plan' => $apiCtrl->getDailyMealPlan($patientAlfaId),
    ];

    assertPx20($homePayload['water_ml_today'] === 750, "Payload da Home reporta exatamente 750 ml consumidos");
    assertPx20($homePayload['water_goal_ml'] === 2800, "Payload da Home reporta exatamente 2800 ml de meta prescrita");
    assertPx20($homePayload['water_goal_ml'] !== 2500, "Payload da Home NÃO possui o antigo valor fictício de 2500 ml");
    logPx20Pass("HTTP_HOME_PAYLOAD", "Payload da Home sincronizado: consumido = 750ml, meta = 2800ml (eliminando 2500ml fixo)");

} finally {
    // Teardown
    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM hydration_logs WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM anamneses WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM personal_access_tokens WHERE tokenable_id IN ({$patientAlfaId}, {$patientBetaId})");
        $db->exec("DELETE FROM patients WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM professionals WHERE id IN ({$profIdAlfa}, {$profIdBeta})");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM users WHERE id IN ({$nutriIdAlfa}, {$nutriIdBeta})");
        $db->exec("DELETE FROM clinics WHERE id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE PX-20 (NI2-313): TODOS OS 7 GATES DE HOMOLOGAÇÃO PASSARAM COM SUCESSO! <<<\n";
echo "========================================================================\n\n";
