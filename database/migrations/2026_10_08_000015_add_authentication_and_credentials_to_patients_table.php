<?php

declare(strict_types=1);

class AddAuthenticationAndCredentialsToPatientsTable
{
    public function up(PDO $db): void
    {
        $columns = [
            'password_hash VARCHAR(255) NULL',
            'access_pin VARCHAR(255) NULL',
            'otp_code VARCHAR(255) NULL',
            'otp_expires_at DATETIME NULL',
        ];

        foreach ($columns as $col) {
            try {
                $db->exec("ALTER TABLE patients ADD COLUMN {$col};");
            } catch (\Throwable $e) {
                // Coluna já existente (idempotência)
            }
        }
    }

    public function down(PDO $db): void
    {
        // Migração sem suporte a drop column no SQLite tradicional
    }
}
