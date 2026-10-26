<?php

declare(strict_types=1);

/**
 * Teste Verificável de Homologação: NI2-478 (Onda 1 - SEC-03)
 * Valida conformidade estrita da tela de Lista de Compras (views/meal_plans/shopping_list.php)
 * com Content-Security-Policy (CSP nonce dinâmico e ausência de handlers inline).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Http\SecurityHeaders;

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
echo ">>> SUÍTE VERIFICÁVEL DE HOMOLOGAÇÃO: NI2-478 (ONDA 1 - SEC-03) <<<\n";
echo "========================================================================\n\n";

// 1. Verificação Estática de Código
echo "[1/4] Análise estática do arquivo views/meal_plans/shopping_list.php...\n";
$filePath = realpath(__DIR__ . '/../views/meal_plans/shopping_list.php');
check($filePath !== false && file_exists($filePath), 'Arquivo shopping_list.php localizado');

$source = file_get_contents($filePath);

// Não deve haver nenhum <script> sem nonce ou src
$noNonceMatches = [];
preg_match_all('/<script(?![^>]*(\bsrc=|\bnonce=|type="application\/json"))[^>]*>/i', $source, $noNonceMatches);
check(count($noNonceMatches[0]) === 0, 'Nenhuma tag <script> inline sem nonce (encontradas: ' . count($noNonceMatches[0]) . ')');

// Não deve haver atributos on* inline
$handlerMatches = [];
preg_match_all('/\son[a-z]+\s*=\s*["\']/i', $source, $handlerMatches);
check(count($handlerMatches[0]) === 0, 'Nenhum atributo on*= inline na view (encontrados: ' . count($handlerMatches[0]) . ')');

// 2. Renderização Dinâmica com Nonce Ativo
echo "\n[2/4] Renderização da view com SecurityHeaders::nonce()...\n";

$expectedNonce = SecurityHeaders::nonce();
check(!empty($expectedNonce) && strlen($expectedNonce) >= 16, "Nonce gerado pelo SecurityHeaders: {$expectedNonce}");

// Mock mínimo de dados para renderização da view
$list = [
    'plan_id' => 42,
    'patient_name' => 'Ana Paula Teste',
    'plan_title' => 'Plano Hipertrofia 2026',
    'status' => 'published',
    'days' => 7,
    'version' => '1.0',
    'total_items_count' => 2,
    'professional_name' => 'Dra. Nutricionista',
    'aisles' => [
        'Hortifrúti' => [
            ['name' => 'Banana prata', 'key' => 'banana_prata', 'amount' => 14, 'unit' => 'un', 'daily' => 2, 'recipes_source' => [], 'is_substituted' => false, 'has_allergen_conflict' => false, 'meals_source' => ['Café da Manhã']],
        ],
        'Açougue & Proteínas' => [
            ['name' => 'Peito de frango', 'key' => 'peito_frango', 'amount' => 1.4, 'unit' => 'kg', 'daily' => 0.2, 'recipes_source' => ['Frango Grelhado'], 'is_substituted' => false, 'has_allergen_conflict' => false, 'meals_source' => []],
        ],
    ],
    'allergens_and_restrictions' => [
        'has_conflicts' => false,
        'conflicts' => [],
        'restrictions_recorded' => '',
    ],
    'linked_recipes' => [
        ['title' => 'Frango Grelhado', 'prescribed_servings' => 1.0, 'meal_name' => 'Almoço'],
    ],
];

ob_start();
try {
    include $filePath;
    $renderedHtml = ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    $renderedHtml = '';
    echo "  [ERROR] Exceção durante renderização: " . $e->getMessage() . "\n";
}

check(!empty($renderedHtml), 'View renderizada com sucesso via buffer de saída');

// 3. Verificação de Atributos e Conformidade na Saída HTML
echo "\n[3/4] Validação de conformidade CSP na saída HTML renderizada...\n";

// Verificar presença de nonce no script renderizado
$hasRenderedNonce = str_contains($renderedHtml, 'nonce="' . htmlspecialchars($expectedNonce, ENT_QUOTES, 'UTF-8') . '"');
check($hasRenderedNonce, "Script inline renderizado com atributo nonce=\"{$expectedNonce}\"");

// Verificar se não sobrou nenhum script sem nonce
preg_match_all('/<script(?![^>]*(\bsrc=|\bnonce=|type="application\/json"))[^>]*>/i', $renderedHtml, $renderedNoNonce);
check(count($renderedNoNonce[0]) === 0, 'Nenhuma tag de script na saída sem nonce');

// Verificar se botões possuem IDs em vez de onclick
check(str_contains($renderedHtml, 'id="btn-print"') && !str_contains($renderedHtml, 'onclick="window.print()"'), 'Botão imprimir usa id="btn-print" sem onclick');
check(str_contains($renderedHtml, 'id="btn-copy"') && !str_contains($renderedHtml, 'onclick="copyShoppingList()"'), 'Botão copiar usa id="btn-copy" sem onclick');
check(str_contains($renderedHtml, 'id="btn-add-manual"') && !str_contains($renderedHtml, 'onclick="addManualItem()"'), 'Botão adicionar manual usa id="btn-add-manual" sem onclick');

// 4. Verificação da Política CSP Emitida
echo "\n[4/4] Verificação da política SecurityHeaders::pagePolicy()...\n";

$policy = SecurityHeaders::pagePolicy();
check(str_contains($policy, "'nonce-{$expectedNonce}'"), "pagePolicy() inclui 'nonce-{$expectedNonce}'");
check(str_contains($policy, "script-src-attr 'none'"), "pagePolicy() restringe atributos inline com script-src-attr 'none'");

echo "\n------------------------------------------------------------------------\n";
echo "RESULTADO: {$passed} testes passaram, {$failed} falharam.\n";
echo "========================================================================\n\n";

exit($failed === 0 ? 0 : 1);
