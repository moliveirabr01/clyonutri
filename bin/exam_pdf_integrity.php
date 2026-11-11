<?php

declare(strict_types=1);

/**
 * v47 (NI2-542): confere os PDFs das solicitações de exames guardados no servidor e, desde a v48 (NI2-539), os laudos
 * anexados. SOMENTE LEITURA.
 * Use depois de restaurar backup, mudar de servidor ou mexer em storage/app.
 *
 *   php bin/exam_pdf_integrity.php            todas as clínicas
 *   php bin/exam_pdf_integrity.php --clinic=3 só a clínica 3
 *
 * Saída 0 = tudo confere; 1 = arquivo faltando ou com SHA-256 diferente do gravado na emissão.
 * Sobras (arquivos sem solicitação) só são listadas: nada é apagado.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\LaboratoryExam\ExamPdfIntegrityChecker;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestPdfService;

Env::load(__DIR__ . '/../.env');
$clinic = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--clinic=(\d+)$/', $arg, $m)) {
        $clinic = (int)$m[1];
    } else {
        fwrite(STDERR, "Uso: php bin/exam_pdf_integrity.php [--clinic=ID]\n");
        exit(2);
    }
}

$r = (new ExamPdfIntegrityChecker(Connection::getInstance(), new ExamRequestPdfService()))->run($clinic);
echo "== Conferência dos PDFs de solicitações de exames" . ($clinic !== null ? " (clínica {$clinic})" : '') . "\n";
echo "Conferidos: {$r['checked']} · OK: {$r['ok']} · Faltando: " . count($r['missing']) . ' · Hash diferente: ' . count($r['mismatch']) . ' · Sobras: ' . count($r['orphans']) . "\n";
foreach ($r['missing'] as $x) {
    echo "FALTANDO   clínica {$x['clinic_id']} · solicitação {$x['id']} ({$x['request_number']}, {$x['status']})\n";
}
foreach ($r['mismatch'] as $x) {
    echo "DIFERENTE  clínica {$x['clinic_id']} · solicitação {$x['id']} ({$x['request_number']}, {$x['status']})\n";
}
echo "Laudos anexados (NI2-539): {$r['results_checked']} conferidos · OK: {$r['results_ok']} · Faltando: " . count($r['results_missing']) . ' · Hash diferente: ' . count($r['results_mismatch']) . "\n";
foreach ($r['results_missing'] as $x) {
    echo "LAUDO FALTANDO   clínica {$x['clinic_id']} · laudo {$x['id']} (solicitação {$x['exam_request_id']}, {$x['status']})\n";
}
foreach ($r['results_mismatch'] as $x) {
    echo "LAUDO DIFERENTE  clínica {$x['clinic_id']} · laudo {$x['id']} (solicitação {$x['exam_request_id']}, {$x['status']})\n";
}
foreach ($r['orphans'] as $x) {
    echo "SOBRA      clínica {$x['clinic_id']} · arquivo {$x['file']} (não apagado)\n";
}
exit(($r['missing'] || $r['mismatch'] || $r['results_missing'] || $r['results_mismatch']) ? 1 : 0);
