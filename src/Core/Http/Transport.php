<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Security\Env;

/**
 * NI2-427 (F01): imposição de HTTPS no primeiro ponto controlado pela aplicação,
 * antes de iniciar sessão, CORS, banco ou renderização.
 *
 * - GET/HEAD por HTTP  -> 301 para a origem canônica (APP_URL https), mesmo caminho e query.
 *   O host do Location vem de APP_URL, nunca do cabeçalho Host da requisição.
 * - Outros métodos por HTTP -> 403 sem processamento (nenhum controller, nenhuma sessão).
 *   O servidor não consegue "desenviar" um corpo que o cliente já mandou por HTTP;
 *   a proteção real é nunca servir o formulário por HTTP (redirect acima + HSTS).
 * - X-Forwarded-Proto / X-Forwarded-Ssl só são considerados quando REMOTE_ADDR
 *   está em TRUSTED_PROXIES (lista de IPs/CIDRs). Sem isso, um cliente não consegue
 *   "fingir" HTTPS nem provocar loop.
 *
 * Configuração (.env):
 *   FORCE_HTTPS=true|false   padrão: true quando APP_ENV=production (só "false" explícito desliga, com aviso no log), senão false.
 *   TRUSTED_PROXIES=         padrão vazio (HostGator termina TLS no próprio Apache).
 */
final class Transport
{
    public static function isHttps(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        $https = strtolower((string)($s['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        if ((string)($s['REQUEST_SCHEME'] ?? '') === 'https') {
            return true;
        }
        if (self::fromTrustedProxy($s)) {
            $proto = strtolower(trim(explode(',', (string)($s['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ($proto === 'https') {
                return true;
            }
            if (strtolower((string)($s['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
                return true;
            }
        }
        return false;
    }

    public static function fromTrustedProxy(array $s): bool
    {
        $remote = (string)($s['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return false;
        }
        $list = trim((string)(Env::get('TRUSTED_PROXIES', '') ?? ''));
        if ($list === '') {
            return false;
        }
        foreach (preg_split('/[\s,]+/', $list) ?: [] as $entry) {
            if ($entry !== '' && self::ipMatches($remote, $entry)) {
                return true;
            }
        }
        return false;
    }

    public static function ipMatches(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }

    /**
     * v10: em produção o HTTPS é obrigatório por padrão. FORCE_HTTPS ausente, vazio ou com valor
     * desconhecido NÃO desliga; só um "false/0/off/no" explícito desliga, e isso gera um aviso no log.
     */
    public static function forceHttpsEnabled(): bool
    {
        $raw = Env::get('FORCE_HTTPS');
        $val = strtolower(trim((string)($raw ?? '')));
        $isProd = self::isProduction();
        if (in_array($val, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($val, ['0', 'false', 'off', 'no'], true)) {
            if ($isProd) {
                self::warnOnce('[ClyoNutri][seguranca] FORCE_HTTPS=false com APP_ENV=production: HTTPS obrigatório DESLIGADO pelo .env. Remova a linha ou use FORCE_HTTPS=true.');
            }
            return false;
        }
        return $isProd;
    }

    public static function isProduction(): bool
    {
        return strtolower(trim((string)(Env::get('APP_ENV', '') ?? ''))) === 'production';
    }

    private static bool $warned = false;

    private static function warnOnce(string $msg): void
    {
        if (self::$warned || PHP_SAPI === 'cli') {
            return;
        }
        self::$warned = true;
        error_log($msg);
    }

    /** Origem canônica https://host[:porta] a partir de APP_URL; null se APP_URL não for https válida. */
    public static function canonicalOrigin(): ?string
    {
        $url = trim((string)(Env::get('APP_URL', '') ?? ''));
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return null;
        }
        $host = strtolower($p['host']);
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }
        return 'https://' . $host . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    }

    /** Caminho+query seguros para o Location (sem esquema, sem "//", sem CR/LF). */
    public static function safeRequestTarget(string $uri): string
    {
        $uri = preg_replace('/[\x00-\x20\x7f]/', '', $uri) ?? '/';
        return '/' . ltrim($uri, '/\\');
    }

    /**
     * Decide o que fazer com a requisição. Retorna null (seguir) ou
     * ['status'=>int,'location'=>?string,'body'=>string].
     */
    public static function decide(?array $server = null): ?array
    {
        $s = $server ?? $_SERVER;
        if (PHP_SAPI === 'cli' && $server === null) {
            return null;
        }
        if (!self::forceHttpsEnabled() || self::isHttps($s)) {
            return null;
        }
        $method = strtoupper((string)($s['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET' || $method === 'HEAD') {
            $origin = self::canonicalOrigin();
            if ($origin === null) {
                return ['status' => 403, 'location' => null,
                    'body' => "Acesso somente por HTTPS. Configure APP_URL com https:// no .env.\n"];
            }
            return ['status' => 301, 'location' => $origin . self::safeRequestTarget((string)($s['REQUEST_URI'] ?? '/')), 'body' => ''];
        }
        return ['status' => 403, 'location' => null,
            'body' => "Requisição recusada: este endereço só aceita HTTPS. Nada foi processado. "
                . "Abra o site por https:// e repita a operação.\n"];
    }

    /** Aplica a decisão e encerra, se for o caso. Chamado no início do front controller. */
    public static function enforce(): void
    {
        $d = self::decide();
        if ($d === null) {
            return;
        }
        http_response_code($d['status']);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if ($d['location'] !== null) {
            header('Location: ' . $d['location'], true, $d['status']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'HEAD') {
                echo $d['body'];
            }
        }
        exit;
    }
}
