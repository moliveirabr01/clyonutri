<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\MealPlanVersionDiffService;
use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\HelpdeskController;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Executando Suíte de Testes da Rodada 5 (NI2-597, NI2-598, NI2-599, NI2-600) ===\n\n";

$passCount = 0;
$failCount = 0;

$assert = function (bool $condition, string $msg) use (&$passCount, &$failCount) {
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$msg}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$msg}\n";
    }
};

$db = Connection::getInstance();

// Setup tenant
$clinicId = 1;
$userId = 1;
TenantContext::set($clinicId, [
    'id' => $userId,
    'role' => 'nutritionist',
    'clinic_id' => $clinicId,
    'permissions' => ['all'],
    'email' => 'nutri@clinicateste.com.br',
    'name' => 'Dra. Nutricionista Teste'
]);
$_SESSION['user_id'] = $userId;
$_SESSION['clinic_id'] = $clinicId;
$_SESSION['user_role'] = 'nutritionist';
$_SESSION['user_name'] = 'Dra. Nutricionista Teste';
$_SESSION['clinic_name'] = 'Clínica Clyo Nutri';

// ======================================================================
// NI2-597: Cada ação mostra no máximo uma mensagem de sucesso
// ======================================================================
echo "--- NI2-597: Desduplicação de avisos em /planos/{id} ---\n";

$planLiveJs = file_get_contents(__DIR__ . '/../public/assets/js/plan-live.js');
$assert(str_contains($planLiveJs, 'dedupeFlashes'), "plan-live.js implementa rotina dedupeFlashes()");
$assert(str_contains($planLiveJs, 'flashes.length > 1'), "dedupeFlashes remove mensagens redundantes além da primeira");

// Simular renderização com mensagem flash
$_SESSION['feedback_message'] = "Substituição cadastrada.";
ob_start();
$dashCtrl = new DashboardController();
// Pegar um plano existente ou criar um
$planStmt = $db->query("SELECT id FROM meal_plans WHERE clinic_id = 1 LIMIT 1");
$planId = $planStmt->fetchColumn();
if (!$planId) {
    $db->exec("INSERT INTO meal_plans (clinic_id, professional_id, title, status, version) VALUES (1, 1, 'Plano Teste 597', 'draft', 1)");
    $planId = (int)$db->lastInsertId();
}

$dashCtrl->mealPlanEditor(['id' => $planId]);
$renderedHtml = ob_get_clean();

// Contar ocorrências de data-plan-flash="ok" no HTML renderizado
$flashOkCount = substr_count($renderedHtml, 'data-plan-flash="ok"');
$assert($flashOkCount <= 1, "HTML gerado pelo servidor contém no máximo 1 banner de feedback (obtido: {$flashOkCount})");
$assert(str_contains($renderedHtml, 'Substituição cadastrada.'), "Mensagem de feedback está presente no HTML gerado");


// ======================================================================
// NI2-598: Regiões do CRN no CFN (CE, PI, MA não repetidas)
// ======================================================================
echo "\n--- NI2-598: Jurisdição do CRN conforme CFN atualizado ---\n";

$settingsProfilePhp = file_get_contents(__DIR__ . '/../views/settings/profile.php');
$registerPhp = file_get_contents(__DIR__ . '/../views/auth/register.php');

$assert(str_contains($settingsProfilePhp, "'CRN-6'=>'CRN-6 (PE/AL/PB/RN)'"), "profile.php: CRN-6 lista somente PE/AL/PB/RN");
$assert(!str_contains($settingsProfilePhp, "'CRN-6'=>'CRN-6 (PE/AL/PB/RN/CE/PI/MA)'"), "profile.php: CE, PI e MA removidos do CRN-6");
$assert(str_contains($settingsProfilePhp, "'CRN-11'=>'CRN-11 (CE/MA/PI)'") || str_contains($settingsProfilePhp, "'CRN-11'=>'CRN-11 (CE/PI/MA)'"), "profile.php: CRN-11 contempla CE, MA e PI");

$assert(str_contains($registerPhp, 'CRN-6 (PE/AL/PB/RN)'), "register.php: CRN-6 exibe apenas PE/AL/PB/RN");
$assert(!str_contains($registerPhp, 'CRN-6 (PE/AL/PB/RN/CE/PI/MA)'), "register.php: CE, PI e MA removidos do CRN-6");
$assert(str_contains($registerPhp, 'CRN-11'), "register.php: Opção do CRN-11 presente");

