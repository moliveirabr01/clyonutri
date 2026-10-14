<?php

declare(strict_types=1);

/**
 * Migration 31: Criação da tabela de configurações globais da plataforma (NI2-407 / AUD-05)
 */
class CreatePlatformSettingsTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS platform_settings (
            id {$pk},
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            description VARCHAR(255) NULL,
            updated_by_user_id INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // Inserir configurações padrão iniciais se não existirem
        $stmtCheck = $db->query("SELECT COUNT(*) FROM platform_settings");
        if ($stmtCheck && (int)$stmtCheck->fetchColumn() === 0) {
            $stmtInsert = $db->prepare("INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
            $stmtInsert->execute(['maintenance_mode', '0', 'Modo de manutenção da plataforma (1 para ativo, 0 para inativo)']);
            $stmtInsert->execute(['support_sla_p1_hours', '4', 'SLA de primeira resposta para chamados P1 (em horas)']);
            $stmtInsert->execute(['support_sla_p2_hours', '24', 'SLA de primeira resposta para chamados P2 (em horas)']);
            $stmtInsert->execute(['session_timeout_minutes', '120', 'Tempo limite de inatividade da sessão de usuários']);
            $stmtInsert->execute(['security_max_login_attempts', '5', 'Máximo de tentativas de login com senha/OTP antes do bloqueio']);
            $stmtInsert->execute(['feature_flag_telehealth', '1', 'Habilita salas seguras WebRTC e links de consulta remota (NI2-221)']);
            $stmtInsert->execute(['feature_flag_chat', '1', 'Habilita canal de comunicação seguro e mensageria em tempo real (NI2-323 / NI2-281)']);
            $stmtInsert->execute(['feature_flag_audit_immutable', '1', 'Força registro compulsório em trilha imutável WAL de todas as mutações e acessos (NI2-358)']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS platform_settings;");
    }
}
