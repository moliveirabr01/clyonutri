<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');
// v10: em produção nunca exibe erro/stack trace ao público, mesmo com APP_DEBUG=true.
\ClyoNutri\Core\Http\ErrorExposure::configure();
// NI2-427: HTTPS obrigatório antes de CORS, banco, sessão ou qualquer controller.
// GET/HEAD por HTTP -> 301 para APP_URL (https); demais métodos -> 403 sem processar.
\ClyoNutri\Core\Http\Transport::enforce();
// NI2-428: HSTS gradual (só por HTTPS; valor em HSTS_MAX_AGE).
\ClyoNutri\Core\Http\SecurityHeaders::sendHsts();
// NI2-429: CSP (nonce por resposta), anti-enquadramento, nosniff, Referrer/Permissions-Policy.
\ClyoNutri\Core\Http\SecurityHeaders::sendBrowserPolicies((string)($_SERVER['REQUEST_URI'] ?? '/'));
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

// NI2-24: toda tentativa de usar id de outra clínica fica na auditoria, qualquer que seja a resposta do controller.
\ClyoNutri\Core\Security\CrossTenantAccessAuditor::register();

// Suporte a CORS para Mobile Web e Apps Híbridos (/api/v1/...)
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/v1/')) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && \ClyoNutri\Core\Auth\MobileWebSession::allowedOrigin($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-ClyoNutri-Web');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Max-Age: 600');
        header_remove('Content-Type');
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
        // NI2-427: Secure sempre que a requisição chegou por HTTPS (inclui proxy confiável)
        // ou quando FORCE_HTTPS está ativo (nesse caso HTTP nunca chega até aqui).
        'secure' => \ClyoNutri\Core\Http\Transport::isHttps() || \ClyoNutri\Core\Http\Transport::forceHttpsEnabled(),
    ]);
    session_start();
}

// Carregar TenantContext a partir da sessão se o usuário estiver autenticado e vínculo ativo (PX-23 / NI2-316)
if (!empty($_SESSION['clinic_id']) && !empty($_SESSION['user_id'])) {
    try {
        $validMember = $db->prepare("
            SELECT u.auth_version, u.role AS user_role, cm.role AS member_role, cm.status AS member_status, c.status AS clinic_status
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
$adminCtrl = new \ClyoNutri\Application\Controllers\AdminController($db);
$subCtrl = new \ClyoNutri\Application\Controllers\SubscriptionController();
$privacyCtrl = new \ClyoNutri\Application\Controllers\PrivacyRequestController();
$evolutionCtrl = new \ClyoNutri\Application\Controllers\PatientEvolutionController();
$patientAppCtrl = new \ClyoNutri\Application\Controllers\PatientAppController();
$bookingCtrl = new \ClyoNutri\Application\Controllers\BookingController($db);

// 1. Rotas de Autenticação & Recuperação de Acesso (PX-29 / NI2-322)
$router->get('/login', [$authCtrl, 'showLogin']);
$router->post('/login', [$authCtrl, 'login']);
$router->get('/cadastro', [$authCtrl, 'showRegister']);
$router->post('/cadastro', [$authCtrl, 'register']);
$router->get('/logout', [$authCtrl, 'logoutConfirm']); // GET não encerra sessão: 405 + formulário de confirmação (POST + CSRF)
$router->post('/logout', [$authCtrl, 'logout']);
$router->get('/recuperar-senha', [$authCtrl, 'showForgotPassword']);
$router->post('/recuperar-senha', [$authCtrl, 'sendResetLink']);
$router->get('/recuperar-senha/{token}', [$authCtrl, 'showResetPassword']);
$router->post('/recuperar-senha/{token}', [$authCtrl, 'resetPassword']);

// 2. Health Check & Observabilidade (/up padrão Laravel 13)
$router->get('/up', function () {
    // v10: resposta mínima (sem versão, horário, nome do serviço ou estado do banco/agendador).
    // Detalhes só pela linha de comando: php bin/diagnostico.php / php bin/healthcheck.php.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dbOk = false;
    try {
        Connection::getInstance()->query('SELECT 1');
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbOk = false;
    }
    http_response_code($dbOk ? 200 : 503);
    echo json_encode(['status' => $dbOk ? 'ok' : 'unavailable']);
});

$router->get('/health', function () {
    // v10 (achado "Endpoint público de diagnóstico"): só {"status":"ok"}; nada de serviço, versão ou horário.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'ok']);
});

// Verificação de Modo de Manutenção em Tempo de Execução (NI2-407 / NI2-411)
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isMaintenanceActive = \ClyoNutri\Core\Settings\PlatformSettings::isMaintenanceMode($db);

if ($isMaintenanceActive) {
    $isExemptPath = str_starts_with($requestPath, '/admin')
        || in_array($requestPath, ['/up', '/health', '/login', '/logout'], true);

    $userRole = $_SESSION['user_role'] ?? '';
    $isPrivilegedStaff = in_array($userRole, ['operator', 'superadmin', 'support_agent', 'security_auditor'], true);

    if (!$isExemptPath && !$isPrivilegedStaff) {
        http_response_code(503);
        header('Retry-After: 300');
        if (str_starts_with($requestPath, '/api/') || 
            str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
            ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'A plataforma está em manutenção temporária agendada.',
                'maintenance' => true,
                'retry_after_seconds' => 300,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Manutenção - ClyoNutri II</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1.5rem;box-sizing:border-box;}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:2.5rem;max-width:520px;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.08);}h1{font-size:1.35rem;color:#0f172a;margin-top:0;}p{font-size:0.92rem;color:#64748b;line-height:1.5;}</style></head><body><div class="card"><h1>Manutenção Programada em Andamento</h1><p>A plataforma ClyoNutri II está passando por calibração de segurança. O acesso aos consultórios será restabelecido em breve.</p><p style="font-size:0.8rem;color:#94a3b8;margin-top:1.5rem;">HTTP 503 Service Unavailable &bull; Modo de Manutenção Ativo</p></div></body></html>';
        exit;
    }
}

// Middleware de proteção: se tentar acessar rotas privadas sem login ou com vínculo revogado, bloqueia e redireciona (PX-23 / NI2-316 / NI2-256)
$requireAuth = function (callable $action) use ($db) {
    return function ($params = []) use ($action, $db) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $reqUriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $wantsJsonAuthError = str_starts_with($reqUriPath, '/api/')
            || str_ends_with($reqUriPath, '/rascunho')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || isset($_SERVER['HTTP_X_CSRF_TOKEN'])
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

        // Validação de tempo de inatividade de sessão configurado na plataforma (NI2-407 / AUD-05)
        $timeoutMinutes = \ClyoNutri\Core\Settings\PlatformSettings::getSessionTimeoutMinutes($db);
        if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > ($timeoutMinutes * 60))) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            if ($wantsJsonAuthError) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'unauthenticated', 'error' => 'Sessão encerrada por inatividade.']);
                exit;
            }
            \ClyoNutri\Core\Http\Response::redirect('/login?error=session_timeout');
        }
        $_SESSION['last_activity'] = time();

        if (empty($_SESSION['user_id']) || empty($_SESSION['clinic_id'])) {
            if ($wantsJsonAuthError) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'unauthenticated', 'error' => 'Sessão expirada ou não autenticada.']);
                exit;
            }
            \ClyoNutri\Core\Http\Response::redirect('/login');
        }

        $authUser = \ClyoNutri\Core\Auth\AuthContext::resolveFromSession($db);
        if (!$authUser) {
            if ($wantsJsonAuthError) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'forbidden', 'error' => 'Acesso revogado ou sessão suspensa.']);
                exit;
            }
            \ClyoNutri\Core\Http\Response::redirect('/login?error=session_revoked');
        }

        // v10 (2.2): páginas autenticadas nunca ficam em cache (navegador/proxy).
        if (!headers_sent()) { header('Cache-Control: no-store, private'); header('Pragma: no-cache'); }
        $actor = $authUser->toArray();
        try {
            \ClyoNutri\Core\Security\ClinicalAuthorization::assertWebAccess($actor, parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', $_SERVER['REQUEST_METHOD'], $db);
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Acesso não autorizado para este perfil.']);
            return;
        }
        // CSRF + Origin/Referer para mutações /api/ autenticadas por cookie de sessão (auditoria 14/10/2026).
        $csrfError = \ClyoNutri\Core\Security\SessionApiCsrfGuard::check(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $_SERVER,
            $_POST,
            (string)file_get_contents('php://input')
        );
        if ($csrfError !== null) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $csrfError]);
            return;
        }
        $action($params);
    };
};

