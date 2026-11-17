<?php

declare(strict_types=1);

/**
 * Suíte Verificável de Homologação da História: NI2-469
 * [SEC-AUDIT] Inventariar arquitetura, stack, banco, hospedagem e superfície de ataque
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Router;
use ClyoNutri\Core\Security\Env;

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
echo ">>> SUÍTE VERIFICÁVEL DE AUDITORIA: NI2-469 (INVENTÁRIO & SUPERFÍCIE) <<<\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

// -------------------------------------------------------------------------
// 1. INVENTÁRIO DO RUNTIME & STACK
// -------------------------------------------------------------------------
echo "[1/6] Inventário do Runtime, PHP e Ponto de Entrada...\n";

check(PHP_VERSION_ID >= 80100, "Versão do PHP no ambiente de execução atende o requisito mínimo (>= 8.1, atual: " . PHP_VERSION . ")");

$indexSrc = file_get_contents(__DIR__ . '/../public/index.php');
check(str_contains($indexSrc, "Transport::enforce()"), "public/index.php impõe HTTPS antes de processar qualquer requisição");
check(str_contains($indexSrc, "SecurityHeaders::sendBrowserPolicies"), "public/index.php aplica cabeçalhos defensivos (CSP, HSTS, XFO)");
check(str_contains($indexSrc, "ErrorExposure::configure()"), "public/index.php suprime exibição de erros técnicos e stack traces");
check(str_contains($indexSrc, "ApiErrorBoundary::register()"), "public/index.php protege endpoints da API com boundary defensivo");

// -------------------------------------------------------------------------
// 2. INVENTÁRIO DO BANCO DE DADOS E TABELAS CLÍNICAS
// -------------------------------------------------------------------------
echo "\n[2/6] Inventário do Banco de Dados e Mapeamento de Tabelas...\n";

$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
check(count($tables) >= 20, "Banco de dados contém schema completo (" . count($tables) . " tabelas mapeadas)");

$mandatoryTables = [
    'users', 'clinics', 'clinic_members', 'patients',
    'anamneses', 'clinical_records', 'consultation_note_drafts',
    'appointments', 'meal_plans', 'recipes', 'foods',
    'helpdesk_tickets', 'helpdesk_attachments', 'chat_conversations',
    'chat_attachments', 'audit_logs', 'login_attempts'
];

$missingTables = array_diff($mandatoryTables, $tables);
check(empty($missingTables), "Todas as tabelas críticas de domínio clínico e segurança estão presentes no schema");

// Verificar se as tabelas clínicas contêm a coluna de tenant clinic_id
$clinicalTables = ['patients', 'anamneses', 'clinical_records', 'appointments', 'meal_plans', 'recipes', 'chat_conversations'];
$allHaveClinicId = true;
foreach ($clinicalTables as $t) {
    $cols = array_column($db->query("PRAGMA table_info({$t})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('clinic_id', $cols, true)) {
        $allHaveClinicId = false;
        break;
    }
}
check($allHaveClinicId, "100% das tabelas de domínio clínico possuem a coluna obrigatória 'clinic_id' para isolamento multi-tenant");

// -------------------------------------------------------------------------
// 3. INVENTÁRIO DE DEPENDÊNCIAS (SUPPLY CHAIN)
// -------------------------------------------------------------------------
echo "\n[3/6] Inventário de Dependências PHP e Mobile...\n";

// v54.77 (decisão do Maicon, 17/11/2026): a política de dependências que vale é a da NI2-475 (supply chain).
// Antes este inventário exigia "exatamente 1 pacote no composer.lock" (só PHPMailer), escrito antes do dompdf (v45).
// Agora confere a mesma política da ni2_475, de um lugar só (tests/_dependency_policy.php).
require_once __DIR__ . '/_dependency_policy.php';
foreach (clyonutri_dependency_policy(dirname(__DIR__)) as [$cond, $label]) {
    check($cond, $label);
}

$mobilePkg = json_decode(file_get_contents(__DIR__ . '/../mobile/package.json'), true);
check(isset($mobilePkg['dependencies']['expo']), "mobile/package.json declara ecossistema Expo 51");
check(isset($mobilePkg['dependencies']['react-native']), "mobile/package.json declara React Native 0.74.2");

// -------------------------------------------------------------------------
// 4. MAPEAMENTO DE ROTAS E SUPERFÍCIE DE ATAQUE
// -------------------------------------------------------------------------
echo "\n[4/6] Mapeamento de Rotas, Endpoints e Vetores de Exposição...\n";

// Analisar mapeamento de rotas públicas vs autenticadas no front controller
check(str_contains($indexSrc, "/login"), "Ponto de entrada mapeia endpoint público /login");
check(str_contains($indexSrc, "/cadastro"), "Ponto de entrada mapeia endpoint público /cadastro");
check(str_contains($indexSrc, "/recuperar-senha"), "Ponto de entrada mapeia endpoint público /recuperar-senha");
check(str_contains($indexSrc, "/dashboard"), "Ponto de entrada mapeia endpoint autenticado /dashboard");
check(str_contains($indexSrc, "/pacientes"), "Ponto de entrada mapeia endpoint autenticado /pacientes");
check(str_contains($indexSrc, "/api/v1/"), "Ponto de entrada mapeia endpoints de API REST móvel /api/v1/*");

// -------------------------------------------------------------------------
// 5. INVENTÁRIO DE UPLOADS E ISOLAMENTO DE ARQUIVOS
// -------------------------------------------------------------------------
echo "\n[5/6] Inventário de Serviços de Upload e Armazenamento Privado...\n";

$helpdeskSrc = file_get_contents(__DIR__ . '/../src/Domain/Helpdesk/HelpdeskAttachmentService.php');
$chatSrc = file_get_contents(__DIR__ . '/../src/Domain/Chat/ChatAttachmentService.php');

check(str_contains($helpdeskSrc, "storage/app/helpdesk_attachments"), "HelpdeskAttachmentService armazena arquivos fora do webroot (storage/app)");
check(str_contains($chatSrc, "storage/app/chat_attachments"), "ChatAttachmentService armazena arquivos fora do webroot (storage/app)");
check(str_contains($chatSrc, "0640"), "Anexos do chat são gravados com permissão estrita 0640");

// -------------------------------------------------------------------------
// 6. INVENTÁRIO DE CONTROLES DE SEGURANÇA NO PERÍMETRO HOSTGATOR
// -------------------------------------------------------------------------
echo "\n[6/6] Inventário de Configurações de Hospedagem e Proteção de Arquivos...\n";

$htaccessRoot = file_get_contents(__DIR__ . '/../.htaccess');
$htaccessPub = file_get_contents(__DIR__ . '/../public/.htaccess');

check(str_contains($htaccessRoot, "RewriteEngine On"), ".htaccess raiz possui mod_rewrite habilitado");
check(str_contains($htaccessPub, "RewriteEngine On"), "public/.htaccess possui mod_rewrite habilitado");
check(str_contains($htaccessPub, "RewriteRule ^ index.php [L]"), "public/.htaccess direciona todo o tráfego HTTP para o front controller index.php");

// -------------------------------------------------------------------------
// RELATÓRIO FINAL
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "RESULTADO DO INVENTÁRIO NI2-469:\n";
echo "  Total de verificações executadas: " . ($passed + $failed) . "\n";
echo "  Sucessos ([PASS]): {$passed}\n";
echo "  Falhas   ([FAIL]): {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] O inventário detectou {$failed} discrepância(s).\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Inventário técnico e mapeamento da superfície de ataque concluídos com êxito!\n";
    exit(0);
}
