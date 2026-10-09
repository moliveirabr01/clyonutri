<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\ClyoNutri\Core\Http\ApiErrorBoundary::register();

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

// Suporte a CORS para Mobile Web e Apps Híbridos (/api/v1/...)
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/v1/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

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

// Carregar TenantContext a partir da sessão se o usuário estiver autenticado e vínculo ativo (PX-23 / NI2-316)
if (!empty($_SESSION['clinic_id']) && !empty($_SESSION['user_id'])) {
    try {
        $validMember = $db->prepare("
            SELECT u.auth_version, cm.status AS member_status, c.status AS clinic_status
            FROM users u
            JOIN clinic_members cm ON cm.user_id = u.id AND cm.clinic_id = :clinic_id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE u.id = :user_id AND u.status = 'active' AND cm.status = 'active' AND c.status = 'active'
            LIMIT 1
        ");
        $validMember->execute([
            'clinic_id' => (int)$_SESSION['clinic_id'],
            'user_id' => (int)$_SESSION['user_id'],
        ]);
        $mRow = $validMember->fetch();
        if ($mRow && (int)$mRow['auth_version'] === (int)($_SESSION['auth_version'] ?? 0)) {
            TenantContext::set((int)$_SESSION['clinic_id'], [
                'id' => (int)$_SESSION['user_id'],
                'name' => $_SESSION['user_name'] ?? 'Nutricionista',
            ]);
        } else {
            $_SESSION = [];
            TenantContext::clear();
        }
    } catch (\Throwable) {
        $_SESSION = [];
        TenantContext::clear();
    }
}


$router = new Router();
$authCtrl = new AuthController();
$dashCtrl = new DashboardController();
$landingCtrl = new LandingController();
$teleCtrl = new \ClyoNutri\Application\Controllers\TeleconsultationController($db);
$helpCtrl = new \ClyoNutri\Application\Controllers\HelpdeskController($db);
$chatCtrl = new \ClyoNutri\Application\Controllers\ChatController($db);
$recipeCtrl = new \ClyoNutri\Application\Controllers\RecipeController($db);
$onboardingCtrl = new \ClyoNutri\Application\Controllers\OnboardingController($db);

// 1. Rotas de Autenticação & Recuperação de Acesso (PX-29 / NI2-322)
$router->get('/login', [$authCtrl, 'showLogin']);
$router->post('/login', [$authCtrl, 'login']);
$router->get('/cadastro', [$authCtrl, 'showRegister']);
$router->post('/cadastro', [$authCtrl, 'register']);
$router->get('/logout', [$authCtrl, 'logout']);
$router->get('/recuperar-senha', [$authCtrl, 'showForgotPassword']);
$router->post('/recuperar-senha', [$authCtrl, 'sendResetLink']);
$router->get('/recuperar-senha/{token}', [$authCtrl, 'showResetPassword']);
$router->post('/recuperar-senha/{token}', [$authCtrl, 'resetPassword']);

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

// Middleware de proteção: se tentar acessar rotas privadas sem login ou com vínculo revogado, bloqueia e redireciona (PX-23 / NI2-316)
$requireAuth = function (callable $action) {
    return function ($params = []) use ($action) {
        if (!TenantContext::hasClinic() || empty($_SESSION['user_id'])) {
            if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || 
                str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
                ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Sessão expirada ou não autenticada.']);
                exit;
            }
            header('Location: /login');
            exit;
        }
        $db = Connection::getInstance();
        $credential = $db->prepare("
            SELECT u.auth_version, cm.status AS member_status, c.status AS clinic_status
            FROM users u
            JOIN clinic_members cm ON cm.user_id = u.id AND cm.clinic_id = :clinic_id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE u.id = :user_id AND u.status = 'active' AND cm.status = 'active' AND c.status = 'active'
            LIMIT 1
        ");
        $credential->execute([
            'user_id' => (int)$_SESSION['user_id'],
            'clinic_id' => (int)$_SESSION['clinic_id'],
        ]);
        $row = $credential->fetch();
        if (!$row || (int)$row['auth_version'] !== (int)($_SESSION['auth_version'] ?? 0)) {
            $_SESSION = [];
            TenantContext::clear();
            if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || 
                str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
                ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Acesso revogado ou sessão suspensa.']);
                exit;
            }
            header('Location: /login?error=session_revoked');
            exit;
        }
        $action($params);
    };
};


