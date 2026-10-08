<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Router;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Application\Controllers\DashboardController;

echo "====================================================================\n";
echo "   CLYONUTRI II — TESTE DE SUBMISSÃO WEB E CSRF EM PLANOS\n";
echo "   Verificação de Criação de Plano e Formulários do Editor\n";
echo "====================================================================\n\n";

$db = Connection::getInstance();
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Camila Mendes']);
$_SESSION['user_id'] = 1;
$_SESSION['clinic_id'] = 1;
$_SESSION['clinic_name'] = 'Clínica ClyoNutri';
$_SESSION['user_name'] = 'Dra. Camila Mendes';

$passed = 0;
$failed = 0;

function assertWeb(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// CENÁRIO 1: POST /planos/novo SEM CSRF (Deve ser Bloqueado pelo Router)
// -----------------------------------------------------------------------------
echo "[1] Testando Bloqueio de POST /planos/novo sem CSRF token...\n";

// Executar subprocesso simulando requisição HTTP POST sem CSRF
$cmdNoCsrf = "php -r '
    session_start();
    require_once \"vendor/autoload.php\";
    \$_SESSION[\"user_id\"] = 1;
    \$_SESSION[\"clinic_id\"] = 1;
    \ClyoNutri\Core\Auth\TenantContext::set(1, [\"id\" => 1, \"role\" => \"nutritionist\"]);
    \$_POST[\"patient_id\"] = 1;
    \$_POST[\"title\"] = \"Plano Bloqueado\";
    \$router = new \ClyoNutri\Core\Http\Router();
    \$ctrl = new \ClyoNutri\Application\Controllers\DashboardController();
    \$router->post(\"/planos/novo\", [\$ctrl, \"createMealPlan\"]);
    \$router->dispatch(\"POST\", \"/planos/novo\");
' 2>&1";

$outputNoCsrf = (string)shell_exec($cmdNoCsrf);
$jsonNoCsrf = json_decode($outputNoCsrf, true);
assertWeb(
    is_array($jsonNoCsrf) && isset($jsonNoCsrf['error']) && str_contains($jsonNoCsrf['error'], 'CSRF token'),
    "Requisição POST sem token CSRF foi interceptada com erro de segurança (403)"
);

// -----------------------------------------------------------------------------
// CENÁRIO 2: POST /planos/novo COM _csrf_token VÁLIDO (Deve Criar Plano Real)
// -----------------------------------------------------------------------------
echo "\n[2] Testando POST /planos/novo COM _csrf_token legítimo...\n";

$planTitle = 'Plano Criado via Form Web ' . uniqid();
$cmdWithCsrf = "php -r '
    session_start();
    require_once \"vendor/autoload.php\";
    \$_SESSION[\"user_id\"] = 1;
    \$_SESSION[\"clinic_id\"] = 1;
    \ClyoNutri\Core\Auth\TenantContext::set(1, [\"id\" => 1, \"role\" => \"nutritionist\"]);
    \$token = \ClyoNutri\Core\Security\Csrf::token();
    \$_POST[\"_csrf_token\"] = \$token;
    \$_POST[\"patient_id\"] = 1;
    \$_POST[\"title\"] = \"{$planTitle}\";
    \$_POST[\"notes\"] = \"Criado via form web verificado\";
    \$router = new \ClyoNutri\Core\Http\Router();
    \$ctrl = new \ClyoNutri\Application\Controllers\DashboardController();
    \$router->post(\"/planos/novo\", [\$ctrl, \"createMealPlan\"]);
    \$router->dispatch(\"POST\", \"/planos/novo\");
' 2>&1";

$outputWithCsrf = (string)shell_exec($cmdWithCsrf);

// O Router não deve ter emitido erro de CSRF
assertWeb(
    !str_contains($outputWithCsrf, 'CSRF token inválido ou ausente'),
    "Requisição com token CSRF passou pela barreira de validação"
);

// Verificar persistência no banco de dados
$stmt = $db->prepare("SELECT * FROM meal_plans WHERE title = :title AND clinic_id = 1 ORDER BY id DESC LIMIT 1");
$stmt->execute(['title' => $planTitle]);
$createdPlan = $stmt->fetch();

assertWeb(!empty($createdPlan), "Plano alimentar persistido fisicamente no banco de dados (ID #{$createdPlan['id']})");
assertWeb((int)$createdPlan['patient_id'] === 1, "Associado com sucesso ao paciente #1");
assertWeb($createdPlan['status'] === 'draft', "Iniciado com status de edição 'draft'");

$planId = (int)$createdPlan['id'];

// -----------------------------------------------------------------------------
// CENÁRIO 3: Verificação de Tokens CSRF nos Formulários das Views HTML
// -----------------------------------------------------------------------------
echo "\n[3] Testando presença do input _csrf_token nas views do sistema...\n";

$dashCtrl = new DashboardController();

// 3.1 Perfil 360 do Paciente
ob_start();
$dashCtrl->profile360(['id' => 1]);
$profileHtml = (string)ob_get_clean();

assertWeb(
    str_contains($profileHtml, 'action="/planos/novo"') && str_contains($profileHtml, 'name="_csrf_token"'),
    "Modal de novo plano no Perfil 360 possui campo _csrf_token"
);

// 3.2 Listagem de Planos
ob_start();
$dashCtrl->mealPlans();
$listHtml = (string)ob_get_clean();

assertWeb(
    str_contains($listHtml, 'action="/planos/novo"') && str_contains($listHtml, 'name="_csrf_token"'),
    "Modal de novo plano na listagem possui campo _csrf_token"
);
assertWeb(
    str_contains($listHtml, '/duplicar') && str_contains($listHtml, 'name="_csrf_token"'),
    "Formulário de duplicar plano na listagem possui campo _csrf_token"
);

// 3.3 Editor do Plano Alimentar
ob_start();
$dashCtrl->mealPlanEditor(['id' => $planId]);
$editorHtml = (string)ob_get_clean();

assertWeb(
    str_contains($editorHtml, 'action="/planos/' . $planId . '/duplicar"') && str_contains($editorHtml, 'name="_csrf_token"'),
    "Formulário de duplicar plano no editor possui campo _csrf_token"
);
assertWeb(
    str_contains($editorHtml, 'action="/planos/' . $planId . '/refeicoes"') && str_contains($editorHtml, 'name="_csrf_token"'),
    "Modal de adicionar refeição possui campo _csrf_token"
);
assertWeb(
    str_contains($editorHtml, 'action="/planos/' . $planId . '/itens"') && str_contains($editorHtml, 'name="_csrf_token"'),
    "Modal de adicionar alimento possui campo _csrf_token"
);
assertWeb(
    str_contains($editorHtml, 'action="/planos/' . $planId . '/substituicoes"') && str_contains($editorHtml, 'name="_csrf_token"'),
    "Modal de adicionar substituição possui campo _csrf_token"
);
assertWeb(
    str_contains($editorHtml, 'action="/planos/' . $planId . '/publicar"') && str_contains($editorHtml, 'name="_csrf_token"'),
    "Modal de publicar plano possui campo _csrf_token"
);

// -----------------------------------------------------------------------------
// SUMÁRIO
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "RESULTADO DOS TESTES DE FORMULÁRIO E CSRF:\n";
echo "  TOTAL DE ASSERÇÕES: " . ($passed + $failed) . "\n";
echo "  PASSOU            : {$passed}\n";
echo "  FALHOU            : {$failed}\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
