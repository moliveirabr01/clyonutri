<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use InvalidArgumentException;
use PDO;

/**
 * NI2-891: revisão do catálogo geral de exames (clinic_id NULL) pelo nutricionista responsável técnico.
 * Fluxo: exportar CSV → RT preenche TUSS (e LOINC quando houver) ou a justificativa → importar com revisor e fonte
 * → gerar o SQL idempotente para produção (phpMyAdmin, MySQL 5.7). Exames da clínica não entram aqui.
 * Solicitações emitidas não mudam: exam_request_items guarda nome e código do momento da emissão.
 */
final class ExamCatalogReviewService
{
    public const HEADER = ['codigo_interno', 'exame', 'material', 'jejum_horas', 'sistema', 'codigo_padrao', 'justificativa_sem_codigo', 'situacao', 'revisor', 'revisado_em', 'fonte'];

    public function __construct(private PDO $db) {}

    public function ready(): bool
    {
        foreach (['clinical_review_source', 'standard_code_note'] as $col) {
            try { $this->db->query("SELECT {$col} FROM exam_catalog WHERE 1 = 0"); } catch (\Throwable) { return false; }
        }
        return true;
    }

    /** @return array{total:int,reviewed:int,pending:int,last_reviewed_at:?string} */
    public function stats(): array
    {
        $r = $this->db->query("SELECT COUNT(*) AS total,
                SUM(CASE WHEN clinical_review_status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed,
                MAX(CASE WHEN clinical_review_status = 'reviewed' THEN clinical_reviewed_at END) AS last_at
            FROM exam_catalog WHERE clinic_id IS NULL AND is_active = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($r['total'] ?? 0); $rev = (int)($r['reviewed'] ?? 0);
        return ['total' => $total, 'reviewed' => $rev, 'pending' => $total - $rev, 'last_reviewed_at' => $r['last_at'] ?? null];
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        $extra = $this->ready() ? 'clinical_review_source, standard_code_note' : 'NULL AS clinical_review_source, NULL AS standard_code_note';
        return $this->db->query("SELECT id, code, canonical_name, sample_type, fasting_hours, standard_code_system, standard_code,
                clinical_review_status, clinical_reviewed_by, clinical_reviewed_at, {$extra}
            FROM exam_catalog WHERE clinic_id IS NULL AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exportCsv(): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, ';', '"', '\\');
        foreach ($this->rows() as $r) {
            fputcsv($out, [$r['code'], $r['canonical_name'], $r['sample_type'], (int)$r['fasting_hours'], (string)$r['standard_code_system'], (string)$r['standard_code'],
                (string)$r['standard_code_note'], $r['clinical_review_status'] === 'reviewed' ? 'revisado' : 'pendente', (string)$r['clinical_reviewed_by'],
                (string)$r['clinical_reviewed_at'], (string)$r['clinical_review_source']], ';', '"', '\\');
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }

    /**
     * Importa a planilha revisada. Cada linha é validada sozinha; linha inválida é rejeitada e as demais seguem.
     * @return array{applied:int,unchanged:int,rejected:list<array{line:int,code:string,reason:string}>,dry_run:bool}
     */
    public function importCsv(string $csv, string $reviewer, string $defaultSource, bool $dryRun = false, ?string $now = null): array
    {
        if (!$this->ready()) throw new InvalidArgumentException('Rode a migração 100 (SQL ni2_891_exames_revisao_mysql57.sql) antes de importar.');
        $reviewer = trim($reviewer);
        if (mb_strlen($reviewer, 'UTF-8') < 5 || !preg_match('/CRN/i', $reviewer)) {
            throw new InvalidArgumentException('Informe o revisor com nome e CRN, por exemplo: "Ana Souza — CRN-3 12345".');
        }
        $now ??= date('Y-m-d H:i:s');
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $header = str_getcsv((string)array_shift($lines), ';', '"', '\\');
        $idx = array_flip(array_map(static fn($h) => trim(mb_strtolower((string)$h, 'UTF-8')), $header));
        foreach (['codigo_interno', 'sistema', 'codigo_padrao', 'justificativa_sem_codigo'] as $need) {
            if (!isset($idx[$need])) throw new InvalidArgumentException("Cabeçalho sem a coluna {$need}. Use o arquivo exportado por --export.");
        }
        $find = $this->db->prepare("SELECT id, standard_code_system, standard_code, standard_code_note, clinical_review_status FROM exam_catalog WHERE code = ? AND clinic_id IS NULL");
        $upd = $this->db->prepare("UPDATE exam_catalog SET standard_code_system = ?, standard_code = ?, standard_code_note = ?, clinical_review_status = 'reviewed',
                clinical_reviewed_by = ?, clinical_reviewed_at = ?, clinical_review_source = ?, updated_at = ? WHERE id = ? AND clinic_id IS NULL");
        $res = ['applied' => 0, 'unchanged' => 0, 'rejected' => [], 'dry_run' => $dryRun];
        $seen = [];
        if (!$dryRun) $this->db->beginTransaction();
        try {
            foreach ($lines as $i => $line) {
                if (trim($line) === '') continue;
                $c = str_getcsv($line, ';', '"', '\\');
                $get = static fn(string $k) => trim((string)($c[$idx[$k] ?? -1] ?? ''));
                $code = $get('codigo_interno'); $system = mb_strtoupper($get('sistema'), 'UTF-8'); $std = $get('codigo_padrao'); $note = $get('justificativa_sem_codigo');
                $source = isset($idx['fonte']) && $get('fonte') !== '' ? $get('fonte') : trim($defaultSource);
                $lineNo = $i + 2;
                $reject = function (string $why) use (&$res, $lineNo, $code) { $res['rejected'][] = ['line' => $lineNo, 'code' => $code, 'reason' => $why]; };
                if ($code === '' || isset($seen[$code])) { $reject($code === '' ? 'linha sem código interno' : 'código repetido na planilha'); continue; }
                $seen[$code] = true;
                $find->execute([$code]); $row = $find->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $reject('exame não existe no catálogo geral'); continue; }
                if ($std === '' && $note === '') { $reject('sem código e sem justificativa'); continue; }
                if ($std !== '') {
                    if ($system === 'TUSS' && !preg_match('/^\d{8}$/', $std)) { $reject('código TUSS deve ter 8 dígitos'); continue; }
                    if ($system === 'LOINC' && !preg_match('/^\d{1,7}-\d$/', $std)) { $reject('código LOINC no formato 12345-6'); continue; }
                    if (!in_array($system, ['TUSS', 'LOINC'], true)) { $reject('sistema deve ser TUSS ou LOINC'); continue; }
                } else { $system = ''; }
                if (mb_strlen($source, 'UTF-8') < 4) { $reject('informe a fonte (ex.: Tabela TUSS 22 da ANS, versão 2026-10)'); continue; }
                $same = $row['clinical_review_status'] === 'reviewed' && (string)$row['standard_code_system'] === $system
                    && (string)$row['standard_code'] === $std && (string)$row['standard_code_note'] === $note;
                if ($same) { $res['unchanged']++; continue; }
                if (!$dryRun) $upd->execute([$system !== '' ? $system : null, $std !== '' ? $std : null, $note !== '' ? mb_substr($note, 0, 255, 'UTF-8') : null,
                    mb_substr($reviewer, 0, 255, 'UTF-8'), $now, mb_substr($source, 0, 255, 'UTF-8'), $now, (int)$row['id']]);
                $res['applied']++;
            }
            if (!$dryRun) $this->db->commit();
        } catch (\Throwable $e) {
            if (!$dryRun && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $res;
    }

    /** SQL idempotente (MySQL 5.7) com os exames revisados, para rodar em produção no phpMyAdmin. */
    public function productionSql(): string
    {
        $q = fn($v) => $v === null || $v === '' ? 'NULL' : $this->db->quote((string)$v);
        $sql = "-- NI2-891: carga da revisão do catálogo de exames (gerado em " . date('d/m/Y H:i') . "). MySQL 5.7, idempotente.\n"
            . "-- Rode antes database/manual/ni2_891_exames_revisao_mysql57.sql. Pode rodar mais de uma vez: só altera o exame do catálogo geral pelo código interno.\n";
        $n = 0;
        foreach ($this->rows() as $r) {
            if ($r['clinical_review_status'] !== 'reviewed') continue;
            $n++;
            $sql .= "UPDATE exam_catalog SET standard_code_system = {$q($r['standard_code_system'])}, standard_code = {$q($r['standard_code'])}, "
                . "standard_code_note = {$q($r['standard_code_note'])}, clinical_review_status = 'reviewed', clinical_reviewed_by = {$q($r['clinical_reviewed_by'])}, "
                . "clinical_reviewed_at = {$q($r['clinical_reviewed_at'])}, clinical_review_source = {$q($r['clinical_review_source'])}, updated_at = {$q($r['clinical_reviewed_at'])} "
                . "WHERE code = {$q($r['code'])} AND clinic_id IS NULL;\n";
        }
        return $sql . "-- {$n} exames revisados.\nSELECT COUNT(*) AS exames_sem_revisao FROM exam_catalog WHERE clinic_id IS NULL AND is_active = 1 AND (clinical_review_status IS NULL OR clinical_review_status <> 'reviewed');\n";
    }
}
