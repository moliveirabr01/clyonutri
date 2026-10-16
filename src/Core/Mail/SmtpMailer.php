<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envio via PHPMailer. Transporte "smtp": SMTP autenticado do cPanel/HostGator (465 + ssl ou
 * 587 + tls) ou provedores externos (Brevo, Amazon SES etc.). Transporte "mail": função mail()
 * do PHP (sendmail do servidor), pelo mesmo montador de MIME.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption, // 'ssl' | 'tls' | '' / 'none'
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly int $timeoutSeconds = 15,
        private readonly bool $verifyPeer = true,
        private readonly string $transport = 'smtp', // 'smtp' | 'mail'
    ) {
        if (!class_exists(PHPMailer::class)) {
            throw new MailException('PHPMailer não instalado: rode "composer install" e envie a pasta vendor/.');
        }
        if (($transport === 'smtp' && $host === '') || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new MailException('Configuração de e-mail incompleta (MAIL_HOST / MAIL_FROM_ADDRESS).');
        }
    }

    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);
        try {
            if ($this->transport === 'mail') {
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host = $this->host;
                $mail->Port = $this->port;
                $mail->Timeout = $this->timeoutSeconds;
                $mail->SMTPAuth = $this->username !== '';
                $mail->Username = $this->username;
                $mail->Password = $this->password;
                $mail->SMTPSecure = match (strtolower($this->encryption)) {
                    'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
                    'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                    default => '',
                };
                $mail->SMTPAutoTLS = $mail->SMTPSecure !== '';
                if (!$this->verifyPeer) {
                    // Só para servidores com certificado que não bate com o host (ex.: nome do servidor
                    // compartilhado). Prefira corrigir MAIL_HOST a desligar a verificação.
                    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                }
            }
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->Sender = $this->fromAddress;
            $mail->addAddress($message->toAddress, $message->toName);
            if ($message->replyTo !== null) {
                $mail->addReplyTo($message->replyTo);
            }
            $mail->isHTML(true);
            $mail->Subject = $message->subject;
            $mail->Body = $message->htmlBody;
            $mail->AltBody = $message->textBody;
            $mail->send();
        } catch (PHPMailerException $e) {
            // Não propaga credenciais; apenas a mensagem do transporte.
            throw new MailException('Falha no envio (' . $this->transport . '): ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
