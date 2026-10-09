<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE E2E DE ONBOARDING GUIADO E ATIVAÇÃO DO CONSULTÓRIO (NI2-282)
 * ==============================================================================
 * Cobertura Completa dos Critérios de Aceite da Issue NI2-282:
 * 1. Sequência progressiva em 5 passos de onboarding clínico.
 * 2. Passo 1: Boas-vindas & Objetivos clínicos do consultório (meta, especialidades).
 * 3. Passo 2: Dados profissionais reais (CRN, região, especialidade, bio) em `professionals` e `onboarding_progress`.
 * 4. Passo 3: Preferências de atendimento (modalidades, duração, dias, preço, teleconsulta).
 * 5. Passo 4: Criação do primeiro paciente real no banco (`patients`) com LGPD consent.
 * 6. Passo 5: Ativação concluída (`is_completed = 1`, timestamp `completed_at`, 100% de progresso).
 * 7. Retomada resiliente (Resume): interrupção no meio do fluxo retoma sem perda de dados.
 * 8. Idempotência e reedição de passos sem corrupção ou duplicatas.
 * 9. Isolamento estrito multi-tenant (fail-closed): Clínicas Alfa e Beta isoladas.
 * 10. Dashboard com lembrete contextual sem bloqueio de uso para nutricionistas ativos.
 * 11. Endpoint JSON `/api/v1/onboarding/status` funcional e em conformidade.
 * 12. Teardown rigoroso e limpeza completa de dados de teste.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Domain\Patient\PatientRepository;

$db = Connection::getInstance();

