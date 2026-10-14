<?php

declare(strict_types=1);
namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Security\Env;

/** Opaque bearer stays in an HttpOnly cookie on web; native keeps using Authorization. */
final class MobileWebSession
{
    private const COOKIE = 'clyonutri_mobile';

    public static function allowedOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])) return false;
        $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        $sameHost = strcasecmp($parts['host'], (string)$requestHost) === 0;
        $configured = array_filter(array_map('trim', explode(',', (string)Env::get('MOBILE_WEB_ORIGINS', ''))));
        return $sameHost || in_array($origin, $configured, true);
    }

    public static function token(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) return substr($header, 7);
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
            && (!self::allowedOrigin($origin) || ($_SERVER['HTTP_X_CLYONUTRI_WEB'] ?? '') !== '1')) {
            http_response_code(403);
            echo json_encode(['error' => 'Origem da solicitação inválida.']);
            exit;
        }
        return $token;
    }

    public static function start(string $token, string $expiresAt): void
    {
        if (($_SERVER['HTTP_X_CLYONUTRI_WEB'] ?? '') !== '1') return;
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && !self::allowedOrigin($origin)) return;
        // Production deployments must serve HTTPS; no insecure persistent cookie there.
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        if (Env::get('APP_ENV') === 'production' && !$secure) return;
        $expires = (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp();
        setcookie(self::COOKIE, $token, ['expires' => $expires, 'path' => '/api/v1', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Strict']);
        header('Cache-Control: no-store');
    }

    public static function clear(): void
    {
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/api/v1', 'httponly' => true, 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', 'samesite' => 'Strict']);
    }
}
