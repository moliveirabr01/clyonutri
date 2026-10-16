<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/**
 * Mensagem de e-mail imutável (HTML + alternativa em texto puro), com responder-para opcional.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $toAddress,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly ?string $replyTo = null,
    ) {
        if (!filter_var($toAddress, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Destinatário de e-mail inválido.');
        }
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Endereço de resposta (reply-to) inválido.');
        }
        if (preg_match('/[\r\n]/', $subject . $toName)) {
            throw new \InvalidArgumentException('Cabeçalhos de e-mail não podem conter quebras de linha.');
        }
    }
}
