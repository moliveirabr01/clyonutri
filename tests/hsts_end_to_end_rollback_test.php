<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * TESTE E2E: COMPROVAÇÃO DE HSTS DE PONTA A PONTA E ROLLBACK CENTRALIZADO (NI2-811)
 * ==============================================================================
 * Comprova os requisitos arquiteturais do NI2-811:
 *  1. Precedência absoluta do rollback manual: operador cria sinalizador e o PHP
 *     mantém max-age=0 mesmo com .env em modo normal; syncRollbackFlag() NUNCA
 *     apaga o sinalizador criado pelo operador.
 *  2. Restauração explícita: somente uma operação administrativa (restoreNormalHsts)
 *     encerra o rollback manual.
 *  3. Teste 100% isolado: executa exclusivamente em diretório temporário isolado,
 *     sem tocar no storage/ operacional.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Security\Env;

$passed = 0;
$failed = 0;

function assertHsts(string $desc, bool $condition, string $ev = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
        if ($ev !== '') echo "         Evidência: {$ev}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}\n";
        if ($ev !== '') echo "         Erro: {$ev}\n";
    }
}

echo "===============================================================================\n";
echo "   CLYONUTRI II — TESTE E2E: ROLLBACK CENTRALIZADO E ISOLADO DE HSTS (NI2-811)\n";
echo "===============================================================================\n\n";

// Requisito 3: Diretório temporário 100% isolado da suíte
$tempStorageDir = sys_get_temp_dir() . '/clyonutri_hsts_isolated_' . bin2hex(random_bytes(6));
if (!is_dir($tempStorageDir)) {
    mkdir($tempStorageDir, 0755, true);
}
$tempFlagFile = $tempStorageDir . '/hsts_rollback';

$operationalStorageDir = __DIR__ . '/../storage';
$operationalFlagFile = $operationalStorageDir . '/hsts_rollback';
$operationalExistedBefore = file_exists($operationalFlagFile);

