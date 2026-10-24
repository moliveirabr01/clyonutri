<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Notification;

/**
 * NI2-95: cliente do Expo Push Service (https://docs.expo.dev/push-notifications/sending-notifications/).
 *
 * - Só HTTPS com curl, sem worker: o cron de cada minuto chama send() e receipts().
 * - Até 100 mensagens por requisição (limite do Expo) e até 1000 ids por consulta de recibo.
 * - Desligado por padrão: PUSH_ENABLED=true no .env liga. EXPO_ACCESS_TOKEN é opcional (só se o projeto no Expo
 *   exigir "enhanced security" para push); o valor nunca aparece em log nem em resposta.
 * - O transporte pode ser trocado nos testes (closure que recebe url, corpo JSON e cabeçalhos).
 */
final class ExpoPushClient
{
    public const SEND_URL = 'https://exp.host/--/api/v2/push/send';
    public const RECEIPTS_URL = 'https://exp.host/--/api/v2/push/getReceipts';
    public const MAX_MESSAGES_PER_REQUEST = 100;
    public const MAX_RECEIPTS_PER_REQUEST = 1000;
    public const TOKEN_PATTERN = '/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]{10,200}\]$/';

    /** @var \Closure(string, string, array<int, string>): array{status: int, body: string} */
    private \Closure $transport;

    public function __construct(?\Closure $transport = null, private ?string $accessToken = null)
    {
        $this->transport = $transport ?? \Closure::fromCallable([$this, 'curl']);
        $this->accessToken ??= self::env('EXPO_ACCESS_TOKEN');
    }

    public static function enabled(): bool
    {
        return in_array(strtolower(trim((string)self::env('PUSH_ENABLED'))), ['1', 'true', 'on', 'yes', 'sim'], true);
    }

    public static function validToken(string $token): bool
    {
        return (bool)preg_match(self::TOKEN_PATTERN, $token);
    }

    /**
     * Envia até 100 mensagens. Resultado:
     * - ok=true e tickets na mesma ordem das mensagens ({status: ok, id} ou {status: error, error, message});
     * - ok=false com retryable (rede, 429, 5xx) ou não (400/401/403: requisição recusada).
     *
     * @param list<array<string, mixed>> $messages
     * @return array{ok: bool, retryable: bool, error: ?string, tickets: list<array{status: string, id: ?string, error: ?string}>}
     */
    public function send(array $messages): array
    {
        if ($messages === [] || count($messages) > self::MAX_MESSAGES_PER_REQUEST) {
            throw new \InvalidArgumentException('Envie de 1 a 100 mensagens por vez.');
        }
        $res = $this->post(self::SEND_URL, array_values($messages));
        if (!$res['ok']) {
            return $res + ['tickets' => []];
        }
        $data = $res['json']['data'] ?? null;
        if (!is_array($data) || count($data) !== count($messages)) {
            return ['ok' => false, 'retryable' => true, 'error' => 'push_bad_response', 'tickets' => []];
        }
        $tickets = [];
        foreach (array_values($data) as $t) {
            $tickets[] = [
                'status' => ($t['status'] ?? '') === 'ok' ? 'ok' : 'error',
                'id' => isset($t['id']) && is_string($t['id']) ? substr($t['id'], 0, 64) : null,
                'error' => ($t['status'] ?? '') === 'ok' ? null : self::errorCode($t['details']['error'] ?? 'PushError'),
            ];
        }
        return ['ok' => true, 'retryable' => false, 'error' => null, 'tickets' => $tickets];
    }

    /**
     * @param list<string> $ticketIds
     * @return array{ok: bool, retryable: bool, error: ?string, receipts: array<string, array{status: string, error: ?string}>}
     */
    public function receipts(array $ticketIds): array
    {
        if ($ticketIds === [] || count($ticketIds) > self::MAX_RECEIPTS_PER_REQUEST) {
            throw new \InvalidArgumentException('Consulte de 1 a 1000 recibos por vez.');
        }
        $res = $this->post(self::RECEIPTS_URL, ['ids' => array_values($ticketIds)]);
        if (!$res['ok']) {
            return $res + ['receipts' => []];
        }
        $out = [];
        foreach ((array)($res['json']['data'] ?? []) as $id => $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[(string)$id] = [
                'status' => ($r['status'] ?? '') === 'ok' ? 'ok' : 'error',
                'error' => ($r['status'] ?? '') === 'ok' ? null : self::errorCode($r['details']['error'] ?? 'PushError'),
            ];
        }
        return ['ok' => true, 'retryable' => false, 'error' => null, 'receipts' => $out];
    }

    /** Erro do Expo em snake_case curto para gravar no banco (DeviceNotRegistered → device_not_registered). */
    public static function errorCode(mixed $error): string
    {
        $e = preg_replace('/[^A-Za-z]/', '', (string)$error) ?: 'PushError';
        return substr(strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $e)), 0, 60);
    }

    /** @return array{ok: bool, retryable: bool, error: ?string, json?: array} */
    private function post(string $url, array $payload): array
    {
        $headers = ['Accept: application/json', 'Accept-Encoding: gzip, deflate', 'Content-Type: application/json'];
        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        try {
            $r = ($this->transport)($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $headers);
        } catch (\Throwable $e) {
            return ['ok' => false, 'retryable' => true, 'error' => 'push_network_error'];
        }
        $status = (int)($r['status'] ?? 0);
        $json = json_decode((string)($r['body'] ?? ''), true);
        if ($status === 0) {
            return ['ok' => false, 'retryable' => true, 'error' => 'push_network_error'];
        }
        if ($status === 429 || $status >= 500) {
            return ['ok' => false, 'retryable' => true, 'error' => $status === 429 ? 'push_rate_limited' : 'push_server_error'];
        }
        if ($status !== 200 || !is_array($json)) {
            return ['ok' => false, 'retryable' => false, 'error' => 'push_rejected'];
        }
        return ['ok' => true, 'retryable' => false, 'error' => null, 'json' => $json];
    }

    /** @return array{status: int, body: string} */
    private function curl(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl indisponível');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $status = $resp === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $resp === false ? '' : (string)$resp];
    }

    private static function env(string $key): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_ENV[$key] ?? null;
        }
        return $v === null ? null : (string)$v;
    }
}
