<?php

declare(strict_types=1);
namespace ClyoNutri\Application\View;

/** Single presentation for starting the scheduling form; returning patients keep their dedicated return action. */
final class NewConsultationButton
{
    private const CLASSES = 'btn btn-primary new-consultation-cta';
    public static function link(string $href, string $testId = 'new-consultation'): string
    {
        return '<a class="'.self::CLASSES.'" href="'.self::escape($href).'" data-testid="'.self::escape($testId).'">+ Nova consulta</a>';
    }
    public static function dialog(string $dialog, string $testId = 'agenda-new-appointment'): string
    {
        return '<button type="button" class="'.self::CLASSES.'" aria-controls="'.self::escape($dialog).'" data-toggle-dialog="'.self::escape($dialog).'" data-testid="'.self::escape($testId).'">+ Nova consulta</button>';
    }
    private static function escape(string $text): string {return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');}
}
