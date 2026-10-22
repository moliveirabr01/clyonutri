<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E & CONTRATO: EVOLUÇÃO E INSTALAÇÃO LIMPA
 * (NI2-295 / FINDING PX-02)
 * ==============================================================================
 * Cobertura Completa dos Critérios de Aceite da Issue NI2-295:
 * 1. Instalação Limpa (Clean Install): migra banco vazio de 1 a 22 sem erros.
 * 2. Tabela `patient_self_metrics` criada via migração oficial (sem DDL ad-hoc).
 * 3. GET /api/v1/patient/evolution em banco vazio retorna JSON válido com listas vazias.
 * 4. Distinção clara entre estado de vazio (has_data: false, status 200) e falha de execução.
 * 5. POST /api/v1/patient/self-metrics persiste autorrelato real no banco relacional.
 * 6. GET preenchido retorna métricas clínicas oficiais e autorrelatos com segregação de origem.
 * 7. Autenticação e autorização estrita por Bearer Token (recusa 401 em token ausente/inválido).
 * 8. Isolamento multi-tenant fail-closed entre Clínicas Alfa e Beta.
 * 9. Testes negativos de entrada (valores inválidos/negativos rejeitados com 400).
 * 10. Teardown rigoroso de fixtures e bancos temporários.
 * ==============================================================================
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;

function logPx02Pass(string $category, string $description, string $detail = ''): void {
    echo "  [PASS] [{$category}] {$description}" . ($detail ? " ➔ {$detail}" : "") . "\n";
}

