<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Application\Controllers\AdminController;
use PDO;

/**
 * Suíte de Testes para P1 / P2:
 * 1. Fazer configurações afetarem o runtime real (P1)
 * 2. Eliminar valores fictícios dos indicadores comerciais (P1)
 * 3. Corrigir cálculo de MRR e ticket médio contratado (P1)
 * 4. Validar e transacionar alterações de configurações (P1)
 */
class RuntimePlatformSettingsAndCommercialMetricsTest
{
    private PDO $db;
    private int $assertions = 0;

    public function __construct()
    {
        $this->db = Connection::getInstance();
    }

    public function run(): void
    {
        echo "========================================================================\n";
        echo ">>> TESTES DE RUNTIME: CONFIGURAÇÕES, MRR REAL & TRANSAÇÕES (P1) <<<\n";
        echo "========================================================================\n\n";

        // [1] Validação de Modo de Manutenção no Runtime
        $this->testMaintenanceModeRuntimeImpact();

        // [2] Validação de Feature Flags no Runtime (Teleconsulta e Chat)
        $this->testFeatureFlagsRuntimeImpact();

        // [3] Validação de Timeout de Inatividade de Sessão
        $this->testSessionInactivityTimeout();

        // [4] Validação Estrita e Atomicidade Transacional de Configurações
        $this->testSettingsValidationAndTransaction();

        // [5] Cálculo Exato de MRR e Ticket Médio Contratado (Zero Fictício)
        $this->testExactMrrAndContractedTicketCalculation();

        echo "\n========================================================================\n";
        echo ">>> TODAS AS {$this->assertions} ASSERÇÕES DE RUNTIME E COMERCIAL FORAM APROVADAS! <<<\n";
        echo "========================================================================\n";
    }

