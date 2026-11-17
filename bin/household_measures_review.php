<?php

declare(strict_types=1);

/**
 * NI2-886 — revisão das medidas caseiras estimadas pela linha de comando.
 *   php bin/household_measures_review.php --count                      # contagem atual (total e por grupo)
 *   php bin/household_measures_review.php --export=medidas.csv [--status=pending|approved|removed|all]
 *   php bin/household_measures_review.php --import=revisado.csv --reviewer-id=ID [--dry-run]
 *   php bin/household_measures_review.php --sql=revisoes.sql           # SQL idempotente (MySQL 5.7) para produção
 * O revisor é um usuário do sistema (id); o nome dele fica gravado junto da medida.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService;

if (is_file(__DIR__ . '/../.env')) {
    Env::load(__DIR__ . '/../.env');
}
date_default_timezone_set((string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo'));
$o = getopt('', ['count', 'export:', 'status:', 'import:', 'reviewer-id:', 'dry-run', 'sql:']);
$db = Connection::getInstance();
if (!HouseholdMeasureReviewService::ready($db)) {
    fwrite(STDERR, "A migração 99 (NI2-886) não foi aplicada neste banco.\n");
    exit(2);
}
$svc = new HouseholdMeasureReviewService($db);

if (isset($o['count'])) {
    $s = $svc->stats();
    echo "Medidas estimadas a revisar: {$s['pending']} · aprovadas: {$s['approved']} · removidas: {$s['removed']}\n";
    foreach ($s['by_group'] as $g) {
        printf("  %-40s %5d a revisar (%d já usadas em planos)\n", $g['grupo'], $g['pendentes'], $g['usadas']);
    }
    exit(0);
}
if (isset($o['export'])) {
    $csv = $svc->exportCsv(['status' => (string)($o['status'] ?? 'pending'), 'order' => 'uso']);
    file_put_contents((string)$o['export'], $csv);
    echo 'CSV gravado: ' . $o['export'] . ' (' . (substr_count($csv, "\n") - 1) . " linhas)\n";
    exit(0);
}
if (isset($o['import'])) {
    $rid = (int)($o['reviewer-id'] ?? 0);
    $st = $db->prepare("SELECT name FROM users WHERE id = ? AND status = 'active'");
    $st->execute([$rid]);
    $name = $st->fetchColumn();
    if ($rid <= 0 || $name === false) {
        fwrite(STDERR, "Informe --reviewer-id de um usuário ativo (quem registra a revisão do RT).\n");
        exit(2);
    }
    // v54.77: o revisor gravado é o RT configurado (nome — CRN); --reviewer-id é quem registra (fica na auditoria).
    $rt = \ClyoNutri\Domain\Catalog\CatalogTechnicalReviewer::load($db);
    if (!$rt->isReady()) {
        fwrite(STDERR, 'Revisão bloqueada: falta ' . implode(' e ', $rt->missing()) . ". Preencha em Admin > Medidas caseiras > Responsável técnico (RT) dos catálogos.\n");
        exit(2);
    }
    $name = $rt->label();
    echo "Revisor (RT): {$name} · registrado por: usuário #{$rid}\n";
    $content = @file_get_contents((string)$o['import']);
    if ($content === false) {
        fwrite(STDERR, "Arquivo não encontrado: {$o['import']}\n");
        exit(2);
    }
    $r = $svc->importCsv($content, $rid, (string)$name, isset($o['dry-run']));
    echo ($r['dry_run'] ? '[SIMULAÇÃO] ' : '') . "Linhas válidas/aplicadas: {$r['applied']} · sem ação: {$r['ignored']} · rejeitadas: " . count($r['rejected']) . "\n";
    foreach ($r['rejected'] as $x) {
        echo "  linha {$x['linha']}: {$x['motivo']}\n";
    }
    exit($r['rejected'] ? 1 : 0);
}
if (isset($o['sql'])) {
    file_put_contents((string)$o['sql'], $svc->productionSql());
    echo "SQL gravado: {$o['sql']}\n";
    exit(0);
}
fwrite(STDERR, "Uso: --count | --export=arq.csv | --import=arq.csv --reviewer-id=ID_DE_QUEM_REGISTRA [--dry-run] | --sql=arq.sql\n");
exit(2);
