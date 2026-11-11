<?php

declare(strict_types=1);

/**
 * Migration 56 (NI2-449): aceite versionado dos Termos de Uso e da Política de Privacidade por profissionais e equipe.
 *
 * - user_legal_acceptances: uma linha por documento aceito ('terms' ou 'privacy'), com versão, data, IP,
 *   navegador e origem ('web_signup' no cadastro, 'reacceptance' na tela /aceite-termos).
 * - Índice (user_id, document, version) para a verificação feita a cada navegação.
 * - Sem chave estrangeira para users/clinics: o registro é prova do aceite e não deve sumir em cascata.
 *   A retenção do IP segue a NI2-457.
 *
 * O aceite dos pacientes continua em patient_consents (não precisa de tabela nova).
 * Idempotente (MySQL/MariaDB e SQLite). Equivalente manual: database/manual/migracao-2026-10-26-aceite-termos-ni2-449.sql
 */
class CreateUserLegalAcceptances
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS user_legal_acceptances (
            id {$pk},
            user_id INT NOT NULL,
            clinic_id INT NULL,
            document VARCHAR(20) NOT NULL,
            version VARCHAR(20) NOT NULL,
            accepted_at DATETIME NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            source VARCHAR(30) NOT NULL
        ){$engine}");

        if (!$this->hasIndex($db, $mysql, 'idx_user_legal_acceptances_lookup')) {
            $db->exec('CREATE INDEX idx_user_legal_acceptances_lookup ON user_legal_acceptances (user_id, document, version)');
        }
    }

    public function down(PDO $db): void
    {
        // Não-destrutivo: os aceites são prova (LGPD art. 8 §2) e não são apagados por rollback de código.
    }

    private function hasIndex(PDO $db, bool $mysql, string $index): bool
    {
        if ($mysql) {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_legal_acceptances' AND INDEX_NAME = ?");
            $st->execute([$index]);
            return (int)$st->fetchColumn() > 0;
        }
        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$index]);
        return (int)$st->fetchColumn() > 0;
    }
}
