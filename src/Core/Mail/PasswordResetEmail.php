<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/**
 * E-mail de redefinição de senha (pt-BR), Design System (Clinical Botanical):
 * Forest #0F3E17 / Clyo Green #39734A / cobre #B04A1B (Clinical Botanical). Layout em tabelas + estilos inline para clientes de e-mail.
 */
final class PasswordResetEmail
{
    public static function build(string $toAddress, string $toName, string $resetUrl, int $validityMinutes, ?string $replyTo = null): MailMessage
    {
        $name = trim($toName) !== '' ? trim($toName) : 'Olá';
        $firstName = \ClyoNutri\Domain\Dashboard\DashboardOverview::firstName($name) ?: explode(' ', $name)[0]; // F-20 (v54.68)
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $subject = 'Crie uma nova senha no Clyo Nutri';
        $footer = $replyTo !== null
            ? 'Equipe Clyo Nutri · Dúvidas? Responda este e-mail ou escreva para ' . $e($replyTo) . '.'
            : 'Equipe Clyo Nutri · E-mail automático, não precisa responder.';

        $text = "Oi, {$firstName}.\n\n"
            . "Pediram uma nova senha para a sua conta no Clyo Nutri.\n\n"
            . "Para criar a nova senha, use o link abaixo. Ele vale por {$validityMinutes} minutos e só funciona uma vez:\n"
            . "{$resetUrl}\n\n"
            . "Se você não pediu a redefinição, ignore este e-mail. Sua senha continua a mesma.\n\n"
            . "Equipe ClyoNutri\n";

        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#FAF9F4;font-family:Arial,Helvetica,sans-serif;color:#0F3E17;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF9F4;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="background:#0F3E17;padding:24px 32px;color:#FFFFFF;font-size:20px;font-weight:bold;">Clyo Nutri</td></tr>'
            . '<tr><td style="padding:32px;font-size:16px;line-height:1.5;color:#0F3E17;">'
            . '<p style="margin:0 0 16px;">Oi, ' . $e($firstName) . '.</p>'
            . '<p style="margin:0 0 24px;">Pediram uma nova senha para a sua conta no Clyo Nutri. Para criar a nova senha, use o botão abaixo.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;"><tr><td style="background:#39734A;border-radius:8px;">'
            . '<a href="' . $e($resetUrl) . '" style="display:inline-block;padding:14px 28px;color:#FFFFFF;font-weight:bold;text-decoration:none;font-size:16px;">Criar nova senha</a>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 16px;font-size:14px;">O link é válido por <strong>' . $validityMinutes . ' minutos</strong> e só pode ser usado uma vez.</p>'
            . '<p style="margin:0 0 16px;font-size:14px;">Se o botão não funcionar, copie e cole este endereço no navegador:<br>'
            . '<a href="' . $e($resetUrl) . '" style="color:#B04A1B;word-break:break-all;">' . $e($resetUrl) . '</a></p>'
            . '<p style="margin:24px 0 0;font-size:14px;color:#5A6B85;">Se você não pediu a redefinição, ignore este e-mail. Sua senha continua a mesma.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 32px;background:#FAF9F4;font-size:12px;color:#5A6B85;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return new MailMessage($toAddress, $toName, $subject, $html, $text, $replyTo);
    }
}