// 3. Rotas Públicas & Institucionais (Landing Page & Legal)
$router->get('/', [$landingCtrl, 'index']);
$router->get('/termos', [$landingCtrl, 'terms']);
$router->get('/privacidade', [$landingCtrl, 'privacy']);

// Onboarding Guiado e Ativação do Consultório (NI2-282)
$router->get('/onboarding', $requireAuth([$onboardingCtrl, 'show']));
$router->post('/onboarding/passo', $requireAuth([$onboardingCtrl, 'submitStep']));
$router->get('/api/v1/onboarding/status', $requireAuth([$onboardingCtrl, 'apiStatus']));

$router->get('/dashboard', $requireAuth([$dashCtrl, 'index']));
$router->get('/pacientes', $requireAuth([$dashCtrl, 'patients']));
$router->post('/pacientes', $requireAuth([$dashCtrl, 'savePatient']));
$router->get('/pacientes/{id}/editar', $requireAuth([$dashCtrl, 'editPatient']));
$router->post('/pacientes/{id}/editar', $requireAuth([$dashCtrl, 'editPatient']));
$router->get('/pacientes/{id}', $requireAuth([$dashCtrl, 'profile360']));
$router->post('/pacientes/{id}/convite-app', $requireAuth([new \ClyoNutri\Application\Controllers\PatientOnboardingController(), 'invite']));
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
$router->get('/catalogo/alimentos/busca', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'search']));
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
$router->post('/planos/{id}/itens/{itemId}/editar', $requireAuth([$dashCtrl, 'updatePlanItem']));
$router->post('/planos/{id}/itens/{itemId}/remover', $requireAuth([$dashCtrl, 'deleteItemFromPlanMeal']));
$router->post('/planos/{id}/substituicoes', $requireAuth([$dashCtrl, 'addSubstitutionToPlanItem']));
$router->post('/planos/{id}/substituicoes/{subId}/remover', $requireAuth([$dashCtrl, 'deleteSubstitutionFromPlan']));
$router->post('/planos/{id}/publicar', $requireAuth([$dashCtrl, 'publishMealPlan']));
$router->post('/planos/{id}/duplicar', $requireAuth([$dashCtrl, 'duplicateMealPlan']));
$router->get('/financeiro', $requireAuth([$dashCtrl, 'finance']));
$router->post('/financeiro/recebiveis/novo', $requireAuth([$dashCtrl, 'createReceivableAction']));
$router->post('/financeiro/recebiveis/{id}/pagar', $requireAuth([$dashCtrl, 'payReceivableAction']));
$router->post('/financeiro/recebiveis/{id}/cancelar', $requireAuth([$dashCtrl, 'cancelReceivableAction']));
$router->get('/configuracoes', $requireAuth([$dashCtrl, 'settings']));
$router->post('/configuracoes', $requireAuth([$dashCtrl, 'settings']));

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

// 3.4 Receitas Culinárias & Prescrição ao Paciente (NI2-268)
$router->get('/receitas', $requireAuth([$recipeCtrl, 'index']));
$router->get('/receitas/nova', $requireAuth([$recipeCtrl, 'create']));
$router->post('/receitas', $requireAuth([$recipeCtrl, 'store']));
$router->get('/receitas/{id}', $requireAuth([$recipeCtrl, 'show']));
$router->get('/receitas/{id}/editar', $requireAuth([$recipeCtrl, 'edit']));
$router->post('/receitas/{id}/editar', $requireAuth([$recipeCtrl, 'update']));
$router->get('/receitas/{id}/prescrever', $requireAuth([$recipeCtrl, 'prescribeForm']));
$router->post('/receitas/{id}/prescrever', $requireAuth([$recipeCtrl, 'prescribe']));
$router->post('/receitas/{id}/duplicar', $requireAuth([$recipeCtrl, 'duplicate']));

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
        JOIN clinics c ON c.id = p.clinic_id
        WHERE t.tokenable_type = 'patient' AND p.status = 'active' AND c.status = 'active'
          AND t.token = :hash AND t.expires_at IS NOT NULL AND t.expires_at > CURRENT_TIMESTAMP
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

$getJsonInput = function(): array {
    if (!empty($_POST)) {
        return $_POST;
    }
    if (PHP_SAPI === 'cli') {
        return [];
    }
    $raw = (string)file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ($_POST ?? []);
};

