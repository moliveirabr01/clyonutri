<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;

/**
 * Limite de requisições por IP nas rotas públicas de agendamento e vitrine de nutricionistas (SEC-F003 / NI2-P2).
 *
 * Protege /nutricionistas, /agendar/{prof}, /api/v1/booking/slots, /api/v1/booking/holds e
 * /api/v1/booking/confirm contra raspagem automatizada, enumeração em massa de horários e DoS/abuso.
 *
 * - Janela deslizante atômica de 60 segundos por ação e por IP (armazenado apenas como hash SHA-256).
 * - Utiliza a tabela login_attempts (scope 'public_booking'), com purga periódica automática.
 * - Registra evento PUBLIC_BOOKING_RATE_LIMITED em audit_logs quando o limite é excedido.
 * - Responde com HTTP 429 Too Many Requests e cabeçalho Retry-After.
 */
final class PublicBookingRateLimiter
{
    public const SCOPE = 'public_booking';
    public const WINDOW_SECONDS = 60;

    /** Limites padrão por minuto (60 segundos). */
    public const DEFAULT_LIMITS = [
        'directory' => 60,
        'booking_page' => 60,
        'slots' => 60,
        'hold' => 20,
        'confirm' => 15,
        'waitlist' => 15,
    ];

    /** @var callable():int */
    private $clock;

    public function __construct(private PDO $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function bucketKey(string $action, string $ip): string
    {
        return hash('sha256', self::SCOPE . '|' . $action . '|' . $ip);
    }

    public function limitForAction(string $action): int
    {
        $envKey = 'PUBLIC_BOOKING_RATE_LIMIT_' . strtoupper($action);
        $val = getenv($envKey);
        if (is_string($val) && ctype_digit($val) && (int)$val > 0) {
            return (int)$val;
        }

        $general = getenv('PUBLIC_BOOKING_RATE_LIMIT_PER_MINUTE');
        if (is_string($general) && ctype_digit($general) && (int)$general > 0) {
            return (int)$general;
        }

        return self::DEFAULT_LIMITS[$action] ?? 60;
    }

    /**
     * Reserva uma requisição. Retorna ['allowed' => bool, 'retry_after' => int, 'limit' => int].
     */
    public function attempt(string $action, string $ip): array
    {
        $limit = $this->limitForAction($action);
        $now = ($this->clock)();
        $key = self::bucketKey($action, $ip);
        $nowStr = gmdate('Y-m-d H:i:s', $now);
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        try {
            $this->db->prepare('INSERT INTO login_attempts (bucket_key, scope, failures, window_started_at, locked_until, last_attempt_at) VALUES (?, ?, 0, ?, NULL, ?)')
                ->execute([$key, self::SCOPE, $nowStr, $nowStr]);
        } catch (\PDOException $e) {
            if (!in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                // Em caso de falha de conexão/driver, fail-closed por segurança
                return ['allowed' => false, 'retry_after' => 30, 'limit' => $limit];
            }
        }

        // Janela expirada: reseta a contagem
        $this->db->prepare('UPDATE login_attempts SET failures = 0, window_started_at = ? WHERE bucket_key = ? AND window_started_at < ?')
            ->execute([$nowStr, $key, $cutoff]);

        // Incremento atômico condicional
        $upd = $this->db->prepare('UPDATE login_attempts SET failures = failures + 1, last_attempt_at = ? WHERE bucket_key = ? AND failures < ?');
        $upd->execute([$nowStr, $key, $limit]);
        if ($upd->rowCount() === 1) {
            return ['allowed' => true, 'retry_after' => 0, 'limit' => $limit];
        }

        $st = $this->db->prepare('SELECT window_started_at FROM login_attempts WHERE bucket_key = ?');
        $st->execute([$key]);
        $started = (string)$st->fetchColumn();
        $startedTs = $started !== '' ? (int)strtotime($started . ' UTC') : $now;
        $retryAfter = max(1, $startedTs + self::WINDOW_SECONDS - $now);

        $this->audit($action, $ip, $retryAfter, $limit);

        return ['allowed' => false, 'retry_after' => $retryAfter, 'limit' => $limit];
    }

    private function audit(string $action, string $ip, int $retryAfter, int $limit): void
    {
        try {
            $this->db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                VALUES (NULL, NULL, 'PUBLIC_BOOKING_RATE_LIMITED', 'public_booking', ?, ?, ?, ?, ?)")
                ->execute([
                    $action,
                    $ip,
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                    json_encode(['action' => $action, 'limit_per_minute' => $limit, 'retry_after' => $retryAfter]),
                    date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable) {
            // Auditoria não derruba a requisição
        }
    }

    /**
     * Valida a taxa da requisição. Se ultrapassar o limite, envia resposta HTTP 429 e retorna false.
     * Se estiver dentro do limite, retorna true.
     */
    public static function checkOrRespond(PDO $db, string $action, string $format = 'html', ?string $ip = null): bool
    {
        $clientIp = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $limiter = new self($db);
        $result = $limiter->attempt($action, $clientIp);

        if ($result['allowed']) {
            return true;
        }

        $retryAfter = (int)$result['retry_after'];

        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            header('Cache-Control: no-store');
        }

        if ($format === 'json') {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'error' => 'Muitas requisições em pouco tempo. Aguarde antes de tentar novamente.',
                'code' => 'RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }

        // Formato HTML com tokens do Botanical Design System
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Muitas tentativas · Clyo Nutri</title>'
            . '<link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">'
            . '<style>'
            . 'body { font-family: var(--font-family-base, system-ui, sans-serif); background: var(--botanical-cream-50, #FAF9F4); color: var(--botanical-forest-900, #0F3E17); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }'
            . '.card-rate-limit { max-width: 520px; width: 100%; background: #ffffff; border-radius: 12px; border: 1px solid var(--border-default, #E6E8EB); box-shadow: 0 4px 16px rgba(0,0,0,0.06); padding: 32px; text-align: center; }'
            . '.icon-badge { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 50%; background: var(--semantic-warning-light, #FEF3C7); color: var(--semantic-warning-strong, #7A4A00); margin-bottom: 20px; font-size: 24px; font-weight: bold; }'
            . 'h1 { font-size: 1.35rem; margin: 0 0 12px; color: var(--botanical-forest-900, #0F3E17); }'
            . 'p { font-size: 0.95rem; line-height: 1.5; color: var(--neutral-gray-600, #4B5563); margin: 0 0 20px; }'
            . '.btn-retry { display: inline-block; background: var(--brand-copper-600, #B04A1B); color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: background 0.2s; }'
            . '.btn-retry:hover { background: var(--brand-copper-700, #8F3A14); }'
            . '</style></head>'
            . '<body><div class="card-rate-limit">'
            . '<div class="icon-badge">!</div>'
            . '<h1>Muitas requisições em pouco tempo</h1>'
            . '<p>Por segurança e estabilidade do serviço, as consultas foram pausadas temporariamente para o seu endereço IP. Por favor, aguarde ' . $retryAfter . ' segundos e tente recarregar a página.</p>'
            . '<a href="javascript:location.reload()" class="btn-retry">Tentar novamente</a>'
            . '</div></body></html>';

        return false;
    }
}
