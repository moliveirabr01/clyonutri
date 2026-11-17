<?php
declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/**
 * CR875-14 (NI2-890): limite de tentativas com link inválido nas telas de convite da equipe e de nova senha.
 * Conta só link inválido/expirado/de outro tipo, por IP, numa janela de 15 minutos (como a recuperação de senha:
 * 10 por IP). Usa login_attempts (bucket_key com hash; não guarda IP nem token em claro). Falha de armazenamento
 * no registro não libera o bloqueio já existente.
 */
final class TokenLinkRateLimiter
{
    public const LIMIT = 10;
    public const WINDOW_SECONDS = 900;

    public function __construct(private PDO $db) {}

    private static function key(string $scope, string $ip): string
    {
        return hash('sha256', 'token_link|' . $scope . '|' . $ip);
    }

    public function blocked(string $scope, ?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        $st = $this->db->prepare('SELECT failures, window_started_at FROM login_attempts WHERE bucket_key = ?');
        $st->execute([self::key($scope, $ip)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $started = strtotime((string)$row['window_started_at'] . ' UTC');
        return (int)$row['failures'] >= self::LIMIT && $started !== false && $started > time() - self::WINDOW_SECONDS;
    }

    public function fail(string $scope, ?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }
        $key = self::key($scope, $ip);
        $now = gmdate('Y-m-d H:i:s');
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
        try {
            $this->db->prepare('INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, last_attempt_at) VALUES (?, ?, 0, ?, ?)')
                ->execute([$key, substr($scope, 0, 20), $now, $now]);
        } catch (\PDOException $e) {
            if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
        }
        $this->db->prepare('UPDATE login_attempts SET failures = 0, window_started_at = ? WHERE bucket_key = ? AND window_started_at <= ?')
            ->execute([$now, $key, $cutoff]);
        $this->db->prepare('UPDATE login_attempts SET failures = failures + 1, last_attempt_at = ? WHERE bucket_key = ?')
            ->execute([$now, $key]);
    }
}
