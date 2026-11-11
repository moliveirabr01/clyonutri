<?php

declare(strict_types=1);

namespace ClyoNutri\Core\I18n;

/**
 * v54.57 (UX-106): um único formatador de data/hora para a interface, sempre no fuso do consultório
 * (APP_TIMEZONE, America/Sao_Paulo) e no padrão dd/mm/aaaa hh:mm.
 *
 * - local(): valores gravados no fuso da aplicação (date() do PHP; CURRENT_TIMESTAMP no MySQL, cuja sessão
 *   é alinhada ao APP_TIMEZONE em Connection).
 * - fromUtc(): valores gravados explicitamente em UTC (gmdate), como a expiração de convites e tokens.
 */
final class LocalTime
{
    public const FORMAT = 'd/m/Y \à\s H:i';

    public static function local(?string $value, string $format = self::FORMAT): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (preg_match('/\s*UTC$/i', $value)) return self::fromUtc($value, $format);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value)) return $value; // já formatado: não reinterpreta
        try {
            return (new \DateTimeImmutable($value, self::zone()))->setTimezone(self::zone())->format($format);
        } catch (\Throwable) {
            return $value;
        }
    }

    public static function fromUtc(?string $value, string $format = self::FORMAT): string
    {
        $value = trim((string)preg_replace('/\s*UTC$/i', '', (string)$value));
        if ($value === '') return '';
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(self::zone())->format($format);
        } catch (\Throwable) {
            return $value;
        }
    }

    /** Nome do fuso para o paciente/profissional (ex.: "horário de Brasília"). */
    public static function zoneLabel(): string
    {
        return self::zone()->getName() === 'America/Sao_Paulo' ? 'horário de Brasília' : self::zone()->getName();
    }

    private static function zone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('America/Sao_Paulo');
        }
    }
}
