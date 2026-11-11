<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/**
 * Limite de ações sensíveis da conta do consultório (v37): trocar de plano, cancelar a assinatura e pedir ou
 * retirar a exclusão da conta.
 *
 * - Janela fixa por ação e por identidade (clínica + usuário). Reserva atômica (UPDATE condicional): requisições
 *   simultâneas não passam do limite.
 * - Usa a tabela login_attempts (scope 'acct_action'), que já tem limpeza periódica (LoginRateLimiter::purgeStale).
 *   A identidade só é guardada como hash SHA-256.
 * - Cada envio do pedido de exclusão gasta uma tentativa (acerte ou erre a senha), então a senha não pode ser
 *   adivinhada em série: 5 envios por hora e por usuário.
 * - Se a tabela de limites falhar, a ação NÃO é liberada (falha fechada) e quem chama mostra uma mensagem de erro.
 */
final class AccountActionRateLimiter
{
    public const SCOPE = 'acct_action';

    /** Ação => [limite, janela em segundos]. */
    public const LIMITS = [
        'plan_change' => [10, 3600],
        'plan_cancel' => [5, 3600],
        'deletion_request' => [5, 3600],
        'deletion_cancel' => [10, 3600],
    ];

    /** @var callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function bucketKey(string $action, int $clinicId, int $userId): string
    {
        return hash('sha256', self::SCOPE . '|' . $action . '|' . $clinicId . '|' . $userId);
    }

    /**
     * Reserva uma tentativa. Retorna ['allowed' => bool, 'retry_after' => segundos].
     */
    public function attempt(string $action, int $clinicId, int $userId): array
    {
        [$limit, $window] = self::LIMITS[$action] ?? [5, 3600];
        $now = ($this->clock)();
        $key = self::bucketKey($action, $clinicId, $userId);
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - $window);

        try {
            $this->db->prepare('INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (?, ?, 0, ?, NULL, ?)')
                ->execute([$key, self::SCOPE, $nowStr, $nowStr]);
        } catch (\PDOException $e) {
            if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
        }
        $this->db->prepare('UPDATE login_attempts SET failures = 0, window_started_at = ? WHERE bucket_key = ? AND window_started_at < ?')
            ->execute([$nowStr, $key, $cutoff]);
        $upd = $this->db->prepare('UPDATE login_attempts SET failures = failures + 1, last_attempt_at = ? WHERE bucket_key = ? AND failures < ?');
        $upd->execute([$nowStr, $key, $limit]);
        if ($upd->rowCount() === 1) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $st = $this->db->prepare('SELECT window_started_at FROM login_attempts WHERE bucket_key = ?');
        $st->execute([$key]);
        $started = (string)$st->fetchColumn();
        $startedTs = $started !== '' ? (int)strtotime($started . ' UTC') : $now;

        return ['allowed' => false, 'retry_after' => max(1, $startedTs + $window - $now)];
    }

    /** Texto curto para o usuário, com o tempo de espera arredondado para cima em minutos. */
    public static function waitMessage(int $retryAfter): string
    {
        $min = max(1, (int)ceil($retryAfter / 60));

        return 'Muitas tentativas em pouco tempo. Aguarde ' . $min . ' ' . ($min === 1 ? 'minuto' : 'minutos') . ' e tente de novo.';
    }
}