// First access uses a private, single-use invitation, never a patient identifier alone.
foreach (['preview', 'complete'] as $operation) {
    $router->post('/api/v1/auth/patient-onboarding/' . $operation, function() use ($db, $getJsonInput, $operation) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $input=$getJsonInput();
            $service=new \ClyoNutri\Domain\PatientPortal\PatientOnboardingService($db);
            if ($operation==='preview') {
                if (!is_string($input['invitation_code']??null)) throw new \DomainException('Informe o código do convite.');
                $result=$service->preview($input['invitation_code']);
            } else { $result=$service->complete($input); }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\DomainException $e) {
            http_response_code(422); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    });
}

// Login do Paciente via API (Mobile)
$router->post('/api/v1/auth/patient-login', function() use ($db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $input = $getJsonInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Requisição inválida.']);
        return;
    }
    foreach (['email', 'phone', 'cpf', 'password', 'pin', 'otp'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => 'Requisição inválida.']);
            return;
        }
    }
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

    // Never match an omitted identifier against another patient's empty field.
    // Ambiguous identifiers fail closed until the clinic can be selected explicitly.
    $stmt = $db->prepare("SELECT p.* FROM patients p JOIN clinics c ON c.id = p.clinic_id
        WHERE ((:email_present <> '' AND p.email = :email) OR (:phone_present <> '' AND p.phone = :phone))
          AND p.status = 'active' AND c.status = 'active' LIMIT 2");
    $stmt->execute(['email_present' => $email, 'email' => $email, 'phone_present' => $phone, 'phone' => $phone]);
    $patients = $stmt->fetchAll();
    if (count($patients) !== 1) {
        http_response_code(401);
        echo json_encode(['error' => 'Credencial inválida ou acesso indisponível.']);
        return;
    }
    $patient = $patients[0];

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
        echo json_encode(['error' => 'Credencial inválida ou acesso indisponível.']);
        return;
    }

    // 3. Emissão com expiração explícita obrigatória
    $token = bin2hex(random_bytes(32));
    $hashed = hash('sha256', $token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 86400);

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
    $waterGoal = $apiCtrl->portal()->getPrescribedWaterGoal($patientId);

    // Hidratação de hoje
    $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND clinic_id = :cid AND date(logged_at) = date('now')");
    $hStmt->execute(['pid' => $patientId, 'cid' => (int)$auth['clinic_id']]);
    $waterToday = (int)$hStmt->fetchColumn();

    echo json_encode([
        'patient_name' => $auth['patient_name'],
        'water_ml_today' => $waterToday,
        'water_goal_ml' => $waterGoal,
        'next_appointment' => $nextApp,
        'active_plan' => $plan,
    ]);
});

// Registrar Hidratação
$router->post('/api/v1/patient/hydration', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();
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
$router->post('/api/v1/patient/appointment/confirm', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();
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
$router->post('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();

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
    http_response_code(200);
    echo json_encode($data);
});

// Registrar Autorrelato de Métrica (Evolução)
$router->post('/api/v1/patient/self-metrics', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();

    $metricType = trim((string)($input['metric_type'] ?? ''));
    if ($metricType === '') {
        $metricType = 'weight_kg';
    }
    $value = isset($input['value']) ? (float)$input['value'] : 0.0;
    $unit = trim((string)($input['unit'] ?? 'kg'));
    $notes = isset($input['notes']) && trim((string)$input['notes']) !== '' ? trim((string)$input['notes']) : null;

    if ($value <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'O valor da métrica deve ser um número positivo e válido.']);
        return;
    }

    try {
        $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
        $res = $apiCtrl->portal()->recordSelfMetric($patientId, $metricType, $value, $unit, $notes);
        http_response_code(201);
        echo json_encode($res);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
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

$router->post('/api/v1/patient/chat/send', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();

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

$router->post('/api/v1/patient/chat/read', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $clinicId = (int)$auth['clinic_id'];
    $input = $getJsonInput();
    $convId = (int)($input['conversation_id'] ?? 0);

    $chatService = new \ClyoNutri\Domain\Chat\ChatService($db);
    if ($convId <= 0) {
        $convs = $chatService->listConversationsForPatient($patientId);
        if (!empty($convs)) {
            $convId = (int)$convs[0]['id'];
        }
    }

    if ($convId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Identificador da conversa inválido ou ausente.']);
        return;
    }

    // Validação estrita de participante contra falsificação de recibos (PX-25 / NI2-318)
    $chk = $db->prepare("SELECT id FROM chat_conversations WHERE id = ? AND clinic_id = ? AND patient_id = ?");
    $chk->execute([$convId, $clinicId, $patientId]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso não autorizado: conversa não pertence ao paciente autenticado.']);
        return;
    }

    try {
        $chatService->markAsRead($convId, 'patient', $patientId);
        echo json_encode(['success' => true]);
    } catch (\ClyoNutri\Core\Security\AuthorizationException $e) {
        http_response_code(403);
        echo json_encode(['error' => $e->getMessage()]);
    }
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

// 4.5 Receitas Prescritas & Lista de Compras do Paciente (NI2-268)
$router->get('/api/v1/patient/recipes', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];

    $recipeService = new \ClyoNutri\Domain\Recipe\RecipeService($db);
    $prescriptions = $recipeService->listPrescriptionsForPatient($patientId);
    echo json_encode(['data' => $prescriptions]);
});

$router->get('/api/v1/patient/recipes/{id}', function($params = []) use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $recipeId = (int)($params['id'] ?? 0);

    $recipeService = new \ClyoNutri\Domain\Recipe\RecipeService($db);
    try {
        $details = $recipeService->getRecipeDetails($recipeId, (int)$auth['clinic_id']);
        echo json_encode(['data' => $details]);
    } catch (\Throwable $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/v1/patient/recipes/{id}/favorite', function($params = []) use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $recipeId = (int)($params['id'] ?? 0);

    $recipeService = new \ClyoNutri\Domain\Recipe\RecipeService($db);
    $isFav = $recipeService->togglePatientFavorite($patientId, $recipeId);
    echo json_encode(['is_favorite' => $isFav]);
});

$router->get('/api/v1/patient/recipes-shopping-list', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $days = (int)($_GET['days'] ?? 7);

    $recipeService = new \ClyoNutri\Domain\Recipe\RecipeService($db);
    $list = $recipeService->generateShoppingListFromRecipes($patientId, [], $days);
    echo json_encode(['data' => $list]);
});

