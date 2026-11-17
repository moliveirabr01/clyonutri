<?php
/**
 * NI2-891: revisão do catálogo geral de exames pelo nutricionista responsável técnico.
 *   php bin/exam_catalog_review.php --count
 *   php bin/exam_catalog_review.php --export > exames.csv
 *   php bin/exam_catalog_review.php --import exames.csv --reviewer "Ana Souza — CRN-3 12345" --source "Tabela TUSS 22 (ANS), versão 2026-10" [--dry-run]
 *   php bin/exam_catalog_review.php --sql > ni2_891_carga_exames.sql
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
\ClyoNutri\Core\Security\Env::load(dirname(__DIR__) . '/.env');
$o = getopt('', ['count', 'export', 'import:', 'reviewer:', 'source:', 'dry-run', 'sql']);
$svc = new \ClyoNutri\Domain\LaboratoryExam\ExamCatalogReviewService(\ClyoNutri\Core\Database\Connection::getInstance());
try {
    if (isset($o['export'])) { echo $svc->exportCsv(); exit(0); }
    if (isset($o['sql'])) { echo $svc->productionSql(); exit(0); }
    if (isset($o['import'])) {
        $csv = @file_get_contents((string)$o['import']);
        if ($csv === false) throw new InvalidArgumentException('Arquivo não encontrado: ' . $o['import']);
        $r = $svc->importCsv($csv, (string)($o['reviewer'] ?? ''), (string)($o['source'] ?? ''), isset($o['dry-run']));
        echo ($r['dry_run'] ? '[simulação] ' : '') . "Revisados: {$r['applied']} · sem mudança: {$r['unchanged']} · rejeitados: " . count($r['rejected']) . "\n";
        foreach ($r['rejected'] as $x) echo "  linha {$x['line']} ({$x['code']}): {$x['reason']}\n";
        exit($r['rejected'] ? 2 : 0);
    }
    $s = $svc->stats();
    echo "Catálogo geral: {$s['total']} exames · revisados: {$s['reviewed']} · aguardando revisão: {$s['pending']}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n"); exit(1);
}
