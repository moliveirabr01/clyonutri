<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/**
 * E-mail de redefinição de senha (pt-BR), em texto e HTML compatível com clientes de e-mail.
 */
final class PasswordResetMail
{
    public static function build(string $to, string $name, string $resetUrl, int $validMinutes, ?string $supportEmail = null): MailMessage
    {
        $first = trim(explode(' ', trim($name))[0] ?? '') ?: 'olá';
        $greeting = $first === 'olá' ? 'Olá,' : "Olá, {$first},";
        $support = $supportEmail ? "Dúvidas? Fale com a gente em {$supportEmail}." : '';

        $text = <<<TXT
{$greeting}

Recebemos um pedido para redefinir a senha da sua conta no ClyoNutri.

Para criar uma nova senha, acesse o link abaixo (válido por {$validMinutes} minutos e para um único uso):

{$resetUrl}

Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.
Por segurança, nunca compartilhe este link.

{$support}
— Equipe ClyoNutri
TXT;

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $supportHtml = $supportEmail ? '<p style="margin:16px 0 0;color:#64748b;font-size:13px">Dúvidas? Fale com a gente em <a href="mailto:' . $e($supportEmail) . '" style="color:#0f2a44">' . $e($supportEmail) . '</a>.</p>' : '';
        $html = <<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redefinir senha</title></head>
<body style="margin:0;padding:0;background:#f5f1ea;font-family:Arial,Helvetica,sans-serif;color:#0f2a44">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f1ea;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px">
<tr><td>
<p style="margin:0 0 24px;font-size:22px;font-weight:bold">Clyo <span style="color:#e07b24">Nutri</span></p>
<p style="margin:0 0 12px;font-size:16px">{$e($greeting)}</p>
<p style="margin:0 0 20px;font-size:15px;line-height:1.5">Recebemos um pedido para redefinir a senha da sua conta no ClyoNutri. Clique no botão para criar uma nova senha.</p>
<p style="margin:0 0 20px"><a href="{$e($resetUrl)}" style="display:inline-block;background:#0f2a44;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 24px;border-radius:8px">Criar nova senha</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#64748b">O link vale por {$validMinutes} minutos e só pode ser usado uma vez. Se o botão não funcionar, copie e cole no navegador:</p>
<p style="margin:0 0 20px;font-size:13px;word-break:break-all"><a href="{$e($resetUrl)}" style="color:#0f2a44">{$e($resetUrl)}</a></p>
<p style="margin:0;font-size:13px;color:#64748b">Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma. Nunca compartilhe este link.</p>
{$supportHtml}
</td></tr></table>
<p style="margin:16px 0 0;font-size:12px;color:#94a3b8">ClyoNutri · mensagem automática enviada a pedido de redefinição de senha.</p>
</td></tr></table>
</body></html>
HTML;

        return new MailMessage($to, 'Redefinição de senha — ClyoNutri', trim($text) . "\n", $html, $name ?: null, $supportEmail ?: null);
    }
}