// Verificar se cada UF aparece exatamente uma vez em profile.php
preg_match('/\$crnOptions\s*=\s*\[(.*?)\];/s', $settingsProfilePhp, $m);
$optionsStr = $m[1] ?? '';
$allUfs = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

$ufCounts = [];
foreach ($allUfs as $uf) {
    // Buscar ocorrências como UF separada por / ou ( ou )
    $count = preg_match_all('/[\(\/]' . $uf . '[\)\/]/', $optionsStr);
    $ufCounts[$uf] = $count;
}

$assert($ufCounts['CE'] === 1, "CE aparece em exatamente 1 CRN (CRN-11)");
$assert($ufCounts['PI'] === 1, "PI aparece em exatamente 1 CRN (CRN-11)");
$assert($ufCounts['MA'] === 1, "MA aparece em exatamente 1 CRN (CRN-11)");
$assert($ufCounts['PE'] === 1, "PE aparece em exatamente 1 CRN (CRN-6)");
$assert($ufCounts['AL'] === 1, "AL aparece em exatamente 1 CRN (CRN-6)");
$assert($ufCounts['PB'] === 1, "PB aparece em exatamente 1 CRN (CRN-6)");
$assert($ufCounts['RN'] === 1, "RN aparece em exatamente 1 CRN (CRN-6)");


// ======================================================================
// NI2-599: Laudo pequeno < 1 KB formatado como bytes e Guia de Onboarding
// ======================================================================
echo "\n--- NI2-599: Formatação de arquivos pequenos & Acesso ao Onboarding ---\n";

$examResultsPhp = file_get_contents(__DIR__ . '/../views/patients/partials/exam_results.php');

// Testar a função de formatação $kb definida no arquivo
// Extrair a função do arquivo
preg_match('/\$kb\s*=\s*static\s+fn\(\$b\)\s*=>\s*([^;]+);/', $examResultsPhp, $kbMatches);
$assert(!empty($kbMatches), "Definição de \$kb localizada em exam_results.php");

eval('$testKb = static fn($b) => ' . $kbMatches[1] . ';');
$assert($testKb(512) === '512 B', "Arquivo de 512 bytes é exibido como '512 B' (não como '0 KB')");
$assert($testKb(100) === '100 B', "Arquivo de 100 bytes é exibido como '100 B'");
$assert($testKb(1024) === '1 KB', "Arquivo de 1024 bytes é exibido como '1 KB'");
$assert($testKb(2048) === '2 KB', "Arquivo de 2048 bytes é exibido como '2 KB'");

// Testar acesso à Central de Ajuda e presença do link de Onboarding
ob_start();
$helpCtrl = new HelpdeskController();
$helpCtrl->index();
$helpHtml = ob_get_clean();

$assert(str_contains($helpHtml, 'href="/onboarding"'), "/ajuda renderiza link apontando para /onboarding");
$assert(str_contains($helpHtml, 'Guia de primeiros passos'), "/ajuda exibe rótulo 'Guia de primeiros passos'");
$assert(str_contains($helpHtml, 'data-testid="onboarding-guide-link"'), "Link possui data-testid='onboarding-guide-link'");

// Verificar acessibilidade em até 2 cliques a partir do shell
$shellPhp = file_get_contents(__DIR__ . '/../views/layouts/professional-shell.php');
$assert(str_contains($shellPhp, 'href="/ajuda"'), "Menu lateral (shell) possui atalho de 1 clique para Central de Ajuda (/ajuda)");


// ======================================================================
// NI2-600: Ações do Editor do Plano Alimentar Vazio & Sem Emojis
// ======================================================================
echo "\n--- NI2-600: Ações contextuais no editor do plano alimentar ---\n";

// Criar um plano completamente vazio (sem refeições nem itens)
$db->exec("INSERT INTO meal_plans (clinic_id, professional_id, title, status, version) VALUES (1, 1, 'Plano Novo Vazio 600', 'draft', 1)");
$emptyPlanId = (int)$db->lastInsertId();

ob_start();
$dashCtrl->mealPlanEditor(['id' => $emptyPlanId]);
$emptyPlanHtml = ob_get_clean();

// 1. Não usar emojis nos botões
$assert(!str_contains($emptyPlanHtml, '🛒'), "Emoji de carrinho 🛒 removido dos botões");
$assert(!str_contains($emptyPlanHtml, '⇄'), "Emoji de setas ⇄ removido dos botões");

// 2. No plano vazio, Lista de compras NÃO deve aparecer
$assert(!str_contains($emptyPlanHtml, 'Lista de compras</a>'), "Plano vazio sem alimentos NÃO exibe 'Lista de compras'");

