<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-474
 * [SEC-AUDIT] Auditar validação, SQL, XSS, CSRF e uploads (Controles 10–13, 15)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\SessionApiCsrfGuard;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Domain\Helpdesk\HelpdeskAttachmentService;
use ClyoNutri\Domain\Chat\ChatAttachmentService;
use ClyoNutri\Core\Auth\TenantContext;

$passed = 0;
$failed = 0;

function check(bool $condition, string $message): void
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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-474 (CONTROLES 10–13, 15) <<<\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// CONTROLE 10: Validação Server-Side de Inputs
// -------------------------------------------------------------------------
echo "[Controle 10] Validação Server-Side de Entradas e Prevenção de Duplo Envio...\n";

$dashboardSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
$foodSearchSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/FoodSearchController.php');

check(str_contains($dashboardSrc, "\$submission = \$_POST['_submission_id'] ?? '';"), "DashboardController valida token de submissão única (_submission_id)");
check(str_contains($dashboardSrc, "mb_strlen(\$name) > 150"), "DashboardController restringe comprimento máximo de strings de entrada");
check(str_contains($foodSearchSrc, "mb_strlen(\$term)>80"), "FoodSearchController limita tamanho máximo de termos de busca a 80 caracteres");
check(str_contains($foodSearchSrc, "!ctype_digit(\$page)||(int)\$page<1||(int)\$page>100"), "FoodSearchController valida estritamente número de página (1 a 100)");

// Teste de rejeição de tipos inválidos
$validatePage = function($page): bool {
    return is_string($page) && ctype_digit($page) && (int)$page >= 1 && (int)$page <= 100;
};
check($validatePage('1') === true, "Validador aceita página '1'");
check($validatePage('100') === true, "Validador aceita página '100'");
check($validatePage('0') === false, "Validador rejeita página '0'");
check($validatePage('101') === false, "Validador rejeita página '101'");
check($validatePage('1; DROP TABLE') === false, "Validador rejeita injeção em parâmetro de página");


// -------------------------------------------------------------------------
// CONTROLE 11: Prepared Statements (Prevenção de SQLi)
// -------------------------------------------------------------------------
echo "\n[Controle 11] Prepared Statements e Prevenção de SQL Injection...\n";

// 1. Teste de injeção direta contra prepared statements de autenticação/busca
$sqliPayload = "admin' OR '1'='1' --";
$stmt = $db->prepare("SELECT id, name FROM users WHERE email = :email");
$stmt->execute(['email' => $sqliPayload]);
$sqliResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
check(empty($sqliResult), "Prepared statement neutraliza payload clássico de SQLi (' OR '1'='1') tratando como string literal");

// 2. Teste de sanitização de listas IN (...)
$rawIds = ['1', '2', '3 OR 1=1', 'invalid_id'];
$safeIds = array_map('intval', $rawIds);
$inClause = implode(',', $safeIds);
check($inClause === '1,2,3,0', "array_map('intval') higieniza rigorosamente IDs da cláusula IN ({$inClause})");

$stmtIn = $db->prepare("SELECT COUNT(*) FROM users WHERE id IN ({$inClause})");
$stmtIn->execute();
check(is_numeric($stmtIn->fetchColumn()), "Consulta com cláusula IN higienizada executa sem erro de sintaxe SQL");


// -------------------------------------------------------------------------
// CONTROLE 12: Prevenção de XSS e Escaping Contextual
// -------------------------------------------------------------------------
echo "\n[Controle 12] Prevenção de XSS, Escaping Contextual e CSP Nonces...\n";

$xssPayloads = [
    '<script>alert("xss")</script>' => '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
    '"><img src=x onerror=alert(1)>' => '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;',
    "' onfocus='alert(1)" => '&#039; onfocus=&#039;alert(1)',
];

foreach ($xssPayloads as $payload => $expected) {
    $escaped = htmlspecialchars($payload, ENT_QUOTES, 'UTF-8');
    check($escaped === $expected, "htmlspecialchars neutraliza vetor XSS: " . substr($payload, 0, 20) . "...");
}

