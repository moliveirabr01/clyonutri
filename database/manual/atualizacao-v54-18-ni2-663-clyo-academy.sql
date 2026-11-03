-- Clyo Nutri / Migração 80 (NI2-663): Clyo Academy
-- Cria as tabelas da Clyo Academy (Biblioteca Científica, Evidence Desk, Publicações Profissionais, Coleção e Vínculos).
-- Compatível com MySQL 5.7/8.0 e MariaDB.

SET @db = DATABASE();

CREATE TABLE IF NOT EXISTS academy_articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pmid VARCHAR(30) NULL,
    doi VARCHAR(120) NULL,
    title VARCHAR(500) NOT NULL,
    authors TEXT NOT NULL,
    journal VARCHAR(255) NOT NULL,
    publication_year INT NOT NULL,
    study_design VARCHAR(50) NOT NULL,
    access_type VARCHAR(30) NOT NULL DEFAULT 'publisher_link',
    abstract LONGTEXT NULL,
    url VARCHAR(500) NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'pubmed',
    condition_tags VARCHAR(255) NULL,
    has_evidence_sheet INT NOT NULL DEFAULT 0,
    retraction_status VARCHAR(20) NOT NULL DEFAULT 'none',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_aca_art_pmid (pmid),
    INDEX idx_aca_art_doi (doi),
    INDEX idx_aca_art_design (study_design),
    INDEX idx_aca_art_year (publication_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_evidence_sheets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    curator_user_id INT NULL,
    curator_name VARCHAR(160) NOT NULL,
    curator_crn VARCHAR(50) NULL,
    population LONGTEXT NOT NULL,
    intervention LONGTEXT NOT NULL,
    comparator LONGTEXT NOT NULL,
    outcomes LONGTEXT NOT NULL,
    methodological_limitations LONGTEXT NOT NULL,
    evidence_certainty VARCHAR(30) NOT NULL,
    clinical_applicability LONGTEXT NOT NULL,
    conflict_of_interest LONGTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'published',
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_aca_ev_art (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_publications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    author_user_id INT NOT NULL,
    author_name VARCHAR(160) NOT NULL,
    author_crn VARCHAR(50) NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(50) NOT NULL,
    summary LONGTEXT NOT NULL,
    body_markdown LONGTEXT NOT NULL,
    references_text LONGTEXT NOT NULL,
    conflict_of_interest_disclosure LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    editorial_notes LONGTEXT NULL,
    reviewer_user_id INT NULL,
    reviewed_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_aca_pub_clinic (clinic_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    user_id INT NOT NULL,
    article_id INT NOT NULL,
    collection_name VARCHAR(100) NOT NULL DEFAULT 'Geral',
    personal_notes LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_aca_fav (clinic_id, user_id, article_id),
    INDEX idx_aca_fav_user (clinic_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_protocol_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_code VARCHAR(60) NOT NULL,
    article_id INT NOT NULL,
    relevance_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_aca_prot_link (protocol_code, article_id),
    INDEX idx_aca_prot_link (protocol_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO migrations (migration) VALUES ('2026_11_03_000080_create_clyo_academy_tables');

SELECT 'MIGRAÇÃO 80 OK — Clyo Academy instalada com sucesso.' AS resultado;
