<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Http\Router;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\Env;

Env::load(__DIR__ . '/../.env');

// Configuração segura de cookies de sessão
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 7200,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}

$router = new Router();

// Rota de Health Check
$router->get('/health', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'ClyoNutri API & Web Engine',
        'version' => '1.0.0',
        'timestamp' => date('c'),
    ]);
});

// Página Inicial / Auth status
$router->get('/', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'app' => 'ClyoNutri',
        'tagline' => 'Sistema Operacional do Nutricionista',
        'version' => '1.0.0',
        'csrf_token' => Csrf::token(),
    ]);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$router->dispatch($method, $uri);