// Middleware para módulo de Teleconsulta com Feature Flag dinâmica (NI2-411)
$requireTelehealth = function (callable $action) use ($db) {
    return function ($params = []) use ($action, $db) {
        if (!\ClyoNutri\Core\Settings\PlatformSettings::isTelehealthEnabled($db)) {
            http_response_code(503);
            if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || 
                str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
                ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Módulo de Teleconsulta & Consultório Online temporariamente suspenso pela administração.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $_SESSION['flash_error'] = 'Módulo de Teleconsulta temporariamente suspenso pela administração da plataforma.';
            \ClyoNutri\Core\Http\Response::redirect('/dashboard');
            return;
        }
        $action($params);
    };
};

// Middleware para canal de Chat com Feature Flag dinâmica (NI2-411)
$requireChat = function (callable $action) use ($db) {
    return function ($params = []) use ($action, $db) {
        if (!\ClyoNutri\Core\Settings\PlatformSettings::isChatEnabled($db)) {
            http_response_code(503);
            if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || 
                str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
                ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Canal Seguro de Mensagens & Chat temporariamente suspenso pela administração.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $_SESSION['flash_error'] = 'Canal Seguro de Mensagens & Chat temporariamente suspenso pela administração da plataforma.';
            \ClyoNutri\Core\Http\Response::redirect('/dashboard');
            return;
        }
        $action($params);
    };
};


// 3. Rotas Públicas & Institucionais (Landing Page & Legal)
$router->get('/', [$landingCtrl, 'index']);
$router->get('/termos', [$landingCtrl, 'terms']);
$router->get('/privacidade', [$landingCtrl, 'privacy']);

// Portal & App do Paciente (P03 - Auditoria Navegação Assistida)
$router->get('/app', [$patientAppCtrl, 'index']);
$router->post('/app/primeiro-acesso', [$patientAppCtrl, 'completeFirstAccess']);
$router->post('/app/login', [$patientAppCtrl, 'login']);

// Autoagendamento Self-Service (NI2-433 / NI2-434)
$router->get('/agendar', [$bookingCtrl, 'publicBookingPage']);
$router->get('/agendar/{professionalId}', [$bookingCtrl, 'publicBookingPage']);
$router->get('/api/v1/booking/slots', [$bookingCtrl, 'getSlots']);
$router->post('/api/v1/booking/holds', [$bookingCtrl, 'createHold']);
$router->post('/api/v1/booking/confirm', [$bookingCtrl, 'confirmBooking']);
$router->post('/api/v1/booking/release', [$bookingCtrl, 'releaseHold']);
// Cancelar/remarcar pela paciente: rotas da NI2-137 na seção da API da paciente (motor da agenda).

// Onboarding Guiado e Ativação do Consultório (NI2-282)
$router->get('/onboarding', $requireAuth([$onboardingCtrl, 'show']));
$router->post('/onboarding/passo', $requireAuth([$onboardingCtrl, 'submitStep']));
$router->get('/api/v1/onboarding/status', $requireAuth([$onboardingCtrl, 'apiStatus']));

