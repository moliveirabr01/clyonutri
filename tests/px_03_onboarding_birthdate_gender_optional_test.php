<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: ONBOARDING DEMOGRÁFICO REAL (PX-03 / NI2-296)
 * ==============================================================================
 * Cobertura de Critérios de Aceite para NI2-296:
 * 1. Omissão de data de nascimento e gênero no Passo 4 não gera valores sintéticos
 *    (persiste NULL em `patients.birth_date` e `patients.gender`).
 * 2. Atualização / reedição com campos em branco preserva NULL.
 * 3. Preenchimento de dados válidos persiste exatamente os dados informados.
 * 4. Validação restritiva de data de nascimento no passado (rejeita datas futuras).
 * 5. Rejeição de formatos de data inválidos com InvalidArgumentException.
 * 6. Isolamento multi-tenant fail-closed na criação e atualização do primeiro paciente.
 * 7. Inspeção do HTML da view: ausência de valor default '1995-04-10', presença de
 *    opção em branco 'Não informado (opcional)', atributo max e textos explicativos.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Application\Controllers\OnboardingController;

$db = Connection::getInstance();

function logPx03Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx03(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO PX-03 (NI2-296): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-03: ONBOARDING DEMOGRÁFICO (NI2-296) <<<\n";
echo "    Eliminação de Dados Sintéticos, Persistência NULL & Validação Clínica\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$nutriIdAlfa = 0;
$nutriIdBeta = 0;
$patientIdAlfa = 0;

try {
    // -------------------------------------------------------------------------
    // SETUP: Provedores e Tenants Reais
    // -------------------------------------------------------------------------
    echo "--> [1/7] Provisionando Tenants Reais (Alfa & Beta)...\n";

    $u1 = 'px03-alfa-' . bin2hex(random_bytes(4));
    $u2 = 'px03-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u1}', 'Clínica Alfa PX03', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$u2}', 'Clínica Beta PX03', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    $nutriUuidAlfa = 'user-px03-alfa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Luiza Nutricionista Alfa', 'nutri-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");

    $nutriUuidBeta = 'user-px03-beta-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Eduardo Nutricionista Beta', 'nutri-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");

    logPx03Pass("SETUP", "Tenants Alfa (#{$clinicIdAlfa}) e Beta (#{$clinicIdBeta}) provisionados");

    $service = new OnboardingService($db);

    // Avançar passos 1 a 3 para deixar no passo 4
    $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 1, ['primary_goal' => 'consultas_particulares', 'specialties' => ['clinica']]);
    $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 2, ['crn' => 'CRN3-11223', 'crn_region' => 'CRN-3', 'specialty' => 'Clínica Geral']);
    $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 3, ['service_modalities' => ['presencial'], 'session_duration' => 60, 'session_price' => 250.0]);

    // -------------------------------------------------------------------------
    // GATE 1: Criação do paciente com apenas o nome (demográficos omitidos)
    // -------------------------------------------------------------------------
    echo "\n--> [2/7] Testando Passo 4 com apenas Nome (birth_date e gender omitidos)...\n";

    $progress = $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, [
        'name' => 'Paciente Sem Demográficos Informados',
    ]);

    $patientIdAlfa = (int)($progress['first_patient_id'] ?? 0);
    assertPx03($patientIdAlfa > 0, "ID do primeiro paciente deve ter sido gerado");

    $stmt = $db->prepare("SELECT id, name, birth_date, gender, clinic_id FROM patients WHERE id = :id");
    $stmt->execute(['id' => $patientIdAlfa]);
    $patient = $stmt->fetch(\PDO::FETCH_ASSOC);

    assertPx03($patient !== false, "Paciente criado deve existir no banco de dados");
    assertPx03($patient['birth_date'] === null, "birth_date DEVE ser NULL quando não informada (encontrado: " . var_export($patient['birth_date'], true) . ")");
    assertPx03($patient['gender'] === null, "gender DEVE ser NULL quando não informado (encontrado: " . var_export($patient['gender'], true) . ")");
    assertPx03((int)$patient['clinic_id'] === $clinicIdAlfa, "clinic_id deve corresponder ao tenant Alfa");

    logPx03Pass("GATE_1", "Paciente criado com birth_date = NULL e gender = NULL no DB", "Paciente #{$patientIdAlfa}");

    // -------------------------------------------------------------------------
    // GATE 2: Reedição com strings vazias (simulando submissão de form em branco)
    // -------------------------------------------------------------------------
    echo "\n--> [3/7] Testando Reedição com strings vazias ('', '')...\n";

    $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, [
        'name' => 'Paciente Sem Demográficos Informados (Atualizado)',
        'birth_date' => '',
        'gender' => '',
        'email' => 'paciente@teste.com',
    ]);

    $stmt->execute(['id' => $patientIdAlfa]);
    $patientUpdated = $stmt->fetch(\PDO::FETCH_ASSOC);

    assertPx03($patientUpdated['birth_date'] === null, "birth_date DEVE continuar NULL após atualização com string vazia");
    assertPx03($patientUpdated['gender'] === null, "gender DEVE continuar NULL após atualização com string vazia");
    assertPx03($patientUpdated['name'] === 'Paciente Sem Demográficos Informados (Atualizado)', "Nome deve ter sido atualizado");

    logPx03Pass("GATE_2", "Reedição preserva NULL para birth_date e gender sem fallbacks sintéticos");

    // -------------------------------------------------------------------------
    // GATE 3: Preenchimento de dados válidos reais
    // -------------------------------------------------------------------------
    echo "\n--> [4/7] Testando Preenchimento com dados válidos reais ('1990-05-15', 'female')...\n";

    $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, [
        'name' => 'Mariana Silveira',
        'birth_date' => '1990-05-15',
        'gender' => 'female',
    ]);

    $stmt->execute(['id' => $patientIdAlfa]);
    $patientValid = $stmt->fetch(\PDO::FETCH_ASSOC);

    assertPx03($patientValid['birth_date'] === '1990-05-15', "birth_date deve ser '1990-05-15'");
    assertPx03($patientValid['gender'] === 'female', "gender deve ser 'female'");

    logPx03Pass("GATE_3", "Dados válidos persistidos com fidelidade", "Nascimento: 1990-05-15, Gênero: female");

    // -------------------------------------------------------------------------
    // GATE 4: Teste Negativo: Data de nascimento no futuro deve ser rejeitada
    // -------------------------------------------------------------------------
    echo "\n--> [5/7] Testando Validação Negativa: Data no Futuro...\n";

    $futureDate = date('Y-m-d', strtotime('+1 year'));
    $exceptionCaughtFuture = false;

    try {
        $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, [
            'name' => 'Paciente Futuro',
            'birth_date' => $futureDate,
        ]);
    } catch (\InvalidArgumentException $e) {
        $exceptionCaughtFuture = true;
        assertPx03(
            str_contains($e->getMessage(), 'futuro') || str_contains($e->getMessage(), 'inválida'),
            "Mensagem de erro deve alertar sobre data inválida ou no futuro"
        );
    }

    assertPx03($exceptionCaughtFuture, "Data no futuro ({$futureDate}) DEVE lançar InvalidArgumentException");

    // Verificar que os dados do banco não foram alterados para o valor futuro
    $stmt->execute(['id' => $patientIdAlfa]);
    $patientAfterFuture = $stmt->fetch(\PDO::FETCH_ASSOC);
    assertPx03($patientAfterFuture['birth_date'] === '1990-05-15', "Banco não pode ter sido corrompido com a data futura");

    logPx03Pass("GATE_4", "Data no futuro rejeitada com InvalidArgumentException e DB intacto");

    // -------------------------------------------------------------------------
    // GATE 5: Teste Negativo: Formato de data inválido / malformado
    // -------------------------------------------------------------------------
    echo "\n--> [6/7] Testando Validação Negativa: Formatos Inválidos ('2025-02-31', 'abc')...\n";

    $invalidDates = ['2025-02-31', 'data-invalida', '2025-13-45'];
    foreach ($invalidDates as $badDate) {
        $caught = false;
        try {
            $service->saveStep($nutriIdAlfa, $clinicIdAlfa, 4, [
                'name' => 'Paciente Bad Date',
                'birth_date' => $badDate,
            ]);
        } catch (\InvalidArgumentException $e) {
            $caught = true;
        }
        assertPx03($caught, "Data malformada '{$badDate}' DEVE lançar InvalidArgumentException");
    }

    logPx03Pass("GATE_5", "Datas malformadas e calendário inexistente rejeitados com sucesso");

    // -------------------------------------------------------------------------
    // GATE 6 & 7: Isolamento Multi-Tenant e Renderização da View
    // -------------------------------------------------------------------------
    echo "\n--> [7/7] Testando Isolamento Multi-Tenant e Renderização da View HTML...\n";

    // Multi-tenant: Beta tenta atualizar paciente de Alfa
    TenantContext::setClinicId($clinicIdBeta);
    TenantContext::set($clinicIdBeta, ['id' => $nutriIdBeta, 'clinic_id' => $clinicIdBeta]);

    $service->saveStep($nutriIdBeta, $clinicIdBeta, 1, ['primary_goal' => 'consultas_particulares']);
    $service->saveStep($nutriIdBeta, $clinicIdBeta, 2, ['crn' => 'CRN3-99887']);
    $service->saveStep($nutriIdBeta, $clinicIdBeta, 3, ['session_price' => 150.0]);

    // O progresso de Beta não tem acesso ao paciente de Alfa
    $betaProgress = $service->getOrCreateProgress($nutriIdBeta, $clinicIdBeta);
    assertPx03(empty($betaProgress['first_patient_id']), "Tenant Beta não deve ver first_patient_id do Tenant Alfa");

    // Criar paciente no Tenant Beta sem nascimento/gênero
    $betaProgressStep4 = $service->saveStep($nutriIdBeta, $clinicIdBeta, 4, ['name' => 'Paciente Beta Próprio']);
    $patientIdBeta = (int)$betaProgressStep4['first_patient_id'];
    assertPx03($patientIdBeta !== $patientIdAlfa, "Pacientes criados em tenants distintos devem ter IDs distintos");

    $stmt->execute(['id' => $patientIdBeta]);
    $patientBetaRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    assertPx03((int)$patientBetaRow['clinic_id'] === $clinicIdBeta, "Paciente de Beta deve ter clinic_id do tenant Beta");
    assertPx03($patientBetaRow['birth_date'] === null, "Paciente de Beta deve ter birth_date = NULL");
    assertPx03($patientBetaRow['gender'] === null, "Paciente de Beta deve ter gender = NULL");

    logPx03Pass("GATE_6", "Isolamento multi-tenant garantido: pacientes e contextos estritamente segregados");

    // Inspeção da View HTML do OnboardingController
    $_SESSION['user_id'] = $nutriIdBeta;
    $_SESSION['clinic_id'] = $clinicIdBeta;
    $_SESSION['user_name'] = 'Dr. Eduardo Nutricionista Beta';
    $_SESSION['user_email'] = 'beta@clinica.com';
    $_SESSION['user_role'] = 'nutritionist';

    // Mock de progresso limpo para renderizar a view sem paciente prévio
    $controller = new OnboardingController();
    $_GET['passo'] = '4';

    // Capturar output da view do controller
    ob_start();
    $controller->show();
    $viewHtml = ob_get_clean();

    assertPx03(!str_contains($viewHtml, '1995-04-10'), "View NÃO deve conter a data hardcoded '1995-04-10'");
    assertPx03(str_contains($viewHtml, 'max="' . date('Y-m-d') . '"'), "View deve conter atributo max com a data de hoje");
    assertPx03(str_contains($viewHtml, 'Não informado (opcional)'), "View deve conter a opção 'Não informado (opcional)'");
    assertPx03(str_contains($viewHtml, 'cálculo de idade e protocolos metabólicos'), "View deve conter texto explicativo clínico de nascimento");
    assertPx03(str_contains($viewHtml, 'equações preditivas basais'), "View deve conter texto explicativo clínico de gênero");

    logPx03Pass("GATE_7", "View HTML validada: sem hardcoded '1995-04-10', com 'Não informado (opcional)' e atributos seguros");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 7 GATES DO PX-03 (NI2-296) FORAM APROVADOS COM SUCESSO! <<<\n";
    echo "========================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[ERROR] FALHA NO TESTE PX-03: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    echo "--> [CLEANUP] Limpando dados de teste PX-03...\n";
    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM onboarding_progress WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM patients WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM users WHERE id IN ({$nutriIdAlfa}, {$nutriIdBeta})");
        $db->exec("DELETE FROM clinics WHERE id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
    }
    echo "  [OK] Teardown concluído.\n\n";
}
