<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use PDO;

/**
 * Conferência dos PDFs guardados (v47, NI2-542): para rodar depois de backup, restauração ou mudança de servidor.
 * SOMENTE LEITURA: não grava no banco nem mexe em arquivo. Para cada solicitação com pdf_path (emitida ou cancelada,
 * porque o PDF fica como histórico) confere se o arquivo existe e se o SHA-256 bate com o gravado na emissão.
 * Também conta arquivos na pasta que nenhuma solicitação aponta (sobras), por clínica.
 * Relatório sem nome de paciente nem texto clínico: só id, número da solicitação e clínica.
 */
class ExamPdfIntegrityChecker
{
    public function __construct(private PDO $db, private ExamRequestPdfService $pdf)
    {
    }

    /** @return array{checked:int, ok:int, missing:array, mismatch:array, orphans:array, results_checked:int, results_ok:int, results_missing:array, results_mismatch:array} */
    public function run(?int $clinicId = null, ?string $storageBaseDir = null): array
    {
        $sql = "SELECT id, clinic_id, request_number, status, pdf_path, pdf_hash FROM exam_requests
            WHERE pdf_path IS NOT NULL AND pdf_path <> ''";
        $params = [];
        if ($clinicId !== null) {
            $sql .= ' AND clinic_id = ?';
            $params[] = $clinicId;
        }
        $st = $this->db->prepare($sql . ' ORDER BY clinic_id, id');
        $st->execute($params);
        $report = ['checked' => 0, 'ok' => 0, 'missing' => [], 'mismatch' => [], 'orphans' => []];
        $known = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $report['checked']++;
            $known[(string)$r['pdf_path']] = true;
            $row = ['id' => (int)$r['id'], 'clinic_id' => (int)$r['clinic_id'], 'request_number' => (string)$r['request_number'], 'status' => (string)$r['status']];
            $bytes = $this->pdf->read((string)$r['pdf_path']);
            if ($bytes === null) {
                $report['missing'][] = $row;
            } elseif (!hash_equals((string)$r['pdf_hash'], hash('sha256', $bytes))) {
                $report['mismatch'][] = $row;
            } else {
                $report['ok']++;
            }
        }
        $base = rtrim($storageBaseDir ?? (getenv('EXAM_PDF_STORAGE_DIR') ?: dirname(__DIR__, 3) . '/storage/app'), '/');

        // v48 (NI2-539): laudos anexados (originais), mesma regra: existe e o SHA-256 bate.
        $report += ['results_checked' => 0, 'results_ok' => 0, 'results_missing' => [], 'results_mismatch' => []];
        $hasResults = true;
        try {
            $rs = $this->db->prepare('SELECT id, clinic_id, exam_request_id, status, stored_path, sha256 FROM exam_result_documents'
                . ($clinicId !== null ? ' WHERE clinic_id = ?' : '') . ' ORDER BY clinic_id, id');
            $rs->execute($clinicId !== null ? [$clinicId] : []);
            $resultRows = $rs->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $hasResults = false; // banco ainda sem a migração 67
            $resultRows = [];
        }
        $resultSvc = new ExamResultDocumentService($this->db, null, $base);
        $knownResults = [];
        foreach ($resultRows as $d) {
            $report['results_checked']++;
            $knownResults[(string)$d['stored_path']] = true;
            $row = ['id' => (int)$d['id'], 'clinic_id' => (int)$d['clinic_id'], 'exam_request_id' => (int)$d['exam_request_id'], 'status' => (string)$d['status']];
            $abs = $resultSvc->resolve((string)$d['stored_path']);
            if ($abs === null || !is_file($abs)) {
                $report['results_missing'][] = $row;
            } elseif (!hash_equals((string)$d['sha256'], (string)hash_file('sha256', $abs))) {
                $report['results_mismatch'][] = $row;
            } else {
                $report['results_ok']++;
            }
        }
        if ($hasResults && is_dir($base . '/exam_results')) {
            foreach (glob($base . '/exam_results/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $cid = (int)basename($dir);
                if ($clinicId !== null && $cid !== $clinicId) {
                    continue;
                }
                foreach (glob($dir . '/*') ?: [] as $file) {
                    if (!isset($knownResults['exam_results/' . $cid . '/' . basename($file)])) {
                        $report['orphans'][] = ['clinic_id' => $cid, 'file' => 'laudo:' . basename($file)];
                    }
                }
            }
        }

        $root = $base . '/exam_requests';
        if (is_dir($root)) {
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $cid = (int)basename($dir);
                if ($clinicId !== null && $cid !== $clinicId) {
                    continue;
                }
                foreach (glob($dir . '/*.pdf') ?: [] as $file) {
                    $rel = 'exam_requests/' . $cid . '/' . basename($file);
                    if (!isset($known[$rel])) {
                        $report['orphans'][] = ['clinic_id' => $cid, 'file' => basename($file)];
                    }
                }
            }
        }
        return $report;
    }
}