$router->get('/dashboard', $requireAuth([$dashCtrl, 'index']));
$router->get('/pacientes', $requireAuth([$dashCtrl, 'patients']));
$router->post('/pacientes', $requireAuth([$dashCtrl, 'savePatient']));
$router->get('/pacientes/{id}/editar', $requireAuth([$dashCtrl, 'editPatient']));
$router->post('/pacientes/{id}/editar', $requireAuth([$dashCtrl, 'editPatient']));
$router->post('/pacientes/{id}/status', $requireAuth([$dashCtrl, 'patientStatus']));
$router->post('/pacientes/{id}/consentimento/retirar', $requireAuth([$dashCtrl, 'patientConsent']));
$router->post('/pacientes/{id}/privacidade', $requireAuth([$dashCtrl, 'patientPrivacyRequest']));
// Fila de pedidos de titulares da clínica (code review NI2-91, A2)
// Evolução no app da paciente: medidas liberadas e metas (NI2-135)
$router->post('/pacientes/{id}/autorrelatos/permissoes', $requireAuth([$evolutionCtrl, 'permissions']));
$router->post('/pacientes/{id}/metas', $requireAuth([$evolutionCtrl, 'createGoal']));
// NI2-138: vínculo que libera o chat da paciente com o profissional.
$chatLinkCtrl = new \ClyoNutri\Application\Controllers\ChatLinkController();
$router->post('/pacientes/{id}/chat/vinculo', $requireAuth([$chatLinkCtrl, 'activate']));
$router->post('/pacientes/{id}/chat/vinculo/{linkId}/encerrar', $requireAuth([$chatLinkCtrl, 'end']));
$router->post('/pacientes/{id}/metas/{goalId}/encerrar', $requireAuth([$evolutionCtrl, 'closeGoal']));
$router->get('/privacidade/pedidos', $requireAuth([$privacyCtrl, 'index']));
$router->post('/privacidade/pedidos/{id}/exportar', $requireAuth([$privacyCtrl, 'export']));
$router->post('/privacidade/pedidos/{id}/anonimizar', $requireAuth([$privacyCtrl, 'processDeletion']));
$router->get('/pacientes/{id}', $requireAuth([$dashCtrl, 'profile360']));
$router->post('/pacientes/{id}/convite-app', $requireAuth([new \ClyoNutri\Application\Controllers\PatientOnboardingController(), 'invite']));
$router->get('/pacientes/{id}/prontuario', $requireAuth([$dashCtrl, 'patientMedicalRecord']));
$router->post('/pacientes/{id}/prontuario', $requireAuth([$dashCtrl, 'savePatientMedicalRecord']));
$router->post('/pacientes/{id}/prontuario/{recId}/retificar', $requireAuth([$dashCtrl, 'revisePatientMedicalRecord']));
$router->get('/pacientes/{id}/prontuario/exportar', $requireAuth([$dashCtrl, 'exportMedicalRecord']));
$router->get('/pacientes/{id}/prontuario/imprimir', $requireAuth([$dashCtrl, 'printMedicalRecord']));
$router->get('/pacientes/{id}/anamnese', $requireAuth([$dashCtrl, 'patientAnamnesis']));
$router->post('/pacientes/{id}/anamnese', $requireAuth([$dashCtrl, 'savePatientAnamnesis']));
$router->get('/pacientes/{id}/resumo-prescricao', $requireAuth([$dashCtrl, 'prescriptionSummary']));
$router->get('/pacientes/{id}/antropometria', $requireAuth([$dashCtrl, 'patientAnthropometry']));
$router->post('/pacientes/{id}/antropometria', $requireAuth([$dashCtrl, 'saveAnthropometry']));
$router->post('/pacientes/{id}/antropometria/{evalId}/compartilhar', $requireAuth([$dashCtrl, 'shareAnthropometry']));
$router->get('/pacientes/{id}/antropometria/comparar', $requireAuth([$dashCtrl, 'compareAnthropometry']));
// NI2-439: dobras cutâneas por protocolo validado
$skinfoldCtrl = new \ClyoNutri\Application\Controllers\SkinfoldController();
$router->get('/pacientes/{id}/antropometria/dobras', $requireAuth([$skinfoldCtrl, 'show']));
$router->post('/pacientes/{id}/antropometria/dobras', $requireAuth([$skinfoldCtrl, 'store']));
$router->post('/pacientes/{id}/antropometria/dobras/{mid}/corrigir', $requireAuth([$skinfoldCtrl, 'correct']));
// NI2-437: pré-consulta e anamnese adaptativa
$preconsCtrl = new \ClyoNutri\Application\Controllers\PreConsultationController();
$router->get('/pacientes/{id}/pre-consulta', $requireAuth([$preconsCtrl, 'index']));
$router->post('/pacientes/{id}/pre-consulta', $requireAuth([$preconsCtrl, 'create']));
$router->get('/pacientes/{id}/pre-consulta/{qid}', $requireAuth([$preconsCtrl, 'show']));
$router->post('/pacientes/{id}/pre-consulta/{qid}/perguntas', $requireAuth([$preconsCtrl, 'updateQuestions']));
$router->post('/pacientes/{id}/pre-consulta/{qid}/enviar', $requireAuth([$preconsCtrl, 'send']));
$router->post('/pacientes/{id}/pre-consulta/{qid}/revogar', $requireAuth([$preconsCtrl, 'revoke']));
$router->post('/pacientes/{id}/pre-consulta/{qid}/revisar', $requireAuth([$preconsCtrl, 'review']));
$router->post('/pacientes/{id}/pre-consulta/{qid}/importar', $requireAuth([$preconsCtrl, 'import']));
// Paciente (público, acesso pelo token do link + data de nascimento)
$router->get('/pre-consulta', [$preconsCtrl, 'patientPage']);
$router->post('/pre-consulta/verificar', [$preconsCtrl, 'patientVerify']);
$router->post('/pre-consulta/salvar', [$preconsCtrl, 'patientSave']);
$router->get('/pre-consulta/{token}', [$preconsCtrl, 'patientLink']);
$router->get('/catalogo/alimentos/busca', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'search']));
$router->get('/alimentos', $requireAuth([$dashCtrl, 'foods']));
$router->get('/agenda', $requireAuth([$dashCtrl, 'agenda']));
$router->post('/agenda', $requireAuth([$dashCtrl, 'saveAppointment']));
$router->get('/agenda/{id}/reagendar', $requireAuth([$dashCtrl, 'rescheduleAppointmentForm'])); // UX-04 (v14)
// NI2-137: pedidos de remarcação feitos pela paciente no app (aceitar/recusar).
$changeRequestCtrl = new \ClyoNutri\Application\Controllers\AppointmentChangeRequestController();
$router->get('/agenda/pedidos-remarcacao', $requireAuth([$changeRequestCtrl, 'index']));
$router->post('/agenda/pedidos-remarcacao/{id}/aceitar', $requireAuth([$changeRequestCtrl, 'approve']));
$router->post('/agenda/pedidos-remarcacao/{id}/recusar', $requireAuth([$changeRequestCtrl, 'decline']));
$router->post('/agenda/{id}/editar', $requireAuth([$dashCtrl, 'editAppointment']));
$router->post('/agenda/{id}/status', $requireAuth([$dashCtrl, 'updateAppointmentStatus']));
$router->get('/consulta/{id}', $requireAuth([$dashCtrl, 'consultationRoom']));
$router->post('/consulta/{id}/concluir', $requireAuth([$dashCtrl, 'completeConsultation']));
$router->get('/consulta/{id}/rascunho', $requireAuth([$dashCtrl, 'consultationDraftGet']));   // UX-03 (v14)
$router->post('/consulta/{id}/rascunho', $requireAuth([$dashCtrl, 'consultationDraftSave'])); // UX-03 (v14)

// 3.1 Consultório Online & Teleconsulta (NI2-221 / NI2-411)
// v11 (NI2-221): criação só por POST; o link do paciente vira cookie e redireciona para URL limpa.
$router->get('/teleconsulta/sessao-agenda/{appointmentId}', $requireAuth($requireTelehealth([$teleCtrl, 'openFromAppointment'])));
$router->post('/teleconsulta/sessao-agenda/{appointmentId}', $requireAuth($requireTelehealth([$teleCtrl, 'createFromAppointment'])));
$router->get('/teleconsulta/{id}/atendimento', $requireAuth($requireTelehealth([$teleCtrl, 'professionalRoom'])));
$router->post('/teleconsulta/{id}/admitir', $requireAuth($requireTelehealth([$teleCtrl, 'admit'])));
$router->post('/teleconsulta/{id}/rejeitar', $requireAuth($requireTelehealth([$teleCtrl, 'reject'])));
$router->post('/teleconsulta/{id}/evoluir', $requireAuth($requireTelehealth([$teleCtrl, 'recordEvolution'])));
$router->post('/teleconsulta/{id}/encerrar', $requireAuth($requireTelehealth([$teleCtrl, 'end'])));
$router->get('/teleconsulta/{id}/estado', $requireAuth($requireTelehealth([$teleCtrl, 'professionalState'])));
// F05: rota do paciente (cookie) antes de /teleconsulta/{id}/midia, senão 'sala' casaria como {id}
$router->post('/teleconsulta/sala/midia', $requireTelehealth([$teleCtrl, 'patientMedia']));
$router->post('/teleconsulta/{id}/midia', $requireAuth($requireTelehealth([$teleCtrl, 'professionalMedia'])));
$router->post('/teleconsulta/{id}/convite', $requireAuth($requireTelehealth([$teleCtrl, 'issueInvite'])));
$router->post('/teleconsulta/{id}/convite-email', $requireAuth($requireTelehealth([$teleCtrl, 'emailInvite'])));
$router->get('/teleconsulta/{id}/rascunho', $requireAuth($requireTelehealth([$teleCtrl, 'draftGet'])));
$router->post('/teleconsulta/{id}/rascunho', $requireAuth($requireTelehealth([$teleCtrl, 'draftSave'])));
// Paciente (F05: convite vira cookie; rotas limpas antes das com {token})
$router->get('/teleconsulta/sala-espera', $requireTelehealth([$teleCtrl, 'patientWaitingRoom']));
$router->get('/teleconsulta/sala-espera/status', $requireTelehealth([$teleCtrl, 'patientStatus']));
$router->post('/teleconsulta/sala-espera/dispositivo', $requireTelehealth([$teleCtrl, 'patientSubmitDeviceCheck']));
$router->post('/teleconsulta/sala-espera/consentir', $requireTelehealth([$teleCtrl, 'patientAcceptConsent']));
$router->post('/teleconsulta/sala-espera/entrar', $requireTelehealth([$teleCtrl, 'patientEnterWaiting']));
$router->get('/teleconsulta/sala', $requireTelehealth([$teleCtrl, 'patientConsultationRoom']));
$router->get('/teleconsulta/sala-espera/{token}', $requireTelehealth([$teleCtrl, 'patientLinkExchange']));
$router->get('/teleconsulta/sala/{token}', $requireTelehealth([$teleCtrl, 'patientLinkExchange']));
$router->get('/teleconsulta/sala-espera/{token}/status', $requireTelehealth([$teleCtrl, 'patientLegacyTokenApi']));
$router->post('/teleconsulta/sala-espera/{token}/{acao}', $requireTelehealth([$teleCtrl, 'patientLegacyTokenApi']));
$router->post('/teleconsulta/sala/{token}/midia', $requireTelehealth([$teleCtrl, 'patientLegacyTokenApi']));
// Sinalização WebRTC P2P (credencial de mídia no cabeçalho; revalidada a cada chamada)
$router->post('/teleconsulta/sinal/{room}', $requireTelehealth([$teleCtrl, 'signalSend']));
$router->get('/teleconsulta/sinal/{room}', $requireTelehealth([$teleCtrl, 'signalPoll']));
$router->get('/planos', $requireAuth([$dashCtrl, 'mealPlans']));
$router->post('/planos/novo', $requireAuth([$dashCtrl, 'createMealPlan']));
$router->get('/planos/{id}/imprimir', $requireAuth([$dashCtrl, 'printMealPlan']));
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
$router->get('/configuracoes/assinatura', $requireAuth([$subCtrl, 'show']));
$router->post('/configuracoes/assinatura/cancelar', $requireAuth([$subCtrl, 'cancel']));
$router->get('/api/v1/subscription', $requireAuth([$subCtrl, 'apiGet']));
$router->post('/api/v1/subscription/cancel', $requireAuth([$subCtrl, 'apiCancel']));

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

