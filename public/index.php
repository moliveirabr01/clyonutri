<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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

// Garantir inicialização do banco SQLite local de desenvolvimento
$db = Connection::getInstance();

// Auto-seed para visualização imediata se vazio
$tacoSeed = new FoodCatalogService($db);
$tacoSeed->seedTacoReference();

// Inicializar contexto demo seguro se não autenticado
if (!TenantContext::hasClinic()) {
    $stmt = $db->query("SELECT id FROM clinics LIMIT 1");
    $clinicId = $stmt->fetchColumn();
    if (!$clinicId) {
        $auth = new AuthService($db);
        $res = $auth->registerNutritionist('Dra. Camila Mendes', 'camila@clyonutri.com.br', 'SenhaDemo123', 'Clínica ClyoNutri Demo', '12345', 'CRN-3');
        $clinicId = $res['clinic_id'];
        $userId = $res['user_id'];
    } else {
        $uStmt = $db->query("SELECT id, name FROM users LIMIT 1");
        $u = $uStmt->fetch();
        $userId = (int)$u['id'];
    }

    TenantContext::set((int)$clinicId, ['id' => $userId, 'name' => 'Dra. Camila Mendes']);

    // Criar paciente demo se não houver
    $pRepo = new PatientRepository($db);
    if (count($pRepo->listAll()) === 0) {
        $pRepo->create([
            'name' => 'Roberto da Silva',
            'email' => 'roberto@email.com',
            'phone' => '(11) 98888-7777',
            'birth_date' => '1990-05-15',
            'gender' => 'masculino',
            'lgpd_consent' => true,
        ]);
        $pRepo->create([
            'name' => 'Larissa Antunes',
            'email' => 'larissa@email.com',
            'phone' => '(11) 97777-6666',
            'birth_date' => '1995-08-20',
            'gender' => 'feminino',
            'lgpd_consent' => true,
        ]);
    }
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

// Telas Principais do Design System & Portal Profissional
$dashCtrl = new DashboardController();

$router->get('/', [$dashCtrl, 'index']);
$router->get('/dashboard', [$dashCtrl, 'index']);
$router->get('/pacientes', [$dashCtrl, 'patients']);
$router->get('/alimentos', [$dashCtrl, 'foods']);

$router->get('/agenda', function () {
    header('Location: /dashboard');
});

$router->get('/planos', function () {
    header('Location: /pacientes');
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$router->dispatch($method, $uri);
