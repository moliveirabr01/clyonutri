<?php

declare(strict_types=1);
namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Security\Env;

/** Opaque bearer stays in an HttpOnly cookie on web; native keeps using Authorization. */
final class MobileWebSession
{
    private const COOKIE = 'clyonutri_mobile';
    private const PORTAL_COOKIE = 'clyonutri_portal';

    /**
     * Origem aceita para CORS com credenciais e para o cookie clyonutri_mobile (auditoria v27, item 2).
     *
     * Compara a origem inteira (esquema, host e porta), não só o host. Valem:
     *  - a própria origem da requisição (esquema real via Transport::isHttps + Host com porta);
     *  - a origem do APP_URL;
     *  - cada origem de MOBILE_WEB_ORIGINS, escrita exatamente (https://app.exemplo.com).
     * Em produção, origens http:// do APP_URL e de MOBILE_WEB_ORIGINS não valem. Fora de produção, localhost/127.0.0.1/[::1] em qualquer porta
     * valem quando o próprio servidor também está em loopback (app web do Expo em outra porta).
     */
    public static function allowedOrigin(string $origin, ?array $server = null): bool
    {
        $o = self::normalizeOrigin($origin);
        if ($o === null) return false;
        $server ??= $_SERVER;
        if (in_array($o, self::allowedOrigins($server), true)) return true;
        if (Transport::isProduction()) return false;
        $requestHost = self::hostOf((string)($server['HTTP_HOST'] ?? ''));
        return self::isLoopback(self::hostOf($o)) && self::isLoopback($requestHost);
    }

    /** @return list<string> origens normalizadas aceitas nesta requisição */
    public static function allowedOrigins(array $server): array
    {
        $configured = [self::normalizeOrigin((string)(Env::get('APP_URL', '') ?? ''))];
        foreach (explode(',', (string)(Env::get('MOBILE_WEB_ORIGINS', '') ?? '')) as $extra) {
            $configured[] = self::normalizeOrigin(trim($extra));
        }
        $prod = Transport::isProduction();
        $out = array_filter($configured, static fn($o) => $o !== null && (!$prod || str_starts_with($o, 'https://')));
        // A origem real desta requisição (esquema verdadeiro + Host com porta) é sempre a mesma página que fez a chamada.
        // Em produção, quem chega por http:// já é redirecionado para https:// antes daqui (Transport::enforce).
        $host = trim((string)($server['HTTP_HOST'] ?? ''));
        if ($host !== '' && ($self = self::normalizeOrigin((Transport::isHttps($server) ? 'https' : 'http') . '://' . $host)) !== null) {
            $out[] = $self;
        }
        return array_values(array_unique($out));
    }

    /** esquema://host[:porta] em minúsculas, sem porta padrão; null se não for uma origem limpa. */
    public static function normalizeOrigin(string $origin): ?string
    {
        if ($origin === '' || preg_match('/[\s\x00-\x1f]/', $origin)) return null;
        $p = parse_url($origin);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return null;
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) return null;
        if (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/') return null;
        $host = strtolower((string)$p['host']);
        if (!preg_match('/^([a-z0-9-]+(\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])$/', $host)) return null;
        $port = isset($p['port']) ? (int)$p['port'] : null;
        $default = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port !== null && $port !== $default ? ':' . $port : '');
    }

    private static function hostOf(string $originOrHost): string
    {
        $h = parse_url(str_contains($originOrHost, '://') ? $originOrHost : 'http://' . $originOrHost, PHP_URL_HOST);
        return strtolower((string)$h);
    }

    private static function isLoopback(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    public static function token(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) return substr($header, 7);
        $token = $_COOKIE[self::COOKIE] ?? $_COOKIE[self::PORTAL_COOKIE] ?? null;
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
        self::writeCookies($token,$expiresAt);
    }

    /** HTML forms have session CSRF instead of the API's X-ClyoNutri-Web header. */
    public static function startFromPortal(string $token,string $expiresAt): void
    {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !\ClyoNutri\Core\Security\Csrf::validate($_POST['_csrf_token']??null)) throw new \DomainException('Formulário expirado. Recarregue o portal.');
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if ($origin!=='' && !self::allowedOrigin($origin)) throw new \DomainException('Origem da solicitação inválida.');
        self::writeCookies($token,$expiresAt);
    }

    private static function writeCookies(string $token,string $expiresAt): void
    {
        // Production deployments must serve HTTPS; no insecure persistent cookie there.
        $secure = Transport::isHttps();
        if (Env::get('APP_ENV') === 'production' && !$secure) return;
        $expires = (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp();
        setcookie(self::COOKIE, $token, ['expires' => $expires, 'path' => '/api/v1', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Strict']);
        setcookie(self::PORTAL_COOKIE, $token, ['expires'=>$expires,'path'=>'/paciente','httponly'=>true,'secure'=>$secure,'samesite'=>'Strict']);
        header('Cache-Control: no-store');
    }

    public static function clear(): void
    {
        setcookie(self::PORTAL_COOKIE, '', ['expires'=>1,'path'=>'/paciente','httponly'=>true,'secure'=>Transport::isHttps(),'samesite'=>'Strict']);
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/api/v1', 'httponly' => true, 'secure' => Transport::isHttps(), 'samesite' => 'Strict']);
    }
}