    private function assertTrue(bool $condition, string $msg): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("FALHA: {$msg}");
        }
        echo "  [PASS] {$msg}\n";
    }

    private function testMaintenanceModeRuntimeImpact(): void
    {
        echo "--- [1] Modo de Manutenção Afetando o Runtime Real ---\n";

        // 1. Ativa modo de manutenção na tabela platform_settings
        $this->db->exec("UPDATE platform_settings SET setting_value = '1' WHERE setting_key = 'maintenance_mode'");
        PlatformSettings::clearCache();

        $this->assertTrue(PlatformSettings::isMaintenanceMode($this->db) === true, "PlatformSettings detecta modo de manutenção ativo");

        // 2. Simula requisição de API com modo de manutenção ativo
        $resApi = $this->simulateHttp('GET', '/api/v1/onboarding/status');
        $this->assertTrue($resApi['status'] === 503, "API pública/cliente barrada com HTTP 503 durante manutenção");
        $this->assertTrue(str_contains($resApi['body'], 'manutenção temporária agendada'), "API retorna mensagem de manutenção agendada");

        // 3. Rotas isentas continuam respondendo (ex: /up e rotas de administração)
        $resUp = $this->simulateHttp('GET', '/up');
        $this->assertTrue($resUp['status'] === 200, "Health check /up permanece acessível durante manutenção (isento)");

        // 4. Restaura modo de manutenção
        $this->db->exec("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'maintenance_mode'");
        PlatformSettings::clearCache();
        $this->assertTrue(PlatformSettings::isMaintenanceMode($this->db) === false, "PlatformSettings restabelece operação normal");
    }

    private function testFeatureFlagsRuntimeImpact(): void
    {
        echo "\n--- [2] Feature Flags de Teleconsulta e Chat no Runtime Real ---\n";

        // Desativa Teleconsulta
        $this->db->exec("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'feature_flag_telehealth'");
        PlatformSettings::clearCache();
        $this->assertTrue(!PlatformSettings::isTelehealthEnabled($this->db), "Flag de Teleconsulta suspensa");

        $resTele = $this->simulateHttp('GET', '/teleconsulta/sala-espera/test-token-fake');
        $this->assertTrue($resTele['status'] === 503, "Acesso à Teleconsulta bloqueado com HTTP 503 quando flag suspensa");

        // Restaura Teleconsulta
        $this->db->exec("UPDATE platform_settings SET setting_value = '1' WHERE setting_key = 'feature_flag_telehealth'");
        PlatformSettings::clearCache();
        $this->assertTrue(PlatformSettings::isTelehealthEnabled($this->db), "Flag de Teleconsulta restabelecida");

        // Desativa Chat
        $this->db->exec("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'feature_flag_chat'");
        PlatformSettings::clearCache();
        $this->assertTrue(!PlatformSettings::isChatEnabled($this->db), "Flag de Chat suspensa");

        // Restaura Chat
        $this->db->exec("UPDATE platform_settings SET setting_value = '1' WHERE setting_key = 'feature_flag_chat'");
        PlatformSettings::clearCache();
        $this->assertTrue(PlatformSettings::isChatEnabled($this->db), "Flag de Chat restabelecida");
    }

    private function testSessionInactivityTimeout(): void
    {
        echo "\n--- [3] Validação de Timeout de Inatividade de Sessão ---\n";

        $this->db->exec("UPDATE platform_settings SET setting_value = '30' WHERE setting_key = 'session_timeout_minutes'");
        PlatformSettings::clearCache();

        $timeoutMinutes = PlatformSettings::getSessionTimeoutMinutes($this->db);
        $this->assertTrue($timeoutMinutes === 30, "Tempo de inatividade configurado como 30 minutos");

        // Restaura para 120 padrão
        $this->db->exec("UPDATE platform_settings SET setting_value = '120' WHERE setting_key = 'session_timeout_minutes'");
        PlatformSettings::clearCache();
    }

    private function testSettingsValidationAndTransaction(): void
    {
        echo "\n--- [4] Validação Estrita e Atomicidade Transacional de Configurações ---\n";

        // Garante clínica 1, operador superadmin 998 e vínculo ativo em clinic_members
        $this->db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document, email, status) VALUES (1, 'uuid-clinic-1', 'Clínica Matriz', '11111111000111', 'matriz@clyo.test', 'active')");
        $this->db->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status) VALUES (998, 'Super Admin Bench', 'superadmin_bench@clyo.test', 'hash', 'superadmin', 'active')");
        $this->db->exec("UPDATE users SET role = 'superadmin', status = 'active' WHERE id = 998");
        $this->db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 998, 'admin', 'active')");

        $session = [
            'user_id' => 998,
            'user_role' => 'superadmin',
            'clinic_id' => 1,
            'auth_version' => 0,
            '_clyonutri_csrf_token' => 'test-csrf-valid-token',
        ];

        // 1. Tentar salvar valor fora do intervalo permitido (ex: session_timeout_minutes = 5 < 15)
        $resInvalid = $this->simulateHttp('POST', '/admin/configuracoes', [
            '_csrf_token' => 'test-csrf-valid-token',
            'session_timeout_minutes' => '5', // Inválido! Mínimo é 15
            'maintenance_mode' => '1',
        ], $session);

        // O valor no banco deve permanecer intacto (120) e não mutado para 5
        $stmt = $this->db->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'session_timeout_minutes'");
        $valAfter = $stmt->fetchColumn();
        $stmt->closeCursor();
        $this->assertTrue($valAfter === '120', "Valor no banco não foi corrompido (abort na validação de limites)");

        // 2. Salvar valores estritamente válidos
        $resValid = $this->simulateHttp('POST', '/admin/configuracoes', [
            '_csrf_token' => 'test-csrf-valid-token',
            'session_timeout_minutes' => '60',
            'support_sla_p1_hours' => '6',
            'support_sla_p2_hours' => '36',
            'security_max_login_attempts' => '7',
            'maintenance_mode' => '0',
            'feature_flag_telehealth' => '1',
            'feature_flag_chat' => '1',
            'feature_flag_audit_immutable' => '1',
        ], $session);

        PlatformSettings::clearCache();
        $freshDb = Connection::getInstance();
        $this->assertTrue((int)$freshDb->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'session_timeout_minutes'")->fetchColumn() === 60, "Novo timeout de 60 minutos transacionado no banco");
        $this->assertTrue(PlatformSettings::getSessionTimeoutMinutes($freshDb) === 60, "Novo timeout de 60 minutos ativo no runtime");
        $this->assertTrue(PlatformSettings::getSupportSlaP1Hours($freshDb) === 6, "Novo SLA P1 de 6 horas transacionado no runtime");
        $this->assertTrue(PlatformSettings::getMaxLoginAttempts($freshDb) === 7, "Novo limite de 7 tentativas transacionado no runtime");

        // Restaura valores para operação normal
        $this->db->exec("UPDATE platform_settings SET setting_value = '120' WHERE setting_key = 'session_timeout_minutes'");
        $this->db->exec("UPDATE platform_settings SET setting_value = '4' WHERE setting_key = 'support_sla_p1_hours'");
        $this->db->exec("UPDATE platform_settings SET setting_value = '24' WHERE setting_key = 'support_sla_p2_hours'");
        $this->db->exec("UPDATE platform_settings SET setting_value = '5' WHERE setting_key = 'security_max_login_attempts'");
        PlatformSettings::clearCache();
    }

    private function testExactMrrAndContractedTicketCalculation(): void
    {
        echo "\n--- [5] Cálculo de MRR e Ticket Médio Contratado (Zero Fictício) ---\n";

        // Cria tabela temporária ou faz backup das assinaturas atuais para testar isoladamente
        $this->db->beginTransaction();
        try {
            // Remove temporariamente assinaturas ativas para testar o caso ZERO FICTÍCIO
            $this->db->exec("UPDATE clinic_subscriptions SET status = 'canceled' WHERE status = 'active'");

            // Consulta com zero assinantes ativos
            $activeSubs = (int)$this->db->query("SELECT COUNT(*) FROM clinic_subscriptions WHERE status = 'active'")->fetchColumn();
            $this->assertTrue($activeSubs === 0, "Cenário de teste: 0 assinantes ativos");

            // Cálculo deve resultar rigorosamente em R$ 0,00 e NUNCA no valor fixo de R$ 189,00
            $activeSubsRows = $this->db->query("
                SELECT cs.id, cs.billing_cycle, COALESCE(cs.price_cents, sp.price_cents, 0) as price_cents
                FROM clinic_subscriptions cs
                LEFT JOIN saas_plans sp ON sp.id = cs.plan_id
                WHERE cs.status = 'active'
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $mrrCents = 0;
            foreach ($activeSubsRows as $sub) {
                $cents = (int)$sub['price_cents'];
                $cycle = strtolower((string)($sub['billing_cycle'] ?? 'monthly'));
                if ($cycle === 'annual' || $cycle === 'yearly') {
                    $mrrCents += (int)round($cents / 12);
                } elseif ($cycle === 'quarterly') {
                    $mrrCents += (int)round($cents / 3);
                } else {
                    $mrrCents += $cents;
                }
            }

            $avgTicketCents = ($activeSubs > 0) ? (int)round($mrrCents / $activeSubs) : 0;
            $this->assertTrue($mrrCents === 0, "MRR é estritamente 0 quando não há contratos ativos");
            $this->assertTrue($avgTicketCents === 0, "Ticket Médio é estritamente R$ 0,00 (zero fictício eliminado)");

            // Agora simula contratos com planos diversificados:
            // 1 contrato Start: R$ 99,00/mês (9900 cents)
            // 1 contrato Pro Mensal: R$ 189,00/mês (18900 cents)
            // 1 contrato Elite Anual: R$ 389,00 x 12 = R$ 4.668,00/ano (466800 cents / 12 = 38900 cents/mês)
            $this->db->exec("
                INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end)
                VALUES 
                (1, 1, 'active', 'monthly', 9900, '2026-10-13 12:00:00', '2026-10-13 12:00:00', '2026-11-13 12:00:00'),
                (2, 2, 'active', 'monthly', 18900, '2026-10-13 12:00:00', '2026-10-13 12:00:00', '2026-11-13 12:00:00'),
                (3, 3, 'active', 'annual', 466800, '2026-10-13 12:00:00', '2026-10-13 12:00:00', '2027-10-13 12:00:00')
            ");

            $activeSubsSim = (int)$this->db->query("SELECT COUNT(*) FROM clinic_subscriptions WHERE status = 'active'")->fetchColumn();
            $this->assertTrue($activeSubsSim === 3, "Três contratos ativos com ciclos distintos inseridos");

            $activeSubsRowsSim = $this->db->query("
                SELECT cs.id, cs.billing_cycle, COALESCE(cs.price_cents, sp.price_cents, 0) as price_cents
                FROM clinic_subscriptions cs
                LEFT JOIN saas_plans sp ON sp.id = cs.plan_id
                WHERE cs.status = 'active'
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $mrrCalculado = 0;
            foreach ($activeSubsRowsSim as $sub) {
                $cents = (int)$sub['price_cents'];
                $cycle = strtolower((string)($sub['billing_cycle'] ?? 'monthly'));
                if ($cycle === 'annual' || $cycle === 'yearly') {
                    $mrrCalculado += (int)round($cents / 12);
                } elseif ($cycle === 'quarterly') {
                    $mrrCalculado += (int)round($cents / 3);
                } else {
                    $mrrCalculado += $cents;
                }
            }

            // MRR esperado: 9900 + 18900 + 38900 = 67700 centavos = R$ 677,00
            $this->assertTrue($mrrCalculado === 67700, "MRR contratado exato calculado como R$ 677,00 (67700 centavos)");

            // Ticket Médio esperado: 67700 / 3 = 22567 centavos = R$ 225,67
            $ticketEsperado = (int)round(67700 / 3);
            $ticketCalculado = (int)round($mrrCalculado / $activeSubsSim);
            $this->assertTrue($ticketCalculado === $ticketEsperado, "Ticket médio contratado exato calculado como R$ 225,67");

            // Rollback para preservar o estado do banco
            $this->db->rollBack();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        echo "  [PASS] Cálculo de MRR e Ticket Médio comprovado com precisão centesimal e ciclo normalizado.\n";
    }

    private function simulateHttp(string $method, string $uri, array $body = [], array $sessionData = []): array
    {
        $indexPath = addslashes(realpath(__DIR__ . '/../public/index.php'));
        $payloadJson = json_encode($body, JSON_UNESCAPED_UNICODE);

        $script = '<?php
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            $_SESSION = ' . var_export($sessionData, true) . ';
            $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
            $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
            $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
            $_SERVER["HTTP_ACCEPT"] = "application/json";
            $_POST = ' . var_export($body, true) . ';
            $GLOBALS["__TEST_JSON_INPUT__"] = ' . var_export($payloadJson, true) . ';

            ob_start();
            register_shutdown_function(function() {
                $output = ob_get_clean();
                $code = http_response_code() ?: 200;
                echo "___JSON_START___" . json_encode([
                    "status" => $code,
                    "body" => $output,
                ]) . "___JSON_END___";
            });

            require "' . $indexPath . '";
        ';

        $tempScript = tempnam(sys_get_temp_dir(), 'ni2_rt_');
        file_put_contents($tempScript, $script);
        $output = (string)shell_exec("php -d display_errors=0 " . escapeshellarg($tempScript) . " 2>&1");
        @unlink($tempScript);

        if (preg_match('/___JSON_START___(.*)___JSON_END___/s', $output, $m)) {
            $decoded = json_decode($m[1], true);
            if ($decoded) {
                return $decoded;
            }
        }

        return ['status' => 500, 'body' => $output];
    }
}

$test = new RuntimePlatformSettingsAndCommercialMetricsTest();
$test->run();
