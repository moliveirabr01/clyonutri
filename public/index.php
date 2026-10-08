<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\AuthController;
use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Router;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Patient\PatientRepository;

Env::load(__DIR__ . '/../.env');

// Inicialização de conexão
$db = Connection::getInstance();

// Auto-seed inicial de referência
$tacoSeed = new FoodCatalogService($db);
$tacoSeed->seedTacoReference();

// Garantir usuário demo existente para testes
$auth = new AuthService($db);
$stmt = $db->query("SELECT id FROM users WHERE email = 'camila@clyonutri.com.br' LIMIT 1");
if (!$stmt->fetch()) {
    $auth->registerNutritionist(
        'Dra. Camila Mendes',
        'camila@clyonutri.com.br',
        'SenhaDemo123',
        'Clínica ClyoNutri Demo',
        '12345',
        'CRN-3'
    );
}

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

// Carregar TenantContext a partir da sessão se o usuário estiver autenticado
if (!empty($_SESSION['clinic_id']) && !empty($_SESSION['user_id'])) {
    TenantContext::set((int)$_SESSION['clinic_id'], [
        'id' => (int)$_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? 'Nutricionista',
    ]);
}

$router = new Router();
$authCtrl = new AuthController();
$dashCtrl = new DashboardController();

// 1. Rotas de Autenticação
$router->get('/login', [$authCtrl, 'showLogin']);
$router->post('/login', [$authCtrl, 'login']);
$router->get('/logout', [$authCtrl, 'logout']);

// 2. Health Check & Observabilidade (/up padrão Laravel 13)
$router->get('/up', function () {
    header('Content-Type: application/json; charset=utf-8');
    $dbOk = false;
    try {
        $db = Connection::getInstance();
        $db->query("SELECT 1");
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbOk = false;
    }

    $heartbeatFile = __DIR__ . '/../storage/scheduler_heartbeat.json';
    $heartbeatOk = false;
    if (file_exists($heartbeatFile)) {
        $data = json_decode((string)file_get_contents($heartbeatFile), true);
        if (!empty($data['timestamp']) && (time() - $data['timestamp'] < 1200)) {
            $heartbeatOk = true;
        }
    }

    $allOk = $dbOk;
    if (!$allOk) {
        http_response_code(503);
    } else {
        http_response_code(200);
    }

    echo json_encode([
        'status' => $allOk ? 'up' : 'degraded',
        'database' => $dbOk ? 'connected' : 'disconnected',
        'scheduler_heartbeat' => $heartbeatOk ? 'healthy' : 'pending_or_delayed',
        'version' => '1.0.0',
        'timestamp' => date('c'),
    ]);
});

$router->get('/health', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'ClyoNutri API & Web Engine',
        'version' => '1.0.0',
        'timestamp' => date('c'),
    ]);
});

// Middleware de proteção: se tentar acessar rotas privadas sem login, redireciona
$requireAuth = function (callable $action) {
    return function ($params = []) use ($action) {
        if (!TenantContext::hasClinic() || empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        $action($params);
    };
};

// 3. Rotas Protegidas do Sistema
$router->get('/', function () {
    if (empty($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    header('Location: /dashboard');
    exit;
});

$router->get('/dashboard', $requireAuth([$dashCtrl, 'index']));
$router->get('/pacientes', $requireAuth([$dashCtrl, 'patients']));
$router->get('/alimentos', $requireAuth([$dashCtrl, 'foods']));

$router->get('/agenda', $requireAuth(function () {
    header('Location: /dashboard');
}));

$router->get('/planos', $requireAuth(function () {
    header('Location: /pacientes');
}));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$router->dispatch($method, $uri);