// 5. API Móvel do Nutricionista / Profissional (/api/v1/professional/... — NI2-292)
$authenticateProfessionalApi = function() use ($db): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado. Token Bearer ausente ou inválido.']);
        exit;
    }
    $plainToken = substr($authHeader, 7);
    $hashed = hash('sha256', $plainToken);

    $stmt = $db->prepare("
        SELECT t.*, u.id AS user_id, u.name AS user_name, u.email AS user_email, u.role AS user_role, cm.clinic_id, c.name AS clinic_name
        FROM personal_access_tokens t
        JOIN users u ON u.id = t.tokenable_id
        JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
        JOIN clinics c ON c.id = cm.clinic_id AND c.status = 'active'
        WHERE t.tokenable_type = 'professional' AND u.status = 'active'
          AND t.token = :hash AND t.expires_at IS NOT NULL AND t.expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");
    $stmt->execute(['hash' => $hashed]);
    $tokenRow = $stmt->fetch();

    if (!$tokenRow) {
        http_response_code(401);
        echo json_encode(['error' => 'Token de acesso profissional inválido ou expirado.']);
        exit;
    }

    \ClyoNutri\Core\Auth\TenantContext::set((int)$tokenRow['clinic_id'], [
        'id' => (int)$tokenRow['user_id'],
        'role' => $tokenRow['user_role'],
        'name' => $tokenRow['user_name'],
    ]);

    return $tokenRow;
};

$router->post('/api/v1/professional/patients/{id}/app-invitation', function(array $params) use ($db, $authenticateProfessionalApi) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth=$authenticateProfessionalApi();
    try {
        $result=(new \ClyoNutri\Domain\PatientPortal\PatientOnboardingService($db))->create((int)$auth['clinic_id'],(int)$auth['user_id'],(int)$params['id']);
        echo json_encode($result);
    } catch (\DomainException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
});

