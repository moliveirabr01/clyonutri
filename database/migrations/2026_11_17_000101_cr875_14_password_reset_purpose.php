<?php

declare(strict_types=1);

/**
 * Migração 101 (CR875-14 / NI2-890): password_resets guarda o tipo do link ('reset' = nova senha, 'invite' = convite
 * da equipe). O escopo deixa de depender só do status do usuário: link de convite não serve na tela de nova senha e
 * vice-versa. Links antigos (purpose NULL) seguem a regra anterior pelo status até expirarem.
 * Idempotente; SQL manual equivalente em database/manual/cr875_14_password_reset_purpose_mysql57.sql.
 */
final class Cr875PasswordResetPurpose
{
    public function up(PDO $db): void
    {
        $mysql = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $c = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'purpose'")->fetchColumn();
        } else {
            $c = 0;
            foreach ($db->query('PRAGMA table_info(password_resets)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === 'purpose') { $c = 1; }
            }
        }
        if ($c === 0) {
            $db->exec('ALTER TABLE password_resets ADD COLUMN purpose VARCHAR(10) NULL');
        }
    }
}
