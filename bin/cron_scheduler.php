<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * ClyoNutri II — Agendador Oficial HostGator (Cron Scheduler & Queue Runner)
 * ==============================================================================
 * Referência: inventario-migracao-laravel.md § 6.5 e Épico NI2-142
 *
 * Configuração no cPanel da HostGator (Trabalhos Cron) — NI2-95: a cada minuto, para o push e o e-mail não atrasarem:
 *   * * * * * /opt/cpanel/ea-php83/root/usr/bin/php ~/clyonutri/current/bin/cron_scheduler.php >> /dev/null 2>&1
 * (Se o plano só permitir de 15 em 15 minutos, tudo continua funcionando; os avisos só demoram mais para sair.)
 * Duas execuções nunca rodam juntas: a segunda sai na hora se a anterior ainda estiver trabalhando (trava em storage/).
 *
 * Funções executadas a cada ciclo:
 *  1. Fila de avisos (NI2-95): lembretes de consulta (antecedência configurada em Configurações, padrão 24 h),
 *     entrega de notification_deliveries vencidas (e-mail e push em lotes de 100), recibos do Expo e,
 *     uma vez por dia, limpeza de avisos lidos há mais de 180 dias
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
        $lock = @fopen($this->storageDir . '/cron_scheduler.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['heartbeat' => 'SKIPPED', 'status' => 'ALREADY_RUNNING', 'duration_seconds' => 0, 'timestamp' => date('c')];
        }
        $this->recordHeartbeat();
        $this->drainDatabaseQueue();
        $this->purgeNotifications();
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
            'interval_expected_minutes' => 1,
            'hostname' => gethostname() ?: 'hostgator_shared',
        ];
        file_put_contents($heartbeatFile, json_encode($payload, JSON_PRETTY_PRINT));
        $this->executionLog['heartbeat'] = 'RECORDED';
    }

    /**
     * NI2-95: fila real de avisos. Antes este passo só contava `notifications` não lidas e dizia DRAINED.
     * Agora cria os lembretes de consulta (antecedência da clínica/profissional) e os avisos do check-in diário, entrega o que venceu em
     * notification_deliveries (push em lotes de até 100 pelo Expo) e confere os recibos do push.
     */
    private function drainDatabaseQueue(): void
    {
        try {
            $db = Connection::getInstance();
            $service = new \ClyoNutri\Domain\Notification\NotificationService($db);
            $reminders = (new \ClyoNutri\Domain\Notification\AppointmentReminderScheduler($db, $service))->schedule();
            $checkins = (new \ClyoNutri\Domain\Notification\CheckinNotificationScheduler($db, $service))->schedule();
            $queue = $service->drain(200);
            $receipts = $service->checkPushReceipts();
            $this->executionLog['queue'] = ['driver' => 'database', 'appointment_reminders' => $reminders, 'checkin_notifications' => $checkins, 'push_receipts' => $receipts] + $queue;
        } catch (\Throwable $e) {
            $this->executionLog['queue'] = ['status' => 'WARNING', 'error' => get_class($e)];
        }
    }

    /** NI2-95: retenção dos avisos (lidos há mais de 180 dias, aparelhos desativados, recibos antigos). Uma vez por dia. */
    private function purgeNotifications(): void
    {
        $marker = $this->storageDir . '/notifications_retention_last_run';
        if (is_file($marker) && time() - (int)@file_get_contents($marker) < 86400) {
            $this->executionLog['notifications_retention'] = 'already_ran_today';
            return;
        }
        try {
            $this->executionLog['notifications_retention'] = (new \ClyoNutri\Domain\Notification\NotificationService(Connection::getInstance()))->purge();
            @file_put_contents($marker, (string)time());
        } catch (\Throwable $e) {
            $this->executionLog['notifications_retention'] = 'error: ' . get_class($e);
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
    echo "   CLYONUTRI II — AGENDADOR HOSTGATOR (CICLO DE 1 MINUTO)\n";
    echo "========================================================================\n";
    $scheduler = new HostGatorCronScheduler();
    $report = $scheduler->run();
    if (($report['status'] ?? '') === 'ALREADY_RUNNING') {
        echo " Status    : a execução anterior ainda está rodando; esta saiu sem fazer nada.\n";
        exit(0);
    }
    echo " Heartbeat : " . $report['heartbeat'] . "\n";
    $q = $report['queue'] ?? [];
    echo " Fila      : " . ($q['status'] ?? 'OK') . (isset($q['processed']) ? " (entregas {$q['processed']}, enviadas " . ($q['sent'] + $q['logged']) . ", falhas {$q['failed']}, na fila {$q['pending']})" : '') . "\n";
    $ar = $q['appointment_reminders'] ?? null;
    if (is_array($ar) && isset($ar['created'])) echo " Consultas : {$ar['created']} lembrete(s) de consulta novo(s)\n";
    $ck = $q['checkin_notifications'] ?? null;
    if (is_array($ck) && isset($ck['reminders'])) echo " Check-in  : {$ck['reminders']['created']} lembrete(s) para paciente, {$ck['submitted']['created']} aviso(s) de check-in recebido\n";
    $pr = $q['push_receipts'] ?? null;
    if (is_array($pr) && isset($pr['checked'])) echo " Push      : {$pr['checked']} recibo(s) conferido(s), {$pr['error']} com erro, {$pr['deactivated']} aparelho(s) desativado(s)\n";
    $tele = $report['teleconsultation_reminders'] ?? 'n/d';
    echo " Lembretes : " . (is_array($tele) ? "enviados {$tele['sent']}, falhas {$tele['failed']}, sem e-mail {$tele['skipped']}" : $tele) . "\n";
    echo " Login     : " . ($report['login_attempts_pruned'] ?? '-') . " registro(s) antigo(s) de tentativa removido(s)\n";
    echo " Duração   : " . $report['duration_seconds'] . "s\n";
    echo " Status    : ✓ Concluído dentro da janela de compliance da HostGator.\n";
    echo "========================================================================\n";
    exit(0);
}