// 3.3 Canal Seguro de Comunicação & Chat (NI2-189 / NI2-411)
$router->get('/chat', $requireAuth($requireChat([$chatCtrl, 'index'])));
$router->get('/chat/pacientes', $requireAuth($requireChat([$chatCtrl, 'patientSearch'])));
$router->post('/chat/nova', $requireAuth($requireChat([$chatCtrl, 'startConversation'])));
$router->get('/chat/{id}', $requireAuth($requireChat([$chatCtrl, 'conversationView'])));
$router->post('/chat/{id}/enviar', $requireAuth($requireChat([$chatCtrl, 'sendMessage'])));
$router->get('/chat/{id}/poll', $requireAuth($requireChat([$chatCtrl, 'pollMessages'])));
$router->get('/chat/anexo/{id}', $requireAuth($requireChat([$chatCtrl, 'downloadAttachment'])));

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

// 3.5 Console Administrativo SaaS da Plataforma (NI2-346 / NI2-347 a NI2-361)
$router->get('/admin', $requireAuth([$adminCtrl, 'index']));
$router->get('/admin/dashboard', $requireAuth([$adminCtrl, 'dashboard']));
$router->get('/admin/teleconsulta/metricas', $requireAuth([$adminCtrl, 'teleconsultationMetrics']));
$router->get('/admin/metricas-adesao', $requireAuth([$adminCtrl, 'adherenceMetrics']));
$router->get('/admin/clinicas', $requireAuth([$adminCtrl, 'clinics']));
$router->post('/admin/clinicas/{id}/status', $requireAuth([$adminCtrl, 'updateClinicStatus']));
$router->get('/admin/usuarios', $requireAuth([$adminCtrl, 'users']));
$router->post('/admin/usuarios/{id}/status', $requireAuth([$adminCtrl, 'updateUserStatus']));
$router->post('/admin/usuarios/{id}/revogar-sessoes', $requireAuth([$adminCtrl, 'revokeUserSessions']));
$router->get('/admin/servicedesk', $requireAuth([$adminCtrl, 'serviceDesk']));
$router->get('/admin/comercial', $requireAuth([$adminCtrl, 'commercial']));
$router->get('/admin/acessos', $requireAuth([$adminCtrl, 'accessControl']));
$router->post('/admin/acessos/revogar-sessao', $requireAuth([$adminCtrl, 'revokeSession']));
$router->get('/admin/auditoria', $requireAuth([$adminCtrl, 'auditLogs']));
$router->get('/admin/configuracoes', $requireAuth([$adminCtrl, 'settings']));
$router->post('/admin/configuracoes', $requireAuth([$adminCtrl, 'saveSettings']));
$router->get('/admin/busca', $requireAuth([$adminCtrl, 'search']));

