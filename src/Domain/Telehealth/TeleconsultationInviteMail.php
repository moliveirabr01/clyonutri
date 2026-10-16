<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Mail\MailMessage;

/**
 * E-mails da teleconsulta (pt-BR). Sem dados clínicos: só nome, profissional,
 * data/hora e o link de acesso (NI2-239/250).
 */
final class TeleconsultationInviteMail
{
    public static function build(string $kind, string $to, string $patientName, string $professionalName, string $whenLocal, string $url): MailMessage
    {
        $first = trim(explode(' ', trim($patientName))[0] ?? '') ?: '';
        $greeting = $first !== '' ? "Olá, {$first}," : 'Olá,';
        $isReminder = $kind === 'reminder';
        $subject = $isReminder ? 'Lembrete: sua teleconsulta começa em breve — ClyoNutri' : 'Convite para sua teleconsulta — ClyoNutri';
        $intro = $isReminder
            ? "Sua teleconsulta com {$professionalName} é em {$whenLocal} (horário de Brasília)."
            : "{$professionalName} convidou você para uma teleconsulta em {$whenLocal} (horário de Brasília).";

        $text = <<<TXT
{$greeting}

{$intro}

A sala de espera abre pouco antes do horário. Entre pelo link abaixo, no celular ou no computador, sem instalar nada:

{$url}

Antes de entrar, você vai testar câmera e microfone e ler o termo da teleconsulta. A chamada não é gravada.
Este link é pessoal: não o compartilhe. Se a consulta for remarcada, você receberá um novo link.

— ClyoNutri
TXT;

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$e($subject)}</title></head>
<body style="margin:0;padding:0;background:#FAF7F2;font-family:Arial,Helvetica,sans-serif;color:#1F2937">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF7F2;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px">
<tr><td>
<p style="margin:0 0 24px;font-size:22px;font-weight:bold;color:#0F2747">ClyoNutri</p>
<p style="margin:0 0 12px;font-size:16px">{$e($greeting)}</p>
<p style="margin:0 0 20px;font-size:15px;line-height:1.5">{$e($intro)} A sala de espera abre pouco antes do horário.</p>
<p style="margin:0 0 20px"><a href="{$e($url)}" style="display:inline-block;background:#0F2747;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 24px;border-radius:10px">Entrar na sala de espera</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#667085">Funciona no celular ou no computador, sem instalar nada. Se o botão não funcionar, copie e cole no navegador:</p>
<p style="margin:0 0 20px;font-size:13px;word-break:break-all"><a href="{$e($url)}" style="color:#0F2747">{$e($url)}</a></p>
<p style="margin:0;font-size:13px;color:#667085">A chamada não é gravada. Este link é pessoal: não o compartilhe.</p>
</td></tr></table>
<p style="margin:16px 0 0;font-size:12px;color:#667085">ClyoNutri · mensagem automática sobre sua consulta.</p>
</td></tr></table>
</body></html>
HTML;

        return new MailMessage($to, $subject, trim($text) . "\n", $html, $patientName ?: null);
    }
}
