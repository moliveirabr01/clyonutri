<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bin/hostgator_spike.php';
require_once __DIR__ . '/../bin/cron_scheduler.php';

use ClyoNutri\Infra\HostGatorSpikeValidator;
use ClyoNutri\Infra\HostGatorCronScheduler;
use App\Domain\Anthropometry\AnthropometryCalculator;
use App\Domain\Foods\NutritionCalculator;
use App\Support\Tenancy\TenantManager;

class ReplatformInfrastructureTest
{
    private int $assertions = 0;

    public function run(): void
    {
        echo "== ClyoNutri II: Replatform Infrastructure & HostGator Test Suite ==\n";

        $this->testHostGatorSpikeValidation();
        $this->testCronSchedulerAndHeartbeat();
        $this->testEncryptedBackupAndRestoreRehearsal();
        $this->testAnthropometryCalculator();
        $this->testNutritionCalculatorCanonical100g();
        $this->testTenantManagerIsolation();
        $this->testWebrootAndSecurityHardening();

        echo "\n✓ Todas as {$this->assertions} asserções da suíte de replataforma passaram com 100% de sucesso.\n";
    }

    private function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("FALHA: $message");
        }
        echo "  [PASS] $message\n";
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $expectedStr = var_export($expected, true);
            $actualStr = var_export($actual, true);
            throw new \RuntimeException("FALHA: $message (Esperado: $expectedStr, Recebido: $actualStr)");
        }
        echo "  [PASS] $message\n";
    }

    private function testHostGatorSpikeValidation(): void
    {
        echo "\n--- 1. Spike de Compatibilidade HostGator ---\n";
        $validator = new HostGatorSpikeValidator();
        $report = $validator->run();

        $this->assertTrue($report['is_go'], "Spike deve aprovar o ambiente (is_go == true)");
        $this->assertTrue(empty($report['blockers']), "Nenhum bloqueador crítico na infraestrutura atual");
        $this->assertEquals('PASS', $report['checks']['php_version']['status'], "Versão do PHP compatível (>= 8.3)");
        $this->assertEquals('PASS', $report['checks']['document_root']['status'], "Document Root isolado em public/");
    }

    private function testCronSchedulerAndHeartbeat(): void
    {
        echo "\n--- 2. Agendador (Cron) e Heartbeat de Observabilidade ---\n";
        $scheduler = new HostGatorCronScheduler();
        $report = $scheduler->run();

        $this->assertEquals('RECORDED', $report['heartbeat'], "Heartbeat do scheduler gravado com sucesso");
        $this->assertTrue(isset($report['queue']['status']), "Fila de jobs processada pelo agendador");

        $heartbeatFile = __DIR__ . '/../storage/scheduler_heartbeat.json';
        $this->assertTrue(file_exists($heartbeatFile), "Arquivo storage/scheduler_heartbeat.json gerado");
        $data = json_decode((string)file_get_contents($heartbeatFile), true);
        $this->assertEquals('HEALTHY', $data['status'], "Status do heartbeat é HEALTHY");
    }

    private function testEncryptedBackupAndRestoreRehearsal(): void
    {
        echo "\n--- 3. Backup Cifrado e Ensaio de Restauração ---\n";
        $backupScript = __DIR__ . '/../bin/backup_database.sh';
        $restoreScript = __DIR__ . '/../bin/restore_database.sh';

        $this->assertTrue(is_executable($backupScript), "Script bin/backup_database.sh é executável");
        $this->assertTrue(is_executable($restoreScript), "Script bin/restore_database.sh é executável");

        // Executar backup
        exec("bash " . escapeshellarg($backupScript) . " 2>&1", $backupOut, $backupCode);
        $this->assertEquals(0, $backupCode, "Rotina de backup executada com código 0");

        // Verificar arquivo .enc gerado
        $encFiles = glob(__DIR__ . '/../storage/backups/*.enc');
        $this->assertTrue(!empty($encFiles), "Arquivo .enc de backup cifrado foi gerado no storage");

        // Executar ensaio de restauração
        exec("bash " . escapeshellarg($restoreScript) . " 2>&1", $restoreOut, $restoreCode);
        $this->assertEquals(0, $restoreCode, "Ensaio de restauração (rehearsal) executado com código 0");
    }

    private function testAnthropometryCalculator(): void
    {
        echo "\n--- 4. Calculadora Pura de Antropometria ---\n";
        // Caso normal: 70kg, 1.75m -> IMC = 22.86 (Eutrofia)
        $bmi = AnthropometryCalculator::calculateBmi(70.0, 1.75);
        $this->assertEquals(22.86, $bmi, "Cálculo correto de IMC");
        $this->assertEquals('Eutrofia (Peso adequado)', AnthropometryCalculator::classifyBmi($bmi), "Classificação correta de IMC");

        // Casos extremos / proteção contra bugs
        $this->assertEquals(null, AnthropometryCalculator::calculateBmi(null, 1.75), "Peso nulo retorna null com segurança");
        $this->assertEquals(null, AnthropometryCalculator::calculateBmi(70.0, 0.0), "Altura zero não causa divisão por zero");
        $this->assertEquals(null, AnthropometryCalculator::calculateBmi(-70.0, 1.75), "Peso negativo retorna null");

        // RCQ: 80cm cintura, 100cm quadril -> 0.8
        $whr = AnthropometryCalculator::calculateWhr(80.0, 100.0);
        $this->assertEquals(0.8, $whr, "Cálculo correto de RCQ");
        $this->assertEquals(null, AnthropometryCalculator::calculateWhr(80.0, 0.0), "Quadril zero não causa divisão por zero");
    }

    private function testNutritionCalculatorCanonical100g(): void
    {
        echo "\n--- 5. Calculadora Nutricional de Precisão (Base 100g) ---\n";
        // Correção do bug do inventário: Aveia (394 kcal por 100g).
        // 30g de aveia deve ser 118.2 kcal (e NÃO 394 kcal como no código legado).
        $oatsCalsPer100g = 394.0;
        $portionG = 30.0;
        $calculatedCals = NutritionCalculator::calculatePortion($portionG, $oatsCalsPer100g);

        $this->assertEquals(118.2, $calculatedCals, "30g de aveia calcula 118.2 kcal (correção do bug do legado)");

        // Totais de macronutrientes proporcionais
        $oatsNutrientsPer100g = [
            'calories' => 394.0,
            'protein_g' => 13.9,
            'carbs_g' => 66.6,
            'fats_g' => 8.5,
            'fiber_g' => 9.1,
        ];
        $totals = NutritionCalculator::calculateNutrientTotals(30.0, $oatsNutrientsPer100g);
        $this->assertEquals(118.2, $totals['calories'], "Total de calorias da porção proporcional");
        $this->assertEquals(4.17, $totals['protein_g'], "Proteína da porção calculada proporcionalmente");
        $this->assertEquals(19.98, $totals['carbs_g'], "Carboidratos da porção calculados proporcionalmente");

        // Proteção contra valores negativos
        $threw = false;
        try {
            NutritionCalculator::calculatePortion(-10.0, 100.0);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, "Rejeita quantidade negativa com exceção explícita");
    }

    private function testTenantManagerIsolation(): void
    {
        echo "\n--- 6. Isolamento e Gerenciamento de Tenant ---\n";
        TenantManager::clear();
        $this->assertTrue(!TenantManager::hasTenant(), "TenantManager inicializa sem tenant ativo");

        $threw = false;
        try {
            TenantManager::getTenantId();
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, "Lança exceção ao acessar tenantId sem contexto ativo");

        TenantManager::setTenantId(42);
        $this->assertEquals(42, TenantManager::getTenantId(), "Contexto de tenant 42 atribuído e recuperado com sucesso");

        TenantManager::clear();
        $this->assertTrue(!TenantManager::hasTenant(), "Contexto de tenant limpo ao término");
    }

    private function testWebrootAndSecurityHardening(): void
    {
        echo "\n--- 7. Hardening de Webroot e Diretórios Sensíveis ---\n";
        $htaccess = __DIR__ . '/../public/.htaccess';
        $this->assertTrue(file_exists($htaccess), "Arquivo public/.htaccess existe");

        $content = (string)file_get_contents($htaccess);
        $this->assertTrue(strpos($content, 'HTTP_AUTHORIZATION') !== false, "Header Authorization repassado no .htaccess");
        $this->assertTrue(strpos($content, 'Options -MultiViews -Indexes') !== false, "Listagem de diretórios desabilitada");

        $publicDir = realpath(__DIR__ . '/../public');
        $envFile = realpath(__DIR__ . '/../.env');
        $storageDir = realpath(__DIR__ . '/../storage');

        $this->assertTrue(strpos($envFile, $publicDir) === false, "Arquivo .env está estritamente fora do webroot público");
        $this->assertTrue(strpos($storageDir, $publicDir) === false, "Diretório storage/ está fora do webroot público");
    }
}

$test = new ReplatformInfrastructureTest();
$test->run();
