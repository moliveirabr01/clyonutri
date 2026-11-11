<?php

declare(strict_types=1);

/**
 * Migração 85 (v54.23): lâminas para pacientes na Clyo Academy.
 *  - academy_handouts: lâmina de uma página (PDF) com prévia opcional (PNG ou JPG), título, resumo, etiquetas, fonte e situação
 *    (draft, published, archived). Os arquivos ficam fora da pasta pública (storage/app/academy/laminas/); o banco guarda
 *    metadados e SHA-256. Quem cadastra e publica é a equipe Clyo Nutri no console; a clínica só lê, baixa e favorita.
 *  - academy_handout_favorites: favoritos das lâminas, por pessoa e clínica, com a mesma pasta da coleção dos artigos.
 * Nenhuma lâmina nasce aqui (sem semente): a importação é feita por bin/importar_laminas_academy.php, sempre como rascunho.
 * Idempotente; aditiva; sem chave estrangeira.
 */
final class CreateAcademyHandouts
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS academy_handouts (id $id, slug VARCHAR(120) NOT NULL, title VARCHAR(200) NOT NULL, summary TEXT NULL,
            tags VARCHAR(500) NULL, source_citation TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft',
            file_name VARCHAR(160) NOT NULL, mime_type VARCHAR(60) NOT NULL, size_bytes INT NOT NULL, sha256 CHAR(64) NOT NULL, storage_name VARCHAR(120) NOT NULL,
            preview_storage_name VARCHAR(120) NULL, preview_mime VARCHAR(60) NULL, preview_size INT NULL, preview_sha256 CHAR(64) NULL,
            created_by_user_id INT NOT NULL, updated_by_user_id INT NULL, published_at DATETIME NULL, published_by_user_id INT NULL,
            archived_reason VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$engine");
        $db->exec("CREATE TABLE IF NOT EXISTS academy_handout_favorites (id $id, clinic_id INT NOT NULL, user_id INT NOT NULL, handout_id INT NOT NULL,
            collection_name VARCHAR(100) NOT NULL DEFAULT 'Geral', created_at DATETIME NOT NULL)$engine");
        $indexes = [
            ['academy_handouts', 'uq_academy_handouts_slug', 'slug', true],
            ['academy_handouts', 'idx_academy_handouts_status', 'status', false],
            ['academy_handout_favorites', 'uq_academy_hfav_user', 'clinic_id,user_id,handout_id', true],
            ['academy_handout_favorites', 'idx_academy_hfav_handout', 'handout_id', false],
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
}
