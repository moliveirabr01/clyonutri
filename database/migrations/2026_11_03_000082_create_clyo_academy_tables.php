<?php

declare(strict_types=1);

/**
 * Migração 82 (NI2-663): Clyo Academy — biblioteca científica, fichas críticas, publicações profissionais, coleção pessoal
 * e vínculos com protocolos (NI2-635). Renumerada da "80" do commit fae6581, que colidia com a 80 (workflow_receipts)
 * da v54.18. Idempotente: cria o que falta e acrescenta as colunas novas se a versão antiga das tabelas já existir.
 *
 * Sem nenhum conteúdo: o acervo e as fichas entram só pelo console editorial, com proveniência e aprovação.
 */
final class CreateClyoAcademyTables
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $tables = [
            // Acervo: metadados com proveniência. O texto do resumo só é exibido com licença declarada.
            'academy_articles' => "id $id, pmid VARCHAR(30) NULL, doi VARCHAR(120) NULL, title VARCHAR(500) NOT NULL, authors $text NOT NULL,
                journal VARCHAR(255) NOT NULL, publication_year INT NOT NULL, study_design VARCHAR(50) NOT NULL,
                access_type VARCHAR(30) NOT NULL DEFAULT 'publisher_link', abstract $text NULL, url VARCHAR(500) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'pubmed', condition_tags VARCHAR(255) NULL, has_evidence_sheet INT NOT NULL DEFAULT 0,
                retraction_status VARCHAR(20) NOT NULL DEFAULT 'none', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL",
            // Ficha crítica: rascunho → em revisão → publicada (aprovação por outra pessoa, com CRN).
            'academy_evidence_sheets' => "id $id, article_id INT NOT NULL, curator_user_id INT NULL, curator_name VARCHAR(160) NOT NULL,
                curator_crn VARCHAR(50) NULL, population $text NOT NULL, intervention $text NOT NULL, comparator $text NOT NULL,
                outcomes $text NOT NULL, methodological_limitations $text NOT NULL, evidence_certainty VARCHAR(30) NOT NULL,
                clinical_applicability $text NOT NULL, conflict_of_interest $text NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft',
                reviewed_at DATETIME NULL, created_at DATETIME NOT NULL",
            'academy_publications' => "id $id, clinic_id INT NOT NULL, author_user_id INT NOT NULL, author_name VARCHAR(160) NOT NULL,
                author_crn VARCHAR(50) NULL, title VARCHAR(255) NOT NULL, category VARCHAR(50) NOT NULL, summary $text NOT NULL,
                body_markdown $text NOT NULL, references_text $text NOT NULL, conflict_of_interest_disclosure $text NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft', editorial_notes $text NULL, reviewer_user_id INT NULL, reviewed_at DATETIME NULL,
                published_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL",
            'academy_favorites' => "id $id, clinic_id INT NOT NULL, user_id INT NOT NULL, article_id INT NOT NULL,
                collection_name VARCHAR(100) NOT NULL DEFAULT 'Geral', personal_notes $text NULL, created_at DATETIME NOT NULL,
                UNIQUE(clinic_id, user_id, article_id)",
            'academy_protocol_links' => "id $id, protocol_code VARCHAR(60) NOT NULL, article_id INT NOT NULL, relevance_note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL, UNIQUE(protocol_code, article_id)",
        ];
        foreach ($tables as $t => $cols) {
            $db->exec("CREATE TABLE IF NOT EXISTS $t ($cols)$engine");
        }

        // Colunas novas (também para quem rodou a "80" do commit fae6581 num ambiente de desenvolvimento)
        $add = [
            'academy_articles' => ['license' => 'VARCHAR(60) NULL', 'retrieved_at' => 'DATETIME NULL', 'created_by_user_id' => 'INT NULL'],
            'academy_evidence_sheets' => ['submitted_at' => 'DATETIME NULL', 'approved_by_user_id' => 'INT NULL', 'approved_by_name' => 'VARCHAR(160) NULL',
                'approved_by_crn' => 'VARCHAR(50) NULL', 'approved_at' => 'DATETIME NULL'],
            'academy_publications' => ['submitted_at' => 'DATETIME NULL'],
            'academy_protocol_links' => ['created_by_user_id' => 'INT NULL'],
        ];
        foreach ($add as $t => $cols) {
            foreach ($cols as $c => $def) {
                if (!self::hasColumn($db, $mysql, $t, $c)) {
                    $db->exec("ALTER TABLE $t ADD COLUMN $c $def");
                }
            }
        }

        $indexes = [
            ['academy_articles', 'uq_aca_art_pmid', 'pmid', true], // deduplicação também no banco (NULL não conflita)
            ['academy_articles', 'uq_aca_art_doi', 'doi', true],
            ['academy_articles', 'idx_aca_art_design', 'study_design', false],
            ['academy_articles', 'idx_aca_art_year', 'publication_year', false],
            ['academy_evidence_sheets', 'idx_aca_ev_art', 'article_id,status', false],
            ['academy_publications', 'idx_aca_pub_clinic', 'clinic_id,status,created_at', false],
            ['academy_publications', 'idx_aca_pub_status', 'status,published_at', false],
            ['academy_favorites', 'idx_aca_fav_user', 'clinic_id,user_id', false],
            ['academy_protocol_links', 'idx_aca_prot_link', 'protocol_code', false],
        ];
        foreach ($indexes as [$t, $i, $c, $unique]) {
            $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
            if ($mysql) {
                $q = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
                $q->execute([$t, $i]);
                if (!$q->fetchColumn()) {
                    $db->exec("CREATE $kind $i ON $t ($c)");
                }
            } else {
                $db->exec("CREATE $kind IF NOT EXISTS $i ON $t ($c)");
            }
        }
    }

    private static function hasColumn(PDO $db, bool $mysql, string $table, string $col): bool
    {
        if ($mysql) {
            $q = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$table, $col]);
            return (bool)$q->fetchColumn();
        }
        foreach ($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['name'] === $col) {
                return true;
            }
        }
        return false;
    }
}
