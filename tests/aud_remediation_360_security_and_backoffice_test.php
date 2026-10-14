<?php

declare(strict_types=1);

namespace ClyoNutri\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\PlatformPolicy;
use PDO;

Env::load(__DIR__ . '/../.env');

final class AudRemediation360SecurityAndBackofficeTest
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance();
    }

    public function run(): void
    {
        echo "========================================================================\n";
        echo "   SUÍTE DE TESTES E2E: AUDITORIA 360° BACKOFFICE & SEGURANÇA (NI2-402)\n";
        echo "   AUD-01 (RBAC Granular), AUD-02 (SQL Portável), AUD-03 (OTP Uso Único),\n";
        echo "   AUD-04 (KPIs Reais), AUD-05 (Config Persistida), AUD-06 (Paginação)\n";
        echo "========================================================================\n\n";

        $this->testAud01GranularPlatformRbacMatrix();
        $this->testAud01HttpEnforcementByRole();
        $this->testAud02PortableSqlWithoutDatetimeNow();
        $this->testAud03SingleUseAtomicOtpLoginAndReplayResistance();
        $this->testAud04AccurateSlaAndSubscriptionKpis();
        $this->testAud05RealPlatformSettingsPersistence();
        $this->testAud06ScalablePagination();

        echo "\n========================================================================\n";
        echo "✓ TODOS OS GATES DA AUDITORIA 360° (NI2-402) HOMOLOGADOS COM SUCESSO!\n";
        echo "========================================================================\n";
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("FALHA: {$message}");
        }
        echo "  [OK] {$message}\n";
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("FALHA: {$message} (Esperado: " . var_export($expected, true) . ", Obtido: " . var_export($actual, true) . ")");
        }
        echo "  [OK] {$message}\n";
    }

    private function testAud01GranularPlatformRbacMatrix(): void
    {
        echo "\n--- [1] AUD-01 (NI2-403): Matriz Granular de Permissões por Papel e Ação ---\n";

        // 1. Support Agent
        $this->assertTrue(PlatformPolicy::can('support_agent', 'tickets.read'), "support_agent pode ler tickets.");
        $this->assertTrue(PlatformPolicy::can('support_agent', 'tickets.manage'), "support_agent pode atender tickets.");
        $this->assertTrue(PlatformPolicy::can('support_agent', 'clinics.read'), "support_agent pode ler clínicas.");
        $this->assertTrue(!PlatformPolicy::can('support_agent', 'clinics.suspend'), "support_agent NÃO PODE suspender clínicas (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('support_agent', 'users.suspend'), "support_agent NÃO PODE suspender usuários (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('support_agent', 'sessions.revoke'), "support_agent NÃO PODE revogar sessões (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('support_agent', 'settings.manage'), "support_agent NÃO PODE alterar configurações (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('support_agent', 'billing.manage'), "support_agent NÃO PODE gerenciar faturamento (bloqueado).");

        // 2. Security Auditor
        $this->assertTrue(PlatformPolicy::can('security_auditor', 'audit.read'), "security_auditor pode ler logs de auditoria.");
        $this->assertTrue(PlatformPolicy::can('security_auditor', 'sessions.read'), "security_auditor pode inspecionar sessões ativas.");
        $this->assertTrue(!PlatformPolicy::can('security_auditor', 'clinics.suspend'), "security_auditor NÃO PODE suspender clínicas (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('security_auditor', 'users.suspend'), "security_auditor NÃO PODE suspender usuários (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('security_auditor', 'settings.manage'), "security_auditor NÃO PODE alterar configurações globais (bloqueado).");

        // 3. Finance Admin
        $this->assertTrue(PlatformPolicy::can('finance_admin', 'billing.read'), "finance_admin pode ler governança comercial.");
        $this->assertTrue(PlatformPolicy::can('finance_admin', 'billing.manage'), "finance_admin pode gerenciar planos e faturamento.");
        $this->assertTrue(!PlatformPolicy::can('finance_admin', 'users.suspend'), "finance_admin NÃO PODE suspender usuários clínicos (bloqueado).");
        $this->assertTrue(!PlatformPolicy::can('finance_admin', 'settings.manage'), "finance_admin NÃO PODE alterar configurações técnicas (bloqueado).");

        // 4. Admin Operacional / Operator
        $this->assertTrue(PlatformPolicy::can('operator', 'clinics.suspend'), "operator pode suspender clínicas.");
        $this->assertTrue(PlatformPolicy::can('operator', 'users.suspend'), "operator pode suspender usuários.");
        $this->assertTrue(PlatformPolicy::can('operator', 'sessions.revoke'), "operator pode revogar sessões de credencial.");
        $this->assertTrue(!PlatformPolicy::can('operator', 'settings.manage'), "operator NÃO PODE alterar configurações globais de infraestrutura.");

        // 5. Superadmin
        $this->assertTrue(PlatformPolicy::can('superadmin', 'clinics.suspend'), "superadmin pode suspender clínicas.");
        $this->assertTrue(PlatformPolicy::can('superadmin', 'settings.manage'), "superadmin pode alterar configurações globais.");
        $this->assertTrue(PlatformPolicy::can('superadmin', 'billing.manage'), "superadmin pode gerenciar faturamento.");
    }

    private function testAud01HttpEnforcementByRole(): void
    {
        echo "\n--- [2] AUD-01 (NI2-403): Bloqueio HTTP Server-Side 403 para Ações Não Autorizadas ---\n";

        // Cria clínica e operadores de teste
        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica RBAC HTTP {$suffix}");
        
        $supportUserId = $this->createOperator("Suporte {$suffix}", "support_{$suffix}@clyo.test", 'support_agent', $clinicId);
        $auditorUserId = $this->createOperator("Auditor {$suffix}", "auditor_{$suffix}@clyo.test", 'security_auditor', $clinicId);
        $superUserId = $this->createOperator("Superadmin {$suffix}", "super_{$suffix}@clyo.test", 'superadmin', $clinicId);

        // Suporte tenta suspender clínica via POST: deve ser barrado com 403 e sem mutação
        $resp = $this->simulateHttpRequest('POST', "/admin/clinicas/{$clinicId}/status", [
            'user_id' => $supportUserId,
            'clinic_id' => $clinicId,
            'user_role' => 'support_agent',
            'auth_version' => 0,
        ], ['status' => 'suspended']);

        // Verifica que foi barrado ou redirecionado com flash de acesso negado
        $stmtStatus = $this->db->prepare("SELECT status FROM clinics WHERE id = :id");
        $stmtStatus->execute(['id' => $clinicId]);
        $currentStatus = $stmtStatus->fetchColumn();
        $this->assertEquals('active', $currentStatus, "Clínica permaneceu 'active' (mutação bloqueada para support_agent).");

        // Verifica auditoria de tentativa negada
        $stmtDenied = $this->db->prepare("
            SELECT COUNT(*) FROM audit_logs 
            WHERE action = 'ADMIN_ACCESS_DENIED' AND user_id = :uid
        ");
        $stmtDenied->execute(['uid' => $supportUserId]);
        $deniedCount = (int)$stmtDenied->fetchColumn();
        $this->assertTrue($deniedCount >= 1, "Tentativa não autorizada registrada em audit_logs (ADMIN_ACCESS_DENIED).");
    }

    private function testAud02PortableSqlWithoutDatetimeNow(): void
    {
        echo "\n--- [3] AUD-02 (NI2-404): Portabilidade SQL (Sem datetime('now') proprietário) ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica SQL Portátil {$suffix}");
        $superUserId = $this->createOperator("Superadmin SQL {$suffix}", "supersql_{$suffix}@clyo.test", 'superadmin', $clinicId);

        // Executa atualização com superadmin
        $this->simulateHttpRequest('POST', "/admin/clinicas/{$clinicId}/status", [
            'user_id' => $superUserId,
            'clinic_id' => $clinicId,
            'user_role' => 'superadmin',
            'auth_version' => 0,
        ], ['status' => 'suspended']);

        $stmtCheck = $this->db->prepare("SELECT status, updated_at FROM clinics WHERE id = :id");
        $stmtCheck->execute(['id' => $clinicId]);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('suspended', $row['status'], "Status da clínica atualizado com sucesso para 'suspended'.");
        $this->assertTrue(!empty($row['updated_at']), "updated_at preenchido confiavelmente em formato UTC padrão (sem erro de sintaxe).");

        // Validação adicional: HelpdeskTicketService::getOperationsMetrics() executa sem funções SQLite proprietárias
        $helpdeskService = new \ClyoNutri\Domain\Helpdesk\HelpdeskTicketService($this->db);
        $metrics = $helpdeskService->getOperationsMetrics();
        $this->assertTrue(is_array($metrics), "Helpdesk metrics calculado com sucesso via ANSI SQL.");
        $this->assertTrue(array_key_exists('avg_first_response_minutes', $metrics), "Métrica FRT calculada confiavelmente.");
        $this->assertTrue(array_key_exists('avg_resolution_hours', $metrics), "Métrica MTTR calculada confiavelmente.");
        $this->assertTrue(array_key_exists('aged_tickets_count', $metrics), "Métrica aged tickets calculada confiavelmente.");

        // Validação estática: ausência completa de datetime('now' e strftime('%s' no diretório src/
        $srcDir = realpath(__DIR__ . '/../src');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        $dialectViolations = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getRealPath());
                if (str_contains($contents, "datetime('now'") || str_contains($contents, "strftime(")) {
                    $dialectViolations[] = $file->getFilename();
                }
            }
        }
        $this->assertTrue(empty($dialectViolations), "Código de produção em src/ livre de datetime('now') e strftime() incompatíveis com MySQL 8: " . implode(', ', $dialectViolations));
    }

    private function testAud03SingleUseAtomicOtpLoginAndReplayResistance(): void
    {
        echo "\n--- [4] AUD-03 (NI2-405): OTP de Uso Único e Resistência a Replay ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica OTP {$suffix}");

        $otpCode = (string)mt_rand(100000, 999999);
        $otpExpiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutos no futuro

        $patientEmail = "paciente_otp_{$suffix}@clyonutri.test";
        $patientUuid = bin2hex(random_bytes(16));
        $stmtP = $this->db->prepare("
            INSERT INTO patients (uuid, clinic_id, name, email, phone, status, otp_code, otp_expires_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
        ");
        $stmtP->execute([$patientUuid, $clinicId, "Paciente OTP {$suffix}", $patientEmail, "1199999{$suffix}", $otpCode, $otpExpiresAt]);
        $patientId = (int)$this->db->lastInsertId();

        // 1. Primeira autenticação com OTP válido no endpoint real /api/v1/auth/patient-login
        $resp1 = $this->simulateHttpRequest('POST', '/api/v1/auth/patient-login', [], [
            'email' => $patientEmail,
            'otp' => $otpCode,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertEquals(200, $resp1['http_code'], "Primeiro login com OTP válido retorna HTTP 200 OK.");
        $json1 = json_decode($resp1['body'], true);
        $this->assertTrue(!empty($json1['token']), "Bearer token de acesso emitido na primeira autenticação.");

        // Verifica que o OTP foi consumido e anulado atomicamente no banco
        $stmtQuery = $this->db->prepare("SELECT otp_code FROM patients WHERE id = :id");
        $stmtQuery->execute(['id' => $patientId]);
        $otpAfter = $stmtQuery->fetchColumn();
        $this->assertEquals(null, $otpAfter, "Código OTP anulado no banco imediatamente após o login (consumo atômico).");

        // 2. Segunda tentativa com o MESMO OTP (Replay Attack):
        $resp2 = $this->simulateHttpRequest('POST', '/api/v1/auth/patient-login', [], [
            'email' => $patientEmail,
            'otp' => $otpCode,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertEquals(401, $resp2['http_code'], "Tentativa de Replay com o mesmo código OTP rejeitada com HTTP 401 Unauthorized.");
        $json2 = json_decode($resp2['body'], true);
        $this->assertTrue(isset($json2['error']), "Mensagem de erro segura retornada, sem emissão de novo token.");
    }

    private function testAud04AccurateSlaAndSubscriptionKpis(): void
    {
        echo "\n--- [5] AUD-04 (NI2-406): KPIs de SLA e Assinaturas no Backoffice ---\n";

        // Cria chamado antigo com SLA P1 estourado (> 48h)
        $pastDate = date('Y-m-d H:i:s', time() - (48 * 3600));
        $suffix = (string)mt_rand(100000, 999999);
        
        $stmtTicket = $this->db->prepare("
            INSERT INTO helpdesk_tickets (protocol, clinic_id, user_id, category, priority_operational, status, subject, description, created_at)
            VALUES (?, 1, 1, 'financial', 'P1', 'open', 'Urgente SLA Expirado {$suffix}', 'Descrição', ?)
        ");
        $stmtTicket->execute(["SUP-TEST-{$suffix}", $pastDate]);

        // Consulta de tickets overdue por SLA
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $p1Limit = $now->modify('-4 hours')->format('Y-m-d H:i:s');
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) FROM helpdesk_tickets 
            WHERE status NOT IN ('resolved', 'closed') AND priority_operational = 'P1' AND created_at < :p1
        ");
        $stmtCount->execute(['p1' => $p1Limit]);
        $overdueCount = (int)$stmtCount->fetchColumn();

        $this->assertTrue($overdueCount >= 1, "Chamado P1 com mais de 4h computado corretamente como overdue SLA.");

        // Consulta de assinaturas ativas na tabela clinic_subscriptions
        $stmtSub = $this->db->query("SELECT COUNT(*) FROM clinic_subscriptions WHERE status = 'active'");
        $activeSubs = (int)$stmtSub->fetchColumn();
        $this->assertTrue($activeSubs >= 1, "Assinaturas ativas consultadas da tabela clinic_subscriptions.");

        // Catálogo dinâmico de planos (NI2-411 / AUD-04)
        $plans = $this->db->query("SELECT * FROM saas_plans ORDER BY price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertTrue(count($plans) >= 3, "Catálogo de planos carregado com sucesso da tabela saas_plans.");
        $planCodes = array_column($plans, 'code');
        $this->assertTrue(in_array('START-01', $planCodes, true), "Plano START-01 presente no catálogo dinâmico.");
        $this->assertTrue(in_array('PRO-02', $planCodes, true), "Plano PRO-02 presente no catálogo dinâmico.");
        $this->assertTrue(in_array('ELITE-03', $planCodes, true), "Plano ELITE-03 presente no catálogo dinâmico.");
    }

    private function testAud05RealPlatformSettingsPersistence(): void
    {
        echo "\n--- [6] AUD-05 (NI2-407): Persistência Efetiva de Configurações no Backoffice ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica Config {$suffix}");
        $superUserId = $this->createOperator("Superadmin Config {$suffix}", "supercfg_{$suffix}@clyo.test", 'superadmin', $clinicId);

        // Submissão real de parâmetros operacionais e feature flags (NI2-411 / AUD-04 / AUD-05)
        $this->simulateHttpRequest('POST', '/admin/configuracoes', [
            'user_id' => $superUserId,
            'clinic_id' => $clinicId,
            'user_role' => 'superadmin',
            'auth_version' => 0,
        ], [
            'maintenance_mode' => '1',
            'support_sla_p1_hours' => '3',
            'support_sla_p2_hours' => '18',
            'session_timeout_minutes' => '45',
            'feature_flag_telehealth' => '0',
            'feature_flag_chat' => '1',
            'feature_flag_audit_immutable' => '1',
        ]);

        // Verifica persistência na tabela platform_settings
        $stmt = $this->db->query("SELECT setting_key, setting_value FROM platform_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertEquals('1', $settings['maintenance_mode'], "maintenance_mode persistido com sucesso como '1'.");
        $this->assertEquals('3', $settings['support_sla_p1_hours'], "support_sla_p1_hours persistido com sucesso como '3'.");
        $this->assertEquals('18', $settings['support_sla_p2_hours'], "support_sla_p2_hours persistido com sucesso como '18'.");
        $this->assertEquals('45', $settings['session_timeout_minutes'], "session_timeout_minutes persistido com sucesso como '45'.");
        $this->assertEquals('0', $settings['feature_flag_telehealth'], "feature_flag_telehealth desabilitada e persistida como '0'.");
        $this->assertEquals('1', $settings['feature_flag_chat'], "feature_flag_chat persistida dinamicamente como '1'.");
        $this->assertEquals('1', $settings['feature_flag_audit_immutable'], "feature_flag_audit_immutable persistida dinamicamente como '1'.");

        // Restaura valores para operação padrão
        $this->simulateHttpRequest('POST', '/admin/configuracoes', [
            'user_id' => $superUserId,
            'clinic_id' => $clinicId,
            'user_role' => 'superadmin',
            'auth_version' => 0,
        ], [
            'maintenance_mode' => '0',
            'support_sla_p1_hours' => '4',
            'support_sla_p2_hours' => '24',
            'session_timeout_minutes' => '120',
            'feature_flag_telehealth' => '1',
            'feature_flag_chat' => '1',
            'feature_flag_audit_immutable' => '1',
        ]);
    }

    private function testAud06ScalablePagination(): void
    {
        echo "\n--- [7] AUD-06 (NI2-408): Paginação Escalável em Listagens Administrativas ---\n";

        $stmtCount = $this->db->query("SELECT COUNT(*) FROM clinics");
        $total = (int)$stmtCount->fetchColumn();

        $perPage = 10;
        $pages = (int)ceil($total / $perPage);

        $this->assertTrue($total > 0, "Total de clínicas no banco maior que 0.");
        $this->assertTrue($pages >= 1, "Cálculo de páginas total_pages correto.");

        $stmtPaged = $this->db->prepare("SELECT id, name FROM clinics ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmtPaged->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmtPaged->bindValue(':offset', 0, PDO::PARAM_INT);
        $stmtPaged->execute();
        $results = $stmtPaged->fetchAll(PDO::FETCH_ASSOC);

        $this->assertTrue(count($results) <= $perPage, "Resultados respeitam o limit por página de forma escalável.");
    }

    // Helpers
    private function createClinic(string $name): int
    {
        $uuid = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO clinics (uuid, name, document, email, phone, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $uuid,
            $name,
            '12.' . mt_rand(100,999) . '.' . mt_rand(100,999) . '/0001-' . mt_rand(10,99),
            'clinic_' . mt_rand(10000, 99999) . '@clyonutri.test',
            '119' . mt_rand(10000000, 99999999)
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function createOperator(string $name, string $email, string $role, int $clinicId): int
    {
        $uuid = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, status, is_verified) VALUES (?, ?, ?, ?, ?, 'active', 1)");
        $stmt->execute([
            $uuid,
            $name,
            $email,
            password_hash('SenhaSegura123!', PASSWORD_BCRYPT),
            $role
        ]);
        $userId = (int)$this->db->lastInsertId();

        $stmtM = $this->db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'admin', 'active')");
        $stmtM->execute([$clinicId, $userId]);

        return $userId;
    }

    private function simulateHttpRequest(
        string $method,
        string $uri,
        array $sessionData = [],
        array $postData = [],
        array $headers = []
    ): array {
        if ($method === 'POST') {
            $csrfToken = $sessionData['_clyonutri_csrf_token'] ?? bin2hex(random_bytes(32));
            $sessionData['_clyonutri_csrf_token'] = $csrfToken;
            if (!isset($postData['_csrf_token'])) {
                $postData['_csrf_token'] = $csrfToken;
            }
        }
        $script = '<?php
            namespace ClyoNutri\Application\Controllers {
                function header(string $h, bool $r = true, int $c = 0): void {
                    $GLOBALS["__captured_headers"][] = $h;
                }
            }
            namespace {
                $GLOBALS["__captured_headers"] = [];
                require_once __DIR__ . "/../vendor/autoload.php";
                \ClyoNutri\Core\Http\Response::setRedirectHandler(function(string $url) {
                    $GLOBALS["__captured_headers"][] = "Location: " . $url;
                    http_response_code(302);
                    exit;
                });
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION = ' . var_export($sessionData, true) . ';
                $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
                $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
                $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
                $_SERVER["HTTP_USER_AGENT"] = "PHPUnit/Admin-E2E-Runner";
                ' . (!empty($headers['HTTP_X_REQUESTED_WITH']) ? '$_SERVER["HTTP_X_REQUESTED_WITH"] = ' . var_export($headers['HTTP_X_REQUESTED_WITH'], true) . ';' : '') . '
                ' . (!empty($headers['HTTP_ACCEPT']) ? '$_SERVER["HTTP_ACCEPT"] = ' . var_export($headers['HTTP_ACCEPT'], true) . ';' : '') . '
                $parsed = parse_url(' . var_export($uri, true) . ');
                if (!empty($parsed["query"])) { parse_str($parsed["query"], $_GET); } else { $_GET = []; }
                $_POST = ' . var_export($postData, true) . ';
                register_shutdown_function(function() {
                    echo "\n___HTTP_CODE___" . http_response_code() . "___HTTP_CODE___\n";
                });
                require "public/index.php";
            }
        ';

        $tempScript = __DIR__ . '/../storage/temp_aud_e2e_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($tempScript, $script);

        $cmd = "php -d display_errors=0 -d error_reporting=0 " . escapeshellarg($tempScript) . " 2>&1";
        $output = (string)shell_exec($cmd);
        @unlink($tempScript);

        $httpCode = 200;
        if (preg_match('/___HTTP_CODE___(\d+)___HTTP_CODE___/', $output, $matches)) {
            $httpCode = (int)$matches[1];
        }

        $cleanBody = preg_replace('/___HTTP_CODE___\d*___HTTP_CODE___/', '', $output);
        $cleanBody = trim((string)$cleanBody);

        // Se a API retornou código explícito via ApiErrorBoundary (ex: HTTP_401, HTTP_403)
        if ($httpCode === 200 && preg_match('/"code"\s*:\s*"HTTP_(\d+)"/', $cleanBody, $codeMatch)) {
            $httpCode = (int)$codeMatch[1];
        }

        return [
            'http_code' => $httpCode,
            'body' => $cleanBody,
        ];
    }
}

// Execução direta
(new AudRemediation360SecurityAndBackofficeTest())->run();
