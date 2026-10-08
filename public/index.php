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

// 1. Rotas de Autenticação e Onboarding
$router->get('/login', [$authCtrl, 'showLogin']);
$router->post('/login', [$authCtrl, 'login']);
$router->get('/cadastro', [$authCtrl, 'showRegister']);
$router->post('/cadastro', [$authCtrl, 'register']);
$router->get('/onboarding', [$authCtrl, 'showRegister']);
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
$router->post('/pacientes', $requireAuth([$dashCtrl, 'savePatient']));
$router->get('/pacientes/{id}', $requireAuth([$dashCtrl, 'profile360']));
$router->get('/alimentos', $requireAuth([$dashCtrl, 'foods']));
$router->get('/agenda', $requireAuth([$dashCtrl, 'agenda']));
$router->get('/planos', $requireAuth([$dashCtrl, 'mealPlans']));
$router->get('/financeiro', $requireAuth([$dashCtrl, 'finance']));
$router->get('/configuracoes', $requireAuth([$dashCtrl, 'settings']));

// 4. API Móvel do Paciente (/api/v1/patient/...)
$authenticatePatientApi = function() use ($db): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado. Token Bearer ausente ou inválido.']);
        exit;
    }
    $plainToken = substr($authHeader, 7);
    $hashed = hash('sha256', $plainToken);

    $stmt = $db->prepare("
        SELECT t.*, p.id AS patient_id, p.clinic_id, p.name AS patient_name
        FROM personal_access_tokens t
        JOIN patients p ON p.id = t.tokenable_id
        WHERE (t.token = :raw OR t.token = :hash) AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)
        LIMIT 1
    ");
    $stmt->execute(['raw' => $plainToken, 'hash' => $hashed]);
    $tokenRow = $stmt->fetch();

    if (!$tokenRow) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de acesso inválido ou expirado.']);
        exit;
    }

    TenantContext::set((int)$tokenRow['clinic_id'], [
        'id' => (int)$tokenRow['patient_id'],
        'role' => 'patient',
        'name' => $tokenRow['patient_name'],
    ]);

    return $tokenRow;
};

// Login do Paciente via API (Mobile)
$router->post('/api/v1/auth/patient-login', function() use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? $input['cpf'] ?? '');

    $stmt = $db->prepare("SELECT * FROM patients WHERE (email = :email OR phone = :phone) AND status = 'active' LIMIT 1");
    $stmt->execute(['email' => $email, 'phone' => $phone]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(401);
        echo json_encode(['error' => 'Paciente não encontrado ou inativo.']);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $hashed = hash('sha256', $token);
    $ins = $db->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, created_at)
        VALUES ('patient', :pid, 'mobile_app', :token, CURRENT_TIMESTAMP)
    ");
    $ins->execute(['pid' => $patient['id'], 'token' => $hashed]);

    echo json_encode([
        'token' => $token,
        'patient' => [
            'id' => (int)$patient['id'],
            'clinic_id' => (int)$patient['clinic_id'],
            'name' => $patient['name'],
            'email' => $patient['email'],
        ],
    ]);
});

// Revogação de Token (Logout do Paciente)
$router->post('/api/v1/auth/patient-logout', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $tokenId = (int)$auth['id'];
    $stmt = $db->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
    $stmt->execute(['id' => $tokenId]);
    echo json_encode(['message' => 'Token revogado com sucesso.']);
});

// Home e Plano do Paciente
$router->get('/api/v1/patient/home', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];

    $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
    $plan = $apiCtrl->getDailyMealPlan($patientId);
    $nextApp = $apiCtrl->portal()->getNextAppointment($patientId);

    // Hidratação de hoje
    $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND date(logged_at) = date('now')");
    $hStmt->execute(['pid' => $patientId]);
    $waterToday = (int)$hStmt->fetchColumn();

    echo json_encode([
        'patient_name' => $auth['patient_name'],
        'water_ml_today' => $waterToday,
        'water_goal_ml' => 2500,
        'next_appointment' => $nextApp,
        'active_plan' => $plan,
    ]);
});

// Registrar Hidratação
$router->post('/api/v1/patient/hydration', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
    $amount = (int)($input['amount_ml'] ?? 250);

    $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
    $res = $apiCtrl->logHydration($patientId, $amount);

    // Retornar novo total acumulado hoje
    $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND date(logged_at) = date('now')");
    $hStmt->execute(['pid' => $patientId]);
    $res['total_water_today'] = (int)$hStmt->fetchColumn();

    echo json_encode($res);
});

// Confirmar Próxima Consulta
$router->post('/api/v1/patient/appointment/confirm', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
    $appointmentId = (int)($input['appointment_id'] ?? 0);

    $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
    $success = $apiCtrl->portal()->confirmAppointment($patientId, $appointmentId);
    echo json_encode(['success' => $success]);
});

// Obter Diário Alimentar do Paciente
$router->get('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];

    $foodDiary = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    $timeline = $foodDiary->getPatientTimeline($patientId);
    echo json_encode(['entries' => $timeline]);
});

// Registrar Refeição no Diário
$router->post('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;

    $foodDiary = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    $res = $foodDiary->logMeal($patientId, $input);
    echo json_encode($res);
});

// Obter Linha do Tempo de Evolução
$router->get('/api/v1/patient/evolution', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];

    $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
    $data = $apiCtrl->portal()->getEvolutionTimeline($patientId);
    echo json_encode($data);
});

// Registrar Autorrelato de Métrica (Evolução)
$router->post('/api/v1/patient/self-metrics', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;

    $metricType = $input['metric_type'] ?? 'weight_kg';
    $value = (float)($input['value'] ?? 0);
    $unit = $input['unit'] ?? 'kg';
    $notes = $input['notes'] ?? null;

    $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
    $res = $apiCtrl->portal()->recordSelfMetric($patientId, $metricType, $value, $unit, $notes);
    echo json_encode($res);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router->dispatch($method, $uri);
