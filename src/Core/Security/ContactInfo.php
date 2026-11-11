<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

/**
 * NI2-446: endereço público e e-mails de contato do ClyoNutri, num lugar só.
 *
 * - URL pública: APP_URL do .env (ex.: https://clyonutri.com). Sem APP_URL válida, usa o domínio oficial
 *   (OFFICIAL_URL), decidido pelo Maicon em 25/10/2026: clyonutri.com.
 * - Suporte: APP_SUPPORT_EMAIL; sem ele, suporte@<domínio do APP_URL>.
 * - Encarregado (DPO): APP_DPO_EMAIL; sem ele, dpo@<domínio do APP_URL>.
 *
 * O domínio dos e-mails vem do host do APP_URL sem o "www.". Se o sistema rodar num subdomínio
 * (ex.: app.clyonutri.com), defina APP_SUPPORT_EMAIL e APP_DPO_EMAIL no .env.
 */
final class ContactInfo
{
    public const OFFICIAL_URL = 'https://clyonutri.com';

    /** URL pública sem barra no fim (ex.: https://clyonutri.com). */
    public static function appUrl(): string
    {
        $url = rtrim(trim((string)(Env::get('APP_URL') ?? '')), '/');
        $parts = $url !== '' ? parse_url($url) : false;
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return self::OFFICIAL_URL;
        }
        return $url;
    }

    /** Endereço absoluto de um caminho do site (canonical, og:url, link público). */
    public static function url(string $path = '/'): string
    {
        return self::appUrl() . '/' . ltrim($path, '/');
    }

    /** Domínio usado nos e-mails padrão: host do APP_URL sem "www.". */
    public static function emailDomain(): string
    {
        $host = strtolower((string)parse_url(self::appUrl(), PHP_URL_HOST));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public static function supportEmail(): string
    {
        return self::configured('APP_SUPPORT_EMAIL') ?? 'suporte@' . self::emailDomain();
    }

    public static function dpoEmail(): string
    {
        return self::configured('APP_DPO_EMAIL') ?? 'dpo@' . self::emailDomain();
    }

    private static function configured(string $key): ?string
    {
        $value = trim((string)(Env::get($key) ?? ''));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
