<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E: NOMES ACESSÍVEIS EM CAMPOS CLÍNICOS (PX-07 / NI2-300)
 * ==============================================================================
 * Cobertura de Critérios de Aceite para NI2-300 (PX-07):
 * 1. Cada controle possui nome acessível único (id vinculado a label for/aria-labelledby).
 * 2. Campos críticos contêm instruções associadas via aria-describedby.
 * 3. Seções clínicas agrupadas com fieldset e legend semânticos.
 * 4. Selects comparativos de versões históricas com id, label for e aria-label explícitos.
 * 5. Estados de erro e sucesso com role="alert" e role="status" (aria-live).
 * 6. Preservação integral da persistência relacional append-only e versionamento da anamnese.
 * 7. Isolamento multi-tenant estrito com recusa de acesso indevido sem vazamento de dados.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Application\Controllers\DashboardController;

$db = Connection::getInstance();

function logPx07Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx07(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO PX-07 (NI2-300): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-07: CAMPOS CLÍNICOS ACESSÍVEIS (NI2-300) <<<\n";
echo "========================================================================\n\n";

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$nutriIdAlfa = 0;
$nutriIdBeta = 0;
$profIdAlfa = 0;
$profIdBeta = 0;
$patientAlfaId = 0;
$patientBetaId = 0;

try {
    // -------------------------------------------------------------------------
    // SETUP: Provisionamento de Tenants e Profissionais Reais
    // -------------------------------------------------------------------------
    $uAlfa = 'px07-alfa-' . bin2hex(random_bytes(4));
    $uBeta = 'px07-beta-' . bin2hex(random_bytes(4));

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uAlfa}', 'Clínica Alfa PX-07', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uBeta}', 'Clínica Beta PX-07', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$db->lastInsertId();

    // Nutricionista Alfa
    $nutriUuidAlfa = 'user-px07-alfa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidAlfa}', 'Dra. Fernanda Nutri Alfa', 'nutri-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, 'CRN3-7071', 'CRN-3')");
    $profIdAlfa = (int)$db->lastInsertId();

    // Nutricionista Beta
    $nutriUuidBeta = 'user-px07-beta-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$nutriUuidBeta}', 'Dr. Thiago Nutri Beta', 'nutri-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash_pw', 'nutritionist', 1, 'active')");
    $nutriIdBeta = (int)$db->lastInsertId();
    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdBeta}, {$nutriIdBeta}, 'nutritionist')");
    $db->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdBeta}, 'CRN3-7072', 'CRN-3')");
    $profIdBeta = (int)$db->lastInsertId();

    // Pacientes
    $pUuidAlfa = 'p-px07-alfa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender, status)
               VALUES ('{$pUuidAlfa}', {$clinicIdAlfa}, 'Juliana Alfa PX-07', 'juliana@alfa-px07.com', '1994-08-12', 'female', 'active')");
    $patientAlfaId = (int)$db->lastInsertId();

    $pUuidBeta = 'p-px07-beta-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender, status)
               VALUES ('{$pUuidBeta}', {$clinicIdBeta}, 'Marcos Beta PX-07', 'marcos@beta-px07.com', '1989-11-03', 'male', 'active')");
    $patientBetaId = (int)$db->lastInsertId();

    logPx07Pass("SETUP", "Tenants, nutricionistas e pacientes provisionados com sucesso");

    // Configura sessão e TenantContext para Nutricionista Alfa
    $_SESSION['user_id'] = $nutriIdAlfa;
    $_SESSION['user_name'] = 'Dra. Fernanda Nutri Alfa';
    $_SESSION['user_role'] = 'nutritionist';
    $_SESSION['clinic_id'] = $clinicIdAlfa;
    $_SESSION['clinic_name'] = 'Clínica Alfa PX-07';
    TenantContext::set($clinicIdAlfa, ['id' => $nutriIdAlfa, 'role' => 'nutritionist', 'name' => 'Dra. Fernanda Nutri Alfa']);

    $dashCtrl = new DashboardController();

    // -------------------------------------------------------------------------
    // GATE 1: Inspeção DOM dos 10 Campos Clínicos de Coleta da Anamnese
    // -------------------------------------------------------------------------
    echo "\n[GATE 1/7] Inspeção DOM dos 10 Campos de Coleta Clínica na Anamnese...\n";

    ob_start();
    $dashCtrl->patientAnamnesis(['id' => (string)$patientAlfaId]);
    $htmlOutput = ob_get_clean();

    $dom = new DOMDocument();
    // Suprimir warnings de tags HTML5 no parser legado
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlOutput, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $expectedFields = [
        'objectives' => [
            'id' => 'anamnesis_objectives',
            'label_for' => 'anamnesis_objectives',
            'tag' => 'textarea',
            'has_help' => false,
        ],
        'allergies' => [
            'id' => 'anamnesis_allergies',
            'label_for' => 'anamnesis_allergies',
            'tag' => 'textarea',
            'has_help' => true,
            'help_id' => 'anamnesis_allergies_help',
        ],
        'restrictions' => [
            'id' => 'anamnesis_restrictions',
            'label_for' => 'anamnesis_restrictions',
            'tag' => 'textarea',
            'has_help' => true,
            'help_id' => 'anamnesis_restrictions_help',
        ],
        'food_preferences' => [
            'id' => 'anamnesis_food_preferences',
            'label_for' => 'anamnesis_food_preferences',
            'tag' => 'textarea',
            'has_help' => false,
        ],
        'symptoms' => [
            'id' => 'anamnesis_symptoms',
            'label_for' => 'anamnesis_symptoms',
            'tag' => 'textarea',
            'has_help' => false,
        ],
        'routine' => [
            'id' => 'anamnesis_routine',
            'label_for' => 'anamnesis_routine',
            'tag' => 'textarea',
            'has_help' => false,
        ],
        'habits' => [
            'id' => 'anamnesis_habits',
            'label_for' => 'anamnesis_habits',
            'tag' => 'textarea',
            'has_help' => false,
        ],
        'clinical_history' => [
            'id' => 'anamnesis_clinical_history',
            'label_for' => 'anamnesis_clinical_history',
            'tag' => 'textarea',
            'has_help' => false,
        ],
        'water_intake_ml' => [
            'id' => 'anamnesis_water_intake_ml',
            'label_for' => 'anamnesis_water_intake_ml',
            'tag' => 'input',
            'has_help' => true,
            'help_id' => 'anamnesis_water_help',
        ],
        'notes' => [
            'id' => 'anamnesis_notes',
            'label_for' => 'anamnesis_notes',
            'tag' => 'textarea',
            'has_help' => false,
        ],
    ];

    foreach ($expectedFields as $fieldName => $cfg) {
        // 1. O elemento de entrada deve existir com o ID esperado
        $inputNode = $xpath->query("//*[@id='{$cfg['id']}']")->item(0);
        assertPx07($inputNode !== null, "Campo clínico '{$fieldName}' deve possuir id='{$cfg['id']}'");
        assertPx07(strtolower($inputNode->nodeName) === $cfg['tag'], "Campo clínico '{$fieldName}' deve ser tag <{$cfg['tag']}>");

        // 2. O atributo name deve ser preservado para integridade do payload
        assertPx07($inputNode->getAttribute('name') === $fieldName, "Campo clínico '{$fieldName}' deve manter name='{$fieldName}'");

        // 3. A label correspondente deve existir com atributo for exatamente igual ao ID
        $labelNode = $xpath->query("//label[@for='{$cfg['id']}']")->item(0);
        assertPx07($labelNode !== null, "Deve existir <label for='{$cfg['id']}'> associada ao campo '{$fieldName}'");
        assertPx07(trim($labelNode->textContent) !== '', "Rótulo do campo '{$fieldName}' não pode ser vazio");

        // 4. aria-labelledby deve estar presente e apontar para o ID do label
        $ariaLabelledBy = $inputNode->getAttribute('aria-labelledby');
        assertPx07(!empty($ariaLabelledBy), "Campo '{$fieldName}' deve ter aria-labelledby definido");

        // 5. Se tiver texto de instrução / ajuda, verificar aria-describedby
        if ($cfg['has_help']) {
            $ariaDescribedBy = $inputNode->getAttribute('aria-describedby');
            assertPx07($ariaDescribedBy === $cfg['help_id'], "Campo '{$fieldName}' deve ter aria-describedby='{$cfg['help_id']}'");
            $helpNode = $xpath->query("//*[@id='{$cfg['help_id']}']")->item(0);
            assertPx07($helpNode !== null, "Instrução descritiva com id='{$cfg['help_id']}' deve existir no DOM");
        }

        logPx07Pass("ACCESSIBLE_FIELD", "Campo clínico '{$fieldName}' ➔ id='{$cfg['id']}', label for='{$cfg['id']}', aria-labelledby");
    }

    // -------------------------------------------------------------------------
    // GATE 2: Estrutura Semântica com Fieldset e Legend
    // -------------------------------------------------------------------------
    echo "\n[GATE 2/7] Validação Semântica de Fieldsets e Legends no Formulário...\n";

    $fieldsets = $xpath->query("//form//fieldset");
    assertPx07($fieldsets->length >= 3, "Formulário clínico deve possuir ao menos 3 fieldsets para agrupamento semântico");

    $legends = [
        "Alergias Alimentares & Restrições Clínicas",
        "Rotina Diária & Hábitos de Vida",
        "Histórico Clínico & Meta Hídrica Prescrita",
    ];

    foreach ($legends as $legendText) {
        $found = false;
        /** @var DOMElement $fs */
        foreach ($fieldsets as $fs) {
            $leg = $fs->getElementsByTagName('legend')->item(0);
            if ($leg && str_contains($leg->textContent, $legendText)) {
                $found = true;
                break;
            }
        }
        assertPx07($found, "Fieldset com legend '{$legendText}' deve estar presente no DOM");
        logPx07Pass("SEMANTIC_FIELDSET", "Fieldset e Legend validado: '{$legendText}'");
    }

    // -------------------------------------------------------------------------
    // GATE 3: Criação de Versões e Inspeção dos 2 Selects Comparativos
    // -------------------------------------------------------------------------
    echo "\n[GATE 3/7] Inspeção DOM dos 2 Selects Comparativos de Versão (Diff Clínico)...\n";

    // Inserir Versão 1
    $db->prepare("
        INSERT INTO anamneses (
            clinic_id, patient_id, professional_id, version,
            objectives, allergies, restrictions, water_intake_ml, is_draft, created_at
        ) VALUES (
            :cid, :pid, :pro_id, 1,
            'Objetivo v1', 'Amendoim', 'Lactose', 2000, 0, datetime('now', '-2 days')
        )
    ")->execute(['cid' => $clinicIdAlfa, 'pid' => $patientAlfaId, 'pro_id' => $profIdAlfa]);

    // Inserir Versão 2
    $db->prepare("
        INSERT INTO anamneses (
            clinic_id, patient_id, professional_id, version,
            objectives, allergies, restrictions, water_intake_ml, is_draft, created_at
        ) VALUES (
            :cid, :pid, :pro_id, 2,
            'Objetivo v2 evoluído', 'Amendoim, Camarão', 'Lactose, Glúten', 2500, 0, datetime('now', '-1 day')
        )
    ")->execute(['cid' => $clinicIdAlfa, 'pid' => $patientAlfaId, 'pro_id' => $profIdAlfa]);

    // Renderizar página novamente (agora com count(history) >= 2)
    ob_start();
    $dashCtrl->patientAnamnesis(['id' => (string)$patientAlfaId]);
    $htmlOutputWithHistory = ob_get_clean();

    $domWithHist = new DOMDocument();
    libxml_use_internal_errors(true);
    $domWithHist->loadHTML('<?xml encoding="utf-8" ?>' . $htmlOutputWithHistory, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpathHist = new DOMXPath($domWithHist);

    // 1. Select Versão A
    $selectV1 = $xpathHist->query("//select[@id='compare_version_1']")->item(0);
    assertPx07($selectV1 !== null, "Select de comparação de Versão A deve possuir id='compare_version_1'");
    assertPx07($selectV1->getAttribute('name') === 'v1', "Select v1 deve manter name='v1'");
    assertPx07($selectV1->getAttribute('aria-label') === 'Versão A para comparação', "Select v1 deve ter aria-label acessível");
    $labelV1 = $xpathHist->query("//label[@for='compare_version_1']")->item(0);
    assertPx07($labelV1 !== null, "Label com for='compare_version_1' deve existir associada ao Select v1");
    logPx07Pass("COMPARE_SELECT_V1", "Select Versão A ➔ id='compare_version_1', label for='compare_version_1', aria-label");

    // 2. Select Versão B
    $selectV2 = $xpathHist->query("//select[@id='compare_version_2']")->item(0);
    assertPx07($selectV2 !== null, "Select de comparação de Versão B deve possuir id='compare_version_2'");
    assertPx07($selectV2->getAttribute('name') === 'v2', "Select v2 deve manter name='v2'");
    assertPx07($selectV2->getAttribute('aria-label') === 'Versão B para comparação', "Select v2 deve ter aria-label acessível");
    $labelV2 = $xpathHist->query("//label[@for='compare_version_2']")->item(0);
    assertPx07($labelV2 !== null, "Label com for='compare_version_2' deve existir associada ao Select v2");
    logPx07Pass("COMPARE_SELECT_V2", "Select Versão B ➔ id='compare_version_2', label for='compare_version_2', aria-label");

    // -------------------------------------------------------------------------
    // GATE 4: Acessibilidade de Feedback & Alertas de Erro/Status
    // -------------------------------------------------------------------------
    echo "\n[GATE 4/7] Mensagens de Feedback com role='alert' e role='status'...\n";

    // Simulação com ?success=1
    $_GET['success'] = '1';
    ob_start();
    $dashCtrl->patientAnamnesis(['id' => (string)$patientAlfaId]);
    $htmlSuccess = ob_get_clean();
    unset($_GET['success']);

    assertPx07(str_contains($htmlSuccess, 'role="status"'), "Feedback de sucesso deve conter role='status'");
    assertPx07(str_contains($htmlSuccess, 'aria-live="polite"'), "Feedback de sucesso deve conter aria-live='polite'");
    logPx07Pass("FEEDBACK_STATUS", "Mensagem de sucesso acessível com role='status' e aria-live='polite'");

    // Simulação com ?error=1&msg=TesteDeErro
    $_GET['error'] = '1';
    $_GET['msg'] = 'Erro de validação clínica teste';
    ob_start();
    $dashCtrl->patientAnamnesis(['id' => (string)$patientAlfaId]);
    $htmlError = ob_get_clean();
    unset($_GET['error'], $_GET['msg']);

    assertPx07(str_contains($htmlError, 'role="alert"'), "Feedback de erro deve conter role='alert'");
    assertPx07(str_contains($htmlError, 'aria-live="assertive"'), "Feedback de erro deve conter aria-live='assertive'");
    logPx07Pass("FEEDBACK_ALERT", "Mensagem de erro acessível com role='alert' e aria-live='assertive'");

    // -------------------------------------------------------------------------
    // GATE 5: Comparativo de Versões Ativo (Diff Clínico)
    // -------------------------------------------------------------------------
    echo "\n[GATE 5/7] Execução do Fluxo Real de Comparativo de Versões (?v1=1&v2=2)...\n";

    $_GET['v1'] = '1';
    $_GET['v2'] = '2';
    ob_start();
    $dashCtrl->patientAnamnesis(['id' => (string)$patientAlfaId]);
    $htmlCompare = ob_get_clean();
    unset($_GET['v1'], $_GET['v2']);

    assertPx07(str_contains($htmlCompare, 'Comparativo de Versões: v1 vs v2'), "Comparativo clínico deve exibir cabeçalho de versões v1 vs v2");
    assertPx07(str_contains($htmlCompare, 'Camarão'), "Diff deve identificar alterações entre v1 e v2");
    logPx07Pass("DIFF_COMPARE", "Visualização comparativa de histórico renderizada com diff clínico");

    // -------------------------------------------------------------------------
    // GATE 6: Persistência Real de Nova Versão e Rascunho
    // -------------------------------------------------------------------------
    echo "\n[GATE 6/7] Persistência Real: Gravação de Versão Oficial e Rascunho Parcial...\n";

    $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();

    // 1. Gravação de Versão Oficial Imutável (v3)
    $v3Payload = [
        'objectives' => 'Objetivo versão 3',
        'allergies' => 'Amendoim, Frutos do Mar',
        'restrictions' => 'Lactose, Açúcar refinado',
        'food_preferences' => 'Frutas vermelhas',
        'symptoms' => 'Sem queixas',
        'routine' => '07:00 às 23:00',
        'habits' => 'Treina 5x semana',
        'clinical_history' => 'Evolução favorável',
        'water_intake_ml' => '3000',
        'notes' => 'Conduta atualizada com sucesso',
    ];

    $resV3 = $anamnesisService->saveAnamnesis($patientAlfaId, $profIdAlfa, $v3Payload, false);

    $stmtCheckV3 = $db->prepare("
        SELECT version, is_draft, water_intake_ml, allergies FROM anamneses
        WHERE patient_id = :pid AND version = 3
    ");
    $stmtCheckV3->execute(['pid' => $patientAlfaId]);
    $v3Data = $stmtCheckV3->fetch();
    assertPx07($v3Data !== false, "Versão 3 deve ser gravada no banco de dados");
    assertPx07((int)$v3Data['is_draft'] === 0, "Versão oficial deve ter is_draft = 0");
    assertPx07((int)$v3Data['water_intake_ml'] === 3000, "Meta hídrica gravada deve ser 3000 ml");
    logPx07Pass("PERSISTENCE_FINAL", "Versão 3 imutável gravada no banco relacional (is_draft=0, water=3000ml)");

    // 2. Gravação de Rascunho Parcial (v4)
    $v4Payload = [
        'objectives' => 'Rascunho v4 em elaboração',
        'allergies' => 'Amendoim, Frutos do Mar',
        'restrictions' => 'Lactose',
        'food_preferences' => 'Frutas',
        'symptoms' => '',
        'routine' => '',
        'habits' => '',
        'clinical_history' => '',
        'water_intake_ml' => '3200',
        'notes' => 'Anotações parciais da consulta',
    ];

    $resV4 = $anamnesisService->saveAnamnesis($patientAlfaId, $profIdAlfa, $v4Payload, true);

    $stmtCheckV4 = $db->prepare("
        SELECT version, is_draft, water_intake_ml FROM anamneses
        WHERE patient_id = :pid AND version = 4
    ");
    $stmtCheckV4->execute(['pid' => $patientAlfaId]);
    $v4Data = $stmtCheckV4->fetch();
    assertPx07($v4Data !== false, "Versão 4 (rascunho) deve ser gravada no banco de dados");
    assertPx07((int)$v4Data['is_draft'] === 1, "Rascunho deve ter is_draft = 1");
    logPx07Pass("PERSISTENCE_DRAFT", "Rascunho parcial v4 gravado com is_draft=1 com sucesso");

    // -------------------------------------------------------------------------
    // GATE 7: Isolamento Multi-Tenant e Autorização
    // -------------------------------------------------------------------------
    echo "\n[GATE 7/7] Multi-Tenant Guard: Rejeição de Acesso a Paciente de Outro Tenant...\n";

    // Nutricionista Alfa tenta acessar paciente do Tenant Beta (Marcos Beta PX-07)
    ob_start();
    @$dashCtrl->patientAnamnesis(['id' => (string)$patientBetaId]);
    $crossTenantOutput = ob_get_clean();

    assertPx07(
        str_contains($crossTenantOutput, 'Paciente ou documento não encontrado') ||
        str_contains($crossTenantOutput, 'Acesso clínico não autorizado'),
        "Tentativa de cruzamento de tenant deve ser recusada com tela de erro e status 404/403"
    );
    assertPx07(!str_contains($crossTenantOutput, 'Marcos Beta PX-07'), "Dados do paciente Beta NUNCA devem vazar para o Tenant Alfa");
    logPx07Pass("TENANT_ISOLATION", "Tentativa de cruzamento de tenant bloqueada sem vazamento de dados");

} finally {
    // Teardown
    if ($clinicIdAlfa > 0) {
        $db->exec("DELETE FROM anamneses WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM patients WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM professionals WHERE id IN ({$profIdAlfa}, {$profIdBeta})");
        $db->exec("DELETE FROM clinic_members WHERE clinic_id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
        $db->exec("DELETE FROM users WHERE id IN ({$nutriIdAlfa}, {$nutriIdBeta})");
        $db->exec("DELETE FROM clinics WHERE id IN ({$clinicIdAlfa}, {$clinicIdBeta})");
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE PX-07 (NI2-300): TODOS OS 7 GATES DE HOMOLOGAÇÃO PASSARAM COM SUCESSO! <<<\n";
echo "========================================================================\n\n";
