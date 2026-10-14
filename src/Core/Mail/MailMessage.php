<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

use InvalidArgumentException;

/**
 * Mensagem de e-mail (texto + HTML opcional), montada em MIME UTF-8 conforme RFC 5322/2045.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $toName = null,
        public readonly ?string $replyTo = null,
    ) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject . (string)$toName . (string)$replyTo)) {
            throw new InvalidArgumentException('Destinatário ou cabeçalho de e-mail inválido.');
        }
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Endereço de resposta inválido.');
        }
    }

    /**
     * Cabeçalhos + corpo MIME prontos para SMTP (linhas terminadas em CRLF).
     *
     * @return array{headers: array<string,string>, body: string}
     */
    public function build(string $fromAddress, string $fromName, string $messageIdDomain): array
    {
        $headers = [
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $messageIdDomain . '>',
            'From' => self::address($fromAddress, $fromName),
            'To' => self::address($this->to, $this->toName),
            'Subject' => self::encodeHeader($this->subject),
            'MIME-Version' => '1.0',
            'Auto-Submitted' => 'auto-generated',
        ];
        if ($this->replyTo !== null) {
            $headers['Reply-To'] = $this->replyTo;
        }

        if ($this->html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'base64';
            return ['headers' => $headers, 'body' => self::base64Body($this->text)];
        }

        $boundary = 'cn_' . bin2hex(random_bytes(12));
        $headers['Content-Type'] = "multipart/alternative; boundary=\"{$boundary}\"";
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . self::base64Body($this->text)
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . self::base64Body($this->html)
            . "--{$boundary}--\r\n";
        return ['headers' => $headers, 'body' => $body];
    }

    public static function address(string $email, ?string $name): string
    {
        return ($name === null || $name === '') ? $email : self::encodeHeader($name) . " <{$email}>";
    }

    public static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private static function base64Body(string $content): string
    {
        return rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";
    }
}
