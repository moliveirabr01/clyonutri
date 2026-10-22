<?php
// Fixture do teste real da NI2-138 (canal seguro de mensagens). Dados fictícios.
// Só roda em banco isolado: SQLite em /tmp/ni2-138-* ou MySQL ni2_138_test_*.
// Base: fixture da NI2-91 (clínicas 1 e 2; Ana, Bruno e Carla; Nutri Um dona; Nutri Equipe nutricionista; recepção).
// Conversas da base: 31 (Bruno–Nutri Um, com anexo 51), 32 (Carla–Nutri Dois), 33 (Ana–Nutri Um).
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_138_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-138-')) {
    throw new RuntimeException('Isolated test database required');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$mode = $argv[1] ?? 'prepare';
$db = $mode === 'prepare' ? null : Connection::getInstance();

if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_91_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) {
        throw new RuntimeException('base fixture');
    }
    $db = Connection::getInstance();
    // Dados da base que não interessam aqui: consultas sem relação e a Ana sem conversa (começa do zero).
    $db->exec('DELETE FROM chat_messages WHERE conversation_id = 33');
    $db->exec('DELETE FROM chat_conversations WHERE id = 33');
    $db->exec("UPDATE clinics SET phone = '(11) 4000-0000' WHERE id = 1");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'link') {
    // link <patient_id> <professional_id> [clinic_id]
    TenantContext::set((int)($argv[4] ?? 1), ['id' => 1, 'role' => 'nutritionist']);
    echo json_encode((new \ClyoNutri\Domain\Chat\PatientLinkService($db))->activate((int)$argv[2], (int)$argv[3], 1, 'test'));
} elseif ($mode === 'end-link') {
    TenantContext::set((int)($argv[4] ?? 1), ['id' => 1, 'role' => 'nutritionist']);
    $l = (new \ClyoNutri\Domain\Chat\PatientLinkService($db))->activeLink((int)($argv[4] ?? 1), (int)$argv[2], (int)$argv[3]);
    echo json_encode((new \ClyoNutri\Domain\Chat\PatientLinkService($db))->end((int)$l['id'], 1, 'teste'));
} elseif ($mode === 'member-status') {
    // member-status <user_id> <clinic_id> <active|inactive>
    $db->prepare('UPDATE clinic_members SET status = ? WHERE user_id = ? AND clinic_id = ?')->execute([$argv[4], (int)$argv[2], (int)$argv[3]]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'conv-status') {
    $db->prepare('UPDATE chat_conversations SET status = ? WHERE id = ?')->execute([$argv[3], (int)$argv[2]]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'chat-flag') {
    $v = (string)$argv[2];
    $has = $db->prepare("SELECT 1 FROM platform_settings WHERE setting_key = 'feature_flag_chat'");
    $has->execute();
    $has->fetchColumn()
        ? $db->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'feature_flag_chat'")->execute([$v])
        : $db->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('feature_flag_chat', ?)")->execute([$v]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'chat-settings') {
    // chat-settings <response_time|''> <years>
    echo json_encode((new \ClyoNutri\Domain\Chat\ChatSettingsService($db))->update(1, $argv[2] !== '' ? $argv[2] : null, $argv[3], 1));
} elseif ($mode === 'prefs') {
    // prefs <user_type> <user_id> <email 0|1> <push 0|1>
    $db->prepare('DELETE FROM chat_preferences WHERE clinic_id = 1 AND user_type = ? AND user_id = ?')->execute([$argv[2], (int)$argv[3]]);
    $db->prepare('INSERT INTO chat_preferences (clinic_id, user_type, user_id, email_enabled, push_enabled, quiet_hours_enabled) VALUES (1, ?, ?, ?, ?, 0)')
        ->execute([$argv[2], (int)$argv[3], (int)$argv[4], (int)$argv[5]]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'age-chat') {
    // age-chat <conversation_id> <anos>: leva as mensagens, anexos e notificações da conversa para o passado.
    $past = (new DateTimeImmutable())->modify('-' . (int)$argv[3] . ' years')->format('Y-m-d H:i:s');
    foreach (['chat_messages' => 'created_at', 'chat_attachments' => 'created_at', 'chat_notifications_log' => 'sent_at'] as $t => $col) {
        $db->prepare("UPDATE {$t} SET {$col} = ? WHERE conversation_id = ?")->execute([$past, (int)$argv[2]]);
    }
    $db->prepare('UPDATE chat_conversations SET updated_at = ? WHERE id = ?')->execute([$past, (int)$argv[2]]);
    echo json_encode(['ok' => true, 'at' => $past]);
} elseif ($mode === 'retention') {
    $dir = getenv('CHAT_STORAGE_DIR') ?: null;
    echo json_encode((new \ClyoNutri\Domain\Chat\ChatRetentionService($db, $dir))->run(($argv[2] ?? '') === '--dry-run'));
} elseif ($mode === 'drop-conv') {
    foreach (['chat_notifications_log', 'chat_attachments', 'chat_messages'] as $tbl) {
        $db->prepare("DELETE FROM {$tbl} WHERE conversation_id = ?")->execute([(int)$argv[2]]);
    }
    $db->prepare('DELETE FROM chat_conversations WHERE id = ?')->execute([(int)$argv[2]]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) {
        throw new RuntimeException('Somente SELECT');
    }
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} else {
    throw new RuntimeException('Unknown fixture mode');
}
