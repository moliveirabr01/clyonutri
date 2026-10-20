<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PreConsultation;

use ClyoNutri\Core\Mail\MailMessage;

/** E-mail com o link da pré-consulta. Sem dados clínicos: nome, profissional, prazo e link. */
final class PreConsultationMail
{
    public static function build(string $to, string $patientName, string $professionalName, string $clinicName, string $expiresLocal, string $url): MailMessage
    {
        $first = trim(explode(' ', trim($patientName))[0] ?? '');
        $greeting = $first !== '' ? "Olá, {$first}," : 'Olá,';
        $subject = 'Questionário antes da sua consulta — ' . ($clinicName !== '' ? $clinicName : 'ClyoNutri');
        $intro = "{$professionalName} pediu que você responda algumas perguntas antes da consulta. Leva poucos minutos e você pode salvar e continuar depois.";

        $text = <<<TXT
{$greeting}

{$intro}

{$url}

O link vale até {$expiresLocal} e é pessoal: não o compartilhe. Para abrir, confirme sua data de nascimento.
As respostas vão apenas para a clínica e não geram diagnóstico automático.

— {$clinicName}
TXT;
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$e($subject)}</title></head>
<body style="margin:0;padding:0;background:#FAF7F2;font-family:Arial,Helvetica,sans-serif;color:#1F2937">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF7F2;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px"><tr><td>
<p style="margin:0 0 24px;font-size:20px;font-weight:bold;color:#0F2747">{$e($clinicName)}</p>
<p style="margin:0 0 12px;font-size:16px">{$e($greeting)}</p>
<p style="margin:0 0 20px;font-size:15px;line-height:1.5">{$e($intro)}</p>
<p style="margin:0 0 20px"><a href="{$e($url)}" style="display:inline-block;background:#0F2747;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 24px;border-radius:10px">Responder questionário</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#667085">Se o botão não funcionar, copie e cole no navegador:</p>
<p style="margin:0 0 20px;font-size:13px;word-break:break-all"><a href="{$e($url)}" style="color:#0F2747">{$e($url)}</a></p>
<p style="margin:0;font-size:13px;color:#667085">O link vale até {$e($expiresLocal)} e é pessoal. As respostas vão apenas para a clínica e não geram diagnóstico automático.</p>
</td></tr></table></td></tr></table>
</body></html>
HTML;
        return new MailMessage($to, $patientName, $subject, $html, trim($text) . "\n");
    }
}
