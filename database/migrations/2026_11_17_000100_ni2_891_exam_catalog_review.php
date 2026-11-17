<?php

declare(strict_types=1);

/**
 * Migração 100 (NI2-891): revisão do catálogo geral de exames pelo nutricionista responsável técnico.
 * exam_catalog ganha a fonte da revisão (tabela TUSS/LOINC consultada) e a justificativa quando o exame
 * não tem código. Os campos clinical_review_status/clinical_reviewed_by/clinical_reviewed_at já existem (migração 66).
 * Solicitações já emitidas não mudam: exam_request_items guarda nome e código do momento da emissão.
 * Idempotente; SQL manual equivalente em database/manual/ni2_891_exames_revisao_mysql57.sql.
 */
final class Ni2ExamCatalogReview
{
    public function up(PDO $db): void
    {
        foreach (['clinical_review_source' => 'VARCHAR(255) NULL', 'standard_code_note' => 'VARCHAR(255) NULL'] as $name => $type) {
            if (!$this->hasColumn($db, $name)) {
                $db->exec("ALTER TABLE exam_catalog ADD COLUMN {$name} {$type}");
            }
        }
    }

    private function hasColumn(PDO $db, string $col): bool
    {
        if ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_catalog' AND COLUMN_NAME = ?");
            $st->execute([$col]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(exam_catalog)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $col) {
                return true;
            }
        }
        return false;
    }
}