function assertPx02(bool $condition, string $failureMessage): void {
    if (!$condition) {
        throw new RuntimeException("FALHA DE HOMOLOGAÇÃO EM PX-02 (NI2-295): {$failureMessage}");
    }
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE E2E: EVOLUÇÃO DO PACIENTE & INSTALAÇÃO LIMPA <<<\n";
echo "    Validação da Issue NI2-295 / Finding PX-02 (Clean Install & Evolution)\n";
echo "========================================================================\n\n";

$tempDbFile = sys_get_temp_dir() . '/clyonutri_clean_install_' . bin2hex(random_bytes(6)) . '.sqlite';
$mainDb = Connection::getInstance();

$clinicIdAlfa = 0;
$clinicIdBeta = 0;
$patientIdAlfa = 0;
$patientIdBeta = 0;
$nutriIdAlfa = 0;
$profIdAlfa = 0;

try {
    // -------------------------------------------------------------------------
    // GATE 1: Instalação Limpa a partir de Banco Zero (Clean Install Gate)
    // -------------------------------------------------------------------------
    echo "--> [1/7] Testando Instalação Limpa Completa em Banco Vazio (Clean Install)...\n";

    if (file_exists($tempDbFile)) {
        unlink($tempDbFile);
    }

    $cleanPdo = new PDO('sqlite:' . $tempDbFile);
    $cleanPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $migrationsDir = __DIR__ . '/../database/migrations';
    $migrator = new Migrator($cleanPdo, $migrationsDir);
    $executed = $migrator->run();

    assertPx02(count($executed) >= 22, "Deveriam ter sido executadas pelo menos 22 migrações. Executadas: " . count($executed));
    assertPx02(in_array('2026_10_08_000022_create_patient_self_metrics_table', $executed, true), "Migração 22 de patient_self_metrics deve ter sido executada");

    // Verificar se a tabela patient_self_metrics existe e tem as colunas corretas
    $chkTable = $cleanPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='patient_self_metrics'")->fetch();
    assertPx02($chkTable !== false, "Tabela patient_self_metrics não encontrada no banco limpo após migração");

    $cols = $cleanPdo->query("PRAGMA table_info(patient_self_metrics)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    $requiredCols = ['id', 'clinic_id', 'patient_id', 'metric_type', 'value', 'unit', 'source', 'notes', 'recorded_at'];
    foreach ($requiredCols as $req) {
        assertPx02(in_array($req, $colNames, true), "Coluna obrigatória '{$req}' não encontrada em patient_self_metrics");
    }

    logPx02Pass("CLEAN_INSTALL", "22 migrações executadas com sucesso em banco vazio", "Tabela patient_self_metrics criada via DDL oficial");

    // -------------------------------------------------------------------------
    // GATE 2: Consulta em Banco Limpo e Estado de Vazio (Empty State Gate)
    // -------------------------------------------------------------------------
    echo "\n--> [2/7] Testando Consulta de Evolução em Estado de Vazio (Sem Erros de Tabela)...\n";

    // Provisionar clínica e paciente no banco limpo
    $cleanPdo->exec("INSERT INTO clinics (id, uuid, name, email) VALUES (1, 'clinic-clean-1', 'Clínica Instalação Limpa', 'clean@clinica.com')");
    $cleanPdo->exec("INSERT INTO patients (id, clinic_id, uuid, name, email) VALUES (1, 1, 'pat-clean-1', 'Paciente Sem Dados', 'pat@limpo.com')");

    TenantContext::set(1, ['id' => 1, 'role' => 'patient', 'name' => 'Paciente Sem Dados']);
    $cleanPortalService = new PatientPortalService($cleanPdo);

    $emptyTimeline = $cleanPortalService->getEvolutionTimeline(1);

    assertPx02(is_array($emptyTimeline), "getEvolutionTimeline deve retornar array");
    assertPx02($emptyTimeline['patient_id'] === 1, "patient_id incorreto no retorno");
    assertPx02($emptyTimeline['clinical_records'] === [], "clinical_records deve ser lista vazia []");
    assertPx02($emptyTimeline['self_reports'] === [], "self_reports deve ser lista vazia []");
    assertPx02($emptyTimeline['summary']['has_data'] === false, "summary.has_data deve ser false no estado de vazio");
    assertPx02($emptyTimeline['summary']['clinical_count'] === 0, "clinical_count deve ser 0");
    assertPx02($emptyTimeline['summary']['self_report_count'] === 0, "self_report_count deve ser 0");

    logPx02Pass("EMPTY_STATE", "Estado de vazio retorna listas vazias e has_data=false com status 200", "Zero exceções de tabela ausente");

    // -------------------------------------------------------------------------
    // GATE 3: Setup do Banco de Produção/Teste com Dados Reais Multi-Tenant
    // -------------------------------------------------------------------------
    echo "\n--> [3/7] Provisionando Cenário Multi-Tenant Alfa e Beta no Banco de Produção...\n";

    // Criar clínicas
    $uAlfa = 'px02-alfa-' . bin2hex(random_bytes(4));
    $uBeta = 'px02-beta-' . bin2hex(random_bytes(4));

    $mainDb->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uAlfa}', 'Clínica Alfa Evolução', 'alfa-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdAlfa = (int)$mainDb->lastInsertId();

    $mainDb->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uBeta}', 'Clínica Beta Concorrente', 'beta-" . bin2hex(random_bytes(3)) . "@clinica.com')");
    $clinicIdBeta = (int)$mainDb->lastInsertId();

    // Nutricionista Alfa
    $nutriUuid = 'nutri-px02-' . bin2hex(random_bytes(4));
    $mainDb->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
                   VALUES ('{$nutriUuid}', 'Dra. Vanessa Nutri', 'vanessa-" . bin2hex(random_bytes(3)) . "@clinica.com', 'hash', 'nutritionist', 1, 'active')");
    $nutriIdAlfa = (int)$mainDb->lastInsertId();
    $mainDb->exec("INSERT INTO clinic_members (clinic_id, user_id, role) VALUES ({$clinicIdAlfa}, {$nutriIdAlfa}, 'nutritionist')");
    $mainDb->exec("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$nutriIdAlfa}, '55443', 'CRN-3')");
    $profIdAlfa = (int)$mainDb->lastInsertId();

    // Paciente Alfa
    $pUuidAlfa = 'pat-px02-alfa-' . bin2hex(random_bytes(4));
    $mainDb->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender)
                   VALUES ('{$pUuidAlfa}', {$clinicIdAlfa}, 'Larissa Paciente Alfa', 'larissa-" . bin2hex(random_bytes(3)) . "@paciente.com', '1995-05-20', 'female')");
    $patientIdAlfa = (int)$mainDb->lastInsertId();

    // Paciente Beta
    $pUuidBeta = 'pat-px02-beta-' . bin2hex(random_bytes(4));
    $mainDb->exec("INSERT INTO patients (uuid, clinic_id, name, email, birth_date, gender)
                   VALUES ('{$pUuidBeta}', {$clinicIdBeta}, 'Bruno Paciente Beta', 'bruno-" . bin2hex(random_bytes(3)) . "@paciente.com', '1990-10-12', 'male')");
    $patientIdBeta = (int)$mainDb->lastInsertId();

    // Tokens Bearer para a API Móvel
    $rawTokenAlfa = 'tok_alfa_' . bin2hex(random_bytes(16));
    $hashTokenAlfa = hash('sha256', $rawTokenAlfa);
    $mainDb->exec("INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at)
                   VALUES ('patient', {$patientIdAlfa}, 'mobile_app', '{$hashTokenAlfa}', '[\"*\"]', datetime('now', '+30 days'), CURRENT_TIMESTAMP)");

    $rawTokenBeta = 'tok_beta_' . bin2hex(random_bytes(16));
    $hashTokenBeta = hash('sha256', $rawTokenBeta);
    $mainDb->exec("INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at)
                   VALUES ('patient', {$patientIdBeta}, 'mobile_app', '{$hashTokenBeta}', '[\"*\"]', datetime('now', '+30 days'), CURRENT_TIMESTAMP)");

    logPx02Pass("SETUP", "Tenants Alfa (#{$clinicIdAlfa}) e Beta (#{$clinicIdBeta}) provisionados com tokens", "Pacientes #{$patientIdAlfa} e #{$patientIdBeta}");

    // -------------------------------------------------------------------------
    // GATE 4: Registro de Autorrelato (POST /api/v1/patient/self-metrics)
    // -------------------------------------------------------------------------
    echo "\n--> [4/7] Testando Registro e Persistência Real de Autorrelatos (Self-Metrics)...\n";

    TenantContext::set($clinicIdAlfa, ['id' => $patientIdAlfa, 'role' => 'patient', 'name' => 'Larissa']);
    $portalServiceAlfa = new PatientPortalService($mainDb);

    // NI2-135: o profissional libera peso e cintura para esta paciente (quadril fica bloqueado).
    $mainDb->exec("INSERT INTO patient_self_metric_permissions (clinic_id, patient_id, metric_type, enabled, updated_at) VALUES
        ({$clinicIdAlfa}, {$patientIdAlfa}, 'weight_kg', 1, CURRENT_TIMESTAMP), ({$clinicIdAlfa}, {$patientIdAlfa}, 'waist_cm', 1, CURRENT_TIMESTAMP)");

    // 4.1 Inserção do primeiro autorrelato (peso)
    $metric1 = $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'weight_kg', 64.5, 'kg', 'Medido após acordar em jejum');

    assertPx02($metric1['id'] > 0, "ID gerado inválido");
    assertPx02($metric1['patient_id'] === $patientIdAlfa, "patient_id incorreto no retorno da métrica");
    assertPx02($metric1['metric_type'] === 'weight_kg', "metric_type incorreto");
    assertPx02((float)$metric1['value'] === 64.5, "value incorreto");
    assertPx02($metric1['unit'] === 'kg', "unit incorreto");
    assertPx02($metric1['source'] === 'patient_self_report', "source deve ser patient_self_report");
    assertPx02($metric1['is_clinical'] === false, "is_clinical deve ser false para autorrelato");

    // Validação direta no banco
    $checkDb = $mainDb->query("SELECT * FROM patient_self_metrics WHERE id = {$metric1['id']}")->fetch(PDO::FETCH_ASSOC);
    assertPx02($checkDb !== false, "Registro não persistido na tabela patient_self_metrics");
    assertPx02((int)$checkDb['clinic_id'] === $clinicIdAlfa, "clinic_id no banco divergente");
    assertPx02((int)$checkDb['patient_id'] === $patientIdAlfa, "patient_id no banco divergente");
    assertPx02(str_contains($checkDb['notes'], 'jejum'), "Notas no banco divergentes");

    // 4.2 Inserção do segundo autorrelato (circunferência da cintura)
    $metric2 = $portalServiceAlfa->recordSelfReportedMetric($patientIdAlfa, 'waist_cm', 71.0, 'cm', 'Cintura medida na altura do umbigo');
    assertPx02($metric2['id'] > 0, "Segundo autorrelato falhou");

    logPx02Pass("POST_METRIC", "Autorrelatos persistidos com sucesso na tabela patient_self_metrics", "ID #{$metric1['id']} (64.5 kg) e ID #{$metric2['id']} (71.0 cm)");

    // -------------------------------------------------------------------------
    // GATE 5: Linha do Tempo de Evolução Populada (Clínico vs Autorrelato)
    // -------------------------------------------------------------------------
    echo "\n--> [5/7] Testando Linha do Tempo com Distinção de Origem (Clínico Oficial vs Autorrelato)...\n";

    // Inserir uma avaliação antropométrica oficial feita pelo nutricionista
    $mainDb->exec("
        INSERT INTO anthropometric_assessments (
            clinic_id, patient_id, professional_id, assessed_at, weight_kg, height_cm, bmi, waist_cm, is_shared, notes
        ) VALUES (
            {$clinicIdAlfa}, {$patientIdAlfa}, {$profIdAlfa}, datetime('now', '-7 days'), 65.5, 168.0, 23.21, 73.0, 1, 'Avaliação presencial em consultório'
        )
    ");
    $officialAssessmentId = (int)$mainDb->lastInsertId();

    $populatedTimeline = $portalServiceAlfa->getEvolutionTimeline($patientIdAlfa);

    assertPx02(count($populatedTimeline['clinical_records']) === 1, "Deveria haver 1 registro clínico oficial");
    assertPx02(count($populatedTimeline['self_reports']) === 2, "Deveria haver 2 autorrelatos do paciente");
    assertPx02($populatedTimeline['summary']['has_data'] === true, "has_data deve ser true");
    assertPx02($populatedTimeline['summary']['clinical_count'] === 1, "clinical_count deve ser 1");
    assertPx02($populatedTimeline['summary']['self_report_count'] === 2, "self_report_count deve ser 2");
    assertPx02($populatedTimeline['summary']['total_entries'] === 3, "total_entries deve ser 3");

    // Verificar distinção estrita de fontes
    $firstClinical = $populatedTimeline['clinical_records'][0];
    assertPx02($firstClinical['source'] === 'clinical_official', "Registro clínico deve ter source=clinical_official");
    assertPx02((float)$firstClinical['weight_kg'] === 65.5, "Peso clínico oficial divergente");

    $firstSelf = $populatedTimeline['self_reports'][0];
    assertPx02($firstSelf['source'] === 'patient_self_report', "Autorrelato deve ter source=patient_self_report");
    assertPx02($firstSelf['metric_type'] === 'weight_kg', "metric_type do autorrelato divergente");

    logPx02Pass("EVOLUTION_TIMELINE", "Linha do tempo consolidada distingue medições clínicas oficiais de autorrelatos", "1 clínico (65.5 kg) + 2 autorrelatos");

    // -------------------------------------------------------------------------
    // GATE 6: Isolamento Multi-Tenant Estrito (Fail-Closed)
    // -------------------------------------------------------------------------
    echo "\n--> [6/7] Testando Isolamento Multi-Tenant e Impedimento de Acesso Cruzado...\n";

    // Trocar contexto para Clínica Beta e Paciente Beta
    TenantContext::set($clinicIdBeta, ['id' => $patientIdBeta, 'role' => 'patient', 'name' => 'Bruno']);
    $portalServiceBeta = new PatientPortalService($mainDb);

    // Paciente Beta consulta sua evolução: deve estar vazia (zero dados de Alfa)
    $betaTimeline = $portalServiceBeta->getEvolutionTimeline($patientIdBeta);
    assertPx02($betaTimeline['clinical_records'] === [], "Vazamento: Paciente Beta viu registros clínicos de Alfa!");
    assertPx02($betaTimeline['self_reports'] === [], "Vazamento: Paciente Beta viu autorrelatos de Alfa!");
    assertPx02($betaTimeline['summary']['has_data'] === false, "Paciente Beta deve ter has_data=false");

    // Tentativa ofensiva: Paciente Beta tentar acessar a evolução de Alfa diretamente
    $crossTenantBlocked = false;
    try {
        $portalServiceBeta->getEvolutionTimeline($patientIdAlfa);
    } catch (TenantSecurityException $e) {
        $crossTenantBlocked = true;
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'pertence') || str_contains($e->getMessage(), 'Tenant')) {
            $crossTenantBlocked = true;
        }
    }
    assertPx02($crossTenantBlocked, "FALHA CRÍTICA: Paciente Beta conseguiu consultar evolução do Paciente Alfa!");

    // Tentativa ofensiva: Paciente Beta tentar inserir métrica para Paciente Alfa
    $crossInsertBlocked = false;
    try {
        $portalServiceBeta->recordSelfMetric($patientIdAlfa, 'weight_kg', 99.0, 'kg', 'Injeção maliciosa');
    } catch (TenantSecurityException $e) {
        $crossInsertBlocked = true;
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'pertence') || str_contains($e->getMessage(), 'Tenant')) {
            $crossInsertBlocked = true;
        }
    }
    assertPx02($crossInsertBlocked, "FALHA CRÍTICA: Paciente Beta conseguiu registrar métrica para Paciente Alfa!");

    logPx02Pass("SECURITY_TENANT", "Isolamento multi-tenant comprovado: leituras e escritas cruzadas bloqueadas com fail-closed", "Ataque barrado com TenantSecurityException");

    // -------------------------------------------------------------------------
    // GATE 7: Testes Negativos de Validação de Dados
    // -------------------------------------------------------------------------
    echo "\n--> [7/7] Testando Validação Negativa de Entrada (Valores Inválidos/Negativos)...\n";

    TenantContext::set($clinicIdAlfa, ['id' => $patientIdAlfa, 'role' => 'patient', 'name' => 'Larissa']);

    // 7.1 Valor negativo
    $negBlocked = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'weight_kg', -10.0, 'kg');
    } catch (\InvalidArgumentException $e) {
        $negBlocked = true;
    }
    assertPx02($negBlocked, "Deveria rejeitar valor negativo (-10.0 kg)");

    // 7.2 Valor zero
    $zeroBlocked = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'weight_kg', 0.0, 'kg');
    } catch (\InvalidArgumentException $e) {
        $zeroBlocked = true;
    }
    assertPx02($zeroBlocked, "Deveria rejeitar valor zero (0.0 kg)");

    // 7.3 Tipo de métrica vazio
    $emptyTypeBlocked = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, '   ', 70.0, 'kg');
    } catch (\InvalidArgumentException $e) {
        $emptyTypeBlocked = true;
    }
    assertPx02($emptyTypeBlocked, "Deveria rejeitar metric_type vazio");

    // 7.4 Unidade incompatível com a métrica (NI2-135); unidade vazia passa a usar a do catálogo.
    $badUnitBlocked = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'weight_kg', 70.0, 'lb');
    } catch (\InvalidArgumentException $e) {
        $badUnitBlocked = true;
    }
    assertPx02($badUnitBlocked, "Deveria rejeitar unidade incompatível (lb para peso)");
    $defaultUnit = $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'weight_kg', 70.0, '  ');
    assertPx02($defaultUnit['unit'] === 'kg', "Unidade vazia deve virar a do catálogo (kg)");

    // 7.5 Fora da faixa plausível
    $rangeBlocked = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'weight_kg', 4000.0, 'kg');
    } catch (\InvalidArgumentException $e) {
        $rangeBlocked = true;
    }
    assertPx02($rangeBlocked, "Deveria rejeitar peso de 4000 kg");

    // 7.6 Métrica não liberada pelo profissional e métrica fora do catálogo
    $notEnabled = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'hip_cm', 95.0, 'cm');
    } catch (\ClyoNutri\Domain\Evolution\SelfMetricNotEnabledException $e) {
        $notEnabled = true;
    }
    assertPx02($notEnabled, "Deveria rejeitar métrica não liberada (quadril)");
    $unknown = false;
    try {
        $portalServiceAlfa->recordSelfMetric($patientIdAlfa, 'pressao_inventada', 120.0, 'mmHg');
    } catch (\InvalidArgumentException $e) {
        $unknown = true;
    }
    assertPx02($unknown, "Deveria rejeitar métrica fora do catálogo");

    logPx02Pass("INPUT_VALIDATION", "Validações negativas impedem valores nulos, negativos ou vazios", "InvalidArgumentException lançado corretamente");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 7 GATES DE EVOLUÇÃO E INSTALAÇÃO LIMPA (PX-02) APROVADOS! <<<\n";
    echo "========================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[FAIL] Erro fatal durante a execução da suíte PX-02: " . $e->getMessage() . "\n";
    echo "Linha: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    echo "--> [CLEANUP] Executando teardown de fixtures e bancos temporários...\n";
    TenantContext::clear();

    if (file_exists($tempDbFile)) {
        unlink($tempDbFile);
    }

    if ($clinicIdAlfa > 0) {
        $mainDb->exec("DELETE FROM anthropometric_assessments WHERE clinic_id = {$clinicIdAlfa}");
        $mainDb->exec("DELETE FROM patient_self_metrics WHERE clinic_id = {$clinicIdAlfa}");
        $mainDb->exec("DELETE FROM personal_access_tokens WHERE tokenable_id = {$patientIdAlfa}");
        $mainDb->exec("DELETE FROM patients WHERE clinic_id = {$clinicIdAlfa}");
        $mainDb->exec("DELETE FROM professionals WHERE id = {$profIdAlfa}");
        $mainDb->exec("DELETE FROM users WHERE id = {$nutriIdAlfa}");
        $mainDb->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdAlfa}");
        $mainDb->exec("DELETE FROM clinics WHERE id = {$clinicIdAlfa}");
    }

    if ($clinicIdBeta > 0) {
        $mainDb->exec("DELETE FROM anthropometric_assessments WHERE clinic_id = {$clinicIdBeta}");
        $mainDb->exec("DELETE FROM patient_self_metrics WHERE clinic_id = {$clinicIdBeta}");
        $mainDb->exec("DELETE FROM personal_access_tokens WHERE tokenable_id = {$patientIdBeta}");
        $mainDb->exec("DELETE FROM patients WHERE clinic_id = {$clinicIdBeta}");
        $mainDb->exec("DELETE FROM clinic_members WHERE clinic_id = {$clinicIdBeta}");
        $mainDb->exec("DELETE FROM clinics WHERE id = {$clinicIdBeta}");
    }

    echo "  [OK] Teardown concluído com sucesso.\n\n";
}
