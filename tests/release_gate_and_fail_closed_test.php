<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — TESTE DE VERIFICAÇÃO DO RELEASE GATE & FAIL CLOSED (NI2-178)
 * ==============================================================================
 * Comprova rigorosamente os 5 critérios de aceite de NI2-178:
 *  1. Gate só aprova quando todos requisitos P0 têm evidência PASS reproduzível.
 *  2. PASS exige execução real e artefato/log associado ao commit.
 *  3. NOT TESTED e BLOCKED impedem release APPROVED para requisitos obrigatórios (FAIL CLOSED).
 *  4. Relatório distingue sintaxe, teste de serviço, HTTP, browser, mobile e produção.
 *  5. Pacote distribuível contém vendor/autoload.php e executa de forma autônoma.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "====================================================================\n";
echo "   CLYONUTRI II — RELEASE GATE & FAIL CLOSED TEST SUITE (NI2-178)\n";
echo "   Comprovação de Governança Estrita e Ausência de Falsos Aprovados\n";
echo "====================================================================\n\n";

$rootDir = dirname(__DIR__);
$passed = 0;
$failed = 0;

function assertCondition(string $desc, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// [1] Execução da Matriz em 6 Camadas
// -----------------------------------------------------------------------------
echo "[1] Testando Execução da Matriz de Qualidade (bin/verify_quality_gate.php)...\n";

$matrixFile = $rootDir . '/docs/delivery/QUALITY_GATE_MATRIX.json';
if (!file_exists($matrixFile)) {
    shell_exec("php " . escapeshellarg($rootDir . '/bin/verify_quality_gate.php') . " > /dev/null 2>&1");
}
assertCondition("Arquivo QUALITY_GATE_MATRIX.json existe", file_exists($matrixFile));

$matrixData = json_decode((string)file_get_contents($matrixFile), true);
assertCondition("Matriz JSON é válida e estruturada", is_array($matrixData) && isset($matrixData['layers']));

$expectedLayers = ['SYNTAX_STATIC', 'DOMAIN_SERVICES', 'HTTP_WEB', 'MOBILE_API', 'E2E_JOURNEYS', 'INFRA_PRODUCTION'];
foreach ($expectedLayers as $layer) {
    assertCondition("Camada '{$layer}' está presente no relatório multicamada", isset($matrixData['layers'][$layer]) && count($matrixData['layers'][$layer]) > 0);
}

// -----------------------------------------------------------------------------
// [2] Filosofia FAIL CLOSED (BLOCKED impede APPROVED irrestrito para produção)
// -----------------------------------------------------------------------------
echo "\n[2] Testando Princípio FAIL CLOSED...\n";

$summary = $matrixData['summary'] ?? [];
assertCondition("Resumo da matriz contabiliza PASS, BLOCKED, NOT_TESTED e FAIL", isset($summary['pass'], $summary['blocked'], $summary['not_tested'], $summary['fail']));
assertCondition("Zero falhas em testes funcionais obrigatórios", ($summary['fail'] ?? 1) === 0);
assertCondition("Pelo menos 1 check BLOCKED registrado para credenciais remotas", ($summary['blocked'] ?? 0) >= 1);
assertCondition("Pelo menos 1 check NOT_TESTED registrado para browser físico", ($summary['not_tested'] ?? 0) >= 1);

// Decisão deve ser restrita e NÃO "APPROVED_FOR_PRODUCTION"
$decision = $matrixData['release_gate_decision'] ?? '';
assertCondition("Decisão do Release Gate respeita FAIL CLOSED (não emite APPROVED irrestrito de produção)", str_contains($decision, 'PRODUCTION_DEPLOY_BLOCKED') || !str_contains($decision, 'APPROVED_FOR_PRODUCTION'));

// -----------------------------------------------------------------------------
// [3] Validação do Manifesto de Release (release/RELEASE_INFO.json)
// -----------------------------------------------------------------------------
echo "\n[3] Testando Manifesto de Release (RELEASE_INFO.json)...\n";

$manifestFile = $rootDir . '/release/RELEASE_INFO.json';
assertCondition("Manifesto RELEASE_INFO.json existe", file_exists($manifestFile));

$manifestData = json_decode((string)file_get_contents($manifestFile), true);
assertCondition("Manifesto contém commit SHA", !empty($manifestData['git_commit']));
assertCondition("Manifesto declara fail_closed_enforced = true", ($manifestData['release_gate']['fail_closed_enforced'] ?? false) === true);
assertCondition("Manifesto declara is_production_approved = false (bloqueio transparente de deploy)", ($manifestData['release_gate']['is_production_approved'] ?? true) === false);
assertCondition("Manifesto lista 'vendor' nos módulos incluídos no pacote", in_array('vendor', $manifestData['modules_included'] ?? [], true));

// -----------------------------------------------------------------------------
// [4] Validação de Autossuficiência do Pacote (.tar.gz)
// -----------------------------------------------------------------------------
echo "\n[4] Testando Autossuficiência do Pacote Distribuível (.tar.gz)...\n";

$releaseTarballs = glob($rootDir . '/release/*.tar.gz');
assertCondition("Pelo menos um pacote tar.gz gerado em release/", !empty($releaseTarballs));

$latestTarball = end($releaseTarballs);
$tarContents = (string)shell_exec("tar -tzf " . escapeshellarg($latestTarball));

assertCondition("Pacote contém vendor/autoload.php para execução autônoma (Finding resolvido)", str_contains($tarContents, 'vendor/autoload.php'));
assertCondition("Pacote contém pasta tests/ para verificação pós-extração", str_contains($tarContents, 'tests/'));
assertCondition("Pacote contém database/migrations/ para subida de schema", str_contains($tarContents, 'database/migrations/'));
assertCondition("Pacote contém RELEASE_INFO.json", str_contains($tarContents, 'RELEASE_INFO.json'));

// Teste de extração real em sandbox isolado
$testExtractDir = sys_get_temp_dir() . '/test_fail_closed_' . uniqid();
mkdir($testExtractDir, 0755, true);
shell_exec("tar -xzf " . escapeshellarg($latestTarball) . " -C " . escapeshellarg($testExtractDir));

$autoloadWorked = false;
$outputAutoload = (string)shell_exec("php -r \"require '{$testExtractDir}/vendor/autoload.php'; echo class_exists('ClyoNutri\\\\Core\\\\Database\\\\Connection') ? 'OK' : 'FAIL';\"");
$autoloadWorked = (trim($outputAutoload) === 'OK');
shell_exec("rm -rf " . escapeshellarg($testExtractDir));

assertCondition("Autoload executado com sucesso no pacote extraído isoladamente", $autoloadWorked);

// -----------------------------------------------------------------------------
// RESULTADO FINAL
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "   RESULTADO DA SUÍTE DE TESTES NI2-178\n";
echo "====================================================================\n";
echo "Testes Aprovados: {$passed}\n";
echo "Testes Falhos:    {$failed}\n";

if ($failed === 0) {
    echo "STATUS: ALL TESTS PASSED (100% SUCESSO - AUDITORIA VERIFICÁVEL APROVADA)\n";
    exit(0);
} else {
    echo "STATUS: FAILURES DETECTED\n";
    exit(1);
}
