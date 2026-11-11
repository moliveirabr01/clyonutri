<?php

declare(strict_types=1);

/**
 * Migration 59 (backoffice: "Último login" de usuários e tenants): adiciona `users.last_login_at`.
 *
 * Por que existe: nenhum login bem-sucedido deixava rastro durável. O login web usa sessão nativa do PHP
 * (a tabela `sessions` nunca é escrita), `audit_logs` só guarda tentativas FALHAS/bloqueadas e
 * `login_attempts` zera no sucesso e é limpa em 1 dia.
 *
 * Fuso: o valor é gravado em UTC (como `login_attempts` e o `updated_at` do admin) e convertido para
 * APP_TIMEZONE (America/Sao_Paulo) só na exibição. Só login de PROFISSIONAL/EQUIPE (web, cadastro e app
 * profissional) grava aqui; paciente não tem linha em `users`.
 *
 * Nenhum registro existente muda: a coluna nasce vazia (NULL = "Sem registro").
 * Idempotente e compatível com MySQL 5.7 (sem ADD COLUMN IF NOT EXISTS; usa information_schema) e SQLite.
 */
class AddLastLoginAtToUsers
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if (!$this->hasColumn($db, $mysql, 'users', 'last_login_at')) {
            $db->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL');
        }

        if (!$this->hasIndex($db, $mysql, 'users', 'idx_users_last_login')) {
            $db->exec('CREATE INDEX idx_users_last_login ON users (last_login_at)');
        }
    }

    public function down(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($this->hasIndex($db, $mysql, 'users', 'idx_users_last_login')) {
            $db->exec($mysql ? 'DROP INDEX idx_users_last_login ON users' : 'DROP INDEX idx_users_last_login');
        }

        if ($this->hasColumn($db, $mysql, 'users', 'last_login_at')) {
            $db->exec('ALTER TABLE users DROP COLUMN last_login_at');
        }
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        }

        foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(PDO $db, bool $mysql, string $table, string $index): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $index]);
            return (int)$st->fetchColumn() > 0;
        }

        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$index]);
        return (int)$st->fetchColumn() > 0;
    }
}
