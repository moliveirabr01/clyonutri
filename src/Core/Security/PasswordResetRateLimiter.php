<?php
declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/** Reservas atômicas, independentes da existência da conta e dos limites de login. */
final class PasswordResetRateLimiter
{
    public function __construct(private PDO $db) {}

    public function attempt(string $email, ?string $ip): bool
    {
        // Check IP first so a blocked sender cannot create unlimited account buckets.
        $buckets = [];
        if ($ip !== null && $ip !== '') {
            $buckets[] = ['ip|' . $ip, 10];
        }
        $buckets[] = ['email|' . strtolower(trim($email)), 5];
        $now = gmdate('Y-m-d H:i:s');
        $cutoff = gmdate('Y-m-d H:i:s', time() - 900);
        foreach ($buckets as [$identity, $limit]) {
            $key = hash('sha256', 'password_reset|' . $identity);
            try {
                $this->db->prepare("INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, last_attempt_at) VALUES (?, 'password_reset', 0, ?, ?)")
                    ->execute([$key, $now, $now]);
            } catch (\PDOException $e) {
                // Ignore only a duplicate key; storage failures must fail closed.
                if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                    throw $e;
                }
            }
            $this->db->prepare('UPDATE login_attempts SET failures = 0, window_started_at = ? WHERE bucket_key = ? AND window_started_at <= ?')
                ->execute([$now, $key, $cutoff]);
            $reserve = $this->db->prepare('UPDATE login_attempts SET failures = failures + 1, last_attempt_at = ? WHERE bucket_key = ? AND failures < ?');
            $reserve->execute([$now, $key, $limit]);
            if ($reserve->rowCount() !== 1) {
                return false;
            }
        }
        return true;
    }
}
