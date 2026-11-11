<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

/**
 * Proteção CSRF para rotas /api/ autenticadas pelo COOKIE de sessão web ($requireAuth).
 * (auditoria 14/10/2026 — lacuna em /api/v1/subscription/cancel)
 *
 * O Router isenta /api/ do CSRF central porque a maioria das APIs usa bearer (app móvel).
 * Quando a autenticação é a sessão do navegador, toda mutação (POST/PUT/PATCH/DELETE) precisa de:
 *   1. token CSRF da sessão (header X-CSRF-Token, campo _csrf_token/csrf_token ou JSON "_csrf_token"); e
 *   2. Origin (ou, na falta dele, Referer) igual à origem do APP_URL ou do próprio Host.
 * Fluxos bearer não passam por $requireAuth e continuam intocados.
 */
final class SessionApiCsrfGuard
{
    /** Retorna null se a requisição pode seguir, ou a mensagem de erro (403). */
    public static function check(string $method, string $path, array $server, array $post, ?string $rawBody): ?string
    {
        $method = strtoupper($method);
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || !str_starts_with($path, '/api/')) {
            return null;
        }

        $token = $server['HTTP_X_CSRF_TOKEN'] ?? $post['_csrf_token'] ?? $post['csrf_token'] ?? null;
        if (($token === null || $token === '') && $rawBody !== null && $rawBody !== '') {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $token = $json['_csrf_token'] ?? $json['csrf_token'] ?? null;
            }
        }
        if (!is_string($token) || !Csrf::validate($token)) {
            return 'CSRF token inválido ou ausente. Ação bloqueada por segurança.';
        }

        $allowed = self::allowedOrigins($server);
        $origin = (string)($server['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && $origin !== 'null') {
            return in_array(self::originOf($origin), $allowed, true) ? null : 'Origem da requisição não autorizada.';
        }
        $referer = (string)($server['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            return in_array(self::originOf($referer), $allowed, true) ? null : 'Origem da requisição não autorizada.';
        }
        return 'Origem da requisição ausente. Ação bloqueada por segurança.';
    }

    private static function allowedOrigins(array $server): array
    {
        $out = [];
        $appUrl = (string)(Env::get('APP_URL', '') ?? '');
        if ($appUrl !== '' && ($o = self::originOf($appUrl)) !== '') {
            $out[] = $o;
        }
        $host = (string)($server['HTTP_HOST'] ?? '');
        if ($host !== '') {
            // X-Forwarded-Proto só vale vindo de TRUSTED_PROXIES (auditoria v27, item 2).
            $https = \ClyoNutri\Core\Http\Transport::isHttps($server);
            $out[] = strtolower(($https ? 'https' : 'http') . '://' . $host);
        }
        return array_values(array_unique($out));
    }

    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $scheme = strtolower($p['scheme']);
        $port = isset($p['port']) ? (int)$p['port'] : null;
        $default = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . strtolower($p['host']) . ($port !== null && $port !== $default ? ':' . $port : '');
    }
}
