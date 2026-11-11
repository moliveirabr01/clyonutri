<?php

declare(strict_types=1);

/**
 * Migration 66 (v47, NI2-530, NI2-532, NI2-533, NI2-537, NI2-542): completa as histórias parciais do pedido de exames.
 *
 *  - exam_regulatory_policies: change_summary (o que mudou em cada versão; versões antigas continuam consultáveis).
 *  - exam_catalog: exame personalizado da clínica com validação (clinic_id, origin, validation_*), campos de código
 *    padronizado (standard_code_system/standard_code, vazios até haver licença) e situação da revisão clínica
 *    (clinical_review_status, pendente para os 22 exames da u3).
 *  - exam_favorites: favoritos por nutricionista e clínica.
 *  - exam_requests: vínculo com o atendimento (appointment_id) e versões (supersedes_request_id, root_request_id,
 *    version_number). A nova versão aponta para a anterior; a anterior fica cancelada com o motivo.
 *
 * Não altera nem apaga dados: só acrescenta colunas com padrão, uma tabela e índices. Sem chave estrangeira.
 * Idempotente. MySQL 5.7/8 e SQLite.
 */
class ExamCatalogFavoritesVersionsAndLinks
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';
        $text = $mysql ? 'TEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $this->columns($db, $mysql, 'exam_regulatory_policies', [
            'change_summary' => "{$text} NULL",
        ]);

        $this->columns($db, $mysql, 'exam_catalog', [
            'clinic_id' => "{$big} NULL",
            'origin' => "VARCHAR(20) NOT NULL DEFAULT 'curated'",
            'validation_status' => "VARCHAR(20) NOT NULL DEFAULT 'approved'",
            'submitted_by_user_id' => "{$big} NULL",
            'validated_by_user_id' => "{$big} NULL",
            'validated_at' => 'DATETIME NULL',
            'validation_note' => 'VARCHAR(500) NULL',
            'standard_code_system' => 'VARCHAR(20) NULL',
            'standard_code' => 'VARCHAR(40) NULL',
            'clinical_review_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'clinical_reviewed_by' => 'VARCHAR(255) NULL',
            'clinical_reviewed_at' => 'DATETIME NULL',
        ]);
        $this->index($db, $mysql, 'exam_catalog', 'idx_exam_catalog_clinic_validation', '(clinic_id, validation_status)');

        $db->exec("CREATE TABLE IF NOT EXISTS exam_favorites (
            id {$pk},
            clinic_id {$big} NOT NULL,
            user_id {$big} NOT NULL,
            exam_id {$big} NOT NULL,
            created_at DATETIME NOT NULL
        ){$engine}");
        $this->index($db, $mysql, 'exam_favorites', 'uq_exam_favorites_user_exam', '(clinic_id, user_id, exam_id)', true);

        $this->columns($db, $mysql, 'exam_requests', [
            'appointment_id' => "{$big} NULL",
            'supersedes_request_id' => "{$big} NULL",
            'root_request_id' => "{$big} NULL",
            'version_number' => 'INT NOT NULL DEFAULT 1',
        ]);
        $this->index($db, $mysql, 'exam_requests', 'idx_exam_requests_appointment', '(clinic_id, appointment_id)');
        $this->index($db, $mysql, 'exam_requests', 'idx_exam_requests_root', '(root_request_id)');
    }

    public function down(PDO $db): void
    {
        // Sem volta automática: as colunas guardam histórico (versões, vínculo com o atendimento, validação).
    }

    private function columns(PDO $db, bool $mysql, string $table, array $columns): void
    {
        foreach ($columns as $name => $definition) {
            if (!$this->hasColumn($db, $mysql, $table, $name)) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
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
