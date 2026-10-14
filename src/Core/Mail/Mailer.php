<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

interface Mailer
{
    /**
     * Envia a mensagem. Lança MailException em caso de falha.
     */
    public function send(MailMessage $message): void;
}
