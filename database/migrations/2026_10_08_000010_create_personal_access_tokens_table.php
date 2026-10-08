<?php

declare(strict_types=1);

class CreatePersonalAccessTokensTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS personal_access_tokens (
            id {$pk},
            tokenable_type VARCHAR(50) NOT NULL,
            tokenable_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            abilities TEXT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS personal_access_tokens;");
    }
}
