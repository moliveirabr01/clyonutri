<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

/**
 * E-mail genérico de aviso de segurança (NI2-481): senha alterada, e-mail alterado,
 * conta bloqueada/suspensa. Neutro, sem dado clínico, senha, OTP ou token.
 * Design Clinical Botanical. Pode ter um botão de ação opcional (link canônico de APP_URL).
 */
final class SecurityNoticeEmail
{
    /**
     * @param list<string> $lines parágrafos do corpo (texto puro; serão escapados no HTML)
     */
    public static function build(
        string $toAddress,
        string $toName,
        string $subject,
        string $headline,
        array $lines,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
        ?string $replyTo = null
    ): MailMessage {
        $name = trim($toName) !== '' ? trim($toName) : 'Olá';
        $firstName = explode(' ', $name)[0];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $footer = $replyTo !== null
            ? 'Equipe Clyo Nutri · Dúvidas? Responda este e-mail ou escreva para ' . $e($replyTo) . '.'
            : 'Equipe Clyo Nutri · E-mail automático, não precisa responder.';

        $text = "Oi, {$firstName}.\n\n" . implode("\n\n", $lines) . "\n";
        if ($actionLabel !== null && $actionUrl !== null) {
            $text .= "\n{$actionLabel}: {$actionUrl}\n";
        }
        $text .= "\nEquipe ClyoNutri\n";

        $htmlLines = '';
        foreach ($lines as $l) {
            $htmlLines .= '<p style="margin:0 0 16px;">' . $e($l) . '</p>';
        }
        $button = '';
        if ($actionLabel !== null && $actionUrl !== null) {
            $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr><td style="background:#39734A;border-radius:8px;">'
                . '<a href="' . $e($actionUrl) . '" style="display:inline-block;padding:14px 28px;color:#FFFFFF;font-weight:bold;text-decoration:none;font-size:16px;">' . $e($actionLabel) . '</a>'
                . '</td></tr></table>';
        }

        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#FAF9F4;font-family:Arial,Helvetica,sans-serif;color:#0F3E17;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF9F4;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="background:#0F3E17;padding:24px 32px;color:#FFFFFF;font-size:20px;font-weight:bold;">Clyo Nutri</td></tr>'
            . '<tr><td style="padding:32px;font-size:16px;line-height:1.5;color:#0F3E17;">'
            . '<p style="margin:0 0 16px;font-size:18px;font-weight:bold;">' . $e($headline) . '</p>'
            . '<p style="margin:0 0 16px;">Oi, ' . $e($firstName) . '.</p>'
            . $htmlLines
            . $button
            . '</td></tr>'
            . '<tr><td style="padding:16px 32px;background:#FAF9F4;font-size:12px;color:#5A6B85;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return new MailMessage($toAddress, $name, $subject, $html, $text, $replyTo);
    }
}