// Login do Nutricionista via API (Mobile)
$router->post('/api/v1/auth/professional-login', function() use ($db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $input = $getJsonInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Requisição inválida.']);
        return;
    }
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if (empty($email) || empty($password)) {
        http_response_code(401);
        echo json_encode(['error' => 'Informe e-mail e senha de acesso profissional.']);
        return;
    }

    $stmt = $db->prepare("
        SELECT u.*, cm.clinic_id, c.name AS clinic_name
        FROM users u
        JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
        JOIN clinics c ON c.id = cm.clinic_id AND c.status = 'active'
        WHERE u.email = :email AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Credencial profissional inválida ou acesso indisponível.']);
        return;
    }

    // Validação de papel clínico (RBAC)
    if (!in_array($user['role'], ['nutritionist', 'admin', 'owner'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Conta sem autorização clínica ou profissional.']);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $hashed = hash('sha256', $token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 86400);

    $ins = $db->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, expires_at, created_at)
        VALUES ('professional', :uid, 'mobile_app_professional', :token, :expires_at, CURRENT_TIMESTAMP)
    ");
    $ins->execute([
        'uid' => $user['id'],
        'token' => $hashed,
        'expires_at' => $expiresAt,
    ]);

    echo json_encode([
        'token' => $token,
        'expires_at' => $expiresAt,
        'professional' => [
            'id' => (int)$user['id'],
            'clinic_id' => (int)$user['clinic_id'],
            'clinic_name' => $user['clinic_name'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
    ]);
});

// Logout do Nutricionista via API (Mobile)
$router->post('/api/v1/auth/professional-logout', function() use ($authenticateProfessionalApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $tokenId = (int)$auth['id'];
    $stmt = $db->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
    $stmt->execute(['id' => $tokenId]);
    echo json_encode(['message' => 'Token profissional revogado com sucesso.']);
});

// Dashboard do Nutricionista no Mobile
$router->get('/api/v1/professional/dashboard', function() use ($authenticateProfessionalApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];
    $userId = (int)$auth['user_id'];

    $apptService = new \ClyoNutri\Domain\Appointment\AppointmentService($db);
    $today = date('Y-m-d');
    $todayStats = $apptService->getTodayStats($today);
    $todayAppointments = $apptService->listByDay($today, $userId);

    $foodDiaryService = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    $pendingDiariesCount = $foodDiaryService->countPendingDiaries($clinicId);

    $stmtPatients = $db->prepare("SELECT COUNT(*) AS total FROM patients WHERE clinic_id = :cid AND status = 'active'");
    $stmtPatients->execute(['cid' => $clinicId]);
    $activePatientsCount = (int)($stmtPatients->fetch()['total'] ?? 0);

    echo json_encode([
        'professional_name' => $auth['user_name'],
        'clinic_name' => $auth['clinic_name'],
        'clinic_id' => $clinicId,
        'today_stats' => $todayStats,
        'today_appointments' => $todayAppointments,
        'pending_diaries_count' => $pendingDiariesCount,
        'active_patients_count' => $activePatientsCount,
    ]);
});

// Lista de Pacientes do Nutricionista no Mobile
$router->get('/api/v1/professional/patients', function() use ($authenticateProfessionalApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];

    $stmt = $db->prepare("
        SELECT id, name, email, phone, status, created_at
        FROM patients
        WHERE clinic_id = :cid
        ORDER BY name ASC
        LIMIT 100
    ");
    $stmt->execute(['cid' => $clinicId]);
    $patients = $stmt->fetchAll();

    echo json_encode(['patients' => $patients]);
});

// Linha do tempo de diários alimentares dos pacientes da clínica
$router->get('/api/v1/professional/food-diaries', function() use ($authenticateProfessionalApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];

    $foodDiaryService = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    $diaries = $foodDiaryService->getClinicDiaries($clinicId, 50);

    echo json_encode(['diaries' => $diaries]);
});

// Envio de feedback contextual em diário alimentar pelo nutricionista
$router->post('/api/v1/professional/food-diary/{id}/feedback', function($params = []) use ($authenticateProfessionalApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];
    $entryId = (int)($params['id'] ?? 0);

    $input = $getJsonInput();
    $feedback = trim((string)($input['feedback'] ?? ''));

    if ($entryId <= 0 || $feedback === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Identificador da refeição e mensagem de feedback clínico são obrigatórios.']);
        return;
    }

    $foodDiaryService = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    $success = $foodDiaryService->addProfessionalFeedback($entryId, $feedback);

    if ($success) {
        $stmtAudit = $db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details, created_at)
            VALUES (:cid, :uid, 'FOOD_DIARY_FEEDBACK_GIVEN', 'food_diary_entry', :eid, :val, CURRENT_TIMESTAMP)
        ");
        $stmtAudit->execute([
            'cid' => $clinicId,
            'uid' => (int)$auth['user_id'],
            'eid' => (string)$entryId,
            'val' => json_encode(['feedback_length' => strlen($feedback)]),
        ]);

        echo json_encode([
            'success' => true,
            'entry_id' => $entryId,
            'feedback' => $feedback,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Registro no diário não encontrado ou não pertence a esta clínica.']);
    }
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router->dispatch($method, $uri);
