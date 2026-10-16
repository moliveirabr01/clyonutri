<?php
/**
 * Diagnóstico do ambiente ClyoNutri — SOMENTE linha de comando (auditoria 14/10/2026, F04).
 * Substitui o antigo public/_diag_*.php. Não imprime senhas nem o conteúdo do .env.
 * Uso: php bin/diagnostico.php   (Terminal do cPanel, SSH ou "Cron Jobs" com saída por e-mail)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$ok = fn(bool $b) => $b ? 'OK  ' : 'FALHA';
echo "ClyoNutri - diagnóstico (CLI)\n\n";
echo $ok(version_compare(PHP_VERSION, '8.3.0', '>=')) . " PHP " . PHP_VERSION . " (mínimo 8.3)\n";
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'session', 'ctype', 'fileinfo'] as $ext) {
    echo $ok(extension_loaded($ext)) . " extensão $ext\n";
}
echo $ok(is_file("$root/vendor/autoload.php")) . " vendor/autoload.php\n";
echo $ok(is_file("$root/.env")) . " arquivo .env na raiz do projeto\n";
foreach (['storage', 'storage/logs', 'storage/logs/mail', 'storage/uploads', 'storage/app/chat_attachments', 'storage/app/helpdesk_attachments', 'storage/cache', 'storage/sessions'] as $d) {
    echo $ok(is_dir("$root/$d") && is_writable("$root/$d")) . " gravável: $d\n";
}
$sp = session_save_path() ?: sys_get_temp_dir();
echo $ok(is_writable($sp)) . " pasta de sessões do PHP gravável\n";
$env = [];
if (is_file("$root/.env")) {
    foreach (file("$root/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim(trim($v), '"\'');
    }
}
$g = fn(string $k) => $env[$k] ?? '';
echo "\nAPP_ENV=" . $g('APP_ENV') . "  APP_DEBUG=" . $g('APP_DEBUG') . "  APP_URL=" . $g('APP_URL') . "  DB_CONNECTION=" . $g('DB_CONNECTION') . "\n";
echo $ok($g('DB_CONNECTION') === 'mysql') . " DB_CONNECTION=mysql\n";
echo $ok($g('APP_DEBUG') === 'false') . " APP_DEBUG=false\n";
foreach (['DB_PASSWORD', 'MAIL_PASSWORD'] as $k) {
    echo $ok($g($k) !== '' && $g($k) !== '__PREENCHER__') . " $k preenchido\n";
}
try {
    $pdo = new PDO('mysql:host=' . ($g('DB_HOST') ?: 'localhost') . ';port=' . ($g('DB_PORT') ?: '3306') . ';dbname=' . $g('DB_DATABASE') . ';charset=utf8mb4',
        $g('DB_USERNAME'), $g('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    echo "OK   conexão com o banco (servidor $ver, $tables tabelas)\n";
    echo $ok($tables >= 70) . " banco importado (esperado ~77 tabelas)\n";
} catch (Throwable $e) {
    echo "FALHA conexão com o banco (código " . $e->getCode() . ") - confira DB_DATABASE, DB_USERNAME, DB_PASSWORD e se o usuário foi adicionado ao banco com todos os privilégios\n";
}
foreach (['MAIL_DRIVER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_FROM_ADDRESS'] as $k) {
    echo $ok($g($k) !== '') . " $k " . ($g($k) !== '' && $k !== 'MAIL_USERNAME' ? '(' . $g($k) . ')' : '') . "\n";
}
echo $ok(class_exists('PHPMailer\\PHPMailer\\PHPMailer') || is_dir("$root/vendor/phpmailer/phpmailer")) . " PHPMailer instalado\n";
$lt = isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'")->fetchColumn() : 0;
echo $ok($lt === 1) . " tabela login_attempts (migração 2026-10-14)\n";
if ($lt === 1) {
    $pk = (string)($pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'PRIMARY'")->fetchColumn() ?: '');
    echo $ok($pk === 'bucket_key') . " login_attempts com chave primária em bucket_key" . ($pk === 'bucket_key' ? '' : " (atual: " . ($pk ?: 'nenhuma') . "; rode migracao-2026-10-15-login-attempts-chave-primaria.sql)") . "\n";
}
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/release_check.php'), $rc);
