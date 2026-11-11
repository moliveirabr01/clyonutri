<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\BookingController;
use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Observability\StructuredLogger;
use ClyoNutri\Core\Security\PublicBookingRateLimiter;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\LaboratoryExam\ExamFileInspector;

$db = Connection::getInstance();
$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "====================================================================\n";
echo "   CLYONUTRI II — SUITE DE TESTES: FINDINGS DE SEGURANÇA P2 E P3\n";
echo "====================================================================\n\n";

// =============================================================================
// TESTE 1: SEC-F003 — Rate Limiting em Rotas Públicas de Booking e Vitrine
// =============================================================================
echo "--- [1] SEC-F003: PublicBookingRateLimiter por IP e Ação ---\n";

$testIp = '203.0.113.199';
$_SERVER['REMOTE_ADDR'] = $testIp;

// Limpar tentativas anteriores de booking
$db->exec("DELETE FROM login_attempts WHERE scope = 'public_booking'");

putenv('PUBLIC_BOOKING_RATE_LIMIT_DIRECTORY=3');
$limiter = new PublicBookingRateLimiter($db);

$r1 = $limiter->attempt('directory', $testIp);
assertTest($r1['allowed'] === true && $r1['retry_after'] === 0, 'Primeira requisição permitida na vitrine');

$r2 = $limiter->attempt('directory', $testIp);
assertTest($r2['allowed'] === true, 'Segunda requisição permitida');

$r3 = $limiter->attempt('directory', $testIp);
assertTest($r3['allowed'] === true, 'Terceira requisição permitida (atinge limite 3)');

$r4 = $limiter->attempt('directory', $testIp);
assertTest($r4['allowed'] === false && $r4['retry_after'] > 0, 'Quarta requisição bloqueada com HTTP 429 e retry_after');

// Verificar log em audit_logs
$auditCheck = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'PUBLIC_BOOKING_RATE_LIMITED' AND ip_address = ?");
$auditCheck->execute([$testIp]);
assertTest((int)$auditCheck->fetchColumn() >= 1, 'Evento PUBLIC_BOOKING_RATE_LIMITED registrado na trilha de auditoria');

// Testar Controller BookingController com IP bloqueado
$bookingCtrl = new BookingController($db);

ob_start();
$bookingCtrl->publicDirectoryPage();
$outputHtml = ob_get_clean();

assertTest(str_contains($outputHtml, 'Muitas requisições em pouco tempo') && str_contains($outputHtml, 'card-rate-limit'), 'BookingController::publicDirectoryPage renderiza página de bloqueio 429 sob excesso');

// =============================================================================
// TESTE 2: SEC-F004 — Diagnóstico e Observabilidade do Antivírus de Laudos
// =============================================================================
echo "\n--- [2] SEC-F004: Diagnóstico e Observabilidade do Antivírus (ClamAV) ---\n";

putenv('EXAM_FILES_ANTIVIRUS=auto');
$avInfoAuto = ExamFileInspector::describeAntivirus();
assertTest(is_array($avInfoAuto) && isset($avInfoAuto['status'], $avInfoAuto['mode']), 'describeAntivirus() retorna estrutura completa');
assertTest($avInfoAuto['mode'] === 'auto', 'Modo auto identificado corretamente');

putenv('EXAM_FILES_ANTIVIRUS=required');
$avInfoReq = ExamFileInspector::describeAntivirus();
assertTest($avInfoReq['mode'] === 'required', 'Modo required identificado');

putenv('EXAM_FILES_ANTIVIRUS=off');
$avInfoOff = ExamFileInspector::describeAntivirus();
assertTest($avInfoOff['mode'] === 'off' && $avInfoOff['ready'] === true, 'Modo off reporta ready=true e status informativo');

// Reset para auto
putenv('EXAM_FILES_ANTIVIRUS=auto');

// Testar emissão de StructuredLogger ao inspecionar sem ClamAV
$logCapture = [];
StructuredLogger::$sink = static function (string $json) use (&$logCapture): void {
    $logCapture[] = json_decode($json, true);
};

// Criar PDF temporário válido
$tmpPdf = tempnam(sys_get_temp_dir(), 'exam_test_') . '.pdf';
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");

$inspector = new ExamFileInspector(antivirus: static fn() => ['available' => false, 'clean' => null, 'engine' => 'none'], mode: 'auto');
$inspected = $inspector->inspect($tmpPdf, 'laudo.pdf');
@unlink($tmpPdf);

$foundWarning = false;
foreach ($logCapture as $log) {
    if (($log['event'] ?? '') === 'EXAM_ANTIVIRUS_UNAVAILABLE') {
        $foundWarning = true;
        break;
    }
}
StructuredLogger::$sink = null;
assertTest($foundWarning, 'StructuredLogger::warning(EXAM_ANTIVIRUS_UNAVAILABLE) emitido quando ClamAV está indisponível');
assertTest(str_contains($inspected['security_check'], 'antivirus:indisponivel'), 'security_check registra antivirus:indisponivel com transparência');

// =============================================================================
// TESTE 3: SEC-F005 — Sessão Única e Encerramento de Sessões Concorrentes
// =============================================================================
echo "\n--- [3] SEC-F005: Sessão Única e Invalidação Imediata por auth_version ---\n";

PlatformSettings::clearCache();
assertTest(PlatformSettings::isSingleSessionEnforced() === false, 'PlatformSettings::isSingleSessionEnforced() padrão é false (0)');

$authService = new AuthService($db);

// Obter usuário de teste existente
$userSt = $db->query("SELECT id, auth_version FROM users WHERE role = 'nutritionist' AND status = 'active' LIMIT 1");
$userRow = $userSt->fetch(\PDO::FETCH_ASSOC);
$userId = (int)$userRow['id'];
$initialVersion = (int)$userRow['auth_version'];

// Simular bumpAuthVersion
$newVersion = $authService->bumpAuthVersion($userId);
assertTest($newVersion === $initialVersion + 1, "bumpAuthVersion incrementa versão de {$initialVersion} para {$newVersion}");

// Validar que uma sessão antiga com initialVersion é invalidada pelo AuthContext
$_SESSION['user_id'] = $userId;
$_SESSION['clinic_id'] = 1;
$_SESSION['auth_version'] = $initialVersion; // versão antiga
$_SESSION['user_role'] = 'nutritionist';

$contextOld = AuthContext::resolveFromSession($db);
assertTest($contextOld === null, 'Sessão anterior com auth_version desatualizada é rejeitada pelo AuthContext (fail-closed e limpa $_SESSION)');

// Validar que a nova sessão iniciada com $newVersion é aceita
$_SESSION['user_id'] = $userId;
$_SESSION['clinic_id'] = 1;
$_SESSION['auth_version'] = $newVersion;
$_SESSION['user_role'] = 'nutritionist';

$contextNew = AuthContext::resolveFromSession($db);
assertTest($contextNew !== null && $contextNew->getUserId() === $userId, 'Nova sessão com auth_version atualizada é aceita com sucesso');

echo "\n====================================================================\n";
echo "  RESULTADO DOS TESTES DOS FINDINGS P2 E P3:\n";
echo "  Total de verificações executadas: " . ($passed + $failed) . "\n";
echo "  Sucessos: {$passed} | Falhas: {$failed}\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