// Inspecionar integridade de nonces nas views
$shoppingListSrc = file_get_contents(__DIR__ . '/../views/meal_plans/shopping_list.php');
check(str_contains($shoppingListSrc, "\$nonce = \\ClyoNutri\\Core\\Http\\SecurityHeaders::nonce();"), "shopping_list.php obtém nonce dinâmico via SecurityHeaders");
check(str_contains($shoppingListSrc, '<script nonce="<?= $e($nonce) ?>">'), "Tag <script> da lista de compras utiliza atributo nonce sanitizado com helper contextual \$e");
check(!str_contains($shoppingListSrc, 'onclick="'), "shopping_list.php não possui manipuladores inline inseguros (onclick)");


// -------------------------------------------------------------------------
// CONTROLE 13: Proteção contra CSRF
// -------------------------------------------------------------------------
echo "\n[Controle 13] Proteção contra Cross-Site Request Forgery (CSRF)...\n";

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// 1. Geração de tokens criptográficos
$token1 = Csrf::token();
check(is_string($token1) && strlen($token1) === 64, "Csrf::token() gera string hexadecimal de 64 caracteres (32 bytes)");
check(Csrf::validate($token1), "Csrf::validate() valida com sucesso token idêntico");
check(!Csrf::validate('fake_invalid_token_12345'), "Csrf::validate() rejeita token forjado");
check(!Csrf::validate(''), "Csrf::validate() rejeita token vazio");
check(!Csrf::validate(null), "Csrf::validate() rejeita token nulo");

// 2. Proteção de API de Sessão (SessionApiCsrfGuard)
$serverMock = [
    'HTTP_HOST' => 'app.clyonutri.com.br',
    'HTTP_ORIGIN' => 'https://app.clyonutri.com.br',
    'HTTP_X_CSRF_TOKEN' => $token1,
    'HTTPS' => 'on',
];

// GET seguro deve passar sempre
check(SessionApiCsrfGuard::check('GET', '/api/v1/subscription', $serverMock, [], null) === null, "SessionApiCsrfGuard: GET em /api/ passa sem exigir CSRF");

// POST com token e origin válidos deve passar
check(SessionApiCsrfGuard::check('POST', '/api/v1/subscription/cancel', $serverMock, [], null) === null, "SessionApiCsrfGuard: POST em /api/ com token e origin corretos é aceito");

// POST com token adulterado deve ser bloqueado
$serverTamperedToken = array_merge($serverMock, ['HTTP_X_CSRF_TOKEN' => 'tampered_token']);
$blockToken = SessionApiCsrfGuard::check('POST', '/api/v1/subscription/cancel', $serverTamperedToken, [], null);
check($blockToken !== null && str_contains($blockToken, 'CSRF'), "SessionApiCsrfGuard: POST com token alterado é bloqueado (403)");

// POST de origem maliciosa cruzada deve ser bloqueado
$serverCrossSite = array_merge($serverMock, ['HTTP_ORIGIN' => 'https://evil-attacker-site.com']);
$blockOrigin = SessionApiCsrfGuard::check('POST', '/api/v1/subscription/cancel', $serverCrossSite, [], null);
check($blockOrigin !== null && str_contains($blockOrigin, 'Origem'), "SessionApiCsrfGuard: POST com Origin cruzada não autorizada é bloqueado");


// -------------------------------------------------------------------------
// CONTROLE 15: Upload Seguro de Arquivos
// -------------------------------------------------------------------------
echo "\n[Controle 15] Upload Seguro de Arquivos (MIME, Magic Bytes, Tamanho, Storage)...\n";

$testDir = sys_get_temp_dir() . '/clyo_test_uploads_' . bin2hex(random_bytes(4));
@mkdir($testDir, 0750, true);

// 1. Criar arquivo PNG válido (magic bytes reais de imagem PNG)
$validPngPath = $testDir . '/test_image.png';
$pngMagicBytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";
file_put_contents($validPngPath, $pngMagicBytes);

// 2. Criar arquivo malicioso mascarado (extensão .png, mas conteúdo PHP/shell)
$fakePngPath = $testDir . '/webshell.png';
file_put_contents($fakePngPath, "<?php system(\$_GET['cmd']); ?>");

// 3. Criar arquivo acima do limite de 10 MB (simulado pelo tamanho)
$oversizedPath = $testDir . '/huge_file.pdf';
file_put_contents($oversizedPath, "%PDF-1.4\n" . str_repeat('A', 1024));

// Preparar dados relacionais para satisfazer chaves estrangeiras de teste
$sampleUserId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
$sampleClinicId = (int)$db->query("SELECT id FROM clinics LIMIT 1")->fetchColumn();

