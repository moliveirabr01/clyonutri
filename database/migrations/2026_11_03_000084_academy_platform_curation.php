<?php

declare(strict_types=1);

/**
 * Migração 84 (v54.22): Clyo Academy só para consumo nas clínicas, com curadoria da equipe Clyo Nutri.
 *  - academy_articles.status (draft | published | archived). Artigos que já existem nascem "published" para não sumirem da biblioteca;
 *    artigos novos entram como rascunho e a equipe publica no console.
 *  - liga a chave global feature_flag_academy (decisão do Maicon em 03/11/2026). Só liga quando a 84 roda pela primeira vez:
 *    o Migrator não repete migração, então desligar depois pelo console continua valendo.
 * Idempotente; aditiva; sem chave estrangeira.
 */
final class AcademyPlatformCuration
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (!$this->hasColumn($db, $mysql, 'academy_articles', 'status')) {
            $db->exec("ALTER TABLE academy_articles ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'published'");
        }
        if ($mysql) {
            $q = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $q->execute(['academy_articles', 'idx_academy_articles_status']);
            if (!$q->fetchColumn()) {
                $db->exec('CREATE INDEX idx_academy_articles_status ON academy_articles (status)');
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_academy_articles_status ON academy_articles (status)');
        }

        $desc = 'Habilita a Clyo Academy para as clínicas (só leitura; curadoria pela equipe Clyo Nutri)';
        $now = date('Y-m-d H:i:s');
        $has = $db->prepare('SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?');
        $has->execute(['feature_flag_academy']);
        if ((int)$has->fetchColumn() > 0) {
            $db->prepare("UPDATE platform_settings SET setting_value = '1', description = ?, updated_at = ? WHERE setting_key = 'feature_flag_academy'")->execute([$desc, $now]);
        } else {
            $db->prepare("INSERT INTO platform_settings (setting_key, setting_value, description, created_at, updated_at) VALUES ('feature_flag_academy', '1', ?, ?, ?)")
                ->execute([$desc, $now, $now]);
        }
        if (class_exists(\ClyoNutri\Core\Settings\PlatformSettings::class)) {
            \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
        }
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$table, $column]);
            return (int)$q->fetchColumn() > 0;
        }
        foreach ($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return true;
            }
        }
        return false;
    }
}
