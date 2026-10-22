<?php

declare(strict_types=1);

/**
 * NI2-138: política de guarda do chat (mensagens, anexos e registros de notificação).
 *   php bin/chat_retention.php --dry-run   (só conta o que seria apagado)
 *   php bin/chat_retention.php             (apaga o que passou do prazo de cada clínica)
 * O bin/cron_scheduler.php já roda isto uma vez por dia.
 */
require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    \ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$report = (new \ClyoNutri\Domain\Chat\ChatRetentionService())->run(in_array('--dry-run', $argv, true));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
