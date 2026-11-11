<?php

declare(strict_types=1);

/**
 * Migration 67 (v48, NI2-539): laudos e resultados laboratoriais anexados à solicitação de exames.
 *
 *  - exam_result_documents: o arquivo ORIGINAL do laudo (guardado fora de public/, nunca sobrescrito), com SHA-256 dos
 *    bytes, tipo real detectado, laboratório, data da coleta, data do laudo, quem enviou (nutricionista ou paciente),
 *    situação (aguardando revisão, aceito, recusado), revisão e versões de correção (a correção é um documento novo
 *    que aponta para o anterior; o anterior continua guardado).
 *  - exam_result_document_items: quais exames da solicitação aquele laudo cobre.
 *
 * Não altera nem apaga dados. Sem chave estrangeira. Idempotente. MySQL 5.7/8 e SQLite.
 */
class ExamResultDocuments
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS exam_result_documents (
            id {$pk},
            clinic_id {$big} NOT NULL,
            patient_id {$big} NOT NULL,
            exam_request_id {$big} NOT NULL,
            uploaded_by_type VARCHAR(20) NOT NULL,
            uploaded_by_user_id {$big} NULL,
            uploaded_by_patient_id {$big} NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes INT NOT NULL,
            sha256 CHAR(64) NOT NULL,
            security_check VARCHAR(255) NOT NULL,
            laboratory_name VARCHAR(255) NULL,
            collected_at DATE NULL,
            report_issued_at DATE NULL,
            notes VARCHAR(1000) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
            reviewed_by_user_id {$big} NULL,
            reviewed_at DATETIME NULL,
            review_note VARCHAR(1000) NULL,
            version_number INT NOT NULL DEFAULT 1,
            supersedes_document_id {$big} NULL,
            root_document_id {$big} NULL,
            correction_reason VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL
        ){$engine}");
        $this->index($db, $mysql, 'exam_result_documents', 'idx_exam_result_docs_request', '(clinic_id, exam_request_id)');
        $this->index($db, $mysql, 'exam_result_documents', 'idx_exam_result_docs_patient', '(clinic_id, patient_id, status)');
        $this->index($db, $mysql, 'exam_result_documents', 'idx_exam_result_docs_supersedes', '(supersedes_document_id)');

        $db->exec("CREATE TABLE IF NOT EXISTS exam_result_document_items (
            id {$pk},
            document_id {$big} NOT NULL,
            exam_request_item_id {$big} NOT NULL,
            created_at DATETIME NOT NULL
        ){$engine}");
        $this->index($db, $mysql, 'exam_result_document_items', 'uq_exam_result_doc_item', '(document_id, exam_request_item_id)', true);
        $this->index($db, $mysql, 'exam_result_document_items', 'idx_exam_result_doc_item_item', '(exam_request_item_id)');
    }

    public function down(PDO $db): void
    {
        // Sem volta automática: os laudos são documentos clínicos originais.
    }

    private function index(PDO $db, bool $mysql, string $table, string $name, string $cols, bool $unique = false): void
    {
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $name]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE {$table} ADD {$kind} {$name} {$cols}");
            }
        } else {
            $db->exec("CREATE {$kind} IF NOT EXISTS {$name} ON {$table} {$cols}");
        }
    }
}
