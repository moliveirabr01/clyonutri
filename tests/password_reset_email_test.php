<?php

declare(strict_types=1);

/**
 * Recuperação de senha por e-mail (envio real via Mailer, token com hash, expiração em UTC).
 * Executar: php -d zend.assertions=1 -d assert.exception=1 tests/password_reset_email_test.php
 * Usa o banco isolado de testes (Connection: storage/testing.sqlite) e um mailer em memória.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\ArrayMailer;
use ClyoNutri\Core\Mail\LogMailer;
use ClyoNutri\Core\Mail\MailException;
use ClyoNutri\Core\Mail\MailMessage;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\NullMailer;
use ClyoNutri\Core\Mail\SmtpMailer;
use ClyoNutri\Core\Mail\PasswordResetEmail;

if ((int)ini_get('zend.assertions') !== 1) {
    fwrite(STDERR, "Execute com: php -d zend.assertions=1 -d assert.exception=1 {$argv[0]}\n");
    exit(2);
}

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite') {
    throw new RuntimeException('Use o runner com banco SQLite isolado em ambiente de teste.');
}
$db = Connection::getInstance();
$passed = 0;
function ok(bool $cond, string $msg): void
{
    global $passed;
    assert($cond, $msg);
    $passed++;
    echo "  [PASS] {$msg}\n";
}

$appUrl = 'https://staging.clyonutri.test';
$active = 'reset.email.ativo@clyonutri.test';
$inactive = 'reset.email.inativo@clyonutri.test';
$unknown = 'reset.email.inexistente@clyonutri.test';
$emails = [$active, $inactive, $unknown];

$cleanup = static function () use ($db, $emails): void {
    foreach ($emails as $e) {
        $db->prepare('DELETE FROM password_resets WHERE LOWER(email) = ?')->execute([$e]);
        $db->prepare('DELETE FROM audit_logs WHERE details LIKE ?')->execute(['%' . $e . '%']);
        $db->prepare('DELETE FROM users WHERE email = ?')->execute([$e]);
    }
};

$tokenFromMail = static function (MailMessage $m) use ($appUrl): ?string {
    return preg_match('#^' . preg_quote($appUrl, '#') . '/recuperar-senha/([0-9a-f]{64})$#m', $m->textBody, $mm) ? $mm[1] : null;
};

$cleanup();
try {
    $auth = new AuthService($db);
    $auth->registerNutritionist(name: 'Ana Reset Ativa', email: $active, password: 'SenhaInicial@2026', clinicName: 'Clínica Reset A', crn: 'CRN3-111111', crnRegion: 'CRN-3');
    $auth->registerNutritionist(name: 'Bia Reset Inativa', email: $inactive, password: 'SenhaInicial@2026', clinicName: 'Clínica Reset B', crn: 'CRN3-222222', crnRegion: 'CRN-3');
    $db->prepare("UPDATE users SET status = 'active' WHERE email = ?")->execute([$active]);
    $db->prepare("UPDATE users SET status = 'inactive' WHERE email = ?")->execute([$inactive]);

    // Host header malicioso: deve ser ignorado (link sai sempre de APP_URL)
    $_SERVER['HTTP_HOST'] = 'evil.example.com';
    $_SERVER['SERVER_NAME'] = 'evil.example.com';
    putenv('APP_URL=' . $appUrl . '/');
    $_ENV['APP_URL'] = $appUrl . '/';

    echo "--- Envio para usuário ativo ---\n";
    $mailer = new ArrayMailer();
    $svc = new PasswordResetService($db, $mailer); // APP_URL vem do ambiente
    $res = $svc->requestReset(strtoupper($active), '10.9.0.1', 'test');
    ok(count($mailer->sent) === 1, 'um e-mail enviado para usuário ativo');
    $msg = $mailer->sent[0];
    ok($msg->toAddress === $active, 'destinatário é o e-mail do usuário');
    echo '  Assunto efetivo: ' . $msg->subject . "\n";
    ok($msg->subject === 'Crie uma nova senha no Clyo Nutri', 'assunto atual em pt-BR, claro e sem dados do titular');
    ok(str_contains($msg->htmlBody, '<title>' . htmlspecialchars($msg->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</title>'), 'título HTML corresponde ao assunto enviado');
    $token = $tokenFromMail($msg);
    ok($token !== null, 'texto contém link APP_URL/recuperar-senha/{token} (sem barra dupla)');
    ok(str_contains($msg->htmlBody, htmlspecialchars($appUrl . '/recuperar-senha/' . $token, ENT_QUOTES)), 'HTML contém o mesmo link');
    ok(!str_contains($msg->htmlBody . $msg->textBody, 'evil.example.com'), 'Host header ignorado');
    ok(str_contains($msg->textBody, '30 minutos') && str_contains($msg->htmlBody, '30 minutos'), 'validade de 30 minutos informada');
    ok(str_contains($msg->textBody, 'ignore este e-mail'), 'nota "se não foi você, ignore"');
    ok(str_contains($msg->htmlBody, '#0F3E17') && str_contains($msg->htmlBody, '#39734A'), 'cores do Design System (Forest/Clyo Green)');
    ok(!array_key_exists('token', $res) && !str_contains(json_encode($res), $token), 'resposta do serviço não contém o token');
    ok($res['email_sent'] === true, 'email_sent=true');
    $generic = $res['message'];

    echo "--- Armazenamento com hash ---\n";
    $row = $db->query("SELECT token, expires_at FROM password_resets WHERE email = " . $db->quote($active))->fetch(PDO::FETCH_ASSOC);
    ok($row['token'] === hash('sha256', $token), 'password_resets.token = SHA-256 do token');
    $raw = (int)$db->query('SELECT COUNT(*) FROM password_resets WHERE token = ' . $db->quote($token))->fetchColumn();
    ok($raw === 0, 'token em claro não existe no banco');
    $auditDump = (string)$db->query("SELECT GROUP_CONCAT(details) FROM audit_logs WHERE details LIKE " . $db->quote('%' . $active . '%'))->fetchColumn();
    ok(!str_contains($auditDump, substr($token, 0, 8)), 'auditoria não guarda prefixo do token');
    ok(str_contains($auditDump, 'expires_at_utc'), 'auditoria registra expiração UTC');

    echo "--- Expiração em UTC ---\n";
    $diff = strtotime($row['expires_at'] . ' UTC') - time();
    ok($diff > 1790 && $diff <= 1800, "expires_at em UTC = agora + 30 min (diff={$diff}s)");
    // Mesmo com o PHP em outro fuso, a validação continua correta
    $prevTz = date_default_timezone_get();
    date_default_timezone_set('Asia/Tokyo');
    ok($svc->validateToken($token) !== null, 'token válido com PHP em Asia/Tokyo');
    date_default_timezone_set('America/Sao_Paulo');
    ok($svc->validateToken($token) !== null, 'token válido com PHP em America/Sao_Paulo');
    date_default_timezone_set($prevTz);
    ok($svc->getActiveTokenForEmail($active) === hash('sha256', $token), 'getActiveTokenForEmail devolve o hash ativo');
    ok($svc->validateToken(hash('sha256', $token)) === null, 'o próprio hash não funciona como token');
    ok($svc->validateToken(strtoupper($token)) !== null, 'token aceita hex maiúsculo (normalizado)');

    echo "--- Token válido, reutilizado e expirado ---\n";
    $out = $svc->resetPassword($token, 'NovaSenha@Forte2026');
    ok($out['success'] === true, 'token válido redefine a senha');
    $hash = (string)$db->query('SELECT password_hash FROM users WHERE email = ' . $db->quote($active))->fetchColumn();
    ok(password_verify('NovaSenha@Forte2026', $hash), 'nova senha gravada');
    $reused = false;
    try { $svc->resetPassword($token, 'OutraSenha@2026'); } catch (InvalidArgumentException) { $reused = true; }
    ok($reused, 'token reutilizado é rejeitado');

    $svc->requestReset($active, '10.9.0.2', 'test');
    $token2 = $tokenFromMail(end($mailer->sent));
    $db->prepare('UPDATE password_resets SET expires_at = ? WHERE token = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 1), hash('sha256', $token2)]);
    ok($svc->validateToken($token2) === null, 'token expirado (1s no passado, UTC) é rejeitado');
    ok((int)$db->query('SELECT COUNT(*) FROM password_resets WHERE email = ' . $db->quote($active))->fetchColumn() === 0, 'token expirado é removido');

    $svc->requestReset($active, '10.9.0.3', 'test');
    $token3 = $tokenFromMail(end($mailer->sent));
    // Ainda válido por 2 minutos: o bug antigo (local x UTC) o trataria como expirado ou 3h mais longo
    $db->prepare('UPDATE password_resets SET expires_at = ? WHERE token = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() + 120), hash('sha256', $token3)]);
    ok($svc->validateToken($token3) !== null, 'token com 2 min restantes (UTC) continua válido');
    $db->prepare("INSERT INTO password_resets (email, token, created_at, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([$unknown, hash('sha256', 'x'), gmdate('Y-m-d H:i:s', time() - 4000), gmdate('Y-m-d H:i:s', time() - 60)]);
    $removed = $svc->cleanExpiredTokens();
    ok($removed >= 1 && $svc->validateToken($token3) !== null, 'cleanExpiredTokens remove só expirados (UTC)');

    echo "--- Sem e-mail para desconhecido/inativo ---\n";
    $before = count($mailer->sent);
    $r1 = $svc->requestReset($unknown, '10.9.0.4', 'test');
    $r2 = $svc->requestReset($inactive, '10.9.0.4', 'test');
    ok(count($mailer->sent) === $before, 'nenhum e-mail para e-mail inexistente ou conta inativa');
    ok($r1['message'] === $generic && $r2['message'] === $generic, 'mesma mensagem genérica');
    ok((int)$db->query('SELECT COUNT(*) FROM password_resets WHERE email = ' . $db->quote($inactive))->fetchColumn() === 0, 'nenhum token para conta inativa');

    echo "--- Falha do mailer ---\n";
    $failing = new ArrayMailer();
    $failing->fail = true;
    $svcFail = new PasswordResetService($db, $failing, $appUrl);
    $prevLog = ini_set('error_log', dirname((string)getenv('DB_DATABASE')) . '/clyonutri_reset_test_error.log');
    $rf = $svcFail->requestReset($active, '10.9.0.5', 'test');
    ini_set('error_log', (string)$prevLog);
    ok($rf['success'] === true && $rf['message'] === $generic, 'falha de envio mantém resposta genérica');
    ok(!array_key_exists('token', $rf), 'falha de envio não devolve token');
    $failAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'PASSWORD_RESET_EMAIL_FAILED' AND details LIKE " . $db->quote('%' . $active . '%'))->fetchColumn();
    ok($failAudit === 1, 'falha registrada na auditoria (PASSWORD_RESET_EMAIL_FAILED)');

    echo "--- APP_URL ausente não cai no Host ---\n";
    putenv('APP_URL');
    unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
    $m2 = new ArrayMailer();
    $prevLog = ini_set('error_log', dirname((string)getenv('DB_DATABASE')) . '/clyonutri_reset_test_error.log');
    $rn = (new PasswordResetService($db, $m2))->requestReset($active, '10.9.0.6', 'test');
    ini_set('error_log', (string)$prevLog);
    ok(count($m2->sent) === 0 && $rn['message'] === $generic, 'sem APP_URL: não envia link com Host e mantém resposta genérica');

    echo "--- Controller e driver de log ---\n";
    $ctrl = (string)file_get_contents(__DIR__ . '/../src/Application/Controllers/AuthController.php');
    ok(!preg_match("/\\\$res\\['token'\\]/", $ctrl), 'controller não usa token da resposta');
    $logFile = dirname((string)getenv('DB_DATABASE')) . '/clyonutri_mail_test_' . bin2hex(random_bytes(4)) . '.log';
    ob_start();
    (new LogMailer($logFile))->send(PasswordResetEmail::build($active, 'Ana', $appUrl . '/recuperar-senha/abc', 30));
    $stdout = ob_get_clean();
    ok($stdout === '' && str_contains((string)file_get_contents($logFile), '/recuperar-senha/abc'), 'driver log grava em arquivo e não em stdout');
    @unlink($logFile);
    $injected = false;
    try { new MailMessage($active, "Ana\r\nBcc: x@y.z", 'Assunto', '', ''); } catch (InvalidArgumentException) { $injected = true; }
    ok($injected, 'quebra de linha em cabeçalho é rejeitada');

    // NI2-901: cenário independente após a janela do limite expirar. A sequência anterior
    // esgotou legitimamente as cinco tentativas; não desligar o limitador no produto.
    $limitedMailer = new ArrayMailer();
    $limited = (new PasswordResetService($db, $limitedMailer, $appUrl))->requestReset($active, '10.9.0.8', 'test');
    ok($limited['email_sent'] === false && $limitedMailer->sent === [] && $limited['message'] === $generic, 'limite real bloqueia novos envios com resposta genérica');
    $db->prepare("UPDATE login_attempts SET window_started_at=? WHERE scope='password_reset' AND bucket_key=?")
        ->execute([gmdate('Y-m-d H:i:s', time()-901), hash('sha256', 'password_reset|email|' . $active)]);
    echo "--- Envio agendado para depois da resposta (timing) ---\n";
    putenv('APP_URL=' . $appUrl);
    $db->prepare('DELETE FROM audit_logs WHERE details LIKE ?')->execute(['%' . $active . '%']);
    $md = new ArrayMailer();
    $svcD = new PasswordResetService($db, $md);
    $jobs = [];
    $svcD->deferEmailDelivery(static function (\Closure $job) use (&$jobs): void { $jobs[] = $job; });
    $rd = $svcD->requestReset($active, '10.9.0.7', 'test');
    $svcD->deferEmailDelivery(null);
    $svcD->requestReset($unknown, '10.9.0.7', 'test');
    ok(count($jobs) === 1 && count($md->sent) === 0, 'envio agendado: nada sai antes da resposta; conta inexistente não agenda nada');
    ok(!array_key_exists('token', $rd) && $rd['email_sent'] === null && $rd['message'] === $generic, 'resposta agendada continua genérica e sem token');
    $jobs[0]();
    $tokD = isset($md->sent[0]) ? $tokenFromMail($md->sent[0]) : null;
    ok($tokD !== null && $svcD->validateToken($tokD) !== null, 'job agendado envia o link válido');

    echo "--- Responder-para e configuração (MAIL_DRIVER / MAIL_MAILER) ---\n";
    putenv('APP_SUPPORT_EMAIL=suporte@clyonutri.test');
    $mr = PasswordResetEmail::build($active, 'Ana', $appUrl . '/recuperar-senha/abc', 30, 'suporte@clyonutri.test');
    ok($mr->replyTo === 'suporte@clyonutri.test' && str_contains($mr->htmlBody, 'suporte@clyonutri.test'), 'APP_SUPPORT_EMAIL vira o responder-para');
    putenv('APP_SUPPORT_EMAIL');
    $envKeys = ['APP_ENV', 'MAIL_DRIVER', 'MAIL_MAILER', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_ENCRYPTION', 'MAIL_PORT', 'MAIL_LOG_PATH'];
    $saved = [];
    foreach ($envKeys as $k) { $saved[$k] = getenv($k); putenv($k); unset($_ENV[$k], $_SERVER[$k]); }
    try {
        putenv('APP_ENV=production');
        $threw = false;
        try { MailerFactory::fromEnv(); } catch (MailException) { $threw = true; }
        ok($threw && MailerFactory::describe()['problems'] !== [], 'produção sem MAIL_DRIVER falha explicitamente (não finge envio)');
        putenv('MAIL_MAILER=null');
        ok(MailerFactory::fromEnv() instanceof NullMailer, 'MAIL_MAILER é aceito como sinônimo');
        putenv('MAIL_DRIVER=log');
        putenv('MAIL_LOG_PATH=' . sys_get_temp_dir() . '/clyonutri_factory.log');
        ok(MailerFactory::fromEnv() instanceof LogMailer, 'MAIL_DRIVER tem precedência sobre MAIL_MAILER');
        foreach (['MAIL_DRIVER=smtp', 'MAIL_HOST=mail.clyonutri.test', 'MAIL_USERNAME=nao-responda@clyonutri.test', 'MAIL_PASSWORD=x', 'MAIL_ENCRYPTION=ssl'] as $kv) { putenv($kv); }
        $d = MailerFactory::describe();
        ok(MailerFactory::fromEnv() instanceof SmtpMailer && $d['port'] === 465 && $d['from'] === 'nao-responda@clyonutri.test' && $d['problems'] === [] && !in_array('x', $d, true), 'smtp: porta 465 por padrão com ssl, remetente = usuário, describe sem senha');
    } finally {
        foreach ($saved as $k => $v) { $v === false ? putenv($k) : putenv("{$k}={$v}"); }
    }

    echo "\nOK: {$passed} asserções passaram.\n";
} finally {
    $cleanup();
}
