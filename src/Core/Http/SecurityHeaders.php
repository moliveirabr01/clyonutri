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

    // ---------------------------------------------------------------- NI2-429
    private static ?string $nonce = null;
    /** @var string[] */
    private static array $extraScript = [];
    /** @var string[] origem do provedor de vídeo, só nas telas de sala (NI2-221 F06) */
    private static array $extraFrame = [];
    private static bool $browserSent = false;
    private static bool $isApi = false;

    /** Nonce imprevisível, um por resposta (16 bytes aleatórios). */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /** CSP_MODE: enforce (padrão) | report-only | off. */
    public static function cspMode(): string
    {
        $m = strtolower(trim((string)(Env::get('CSP_MODE', 'enforce') ?? 'enforce')));
        return in_array($m, ['enforce', 'report-only', 'off'], true) ? $m : 'enforce';
    }

    public static function pagePolicy(): string
    {
        $script = array_merge(["'self'", "'nonce-" . self::nonce() . "'"], self::$extraScript);
        $d = [
            "default-src 'self'",
            'script-src ' . implode(' ', array_unique($script)),
            "script-src-attr 'none'",
            // style-src com 'unsafe-inline': ~1.600 atributos style="" nas telas; exceção documentada (NI2-429).
            "style-src 'self' 'unsafe-inline'",
            // Fontes servidas do próprio domínio (NI2-25): nada de Google Fonts, nenhum IP de visitante sai para terceiro.
            "font-src 'self' data:",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            'connect-src ' . implode(' ', array_unique(array_merge(["'self'"], self::$extraFrame))),
            'frame-src ' . implode(' ', array_unique(array_merge(["'self'"], self::$extraFrame))),
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if (Transport::isHttps()) {
            $d[] = 'upgrade-insecure-requests';
        }
        return implode('; ', $d);
    }

    public static function apiPolicy(): string
    {
        return "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    }

    /** Política mínima SEMPRE efetiva, mesmo com CSP_MODE=report-only (frame-ancestors não funciona em Report-Only). */
    public static function baselinePolicy(): string
    {
        return "frame-ancestors 'none'; object-src 'none'; base-uri 'self'";
    }

    /**
     * Envia nosniff, anti-enquadramento, Referrer-Policy, Permissions-Policy e CSP.
     * Chamado no início do front controller; respostas da API recebem CSP "default-src 'none'"
     * (JSON não executa nada) e mantêm os cabeçalhos CORS existentes.
     */
    public static function sendBrowserPolicies(string $requestUri): void
    {
        if (headers_sent()) {
            return;
        }
        self::$isApi = str_starts_with($requestUri, '/api/');
        header('X-Content-Type-Options: nosniff', true);
        header('X-Frame-Options: DENY', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self), fullscreen=(self), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), magnetometer=(), gyroscope=(), accelerometer=(), browsing-topics=()', true);
        header('Cross-Origin-Opener-Policy: same-origin', true);
        self::$browserSent = true;
        self::emitCsp();
        if (!self::$isApi && self::cspMode() !== 'off') {
            // Carrega o substituto dos handlers inline em toda página HTML (arquivo do próprio site, sem nonce).
            ob_start([self::class, 'injectActions']);
        }
    }

    private static function emitCsp(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('Content-Security-Policy');
        header_remove('Content-Security-Policy-Report-Only');
        if (self::$isApi) {
            header('Content-Security-Policy: ' . self::apiPolicy(), true);
            return;
        }
        switch (self::cspMode()) {
            case 'off':
                header('Content-Security-Policy: ' . self::baselinePolicy(), true);
                break;
            case 'report-only':
                header('Content-Security-Policy: ' . self::baselinePolicy(), true);
                header('Content-Security-Policy-Report-Only: ' . self::pagePolicy(), true);
                break;
            default:
                header('Content-Security-Policy: ' . self::pagePolicy(), true);
        }
    }

    /** Exceção por tela (ex.: Tailwind CDN só nas telas de teleconsulta). Reenvia a CSP. */
    public static function allowScriptSource(string $origin): void
    {
        if (!preg_match('#^https://[a-z0-9.-]+$#', $origin)) {
            return;
        }
        self::$extraScript[] = $origin;
        if (self::$browserSent) {
            self::emitCsp();
        }
    }

    /**
     * NI2-221 F06 (preparado, desligado por padrão): libera a origem do provedor de vídeo
     * (TELEHEALTH_PROVIDER_ORIGIN, ex.: https://clinica.daily.co) em frame-src/connect-src e
     * na Permissions-Policy de câmera/microfone/tela SÓ na resposta da sala que chamar este método.
     * Vazio ou inválido = nada muda. Retorna a origem liberada ou null.
     */
    public static function allowTelehealthProviderOrigin(): ?string
    {
        $origin = strtolower(trim((string)(Env::get('TELEHEALTH_PROVIDER_ORIGIN', '') ?? '')));
        if ($origin === '' || !preg_match('#^https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$#', $origin)) {
            return null;
        }
        self::$extraFrame[] = $origin;
        if (self::$browserSent && !headers_sent()) {
            $o = '"' . $origin . '"';
            header("Permissions-Policy: camera=(self {$o}), microphone=(self {$o}), display-capture=(self {$o}), fullscreen=(self {$o}), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), magnetometer=(), gyroscope=(), accelerometer=(), browsing-topics=()", true);
            self::emitCsp();
        }
        return $origin;
    }

    public static function injectActions(string $buffer, int $phase = 0): string
    {
        $ct = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0) { $ct = strtolower($h); }
        }
        if (($ct !== '' && !str_contains($ct, 'text/html')) || stripos($buffer, '<html') === false
            || str_contains($buffer, '/assets/js/csp-actions.js')) {
            return $buffer;
        }
        $tag = '<script src="/assets/js/csp-actions.js" defer></script>';
        $pos = strripos($buffer, '</body>');
        return $pos === false ? $buffer . $tag : substr($buffer, 0, $pos) . $tag . substr($buffer, $pos);
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
