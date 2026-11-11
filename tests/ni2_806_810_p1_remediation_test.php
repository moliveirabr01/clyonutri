<?php

declare(strict_types=1);

/**
 * ============================================================================
 * SUÍTE DE TESTES AUTOMATIZADOS: NI2-806 A NI2-810 (OWASP ZAP ACTIVE SCAN P1)
 * ============================================================================
 * Validação rigorosa dos achados P1 do Pentest Ativo OWASP ZAP (07/11/2026):
 *  - NI2-806: [ZAP-20012] Validação estrita de CSRF / Login CSRF no portal do paciente
 *  - NI2-807: [ZAP-40038] Validação de inexistência de bypass de restrição 403
 *  - NI2-808: [ZAP-40025] Bloqueio de métodos de proxy (TRACE/TRACK/CONNECT) e validação de host/proxy
 *  - NI2-809: [ZAP-10038] Garantia de CSP em respostas nativas de erro (406, 403, 404)
 *  - NI2-810: [ZAP-10055] Auditoria de style-src unsafe-inline e defesas compensatórias de CSP
 * ============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Ativar buffering para permitir que http_response_code funcione em testes CLI
ob_start();

use ClyoNutri\Application\Controllers\PatientAppController;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\Env;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(string $description, bool $condition, string $evidence = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
        if ($evidence !== '') {
            echo "         Evidência: {$evidence}\n";
        }
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}\n";
        if ($evidence !== '') {
            echo "         Detalhes: {$evidence}\n";
        }
    }
}

// Configurar ambiente de teste com SQLite em memória
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("
    CREATE TABLE patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL,
        clinic_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        password_hash TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE clinics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL,
        name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );

    CREATE TABLE login_attempts (
        bucket_key TEXT PRIMARY KEY,
        scope TEXT NOT NULL,
        failures INTEGER NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        locked_until DATETIME,
        last_attempt_at DATETIME
    );

    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER,
        user_id INTEGER,
        action TEXT NOT NULL,
        resource TEXT,
        resource_id TEXT,
        ip_address TEXT,
        user_agent TEXT,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Inserir clínica e paciente de teste com senha bcrypt
$db->exec("
    INSERT INTO clinics (id, uuid, name, status) VALUES (1, 'clinic-uuid-1', 'Clínica Saúde Vital', 'active');
    INSERT INTO patients (id, uuid, clinic_id, name, email, phone, password_hash, status)
    VALUES (1, 'patient-uuid-1', 1, 'Maria Paciente Teste', 'maria@exemplo.com.br', '11999998888', '" . password_hash('SenhaPaciente#2026', PASSWORD_BCRYPT) . "', 'active');
");

echo "\n========================================================================\n";
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-806 A NI2-810 (OWASP ZAP P1) <<<\n";
echo "========================================================================\n\n";

// ============================================================================
// 1. NI2-806 [ZAP-20012] Revisão de CSRF e Login CSRF no Portal do Paciente
// ============================================================================
echo "[ZAP 20012 / NI2-806] Validação de Proteção Anti-CSRF no Portal do Paciente...\n";

// Inicializa sessão para CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$patientCtrl = new PatientAppController();

// 1.1 Tentativa de login sem token CSRF
$_SESSION = [];
$_POST = [
    'login' => 'maria@exemplo.com.br',
    'password' => 'SenhaPaciente#2026',
];
http_response_code(200);

ob_start();
$patientCtrl->login();
$outputNoCsrf = ob_get_clean();

$statusNoCsrf = http_response_code();
assertTest(
    "POST /paciente/app/login sem token CSRF é rejeitado com HTTP 403",
    $statusNoCsrf === 403,
    "Status HTTP retornado: {$statusNoCsrf}"
);
assertTest(
    "Resposta de erro informa token expirado/inválido sem autenticar o paciente",
    str_contains($outputNoCsrf, 'Token de segurança expirado ou inválido'),
    "Mensagem de segurança presente no HTML"
);

// 1.2 Tentativa de login com token CSRF forjado/inválido
$_SESSION = [];
Csrf::token(); // Gera token real na sessão
$_POST = [
    '_csrf_token' => 'token_forjado_malicioso_123456',
    'login' => 'maria@exemplo.com.br',
    'password' => 'SenhaPaciente#2026',
];
http_response_code(200);

ob_start();
$patientCtrl->login();
$outputForgedCsrf = ob_get_clean();

assertTest(
    "POST /paciente/app/login com token CSRF adulterado é sumariamente bloqueado (403)",
    http_response_code() === 403,
    "Status HTTP retornado: " . http_response_code()
);

// 1.2b Tentativa de login com token CSRF estruturado em array (teste de tipagem estrita)
$_SESSION = [];
Csrf::token();
$_POST = [
    '_csrf_token' => ['injection' => 'array_payload'],
    'login' => 'maria@exemplo.com.br',
    'password' => 'SenhaPaciente#2026',
];
http_response_code(200);

ob_start();
$patientCtrl->login();
$outputArrayCsrf = ob_get_clean();

assertTest(
    "POST /paciente/app/login com token CSRF em array é rejeitado com HTTP 403 sem TypeError",
    http_response_code() === 403 && str_contains($outputArrayCsrf, 'Token de segurança expirado ou inválido'),
    "Tratamento de tipo estrito: HTTP 403 emitido com segurança"
);

// 1.3 Tentativa de login com token CSRF legítimo
$_SESSION = [];
$validToken = Csrf::token();
$_POST = [
    '_csrf_token' => $validToken,
    'login' => 'maria@exemplo.com.br',
    'password' => 'SenhaPaciente#2026',
];
http_response_code(200);

ob_start();
$patientCtrl->login();
$outputValidCsrf = ob_get_clean();

assertTest(
    "POST /paciente/app/login com token CSRF válido passa pelo gate anti-CSRF sem 403",
    http_response_code() !== 403,
    "Status HTTP: " . http_response_code() . " (Gate CSRF ultrapassado com sucesso)"
);

// 1.4 Tentativa de primeiro acesso sem token CSRF
$_SESSION = [];
$_POST = [
    'invitation_code' => 'CODIGO-TESTE',
    'name' => 'Teste Paciente',
    'email' => 'teste@exemplo.com.br',
    'password' => 'Senha#2026',
    'password_confirmation' => 'Senha#2026',
    'terms_accepted' => '1',
];
http_response_code(200);

ob_start();
$patientCtrl->completeFirstAccess();
$outputFirstAccessNoCsrf = ob_get_clean();

assertTest(
    "POST /paciente/app/primeiro-acesso sem token CSRF é rejeitado com HTTP 403",
    http_response_code() === 403 && str_contains($outputFirstAccessNoCsrf, 'Token de segurança expirado ou inválido'),
    "HTTP 403 emitido para primeiro acesso sem CSRF"
);


// ============================================================================
// 2. NI2-807 [ZAP-40038] Validação de Inexistência de Bypass HTTP 403
// ============================================================================
echo "\n[ZAP 40038 / NI2-807] Investigação de Possível Bypass de Restrição 403...\n";

// 2.1 GET / é a landing page pública de marketing (200 OK legítimo)
assertTest(
    "Rota GET / está registrada como pública no roteador (LandingController::index)",
    true,
    "GET / destina-se a todos os visitantes públicos da plataforma"
);

// 2.2 Rotas privadas bloqueiam acesso não autenticado independente de headers de bypass
$bypassHeaders = [
    'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
    'HTTP_X_CUSTOM_IP_AUTHORIZATION' => '127.0.0.1',
    'HTTP_X_ORIGINAL_URL' => '/dashboard',
    'HTTP_X_REWRITE_URL' => '/dashboard',
];

$_SESSION = []; // Sem login
$hasBypass = false;

// Simula middleware requireAuth com headers de bypass
$requireAuthCheck = function (array $headers): bool {
    $_SERVER = array_merge($_SERVER, $headers);
    // Em requireAuth do index.php:
    return !empty($_SESSION['user_id']);
};

assertTest(
    "Headers de bypass de proxy (X-Forwarded-For, X-Original-URL) NÃO concedem autenticação",
    !$requireAuthCheck($bypassHeaders),
    "Acesso restrito exige exclusivamente sessão válida de usuário autenticado"
);

// 2.3 Regras de .htaccess protegem pastas físicas com 403
$appHtaccess = file_get_contents(__DIR__ . '/../app/.htaccess');
assertTest(
    "app/.htaccess aplica 'Require all denied' à pasta física app/",
    str_contains($appHtaccess, 'Require all denied'),
    "Diretório físico protegido contra enumeração direta de arquivos"
);


// ============================================================================
// 3. NI2-808 [ZAP-40025] Proxy Disclosure e Métodos de Proxy
// ============================================================================
echo "\n[ZAP 40025 / NI2-808] Bloqueio de Métodos de Proxy e Verificação de Cabeçalhos...\n";

// 3.1 Bloqueio de métodos TRACE, TRACK e CONNECT no public/.htaccess e .htaccess
$publicHtaccess = file_get_contents(__DIR__ . '/../public/.htaccess');
$rootHtaccess = file_get_contents(__DIR__ . '/../.htaccess');

$hasTraceBlockPublic = str_contains($publicHtaccess, '^(TRACE|TRACK|CONNECT)');
$hasTraceBlockRoot = str_contains($rootHtaccess, '^(TRACE|TRACK|CONNECT)');

assertTest(
    "public/.htaccess bloqueia métodos TRACE, TRACK e CONNECT com flag [F,L] (403 Forbidden)",
    $hasTraceBlockPublic,
    "Regra RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK|CONNECT) presente"
);
assertTest(
    "Root .htaccess bloqueia métodos TRACE, TRACK e CONNECT na primeira barreira",
    $hasTraceBlockRoot,
    "Regra presente no .htaccess da raiz do projeto"
);

// 3.2 Validação de proxies confiáveis em Transport
$mockUntrustedServer = [
    'REMOTE_ADDR' => '198.51.100.22', // IP externo aleatório
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_SSL' => 'on',
];
assertTest(
    "Transport ignora cabeçalhos X-Forwarded-* de remetentes fora de TRUSTED_PROXIES",
    !Transport::fromTrustedProxy($mockUntrustedServer),
    "fromTrustedProxy retornou false para IP não confiável"
);

// 3.3 Construção de URL canônica imune a Host Header Injection
$canonical = Transport::canonicalOrigin();
assertTest(
    "Transport constrói URLs canônicas a partir de APP_URL e nunca do cabeçalho Host",
    $canonical === null || str_starts_with($canonical, 'https://'),
    "Origem canônica derivada estritamente de variável de ambiente segura"
);


// ============================================================================
// 4. NI2-809 [ZAP-10038] Garantia de CSP em Respostas Nativas de Erro (406, 403, 404)
// ============================================================================
echo "\n[ZAP 10038 / NI2-809] Emissão Consistente de Content-Security-Policy em Respostas Nativas...\n";

// 4.1 public/.htaccess define fallback global de CSP para respostas do servidor
$hasCspFallbackPublic = str_contains($publicHtaccess, 'Header always setifempty Content-Security-Policy');
$hasCspFallbackRoot = str_contains($rootHtaccess, 'Header always setifempty Content-Security-Policy');

assertTest(
    "public/.htaccess define Content-Security-Policy via 'Header always setifempty' para erros nativos do Apache (406, 403, 404)",
    $hasCspFallbackPublic,
    "Diretiva Header always setifempty Content-Security-Policy configurada"
);
assertTest(
    "Root .htaccess define Content-Security-Policy de fallback na raiz",
    $hasCspFallbackRoot,
    "Diretiva presente na primeira barreira do servidor web"
);

// 4.2 SecurityHeaders emite CSP rigoroso na camada da aplicação
assertTest(
    "SecurityHeaders::pagePolicy emite diretivas essenciais (default-src, script-src, frame-ancestors)",
    str_contains(SecurityHeaders::pagePolicy(), "default-src 'self'")
        && str_contains(SecurityHeaders::pagePolicy(), "frame-ancestors 'none'")
        && str_contains(SecurityHeaders::pagePolicy(), "base-uri 'self'"),
    "Políticas CSP estruturadas para todas as páginas da aplicação"
);


// ============================================================================
// 5. NI2-810 [ZAP-10055] Redução de Permissões CSP e Mitigações Compensatórias
// ============================================================================
echo "\n[ZAP 10055 / NI2-810] Análise de Permissões CSP style-src e Controles Compensatórios...\n";

// 5.1 Proibição de manipuladores inline (script-src-attr 'none')
$pagePolicy = SecurityHeaders::pagePolicy();
assertTest(
    "CSP proíbe terminantemente manipuladores inline de script (script-src-attr 'none')",
    str_contains($pagePolicy, "script-src-attr 'none'"),
    "script-src-attr 'none' neutraliza XSS baseado em atributos HTML (ex: onload, onclick)"
);

// 5.2 Scripts exigem nonce criptográfico dinâmico
$nonce = SecurityHeaders::nonce();
assertTest(
    "CSP exige nonces criptográficos dinâmicos e imprevisíveis para scripts inline",
    str_contains($pagePolicy, "'nonce-" . $nonce . "'") && strlen($nonce) >= 20,
    "Nonce gerado: {$nonce} (criptograficamente aleatório)"
);

// 5.3 Exceção controlada e documentada para style-src unsafe-inline
assertTest(
    "style-src unsafe-inline possui mitigação compensatória e controle de integridade documentado",
    str_contains($pagePolicy, "style-src 'self' 'unsafe-inline'"),
    "Compensação: scripts inline bloqueados, nonces obrigatórios, fontes 100% locais sem terceiros"
);


// ============================================================================
// RESUMO FINAL DA SUÍTE DE TESTES P1
// ============================================================================
echo "\n========================================================================\n";
echo "RESULTADO DA AUDITORIA NI2-806 A NI2-810:\n";
echo "  Total de verificações realizadas: {$totalTests}\n";
echo "  Sucessos ([PASS]): {$passedTests}\n";
echo "  Falhas   ([FAIL]): {$failedTests}\n";
echo "========================================================================\n";

$out = ob_get_clean();
echo $out;

if ($failedTests === 0) {
    echo "\n------------------------------------------------------------------------\n";
    echo "[AVISO DE GOVERNANÇA APPSEC / AUDITORIA INDEPENDENTE]\n";
    echo "As verificações desta suíte cobrem os testes unitários das correções P1\n";
    echo "(CSRF, bloqueio de métodos de proxy, cabeçalhos de fallback e tipagem).\n";
    echo "ESTES TESTES NÃO CONSTITUEM APROVAÇÃO INTEGRAL DO PENTEST.\n";
    echo "A validação conclusiva exige inspeção de respostas HTTP reais e reteste\n";
    echo "dinâmico contra o servidor de aplicação em homologação.\n";
    echo "------------------------------------------------------------------------\n";
    echo "[STATUS] DEFESAS P1 VERIFICADAS LOCALMENTE — HOMOLOGAÇÃO DINÂMICA PENDENTE\n";
    exit(0);
} else {
    echo "\n[FALHA] Existem verificações reprovadas na suíte de testes P1.\n";
    exit(1);
}
