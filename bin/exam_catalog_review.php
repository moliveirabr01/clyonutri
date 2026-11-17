<?php
/**
 * NI2-891: revisão do catálogo geral de exames pelo nutricionista responsável técnico.
 *   php bin/exam_catalog_review.php --count
 *   php bin/exam_catalog_review.php --export > exames.csv
 *   php bin/exam_catalog_review.php --import exames.csv --source "Tabela TUSS 22 (ANS), versão 2026-10" [--dry-run]
 *   (v54.77: o revisor gravado é o RT configurado em Admin > Medidas caseiras — nome e CRN; --reviewer é opcional e precisa ser ele)
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
        // v54.77: o revisor é o RT configurado na plataforma (nome — CRN). --reviewer é opcional e, se vier, precisa ser ele.
        $rt = \ClyoNutri\Domain\Catalog\CatalogTechnicalReviewer::load(\ClyoNutri\Core\Database\Connection::getInstance());
        $rt->assertReady();
        $given = trim((string)($o['reviewer'] ?? ''));
        if ($given !== '' && mb_stripos($given, $rt->name) === false) {
            throw new InvalidArgumentException('O RT configurado é ' . $rt->label() . '. Para revisar com outra pessoa, troque o RT em Admin > Medidas caseiras.');
        }
        echo 'Revisor (RT): ' . $rt->label() . "\n";
        $r = $svc->importCsv($csv, $rt->label(), (string)($o['source'] ?? ''), isset($o['dry-run']));
        echo ($r['dry_run'] ? '[simulação] ' : '') . "Revisados: {$r['applied']} · sem mudança: {$r['unchanged']} · rejeitados: " . count($r['rejected']) . "\n";
        foreach ($r['rejected'] as $x) echo "  linha {$x['line']} ({$x['code']}): {$x['reason']}\n";
        exit($r['rejected'] ? 2 : 0);
    }
    $s = $svc->stats();
    echo "Catálogo geral: {$s['total']} exames · revisados: {$s['reviewed']} · aguardando revisão: {$s['pending']}\n";
    $rt = \ClyoNutri\Domain\Catalog\CatalogTechnicalReviewer::load(\ClyoNutri\Core\Database\Connection::getInstance());
    echo 'Responsável técnico (RT): ' . ($rt->hasName() ? $rt->label() : '(não configurado)') . ($rt->isReady() ? '' : ' — falta ' . implode(' e ', $rt->missing())) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n"); exit(1);
}
