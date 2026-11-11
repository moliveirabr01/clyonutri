<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — FERRAMENTA OPERACIONAL DE GESTÃO E ROLLBACK DE HSTS (NI2-811)
 * ==============================================================================
 * Centraliza e valida o status de HSTS de ponta a ponta entre PHP e Apache (.htaccess).
 * Uso:
 *   php bin/hsts.php --status
 *   php bin/hsts.php --rollback
 *   php bin/hsts.php --restore
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');

$action = $argv[1] ?? '--status';
$storageDir = dirname(__DIR__) . '/storage';
$flagFile = $storageDir . '/hsts_rollback';

echo "===============================================================================\n";
echo "   CLYONUTRI II — GESTÃO UNIFICADA DE HSTS E CONTROLE DE ROLLBACK\n";
echo "===============================================================================\n\n";

switch ($action) {
    case '--rollback':
        echo "--> [ATIVANDO ROLLBACK MANUAL DE HSTS DE PONTA A PONTA (NI2-811)]...\n";
        $ok = SecurityHeaders::activateRollback($storageDir);
        if ($ok) {
            echo "  ✓ Flag de rollback manual criado em: {$flagFile}\n";
            echo "  ✓ Precedência absoluta ativa: sobrepõe qualquer configuração PHP/env.\n";
            echo "  ✓ Apache (.htaccess) agora força: Strict-Transport-Security: max-age=0 para TODOS os assets\n";
            echo "  ✓ Navegadores desarmarão o HSTS imediatamente ao acessar qualquer URL ou asset.\n";
        } else {
            echo "  ✗ ERRO ao criar flag de rollback manual em: {$flagFile}\n";
            exit(1);
        }
        break;

    case '--restore':
        echo "--> [RESTAURAÇÃO EXPLÍCITA DE HSTS — OPERAÇÃO ADMINISTRATIVA (NI2-811)]...\n";
        $ok = SecurityHeaders::restoreNormalHsts($storageDir);
        if ($ok) {
            echo "  ✓ Flag de rollback removido com sucesso de: {$flagFile}\n";
            echo "  ✓ Apache e PHP retornam ao baseline seguro normal (86400s / 1 dia).\n";
        } else {
            echo "  ✗ ERRO ao remover flag de rollback em: {$flagFile}\n";
            exit(1);
        }
        break;

    case '--status':
    default:
        $manualRollbackActive = file_exists($flagFile);
        $activeRollback = SecurityHeaders::isRollbackActive($storageDir);
        $hstsHeader = SecurityHeaders::hstsValue($storageDir);
        $isProd = strtolower((string)(Env::get('APP_ENV', '') ?? '')) === 'production';
        $envAge = Env::get('HSTS_MAX_AGE');

        echo "  • Ambiente (APP_ENV)               : " . ($isProd ? 'production' : 'development/local') . "\n";
        echo "  • Configuração HSTS_MAX_AGE (.env) : " . ($envAge !== null ? $envAge : 'não definido (usa baseline)') . "\n";
        echo "  • Flag HSTS_SEND_ZERO (.env)       : " . (SecurityHeaders::bool('HSTS_SEND_ZERO', false) ? 'true' : 'false') . "\n";
        echo "  • Sinalizador Manual do Operador   : " . ($manualRollbackActive ? "ATIVO ({$flagFile}) [PRECEDÊNCIA ABSOLUTA]" : "INATIVO") . "\n";
        echo "  • Status de Rollback Ativo         : " . ($activeRollback ? "SIM (max-age=0 forçado de ponta a ponta)" : "NÃO (Modo Gradual Normal)") . "\n";
        echo "  • Cabeçalho emitido pelo PHP       : " . ($hstsHeader ?? 'Nenhum') . "\n";
        echo "  • Cabeçalho estático no Apache     : " . ($activeRollback ? 'max-age=0' : 'max-age=86400') . "\n\n";
        break;
}

echo "===============================================================================\n";
