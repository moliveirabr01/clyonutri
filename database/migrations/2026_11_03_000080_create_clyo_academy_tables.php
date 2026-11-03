<?php

declare(strict_types=1);

/**
 * Migração 80 (NI2-663): Clyo Academy — Biblioteca científica, evidências clínicas e publicação profissional.
 *
 * Cria as tabelas do Clyo Academy:
 * - academy_articles (Biblioteca de literatura e estudos científicos com proveniência, DOI, PMID, acesso e desenho de estudo)
 * - academy_evidence_sheets (Evidence Desk: fichas de leitura crítica PICO, limitações e certeza por curador)
 * - academy_publications (Publishing: artigos e sínteses técnicas produzidos por nutricionistas da plataforma)
 * - academy_favorites (Coleção pessoal e favoritos por profissional e clínica)
 * - academy_protocol_links (Vínculo de evidências aos protocolos clínicos de anamnese NI2-635)
 *
 * Idempotente. Compatível com SQLite, MySQL 5.7/8.0 e MariaDB 10.3+.
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
            'academy_articles' => "
                id $id,
                pmid VARCHAR(30) NULL,
                doi VARCHAR(120) NULL,
                title VARCHAR(500) NOT NULL,
                authors $text NOT NULL,
                journal VARCHAR(255) NOT NULL,
                publication_year INT NOT NULL,
                study_design VARCHAR(50) NOT NULL,
                access_type VARCHAR(30) NOT NULL DEFAULT 'publisher_link',
                abstract $text NULL,
                url VARCHAR(500) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'pubmed',
                condition_tags VARCHAR(255) NULL,
                has_evidence_sheet INT NOT NULL DEFAULT 0,
                retraction_status VARCHAR(20) NOT NULL DEFAULT 'none',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ",
            'academy_evidence_sheets' => "
                id $id,
                article_id INT NOT NULL,
                curator_user_id INT NULL,
                curator_name VARCHAR(160) NOT NULL,
                curator_crn VARCHAR(50) NULL,
                population $text NOT NULL,
                intervention $text NOT NULL,
                comparator $text NOT NULL,
                outcomes $text NOT NULL,
                methodological_limitations $text NOT NULL,
                evidence_certainty VARCHAR(30) NOT NULL,
                clinical_applicability $text NOT NULL,
                conflict_of_interest $text NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ",
            'academy_publications' => "
                id $id,
                clinic_id INT NOT NULL,
                author_user_id INT NOT NULL,
                author_name VARCHAR(160) NOT NULL,
                author_crn VARCHAR(50) NULL,
                title VARCHAR(255) NOT NULL,
                category VARCHAR(50) NOT NULL,
                summary $text NOT NULL,
                body_markdown $text NOT NULL,
                references_text $text NOT NULL,
                conflict_of_interest_disclosure $text NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                editorial_notes $text NULL,
                reviewer_user_id INT NULL,
                reviewed_at DATETIME NULL,
                published_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ",
            'academy_favorites' => "
                id $id,
                clinic_id INT NOT NULL,
                user_id INT NOT NULL,
                article_id INT NOT NULL,
                collection_name VARCHAR(100) NOT NULL DEFAULT 'Geral',
                personal_notes $text NULL,
                created_at DATETIME NOT NULL,
                UNIQUE(clinic_id, user_id, article_id)
            ",
            'academy_protocol_links' => "
                id $id,
                protocol_code VARCHAR(60) NOT NULL,
                article_id INT NOT NULL,
                relevance_note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE(protocol_code, article_id)
            ",
        ];

        foreach ($tables as $t => $cols) {
            $db->exec("CREATE TABLE IF NOT EXISTS $t ($cols)$engine");
        }

        $indexes = [
            ['academy_articles', 'idx_aca_art_pmid', 'pmid'],
            ['academy_articles', 'idx_aca_art_doi', 'doi'],
            ['academy_articles', 'idx_aca_art_design', 'study_design'],
            ['academy_articles', 'idx_aca_art_year', 'publication_year'],
            ['academy_evidence_sheets', 'idx_aca_ev_art', 'article_id'],
            ['academy_publications', 'idx_aca_pub_clinic', 'clinic_id,status,created_at'],
            ['academy_favorites', 'idx_aca_fav_user', 'clinic_id,user_id'],
            ['academy_protocol_links', 'idx_aca_prot_link', 'protocol_code'],
        ];

        foreach ($indexes as [$t, $i, $c]) {
            if ($mysql) {
                $q = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
                $q->execute([$t, $i]);
                if (!$q->fetchColumn()) {
                    $db->exec("CREATE INDEX $i ON $t ($c)");
                }
            } else {
                $db->exec("CREATE INDEX IF NOT EXISTS $i ON $t ($c)");
            }
        }
    }
}
