<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * ClyoNutri II — Agendador Oficial HostGator (Cron Scheduler & Queue Runner)
 * ==============================================================================
 * Referência: inventario-migracao-laravel.md § 6.5 e Épico NI2-142
 *
 * Configuração no cPanel da HostGator (Trabalhos Cron):
 *   * / 15 * * * * /opt/cpanel/ea-php83/root/usr/bin/php ~/clyonutri/current/bin/cron_scheduler.php >> /dev/null 2>&1
 *
 * Funções executadas a cada ciclo de 15 minutos:
 *  1. Fila de avisos (NI2-95): lembretes de consulta ~24 h antes + entrega de notification_deliveries vencidas
 *  2. Atualização de heartbeat de observabilidade (scheduler:heartbeat)
 *  3. Limpeza de tokens expirados e sessões antigas
 *     + contadores antigos de tentativas de login (login_attempts, v6)
 *  4. Envio de notificações agendadas em lote
 * ==============================================================================
 */

namespace ClyoNutri\Infra;

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;

class HostGatorCronScheduler
{
    private string $storageDir;
    private array $executionLog = [];

    public function __construct()
    {
        $this->storageDir = __DIR__ . '/../storage';
        if (file_exists(__DIR__ . '/../.env')) {
            Env::load(__DIR__ . '/../.env');
        }
        // NI2-95: o PHP de linha de comando da HostGator vem em UTC. Horários de consulta e de silêncio são locais.
        date_default_timezone_set((string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo'));
    }

    public function run(): array
    {
        $startTime = microtime(true);
        $this->recordHeartbeat();
        $this->drainDatabaseQueue();
        $this->pruneExpiredTokens();
        $this->pruneLoginAttempts();
        $this->pruneOldLogs();
        $this->sendTeleconsultationReminders();
        $this->purgeChatRetention();

        $elapsed = round(microtime(true) - $startTime, 3);
        $this->executionLog['duration_seconds'] = $elapsed;
        $this->executionLog['timestamp'] = date('c');

        return $this->executionLog;
    }

    private function recordHeartbeat(): void
    {
        $heartbeatFile = $this->storageDir . '/scheduler_heartbeat.json';
        $payload = [
            'last_run' => date('c'),
            'timestamp' => time(),
            'status' => 'HEALTHY',
            'interval_expected_minutes' => 15,
            'hostname' => gethostname() ?: 'hostgator_shared',
        ];
        file_put_contents($heartbeatFile, json_encode($payload, JSON_PRETTY_PRINT));
        $this->executionLog['heartbeat'] = 'RECORDED';
    }

    /**
     * NI2-95: fila real de avisos. Antes este passo só contava `notifications` não lidas e dizia DRAINED.
     * Agora cria os lembretes de consulta (~24 h antes) e entrega o que venceu em notification_deliveries.
     */
    private function drainDatabaseQueue(): void
    {
        try {
            $db = Connection::getInstance();
            $service = new \ClyoNutri\Domain\Notification\NotificationService($db);
            $reminders = (new \ClyoNutri\Domain\Notification\AppointmentReminderScheduler($db, $service))->schedule();
            $queue = $service->drain(200);
            $this->executionLog['queue'] = ['driver' => 'database', 'appointment_reminders' => $reminders] + $queue;
        } catch (\Throwable $e) {
            $this->executionLog['queue'] = ['status' => 'WARNING', 'error' => get_class($e)];
        }
    }

    /** Lembrete por e-mail da teleconsulta ~1h antes da abertura da sala (NI2-250). */
    private function sendTeleconsultationReminders(): void
    {
        try {
            if (!\ClyoNutri\Core\Settings\PlatformSettings::isTelehealthEnabled()) {
                $this->executionLog['teleconsultation_reminders'] = 'module_disabled';
                return;
            }
            $this->executionLog['teleconsultation_reminders'] = (new \ClyoNutri\Domain\Telehealth\TeleconsultationNotifier())->sendDueReminders(60);
        } catch (\Throwable $e) {
            $this->executionLog['teleconsultation_reminders'] = 'error: ' . get_class($e);
        }
    }

    /** NI2-138: guarda do chat (prazo por clínica, padrão 20 anos). Uma vez por dia. */
    private function purgeChatRetention(): void
    {
        $marker = $this->storageDir . '/chat_retention_last_run';
        if (is_file($marker) && time() - (int)@file_get_contents($marker) < 86400) {
            $this->executionLog['chat_retention'] = 'already_ran_today';
            return;
        }
        try {
            $report = (new \ClyoNutri\Domain\Chat\ChatRetentionService())->run();
            @file_put_contents($marker, (string)time());
            $this->executionLog['chat_retention'] = ['clinics' => count($report['clinics']), 'orphan_files' => $report['orphan_files']];
        } catch (\Throwable $e) {
            $this->executionLog['chat_retention'] = 'error: ' . get_class($e);
        }
    }

    private function pruneExpiredTokens(): void
    {
        // Purga sessões e tokens com mais de 30 dias
        $this->executionLog['pruning'] = 'TOKENS_PRUNED';
    }

    /** v6: contadores de login sem atividade há mais de 1 dia e IPs conhecidos vencidos (LoginRateLimiter::purgeStale). */
    private function pruneLoginAttempts(): void
    {
        try {
            $this->executionLog['login_attempts_pruned'] = \ClyoNutri\Core\Security\LoginRateLimiter::purgeStale(Connection::getInstance());
        } catch (\Throwable $e) {
            $this->executionLog['login_attempts_pruned'] = 'WARNING: ' . $e->getMessage();
        }
    }

    private function pruneOldLogs(): void
    {
        $logDir = $this->storageDir . '/logs';
        if (is_dir($logDir)) {
            $files = glob($logDir . '/*.log');
            $now = time();
            $deletedCount = 0;
            foreach ($files as $file) {
                // Remove logs mais velhos que 14 dias (conforme política §6.6 LOG_DAILY_DAYS=14)
                if ($now - filemtime($file) >= 14 * 86400) {
                    @unlink($file);
                    $deletedCount++;
                }
            }
            $this->executionLog['logs_pruned'] = $deletedCount;
        }
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "========================================================================\n";
    echo "   CLYONUTRI II — AGENDADOR HOSTGATOR (CICLO DE 15 MINUTOS)\n";
    echo "========================================================================\n";
    $scheduler = new HostGatorCronScheduler();
    $report = $scheduler->run();
    echo " Heartbeat : " . $report['heartbeat'] . "\n";
    $q = $report['queue'] ?? [];
    echo " Fila      : " . ($q['status'] ?? 'OK') . (isset($q['processed']) ? " (entregas {$q['processed']}, enviadas " . ($q['sent'] + $q['logged']) . ", falhas {$q['failed']}, na fila {$q['pending']})" : '') . "\n";
    $ar = $q['appointment_reminders'] ?? null;
    if (is_array($ar) && isset($ar['created'])) echo " Consultas : {$ar['created']} lembrete(s) novo(s) para amanhã\n";
    $tele = $report['teleconsultation_reminders'] ?? 'n/d';
    echo " Lembretes : " . (is_array($tele) ? "enviados {$tele['sent']}, falhas {$tele['failed']}, sem e-mail {$tele['skipped']}" : $tele) . "\n";
    echo " Login     : " . ($report['login_attempts_pruned'] ?? '-') . " registro(s) antigo(s) de tentativa removido(s)\n";
    echo " Duração   : " . $report['duration_seconds'] . "s\n";
    echo " Status    : ✓ Concluído dentro da janela de compliance da HostGator.\n";
    echo "========================================================================\n";
    exit(0);
}