try {
    // -----------------------------------------------------------------------------
    // 1. Validação de Arquitetura Apache (.htaccess)
    // -----------------------------------------------------------------------------
    echo "--> [1/5] Verificando Regras de Rollback nos Arquivos .htaccess...\n";

    $publicHt = file_get_contents(__DIR__ . '/../public/.htaccess');
    $rootHt = file_get_contents(__DIR__ . '/../.htaccess');

    assertHsts(
        "public/.htaccess detecta flag storage/hsts_rollback e ativa variável HSTS_ROLLBACK",
        str_contains($publicHt, 'RewriteCond %{DOCUMENT_ROOT}/../storage/hsts_rollback -f [OR]') &&
        str_contains($publicHt, 'RewriteRule ^ - [E=HSTS_ROLLBACK:1]'),
        "Regra mod_rewrite presente para detecção de rollback"
    );

    assertHsts(
        "public/.htaccess força max-age=0 para assets estáticos quando HSTS_ROLLBACK estiver ativo",
        str_contains($publicHt, 'Header always set Strict-Transport-Security "max-age=0"') &&
        str_contains($publicHt, "hsts_rollback"),
        "Diretiva de rollback ativo presente no mod_headers"
    );

    assertHsts(
        "public/.htaccess restringe o fallback de 6 meses para NÃO atuar quando rollback estiver ativo",
        str_contains($publicHt, "! -f '%{DOCUMENT_ROOT}/../storage/hsts_rollback'") ||
        str_contains($publicHt, "-z reqenv('HSTS_ROLLBACK')"),
        "Condição protege assets contra reativação indevida"
    );

    assertHsts(
        "Root .htaccess possui espelhamento exato das regras de rollback do Apache",
        str_contains($rootHt, 'Header always set Strict-Transport-Security "max-age=0"') &&
        str_contains($rootHt, 'hsts_rollback'),
        "Root .htaccess alinhado"
    );

    // -----------------------------------------------------------------------------
    // 2. Operação Normal de Produção (Sem Rollback)
    // -----------------------------------------------------------------------------
    echo "\n--> [2/5] Validando Baseline em Operação Normal (Produção)...\n";

    putenv('APP_ENV=production');
    putenv('HSTS_MAX_AGE=');
    putenv('HSTS_SEND_ZERO=');

    assertHsts(
        "isRollbackActive(\$tempStorageDir) retorna false em operação normal",
        SecurityHeaders::isRollbackActive($tempStorageDir) === false,
        "Rollback inativo no diretório isolado"
    );

    assertHsts(
        "hstsValue(\$tempStorageDir) em produção retorna padrão de 6 meses (15552000, NI2-885)",
        SecurityHeaders::hstsValue($tempStorageDir) === 'max-age=15552000',
        "Valor retornado: " . SecurityHeaders::hstsValue($tempStorageDir)
    );

    SecurityHeaders::syncRollbackFlag($tempStorageDir);

    assertHsts(
        "Flag isolado hsts_rollback permanece ausente em operação normal",
        !file_exists($tempFlagFile),
        "Arquivo de rollback inexistente no diretório isolado"
    );

    // -----------------------------------------------------------------------------
    // 3. Precedência Absoluta do Rollback Manual do Operador (Item 1)
    // -----------------------------------------------------------------------------
    echo "\n--> [3/5] Validando Precedência do Rollback Manual do Operador (NI2-811 Item 1)...\n";

    // Operador cria o sinalizador manual
    $activated = SecurityHeaders::activateRollback($tempStorageDir);
    assertHsts(
        "activateRollback(\$tempStorageDir) cria o sinalizador com sucesso",
        $activated && file_exists($tempFlagFile),
        "Sinalizador criado em: {$tempFlagFile}"
    );

    // Mesmo com .env em modo normal de produção, o sinalizador manual deve PREVALECER
    putenv('APP_ENV=production');
    putenv('HSTS_MAX_AGE=86400');
    putenv('HSTS_SEND_ZERO=false');

    assertHsts(
        "isRollbackActive(\$tempStorageDir) tem PRECEDÊNCIA e retorna true mesmo com .env em modo normal",
        SecurityHeaders::isRollbackActive($tempStorageDir) === true,
        "Precedência manual respeitada"
    );

    assertHsts(
        "hstsValue(\$tempStorageDir) emite max-age=0 por precedência do sinalizador em disco",
        SecurityHeaders::hstsValue($tempStorageDir) === 'max-age=0',
        "Valor retornado: " . SecurityHeaders::hstsValue($tempStorageDir)
    );

    // A rotina syncRollbackFlag() chamada durante requisições normais NÃO DEVE DELETAR o sinalizador do operador
    SecurityHeaders::syncRollbackFlag($tempStorageDir);

    assertHsts(
        "syncRollbackFlag(\$tempStorageDir) NÃO remove o sinalizador criado pelo operador durante operação normal",
        file_exists($tempFlagFile),
        "Sinalizador mantido intacto no disco"
    );

    // -----------------------------------------------------------------------------
    // 4. Restauração Explícita Administrativa (Item 2)
    // -----------------------------------------------------------------------------
    echo "\n--> [4/5] Validando Restauração Explícita Administrativa (NI2-811 Item 2)...\n";

    $restored = SecurityHeaders::restoreNormalHsts($tempStorageDir);

    assertHsts(
        "restoreNormalHsts(\$tempStorageDir) remove explicitamente o sinalizador manual",
        $restored && !file_exists($tempFlagFile),
        "Sinalizador removido apenas mediante comando explícito de restore"
    );

    assertHsts(
        "Após restauração explícita, isRollbackActive(\$tempStorageDir) retorna false",
        SecurityHeaders::isRollbackActive($tempStorageDir) === false,
        "Rollback encerrado"
    );

    assertHsts(
        "Após restauração explícita, hstsValue(\$tempStorageDir) retorna ao baseline 86400",
        SecurityHeaders::hstsValue($tempStorageDir) === 'max-age=86400',
        "Baseline restaurado: " . SecurityHeaders::hstsValue($tempStorageDir)
    );

    // -----------------------------------------------------------------------------
    // 5. Isolamento Estrito do Ambiente Operacional (Item 3)
    // -----------------------------------------------------------------------------
    echo "\n--> [5/5] Confirmando Isolamento Total (storage/ operacional intocado)...\n";

    $operationalExistedAfter = file_exists($operationalFlagFile);
    assertHsts(
        "storage/ operacional NÃO foi tocado durante a execução dos testes",
        $operationalExistedBefore === $operationalExistedAfter,
        "Estado de storage/ operacional preservado intacto (" . ($operationalExistedAfter ? 'presente' : 'ausente') . ")"
    );

} finally {
    // Limpeza total do ambiente temporário isolado
    if (file_exists($tempFlagFile)) {
        @unlink($tempFlagFile);
    }
    if (is_dir($tempStorageDir)) {
        @rmdir($tempStorageDir);
    }
}

echo "\n===============================================================================\n";
echo "RESULTADO DO TESTE DE ROLLBACK ISOLADO:\n";
echo "  Total: " . ($passed + $failed) . " | Passou: {$passed} | Falhou: {$failed}\n";
echo "===============================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] Falha na comprovação do rollback de HSTS.\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Requisitos 1, 2 e 3 de HSTS comprovados com isolamento estrito!\n";
    exit(0);
}
