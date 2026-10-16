<?php
declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Security\Env;

/**
 * v10 (achado "Endpoint público de diagnóstico"): nunca mostrar erro, stack trace, caminho de
 * arquivo ou versão para o público em produção, mesmo com APP_DEBUG=true no .env.
 * Saída de depuração só é permitida quando APP_ENV != production E a requisição vem de loopback.
 */
final class ErrorExposure
{
    public static function debugOutputAllowed(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        if (Transport::isProduction()) {
            return false;
        }
        $debug = in_array(strtolower(trim((string)(Env::get('APP_DEBUG', '') ?? ''))), ['1', 'true', 'on', 'yes'], true);
        if (!$debug) {
            return false;
        }
        $ip = (string)($s['REMOTE_ADDR'] ?? '');
        return PHP_SAPI === 'cli' || $ip === '127.0.0.1' || $ip === '::1';
    }

    /** Chamado no início do front controller (antes de qualquer saída). */
    public static function configure(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (function_exists('header_remove') && !headers_sent()) {
            header_remove('X-Powered-By');
        }
        if (self::debugOutputAllowed()) {
            return;
        }
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('html_errors', '0');
        ini_set('log_errors', '1');
        ini_set('expose_php', '0');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/api/')) {
            return; // ApiErrorBoundary cuida da API (JSON genérico com request_id)
        }
        set_exception_handler(static function (\Throwable $e): void {
            $id = bin2hex(random_bytes(6));
            error_log(json_encode(['request_id' => $id, 'exception' => get_class($e),
                'file' => basename($e->getFile()), 'line' => $e->getLine(), 'message' => $e->getMessage()]));
            self::renderGeneric500($id);
        });
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $id = bin2hex(random_bytes(6));
                error_log(json_encode(['request_id' => $id, 'event' => 'FATAL', 'file' => basename((string)$err['file']), 'line' => $err['line']]));
                self::renderGeneric500($id);
            }
        });
    }

    public static function renderGeneric500(string $id): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Erro - ClyoNutri</title></head><body>'
            . '<h1>Não foi possível concluir a solicitação.</h1><p>Tente novamente em instantes. Código: '
            . htmlspecialchars($id) . '</p></body></html>';
    }
}
