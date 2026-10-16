<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/** Descarta as mensagens (MAIL_MAILER=null). */
final class NullMailer implements Mailer
{
    public function send(MailMessage $message): void
    {
    }
}
