<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Security\Csrf;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path) . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Validação CSRF em requisições de mutação
        if ($method === 'POST') {
            $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!Csrf::validate($token)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'CSRF token inválido ou ausente. Ação bloqueada por segurança.']);
                return;
            }
        }

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
                $handler = $route['handler'];

                if (is_callable($handler)) {
                    call_user_func($handler, $params);
                    return;
                }

                if (is_array($handler) && count($handler) === 2) {
                    [$class, $methodName] = $handler;
                    $controller = new $class();
                    $controller->$methodName($params);
                    return;
                }
            }
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Rota não encontrada', 'path' => $path]);
    }
}
