<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/**
 * Mailer em memória para testes: guarda as mensagens e pode simular falha.
 */
final class ArrayMailer implements Mailer
{
    /** @var MailMessage[] */
    public array $sent = [];
    public bool $fail = false;

    public function send(MailMessage $message): void
    {
        if ($this->fail) {
            throw new MailException('Falha simulada de envio.');
        }
        $this->sent[] = $message;
    }
}
