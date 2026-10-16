<?php
declare(strict_types=1);
namespace ClyoNutri\Core\Http;

final class ApiErrorBoundary
{
    public static function register(): void
    {
        if (!str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/api/')) return;
        $requestId = bin2hex(random_bytes(12));
        ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-ID: ' . $requestId);
        header('Cache-Control: no-store');
        $failed = false;
        $fail = static function (\Throwable $e) use ($requestId, &$failed): void {
            if ($e instanceof \ClyoNutri\Core\Security\TenantSecurityException) {
                http_response_code($e->getHttpStatusCode());
                echo json_encode(['error'=>'Acesso não autorizado.']);
                return;
            }
            $failed = true;
            http_response_code(500);
            // Do not log credentials, request bodies or clinical data.
            error_log(json_encode(['request_id' => $requestId, 'exception' => get_class($e),
                'file' => basename($e->getFile()), 'line' => $e->getLine(), 'message' => $e->getMessage()]));
        };
        ob_start(static function (string $body) use ($requestId, &$failed): string {
            $status = http_response_code();
            // v13: respostas sem corpo (preflight OPTIONS 204, 304) não são JSON inválido.
            if (!$failed && ($status === 204 || $status === 304)) {
                return '';
            }
            $isJson = false;
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type: application/json') === 0) $isJson = true;
            }
            if ($status < 400 && $isJson) {
                json_decode($body);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(500);
                    $status = 500;
                }
            }
            if ($failed || $status >= 500) {
                if (!$failed) error_log(json_encode(['request_id' => $requestId, 'status' => $status, 'event' => 'API_ERROR_RESPONSE']));
                header('Content-Type: application/json; charset=utf-8');
                return json_encode(['error' => 'Não foi possível concluir a solicitação. Tente novamente.',
                    'code' => 'INTERNAL_ERROR', 'request_id' => $requestId], JSON_UNESCAPED_UNICODE);
            }
            if ($status >= 400) {
                $data = json_decode($body, true);
                if (!is_array($data)) $data = ['error' => 'Não foi possível concluir a solicitação.'];
                $data['request_id'] = $requestId;
                $data['code'] = $data['code'] ?? ('HTTP_' . $status);
                return json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            return $body;
        });
        set_error_handler(static function ($severity, $message, $file, $line): bool {
            if (!(error_reporting() & $severity)) return false;
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler($fail);
        register_shutdown_function(static function () use ($fail): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $fail(new \ErrorException('Fatal API failure', 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }
}
