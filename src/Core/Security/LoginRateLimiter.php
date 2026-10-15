<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use ClyoNutri\Core\Settings\PlatformSettings;
use PDO;

/**
 * Limitador persistente e compartilhado de tentativas de login (auditoria 14/10/2026, F03).
 *
 * - Baldes: (conta normalizada + IP) com limite = PlatformSettings::getMaxLoginAttempts (padrão 5)
 *   e (somente IP) com limite 10x maior, ambos numa janela de 15 minutos.
 * - A tentativa é RESERVADA (contada) antes do password_verify, com UPDATE atômico: requisições
 *   concorrentes não ultrapassam a política. Sucesso limpa o balde da conta.
 * - Ao atingir o limite, bloqueio temporário progressivo (60s, 120s, 240s... até 15 min). Nunca é permanente:
 *   ao fim do bloqueio e da janela a contagem é zerada.
 * - O contador vive no banco: trocar de sessão/cookie ou alternar senha/PIN não zera nada.
 * - Nada de senha/PIN é armazenado ou auditado; a conta é guardada só como hash SHA-256.
 */
final class LoginRateLimiter
{
    public const WINDOW_SECONDS = 900;
    public const BASE_LOCK_SECONDS = 60;
    public const MAX_LOCK_SECONDS = 900;
    public const IP_MULTIPLIER = 10;

    /** @var callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null, private ?int $maxAttempts = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function normalizeAccount(string $account): string
    {
        $a = mb_strtolower(trim($account), 'UTF-8');
        if ($a !== '' && !str_contains($a, '@')) {
            $digits = preg_replace('/\D+/', '', $a);
            if ($digits !== '') {
                return $digits;
            }
        }
        return $a;
    }

    public function maxAttempts(): int
    {
        if ($this->maxAttempts === null) {
            try {
                $this->maxAttempts = PlatformSettings::getMaxLoginAttempts($this->db);
            } catch (\Throwable) {
                $this->maxAttempts = 5;
            }
        }
        return $this->maxAttempts;
    }

    /**
     * Reserva uma tentativa. Retorna ['allowed'=>bool, 'retry_after'=>int].
     * $realm separa populações de contas (ex.: 'staff' para users, 'patient' para pacientes).
     */
    public function attempt(string $realm, string $account, string $ip): array
    {
        $now = ($this->clock)();
        $buckets = $this->buckets($realm, $account, $ip);
        $reserved = [];
        foreach ($buckets as [$key, $scope, $limit]) {
            $r = $this->reserve($key, $scope, $limit, $now);
            if (!$r['allowed']) {
                // devolve as reservas já feitas para não penalizar o outro balde à toa
                foreach ($reserved as $k) {
                    $this->db->prepare("UPDATE login_attempts SET failures = failures - 1 WHERE bucket_key = :k AND failures > 0")->execute(['k' => $k]);
                }
                $this->audit('LOGIN_RATE_LIMITED', $realm, $scope, $ip);
                return $r;
            }
            $reserved[] = $key;
        }
        return ['allowed' => true, 'retry_after' => 0];
    }

    /** Credencial correta: zera o balde conta+IP e devolve a reserva do balde de IP. */
    public function success(string $realm, string $account, string $ip): void
    {
        [$acct, $ipb] = $this->buckets($realm, $account, $ip);
        $this->db->prepare("DELETE FROM login_attempts WHERE bucket_key = :k")->execute(['k' => $acct[0]]);
        $this->db->prepare("UPDATE login_attempts
            SET locked_until = CASE WHEN failures - 1 < CAST(:lim AS SIGNED) THEN NULL ELSE locked_until END,
                failures = failures - 1
            WHERE bucket_key = :k AND failures > 0")->execute(['k' => $ipb[0], 'lim' => $ipb[2]]);
    }

    /** Credencial errada: a tentativa já foi contada; apenas audita (sem segredo). */
    public function failure(string $realm, string $account, string $ip): void
    {
        $this->audit('LOGIN_FAILED', $realm, 'account_ip', $ip);
    }

    private function buckets(string $realm, string $account, string $ip): array
    {
        $acct = self::normalizeAccount($account);
        $max = $this->maxAttempts();
        return [
            [hash('sha256', "acct|{$realm}|{$acct}|{$ip}"), 'account_ip', $max],
            [hash('sha256', "ip|{$ip}"), 'ip', $max * self::IP_MULTIPLIER],
        ];
    }

    private function reserve(string $key, string $scope, int $limit, int $now): array
    {
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        try {
            $this->db->prepare("INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (:k, :s, 0, :n, NULL, :n2)")
                ->execute(['k' => $key, 's' => $scope, 'n' => $nowStr, 'n2' => $nowStr]);
        } catch (\PDOException) {
            // já existe (UNIQUE): segue
        }

        // Janela expirada e sem bloqueio vigente: recomeça a contagem (sem bloqueio permanente).
        $this->db->prepare("UPDATE login_attempts SET failures = 0, locked_until = NULL, window_started_at = :n
            WHERE bucket_key = :k AND window_started_at < :cutoff AND (locked_until IS NULL OR locked_until <= :n2)")
            ->execute(['n' => $nowStr, 'k' => $key, 'cutoff' => $cutoff, 'n2' => $nowStr]);

        $row = $this->db->prepare("SELECT failures, locked_until FROM login_attempts WHERE bucket_key = :k");
        $row->execute(['k' => $key]);
        $cur = $row->fetch(PDO::FETCH_ASSOC) ?: ['failures' => 0, 'locked_until' => null];
        $over = max(0, (int)$cur['failures'] + 1 - $limit);
        $lockSeconds = min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS * (2 ** min($over, 10)));
        $lockUntil = gmdate('Y-m-d H:i:s', $now + $lockSeconds);

        // Atômico: locked_until é atribuído ANTES de failures (MySQL avalia SET da esquerda p/ direita;
        // SQLite usa valores antigos) — nos dois casos a expressão enxerga o valor antigo de failures.
        $upd = $this->db->prepare("UPDATE login_attempts
            SET locked_until = CASE WHEN failures + 1 >= CAST(:lim AS SIGNED) THEN :lu ELSE locked_until END,
                failures = failures + 1,
                last_attempt_at = :n
            WHERE bucket_key = :k AND (locked_until IS NULL OR locked_until <= :n2)");
        $upd->execute(['lim' => $limit, 'lu' => $lockUntil, 'n' => $nowStr, 'k' => $key, 'n2' => $nowStr]);

        if ($upd->rowCount() === 0) {
            $row->execute(['k' => $key]);
            $lu = (string)($row->fetchColumn(1) ?: '');
            $retry = $lu !== '' ? max(1, strtotime($lu . ' UTC') - $now) : self::BASE_LOCK_SECONDS;
            return ['allowed' => false, 'retry_after' => $retry];
        }
        return ['allowed' => true, 'retry_after' => 0];
    }

    private function audit(string $action, string $realm, string $scope, string $ip): void
    {
        try {
            $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                VALUES (NULL, NULL, :a, 'auth', NULL, :ip, :ua, :d)")
                ->execute([
                    'a' => $action,
                    'ip' => $ip,
                    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                    'd' => json_encode(['realm' => $realm, 'scope' => $scope]),
                ]);
        } catch (\Throwable) {
            // auditoria nunca derruba o login
        }
    }
}
