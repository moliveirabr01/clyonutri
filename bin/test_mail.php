<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Teste de envio de e-mail (SMTP/mail) com as configurações do .env.
 *
 * Uso (Terminal do cPanel):  php bin/test_mail.php voce@exemplo.com
 * Mostra a configuração (sem senha), envia uma mensagem de teste e explica o erro, se houver.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Mail\MailMessage;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');
date_default_timezone_set((string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo'));

$to = trim((string)($argv[1] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/test_mail.php <destinatario@exemplo.com>\n");
    exit(1);
}

$info = MailerFactory::describe();
echo "Configuração de e-mail:\n";
foreach (['driver', 'host', 'port', 'encryption', 'from'] as $k) {
    printf("  %-11s %s\n", $k, $info[$k] === null || $info[$k] === '' ? '(não definido)' : (string)$info[$k]);
}
if ($info['problems']) {
    echo "\n✗ Corrija no .env antes de testar:\n  - " . implode("\n  - ", $info['problems']) . "\n";
    exit(1);
}
if (!$info['delivers']) {
    echo "\n⚠ MAIL_DRIVER={$info['driver']}: a mensagem vai para o log, não para a caixa de e-mail.\n";
}

try {
    $started = microtime(true);
    MailerFactory::fromEnv()->send(new MailMessage(
        $to,
        '',
        'Teste de e-mail — ClyoNutri',
        '<p>Este é um teste de envio do <strong>ClyoNutri</strong>.</p><p>Se você recebeu, a recuperação de senha por e-mail está pronta para funcionar.</p>',
        "Este é um teste de envio do ClyoNutri.\nSe você recebeu, a recuperação de senha por e-mail está pronta para funcionar.\n"
    ));
    printf("\n✓ Mensagem entregue ao servidor em %.1f s. Confira a caixa de entrada (e o spam) de %s.\n", microtime(true) - $started, $to);
} catch (Throwable $e) {
    echo "\n✗ Falha no envio: " . $e->getMessage() . "\n";
    echo "  Dicas: no cPanel, em E-mail → Contas → Connect Devices, use o \"Outgoing Server\" exibido\n";
    echo "  (ex.: mail.clyonutri.com ou o nome do servidor), porta 465 com MAIL_ENCRYPTION=ssl,\n";
    echo "  usuário = e-mail completo e a senha dessa caixa.\n";
    exit(1);
}
