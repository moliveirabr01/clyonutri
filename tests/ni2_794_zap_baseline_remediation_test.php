<?php

declare(strict_types=1);

/**
 * Suíte de Testes Automatizados da História: NI2-794
 * [SECURITY][ZAP] Remediação e validação dos findings do Baseline OWASP ZAP (07/11/2026)
 *
 * Cobre os achados do ZAP Baseline:
 * - 10031: User Controllable HTML Element Attribute (parâmetro tema na vitrine /nutricionistas)
 * - 10035: HSTS consistente em assets estáticos
 * - 90004: Cross-Origin-Resource-Policy (CORP) e avaliação de COEP
 * - 10063: Permissions-Policy em respostas de assets/JS
 * - 10015 / 10049: Cache-Control para assets estáticos vs. páginas dinâmicas
 * - 10055: CSP style-src unsafe-inline documentado com defesa em profundidade
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\BookingController;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Security\Env;

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-794 (OWASP ZAP BASELINE) <<<\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// 1. Finding 10031: Validação do Parâmetro 'tema' (/nutricionistas)
// -------------------------------------------------------------------------
echo "[ZAP 10031] Validação de Atributos e Prevenção de Injeção em 'tema'...\n";

assertCondition(
    is_array(BookingController::DIRECTORY_TOPICS) && count(BookingController::DIRECTORY_TOPICS) === 8,
    "BookingController declara lista canônica de temas permitidos (DIRECTORY_TOPICS)"
);

// Simulação de controlador para teste de unidade
$db = \ClyoNutri\Core\Database\Connection::getInstance();
$bookingCtrl = new BookingController($db);

// Caso Positivo: Tema canônico exato
$_GET = ['tema' => 'Emagrecimento'];
ob_start();
$bookingCtrl->publicDirectoryPage();
$outputValid = ob_get_clean();
assertCondition(
    str_contains($outputValid, 'name="tema" value="Emagrecimento"'),
    "Tema válido 'Emagrecimento' é aceito e refletido com segurança no formulário"
);

// Caso Positivo: Tema em minúsculas (normalização case-insensitive)
$_GET = ['tema' => 'saúde da mulher'];
ob_start();
$bookingCtrl->publicDirectoryPage();
$outputCase = ob_get_clean();
assertCondition(
    str_contains($outputCase, 'name="tema" value="Saúde da Mulher"'),
    "Tema com capitalização diferente é canonicamente normalizado para 'Saúde da Mulher'"
);

// Caso Negativo: Tentativa de injeção de atributo / XSS
$_GET = ['tema' => '"><script>alert(1)</script><input type="text'];
ob_start();
$bookingCtrl->publicDirectoryPage();
$outputPayload = ob_get_clean();
assertCondition(
    !str_contains($outputPayload, 'alert(1)') && !str_contains($outputPayload, '"><script>'),
    "Vetor malicioso em 'tema' é completamente neutralizado e rejeitado pelo filtro"
);
assertCondition(
    !str_contains($outputPayload, 'name="tema"'),
    "Parâmetro malicioso não é renderizado em campos hidden"
);

// Caso Negativo: Tema arbitrário não constante na lista
$_GET = ['tema' => 'AssuntoArbitrarioInvalido'];
ob_start();
$bookingCtrl->publicDirectoryPage();
$outputUnknown = ob_get_clean();
assertCondition(
    !str_contains($outputUnknown, 'name="tema" value="AssuntoArbitrarioInvalido"'),
    "Tema não reconhecido fora da whitelist é descartado (fail-closed)"
);

// Limpeza de ambiente de teste
$_GET = [];

// -------------------------------------------------------------------------
// 2. Finding 10035: HSTS Consistente em Camada de Entrega e Assets
// -------------------------------------------------------------------------
echo "\n[ZAP 10035] HSTS em Camada de Entrega e Assets Estáticos...\n";

$publicHtaccess = file_get_contents(__DIR__ . '/../public/.htaccess');
$rootHtaccess = file_get_contents(__DIR__ . '/../.htaccess');

assertCondition(
    str_contains($publicHtaccess, 'Header always setifempty Strict-Transport-Security "max-age=86400" "expr=%{HTTPS} == \'on\''),
    "public/.htaccess define Strict-Transport-Security (baseline gradual 86400) para conexões HTTPS garantindo cobertura de assets"
);
assertCondition(
    str_contains($rootHtaccess, 'Header always setifempty Strict-Transport-Security "max-age=86400" "expr=%{HTTPS} == \'on\''),
    "Root .htaccess define Strict-Transport-Security (baseline gradual 86400) como primeira barreira de entrega HTTPS"
);

// -------------------------------------------------------------------------
// 3. Finding 90004: CORP (Cross-Origin-Resource-Policy) e Avaliação de COEP
// -------------------------------------------------------------------------
echo "\n[ZAP 90004] Cross-Origin-Resource-Policy (CORP) e COEP...\n";

$secHeadersSrc = file_get_contents(__DIR__ . '/../src/Core/Http/SecurityHeaders.php');

assertCondition(
    str_contains($secHeadersSrc, "header('Cross-Origin-Resource-Policy: same-origin'"),
    "SecurityHeaders emite Cross-Origin-Resource-Policy: same-origin em respostas de aplicação"
);
assertCondition(
    str_contains($publicHtaccess, 'Header set Cross-Origin-Resource-Policy "same-origin"'),
    "public/.htaccess emite Cross-Origin-Resource-Policy: same-origin em arquivos estáticos"
);

// Avaliação do COEP: off por padrão para compatibilidade com WebRTC / Daily.co
assertCondition(
    SecurityHeaders::coepValue() === null,
    "COEP permanece desativado por padrão (COEP_MODE=off) para prevenir bloqueio de embeds de teleconsulta"
);

putenv('COEP_MODE=require-corp');
assertCondition(
    SecurityHeaders::coepValue() === 'require-corp',
    "SecurityHeaders suporta ativação configurável de COEP (require-corp) quando exigido pelo ambiente"
);
putenv('COEP_MODE'); // reset

// -------------------------------------------------------------------------
// 4. Finding 10063: Permissions-Policy em Respostas de Assets Estáticos
// -------------------------------------------------------------------------
echo "\n[ZAP 10063] Permissions-Policy em Assets e JavaScript...\n";

assertCondition(
    str_contains($publicHtaccess, 'Header set Permissions-Policy "camera=(), microphone=(), display-capture=(), geolocation=(), payment=()"'),
    "public/.htaccess emite Permissions-Policy restritivo em arquivos JavaScript e estáticos"
);
assertCondition(
    str_contains($secHeadersSrc, "Permissions-Policy: camera=(self)"),
    "SecurityHeaders emite Permissions-Policy estrito para a aplicação web"
);

// -------------------------------------------------------------------------
// 5. Findings 10015 / 10049: Cache-Control de Assets vs. Páginas Dinâmicas
// -------------------------------------------------------------------------
echo "\n[ZAP 10015 / 10049] Diretivas de Cache-Control por Tipo de Conteúdo...\n";

assertCondition(
    str_contains($publicHtaccess, 'Header set Cache-Control "public, max-age=604800, stale-while-revalidate=86400"'),
    "public/.htaccess define cache estático com stale-while-revalidate para arquivos de mídia/assets"
);

$bookingSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/BookingController.php');
assertCondition(
    str_contains($bookingSrc, "header('Cache-Control: no-store')"),
    "BookingController mantém Cache-Control: no-store em busca dinâmica com rate limiting"
);

// -------------------------------------------------------------------------
// 6. Finding 10055: CSP style-src e Defesa em Profundidade
// -------------------------------------------------------------------------
echo "\n[ZAP 10055] CSP e Defesa em Profundidade...\n";

$pagePolicy = SecurityHeaders::pagePolicy();
assertCondition(
    str_contains($pagePolicy, "style-src 'self' 'unsafe-inline'"),
    "CSP mantém exceção documentada 'unsafe-inline' para style-src devido a atributos de estilo legados"
);
assertCondition(
    str_contains($pagePolicy, "script-src-attr 'none'"),
    "CSP proíbe estritamente manipuladores de eventos inline (script-src-attr 'none') como mitigação compensatória"
);
assertCondition(
    str_contains($pagePolicy, "default-src 'self'") && str_contains($pagePolicy, "object-src 'none'"),
    "CSP impõe isolamento estrito de plugins e fontes padrão"
);

// -------------------------------------------------------------------------
// RESULTADO FINAL
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULTADO DA AUDITORIA NI2-794:\n";
echo "  Total de verificações realizadas: " . ($passed + $failed) . "\n";
echo "  Sucessos ([PASS]): {$passed}\n";
echo "  Falhas   ([FAIL]): {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] Foram detectadas {$failed} falhas na validação local do ZAP Baseline.\n";
    exit(1);
} else {
    echo "\n------------------------------------------------------------------------\n";
    echo "[AVISO DE GOVERNANÇA APPSEC / AUDITORIA INDEPENDENTE]\n";
    echo "As verificações desta suíte cobrem os testes unitários de headers e configurações.\n";
    echo "ESTES TESTES NÃO CONSTITUEM APROVAÇÃO INTEGRAL DO PENTEST.\n";
    echo "A validação conclusiva exige inspeção de tráfego HTTP e re-scan dinâmico em homologação.\n";
    echo "------------------------------------------------------------------------\n";
    echo "[STATUS] DEFESAS BASELINE VERIFICADAS LOCALMENTE — VALIDAÇÃO DINÂMICA PENDENTE\n";
    exit(0);
}
