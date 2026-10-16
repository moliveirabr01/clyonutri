<?php

declare(strict_types=1);

/**
 * Migration 36 (era a 32 do WIP do Maicon; renumerada no merge v11): Endurecimento do Consultório Online (NI2-221 / NI2-235 / NI2-236 / NI2-244).
 *
 * Aditiva e idempotente: não remove colunas nem altera dados existentes.
 * - Janela de entrada e expiração do convite (instantes em UTC, sufixo _utc).
 * - Geração do convite para revogação em cancelamento/reagendamento.
 * - Nome da sala no provedor (a API Daily remove salas pelo nome, não pelo id).
 * - Encerramento de mídia separado da conclusão clínica da consulta.
 * - Registro de tentativas para limitação de taxa dos endpoints do paciente.
 * - Canal de sinalização do WebRTC P2P (offer/answer/ICE); apagado ao liberar a sala.
 */
class AddTeleconsultationWebrtcAndJoinWindow
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $columns = [
            'external_room_name' => 'VARCHAR(150) NULL',
            'provider_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'join_opens_at_utc' => 'DATETIME NULL',
            'join_closes_at_utc' => 'DATETIME NULL',
            'invite_generation' => 'INTEGER NOT NULL DEFAULT 1',
            'invite_issued_at_utc' => 'DATETIME NULL',
            'invite_revoked_at_utc' => 'DATETIME NULL',
            'media_ended_at_utc' => 'DATETIME NULL',
        ];

        $existing = $this->existingColumns($db, $driver);
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                $db->exec("ALTER TABLE teleconsultation_sessions ADD COLUMN {$name} {$definition}");
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS teleconsultation_access_attempts (
            id {$pk},
            bucket VARCHAR(40) NOT NULL,
            subject_hash VARCHAR(64) NOT NULL,
            attempted_at_utc DATETIME NOT NULL
        ){$engine};");

        $db->exec("CREATE TABLE IF NOT EXISTS teleconsultation_signals (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            room_name VARCHAR(150) NOT NULL,
            sender_role VARCHAR(20) NOT NULL,
            signal_type VARCHAR(20) NOT NULL,
            payload TEXT NOT NULL,
            created_at_utc DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES teleconsultation_sessions(id) ON DELETE CASCADE
        ){$engine};");

        try {
            $db->exec('CREATE INDEX idx_tele_signals_room ON teleconsultation_signals (room_name, id)');
        } catch (\Throwable $e) {
            // Índice já existe
        }

        try {
            $db->exec('CREATE INDEX idx_tele_attempts_lookup ON teleconsultation_access_attempts (bucket, subject_hash, attempted_at_utc)');
        } catch (\Throwable $e) {
            // Índice já existe
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS teleconsultation_signals');
        $db->exec('DROP TABLE IF EXISTS teleconsultation_access_attempts');
        // Colunas aditivas permanecem (DROP COLUMN destrutivo exige ensaio de rollback próprio).
    }

    private function existingColumns(PDO $db, string $driver): array
    {
        if ($driver === 'mysql') {
            $rows = $db->query("SHOW COLUMNS FROM teleconsultation_sessions")->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn(array $r) => (string)$r['Field'], $rows);
        }
        $rows = $db->query("PRAGMA table_info(teleconsultation_sessions)")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r) => (string)$r['name'], $rows);
    }
}
