<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/**
 * Driver de desenvolvimento (MAIL_MAILER=log): grava a mensagem em storage/logs/mail.log.
 * Nunca escreve em stdout/stderr. Não use em produção: o arquivo contém os links enviados.
 */
final class LogMailer implements Mailer
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 3) . '/storage/logs/mail.log';
    }

    public function send(MailMessage $message): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MailException('Não foi possível criar o diretório de log de e-mail.');
        }
        $entry = sprintf(
            "==== %s UTC ====\nTo: %s <%s>\nSubject: %s\n\n%s\n\n",
            gmdate('Y-m-d H:i:s'),
            $message->toName,
            $message->toAddress,
            $message->subject,
            $message->textBody
        );
        if (@file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailException('Não foi possível gravar o log de e-mail.');
        }
        @chmod($this->path, 0600);
    }
}
