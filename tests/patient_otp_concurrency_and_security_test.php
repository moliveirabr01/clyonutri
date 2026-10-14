<?php
declare(strict_types=1);

namespace ClyoNutri\Tests;

use PDO;
use RuntimeException;
use ClyoNutri\Core\Database\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Suíte de Testes E2E: Segurança, Validação Estrita, Anti-Brute Force e Concorrência do OTP (NI2-405 / AUD-03)
 */
class PatientOtpConcurrencyAndSecurityTest
{
    private PDO $db;
    private string $baseUrl = 'http://127.0.0.1:8080';

    public function __construct()
    {
        date_default_timezone_set('America/Sao_Paulo');
        $this->db = Connection::getInstance();
    }

    public function run(): void
    {
        echo "\n========================================================================\n";
        echo ">>> TESTES E2E: SEGURANÇA, VALIDAÇÃO E CONCORRÊNCIA DO OTP (NI2-405) <<<\n";
        echo "========================================================================\n\n";

        $this->testStrictOtpFormatValidation();
        $this->testStrictOtpExpirationAndPurge();
        $this->testBruteForceRateLimitingAndOtpRevocation();
        $this->testZeroSecretLeakageInAuditLogs();
        $this->testConcurrentOtpExecutionRaceCondition();

        echo "\n========================================================================\n";
        echo ">>> TODOS OS TESTES DE SEGURANÇA E CONCORRÊNCIA DO OTP PASSARAM COM SUCESSO! <<<\n";
        echo "========================================================================\n\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException("FALHA: {$message}");
        }
        echo "  [PASS] {$message}\n";
    }

    private function createClinic(string $name): int
    {
        $uuid = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO clinics (uuid, name, status) VALUES (:uuid, :name, 'active')");
        $stmt->execute(['uuid' => $uuid, 'name' => $name]);
        return (int)$this->db->lastInsertId();
    }

    private function createPatient(int $clinicId, string $email, string $phone, ?string $otpCode, ?string $otpExpiresAt): int
    {
        $uuid = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("
            INSERT INTO patients (uuid, clinic_id, name, email, phone, status, otp_code, otp_expires_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
        ");
        $stmt->execute([$uuid, $clinicId, "Paciente Teste {$uuid}", $email, $phone, $otpCode, $otpExpiresAt]);
        return (int)$this->db->lastInsertId();
    }

    private function runHttp(string $method, string $uri, array $body = []): array
    {
        $script = $this->generateSubprocessScript($method, $uri, $body);
        $tempScript = tempnam(sys_get_temp_dir(), 'ni2_otp_');
        file_put_contents($tempScript, $script);
        $output = shell_exec("php -d display_errors=0 " . escapeshellarg($tempScript) . " 2>&1");
        @unlink($tempScript);

        $decoded = json_decode((string)$output, true);
        return $decoded ?: ['status' => 500, 'output' => $output, 'json' => []];
    }

    private function generateSubprocessScript(string $method, string $uri, array $body): string
    {
        $payloadJson = json_encode($body, JSON_UNESCAPED_UNICODE);
        $indexPath = addslashes(realpath(__DIR__ . '/../public/index.php'));

        return '<?php
            $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
            $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
            $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
            $_POST = ' . var_export($body, true) . ';
            
            // Sobrescreve stream de entrada JSON
            $rawJson = ' . var_export($payloadJson, true) . ';
            $GLOBALS["__TEST_JSON_INPUT__"] = $rawJson;

            ob_start();
            require "' . $indexPath . '";
            $output = ob_get_clean();
            $code = http_response_code() ?: 200;
            echo json_encode([
                "status" => $code,
                "output" => $output,
                "json" => json_decode($output, true),
            ]);
        ';
    }

    /**
     * 1. Validação estrita de formato (apenas 6 dígitos numéricos)
     */
    private function testStrictOtpFormatValidation(): void
    {
        echo "--- [1] Validação Estrita de Formato do Código OTP ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica Formato {$suffix}");
        $email = "paciente_fmt_{$suffix}@clyo.test";
        $phone = "1198888{$suffix}";
        $this->createPatient($clinicId, $email, $phone, '123456', date('Y-m-d H:i:s', time() + 600));

        // Letras ou alfanumérico
        $resAlpha = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => 'ABC123']);
        $this->assert($resAlpha['status'] === 400, "OTP alfanumérico ('ABC123') rejeitado com HTTP 400 Bad Request");

        // Curto (4 dígitos)
        $resShort = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => '1234']);
        $this->assert($resShort['status'] === 400, "OTP com menos de 6 dígitos ('1234') rejeitado com HTTP 400 Bad Request");

        // Longo (8 dígitos)
        $resLong = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => '12345678']);
        $this->assert($resLong['status'] === 400, "OTP com mais de 6 dígitos ('12345678') rejeitado com HTTP 400 Bad Request");

        // Não-numérico especial
        $resSpec = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => '12-456']);
        $this->assert($resSpec['status'] === 400, "OTP com caracteres especiais ('12-456') rejeitado com HTTP 400 Bad Request");
    }

    /**
     * 2. Expiração estrita e anulação de código vencido
     */
    private function testStrictOtpExpirationAndPurge(): void
    {
        echo "\n--- [2] Expiração Estrita e Invalidação de OTP Vencido ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica Expira {$suffix}");
        $email = "paciente_exp_{$suffix}@clyo.test";
        $phone = "1197777{$suffix}";
        $patientId = $this->createPatient($clinicId, $email, $phone, '654321', date('Y-m-d H:i:s', time() - 60)); // Expirou há 1 min

        $res = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => '654321']);
        $this->assert($res['status'] === 401, "OTP com data de expiração no passado rejeitado com HTTP 401");
        $this->assert(str_contains($res['json']['error'] ?? '', 'expirado'), "Mensagem informa código expirado com segurança");

        // Verifica que o OTP expirado foi anulado no banco
        $stmt = $this->db->prepare("SELECT otp_code, otp_expires_at FROM patients WHERE id = :id");
        $stmt->execute(['id' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assert($row['otp_code'] === null, "Código OTP expirado foi imediatamente anulado do banco de dados relacional");
    }

    /**
     * 3. Proteção contra força bruta e rate limit (máx 5 tentativas)
     */
    private function testBruteForceRateLimitingAndOtpRevocation(): void
    {
        echo "\n--- [3] Proteção Anti-Brute Force e Revogação por Rate Limit ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica BruteForce {$suffix}");
        $email = "paciente_bf_{$suffix}@clyo.test";
        $phone = "1196666{$suffix}";
        $validOtp = '777888';
        $patientId = $this->createPatient($clinicId, $email, $phone, $validOtp, date('Y-m-d H:i:s', time() + 600));

        // Realiza 5 tentativas incorretas
        for ($i = 1; $i <= 5; $i++) {
            $wrongOtp = sprintf('%06d', mt_rand(100000, 666666));
            $resFail = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => $wrongOtp]);
            $this->assert($resFail['status'] === 401, "Tentativa incorreta #{$i} rejeitada com HTTP 401");
        }

        // A 6ª tentativa deve ser barrada por rate limit (HTTP 429) e o OTP válido deve ser revogado
        $resBlocked = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => $validOtp]);
        $this->assert($resBlocked['status'] === 429, "6ª tentativa bloqueada por Rate Limit com HTTP 429 Too Many Requests");
        $this->assert(str_contains($resBlocked['json']['error'] ?? '', 'revogado'), "Mensagem informa revogação protetiva do código");

        // Verifica que o código no banco foi sumariamente revogado
        $stmt = $this->db->prepare("SELECT otp_code FROM patients WHERE id = :id");
        $stmt->execute(['id' => $patientId]);
        $codeInDb = $stmt->fetchColumn();
        $this->assert($codeInDb === null, "OTP válido revogado atomicamente no banco após esgotar o limite de tentativas");
    }

    /**
     * 4. Zero vazamento de segredos em logs de auditoria
     */
    private function testZeroSecretLeakageInAuditLogs(): void
    {
        echo "\n--- [4] Zero Vazamento de Código OTP em Logs de Auditoria ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica Audit {$suffix}");
        $email = "paciente_aud_{$suffix}@clyo.test";
        $phone = "1195555{$suffix}";
        $secretOtp = '999111';
        $this->createPatient($clinicId, $email, $phone, $secretOtp, date('Y-m-d H:i:s', time() + 600));

        // Login correto
        $res = $this->runHttp('POST', '/api/v1/auth/patient-login', ['email' => $email, 'otp' => $secretOtp]);
        $this->assert($res['status'] === 200, "Login legítimo com OTP efetuado com sucesso (HTTP 200)");

        // Varre todos os audit_logs recentes procurando se o código '999111' apareceu em texto claro
        $stmt = $this->db->prepare("
            SELECT details FROM audit_logs 
            WHERE created_at >= :since AND details LIKE :leakPattern
        ");
        $stmt->execute([
            'since' => date('Y-m-d H:i:s', time() - 60),
            'leakPattern' => "%{$secretOtp}%",
        ]);
        $leaks = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assert(empty($leaks), "Nenhuma ocorrência do código OTP '{$secretOtp}' encontrada na trilha de auditoria (zero secret leak)");
    }

    /**
     * 5. Teste de concorrência real com 20 processos simultâneos concorrentes
     */
    private function testConcurrentOtpExecutionRaceCondition(): void
    {
        echo "\n--- [5] Concorrência Extrema: 20 Processos Simultâneos Disputando o Mesmo OTP ---\n";

        $suffix = (string)mt_rand(100000, 999999);
        $clinicId = $this->createClinic("Clínica Concorrente {$suffix}");
        $email = "paciente_conc_{$suffix}@clyo.test";
        $phone = "1194444{$suffix}";
        $singleUseOtp = '345678';
        $patientId = $this->createPatient($clinicId, $email, $phone, $singleUseOtp, date('Y-m-d H:i:s', time() + 600));

        $totalRequests = 20;
        $body = ['email' => $email, 'otp' => $singleUseOtp];
        $scriptContent = $this->generateSubprocessScript('POST', '/api/v1/auth/patient-login', $body);

        $tempFiles = [];
        $processes = [];
        $pipes = [];

        // Inicia 20 processos simultâneos em paralelo
        for ($i = 0; $i < $totalRequests; $i++) {
            $tmp = tempnam(sys_get_temp_dir(), "ni2_race_{$i}_");
            file_put_contents($tmp, $scriptContent);
            $tempFiles[$i] = $tmp;

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $cmd = "php -d display_errors=0 " . escapeshellarg($tmp);
            $proc = proc_open($cmd, $descriptors, $procPipes);
            if (is_resource($proc)) {
                $processes[$i] = $proc;
                $pipes[$i] = $procPipes;
            }
        }

        $successCount = 0;
        $unauthorizedCount = 0;
        $rateLimitedCount = 0;
        $issuedTokens = [];

        // Coleta o retorno de todos os processos paralelos
        $otherCount = 0;
        foreach ($processes as $i => $proc) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($proc);
            @unlink($tempFiles[$i]);

            $res = json_decode((string)$stdout, true);
            $code = $res['status'] ?? 500;

            $allCodes[] = $code;
            if ($code === 200) {
                $successCount++;
                if (!empty($res['json']['token'])) {
                    $issuedTokens[] = $res['json']['token'];
                }
            } elseif ($code === 401) {
                $unauthorizedCount++;
            } elseif ($code === 429) {
                $rateLimitedCount++;
            } else {
                $otherCount++;
                fwrite(STDERR, "DEBUG OTHER STATUS: {$code} STDERR: {$stderr} OUTPUT: " . substr((string)$stdout, 0, 150) . "\n");
            }
        }

        $totalRejected = $unauthorizedCount + $rateLimitedCount;

        echo "  ➔ Resultados das {$totalRequests} requisições concorrentes disparadas em paralelo:\n";
        echo "     • HTTP 200 OK (Sucesso único)        : {$successCount}\n";
        echo "     • HTTP 401 Unauthorized (Consumido)  : {$unauthorizedCount}\n";
        echo "     • HTTP 429 Rate Limited (Bloqueado)  : {$rateLimitedCount}\n";
        echo "     • Total Rejeitadas sem Novo Token    : {$totalRejected}\n";
        echo "     • Tokens emitidos                    : " . count($issuedTokens) . "\n";

        $this->assert($successCount === 1, "Exatamente 1 processo concorrente obteve sucesso (HTTP 200)");
        $this->assert($totalRejected === ($totalRequests - 1), "Exatamente " . ($totalRequests - 1) . " requisições foram rejeitadas com erro seguro (401/429)");
        $this->assert(count($issuedTokens) === 1, "Exatamente 1 Bearer token foi emitido durante a corrida concorrente");

        // Verifica estado final do banco
        $stmtCheck = $this->db->prepare("SELECT otp_code, otp_expires_at FROM patients WHERE id = :id");
        $stmtCheck->execute(['id' => $patientId]);
        $rowCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        $this->assert($rowCheck['otp_code'] === null, "Código OTP do paciente foi consumido atomicamente e encontra-se NULL");
    }
}

// Execução
$suite = new PatientOtpConcurrencyAndSecurityTest();
$suite->run();