// 4. API Móvel do Paciente (/api/v1/patient/...)
$authenticatePatientApi = function() use ($db): array {
    $plainToken = \ClyoNutri\Core\Auth\MobileWebSession::token();
    if (!$plainToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado. Token Bearer ausente ou inválido.']);
        exit;
    }
    $hashed = hash('sha256', $plainToken);

    // Validação estrita: SEM bypass por plaintext e com expiração estrita obrigatória (expires_at NOT NULL - NI2-209)
    $stmt = $db->prepare("
        SELECT t.*, p.id AS patient_id, p.clinic_id, p.name AS patient_name
        FROM personal_access_tokens t
        JOIN patients p ON p.id = t.tokenable_id
        JOIN clinics c ON c.id = p.clinic_id
        WHERE t.tokenable_type = 'patient' AND t.clinic_id = p.clinic_id AND p.status = 'active' AND c.status = 'active'
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
    if (!empty($GLOBALS['__TEST_JSON_INPUT__'])) {
        $decodedTest = json_decode((string)$GLOBALS['__TEST_JSON_INPUT__'], true);
        if (is_array($decodedTest)) {
            return $decodedTest;
        }
    }
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
            if ($operation === 'complete') \ClyoNutri\Core\Auth\MobileWebSession::start($result['token'], $result['expires_at']);
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
    $otp = trim((string)($input['otp'] ?? ''));

    // 1. Proibição de login apenas por identificador (NI2-209)
    if (empty($password) && empty($otp)) {
        http_response_code(401);
        echo json_encode(['error' => 'Autenticação forte exigida: informe senha, PIN de acesso ou código OTP (login apenas por identificador proibido).']);
        return;
    }

    // Validação estrita de formato do OTP (quando fornecido)
    if ($otp !== '') {
        if (!preg_match('/^[0-9]{6}$/', $otp)) {
            http_response_code(400);
            echo json_encode(['error' => 'Formato de código OTP inválido. Deve conter exatamente 6 dígitos numéricos.']);
            return;
        }
    }

    // F03: senha e PIN chegam pelo mesmo campo e compartilham o MESMO contador persistente
    // (realm 'patient', conta normalizada + IP e só IP). Reservado antes de buscar o paciente e do password_verify,
    // então conta existente e inexistente consomem o limite igualmente. Limites de OTP abaixo continuam valendo.
    $patientLimiter = null;
    // V2-02: e-mail, telefone e aliases do mesmo paciente resolvem para a mesma identidade (patient#ID).
    $patientAccountKey = \ClyoNutri\Core\Security\LoginRateLimiter::resolvePatientIdentity($db, $email, $phone);
    $patientIpKey = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ($password !== '') {
        $patientLimiter = new \ClyoNutri\Core\Security\LoginRateLimiter($db);
        $gate = $patientLimiter->attempt('patient', $patientAccountKey, $patientIpKey);
        if (!$gate['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . max(1, (int)$gate['retry_after']));
            echo json_encode(['error' => 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.', 'retry_after' => (int)$gate['retry_after']]);
            return;
        }
    }

    // Helper para concorrência e retry em SQLite/MySQL sob corrida de processos
    $executeWithRetry = function(\PDOStatement &$stmt, array $params = []) use ($db): bool {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            try {
                @$stmt->closeCursor();
                return $stmt->execute($params);
            } catch (\PDOException $e) {
                @$stmt->closeCursor();
                $msg = strtolower($e->getMessage());
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                $isRetryable = str_contains($msg, 'locked') 
                    || str_contains($msg, 'busy') 
                    || str_contains($msg, 'deadlock') 
                    || str_contains($msg, 'timeout')
                    || str_contains($msg, 'misuse')
                    || $driverCode === 5 
                    || $driverCode === 6
                    || $driverCode === 21;
                if ($attempt < 59 && $isRetryable) {
                    usleep(random_int(30000, 150000));
                    if (!empty($stmt->queryString)) {
                        try {
                            $stmt = $db->prepare($stmt->queryString);
                        } catch (\Throwable) {}
                    }
                    continue;
                }
                throw $e;
            }
        }
        return false;
    };

    // Never match an omitted identifier against another patient's empty field.
    // Ambiguous identifiers fail closed until the clinic can be selected explicitly.
    $stmt = $db->prepare("SELECT p.* FROM patients p JOIN clinics c ON c.id = p.clinic_id
        WHERE ((:email_present <> '' AND p.email = :email) OR (:phone_present <> '' AND p.phone = :phone))
          AND p.status = 'active' AND c.status = 'active' LIMIT 2");
    $executeWithRetry($stmt, ['email_present' => $email, 'email' => $email, 'phone_present' => $phone, 'phone' => $phone]);
    $patients = $stmt->fetchAll();
    $stmt->closeCursor();
    if (count($patients) !== 1) {
        $patientLimiter?->failure('patient', $patientAccountKey, $patientIpKey);
        http_response_code(401);
        echo json_encode(['error' => 'Credencial inválida ou acesso indisponível.']);
        return;
    }
    $patient = $patients[0];
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $nowStr = date('Y-m-d H:i:s');

    // 2. Validação da credencial
    $authenticated = false;
    if (!empty($password)) {
        if (!empty($patient['password_hash']) && password_verify($password, $patient['password_hash'])) {
            $authenticated = true;
        } elseif (!empty($patient['access_pin']) && password_verify($password, $patient['access_pin'])) {
            $authenticated = true;
        }
        if ($patientLimiter !== null) {
            $authenticated
                ? $patientLimiter->success('patient', $patientAccountKey, $patientIpKey)
                : $patientLimiter->failure('patient', $patientAccountKey, $patientIpKey);
        }
    }

    if (!$authenticated && !empty($otp)) {

        // 0. Proteção contra força bruta: Limite configurável de tentativas inválidas de OTP por paciente nos últimos 15 min (NI2-405 / AUD-03 / NI2-407)
        $maxAttempts = \ClyoNutri\Core\Settings\PlatformSettings::getMaxLoginAttempts($db);
        $cutoff15m = date('Y-m-d H:i:s', time() - 900);
        $checkFailedStmt = $db->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE action = 'PATIENT_OTP_ATTEMPT_FAILED'
              AND user_id = :patient_id
              AND created_at >= :cutoff
        ");
        $executeWithRetry($checkFailedStmt, [
            'patient_id' => $patient['id'],
            'cutoff' => $cutoff15m,
        ]);
        $priorFailures = (int)$checkFailedStmt->fetchColumn();
        $checkFailedStmt->closeCursor();

        if ($priorFailures >= $maxAttempts) {
            // Garante revogação do OTP se ainda persistir
            if (!empty($patient['otp_code'])) {
                $revokeOtpStmt = $db->prepare("UPDATE patients SET otp_code = NULL, otp_expires_at = NULL WHERE id = :id");
                $executeWithRetry($revokeOtpStmt, ['id' => $patient['id']]);
            }
            http_response_code(429);
            echo json_encode(['error' => 'Muitas tentativas inválidas de código OTP. Por segurança, o código foi revogado. Solicite um novo código de acesso.']);
            return;
        }

        // Validação de expiração e consumo atômico
        if (empty($patient['otp_code'])) {
            $authenticated = false;
        } elseif (!empty($patient['otp_expires_at']) && strtotime((string)$patient['otp_expires_at']) <= time()) {
            // Expiração estrita: anula o código e registra evento seguro
            $expireStmt = $db->prepare("UPDATE patients SET otp_code = NULL, otp_expires_at = NULL WHERE id = :id");
            $executeWithRetry($expireStmt, ['id' => $patient['id']]);

            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (action, resource, resource_id, user_id, clinic_id, ip_address, details, created_at)
                VALUES ('PATIENT_OTP_EXPIRED', 'patient', :res_id, :uid, :cid, :ip, :details, :created_at)
            ");
            $executeWithRetry($auditStmt, [
                'res_id' => (string)$patient['id'],
                'uid' => $patient['id'],
                'cid' => $patient['clinic_id'],
                'ip' => $clientIp,
                'details' => json_encode(['reason' => 'Código OTP expirado.'], JSON_UNESCAPED_UNICODE),
                'created_at' => $nowStr,
            ]);

            http_response_code(401);
            echo json_encode(['error' => 'Código OTP expirado. Solicite um novo código de acesso.']);
            return;
        } elseif (hash_equals((string)$patient['otp_code'], $otp)) {
            // Consumo atômico de OTP: uso único garantido sob concorrência (AUD-03 / NI2-405)
            // Apenas uma das requisições simultâneas consegue afetar 1 linha
            $consumeStmt = $db->prepare("
                UPDATE patients 
                SET otp_code = NULL, otp_expires_at = NULL 
                WHERE id = :id AND otp_code = :otp AND (otp_expires_at IS NULL OR otp_expires_at > :now)
            ");
            $executeWithRetry($consumeStmt, [
                'id' => $patient['id'],
                'otp' => $otp,
                'now' => $nowStr,
            ]);
            $isRowAffected = ($consumeStmt->rowCount() === 1);
            $consumeStmt->closeCursor();
            if ($isRowAffected) {
                $authenticated = true;

                // Auditoria segura: nunca grava o código OTP
                $auditStmt = $db->prepare("
                    INSERT INTO audit_logs (action, resource, resource_id, user_id, clinic_id, ip_address, details, created_at)
                    VALUES ('PATIENT_OTP_LOGIN_SUCCESS', 'patient', :res_id, :uid, :cid, :ip, :details, :created_at)
                ");
                $executeWithRetry($auditStmt, [
                    'res_id' => (string)$patient['id'],
                    'uid' => $patient['id'],
                    'cid' => $patient['clinic_id'],
                    'ip' => $clientIp,
                    'details' => json_encode(['channel' => 'mobile_api', 'method' => 'otp_single_use'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $nowStr,
                ]);
            }
        }

        // Se o OTP falhou (código incorreto, expirado, já consumido ou corrida concorrente perdida)
        if (!$authenticated) {
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (action, resource, resource_id, user_id, clinic_id, ip_address, details, created_at)
                VALUES ('PATIENT_OTP_ATTEMPT_FAILED', 'patient', :res_id, :uid, :cid, :ip, :details, :created_at)
            ");
            $executeWithRetry($auditStmt, [
                'res_id' => (string)$patient['id'],
                'uid' => $patient['id'],
                'cid' => $patient['clinic_id'],
                'ip' => $clientIp,
                'details' => json_encode(['reason' => 'Código inválido ou já consumido.'], JSON_UNESCAPED_UNICODE),
                'created_at' => $nowStr,
            ]);

            // Se atingiu o limite de falhas com esta tentativa, revoga o código preventivamente e loga evento de rate limit
            if (($priorFailures + 1) >= $maxAttempts) {
                $revokeOtpStmt = $db->prepare("UPDATE patients SET otp_code = NULL, otp_expires_at = NULL WHERE id = :id");
                $executeWithRetry($revokeOtpStmt, ['id' => $patient['id']]);

                $auditStmt = $db->prepare("
                    INSERT INTO audit_logs (action, resource, resource_id, user_id, clinic_id, ip_address, details, created_at)
                    VALUES ('PATIENT_OTP_RATE_LIMITED', 'patient', :res_id, :uid, :cid, :ip, :details, :created_at)
                ");
                $executeWithRetry($auditStmt, [
                    'res_id' => (string)$patient['id'],
                    'uid' => $patient['id'],
                    'cid' => $patient['clinic_id'],
                    'ip' => $clientIp,
                    'details' => json_encode(['reason' => "Limite de {$maxAttempts} tentativas excedido. OTP revogado por segurança."], JSON_UNESCAPED_UNICODE),
                    'created_at' => $nowStr,
                ]);
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
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, clinic_id, name, token, expires_at, created_at)
        VALUES ('patient', :pid, :clinic_id, 'mobile_app', :token, :expires_at, CURRENT_TIMESTAMP)
    ");
    $executeWithRetry($ins, [
        'pid' => $patient['id'],
        'clinic_id' => $patient['clinic_id'],
        'token' => $hashed,
        'expires_at' => $expiresAt,
    ]);

    \ClyoNutri\Core\Auth\MobileWebSession::start($token, $expiresAt);
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
    \ClyoNutri\Core\Auth\MobileWebSession::clear();
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

    // Hidratação de hoje (ANSI SQL portável)
    $todayDate = date('Y-m-d');
    $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND clinic_id = :cid AND date(logged_at) = :today");
    $hStmt->execute(['pid' => $patientId, 'cid' => (int)$auth['clinic_id'], 'today' => $todayDate]);
    $waterToday = (int)$hStmt->fetchColumn();

    $checkinToday = (new \ClyoNutri\Domain\Engagement\EngagementService($db))->todayCheckin($patientId);
    // NI2-96: métrica agregada "plano visto" (sem conteúdo do plano, sem id da paciente no agregado).
    if ($plan) {
        (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($db))->record(\ClyoNutri\Domain\Analytics\AdherenceAnalytics::PLAN_VIEWED, (int)$auth['clinic_id'], $patientId);
    }

    echo json_encode([
        'patient_name' => $auth['patient_name'],
        'water_ml_today' => $waterToday,
        'water_goal_ml' => $waterGoal,
        'next_appointment' => $nextApp,
        'active_plan' => $plan,
        'checkin_today' => $checkinToday,
    ]);
});

// Registrar Hidratação
$router->post('/api/v1/patient/hydration', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();
    $rawAmount = $input['amount_ml'] ?? 250;
    if (!is_numeric($rawAmount) || (int)$rawAmount != $rawAmount) {
        http_response_code(422);
        echo json_encode(['error' => 'Informe o volume de água em ml (número inteiro).', 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
    try {
        $res = $apiCtrl->logHydration($patientId, (int)$rawAmount);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Retornar novo total acumulado hoje (ANSI SQL portável)
    $todayDate = date('Y-m-d');
    $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND clinic_id = :cid AND date(logged_at) = :today");
    $hStmt->execute(['pid' => $patientId, 'cid' => (int)$auth['clinic_id'], 'today' => $todayDate]);
    $res['total_water_today'] = (int)$hStmt->fetchColumn();
    (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($db))->record(\ClyoNutri\Domain\Analytics\AdherenceAnalytics::HYDRATION_LOGGED, (int)$auth['clinic_id'], $patientId);

    echo json_encode($res);
});

// Check-in diário (NI2-96): quanto do plano seguiu hoje (0–100 em passos de 25), energia e fome opcionais (1–5),
// observação opcional. Um por dia; reenviar no mesmo dia corrige. O dia vem do servidor.
$router->get('/api/v1/patient/checkin/today', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    echo json_encode(['checkin' => (new \ClyoNutri\Domain\Engagement\EngagementService($db))->todayCheckin((int)$auth['patient_id'])], JSON_UNESCAPED_UNICODE);
});
$router->post('/api/v1/patient/checkin', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    try {
        $res = (new \ClyoNutri\Domain\Engagement\EngagementService($db))->submitDailyCheckin($patientId, $getJsonInput());
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($db))->record(\ClyoNutri\Domain\Analytics\AdherenceAnalytics::CHECKIN_SUBMITTED, (int)$auth['clinic_id'], $patientId);
    http_response_code($res['created'] ? 201 : 200);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
});

// Próxima consulta: ações da paciente (NI2-137). Tudo passa pelo motor da agenda e pela política da clínica.
$patientAppointmentApi = static fn() => new \ClyoNutri\Application\Api\PatientAppointmentApiController($db);
$router->post('/api/v1/patient/appointment/confirm', function() use ($authenticatePatientApi, $getJsonInput, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $input = $getJsonInput();
    $patientAppointmentApi()->confirm((int)$auth['patient_id'], is_numeric($input['appointment_id'] ?? null) ? (int)$input['appointment_id'] : 0);
});
$router->post('/api/v1/patient/appointments/{id}/confirm', function($params = []) use ($authenticatePatientApi, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientAppointmentApi()->confirm((int)$auth['patient_id'], (int)($params['id'] ?? 0));
});
$router->post('/api/v1/patient/appointments/{id}/cancel', function($params = []) use ($authenticatePatientApi, $getJsonInput, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientAppointmentApi()->cancel((int)$auth['patient_id'], (int)($params['id'] ?? 0), $getJsonInput());
});
$router->get('/api/v1/patient/appointments/{id}/reschedule-options', function($params = []) use ($authenticatePatientApi, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientAppointmentApi()->rescheduleOptions((int)$auth['patient_id'], (int)($params['id'] ?? 0));
});
$router->post('/api/v1/patient/appointments/{id}/reschedule-requests', function($params = []) use ($authenticatePatientApi, $getJsonInput, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientAppointmentApi()->requestReschedule((int)$auth['patient_id'], (int)($params['id'] ?? 0), $getJsonInput());
});
// Rota antiga: deixou de mover a consulta na hora; agora registra o pedido (202), como a rota acima.
$router->post('/api/v1/patient/appointments/{id}/reschedule', function($params = []) use ($authenticatePatientApi, $getJsonInput, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientAppointmentApi()->requestReschedule((int)$auth['patient_id'], (int)($params['id'] ?? 0), $getJsonInput());
});
$router->post('/api/v1/patient/reschedule-requests/{id}/cancel', function($params = []) use ($authenticatePatientApi, $patientAppointmentApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientAppointmentApi()->withdrawRequest((int)$auth['patient_id'], (int)($params['id'] ?? 0));
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
    // NI2-89: o app não envia arquivos por esta rota; caminho de foto vindo do cliente não é aceito.
    unset($input['photo_path']);

    $foodDiary = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
    try {
        $res = $foodDiary->logMeal($patientId, $input);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode($res);
});

// Obter Linha do Tempo de Evolução
$router->get('/api/v1/patient/evolution', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : null;
    $to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : null;
    try {
        $data = (new \ClyoNutri\Domain\Evolution\PatientEvolutionService($db))->timeline($patientId, $from, $to);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
});

// Registrar Autorrelato (NI2-135): só métricas habilitadas pelo profissional, unidade e faixa do catálogo.
$router->post('/api/v1/patient/self-metrics', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();
    $type = $input['metric_type'] ?? null;
    $unit = $input['unit'] ?? null;
    $notes = $input['notes'] ?? null;
    if (!is_string($type) || ($unit !== null && !is_string($unit)) || ($notes !== null && !is_string($notes)) || !array_key_exists('value', $input)) {
        http_response_code(422);
        echo json_encode(['error' => 'Informe a medida (metric_type) e o valor (value).', 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    try {
        $res = (new \ClyoNutri\Domain\Evolution\PatientEvolutionService($db))->recordSelfMetric($patientId, $type, $input['value'], $unit, $notes);
        http_response_code(201);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } catch (\ClyoNutri\Domain\Evolution\SelfMetricNotEnabledException $e) {
        http_response_code(403);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'METRIC_NOT_ENABLED'], JSON_UNESCAPED_UNICODE);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
    }
});

// 4.1 Chat seguro da paciente (NI2-189, revisto na NI2-138): vínculo ativo, limites, anexos, chave de desligar.
$chatApi = static fn() => new \ClyoNutri\Application\Api\ChatApiController($db);
$router->get('/api/v1/patient/chat/messages', function() use ($authenticatePatientApi, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $after = isset($_GET['after_id']) && ctype_digit((string)$_GET['after_id']) ? (int)$_GET['after_id'] : null;
    $prof = isset($_GET['professional_id']) && ctype_digit((string)$_GET['professional_id']) ? (int)$_GET['professional_id'] : null;
    $api->patientMessages((int)$auth['patient_id'], $after, $prof);
});
$router->post('/api/v1/patient/chat/send', function() use ($authenticatePatientApi, $getJsonInput, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    // JSON (só texto) ou multipart (texto + arquivo "attachment").
    $file = !empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['attachment'] : null;
    $api->patientSend((int)$auth['patient_id'], $getJsonInput(), $file);
});
$router->post('/api/v1/patient/chat/read', function() use ($authenticatePatientApi, $getJsonInput, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->patientRead((int)$auth['patient_id'], $getJsonInput());
});
$router->get('/api/v1/patient/chat/attachment/{id}', function($params = []) use ($authenticatePatientApi, $chatApi) {
    $auth = $authenticatePatientApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->patientDownload((int)$auth['patient_id'], (int)($params['id'] ?? 0));
});
$router->post('/api/v1/patient/chat/attachment/{id}/link', function($params = []) use ($authenticatePatientApi, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->patientAttachmentLink((int)$auth['patient_id'], (int)($params['id'] ?? 0));
});
// Link assinado e curto (2 min) para abrir o anexo no navegador/sistema do aparelho, sem levar o token de sessão.
$router->get('/api/v1/chat/anexo/{token}', function($params = []) use ($chatApi) {
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->downloadByToken((string)($params['token'] ?? ''));
});

// 4.4 Privacidade da paciente: consentimentos e pedidos LGPD (code review NI2-91, C1)
$router->get('/api/v1/patient/privacy', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $consents = array_map(fn(array $c) => [
        'purpose' => $c['consent_type'],
        'term_title' => $c['term_title'],
        'term_version' => $c['term_version'],
        'granted' => (bool)$c['granted'],
        'granted_at' => $c['granted_at'],
        'revoked_at' => $c['revoked_at'],
        'revocation_reason' => $c['revocation_reason'],
    ], (new \ClyoNutri\Domain\Privacy\ConsentManagementService($db))->getConsentAuditTrail($patientId));
    $requests = (new \ClyoNutri\Domain\Privacy\DataSubjectRequestService($db))->listForPatient($patientId);
    echo json_encode(['consents' => $consents, 'requests' => $requests], JSON_UNESCAPED_UNICODE);
});

$router->post('/api/v1/patient/privacy-requests', function() use ($authenticatePatientApi, $db, $getJsonInput) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $input = $getJsonInput();
    $type = $input['request_type'] ?? null;
    if (!in_array($type, ['export', 'deletion'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Escolha o tipo de pedido: export (cópia dos dados) ou deletion (exclusão).', 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (isset($input['details']) && (!is_string($input['details']) || mb_strlen($input['details']) > 1000)) {
        http_response_code(422);
        echo json_encode(['error' => 'O comentário deve ter no máximo 1000 caracteres.', 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $request = (new \ClyoNutri\Domain\Privacy\DataSubjectRequestService($db))->openRequest(
        (int)$auth['patient_id'], $type, $input['details'] ?? null, \ClyoNutri\Domain\Privacy\DataSubjectRequestService::CHANNEL_PATIENT_APP);
    http_response_code($request['already_open'] ? 200 : 201);
    echo json_encode(['request' => $request], JSON_UNESCAPED_UNICODE);
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
        $permission = $db->prepare('SELECT id, recipe_version_id FROM recipe_prescriptions WHERE recipe_id = :recipe_id AND patient_id = :patient_id AND clinic_id = :clinic_id ORDER BY id DESC LIMIT 1');
        $permission->execute(['recipe_id' => $recipeId, 'patient_id' => (int)$auth['patient_id'], 'clinic_id' => (int)$auth['clinic_id']]);
        $prescription = $permission->fetch(PDO::FETCH_ASSOC);
        if (!$prescription) {
            http_response_code(404);
            echo json_encode(['error' => 'Receita não encontrada.']);
            return;
        }
        if (isset($_GET['prescription_id'])) {
            $selected = $db->prepare('SELECT id, recipe_version_id FROM recipe_prescriptions WHERE id = :id AND recipe_id = :recipe AND patient_id = :patient AND clinic_id = :clinic');
            $selected->execute(['id' => (int)$_GET['prescription_id'], 'recipe' => $recipeId, 'patient' => (int)$auth['patient_id'], 'clinic' => (int)$auth['clinic_id']]);
            $prescription = $selected->fetch(PDO::FETCH_ASSOC);
            if (!$prescription) {
                http_response_code(404);
                echo json_encode(['error' => 'Prescrição não encontrada.']);
                return;
            }
        }
        $versionId = $prescription['recipe_version_id'] ? (int)$prescription['recipe_version_id'] : null;
        if (isset($_GET['version_id']) && (int)$_GET['version_id'] !== $versionId) {
            http_response_code(404);
            echo json_encode(['error' => 'Versão não corresponde à prescrição.']);
            return;
        }
        $details = $recipeService->getRecipeDetails($recipeId, (int)$auth['clinic_id'], $versionId);
        echo json_encode(['data' => $details]);
    } catch (\ClyoNutri\Domain\Recipe\PrescriptionContentUnavailable $e) {
        http_response_code(404);
        echo json_encode(['error' => 'O conteúdo desta prescrição está indisponível. Solicite uma nova prescrição ao seu nutricionista.', 'code' => 'prescription_content_unavailable'], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível carregar a receita. Tente novamente.'], JSON_UNESCAPED_UNICODE);
    }
});

$router->post('/api/v1/patient/recipes/{id}/favorite', function($params = []) use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $recipeId = (int)($params['id'] ?? 0);

    $recipeService = new \ClyoNutri\Domain\Recipe\RecipeService($db);
    try {
        $isFav = $recipeService->togglePatientFavorite($patientId, $recipeId);
    } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
        http_response_code(404);
        echo json_encode(['error' => 'Receita não encontrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
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
    $plainToken = \ClyoNutri\Core\Auth\MobileWebSession::token();
    if (!$plainToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado. Token Bearer ausente ou inválido.']);
        exit;
    }
    $hashed = hash('sha256', $plainToken);

    $stmt = $db->prepare("
        SELECT t.*, u.id AS user_id, u.name AS user_name, u.email AS user_email, u.role AS user_role, cm.role AS member_role, cm.clinic_id, c.name AS clinic_name
        FROM personal_access_tokens t
        JOIN users u ON u.id = t.tokenable_id
        JOIN clinic_members cm ON cm.user_id = u.id AND cm.clinic_id = t.clinic_id AND cm.status = 'active'
        JOIN clinics c ON c.id = cm.clinic_id AND c.status = 'active'
        WHERE t.tokenable_type = 'professional' AND t.auth_version = u.auth_version AND u.status = 'active'
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

    if (!\ClyoNutri\Core\Security\ClinicalAuthorization::isClinical($tokenRow)) {
        TenantContext::set((int)$tokenRow['clinic_id'], ['id'=>(int)$tokenRow['user_id'],'role'=>$tokenRow['user_role']]);
        try { \ClyoNutri\Core\Security\ClinicalAuthorization::deny($db); }
        catch (\ClyoNutri\Core\Security\TenantSecurityException $e) { http_response_code(403); echo json_encode(['error'=>'Acesso profissional revogado.']); exit; }
    }

    // Buscar ID profissional correspondente ao user_id
    $pStmt = $db->prepare("SELECT id FROM professionals WHERE user_id = :uid LIMIT 1");
    $pStmt->execute(['uid' => $tokenRow['user_id']]);
    $profId = (int)$pStmt->fetchColumn();
    $tokenRow['professional_id'] = $profId > 0 ? $profId : (int)$tokenRow['user_id'];

    \ClyoNutri\Core\Auth\TenantContext::set((int)$tokenRow['clinic_id'], [
        'id' => (int)$tokenRow['user_id'],
        'role' => $tokenRow['user_role'],
        'name' => $tokenRow['user_name'],
        'professional_id' => $tokenRow['professional_id'],
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
    } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) { http_response_code(403); echo json_encode(['error'=>'Convite indisponível para este paciente.']); }
    catch (\DomainException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
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

    // F03: limitador compartilhado com o login web (mesmo realm 'staff'), antes de qualquer password_verify.
    $loginLimiter = new \ClyoNutri\Core\Security\LoginRateLimiter($db);
    $clientIpPro = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $gate = $loginLimiter->attempt('staff', $email, $clientIpPro);
    if (!$gate['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . max(1, (int)$gate['retry_after']));
        echo json_encode(['error' => 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.', 'retry_after' => (int)$gate['retry_after']]);
        return;
    }

    $stmt = $db->prepare("
        SELECT u.*, cm.role AS member_role, cm.clinic_id, c.name AS clinic_name
        FROM users u
        JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
        JOIN clinics c ON c.id = cm.clinic_id AND c.status = 'active'
        WHERE u.email = :email AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $loginLimiter->failure('staff', $email, $clientIpPro);
        http_response_code(401);
        echo json_encode(['error' => 'Credencial profissional inválida ou acesso indisponível.']);
        return;
    }

    $loginLimiter->success('staff', $email, $clientIpPro);

    // Validação de papel clínico (RBAC)
    if (!\ClyoNutri\Core\Security\ClinicalAuthorization::isClinical(['user_role'=>$user['role'],'member_role'=>$user['member_role']])) {
        http_response_code(403);
        echo json_encode(['error' => 'Conta sem autorização clínica ou profissional.']);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $hashed = hash('sha256', $token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 86400);

    $ins = $db->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, clinic_id, name, token, expires_at, created_at, auth_version)
        VALUES ('professional', :uid, :clinic_id, 'mobile_app_professional', :token, :expires_at, CURRENT_TIMESTAMP, :auth_version)
    ");
    $ins->execute([
        'uid' => $user['id'],
        'auth_version' => $user['auth_version'],
        'clinic_id' => $user['clinic_id'],
        'token' => $hashed,
        'expires_at' => $expiresAt,
    ]);

    \ClyoNutri\Core\Auth\MobileWebSession::start($token, $expiresAt);
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
    \ClyoNutri\Core\Auth\MobileWebSession::clear();
    echo json_encode(['message' => 'Token profissional revogado com sucesso.']);
});

// Restore identity only; the persistent secret is never returned to JavaScript.
$router->get('/api/v1/auth/session', function() use ($db, $authenticatePatientApi, $authenticateProfessionalApi) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = \ClyoNutri\Core\Auth\MobileWebSession::token();
    $stmt = $db->prepare('SELECT tokenable_type FROM personal_access_tokens WHERE token = :token');
    $stmt->execute(['token' => hash('sha256', $token ?? '')]);
    $type = $stmt->fetchColumn();
    if ($type === 'patient') {
        $auth = $authenticatePatientApi();
        $p = $db->prepare('SELECT email FROM patients WHERE id = :id AND clinic_id = :clinic');
        $p->execute(['id' => $auth['patient_id'], 'clinic' => $auth['clinic_id']]);
        echo json_encode(['token' => '', 'expires_at' => $auth['expires_at'], 'patient' => ['id' => (int)$auth['patient_id'], 'clinic_id' => (int)$auth['clinic_id'], 'name' => $auth['patient_name'], 'email' => (string)$p->fetchColumn()]]);
    } elseif ($type === 'professional') {
        $auth = $authenticateProfessionalApi();
        echo json_encode(['token' => '', 'expires_at' => $auth['expires_at'], 'professional' => ['id' => (int)$auth['user_id'], 'clinic_id' => (int)$auth['clinic_id'], 'clinic_name' => $auth['clinic_name'], 'name' => $auth['user_name'], 'email' => $auth['user_email'], 'role' => $auth['user_role']]]);
    } else {
        \ClyoNutri\Core\Auth\MobileWebSession::clear();
        http_response_code(401); echo json_encode(['error' => 'Sessão indisponível. Entre novamente.']);
    }
});

$router->get('/api/v1/auth/access-help', function() {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $supportEmail = trim((string)Env::get('APP_SUPPORT_EMAIL', ''));
    echo json_encode(['support_email' => filter_var($supportEmail, FILTER_VALIDATE_EMAIL) ? $supportEmail : null]);
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

// Lista de Pacientes do Nutricionista no Mobile (NI2-292 / MOB-UX-008: busca e paginação completa)
$router->get('/api/v1/professional/patients', function() use ($authenticateProfessionalApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];

    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    $whereSql = "WHERE clinic_id = :cid";
    $params = ['cid' => $clinicId];

    if ($search !== '') {
        $whereSql .= " AND (name LIKE :term OR email LIKE :term OR phone LIKE :term)";
        $params['term'] = '%' . $search . '%';
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM patients {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT id, name, email, phone, status, created_at
        FROM patients
        {$whereSql}
        ORDER BY name ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $patients = $stmt->fetchAll();

    echo json_encode([
        'patients' => $patients,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'has_more' => ($offset + count($patients)) < $total,
    ]);
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
        echo json_encode(['error' => 'Registro no diário não encontrado.']);
    }
});

// 5.2 Chat do profissional no app (NI2-292 / NI2-189, revisto na NI2-138)
$router->get('/api/v1/professional/chat/conversations', function() use ($authenticateProfessionalApi, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->professionalConversations((int)$auth['professional_id']);
});
$router->get('/api/v1/professional/chat/{id}/messages', function(array $params) use ($authenticateProfessionalApi, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $after = isset($_GET['after_id']) && ctype_digit((string)$_GET['after_id']) ? (int)$_GET['after_id'] : null;
    $api->professionalMessages((int)$auth['professional_id'], (int)($params['id'] ?? 0), $after);
});
$router->post('/api/v1/professional/chat/{id}/send', function(array $params) use ($authenticateProfessionalApi, $getJsonInput, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->professionalSend((int)$auth['professional_id'], (int)($params['id'] ?? 0), $getJsonInput());
});
$router->post('/api/v1/professional/chat/{id}/read', function(array $params) use ($authenticateProfessionalApi, $chatApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticateProfessionalApi();
    $api = $chatApi();
    if (!$api->enabled()) return;
    $api->professionalRead((int)$auth['professional_id'], (int)($params['id'] ?? 0));
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router->dispatch($method, $uri);
