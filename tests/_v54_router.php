<?php
// Roteador do php -S para o teste de navegador (só local, não vai no zip): /__teste_login abre a sessão TESTE.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// NI2-604: mesma pasta de sessão do index.php (storage/sessions)
$__sd = __DIR__ . '/../storage/sessions'; if (!is_dir($__sd)) { mkdir($__sd, 0700, true); } ini_set('session.save_path', $__sd);
if ($path === '/__teste_expirar' || $path === '/__teste_quase') {
    session_start();
    $ttl = 120 * 60;
    $_SESSION['last_activity'] = $path === '/__teste_expirar' ? time() - $ttl - 60 : time() - $ttl + 120;
    header('Content-Type: text/plain'); echo 'ok'; exit;
}
if ($path === '/__teste_login') {
    session_start();
    $_SESSION = []; // como logout + login real (AuthController::logout zera a sessão)
    $_SESSION['user_id'] = (int)$_GET['u']; $_SESSION['clinic_id'] = (int)$_GET['c']; $_SESSION['auth_version'] = (int)$_GET['v'];
    $_SESSION['last_activity'] = time();
    header('Location: ' . ($_GET['to'] ?? '/dashboard')); exit;
}
$file = __DIR__ . '/../public' . $path;
if ($path !== '/' && is_file($file)) { return false; }
chdir(__DIR__ . '/../public');
require __DIR__ . '/../public/index.php';