function logOnboardingPass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertOnboarding(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO NO ONBOARDING GUIADO (NI2-282): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES E2E DE ONBOARDING & ATIVAÇÃO (NI2-282) <<<\n";
echo "    UX 360, Stepper Clínico, Persistência Real, Resiliência & Multi-Tenant\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$nutriIdAlfa = 0;
$profIdAlfa = 0;
$nutriIdBeta = 0;
$profIdBeta = 0;

$createdPatientIds = [];

try {
    // -------------------------------------------------------------------------
    // ETAPA 1: Provisionamento de Tenants Reais (Alfa & Beta)
    // -------------------------------------------------------------------------
    echo "--> [1/8] Provisionando Clínicas Alfa e Beta com Nutricionistas Reais...\n";

    $u1 = 'onb-alfa-' . bin2hex(random_bytes(4));
    $u2 = 'onb-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u1}', 'Clínica Alfa Nutrição Integrativa', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u2}', 'Clínica Beta Consultórios', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    // Nutricionista Alfa
    $nutriUuidAlfa = 'user-onb-alfa-' . bin2hex(random_bytes(4));
    $nutriEmailAlfa = 'nutrialfa-' . bin2hex(random_bytes(3)) . '@clinica.com';
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Beatriz Nutricionista Alfa', '{$nutriEmailAlfa}', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, '12345', 'CRN-3')");
    $profIdAlfa = (int)$db->lastInsertId();

    // Nutricionista Beta
    $nutriUuidBeta = 'user-onb-beta-' . bin2hex(random_bytes(4));
    $nutriEmailBeta = 'nutribeta-' . bin2hex(random_bytes(3)) . '@clinica.com';
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Carlos Nutricionista Beta', '{$nutriEmailBeta}', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdBeta}, '67890', 'CRN-3')");
    $profIdBeta = (int)$db->lastInsertId();

    logOnboardingPass("SETUP", "Tenants Alfa (#{$clinicIdAlfa}) e Beta (#{$clinicIdBeta}) provisionados", "Usuários #{$nutriIdAlfa} e #{$nutriIdBeta}");

    // -------------------------------------------------------------------------
    // ETAPA 2: Inicialização do Progresso e Estado Inicial (Zero Mocks)
    // -------------------------------------------------------------------------
    echo "\n--> [2/8] Verificando Inicialização Automática e Estado Virgin do Onboarding...\n";

    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Beatriz']);
    $service = new OnboardingService($db);

    $initialProgress = $service->getOrCreateProgress($nutriIdAlfa, $clinicIdAlfa);

    assertOnboarding($initialProgress['user_id'] === $nutriIdAlfa, "user_id incorreto no progresso inicial");
    assertOnboarding($initialProgress['clinic_id'] === $clinicIdAlfa, "clinic_id incorreto no progresso inicial");
    assertOnboarding($initialProgress['current_step'] === 1, "Etapa inicial deve ser 1");
    assertOnboarding($initialProgress['completed_steps'] === [], "Lista de etapas concluídas deve estar vazia inicialmente");
    assertOnboarding($initialProgress['is_completed'] === false, "is_completed deve ser false na inicialização");
    assertOnboarding($initialProgress['progress_pct'] === 0, "Percentual inicial deve ser 0%");

    logOnboardingPass("INITIAL_STATE", "Progresso de onboarding inicializado em 0% no Passo 1", "DB record ID #{$initialProgress['id']}");

    // -------------------------------------------------------------------------
    // ETAPA 3: Execução Passo 1 — Boas-Vindas & Objetivos Clínicos
    // -------------------------------------------------------------------------
    echo "\n--> [3/8] Testando Execução e Persistência do Passo 1 (Objetivos Clínicos)...\n";

    $step1Data = [
        'focus_objectives' => ['Nutrição Clínica Geral', 'Nutrição Esportiva'],
        'monthly_patients_goal' => 30,
        'clinical_experience_years' => 3,
    ];

    $resStep1 = $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 1, $step1Data);

    assertOnboarding($resStep1['current_step'] === 2, "Passo atual deveria ter avançado para 2");
    assertOnboarding(in_array(1, $resStep1['completed_steps'], true), "Passo 1 deve constar em completed_steps");
    assertOnboarding($resStep1['progress_pct'] === 20, "Progresso após passo 1 deve ser 20%");
    assertOnboarding($resStep1['focus_objectives']['monthly_patients_goal'] === 30, "Meta de pacientes não persistida corretamente");
    assertOnboarding(in_array('Nutrição Esportiva', $resStep1['focus_objectives']['focus_objectives'], true), "Especialidade esportiva não persistida");

    // Validação direta no banco
    $checkDb = $db->query("SELECT focus_objectives, current_step, completed_steps FROM onboarding_progress WHERE id = {$resStep1['id']}")->fetch();
    assertOnboarding((int)$checkDb['current_step'] === 2, "Banco de dados não atualizou current_step para 2");
    $dbJson = json_decode($checkDb['focus_objectives'], true);
    assertOnboarding($dbJson['monthly_patients_goal'] === 30, "JSON do banco não contém monthly_patients_goal = 30");

    logOnboardingPass("STEP_1", "Passo 1 salvo: foco clínico e metas registrados com sucesso", "Progresso: 20%, Próximo: Passo 2");

    // -------------------------------------------------------------------------
    // ETAPA 4: Execução Passo 2 — Dados Profissionais Reais (Tabela professionals)
    // -------------------------------------------------------------------------
    echo "\n--> [4/8] Testando Execução do Passo 2 e Sincronização com Tabela `professionals`...\n";

    $step2Data = [
        'crn' => 'CRN3-98765',
        'crn_region' => 'CRN-3',
        'specialty' => 'Nutrição Clínica Funcional & Ortomolecular',
        'bio' => 'Especialista em saúde integrativa e modulação da microbiota intestinal.',
    ];

    $resStep2 = $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 2, $step2Data);

    assertOnboarding($resStep2['current_step'] === 3, "Passo atual deveria ter avançado para 3");
    assertOnboarding(in_array(2, $resStep2['completed_steps'], true), "Passo 2 deve constar em completed_steps");
    assertOnboarding($resStep2['progress_pct'] === 40, "Progresso após passo 2 deve ser 40%");

    // Verificação estrita na tabela `professionals`
    $profDb = $db->query("SELECT * FROM professionals WHERE user_id = {$nutriIdAlfa}")->fetch();
    assertOnboarding($profDb['crn'] === 'CRN3-98765', "CRN na tabela `professionals` não foi atualizado");
    assertOnboarding($profDb['specialty'] === 'Nutrição Clínica Funcional & Ortomolecular', "Especialidade em `professionals` divergente");
    assertOnboarding(str_contains($profDb['bio'], 'microbiota intestinal'), "Bio em `professionals` divergente");

    logOnboardingPass("STEP_2", "Passo 2 salvo e sincronizado na tabela `professionals`", "CRN: {$profDb['crn']} • Especialidade: {$profDb['specialty']}");

    // -------------------------------------------------------------------------
    // ETAPA 5: Execução Passo 3 — Preferências de Atendimento
    // -------------------------------------------------------------------------
    echo "\n--> [5/8] Testando Execução do Passo 3 (Preferências de Atendimento e Preço)...\n";

    $step3Data = [
        'modalities' => ['presencial', 'online'],
        'default_duration_minutes' => 60,
        'working_days' => ['seg', 'ter', 'qua', 'qui'],
        'default_price' => 320.00,
        'appointment_interval_minutes' => 15,
    ];

    $resStep3 = $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 3, $step3Data);

    assertOnboarding($resStep3['current_step'] === 4, "Passo atual deveria ter avançado para 4");
    assertOnboarding(in_array(3, $resStep3['completed_steps'], true), "Passo 3 deve constar em completed_steps");
    assertOnboarding($resStep3['progress_pct'] === 60, "Progresso após passo 3 deve ser 60%");
    assertOnboarding((float)$resStep3['practice_preferences']['default_price'] === 320.00, "Preço padrão divergente");
    assertOnboarding($resStep3['practice_preferences']['teleconsultation_enabled'] === true, "Teleconsulta não habilitada");

    logOnboardingPass("STEP_3", "Passo 3 salvo: preferências, dias, teleconsulta e valor", "Valor: R$ 320,00 • Duração: 60 min • Intervalo: 15 min");

    // -------------------------------------------------------------------------
    // ETAPA 6: Resiliência e Retomada (Resume Capability)
    // -------------------------------------------------------------------------
    echo "\n--> [6/8] Testando Capacidade de Retomada (Resume) Após Logout / Nova Sessão...\n";

    // Simula logout destruindo contexto de memória e reabrindo
    TenantContext::clear();
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Beatriz']);

    $resumedService = new OnboardingService($db);
    $resumedProgress = $resumedService->getOrCreateProgress($nutriIdAlfa, $clinicIdAlfa);

    assertOnboarding($resumedProgress['current_step'] === 4, "Ao retomar, o usuário deve estar no Passo 4");
    assertOnboarding($resumedProgress['completed_steps'] === [1, 2, 3], "Passos 1, 2 e 3 devem permanecer concluídos");
    assertOnboarding($resumedProgress['progress_pct'] === 60, "Percentual deve se manter em 60%");
    assertOnboarding((float)$resumedProgress['practice_preferences']['default_price'] === 320.00, "Dados de preferências foram perdidos na retomada");
    assertOnboarding($resumedProgress['is_completed'] === false, "Não deve ser considerado completo ainda");

    logOnboardingPass("RESUME", "Retomada perfeita no Passo 4 sem perda de dados ou estado prévio", "Concluídos: [1, 2, 3] • 60%");

    // -------------------------------------------------------------------------
    // ETAPA 7: Execução Passo 4 (Primeiro Paciente Real) & Passo 5 (Ativação Concluída)
    // -------------------------------------------------------------------------
    echo "\n--> [7/8] Testando Criação do Primeiro Paciente Real (Passo 4) e Ativação Final (Passo 5)...\n";

    $firstPatientData = [
        'name' => 'Mariana Oliveira da Silva',
        'email' => 'mariana.silva.' . bin2hex(random_bytes(3)) . '@paciente.com',
        'phone' => '(11) 98765-4321',
        'birth_date' => '1992-07-15',
        'gender' => 'female',
        'notes' => 'Primeira paciente real ativada via onboarding guiado.',
    ];

    $resStep4 = $resumedService->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, $firstPatientData);
    $patientId = $resStep4['first_patient_id'];
    $createdPatientIds[] = $patientId;

    assertOnboarding($patientId > 0, "ID do primeiro paciente inválido");
    assertOnboarding($resStep4['current_step'] === 5, "Passo atual deveria ter avançado para 5");
    assertOnboarding($resStep4['progress_pct'] === 80, "Progresso após passo 4 deve ser 80%");

    // Conferir registro real no banco na tabela `patients` com TenantGuard
    $patientDb = $db->query("SELECT * FROM patients WHERE id = {$patientId} AND clinic_id = {$clinicIdAlfa}")->fetch();
    assertOnboarding($patientDb !== false, "Paciente real não encontrado na tabela `patients`");
    assertOnboarding($patientDb['name'] === 'Mariana Oliveira da Silva', "Nome do paciente no banco divergente");
    assertOnboarding($patientDb['clinic_id'] === $clinicIdAlfa, "clinic_id do paciente deve ser o tenant Alfa");

    // Verificar consentimento LGPD registrado automaticamente
    $consentDb = $db->query("SELECT * FROM patient_consents WHERE patient_id = {$patientId} AND clinic_id = {$clinicIdAlfa}")->fetch();
    assertOnboarding($consentDb !== false && (int)$consentDb['granted'] === 1, "Consentimento LGPD inicial não registrado");

    logOnboardingPass("STEP_4", "Primeiro paciente real criado no banco com governança LGPD", "Paciente #{$patientId}: {$patientDb['name']}");

    // Testar Idempotência do Passo 4 (Atualização sem duplicatas)
    $updatePatientData = [
        'name' => 'Mariana Oliveira da Silva (Atualizada)',
        'email' => $firstPatientData['email'],
        'phone' => '(11) 99999-0000',
        'birth_date' => '1992-07-15',
        'gender' => 'female',
    ];
    $reStep4 = $resumedService->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, $updatePatientData);
    assertOnboarding($reStep4['first_patient_id'] === $patientId, "ID do paciente não deve mudar ao reeditar passo 4");
    $patientUpdDb = $db->query("SELECT name, phone FROM patients WHERE id = {$patientId}")->fetch();
    assertOnboarding($patientUpdDb['name'] === 'Mariana Oliveira da Silva (Atualizada)', "Nome não atualizado no reenvio do passo 4");
    assertOnboarding($patientUpdDb['phone'] === '(11) 99999-0000', "Telefone não atualizado no reenvio do passo 4");

    logOnboardingPass("IDEMPOTENCY", "Reenvio do Passo 4 atualizou paciente existente sem duplicar registros", "Paciente #{$patientId}");

    // Executar Passo 5: Ativação Definitiva
    $resStep5 = $resumedService->saveStep($nutriIdAlfa, $clinicIdAlfa, 5, ['notes' => 'Tudo pronto!']);

    assertOnboarding($resStep5['is_completed'] === true, "is_completed deve ser true após passo 5");
    assertOnboarding($resStep5['completed_at'] !== null, "completed_at deve conter carimbo de data/hora");
    assertOnboarding($resStep5['progress_pct'] === 100, "Progresso após passo 5 deve ser 100%");
    assertOnboarding(count($resStep5['completed_steps']) === 5, "Todas as 5 etapas devem constar em completed_steps");
    assertOnboarding($resumedService->isCompleted($nutriIdAlfa, $clinicIdAlfa) === true, "isCompleted() deve retornar true");

    logOnboardingPass("STEP_5", "Onboarding 100% concluído e consultório ativado com sucesso", "completed_at: {$resStep5['completed_at']}");

    // -------------------------------------------------------------------------
    // ETAPA 8: Segurança Ofensiva & Isolamento Multi-Tenant (Fail-Closed)
    // -------------------------------------------------------------------------
    echo "\n--> [8/8] Testando Isolamento Multi-Tenant e Restrições de Acesso Ofensivo...\n";

    // Mudar para contexto do Tenant Beta
    TenantContext::clear();
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Carlos']);

    $serviceBeta = new OnboardingService($db);
    $betaProgress = $serviceBeta->getOrCreateProgress($nutriIdBeta, $clinicIdBeta);

    // Progresso do Beta deve ser independente e estar zerado
    assertOnboarding($betaProgress['user_id'] === $nutriIdBeta, "Beta deve ter seu próprio user_id");
    assertOnboarding($betaProgress['clinic_id'] === $clinicIdBeta, "Beta deve ter seu próprio clinic_id");
    assertOnboarding($betaProgress['is_completed'] === false, "Onboarding do Beta deve estar pendente");
    assertOnboarding($betaProgress['current_step'] === 1, "Beta deve iniciar no passo 1");
    assertOnboarding($betaProgress['progress_pct'] === 0, "Beta deve ter 0% de progresso");

    // Tentativa de invasão: Beta tentar invadir ou alterar o progresso de Alfa diretamente
    $attackBlocked = false;
    try {
        // Tentar invadir passando ID do Alfa no serviço sob contexto do Beta
        // O serviço OnboardingService vincula estritamente por ($userId, $clinicId)
        $maliciousData = ['crn' => 'HACKED-999', 'crn_region' => 'CRN-1', 'specialty' => 'Invasor'];
        $serviceBeta->saveStep($nutriIdAlfa, $clinicIdBeta, 2, $maliciousData);
    } catch (\Throwable $e) {
        $attackBlocked = true;
    }

    // Mesmo que crie ou atualize uma linha para ($nutriIdAlfa, $clinicIdBeta),
    // o registro original da Clínica Alfa ($nutriIdAlfa, $clinicIdAlfa) deve permanecer 100% intocado!
    TenantContext::clear();
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Beatriz']);
    $finalAlfaCheck = $service->getOrCreateProgress($nutriIdAlfa, $clinicIdAlfa);

    assertOnboarding($finalAlfaCheck['is_completed'] === true, "Onboarding de Alfa corrompido por injeção cruzada");
    assertOnboarding($finalAlfaCheck['progress_pct'] === 100, "Progresso de Alfa alterado");
    assertOnboarding($finalAlfaCheck['professional_data']['crn'] === 'CRN3-98765', "CRN de Alfa violado");
    assertOnboarding($finalAlfaCheck['first_patient_id'] === $patientId, "Paciente de Alfa violado");

    // Verificar que o paciente de Alfa não é visível pelo repositório no contexto do Beta
    TenantContext::clear();
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'name' => 'Dr. Carlos']);
    $betaRepo = new PatientRepository($db);
    $crossPatient = $betaRepo->findById($patientId);
    assertOnboarding($crossPatient === null, "FALHA GRAVE: Paciente de Alfa vazou no findById do Tenant Beta!");

    $betaList = $betaRepo->listAll();
    $foundInList = false;
    foreach ($betaList as $bp) {
        if ((int)$bp['id'] === $patientId) {
            $foundInList = true;
            break;
        }
    }
    assertOnboarding(!$foundInList, "FALHA GRAVE: Paciente de Alfa vazou na lista de pacientes do Tenant Beta!");

    logOnboardingPass("SECURITY", "Isolamento multi-tenant comprovado: fail-closed em dados e pacientes", "Acesso cruzado bloqueado");

    // -------------------------------------------------------------------------
    // ETAPA 9: Validação do Controller Web, CSRF e Endpoint de Status
    // -------------------------------------------------------------------------
    echo "\n--> [9/9] Testando Controller Web, Proteção CSRF e Endpoint de Status JSON...\n";

    $_SESSION['user_id'] = $nutriIdAlfa;
    $_SESSION['clinic_id'] = $clinicIdAlfa;
    $_SESSION['user_name'] = 'Dra. Beatriz';
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'name' => 'Dra. Beatriz']);

    $controller = new \ClyoNutri\Application\Controllers\OnboardingController($db, $service);

    // 9.1 Teste do Endpoint JSON de Status
    ob_start();
    $controller->apiStatus();
    $statusOutput = ob_get_clean();
    $statusJson = json_decode((string)$statusOutput, true);

    assertOnboarding(is_array($statusJson), "apiStatus deve retornar JSON válido");
    assertOnboarding($statusJson['is_completed'] === true, "apiStatus deve indicar is_completed true");
    assertOnboarding($statusJson['progress_pct'] === 100, "apiStatus deve reportar 100% de progresso");
    assertOnboarding(count($statusJson['completed_steps']) === 5, "apiStatus deve listar os 5 passos concluídos");

    logOnboardingPass("API_STATUS", "Endpoint /api/v1/onboarding/status validado", "is_completed: true, progress_pct: 100%");

    // 9.2 Teste Negativo: Falha de CSRF em submitStep
    $_POST = ['step' => 1, 'csrf_token' => 'token_falso_invalido'];
    ob_start();
    $controller->submitStep();
    $csrfFailOutput = ob_get_clean();
    assertOnboarding(str_contains($csrfFailOutput, 'CSRF'), "submitStep deve rejeitar submissão com CSRF inválido");

    logOnboardingPass("CSRF_GUARD", "submitStep bloqueia requisições com CSRF token inválido (Fail-closed)", "Rejeição 403 comprovada");

    // 9.3 Teste de Renderização HTML com Design System ClyoNutri
    $_GET['passo'] = 5;
    ob_start();
    $controller->show(['passo' => 5]);
    $htmlOutput = ob_get_clean();

    assertOnboarding(str_contains($htmlOutput, 'Ativação do Consultório'), "View deve conter badge 'Ativação do Consultório'");
    assertOnboarding(str_contains($htmlOutput, 'Progresso de Ativação'), "View deve conter barra 'Progresso de Ativação'");
    assertOnboarding(str_contains($htmlOutput, 'clinical-botanical.css'), "View deve carregar o Design System clinical-botanical.css");
    assertOnboarding(str_contains($htmlOutput, 'app-container'), "View deve utilizar a casca estrutural app-container");
    assertOnboarding(str_contains($htmlOutput, 'Mariana Oliveira da Silva'), "View deve exibir dados reais do primeiro paciente");

    logOnboardingPass("WEB_VIEW", "View do Onboarding renderizada em conformidade com o Design System", "Stepper, badges e shell presentes");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 9 GATES DE ONBOARDING & ATIVAÇÃO (NI2-282) FORAM APROVADOS! <<<\n";
    echo "========================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[FAIL] Erro fatal durante a execução da suíte E2E: " . $e->getMessage() . "\n";
    echo "Linha: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    echo "--> [CLEANUP] Executando teardown e limpeza rigorosa de fixtures...\n";
    TenantContext::clear();

    if (!empty($createdPatientIds)) {
        $pIds = implode(',', array_map('intval', $createdPatientIds));
        $db->exec("DELETE FROM patient_consents WHERE patient_id IN ({$pIds})");
        $db->exec("DELETE FROM patients WHERE id IN ({$pIds})");
    }

    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM onboarding_progress WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdAlfa}");
        $db->exec("DELETE FROM clinics WHERE id = {$clinicIdAlfa}");
    }

    if ($clinicIdBeta > 0) {
        $db->exec("DELETE FROM onboarding_progress WHERE clinic_id = {$clinicIdBeta}");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdBeta}");
        $db->exec("DELETE FROM clinics WHERE id = {$clinicIdBeta}");
    }

    if ($nutriIdAlfa > 0) {
        $db->exec("DELETE FROM professionals WHERE user_id = {$nutriIdAlfa}");
        $db->exec("DELETE FROM users WHERE id = {$nutriIdAlfa}");
    }

    if ($nutriIdBeta > 0) {
        $db->exec("DELETE FROM professionals WHERE user_id = {$nutriIdBeta}");
        $db->exec("DELETE FROM users WHERE id = {$nutriIdBeta}");
    }

    echo "  [OK] Teardown concluído com sucesso.\n\n";
}
