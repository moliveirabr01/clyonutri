<?php

declare(strict_types=1);

class CreateTenantsAndUsersTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Tenants / Clínicas
        $db->exec("CREATE TABLE IF NOT EXISTS clinics (
            id {$pk},
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            document VARCHAR(30) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // Usuários
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id {$pk},
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'nutritionist',
            is_verified INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // Vínculo Multi-tenant (Membros da Clínica)
        $db->exec("CREATE TABLE IF NOT EXISTS clinic_members (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'nutritionist',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE (clinic_id, user_id)
        ){$engine};");

        // Perfil Profissional (Nutricionista)
        $db->exec("CREATE TABLE IF NOT EXISTS professionals (
            id {$pk},
            user_id INTEGER NOT NULL UNIQUE,
            crn VARCHAR(20) NOT NULL,
            crn_region VARCHAR(10) NOT NULL,
            specialty VARCHAR(100) NULL,
            bio TEXT NULL,
            signature_path VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // Sessões Seguras
        $db->exec("CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) PRIMARY KEY,
            user_id INTEGER NOT NULL,
            clinic_id INTEGER NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            payload TEXT NOT NULL,
            last_activity INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // Password Reset Tokens
        $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
            email VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (email, token)
        ){$engine};");

        // Trilha de Auditoria (Audit Logs)
        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id {$pk},
            clinic_id INTEGER NULL,
            user_id INTEGER NULL,
            action VARCHAR(100) NOT NULL,
            resource VARCHAR(100) NOT NULL,
            resource_id VARCHAR(50) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            details TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");
    }
}
