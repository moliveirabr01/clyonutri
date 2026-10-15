<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Security\Env;

/**
 * Cabeçalhos de segurança centralizados (fonte única para respostas do PHP).
 * O .htaccess só completa arquivos estáticos com "Header setifempty", então não há
 * valores duplicados ou divergentes entre Apache e PHP.
 *
 * NI2-428 (HSTS): HSTS_MAX_AGE (segundos; padrão 300 em produção, 0 = não enviar),
 * HSTS_INCLUDE_SUBDOMAINS=false, HSTS_PRELOAD=false. Só é enviado por HTTPS.
 * Rollback: HSTS_MAX_AGE=0 desliga o envio; para apagar política já gravada nos
 * navegadores, use HSTS_SEND_ZERO=true (envia "max-age=0" por HTTPS).
 */
final class SecurityHeaders
{
    public static function hstsValue(): ?string
    {
        if (self::bool('HSTS_SEND_ZERO', false)) {
            return 'max-age=0';
        }
        $raw = Env::get('HSTS_MAX_AGE');
        $default = strtolower((string)(Env::get('APP_ENV', '') ?? '')) === 'production' ? 300 : 0;
        $age = ($raw === null || $raw === '') ? $default : (int)$raw;
        if ($age <= 0) {
            return null;
        }
        $age = min($age, 63072000);
        $v = 'max-age=' . $age;
        if (self::bool('HSTS_INCLUDE_SUBDOMAINS', false)) {
            $v .= '; includeSubDomains';
            if (self::bool('HSTS_PRELOAD', false) && $age >= 31536000) {
                $v .= '; preload';
            }
        }
        return $v;
    }

    public static function sendHsts(): void
    {
        if (headers_sent() || !Transport::isHttps()) {
            return;
        }
        $v = self::hstsValue();
        if ($v !== null) {
            header('Strict-Transport-Security: ' . $v, true);
        }
    }

    public static function bool(string $key, bool $default): bool
    {
        $raw = Env::get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'on', 'yes'], true);
    }
}
