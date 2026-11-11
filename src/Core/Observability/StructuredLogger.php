<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Observability;

/**
 * v54.46 / CR-NI2-010 (NI2-723): log estruturado em JSON de uma linha, com request_id por requisição.
 *
 * - Saída: error_log() (no HostGator vai para o error_log do PHP/cPanel); uma linha JSON por evento.
 * - Nunca registra payload clínico: chaves de contexto com nome clínico/sensível são descartadas e
 *   strings são truncadas. Passe apenas ids numéricos, classes de exceção e contadores.
 * - request_id: reaproveita X-Request-Id válido do proxy; senão gera 12 hex. Mesmo id em toda a requisição.
 */
final class StructuredLogger
{
    /** Chaves nunca aceitas no contexto (dados clínicos/pessoais/credenciais). */
    public const FORBIDDEN_KEYS = [
        'allergies', 'objectives', 'notes', 'symptoms', 'clinical_history', 'restrictions', 'habits',
        'routine', 'food_preferences', 'structured_data_json', 'diagnosis', 'name', 'email', 'cpf', 'phone',
        'password', 'token', 'secret', 'body', 'content', 'message_body',
    ];

    private static ?string $requestId = null;
    /** @var null|callable(string):void gancho de teste */
    public static $sink = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            $hdr = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
            self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $hdr) ? $hdr : bin2hex(random_bytes(6));
        }
        return self::$requestId;
    }

    public static function resetForTesting(): void
    {
        self::$requestId = null;
    }

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $event, array $context = []): string
    {
        $line = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => strtolower($level),
            'event' => preg_replace('/[^A-Z0-9_]/', '_', strtoupper($event)),
            'request_id' => self::requestId(),
        ];
        foreach ($context as $k => $v) {
            $k = (string)$k;
            if (in_array(strtolower($k), self::FORBIDDEN_KEYS, true) || isset($line[$k])) {
                continue;
            }
            if ($v instanceof \Throwable) {
                $line[$k] = get_class($v);
            } elseif (is_scalar($v) || $v === null) {
                $line[$k] = is_string($v) ? mb_substr($v, 0, 200) : $v;
            }
        }
        $json = (string)json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (self::$sink !== null) {
            (self::$sink)($json);
        } else {
            error_log($json);
        }
        return $json;
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $event, array $context = []): string
    {
        return self::log('warning', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $event, array $context = []): string
    {
        return self::log('error', $event, $context);
    }
}
