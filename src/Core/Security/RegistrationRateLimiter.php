<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/**
 * Limitador persistente de tentativas de cadastro por IP (NI2-479 / SEC-01).
 *
 * - Protege o endpoint POST /cadastro contra exaustão de CPU (custo 12 do Bcrypt)
 *   e criação em massa de contas fantasmas por automações não autorizadas.
 * - Utiliza a tabela atômica `login_attempts` com o scope 'register_ip'.
 * - Limite padrão: 5 requisições por hora por IP (janela de 3600 segundos).
 * - Ao exceder o limite, aplica bloqueio temporário progressivo com Retry-After.
 * - Auditoria de incidentes registrada na tabela `audit_logs` com IP e User-Agent.
 */
final class RegistrationRateLimiter
{
    public const WINDOW_SECONDS = 3600;
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const BASE_LOCK_SECONDS = 300;
    public const MAX_LOCK_SECONDS = 3600;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private PDO $db,
        ?callable $clock = null,
        private ?int $maxAttempts = null
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function bucketKey(string $ip): string
    {
        return hash('sha256', "register_ip|{$ip}");
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Tenta reservar uma cota de registro para o IP informado.
     * Retorna ['allowed' => bool, 'retry_after' => int, 'remaining' => int].
     */
    public function attempt(string $ip): array
    {
        $now = ($this->clock)();
        $key = self::bucketKey($ip);
        $limit = $this->maxAttempts();

        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        try {
            $this->db->prepare("INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (:k, 'register_ip', 0, :n, NULL, :n2)")
                ->execute(['k' => $key, 'n' => $nowStr, 'n2' => $nowStr]);
        } catch (\PDOException) {
            // Registro já existe na chave primária bucket_key
        }

        // Se a janela expirou e não há bloqueio vigente, reinicia a contagem
        $this->db->prepare("UPDATE login_attempts SET failures = 0, locked_until = NULL, window_started_at = :n
            WHERE bucket_key = :k AND window_started_at < :cutoff AND (locked_until IS NULL OR locked_until <= :n2)")
            ->execute(['n' => $nowStr, 'k' => $key, 'cutoff' => $cutoff, 'n2' => $nowStr]);

        $row = $this->db->prepare("SELECT failures, locked_until FROM login_attempts WHERE bucket_key = :k");
        $row->execute(['k' => $key]);
        $cur = $row->fetch(PDO::FETCH_ASSOC) ?: ['failures' => 0, 'locked_until' => null];

        $over = max(0, (int)$cur['failures'] + 1 - $limit);
        $lockSeconds = min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS * (2 ** min($over, 6)));
        $lockUntil = gmdate('Y-m-d H:i:s', $now + $lockSeconds);

        // Atualização atômica condicional: bloqueia se atingir ou exceder o limite
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
            $this->audit($ip, $retry);
            return [
                'allowed' => false,
                'retry_after' => $retry,
                'remaining' => 0,
            ];
        }

        $remaining = max(0, $limit - ((int)$cur['failures'] + 1));
        return [
            'allowed' => true,
            'retry_after' => 0,
            'remaining' => $remaining,
        ];
    }

    /**
     * Limpa o bucket de um IP (útil para testes unitários / sanitização de fixtures).
     */
    public function reset(string $ip): void
    {
        $this->db->prepare("DELETE FROM login_attempts WHERE bucket_key = :k AND scope = 'register_ip'")
            ->execute(['k' => self::bucketKey($ip)]);
    }

    private function audit(string $ip, int $retryAfter): void
    {
        try {
            $st = $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, details, ip_address, user_agent) VALUES (NULL, NULL, 'REGISTER_RATE_LIMITED', 'auth', :d, :ip, :ua)");
            $st->execute([
                'd' => json_encode([
                    'retry_after' => $retryAfter,
                    'limit' => $this->maxAttempts(),
                ], JSON_UNESCAPED_SLASHES),
                'ip' => $ip,
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        } catch (\Throwable) {
            // Falhas de auditoria não interrompem a resposta de bloqueio
        }
    }
}
