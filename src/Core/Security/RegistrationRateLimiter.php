<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/**
 * Limite de cadastros de profissional por IP (NI2-479 / SEC-01).
 *
 * Protege o POST /cadastro contra criação em massa de contas e contra gasto de CPU com o bcrypt (custo 12).
 *
 * - Conta só os envios que chegariam a criar a conta: o controller chama attempt() depois do CSRF e da
 *   validação do formulário, logo antes do cadastro. Erro de digitação, campo vazio ou token vencido não
 *   gastam a cota (nesses casos o bcrypt nem roda).
 * - Janela fixa de 1 hora por IP; limite padrão 10 (REGISTER_MAX_PER_HOUR no .env, de 1 a 100). O valor é
 *   folgado de propósito: várias pessoas podem sair pelo mesmo IP (rede da clínica, operadora com CGNAT).
 * - Reserva atômica (UPDATE condicional): requisições simultâneas não passam do limite.
 * - Usa a tabela login_attempts (scope 'register_ip'), que já tem limpeza periódica
 *   (LoginRateLimiter::purgeStale); o IP é guardado só como hash SHA-256.
 * - Ao estourar, registra REGISTER_RATE_LIMITED em audit_logs.
 */
final class RegistrationRateLimiter
{
    public const SCOPE = 'register_ip';
    public const WINDOW_SECONDS = 3600;
    public const DEFAULT_MAX_PER_HOUR = 10;

    /** @var callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null, private ?int $maxPerHour = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function bucketKey(string $ip): string
    {
        return hash('sha256', self::SCOPE . '|' . $ip);
    }

    public function maxPerHour(): int
    {
        if ($this->maxPerHour !== null) {
            return max(1, $this->maxPerHour);
        }
        $env = (int)(Env::get('REGISTER_MAX_PER_HOUR') ?? 0);
        return ($env >= 1 && $env <= 100) ? $env : self::DEFAULT_MAX_PER_HOUR;
    }

    /**
     * Reserva um cadastro para o IP. Retorna ['allowed' => bool, 'retry_after' => segundos].
     */
    public function attempt(string $ip): array
    {
        $now = ($this->clock)();
        $key = self::bucketKey($ip);
        $limit = $this->maxPerHour();
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        try {
            $this->db->prepare('INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (?, ?, 0, ?, NULL, ?)')
                ->execute([$key, self::SCOPE, $nowStr, $nowStr]);
        } catch (\PDOException) {
            // já existe: segue
        }
        // Janela vencida: recomeça a contagem.
        $this->db->prepare('UPDATE login_attempts SET failures = 0, window_started_at = ? WHERE bucket_key = ? AND window_started_at < ?')
            ->execute([$nowStr, $key, $cutoff]);
        // Reserva atômica: só incrementa enquanto estiver abaixo do limite.
        $upd = $this->db->prepare('UPDATE login_attempts SET failures = failures + 1, last_attempt_at = ? WHERE bucket_key = ? AND failures < ?');
        $upd->execute([$nowStr, $key, $limit]);
        if ($upd->rowCount() === 1) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $st = $this->db->prepare('SELECT window_started_at FROM login_attempts WHERE bucket_key = ?');
        $st->execute([$key]);
        $started = strtotime((string)$st->fetchColumn() . ' UTC') ?: $now;
        $retry = max(60, $started + self::WINDOW_SECONDS - $now);
        $this->audit($ip, $retry, $limit);
        return ['allowed' => false, 'retry_after' => $retry];
    }

    private function audit(string $ip, int $retryAfter, int $limit): void
    {
        try {
            $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                VALUES (NULL, NULL, 'REGISTER_RATE_LIMITED', 'auth', NULL, ?, ?, ?)")
                ->execute([
                    $ip,
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                    json_encode(['limit_per_hour' => $limit, 'retry_after' => $retryAfter]),
                ]);
        } catch (\Throwable) {
            // auditoria nunca derruba a resposta
        }
    }
}
