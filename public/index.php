<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\AuthController;
use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\LandingController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Router;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Patient\PatientRepository;

Env::load(__DIR__ . '/../.env');

// Configuração segura de fuso horário da aplicação (NI2-187)
$appTimezone = (string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo');
date_default_timezone_set($appTimezone);

// Inicialização de conexão
$db = Connection::getInstance();

// Configuração segura de cookies de sessão
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
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
$landingCtrl = new LandingController();
$teleCtrl = new \ClyoNutri\Application\Controllers\TeleconsultationController($db);
$helpCtrl = new \ClyoNutri\Application\Controllers\HelpdeskController($db);
$chatCtrl = new \ClyoNutri\Application\Controllers\ChatController($db);

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

// 3. Rotas Públicas & Institucionais (Landing Page & Legal)
$router->get('/', [$landingCtrl, 'index']);
$router->get('/termos', [$landingCtrl, 'terms']);
$router->get('/privacidade', [$landingCtrl, 'privacy']);

$router->get('/dashboard', $requireAuth([$dashCtrl, 'index']));
$router->get('/pacientes', $requireAuth([$dashCtrl, 'patients']));
$router->post('/pacientes', $requireAuth([$dashCtrl, 'savePatient']));
$router->get('/pacientes/{id}', $requireAuth([$dashCtrl, 'profile360']));
$router->get('/pacientes/{id}/prontuario', $requireAuth([$dashCtrl, 'patientMedicalRecord']));
$router->post('/pacientes/{id}/prontuario', $requireAuth([$dashCtrl, 'savePatientMedicalRecord']));
$router->post('/pacientes/{id}/prontuario/{recId}/retificar', $requireAuth([$dashCtrl, 'revisePatientMedicalRecord']));
$router->get('/pacientes/{id}/prontuario/exportar', $requireAuth([$dashCtrl, 'exportMedicalRecord']));
$router->get('/pacientes/{id}/anamnese', $requireAuth([$dashCtrl, 'patientAnamnesis']));
$router->post('/pacientes/{id}/anamnese', $requireAuth([$dashCtrl, 'savePatientAnamnesis']));
$router->get('/pacientes/{id}/resumo-prescricao', $requireAuth([$dashCtrl, 'prescriptionSummary']));
$router->get('/pacientes/{id}/antropometria', $requireAuth([$dashCtrl, 'patientAnthropometry']));
$router->post('/pacientes/{id}/antropometria', $requireAuth([$dashCtrl, 'saveAnthropometry']));
$router->post('/pacientes/{id}/antropometria/{evalId}/compartilhar', $requireAuth([$dashCtrl, 'shareAnthropometry']));
$router->get('/pacientes/{id}/antropometria/comparar', $requireAuth([$dashCtrl, 'compareAnthropometry']));
$router->get('/alimentos', $requireAuth([$dashCtrl, 'foods']));
$router->get('/agenda', $requireAuth([$dashCtrl, 'agenda']));
$router->post('/agenda', $requireAuth([$dashCtrl, 'saveAppointment']));
$router->post('/agenda/{id}/editar', $requireAuth([$dashCtrl, 'editAppointment']));
$router->post('/agenda/{id}/status', $requireAuth([$dashCtrl, 'updateAppointmentStatus']));
$router->get('/consulta/{id}', $requireAuth([$dashCtrl, 'consultationRoom']));
$router->post('/consulta/{id}/concluir', $requireAuth([$dashCtrl, 'completeConsultation']));

// 3.1 Consultório Online & Teleconsulta (NI2-221)
$router->get('/teleconsulta/sessao-agenda/{appointmentId}', $requireAuth([$teleCtrl, 'openFromAppointment']));
$router->get('/teleconsulta/{id}/atendimento', $requireAuth([$teleCtrl, 'professionalRoom']));
$router->post('/teleconsulta/{id}/admitir', $requireAuth([$teleCtrl, 'admit']));
$router->post('/teleconsulta/{id}/rejeitar', $requireAuth([$teleCtrl, 'reject']));
$router->post('/teleconsulta/{id}/evoluir', $requireAuth([$teleCtrl, 'recordEvolution']));
$router->post('/teleconsulta/{id}/encerrar', $requireAuth([$teleCtrl, 'end']));
$router->get('/teleconsulta/sala-espera/{token}', [$teleCtrl, 'patientWaitingRoom']);
$router->post('/teleconsulta/sala-espera/{token}/dispositivo', [$teleCtrl, 'patientSubmitDeviceCheck']);
$router->post('/teleconsulta/sala-espera/{token}/consentir', [$teleCtrl, 'patientAcceptConsent']);
$router->post('/teleconsulta/sala-espera/{token}/entrar', [$teleCtrl, 'patientEnterWaiting']);
$router->get('/teleconsulta/sala-espera/{token}/status', [$teleCtrl, 'patientStatus']);
$router->get('/teleconsulta/sala/{token}', [$teleCtrl, 'patientConsultationRoom']);
$router->get('/planos', $requireAuth([$dashCtrl, 'mealPlans']));
$router->post('/planos/novo', $requireAuth([$dashCtrl, 'createMealPlan']));
$router->get('/planos/{id}', $requireAuth([$dashCtrl, 'mealPlanEditor']));
$router->post('/planos/{id}/refeicoes', $requireAuth([$dashCtrl, 'addMealToPlan']));
$router->post('/planos/{id}/refeicoes/{mealId}/remover', $requireAuth([$dashCtrl, 'deleteMealFromPlan']));
$router->post('/planos/{id}/itens', $requireAuth([$dashCtrl, 'addItemToPlanMeal']));
$router->post('/planos/{id}/itens/{itemId}/remover', $requireAuth([$dashCtrl, 'deleteItemFromPlanMeal']));
$router->post('/planos/{id}/substituicoes', $requireAuth([$dashCtrl, 'addSubstitutionToPlanItem']));
$router->post('/planos/{id}/substituicoes/{subId}/remover', $requireAuth([$dashCtrl, 'deleteSubstitutionFromPlan']));
$router->post('/planos/{id}/publicar', $requireAuth([$dashCtrl, 'publishMealPlan']));
$router->post('/planos/{id}/duplicar', $requireAuth([$dashCtrl, 'duplicateMealPlan']));
$router->get('/financeiro', $requireAuth([$dashCtrl, 'finance']));
$router->get('/configuracoes', $requireAuth([$dashCtrl, 'settings']));

// 3.2 Central de Ajuda & Service Desk (NI2-195 a NI2-208)
$router->get('/ajuda', $requireAuth([$helpCtrl, 'index']));
$router->get('/ajuda/categoria/{slug}', $requireAuth([$helpCtrl, 'categoryArticles']));
$router->get('/ajuda/artigo/{slug}', $requireAuth([$helpCtrl, 'showArticle']));
$router->get('/ajuda/abrir-chamado', $requireAuth([$helpCtrl, 'showCreateTicket']));
$router->post('/ajuda/abrir-chamado', $requireAuth([$helpCtrl, 'storeTicket']));
$router->get('/ajuda/meus-chamados', $requireAuth([$helpCtrl, 'myTickets']));
$router->get('/ajuda/chamado/{protocol}', $requireAuth([$helpCtrl, 'ticketDetail']));
$router->post('/ajuda/chamado/{protocol}/responder', $requireAuth([$helpCtrl, 'replyTicket']));
$router->post('/ajuda/chamado/{protocol}/resolver', $requireAuth([$helpCtrl, 'resolveTicket']));
$router->post('/ajuda/chamado/{protocol}/csat', $requireAuth([$helpCtrl, 'submitCsat']));
$router->get('/ajuda/anexo/{id}', $requireAuth([$helpCtrl, 'downloadAttachment']));

// Backoffice de Triagem e Atendimento do Suporte (RBAC)
$router->get('/suporte/fila', $requireAuth([$helpCtrl, 'backofficeQueue']));
$router->get('/suporte/chamado/{id}', $requireAuth([$helpCtrl, 'backofficeTicket']));
$router->post('/suporte/chamado/{id}/responder', $requireAuth([$helpCtrl, 'backofficeReply']));
$router->post('/suporte/chamado/{id}/status', $requireAuth([$helpCtrl, 'backofficeUpdateStatus']));

// 3.3 Canal Seguro de Comunicação & Chat (NI2-189)
$router->get('/chat', $requireAuth([$chatCtrl, 'index']));
$router->get('/chat/{id}', $requireAuth([$chatCtrl, 'conversationView']));
$router->post('/chat/{id}/enviar', $requireAuth([$chatCtrl, 'sendMessage']));
$router->get('/chat/{id}/poll', $requireAuth([$chatCtrl, 'pollMessages']));
$router->get('/chat/anexo/{id}', $requireAuth([$chatCtrl, 'downloadAttachment']));

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

    // Validação estrita: SEM bypass por plaintext e com expiração estrita obrigatória (expires_at NOT NULL - NI2-209)
    $stmt = $db->prepare("
        SELECT t.*, p.id AS patient_id, p.clinic_id, p.name AS patient_name
        FROM personal_access_tokens t
        JOIN patients p ON p.id = t.tokenable_id
        WHERE t.token = :hash AND t.expires_at IS NOT NULL AND t.expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");
    $stmt->execute(['hash' => $hashed]);
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
    $password = (string)($input['password'] ?? $input['pin'] ?? '');
    $otp = (string)($input['otp'] ?? '');

    // 1. Proibição de login apenas por identificador (NI2-209)
    if (empty($password) && empty($otp)) {
        http_response_code(401);
        echo json_encode(['error' => 'Autenticação forte exigida: informe senha, PIN de acesso ou código OTP (login apenas por identificador proibido).']);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM patients WHERE (email = :email OR phone = :phone) AND status = 'active' LIMIT 1");
    $stmt->execute(['email' => $email, 'phone' => $phone]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(401);
        echo json_encode(['error' => 'Paciente não encontrado ou inativo.']);
        return;
    }

    // 2. Validação da credencial
    $authenticated = false;
    if (!empty($password)) {
        if (!empty($patient['password_hash']) && password_verify($password, $patient['password_hash'])) {
            $authenticated = true;
        } elseif (!empty($patient['access_pin']) && password_verify($password, $patient['access_pin'])) {
            $authenticated = true;
        }
    }
    if (!$authenticated && !empty($otp)) {
        if (!empty($patient['otp_code']) && hash_equals((string)$patient['otp_code'], $otp)) {
            if (!empty($patient['otp_expires_at']) && strtotime($patient['otp_expires_at']) > time()) {
                $authenticated = true;
            }
        }
    }

    if (!$authenticated) {
        http_response_code(401);
        echo json_encode(['error' => 'Credencial inválida: senha, PIN ou código OTP incorreto ou expirado.']);
        return;
    }

    // 3. Emissão com expiração explícita obrigatória
    $token = bin2hex(random_bytes(32));
    $hashed = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $ins = $db->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, expires_at, created_at)
        VALUES ('patient', :pid, 'mobile_app', :token, :expires_at, CURRENT_TIMESTAMP)
    ");
    $ins->execute([
        'pid' => $patient['id'],
        'token' => $hashed,
        'expires_at' => $expiresAt,
    ]);

    echo json_encode([
        'token' => $token,
        'expires_at' => $expiresAt,
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

// 4.1 Chat Seguro do Paciente (NI2-189)
$router->get('/api/v1/patient/chat/messages', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;

    $chatService = new \ClyoNutri\Domain\Chat\ChatService($db);
    $convs = $chatService->listConversationsForPatient($patientId);
    if (empty($convs)) {
        echo json_encode(['conversation_id' => null, 'messages' => [], 'cursor' => ['after_id' => 0, 'count' => 0, 'has_more' => false]]);
        return;
    }

    $convId = (int)$convs[0]['id'];
    $data = $chatService->getMessagesWithCursor($convId, $afterId, 50, 'patient', $patientId);
    echo json_encode($data);
});

$router->post('/api/v1/patient/chat/send', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;

    $message = trim((string)($input['message'] ?? ''));
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = bin2hex(random_bytes(16));
    }

    $chatService = new \ClyoNutri\Domain\Chat\ChatService($db);
    $convId = (int)($input['conversation_id'] ?? 0);

    if ($convId <= 0) {
        $convs = $chatService->listConversationsForPatient($patientId);
        if (!empty($convs)) {
            $convId = (int)$convs[0]['id'];
        }
    }

    if ($convId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Nenhuma conversa ativa encontrada para o paciente.']);
        return;
    }

    try {
        $msg = $chatService->sendMessage(
            $convId,
            'patient',
            $patientId,
            $message,
            null,
            $idempotencyKey
        );
        echo json_encode(['success' => true, 'message' => $msg]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/v1/patient/chat/read', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
    $convId = (int)($input['conversation_id'] ?? 0);

    $chatService = new \ClyoNutri\Domain\Chat\ChatService($db);
    if ($convId <= 0) {
        $convs = $chatService->listConversationsForPatient($patientId);
        if (!empty($convs)) {
            $convId = (int)$convs[0]['id'];
        }
    }

    if ($convId > 0) {
        $chatService->markAsRead($convId, 'patient');
    }

    echo json_encode(['success' => true]);
});

$router->get('/api/v1/patient/chat/attachment/{id}', function($params = []) use ($authenticatePatientApi, $db) {
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $attId = (int)($params['id'] ?? 0);

    $attService = new \ClyoNutri\Domain\Chat\ChatAttachmentService($db);
    try {
        $fileData = $attService->getAttachmentForDownload($attId, 'patient', $patientId);
        header('Content-Type: ' . $fileData['mime_type']);
        header('Content-Length: ' . $fileData['size_bytes']);
        header('Content-Disposition: inline; filename="' . addslashes($fileData['file_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache, must-revalidate');
        readfile($fileData['file_path']);
        exit;
    } catch (\Throwable $e) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router->dispatch($method, $uri);