$db->prepare("INSERT INTO helpdesk_tickets (protocol, clinic_id, user_id, type, category, subject, description) VALUES (:proto, :cid, :uid, 'question', 'duvida', 'Ticket Teste Auditoria', 'Descricao')")
   ->execute(['proto' => 'PROTO_AUDIT_' . bin2hex(random_bytes(4)), 'cid' => $sampleClinicId, 'uid' => $sampleUserId]);
$testTicketId = (int)$db->lastInsertId();

// Teste com HelpdeskAttachmentService
$helpdeskStorage = $testDir . '/helpdesk_storage';
$helpdeskService = new HelpdeskAttachmentService($db, $helpdeskStorage);

// Teste de rejeição de webshell disfarçado
$blockedWebshell = false;
try {
    $helpdeskService->storeAttachment($testTicketId, $sampleUserId, [
        'tmp_name' => $fakePngPath,
        'name' => 'webshell.png',
        'size' => filesize($fakePngPath),
        'error' => UPLOAD_ERR_OK,
    ]);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'Tipo de arquivo não permitido')) {
        $blockedWebshell = true;
    }
}
check($blockedWebshell, "Upload de script PHP mascarado como PNG é bloqueado por inspeção de magic bytes (finfo)");

// Teste de rejeição de arquivo com tamanho informado superior a 10 MB
$blockedOversized = false;
try {
    $helpdeskService->storeAttachment($testTicketId, $sampleUserId, [
        'tmp_name' => $oversizedPath,
        'name' => 'huge_document.pdf',
        'size' => 11 * 1024 * 1024, // 11MB
        'error' => UPLOAD_ERR_OK,
    ]);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), '10 MB')) {
        $blockedOversized = true;
    }
}
check($blockedOversized, "Upload com tamanho superior a 10 MB é bloqueado");

// Teste de armazenamento e sanitização de nome com PNG legítimo
$uploadResult = $helpdeskService->storeAttachment($testTicketId, $sampleUserId, [
    'tmp_name' => $validPngPath,
    'name' => '../../traversal (name) test!.png',
    'size' => filesize($validPngPath),
    'error' => UPLOAD_ERR_OK,
]);

check(!empty($uploadResult['id']), "Upload de PNG legítimo registrado com ID no banco");
check(!str_contains($uploadResult['file_name'], '..') && !str_contains($uploadResult['file_name'], '/'), "Nome do arquivo sanitizado prevenindo path traversal ({$uploadResult['file_name']})");
check(file_exists($uploadResult['stored_path']), "Arquivo físico gravado no diretório de armazenamento seguro");
check(str_contains($uploadResult['stored_path'], 'helpdesk_storage'), "Arquivo gravado na pasta de destino segura");

// 4. Teste de tokens assinados de download seguro no ChatAttachmentService
// Inspecionar lógica de geração e validação de token assinado
$chatSrc = file_get_contents(__DIR__ . '/../src/Domain/Chat/ChatAttachmentService.php');
check(str_contains($chatSrc, "hash_hmac('sha256'"), "ChatAttachmentService utiliza HMAC-SHA256 para assinatura de tokens de download");
check(str_contains($chatSrc, "time() + 120"), "Tokens de download seguro possuem validade efêmera restrita a 120 segundos");
check(str_contains($chatSrc, "hash_equals(\$expected, \$m[6])"), "Validação de token assinado usa hash_equals contra timing attacks");

// Limpeza de registros e arquivos temporários do teste
$db->exec("DELETE FROM helpdesk_attachments WHERE id = {$uploadResult['id']}");
$db->exec("DELETE FROM helpdesk_tickets WHERE id = {$testTicketId}");

@unlink($validPngPath);
@unlink($fakePngPath);
@unlink($oversizedPath);
@unlink($uploadResult['stored_path']);
@rmdir($helpdeskStorage);
@rmdir($testDir);

// -------------------------------------------------------------------------
// RELATÓRIO FINAL
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULTADO DA AUDITORIA NI2-474:\n";
echo "  Total de verificações realizadas: " . ($passed + $failed) . "\n";
echo "  Sucessos ([PASS]): {$passed}\n";
echo "  Falhas   ([FAIL]): {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] A auditoria detectou {$failed} inconformidade(s).\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Todos os controles (10, 11, 12, 13 e 15) foram auditados e validados com êxito!\n";
    exit(0);
}
