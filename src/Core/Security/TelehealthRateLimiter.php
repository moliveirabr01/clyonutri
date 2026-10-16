<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/**
 * Rate limit dos endpoints do paciente na teleconsulta (NI2-221 F14 / NI2-236 / NI2-244).
 *
 * Reaproveita a tabela login_attempts (mesmas colunas e o mesmo padrão de bucket do LoginRateLimiter):
 * bucket_key = SHA-256 do escopo, janela fixa de 15 min, bloqueio até o fim da janela.
 * Nada de IP ou token em claro no banco: só o hash do bucket.
 */
final class TelehealthRateLimiter
{
    public const WINDOW_SECONDS = 900;

    /** Limites por janela de 15 min (por IP; e por sessão de teleconsulta onde houver). */
    public const LIMITS = [
        'tele_link' => 30,     // troca do link (token) por cookie, por IP — inclui tokens inválidos
        'tele_bad' => 10,      // tokens inválidos/expirados por IP (enumeração)
        'tele_poll' => 400,    // polling de status, por sessão+IP (backoff no cliente: ~1 req/5-30 s)
        'tele_post' => 30,     // dispositivo/entrar, por sessão+IP
        'tele_consent' => 10,  // aceite/recusa do termo, por sessão+IP
    ];

    public function __construct(private PDO $db, private ?\Closure $clock = null)
    {
    }

    private function now(): int
    {
        return $this->clock ? (int)($this->clock)() : time();
    }

    /** true = pode seguir; false = 429. Conta a tentativa. */
    public function hit(string $scope, string $subject): bool
    {
        $limit = self::LIMITS[$scope] ?? 30;
        $key = hash('sha256', "tele|{$scope}|{$subject}");
        $now = $this->now();
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);
        $lockUntil = gmdate('Y-m-d H:i:s', $now + self::WINDOW_SECONDS);

        try {
            $this->db->prepare("INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (:k, :s, 0, :n, NULL, :n2)")
                ->execute(['k' => $key, 's' => $scope, 'n' => $nowStr, 'n2' => $nowStr]);
        } catch (\PDOException) {
            // já existe
        }
        $this->db->prepare("UPDATE login_attempts SET failures = 0, locked_until = NULL, window_started_at = :n
            WHERE bucket_key = :k AND window_started_at < :cutoff AND (locked_until IS NULL OR locked_until <= :n2)")
            ->execute(['n' => $nowStr, 'k' => $key, 'cutoff' => $cutoff, 'n2' => $nowStr]);

        $upd = $this->db->prepare("UPDATE login_attempts
            SET locked_until = CASE WHEN failures + 1 >= CAST(:lim AS SIGNED) THEN :lu ELSE locked_until END,
                failures = failures + 1,
                last_attempt_at = :n
            WHERE bucket_key = :k AND (locked_until IS NULL OR locked_until <= :n2)");
        $upd->execute(['lim' => $limit, 'lu' => $lockUntil, 'n' => $nowStr, 'k' => $key, 'n2' => $nowStr]);
        return $upd->rowCount() > 0;
    }

    /** Consulta sem contar: o bucket está bloqueado agora? */
    public function isLocked(string $scope, string $subject): bool
    {
        $key = hash('sha256', "tele|{$scope}|{$subject}");
        $st = $this->db->prepare("SELECT locked_until FROM login_attempts WHERE bucket_key = :k");
        $st->execute(['k' => $key]);
        $lu = $st->fetchColumn();
        return is_string($lu) && $lu !== '' && $lu > gmdate('Y-m-d H:i:s', $this->now());
    }

    /** IP do cliente: REMOTE_ADDR, a mesma fonte usada pelo LoginRateLimiter. */
    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Minimização (LGPD): IPv4 → /24, IPv6 → /48. */
    public static function anonymizeIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return "{$p[0]}.{$p[1]}.{$p[2]}.0";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) ?: null;
        }
        return null;
    }

    /** Minimização do User-Agent: só família do navegador e do sistema. */
    public static function summarizeUserAgent(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }
        $browser = 'outro';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) { $browser = $name; break; }
        }
        $os = 'outro';
        foreach (['Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (str_contains($ua, $needle)) { $os = $name; break; }
        }
        return "{$browser}/{$os}";
    }
}