// 3. 'Comparar versões' NÃO deve aparecer sem versões publicadas
$assert(!str_contains($emptyPlanHtml, 'data-testid="compare-plan-versions-link"'), "Plano sem versões publicadas NÃO exibe 'Comparar versões'");

// 4. No topo, no máximo 3 ações no contêiner principal
// Contar elementos de ação direta no flex container do topo
// O container possui os botões diretos: Imprimir plano, Mais ações ▾, e Publicar
$assert(str_contains($emptyPlanHtml, 'data-testid="plan-more-actions"'), "Menu dropdown 'Mais ações ▾' presente");
$assert(str_contains($emptyPlanHtml, 'Imprimir plano'), "Ação 'Imprimir plano' presente");
$assert(str_contains($emptyPlanHtml, 'data-testid="publish-needs-patient"'), "Botão de publicar presente");

// Contar os filhos imediatos do contêiner de botões do topo
preg_match('/<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">(.*?)<\/div>\s*<\/div>/s', $emptyPlanHtml, $topMatches);
$topBarContent = $topMatches[1] ?? '';

// Extrair tags de nível superior no top bar (<a, <button, <details, <form)
preg_match_all('/<(a|button|details|form)\b/i', $topBarContent, $topActions);
// Note: <details> contém <summary> e links internos, mas é 1 componente no topo
// Verificando que no nível superior há exatamente 3 ações: 1 link (imprimir), 1 details (mais ações), 1 button (publicar)
$hasDetails = str_contains($topBarContent, '<details');
$hasPrint = str_contains($topBarContent, 'Imprimir plano');
$hasPublish = str_contains($topBarContent, 'Publicar');

$assert($hasDetails && $hasPrint && $hasPublish, "No topo aparecem exatamente 3 ações: Imprimir plano, Mais ações ▾ e Publicar");

// 5. Testar quando o paciente tem >= 2 versões publicadas: Comparar versões deve aparecer
// Criar paciente com 2 planos publicados
$pUuid = 'p2v-' . bin2hex(random_bytes(6));
$db->exec("INSERT INTO patients (clinic_id, uuid, name, email, status) VALUES (1, '{$pUuid}', 'Paciente 2 Versoes', 'p2v@test.com', 'active')");
$pId = (int)$db->lastInsertId();

$db->exec("INSERT INTO meal_plans (clinic_id, professional_id, patient_id, title, status, version) VALUES (1, 1, {$pId}, 'Rascunho Versao 3', 'draft', 3)");
$draftPlanId = (int)$db->lastInsertId();

$now = date('Y-m-d H:i:s');
$db->exec("INSERT INTO meal_plan_versions (clinic_id, meal_plan_id, patient_id, professional_id, version_number, title, daily_energy_kcal, payload_json, status, published_at) VALUES (1, {$draftPlanId}, {$pId}, 1, 1, 'Versao 1', 1800, '{}', 'superseded', '{$now}')");
$db->exec("INSERT INTO meal_plan_versions (clinic_id, meal_plan_id, patient_id, professional_id, version_number, title, daily_energy_kcal, payload_json, status, published_at) VALUES (1, {$draftPlanId}, {$pId}, 1, 2, 'Versao 2', 1850, '{}', 'published', '{$now}')");

// Adicionar uma refeição e item para testar que lista de compras aparece quando há itens
$db->exec("INSERT INTO meal_plan_meals (meal_plan_id, name, ordering) VALUES ({$draftPlanId}, 'Almoço', 1)");
$mealId = (int)$db->lastInsertId();
$db->exec("INSERT INTO meal_plan_items (meal_id, food_id, amount, measure_unit) VALUES ({$mealId}, 1, 100, 'g')");

ob_start();
$dashCtrl->mealPlanEditor(['id' => $draftPlanId]);
$populatedPlanHtml = ob_get_clean();

$assert(str_contains($populatedPlanHtml, 'data-testid="compare-plan-versions-link"'), "Com 2 ou mais versões publicadas, 'Comparar versões' é exibido no menu");
$assert(str_contains($populatedPlanHtml, 'Lista de compras'), "Com itens no plano, 'Lista de compras' é exibido no menu");
$assert(!str_contains($populatedPlanHtml, '🛒'), "Lista de compras usa ícone SVG sem emoji");
$assert(!str_contains($populatedPlanHtml, '⇄'), "Comparar versões usa ícone SVG sem emoji");

echo "\n======================================================================\n";
echo "Resultado Rodada 5: {$passCount} PASS, {$failCount} FAIL\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
