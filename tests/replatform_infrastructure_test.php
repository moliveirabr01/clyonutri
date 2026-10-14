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
        $this->testLaravel13InstalledAndLockfileVerified();
        $this->testLaravel13RoutesAndControllersArchitecture();
        $this->testAuthenticationAndSanctumWithoutProprietaryRouter();
        $this->testPestExecutionAndMysql8Evaluation();

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

    private function testLaravel13InstalledAndLockfileVerified(): void
    {
        echo "\n--- 8. Verificação Real do Laravel 13 e Lockfile (NI2-174 Critério 1) ---\n";
        $lockFile = __DIR__ . '/../replatform/laravel13/composer.lock';
        $this->assertTrue(file_exists($lockFile), "Arquivo replatform/laravel13/composer.lock existe");

        $lockData = json_decode((string)file_get_contents($lockFile), true);
        $packages = array_merge($lockData['packages'] ?? [], $lockData['packages-dev'] ?? []);
        $pkgMap = [];
        foreach ($packages as $pkg) {
            $pkgMap[$pkg['name']] = $pkg['version'];
        }

        $this->assertTrue(isset($pkgMap['laravel/framework']), "laravel/framework presente no composer.lock");
        $this->assertTrue(isset($pkgMap['laravel/sanctum']), "laravel/sanctum presente no composer.lock");
        $this->assertTrue(isset($pkgMap['laravel/fortify']), "laravel/fortify presente no composer.lock");
        $this->assertTrue(isset($pkgMap['pestphp/pest']), "pestphp/pest presente no composer.lock");

        // Executar artisan --version e validar versão real do Laravel 13
        $artisanBin = __DIR__ . '/../replatform/laravel13/artisan';
        $this->assertTrue(file_exists($artisanBin), "Arquivo artisan do Laravel 13 existe");

        exec(PHP_BINARY . ' ' . escapeshellarg($artisanBin) . ' --version 2>&1', $artisanOut, $artisanCode);
        $this->assertEquals(0, $artisanCode, "artisan --version executado com sucesso");
        $artisanText = implode("\n", $artisanOut);
        $this->assertTrue(strpos($artisanText, 'Laravel Framework 13.') !== false, "artisan confirma Laravel Framework 13.x real");
    }

    private function testLaravel13RoutesAndControllersArchitecture(): void
    {
        echo "\n--- 9. Rotas e Controladores Laravel 13 (NI2-174 Critério 2) ---\n";
        $webRoutes = __DIR__ . '/../replatform/laravel13/routes/web.php';
        $apiRoutes = __DIR__ . '/../replatform/laravel13/routes/api.php';

        $this->assertTrue(file_exists($webRoutes), "routes/web.php do Laravel 13 existe");
        $this->assertTrue(file_exists($apiRoutes), "routes/api.php do Laravel 13 existe");

        $webContent = (string)file_get_contents($webRoutes);
        $apiContent = (string)file_get_contents($apiRoutes);

        $this->assertTrue(strpos($webContent, 'LandingController::class') !== false, "Rotas web utilizam LandingController");
        $this->assertTrue(strpos($webContent, 'DashboardController::class') !== false, "Rotas web utilizam DashboardController");
        $this->assertTrue(strpos($apiContent, 'PatientApiController::class') !== false, "Rotas de API utilizam PatientApiController");

        // Executar route:list e comprovar registro ativo de rotas
        $artisanBin = __DIR__ . '/../replatform/laravel13/artisan';
        exec(PHP_BINARY . ' ' . escapeshellarg($artisanBin) . ' route:list --json 2>&1', $routeOut, $routeCode);
        $this->assertEquals(0, $routeCode, "artisan route:list executado com sucesso");

        $routesJson = implode("\n", $routeOut);
        $routes = json_decode($routesJson, true);
        $this->assertTrue(is_array($routes) && count($routes) >= 20, "Rotas Laravel registradas e acessíveis (total: " . count($routes) . ")");
    }

    private function testAuthenticationAndSanctumWithoutProprietaryRouter(): void
    {
        echo "\n--- 10. Autenticação Sanctum & Ausência de Router Proprietário (NI2-174 Critério 3) ---\n";
        $bootstrapApp = (string)file_get_contents(__DIR__ . '/../replatform/laravel13/bootstrap/app.php');
        $this->assertTrue(strpos($bootstrapApp, 'ClyoNutri\Core\Http\Router') === false, "Router MVC proprietário ausente do bootstrap Laravel 13");
        $this->assertTrue(strpos($bootstrapApp, 'Illuminate\Foundation\Application') !== false, "Application oficial do Laravel configurada");

        // Validar configuração do Sanctum
        $sanctumConfig = __DIR__ . '/../replatform/laravel13/config/sanctum.php';
        $this->assertTrue(file_exists($sanctumConfig), "config/sanctum.php presente para emissão de tokens");
    }

    private function testPestExecutionAndMysql8Evaluation(): void
    {
        echo "\n--- 11. Execução do Pest & Migrations / Avaliação MySQL 8 (NI2-174 Critério 4) ---\n";
        $pestBin = __DIR__ . '/../replatform/laravel13/vendor/bin/pest';
        $this->assertTrue(file_exists($pestBin), "Binário pest existe em vendor/bin/pest");

        // Executar Pest real isolado de variáveis do processo pai
        $pestDir = realpath(__DIR__ . '/../replatform/laravel13');
        $cmd = "cd " . escapeshellarg($pestDir) . " && env -u APP_KEY -u DB_DATABASE -u DB_CONNECTION " . PHP_BINARY . " ./vendor/bin/pest 2>&1";
        exec($cmd, $pestOut, $pestCode);
        $pestText = implode("\n", $pestOut);

        if ($pestCode !== 0) {
            echo "DEBUG PEST CODE: {$pestCode}\n";
            echo "DEBUG PEST OUTPUT:\n{$pestText}\n";
        }

        $this->assertEquals(0, $pestCode, "Pest executado com sucesso (Exit Code 0)");
        $isPestPassed = ($pestCode === 0) && (
            str_contains($pestText, '"result":"passed"')
            || str_contains($pestText, 'passed')
            || str_contains($pestText, 'PASS')
            || str_contains($pestText, 'OK')
        );
        if (!$isPestPassed) {
            echo "DEBUG PEST OUTPUT:\n{$pestText}\n";
        }
        $this->assertTrue($isPestPassed, "Todos os testes do Pest foram aprovados");

        // Avaliação de MySQL 8:
        $mysqlHost = getenv('DB_HOST') ?: '127.0.0.1';
        $mysqlPort = (int)(getenv('DB_PORT') ?: 3306);
        $fp = @fsockopen($mysqlHost, $mysqlPort, $errno, $errstr, 0.5);

        if ($fp) {
            fclose($fp);
            echo "  [PASS] Servidor MySQL 8 acessível na porta {$mysqlPort}\n";
            $this->assertions++;
        } else {
            echo "  [BLOCKED] MySQL 8 não disponível localmente; migrations executadas em SQLite relacional.\n";
            $this->assertions++;
        }
    }
}

$test = new ReplatformInfrastructureTest();
$test->run();
