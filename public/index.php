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
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-ClyoNutri-Web, Idempotency-Key');
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
// NI2-604 (UX-R3-03): a sessão caía no meio do uso. Causas tratadas aqui:
//  - o cookie tinha validade fixa de 7200 s a partir do login e nunca era renovado;
//  - as sessões ficavam na pasta comum da hospedagem, limpa com o gc_maxlifetime padrão (24 min) de qualquer site.
// Agora: pasta própria (storage/sessions), gc_maxlifetime = tempo de inatividade da plataforma + 10 min, e o cookie
// é renovado a cada requisição de quem está logado. O encerramento continua sendo pela inatividade (requireAuth).
$sessionTtl = 7200;
try {
    $sessionTtl = \ClyoNutri\Core\Settings\PlatformSettings::getSessionTimeoutMinutes($db) * 60;
} catch (\Throwable $e) {
    $sessionTtl = 7200;
}
$sessionCookieSecure = \ClyoNutri\Core\Http\Transport::isHttps() || \ClyoNutri\Core\Http\Transport::forceHttpsEnabled();
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    $sessionDir = dirname(__DIR__) . '/storage/sessions';
    if ((is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) && is_writable($sessionDir)) {
        ini_set('session.save_path', $sessionDir);
        ini_set('session.gc_probability', '1'); // pasta própria: a limpeza do servidor não passa por ela
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)($sessionTtl + 600));
    session_set_cookie_params([
        'lifetime' => $sessionTtl,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // NI2-427: Secure sempre que a requisição chegou por HTTPS (inclui proxy confiável)
        // ou quando FORCE_HTTPS está ativo (nesse caso HTTP nunca chega até aqui).
        'secure' => $sessionCookieSecure,
    ]);
    session_start();
    if (!empty($_SESSION['user_id']) && !headers_sent()) {
        // NI2-604: renova a validade do cookie a cada requisição (antes vencia 2 h depois do login, mesmo em uso).
        setcookie(session_name(), session_id(), ['expires' => time() + $sessionTtl, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => $sessionCookieSecure]);
    }
}

/** NI2-604: caminho interno para voltar depois do login (só GET de página, nunca URL externa nem /login). */
$sessionReturnPath = static function (): ?string {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return null;
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    return \ClyoNutri\Core\Http\SafeReturnPath::clean($uri);
};

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
$asaasWebhookCtrl = new \ClyoNutri\Application\Controllers\AsaasWebhookController();

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
$router->get('/verificar-email/{token}', [$authCtrl, 'verifyEmail']);

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

        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Manutenção - Clyo Nutri</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1.5rem;box-sizing:border-box;}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:2.5rem;max-width:520px;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.08);}h1{font-size:1.35rem;color:#0f172a;margin-top:0;}p{font-size:0.92rem;color:#64748b;line-height:1.5;}</style></head><body><div class="card"><h1>Manutenção Programada em Andamento</h1><p>A plataforma Clyo Nutri está passando por calibração de segurança. O acesso aos consultórios será restabelecido em breve.</p><p style="font-size:0.8rem;color:#94a3b8;margin-top:1.5rem;">HTTP 503 Service Unavailable &bull; Modo de Manutenção Ativo</p></div></body></html>';
        exit;
    }
}

// Middleware de proteção: se tentar acessar rotas privadas sem login ou com vínculo revogado, bloqueia e redireciona (PX-23 / NI2-316 / NI2-256)
$requireAuth = function (callable $action) use ($db, $sessionReturnPath) {
    return function ($params = []) use ($action, $db, $sessionReturnPath) {
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
            $back = $sessionReturnPath();
            \ClyoNutri\Core\Http\Response::redirect('/login?error=session_timeout' . ($back !== null ? '&voltar=' . rawurlencode($back) : ''));
        }
        $_SESSION['last_activity'] = time();

        if (empty($_SESSION['user_id']) || empty($_SESSION['clinic_id'])) {
            if ($wantsJsonAuthError) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'unauthenticated', 'error' => 'Sessão expirada ou não autenticada.']);
                exit;
            }
            // NI2-604: quem tinha cookie de sessão e chegou sem login teve a sessão encerrada: o login diz o motivo
            // e, depois de entrar, volta para esta página.
            $back = $sessionReturnPath();
            $hadSession = isset($_COOKIE[session_name()]);
            $q = array_filter(['error' => $hadSession ? 'session_expired' : null, 'voltar' => $back]);
            \ClyoNutri\Core\Http\Response::redirect('/login' . ($q ? '?' . http_build_query($q) : ''));
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
            // v54.57 (UX-104): navegação no navegador recebe a página de erro com o layout e um caminho permitido.
            if (!$wantsJsonAuthError && \ClyoNutri\Core\Http\ErrorPage::wantsHtml()) {
                \ClyoNutri\Core\Http\ErrorPage::render(403, 'Acesso não permitido para o seu perfil', 'Esta página tem dados clínicos ou de gestão que o seu perfil nesta clínica não acessa. Nada foi alterado. Use um dos caminhos abaixo.');
                return;
            }
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Acesso não autorizado para este perfil.']);
            return;
        }
        // UX-002: validate explicit consultation context before the controller can read/write.
        try {
            \ClyoNutri\Application\View\AttendanceContext::begin($db);
        } catch (\DomainException $e) {
            http_response_code(403);
            $contextMessage='Atendimento não disponível para este paciente e perfil. Reabra a consulta pela agenda.';
            if ($wantsJsonAuthError) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$contextMessage]); }
            else \ClyoNutri\Core\Http\ErrorPage::render(403, 'Atendimento não disponível', $contextMessage, [['Abrir a agenda', '/agenda'], ['Ver pacientes', '/pacientes']]); // v54.57 (R55-01)
            return;
        } catch (\Throwable $e) {
            http_response_code(503);
            $contextMessage='Não foi possível conferir o atendimento. Tente novamente antes de salvar.';
            if ($wantsJsonAuthError) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$contextMessage]); }
            else \ClyoNutri\Core\Http\ErrorPage::render(503, 'Não foi possível conferir o atendimento', $contextMessage, [['Abrir a agenda', '/agenda']]); // v54.57 (R55-01)
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
        // NI2-449: sem aceite da versão vigente dos Termos/Política, a navegação vai para /aceite-termos.
        $legalRedirect = \ClyoNutri\Domain\Legal\LegalAcceptanceGate::redirectFor(
            $db,
            $authUser->getUserId(),
            $authUser->isSupport() || $authUser->isPlatformAdmin(),
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string)($_SERVER['REQUEST_URI'] ?? '/'),
            $wantsJsonAuthError
        );
        if ($legalRedirect !== null) {
            \ClyoNutri\Core\Http\Response::redirect($legalRedirect);
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
$router->get('/planos-assinatura', [$landingCtrl, 'plans']);

// Portal & App do Paciente (P03 - Auditoria Navegação Assistida)
// v20: /app colide com a pasta app/ da raiz (Require all denied => 403 da hospedagem).
// URL pública passa a ser /paciente/app; /app fica como alias (e o .htaccess redireciona com 301).
$router->get('/paciente/app', [$patientAppCtrl, 'index']);
$router->post('/paciente/app/primeiro-acesso', [$patientAppCtrl, 'completeFirstAccess']);
$router->post('/paciente/app/login', [$patientAppCtrl, 'login']);
$router->post('/paciente/app/aceite-termos', [$patientAppCtrl, 'acceptTerms']); // NI2-449
// v54.57 (UX-102): portal web com sessão real (cookie HttpOnly do login): plano em leitura, água e sair.
$router->get('/paciente/plano', [$patientAppCtrl, 'plan']);
$router->post('/paciente/hidratacao', [$patientAppCtrl, 'logWater']);
$router->post('/paciente/app/sair', [$patientAppCtrl, 'logout']);
$router->get('/app', [$patientAppCtrl, 'index']);
$router->post('/app/primeiro-acesso', [$patientAppCtrl, 'completeFirstAccess']);
$router->post('/app/login', [$patientAppCtrl, 'login']);
$router->post('/app/aceite-termos', [$patientAppCtrl, 'acceptTerms']); // NI2-449 (alias, como os demais /app)

// Recuperação de senha do paciente (NI2-481). Públicas; CSRF nos POST é validado pelo Router.
$patientPwResetCtrl = new \ClyoNutri\Application\Controllers\PatientPasswordResetController();
$router->get('/paciente/recuperar-senha', [$patientPwResetCtrl, 'showRequest']);
$router->post('/paciente/recuperar-senha', [$patientPwResetCtrl, 'submitRequest']);
$router->get('/paciente/recuperar-senha/{token}', [$patientPwResetCtrl, 'showReset']);
$router->post('/paciente/recuperar-senha/{token}', [$patientPwResetCtrl, 'submitReset']);

// Autoagendamento Self-Service (NI2-433 / NI2-434)
$router->get('/nutricionistas', [$bookingCtrl, 'publicDirectoryPage']);
$router->get('/paciente/lista-espera', [$bookingCtrl, 'waitlistPage']);
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

// NI2-449: reaceite dos Termos de Uso e da Política de Privacidade (profissional e equipe).
$legalAcceptanceCtrl = new \ClyoNutri\Application\Controllers\LegalAcceptanceController($db);
$router->get('/aceite-termos', $requireAuth([$legalAcceptanceCtrl, 'show']));
$router->post('/aceite-termos', $requireAuth([$legalAcceptanceCtrl, 'accept']));
$router->get('/dashboard', $requireAuth([$dashCtrl, 'index']));
// NI2-604: aviso de fim de sessão. /sessao/estado só lê (não conta como uso); /sessao/renovar passa pelo
// requireAuth, que registra a atividade (é o "Continuar conectado" e o uso contínuo na mesma página).
$router->get('/sessao/estado', function () use ($sessionTtl) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (empty($_SESSION['user_id']) || empty($_SESSION['last_activity'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        return;
    }
    $remaining = max(0, $sessionTtl - (time() - (int)$_SESSION['last_activity']));
    echo json_encode(['authenticated' => $remaining > 0, 'remaining' => $remaining, 'ttl' => $sessionTtl]);
});
$router->get('/sessao/renovar', $requireAuth(function () use ($sessionTtl) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['authenticated' => true, 'remaining' => $sessionTtl, 'ttl' => $sessionTtl]);
}));
$router->get('/pacientes', $requireAuth([$dashCtrl, 'patients']));
$router->post('/pacientes', $requireAuth([$dashCtrl, 'savePatient']));
// NI2-522 (v42): importação de pacientes por CSV com prévia, relatório por linha e desfazer. Antes de /pacientes/{id}.
$patientImportCtrl = new \ClyoNutri\Application\Controllers\PatientImportController();
$router->get('/pacientes/importar', $requireAuth([$patientImportCtrl, 'index']));
$router->get('/pacientes/importar/modelo.csv', $requireAuth([$patientImportCtrl, 'template']));
$router->post('/pacientes/importar/previa', $requireAuth([$patientImportCtrl, 'preview']));
$router->get('/pacientes/importar/{id}', $requireAuth([$patientImportCtrl, 'show']));
$router->post('/pacientes/importar/{id}/confirmar', $requireAuth([$patientImportCtrl, 'confirm']));
$router->post('/pacientes/importar/{id}/descartar', $requireAuth([$patientImportCtrl, 'discard']));
$router->post('/pacientes/importar/{id}/desfazer', $requireAuth([$patientImportCtrl, 'rollback']));
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
$router->post('/pacientes/{id}/iniciar-agora', $requireAuth([$dashCtrl, 'startCareNow'])); // NI2-705
$router->get('/pacientes/{id}/preparar', $requireAuth([$dashCtrl, 'prepareConsultation'])); // NI2-712
$router->get('/pacientes/{id}/plano', $requireAuth([$dashCtrl, 'patientPlansTab'])); // NI2-711
$router->get('/pacientes/{id}/documentos', $requireAuth([$dashCtrl, 'patientDocumentsTab'])); // NI2-711
$router->get('/pacientes/{id}/prontuario', $requireAuth([$dashCtrl, 'patientMedicalRecord']));
$router->post('/pacientes/{id}/prontuario', $requireAuth([$dashCtrl, 'savePatientMedicalRecord']));
$router->post('/pacientes/{id}/prontuario/{recId}/retificar', $requireAuth([$dashCtrl, 'revisePatientMedicalRecord']));
$router->get('/pacientes/{id}/prontuario/exportar', $requireAuth([$dashCtrl, 'exportMedicalRecord']));
$router->get('/pacientes/{id}/prontuario/imprimir', $requireAuth([$dashCtrl, 'printMedicalRecord']));
$router->get('/pacientes/{id}/anamnese', $requireAuth([$dashCtrl, 'patientAnamnesis']));
$router->post('/pacientes/{id}/anamnese', $requireAuth([$dashCtrl, 'savePatientAnamnesis']));
$router->post('/pacientes/{id}/anamnese/recuperar-rascunho', $requireAuth([$dashCtrl, 'recoverAnamnesisDraft'])); // v54.57 (UX-103)
$router->post('/pacientes/{id}/alergias/revisao', $requireAuth([$dashCtrl, 'dismissAllergyHistory'])); // NI2-624
// NI2-626/631: authenticated canonical draft mutations (session + CSRF).
$workflowCtrl = new \ClyoNutri\Application\Controllers\WorkflowController($db);
$router->get('/api/workflow/{kind}/{id}', $requireAuth([$workflowCtrl, 'state']));
$router->post('/api/workflow/{kind}/{id}', $requireAuth([$workflowCtrl, 'mutate']));
// NI2-635: anamnese adaptativa (tudo 404 com feature_flag_clinical_protocols = 0)
$cpCtrl = new \ClyoNutri\Application\Controllers\ClinicalProtocolController($db);
$router->get('/configuracoes/protocolos', $requireAuth([$cpCtrl, 'settings']));
$router->post('/configuracoes/protocolos', $requireAuth([$cpCtrl, 'toggle']));
// NI2-701: protected artifact diagnostics; canonical roles are checked again in the controller.
$buildIdentityCtrl = new \ClyoNutri\Application\Controllers\BuildIdentityController();
$router->get('/configuracoes/versao', $requireAuth([$buildIdentityCtrl, 'show']));
$router->get('/admin/versao', $requireAuth([$buildIdentityCtrl, 'show']));
$router->get('/suporte/versao', $requireAuth([$buildIdentityCtrl, 'show']));
$router->get('/configuracoes/interface', $requireAuth(function() {
    $title='Componentes da interface';$activeNav='configuracoes';$clinicName=$_SESSION['clinic_name']??'';$userName=$_SESSION['user_name']??'';
    ob_start();require __DIR__.'/../views/settings/operational_system.php';$content=ob_get_clean();require __DIR__.'/../views/layouts/professional-shell.php';
}));
$cpEditorCtrl = new \ClyoNutri\Application\Controllers\ClinicProtocolEditorController($db);
$router->get('/configuracoes/protocolos/editor', $requireAuth([$cpEditorCtrl, 'page']));
$router->post('/configuracoes/protocolos/editor', $requireAuth([$cpEditorCtrl, 'mutate']));
$router->post('/pacientes/{id}/condicoes', $requireAuth([$cpCtrl, 'recordCondition']));
$router->post('/pacientes/{id}/condicoes/sugerir', $requireAuth([$cpCtrl, 'suggest']));
$router->post('/pacientes/{id}/condicoes/{cid}/confirmar', $requireAuth([$cpCtrl, 'confirmCondition']));
$router->post('/pacientes/{id}/condicoes/{cid}/encerrar', $requireAuth([$cpCtrl, 'closeCondition']));
$router->post('/pacientes/{id}/protocolos', $requireAuth([$cpCtrl, 'saveAnswers']));
// NI2-663: Clyo Academy (atrás de feature_flag_academy; CSRF dos POST conferido pelo Router).
// v54.22: a clínica só consome (pesquisa, lê, favorita). As antigas rotas de escrita da clínica respondem 403 e ficam na auditoria;
// publicar, editar, revisar e arquivar é só no console da equipe (/admin/academy).
$academyCtrl = new \ClyoNutri\Application\Controllers\AcademyController($db);
$router->get('/academy', $requireAuth([$academyCtrl, 'index']));
$router->get('/academy/artigo/{id}', $requireAuth([$academyCtrl, 'articleDetail']));
$router->post('/academy/artigo/{id}/favoritar', $requireAuth([$academyCtrl, 'toggleFavorite']));
$router->get('/academy/evidencias', $requireAuth([$academyCtrl, 'evidenceDesk']));
$router->get('/academy/colecao', $requireAuth([$academyCtrl, 'myCollection']));
$router->get('/academy/publicacoes', $requireAuth([$academyCtrl, 'publications']));
$router->get('/academy/publicacoes/nova', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->post('/academy/publicacoes', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->get('/academy/publicacoes/{id}/editar', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->get('/academy/publicacoes/{id}', $requireAuth([$academyCtrl, 'publicationDetail']));
$router->post('/academy/publicacoes/{id}', $requireAuth([$academyCtrl, 'authoringDenied']));
// v54.23: lâminas para pacientes. A clínica lê, abre, baixa e favorita; cadastro e troca de arquivo só no console (403 aqui).
$router->get('/academy/laminas', $requireAuth([$academyCtrl, 'handoutList']));
$router->get('/academy/laminas/nova', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->post('/academy/laminas', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->get('/academy/laminas/{id}/editar', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->post('/academy/laminas/{id}/favoritar', $requireAuth([$academyCtrl, 'toggleHandoutFavorite']));
$router->post('/academy/laminas/{id}', $requireAuth([$academyCtrl, 'authoringDenied']));
$router->get('/academy/laminas/{id}/arquivo', $requireAuth([$academyCtrl, 'handoutFile']));
$router->get('/academy/laminas/{id}/previa', $requireAuth(static fn(array $p = []) => $academyCtrl->handoutFile(['id' => $p['id'] ?? 0, 'kind' => 'preview'])));
$router->get('/academy/laminas/{id}', $requireAuth([$academyCtrl, 'handoutDetail']));
$router->get('/api/v1/academy/search', $requireAuth([$academyCtrl, 'searchApi']));
$router->get('/api/v1/academy/protocols/{code}', $requireAuth([$academyCtrl, 'protocolEvidenceApi']));
// v54.21: materiais educativos (biblioteca da clínica e envio para pacientes). Rotas estáticas antes de {id}.
$matCtrl = new \ClyoNutri\Application\Controllers\MaterialController($db);
$router->get('/materiais', $requireAuth([$matCtrl, 'index']));
$router->post('/materiais', $requireAuth([$matCtrl, 'upload']));
$router->post('/materiais/envios/{sid}/revogar', $requireAuth([$matCtrl, 'revoke']));
$router->get('/materiais/{id}/arquivo', $requireAuth([$matCtrl, 'file']));
$router->post('/materiais/{id}/enviar', $requireAuth([$matCtrl, 'share']));
$router->post('/materiais/{id}/{acao}', $requireAuth([$matCtrl, 'archive']));
$router->get('/materiais/{id}', $requireAuth([$matCtrl, 'show']));
$router->post('/materiais/{id}', $requireAuth([$matCtrl, 'update']));
$router->post('/pacientes/{id}/protocolos/{code}/dispensar', $requireAuth([$cpCtrl, 'dismissModule'])); // NI2-642
$router->post('/pacientes/{id}/protocolos/{code}/reativar', $requireAuth([$cpCtrl, 'restoreModule']));
$router->get('/pacientes/{id}/resumo-prescricao', $requireAuth([$dashCtrl, 'prescriptionSummary']));
$router->get('/pacientes/{id}/antropometria', $requireAuth([$dashCtrl, 'patientAnthropometry']));
$router->post('/pacientes/{id}/antropometria', $requireAuth([$dashCtrl, 'saveAnthropometry']));
$router->post('/pacientes/{id}/antropometria/{evalId}/compartilhar', $requireAuth([$dashCtrl, 'shareAnthropometry']));
$router->get('/pacientes/{id}/antropometria/comparar', $requireAuth([$dashCtrl, 'compareAnthropometry']));
$router->get('/pacientes/{id}/planos/comparar', $requireAuth([$dashCtrl, 'compareMealPlanVersions'])); // NI2-526

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
$router->post('/pacientes/{id}/pre-consulta/{qid}/condicoes', $requireAuth([$preconsCtrl, 'suggestConditions']));
// NI2-554: clinical prescriptions; author and tenant are revalidated by the service.
$prescriptionCtrl = new \ClyoNutri\Application\Controllers\PrescriptionController($db);
$router->get('/pacientes/{id}/prescricoes', $requireAuth([$prescriptionCtrl, 'index']));
$router->post('/pacientes/{id}/prescricoes', $requireAuth([$prescriptionCtrl, 'mutate']));
$router->get('/pacientes/{id}/prescricoes/{rxId}', $requireAuth([$prescriptionCtrl, 'show']));
$router->post('/pacientes/{id}/prescricoes/{rxId}', $requireAuth([$prescriptionCtrl, 'mutate']));
$router->get('/pacientes/{id}/prescricoes/{rxId}/arquivo/{file}', $requireAuth([$prescriptionCtrl, 'download']));

// Solicitação de Exames Laboratoriais para Acompanhamento Nutricional (NI2-529 a NI2-533)
$examRequestCtrl = new \ClyoNutri\Application\Controllers\ExamRequestController($db);
$router->get('/pacientes/{id}/exames', $requireAuth([$examRequestCtrl, 'index']));
$router->get('/pacientes/{id}/exames/novo', $requireAuth([$examRequestCtrl, 'create']));
$router->post('/pacientes/{id}/exames', $requireAuth([$examRequestCtrl, 'store']));
// v45: rotas estáticas antes de /{reqId}; fluxo rascunho → revisão → emissão (PDF no servidor).
$router->get('/pacientes/{id}/exames/{reqId}/editar', $requireAuth([$examRequestCtrl, 'edit']));
$router->post('/pacientes/{id}/exames/{reqId}/editar', $requireAuth([$examRequestCtrl, 'update']));
$router->post('/pacientes/{id}/exames/{reqId}/revisar', $requireAuth([$examRequestCtrl, 'review']));
$router->post('/pacientes/{id}/exames/{reqId}/emitir', $requireAuth([$examRequestCtrl, 'issue']));
$router->get('/pacientes/{id}/exames/{reqId}/imprimir', $requireAuth([$examRequestCtrl, 'printView']));
$router->get('/pacientes/{id}/exames/{reqId}/pdf', $requireAuth([$examRequestCtrl, 'downloadPdf']));
$router->post('/pacientes/{id}/exames/{reqId}/cancelar', $requireAuth([$examRequestCtrl, 'cancel']));
$router->post('/pacientes/{id}/exames/{reqId}/nova-versao', $requireAuth([$examRequestCtrl, 'newVersion'])); // v47 NI2-537
$router->get('/pacientes/{id}/exames/{reqId}', $requireAuth([$examRequestCtrl, 'show']));
$router->get('/api/exames/buscar', $requireAuth([$examRequestCtrl, 'searchApi']));
// v47 (NI2-530, NI2-532): catálogo, favoritos, exames da clínica com validação e versões da política
$examCatalogCtrl = new \ClyoNutri\Application\Controllers\ExamCatalogController($db);
$router->get('/exames', $requireAuth([$examCatalogCtrl, 'index']));
$router->get('/exames/catalogo', $requireAuth([$examCatalogCtrl, 'index']));
$router->post('/exames/catalogo/favoritos/{examId}', $requireAuth([$examCatalogCtrl, 'toggleFavorite']));
$router->post('/exames/catalogo/personalizados', $requireAuth([$examCatalogCtrl, 'submitCustom']));
$router->post('/exames/catalogo/personalizados/{examId}/validar', $requireAuth([$examCatalogCtrl, 'validateCustom']));
$router->get('/exames/politica', $requireAuth([$examCatalogCtrl, 'policy']));
// v48 (NI2-539): laudos da solicitação, lado da nutricionista. O arquivo só sai por link assinado de 5 min + sessão.
$examResultCtrl = new \ClyoNutri\Application\Controllers\ExamResultController($db);
$router->post('/pacientes/{id}/exames/{reqId}/laudos', $requireAuth([$examResultCtrl, 'upload']));
$router->post('/pacientes/{id}/exames/{reqId}/laudos/{docId}/revisar', $requireAuth([$examResultCtrl, 'review']));
$router->post('/pacientes/{id}/exames/{reqId}/laudos/{docId}/nova-versao', $requireAuth([$examResultCtrl, 'newVersion']));
$router->get('/pacientes/{id}/exames/{reqId}/laudos/{docId}/abrir', $requireAuth([$examResultCtrl, 'open']));
$router->get('/exames/laudo/{token}', $requireAuth([$examResultCtrl, 'download']));
// Paciente (público, acesso pelo token do link + data de nascimento)
$router->get('/pre-consulta', [$preconsCtrl, 'patientPage']);
$router->post('/pre-consulta/verificar', [$preconsCtrl, 'patientVerify']);
$router->post('/pre-consulta/salvar', [$preconsCtrl, 'patientSave']);
$router->get('/pre-consulta/{token}', [$preconsCtrl, 'patientLink']);
$router->get('/catalogo/alimentos/busca', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'search']));
$router->get('/catalogo/alimentos/{id}/conversao', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'conversion']));
// NI2-520 (v43): favorito da clínica e métrica de seleção (escolha e troca de escolha) no seletor de alimentos.
$router->post('/catalogo/alimentos/selecao', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'selection']));
$router->post('/catalogo/alimentos/{id}/favorito', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'favorite']));
// NI2-519 / NI2-520 (v43): regras de equivalência da clínica e trocas sugeridas no editor de plano.
$foodEqCtrl = new \ClyoNutri\Application\Controllers\FoodEquivalenceController();
$router->get('/alimentos/equivalencias', $requireAuth([$foodEqCtrl, 'index']));
$router->post('/alimentos/equivalencias', $requireAuth([$foodEqCtrl, 'create']));
$router->post('/alimentos/equivalencias/{id}', $requireAuth([$foodEqCtrl, 'update']));
$router->post('/alimentos/equivalencias/{id}/revisar', $requireAuth([$foodEqCtrl, 'review']));
$router->post('/alimentos/equivalencias/{id}/arquivar', $requireAuth([$foodEqCtrl, 'archive']));
$router->get('/planos/{planId}/itens/{itemId}/equivalentes', $requireAuth([$foodEqCtrl, 'suggestions']));
$router->get('/alimentos', $requireAuth([$dashCtrl, 'foods']));
// v54.41: Base de Alimentos > Adicionar alimento (alimento próprio da clínica).
$router->get('/alimentos/novo', $requireAuth([new \ClyoNutri\Application\Controllers\FoodCatalogController(), 'createForm']));
$router->post('/alimentos/novo', $requireAuth([new \ClyoNutri\Application\Controllers\FoodCatalogController(), 'create']));
$clinicMeasuresCtrl = new \ClyoNutri\Application\Controllers\ClinicMeasuresController();
$router->get('/medidas-caseiras', $requireAuth([$clinicMeasuresCtrl, 'index']));
$router->get('/medidas-caseiras/modelo.csv', $requireAuth([$clinicMeasuresCtrl, 'template']));
$router->post('/medidas-caseiras', $requireAuth([$clinicMeasuresCtrl, 'save']));
$router->post('/medidas-caseiras/importar', $requireAuth([$clinicMeasuresCtrl, 'import']));
$router->post('/medidas-caseiras/{id}/remover', $requireAuth([$clinicMeasuresCtrl, 'remove']));
// NI2-520 (v42): buscas sem resultado no catálogo (por clínica, últimos 30 dias).
$router->get('/alimentos/buscas', $requireAuth([new \ClyoNutri\Application\Controllers\FoodSearchController(), 'zeroResultReport']));
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
$router->get('/agenda/{id}/previa-confirmacao', $requireAuth([$dashCtrl, 'previewAppointmentConfirmation'])); // NI2-527
$router->post('/agenda/{id}/confirmacao-autorizada', $requireAuth([$dashCtrl, 'sendAuthorizedAppointmentConfirmation'])); // NI2-527

$router->get('/consulta/{id}', $requireAuth([$dashCtrl, 'consultationRoom']));
$router->post('/consulta/{id}/concluir', $requireAuth([$dashCtrl, 'completeConsultation']));
$router->get('/consulta/{id}/rascunho', $requireAuth([$dashCtrl, 'consultationDraftGet']));   // UX-03 (v14)
$router->post('/consulta/{id}/rascunho', $requireAuth([$dashCtrl, 'consultationDraftSave'])); // UX-03 (v14)

// NI2-820: Transcrição Nutricional em Tempo Real na Consulta
$transcriptionCtrl = new \ClyoNutri\Application\Controllers\RealtimeTranscriptionController($db);
$router->post('/api/consulta/{id}/transcription/consent', $requireAuth([$transcriptionCtrl, 'grantConsent']));
$router->post('/api/consulta/{id}/transcription/consent/revoke', $requireAuth([$transcriptionCtrl, 'revokeConsent']));
$router->get('/api/consulta/{id}/transcription/session', $requireAuth([$transcriptionCtrl, 'getSessionStatus']));
$router->post('/api/consulta/{id}/transcription/start', $requireAuth([$transcriptionCtrl, 'startSession']));
$router->post('/api/consulta/{id}/transcription/mode', $requireAuth([$transcriptionCtrl, 'switchMode']));
$router->post('/api/consulta/{id}/transcription/pause', $requireAuth([$transcriptionCtrl, 'pause']));
$router->post('/api/consulta/{id}/transcription/resume', $requireAuth([$transcriptionCtrl, 'resume']));
$router->post('/api/consulta/{id}/transcription/segment', $requireAuth([$transcriptionCtrl, 'ingestSegment']));
$router->post('/api/consulta/{id}/transcription/finish', $requireAuth([$transcriptionCtrl, 'finish']));
$router->get('/api/consulta/{id}/transcription/draft', $requireAuth([$transcriptionCtrl, 'getDraft']));
$router->post('/api/consulta/{id}/transcription/draft/approve', $requireAuth([$transcriptionCtrl, 'approveDraft']));

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
$router->get('/planos', function ($params = []) use ($dashCtrl, $landingCtrl, $requireAuth) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['clinic_id'])) {
        $landingCtrl->plans();
        return;
    }
    $requireAuth([$dashCtrl, 'mealPlans'])($params);
});
$router->post('/planos/novo', $requireAuth([$dashCtrl, 'createMealPlan']));
$router->get('/planos/{id}/imprimir', $requireAuth([$dashCtrl, 'printMealPlan']));
$router->get('/planos/{id}/lista-compras', $requireAuth([$dashCtrl, 'shoppingList']));
$router->get('/planos/{id}', $requireAuth([$dashCtrl, 'mealPlanEditor']));
$router->post('/planos/{id}/refeicoes', $requireAuth([$dashCtrl, 'addMealToPlan']));
$router->post('/planos/{id}/refeicoes/{mealId}/editar', $requireAuth([$dashCtrl, 'updateMealInPlan']));
$router->post('/planos/{id}/refeicoes/{mealId}/remover', $requireAuth([$dashCtrl, 'deleteMealFromPlan']));
$router->post('/planos/{id}/itens', $requireAuth([$dashCtrl, 'addItemToPlanMeal']));
$router->post('/planos/{id}/itens/{itemId}/editar', $requireAuth([$dashCtrl, 'updatePlanItem']));
$router->post('/planos/{id}/itens/{itemId}/remover', $requireAuth([$dashCtrl, 'deleteItemFromPlanMeal']));
$dietaryCtrl = new \ClyoNutri\Application\Controllers\DietarySuggestionController($db);
$router->get('/planos/{id}/sugestoes-dieteticas', $requireAuth([$dietaryCtrl,'page']));
$router->post('/planos/{id}/sugestoes-dieteticas', $requireAuth([$dietaryCtrl,'page']));
$router->post('/planos/{id}/substituicoes', $requireAuth([$dashCtrl, 'addSubstitutionToPlanItem']));
$router->post('/planos/{id}/substituicoes/{subId}/remover', $requireAuth([$dashCtrl, 'deleteSubstitutionFromPlan']));
$planEnergyCtrl = new \ClyoNutri\Application\Controllers\MealPlanEnergyController();
$router->post('/planos/{id}/energia', $requireAuth([$planEnergyCtrl, 'saveEnergy']));
$router->post('/planos/{id}/paciente', $requireAuth([$planEnergyCtrl, 'assignPatient']));
$router->post('/planos/{id}/publicar', $requireAuth([$dashCtrl, 'publishMealPlan']));
$router->post('/planos/{id}/duplicar', $requireAuth([$dashCtrl, 'duplicateMealPlan']));
// NI2-519 (v42): modelos de plano da clínica.
$planTemplateCtrl = new \ClyoNutri\Application\Controllers\MealPlanTemplateController();
$router->post('/planos/{id}/salvar-como-modelo', $requireAuth([$planTemplateCtrl, 'saveFromPlan']));
$router->get('/modelos', $requireAuth([$planTemplateCtrl, 'index']));
$router->post('/modelos/{id}/usar', $requireAuth([$planTemplateCtrl, 'usePlan']));
$router->post('/modelos/{id}/arquivar', $requireAuth([$planTemplateCtrl, 'archive']));
$router->post('/modelos/{id}/padrao', $requireAuth([$planTemplateCtrl, 'setDefault']));
$router->post('/modelos/{id}/aprovacao', $requireAuth([$planTemplateCtrl, 'setApproval']));
$router->post('/modelos/{id}/visibilidade', $requireAuth([$planTemplateCtrl, 'setVisibility']));
$router->get('/financeiro', $requireAuth([$dashCtrl, 'finance']));
$router->post('/financeiro/recebiveis/novo', $requireAuth([$dashCtrl, 'createReceivableAction']));
$router->post('/financeiro/recebiveis/{id}/pagar', $requireAuth([$dashCtrl, 'payReceivableAction']));
$router->post('/financeiro/recebiveis/{id}/cancelar', $requireAuth([$dashCtrl, 'cancelReceivableAction']));
// v54.36: relatório exportável e recibo imprimível
$financeDocCtrl = new \ClyoNutri\Application\Controllers\FinanceDocumentController();
$router->get('/financeiro/exportar/csv', $requireAuth([$financeDocCtrl, 'exportCsv']));
$router->get('/financeiro/exportar/pdf', $requireAuth([$financeDocCtrl, 'exportPdf']));
$router->get('/financeiro/recibos/{code}', $requireAuth([$financeDocCtrl, 'receipt']));
$router->get('/financeiro/recibos/{code}/pdf', $requireAuth([$financeDocCtrl, 'receiptPdf']));
$router->get('/recibo/{code}', [$financeDocCtrl, 'publicReceipt']);
// v54.37: saídas (despesas da clínica)
$router->post('/financeiro/saidas/{id}/pagar', $requireAuth([$financeDocCtrl, 'payExpense']));
$router->post('/financeiro/saidas/{id}/cancelar', $requireAuth([$financeDocCtrl, 'cancelExpense']));
// NI2-95: avisos do profissional na web (mensagem nova no chat etc.)
$noticesCtrl = new \ClyoNutri\Application\Controllers\NotificationsController();
$router->get('/avisos', $requireAuth([$noticesCtrl, 'index']));
$router->post('/avisos/lidas', $requireAuth([$noticesCtrl, 'markAll']));
$router->post('/avisos/{id}/lida', $requireAuth([$noticesCtrl, 'markOne']));
$carePolicyCtrl=new \ClyoNutri\Application\Controllers\CareWorkflowPolicyController();
$router->get('/configuracoes/atendimento',$requireAuth([$carePolicyCtrl,'page']));
$router->post('/configuracoes/atendimento',$requireAuth([$carePolicyCtrl,'page']));
$careProgressCtrl = new \ClyoNutri\Application\Controllers\CareProgressController();
$router->post('/consulta/{id}/progresso', $requireAuth([$careProgressCtrl, 'save']));
$router->get('/configuracoes', $requireAuth([$dashCtrl, 'settings']));
$router->post('/configuracoes', $requireAuth([$dashCtrl, 'settings']));
$router->get('/configuracoes/assinatura', $requireAuth([$subCtrl, 'show']));
// NI2-606: equipe da clínica (CSRF conferido no controller; permissão no TeamService)
$teamCtrl = new \ClyoNutri\Application\Controllers\TeamController();
$router->get('/configuracoes/equipe', $requireAuth([$teamCtrl, 'show']));
$router->post('/configuracoes/equipe', $requireAuth([$teamCtrl, 'invite']));
$router->post('/configuracoes/equipe/{userId}/papel', $requireAuth([$teamCtrl, 'changeRole']));
$router->post('/configuracoes/equipe/{userId}/desativar', $requireAuth([$teamCtrl, 'deactivate']));
$router->post('/configuracoes/equipe/{userId}/reativar', $requireAuth([$teamCtrl, 'reactivate']));
$router->post('/configuracoes/equipe/{userId}/reenviar', $requireAuth([$teamCtrl, 'resend']));
$router->post('/configuracoes/assinatura/cancelar', $requireAuth([$subCtrl, 'cancel']));
$router->post('/configuracoes/assinatura/plano', $requireAuth([$subCtrl, 'changePlan']));
$router->post('/configuracoes/conta/exclusao', $requireAuth([$subCtrl, 'requestAccountDeletion']));
$router->post('/configuracoes/conta/exclusao/cancelar', $requireAuth([$subCtrl, 'cancelAccountDeletion']));
$router->get('/api/v1/subscription', $requireAuth([$subCtrl, 'apiGet']));
$router->post('/api/v1/subscription/cancel', $requireAuth([$subCtrl, 'apiCancel']));
$router->post('/configuracoes/assinatura/asaas', $requireAuth([$subCtrl, 'startAsaasCheckout']));
// Webhook público do Asaas: autenticação por asaas-access-token (sem sessão/CSRF), NI2-727.
$router->post('/webhooks/asaas', [$asaasWebhookCtrl, 'receive']);

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
// v33: gestão de usuários. /novo precisa vir antes de /{id} (o roteador casa na ordem de registro).
$router->get('/admin/usuarios/novo', $requireAuth([$adminCtrl, 'staffForm']));
$router->post('/admin/usuarios', $requireAuth([$adminCtrl, 'staffCreate']));
$router->get('/admin/usuarios/{id}', $requireAuth([$adminCtrl, 'userDetail']));
$router->get('/admin/usuarios/{id}/acao/{acao}', $requireAuth([$adminCtrl, 'userActionForm']));
$router->post('/admin/usuarios/{id}/acao/{acao}', $requireAuth([$adminCtrl, 'userActionExecute']));
$router->post('/admin/usuarios/{id}/status', $requireAuth([$adminCtrl, 'updateUserStatus']));
$router->post('/admin/usuarios/{id}/revogar-sessoes', $requireAuth([$adminCtrl, 'revokeUserSessions']));
$router->get('/admin/servicedesk', $requireAuth([$adminCtrl, 'serviceDesk']));
$router->get('/admin/comercial', $requireAuth([$adminCtrl, 'commercial']));
$router->post('/admin/pedidos-exclusao/{id}/analise', $requireAuth([$adminCtrl, 'reviewDeletionRequest']));
$router->post('/admin/pedidos-exclusao/{id}/encerrar', $requireAuth([$adminCtrl, 'resolveDeletionRequest']));
$router->get('/admin/acessos', $requireAuth([$adminCtrl, 'accessControl']));
$router->post('/admin/acessos/revogar-sessao', $requireAuth([$adminCtrl, 'revokeSession']));
$router->get('/admin/auditoria', $requireAuth([$adminCtrl, 'auditLogs']));
// NI2-767: Central de Eventos de Produção (observabilidade operacional e incidentes)
$router->get('/admin/eventos', $requireAuth([$adminCtrl, 'events']));
$router->get('/admin/eventos/exportar', $requireAuth([$adminCtrl, 'eventsExport']));
$router->get('/admin/eventos/{id}', $requireAuth([$adminCtrl, 'eventDetail']));
$router->post('/admin/eventos/{id}/criar-incidente', $requireAuth([$adminCtrl, 'eventCreateIncident']));
$router->get('/admin/configuracoes', $requireAuth([$adminCtrl, 'settings']));
$router->post('/admin/configuracoes', $requireAuth([$adminCtrl, 'saveSettings']));
// NI2-635: catálogo de protocolos clínicos (governança editorial)
$router->get('/admin/protocolos', $requireAuth([$adminCtrl, 'protocols']));
$router->post('/admin/protocolos', $requireAuth([$adminCtrl, 'protocolAction']));
$router->get('/admin/protocolos/{id}', $requireAuth([$adminCtrl, 'protocolDetail']));
$router->post('/admin/protocolos/{id}/{acao}', $requireAuth([$adminCtrl, 'protocolAction']));
$router->get('/admin/academy', $requireAuth([$adminCtrl, 'academy'])); // NI2-663: console editorial
$router->get('/admin/academy/artigos/{id}', $requireAuth([$adminCtrl, 'academyArticle']));
$router->get('/admin/academy/publicacoes/nova', $requireAuth([$adminCtrl, 'academyPublication'])); // v54.22: publicações escritas pela equipe
$router->get('/admin/academy/publicacoes/{id}', $requireAuth([$adminCtrl, 'academyPublication']));
$router->get('/admin/academy/laminas/nova', $requireAuth([$adminCtrl, 'academyHandout'])); // v54.23: lâminas para pacientes
$router->get('/admin/academy/laminas/{id}', $requireAuth([$adminCtrl, 'academyHandout']));
$router->get('/admin/academy/laminas/{id}/arquivo', $requireAuth([$adminCtrl, 'academyHandoutFile']));
$router->get('/admin/academy/laminas/{id}/previa', $requireAuth(static fn(array $p = []) => $adminCtrl->academyHandoutFile(['id' => $p['id'] ?? 0, 'kind' => 'preview'])));
$router->post('/admin/academy/{acao}', $requireAuth([$adminCtrl, 'academyAction']));
$router->post('/admin/academy/{tipo}/{id}/{acao}', $requireAuth([$adminCtrl, 'academyAction']));
$router->get('/admin/busca', $requireAuth([$adminCtrl, 'search']));
// v34: central de notificações da equipe. Rotas fixas antes de /{id}.
$router->get('/admin/notificacoes', $requireAuth([$adminCtrl, 'notifications']));
$router->get('/admin/notificacoes/preferencias', $requireAuth([$adminCtrl, 'notificationPrefs']));
$router->post('/admin/notificacoes/preferencias', $requireAuth([$adminCtrl, 'notificationPrefsSave']));
$router->post('/admin/notificacoes/lidas', $requireAuth([$adminCtrl, 'notificationMarkAll']));
$router->post('/admin/notificacoes/{id}/lida', $requireAuth([$adminCtrl, 'notificationMarkOne']));

// 4. API Móvel do Paciente (/api/v1/patient/...)
$authenticatePatientApi = function() use ($db): array {
    $plainToken = \ClyoNutri\Core\Auth\MobileWebSession::token();
    // v54.57 (UX-102): página do portal (/paciente/...) aberta no navegador sem sessão válida volta ao login do portal
    // com explicação, em vez de JSON 401. A API (/api/v1/...) e chamadas fetch continuam recebendo JSON.
    $portalPage = static function () use ($db, $plainToken): bool {
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (!str_starts_with($p, '/paciente/') || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || !\ClyoNutri\Core\Http\ErrorPage::wantsHtml()) return false;
        if ($plainToken) {
            $st = $db->prepare("SELECT 1 FROM personal_access_tokens WHERE tokenable_type = 'patient' AND token = ? AND expires_at IS NOT NULL AND expires_at > CURRENT_TIMESTAMP");
            $st->execute([hash('sha256', $plainToken)]);
            if ($st->fetchColumn()) return false;
        }
        header('Location: /paciente/app?sessao=1', true, 303);
        return true;
    };
    if ($portalPage()) exit;
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

// NI2-554: no public document tokens. Cookie/bearer authentication on every request.
$rxPatientError = static function(\Throwable $e): void {
    http_response_code($e instanceof \DomainException ? 404 : 503);
    if (!$e instanceof \DomainException) \ClyoNutri\Core\Observability\StructuredLogger::error('PRESCRIPTION_PATIENT_FAILED', ['exception' => $e]);
    echo json_encode(['error'=>'Prescrição indisponível neste contexto.'], JSON_UNESCAPED_UNICODE);
};
$router->get('/api/v1/patient/prescriptions', function() use($authenticatePatientApi,$db,$rxPatientError) {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
    $auth=$authenticatePatientApi();
    try {echo json_encode(['prescriptions'=>(new \ClyoNutri\Domain\Prescription\PrescriptionService($db))->patientList((int)$auth['clinic_id'],(int)$auth['patient_id'])],JSON_UNESCAPED_UNICODE);}catch(\Throwable $e){$rxPatientError($e);}
});
$router->get('/api/v1/patient/prescriptions/{rxId}/{file}', function($params=[]) use($authenticatePatientApi,$db,$rxPatientError) {
    $auth=$authenticatePatientApi();
    try {\ClyoNutri\Application\Controllers\PrescriptionController::send((new \ClyoNutri\Domain\Prescription\PrescriptionService($db))->patientDownload((int)$auth['clinic_id'],(int)$auth['patient_id'],(int)$params['rxId'],($params['file']??'')==='assinatura'));}catch(\Throwable $e){$rxPatientError($e);}
});
$router->get('/paciente/prescricoes', function() use($authenticatePatientApi,$db,$rxPatientError) {
    header('Cache-Control: no-store, private');$auth=$authenticatePatientApi();
    try {$prescriptions=(new \ClyoNutri\Domain\Prescription\PrescriptionService($db))->patientList((int)$auth['clinic_id'],(int)$auth['patient_id']);require __DIR__.'/../views/prescriptions/patient.php';}catch(\Throwable $e){$rxPatientError($e);}
});

// v54.21: materiais enviados ao paciente (cookie/bearer; só os dele, ativos e não revogados)
$matPatientError = static function(\Throwable $e): void {
    http_response_code($e instanceof \DomainException ? 404 : 503);
    if (!$e instanceof \DomainException) \ClyoNutri\Core\Observability\StructuredLogger::error('MATERIAL_PATIENT_FAILED', ['exception' => $e]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'Material indisponível.'], JSON_UNESCAPED_UNICODE);
};
$router->get('/api/v1/patient/materials', function() use($authenticatePatientApi,$db,$matPatientError) {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
    $auth=$authenticatePatientApi();
    try {echo json_encode(['materials'=>(new \ClyoNutri\Domain\Materials\MaterialService($db))->patientList((int)$auth['clinic_id'],(int)$auth['patient_id'])],JSON_UNESCAPED_UNICODE);}catch(\Throwable $e){$matPatientError($e);}
});
$router->get('/api/v1/patient/materials/{id}', function($params=[]) use($authenticatePatientApi,$db,$matPatientError) {
    $auth=$authenticatePatientApi();
    try {\ClyoNutri\Domain\Materials\MaterialService::send((new \ClyoNutri\Domain\Materials\MaterialService($db))->patientFile((int)$auth['clinic_id'],(int)$auth['patient_id'],(int)($params['id']??0)),($_GET['baixar']??'')==='1');}catch(\Throwable $e){$matPatientError($e);}
});
$router->get('/paciente/materiais', function() use($authenticatePatientApi,$db,$matPatientError) {
    header('Cache-Control: no-store, private');$auth=$authenticatePatientApi();
    try {$materials=(new \ClyoNutri\Domain\Materials\MaterialService($db))->patientList((int)$auth['clinic_id'],(int)$auth['patient_id']);require __DIR__.'/../views/materials/patient.php';}catch(\Throwable $e){$matPatientError($e);}
});

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
            } else {
                // NI2-449: o aceite só é gravado quando o app envia terms_accepted (o app atual ainda não envia;
                // nesse caso o paciente fica com o aceite pendente e confirma no próximo login pela web).
                $acceptance = filter_var($input['terms_accepted'] ?? false, FILTER_VALIDATE_BOOL)
                    ? ['channel' => 'app', 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '')]
                    : null;
                $result=$service->complete($input, $acceptance);
            }
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

    // Hidratação de hoje: dia local (APP_TIMEZONE), sem registros excluídos (NI2-92).
    $waterToday = $apiCtrl->getHydrationTodayTotal($patientId);

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

// Hidratação da paciente (NI2-92): registrar, histórico, corrigir e excluir.
// Regras em src/Domain/Engagement/HydrationService.php e docs/api/openapi_v1.json.
$hydrationRespondError = static function (\Throwable $e): void {
    if ($e instanceof \ClyoNutri\Domain\Engagement\HydrationException) {
        http_response_code($e->httpStatus);
        echo json_encode(['error' => $e->getMessage(), 'code' => $e->errorCode], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($e instanceof \ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['error' => 'Muitos registros em pouco tempo. Aguarde um minuto e tente de novo.', 'code' => 'RATE_LIMITED'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($e instanceof \ClyoNutri\Core\Security\TenantSecurityException) {
        http_response_code(404);
        echo json_encode(['error' => 'Registro de água não encontrado.', 'code' => 'HYDRATION_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        return;
    }
    error_log('[ClyoNutri][hydration] ' . get_class($e) . ' #' . $e->getCode() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível salvar o registro de água. Tente novamente.', 'code' => 'INTERNAL_ERROR'], JSON_UNESCAPED_UNICODE);
};
// Limite de escrita por paciente (M1). Ajustável por HYDRATION_RATE_LIMIT_PER_MINUTE.
$hydrationRateLimit = static function (array $auth) use ($db): void {
    $max = getenv('HYDRATION_RATE_LIMIT_PER_MINUTE');
    $max = is_string($max) && ctype_digit($max) && (int)$max > 0 ? (int)$max : 30;
    (new \ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter($db))
        ->hit('hydration_write', 'patient:' . (int)$auth['clinic_id'] . ':' . (int)$auth['patient_id'], $max, 60);
};

$router->post('/api/v1/patient/hydration', function() use ($authenticatePatientApi, $db, $getJsonInput, $hydrationRespondError, $hydrationRateLimit) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $input = $getJsonInput();
    $input = is_array($input) ? $input : [];
    try {
        $hydrationRateLimit($auth);
        $headerKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        $res = (new \ClyoNutri\Application\Api\PatientApiController($db))->logHydration((int)$auth['patient_id'], $input['amount_ml'] ?? null, [
            'idempotency_key' => $headerKey !== '' ? $headerKey : ($input['idempotency_key'] ?? null),
            'logged_at' => $input['logged_at'] ?? null,
            'source' => 'app',
        ]);
        // NI2-96: conta o evento de adesão (sem valores nem id de paciente), só quando o registro é novo.
        if (empty($res['idempotent_replay'])) {
            (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($db))->record(\ClyoNutri\Domain\Analytics\AdherenceAnalytics::HYDRATION_LOGGED, (int)$auth['clinic_id'], (int)$auth['patient_id']);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $hydrationRespondError($e);
    }
});

$router->get('/api/v1/patient/hydration/history', function() use ($authenticatePatientApi, $db, $hydrationRespondError) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    try {
        echo json_encode((new \ClyoNutri\Application\Api\PatientApiController($db))
            ->getHydrationHistory((int)$auth['patient_id'], $_GET['before'] ?? null, $_GET['days'] ?? null), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $hydrationRespondError($e);
    }
});

$router->post('/api/v1/patient/hydration/{id}/update', function($params = []) use ($authenticatePatientApi, $db, $getJsonInput, $hydrationRespondError, $hydrationRateLimit) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $input = $getJsonInput();
    $input = is_array($input) ? $input : [];
    try {
        $hydrationRateLimit($auth);
        $id = ctype_digit((string)($params['id'] ?? '')) ? (int)$params['id'] : 0;
        echo json_encode((new \ClyoNutri\Application\Api\PatientApiController($db))->updateHydration((int)$auth['patient_id'], $id, [
            'amount_ml' => $input['amount_ml'] ?? null,
            'logged_at' => $input['logged_at'] ?? null,
        ]), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $hydrationRespondError($e);
    }
});

$router->post('/api/v1/patient/hydration/{id}/delete', function($params = []) use ($authenticatePatientApi, $db, $hydrationRespondError, $hydrationRateLimit) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    try {
        $hydrationRateLimit($auth);
        $id = ctype_digit((string)($params['id'] ?? '')) ? (int)$params['id'] : 0;
        echo json_encode((new \ClyoNutri\Application\Api\PatientApiController($db))->deleteHydration((int)$auth['patient_id'], $id), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $hydrationRespondError($e);
    }
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

// Uses the existing Bearer/cookie authentication, expiry, Origin and web-header checks.
$router->get('/api/v1/patient/booking/context', function() use ($authenticatePatientApi, $bookingCtrl) {
    $authenticatePatientApi();
    $bookingCtrl->patientBookingContext();
});
$router->post('/api/v1/patient/booking/confirm', function() use ($authenticatePatientApi, $bookingCtrl) {
    $authenticatePatientApi();
    $bookingCtrl->confirmAuthenticatedBooking();
});

$router->get('/api/v1/patient/waitlist', function() use ($authenticatePatientApi, $bookingCtrl) {
    $authenticatePatientApi();
    $bookingCtrl->waitlistApi('list');
});
$router->post('/api/v1/patient/waitlist', function() use ($authenticatePatientApi, $bookingCtrl) {
    $authenticatePatientApi();
    $bookingCtrl->waitlistApi('join');
});
$router->post('/api/v1/patient/waitlist/{id}/cancel', function($params = []) use ($authenticatePatientApi, $bookingCtrl) {
    $authenticatePatientApi();
    $bookingCtrl->waitlistApi('cancel', (int)($params['id'] ?? 0));
});

// Próxima consulta: ações da paciente (NI2-137). Tudo passa pelo motor da agenda e pela política da clínica.
// NI2-433: patient-owned management; canonical appointments and the existing patient auth.
$selfServiceCtrl = new \ClyoNutri\Application\Controllers\PatientSelfServiceController($db);
$router->get('/paciente/consultas', function() use($authenticatePatientApi,$selfServiceCtrl){$authenticatePatientApi();$selfServiceCtrl->page();});
$router->get('/api/v1/patient/appointments', function() use($authenticatePatientApi,$selfServiceCtrl){$authenticatePatientApi();$selfServiceCtrl->list();});
$router->get('/api/v1/patient/appointments/{id}/self-service-slots', function($params=[]) use($authenticatePatientApi,$selfServiceCtrl){$authenticatePatientApi();$selfServiceCtrl->slots((int)($params['id']??0));});
$router->post('/api/v1/patient/appointments/{id}/reschedule-now', function($params=[]) use($authenticatePatientApi,$selfServiceCtrl,$getJsonInput){$authenticatePatientApi();$selfServiceCtrl->reschedule((int)($params['id']??0),$getJsonInput());});
$router->get('/api/v1/booking/catalog', [new \ClyoNutri\Application\Controllers\PatientSelfServiceController($db),'catalog']);
$router->get('/api/booking/metrics', $requireAuth([$selfServiceCtrl,'metrics']));
$router->get('/agenda/indicadores-autoagendamento', $requireAuth([$selfServiceCtrl,'metricsPage']));

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

// Diário alimentar da paciente (NI2-132). Regras em src/Domain/FoodDiary/FoodDiaryService.php e docs/api/openapi_v1.json.
$foodDiaryRespondError = static function (\Throwable $e, string $notFound = 'Registro não encontrado.'): void {
    if ($e instanceof \InvalidArgumentException) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($e instanceof \ClyoNutri\Domain\FoodDiary\IdempotencyKeyReusedException) {
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'IDEMPOTENCY_KEY_REUSED'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($e instanceof \ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['error' => 'Muitos registros em pouco tempo. Aguarde um minuto e tente de novo.', 'code' => 'RATE_LIMITED'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($e instanceof \ClyoNutri\Core\Security\TenantSecurityException) {
        http_response_code(404);
        echo json_encode(['error' => $notFound, 'code' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        return;
    }
    throw $e;
};
// Parâmetros de lista (limit, cursor, from, to): valor fora do formato vira 422, nunca é ignorado em silêncio.
$foodDiaryListParams = static function (): array {
    $out = [];
    if (isset($_GET['limit'])) {
        if (!is_string($_GET['limit']) || !ctype_digit($_GET['limit']) || (int)$_GET['limit'] < 1) {
            throw new \InvalidArgumentException('O limite deve ser um número inteiro maior que zero.');
        }
        $out['limit'] = (int)$_GET['limit'];
    }
    foreach (['cursor', 'from', 'to'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            if (!is_string($_GET[$k])) throw new \InvalidArgumentException("Parâmetro {$k} inválido.");
            $out[$k] = $_GET[$k];
        }
    }
    return $out;
};
// Limite de escrita por paciente (M5). Ajustável por FOOD_DIARY_RATE_LIMIT_PER_MINUTE (padrão 30, como na hidratação).
$foodDiaryRateLimit = static function (array $auth) use ($db): void {
    $max = getenv('FOOD_DIARY_RATE_LIMIT_PER_MINUTE');
    $max = is_string($max) && ctype_digit($max) && (int)$max > 0 ? (int)$max : 30;
    (new \ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter($db))
        ->hit('food_diary_write', 'patient:' . (int)$auth['clinic_id'] . ':' . (int)$auth['patient_id'], $max, 60);
};

// Obter o diário alimentar da paciente: ?limit= (padrão 30, máx. 100), ?cursor= (next_cursor), ?from= e ?to= (AAAA-MM-DD)
$router->get('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db, $foodDiaryRespondError, $foodDiaryListParams) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    try {
        $page = (new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db))->listPatientEntries((int)$auth['patient_id'], $foodDiaryListParams());
        echo json_encode($page, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $foodDiaryRespondError($e);
    }
});

// Registrar refeição no diário: 201 ao criar; 200 com idempotent_replay=true no reenvio idêntico; 409 se a chave
// (Idempotency-Key ou idempotency_key) for reutilizada com outro conteúdo; 429 acima do limite por minuto.
$router->post('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db, $getJsonInput, $foodDiaryRespondError, $foodDiaryRateLimit) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $input = $getJsonInput();
    $input = is_array($input) ? $input : [];
    // NI2-89: o app não envia arquivos por esta rota; caminho de foto vindo do cliente não é aceito.
    unset($input['photo_path']);
    $headerKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($headerKey !== '') {
        $input['idempotency_key'] = $headerKey;
    }

    try {
        $foodDiaryRateLimit($auth);
        $res = (new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db))->logMeal($patientId, $input);
        http_response_code($res['idempotent_replay'] ? 200 : 201);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $foodDiaryRespondError($e);
    }
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

// 4.1b Laudos de exames pelo app (v48, NI2-539). O envio da paciente entra "aguardando revisão" e não vira dado
// clínico até a nutricionista aceitar. Arquivo inspecionado (tipo real, conteúdo ativo, antivírus se houver).
$examResultApiError = static function (\Throwable $e): void {
    $known = $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
    http_response_code($known ? 422 : 500);
    if (!$known) { error_log('EXAM_RESULT_API_ERROR ' . get_class($e) . ': ' . $e->getMessage()); }
    echo json_encode(['status' => 'error', 'error' => $known ? $e->getMessage() : 'Não foi possível concluir agora. Tente de novo.'], JSON_UNESCAPED_UNICODE);
};
$router->get('/api/v1/patient/exam-requests', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $data = (new \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService($db))->patientOverview((int)$auth['patient_id'], (int)$auth['clinic_id']);
    echo json_encode(['status' => 'success', 'exam_requests' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});
$router->post('/api/v1/patient/exam-requests/{id}/results', function($params = []) use ($authenticatePatientApi, $db, $examResultApiError) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    try {
        $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => ''];
        $s = static fn(string $k) => is_scalar($_POST[$k] ?? null) ? (string)$_POST[$k] : '';
        $doc = (new \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService($db))->uploadByPatient((int)$auth['patient_id'], (int)($params['id'] ?? 0), $file,
            ['laboratory_name' => $s('laboratory_name'), 'collected_at' => $s('collected_at'), 'notes' => $s('notes'),
             'item_ids' => array_map('intval', array_filter((array)($_POST['item_ids'] ?? []), 'is_scalar'))]);
        http_response_code(201);
        echo json_encode(['status' => 'success', 'message' => 'Laudo enviado. Sua nutricionista vai revisar.', 'result' => [
            'id' => (int)$doc['id'], 'status' => $doc['status'], 'status_label' => $doc['status_label'], 'original_name' => $doc['original_name']]], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $examResultApiError($e);
    }
});
$router->post('/api/v1/patient/exam-results/{id}/link', function($params = []) use ($authenticatePatientApi, $db, $examResultApiError) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    try {
        $token = (new \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService($db))->patientLink((int)($params['id'] ?? 0), (int)$auth['patient_id']);
        echo json_encode(['status' => 'success', 'url' => '/api/v1/exames/laudo/' . $token, 'expires_in' => 120], JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        $examResultApiError($e);
    }
});
// Link assinado e curto (2 min) para abrir o laudo no aparelho, sem levar o token de sessão (como no anexo do chat).
$router->get('/api/v1/exames/laudo/{token}', function($params = []) use ($db) {
    try {
        $link = \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService::verifyLink((string)($params['token'] ?? ''));
        if ($link['kind'] !== 'q') { throw new \DomainException('Link de laudo inválido.'); }
        $file = (new \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService($db))->fileFor($link['document_id'], $link['clinic_id'], ['type' => 'patient', 'id' => $link['subject_id']]);
    } catch (\Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $e instanceof \DomainException ? $e->getMessage() : 'Laudo não encontrado.';
        return;
    }
    \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService::sendHeaders($file);
    echo $file['bytes'];
});

// 4.2 Avisos da paciente (NI2-95): caixa de avisos e preferências por tipo de aviso e canal.
$notificationApi = static fn() => new \ClyoNutri\Domain\Notification\NotificationService($db);
$router->get('/api/v1/patient/notifications', function() use ($authenticatePatientApi, $notificationApi) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $before = isset($_GET['before_id']) && is_string($_GET['before_id']) && ctype_digit($_GET['before_id']) ? (int)$_GET['before_id'] : null;
    $limit = isset($_GET['limit']) && is_string($_GET['limit']) && ctype_digit($_GET['limit']) ? (int)$_GET['limit'] : 20;
    echo json_encode($notificationApi()->inbox((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'], $before, $limit), JSON_UNESCAPED_UNICODE);
});
$router->post('/api/v1/patient/notifications/read-all', function() use ($authenticatePatientApi, $notificationApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $svc = $notificationApi();
    $marked = $svc->markAllRead((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id']);
    echo json_encode(['marked' => $marked, 'unread_count' => $svc->unreadCount((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'])], JSON_UNESCAPED_UNICODE);
});
$router->post('/api/v1/patient/notifications/{id}/read', function($params = []) use ($authenticatePatientApi, $notificationApi) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $id = (string)($params['id'] ?? '');
    $svc = $notificationApi();
    if (!ctype_digit($id) || !$svc->available() || !$svc->markRead((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'], (int)$id)) {
        http_response_code(404);
        echo json_encode(['error' => 'Aviso não encontrado.', 'code' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['id' => (int)$id, 'read' => true, 'unread_count' => $svc->unreadCount((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'])], JSON_UNESCAPED_UNICODE);
});
$router->get('/api/v1/patient/notification-preferences', function() use ($authenticatePatientApi, $notificationApi) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    echo json_encode($notificationApi()->preferences((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id']), JSON_UNESCAPED_UNICODE);
});
$router->post('/api/v1/patient/notification-preferences', function() use ($authenticatePatientApi, $getJsonInput, $notificationApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    try {
        $res = $notificationApi()->savePreferences((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'], $getJsonInput());
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    } catch (\RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
        return;
    }
    (new \ClyoNutri\Core\Audit\AuditLogger($db))->log('NOTIFICATION_PREFERENCES_UPDATED', 'patients', (string)$auth['patient_id'], ['before' => $res['before'], 'after' => $res['after']]);
    echo json_encode($res['after'], JSON_UNESCAPED_UNICODE);
});
// NI2-95: aparelho da paciente para o aviso no celular (token do Expo Push). O token nunca volta na resposta.
$router->post('/api/v1/patient/push-tokens', function() use ($authenticatePatientApi, $getJsonInput, $db) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $in = $getJsonInput();
    try {
        $res = (new \ClyoNutri\Domain\Notification\PushTokenService($db))->register((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'], $in['token'] ?? null, $in['platform'] ?? null);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], JSON_UNESCAPED_UNICODE);
        return;
    } catch (\RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => $e->getMessage(), 'code' => 'UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
        return;
    }
    (new \ClyoNutri\Core\Audit\AuditLogger($db))->log('PUSH_DEVICE_REGISTERED', 'patients', (string)$auth['patient_id'], ['platform' => is_string($in['platform'] ?? null) ? substr($in['platform'], 0, 16) : 'unknown']);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
});
$router->post('/api/v1/patient/push-tokens/remove', function() use ($authenticatePatientApi, $getJsonInput, $db) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticatePatientApi();
    $in = $getJsonInput();
    $removed = (new \ClyoNutri\Domain\Notification\PushTokenService($db))->remove((int)$auth['clinic_id'], 'patient', (int)$auth['patient_id'], $in['token'] ?? null);
    if ($removed) {
        (new \ClyoNutri\Core\Audit\AuditLogger($db))->log('PUSH_DEVICE_REMOVED', 'patients', (string)$auth['patient_id'], []);
    }
    // Idempotente: sair duas vezes, ou com token que não é seu, responde igual (sem revelar se existe).
    echo json_encode(['removed' => true], JSON_UNESCAPED_UNICODE);
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

// Lista de Compras do Plano Alimentar Publicado Vigente (NI2-116)
$router->get('/api/v1/patient/shopping-list', function() use ($authenticatePatientApi, $db) {
    header('Content-Type: application/json; charset=utf-8');
    $auth = $authenticatePatientApi();
    $patientId = (int)$auth['patient_id'];
    $days = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;

    try {
        $service = new \ClyoNutri\Domain\ShoppingList\ShoppingListService($db);
        $list = $service->generateForPatient($patientId, $days);
        echo json_encode(['data' => $list]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno ao processar lista de compras.']);
    }
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
        // NI2-481: convite gerado e commitado -> envia o e-mail de primeiro acesso ao paciente.
        // O token em texto puro não é persistido; a falha de e-mail não invalida o convite já criado.
        $delivery=(new \ClyoNutri\Domain\PatientPortal\PatientInviteNotifier($db))->sendInvite(
            (int)$auth['clinic_id'],(int)$params['id'],(string)$result['invitation_code'],(string)$result['expires_at'],(int)$auth['user_id']
        );
        // Mantém invitation_code (fallback: paciente sem e-mail, ou envio manual pelo profissional)
        // e acrescenta o resultado do envio do e-mail (sent/skipped/failed).
        echo json_encode($result + ['email_status'=>$delivery['status'],'email_reason'=>$delivery['reason']??null]);
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

    (new \ClyoNutri\Core\Auth\LastLoginService($db))->record((int)$user['id']);

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

// Diários alimentares dos pacientes da clínica (NI2-132).
// Filtros: ?patient_id= ?pending=1 ?from= ?to= (AAAA-MM-DD) ?limit= (padrão 30, máx. 100) ?cursor= (next_cursor).
// Cada leitura fica na auditoria (FOOD_DIARY_VIEWED). Sem e-mail, chave interna nem clinic_id.
$router->get('/api/v1/professional/food-diaries', function() use ($authenticateProfessionalApi, $db, $foodDiaryRespondError, $foodDiaryListParams) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];

    try {
        $opts = $foodDiaryListParams();
        if (isset($_GET['patient_id'])) {
            if (!is_string($_GET['patient_id']) || !ctype_digit($_GET['patient_id']) || (int)$_GET['patient_id'] < 1) {
                throw new \InvalidArgumentException('Paciente inválido (patient_id).');
            }
            $opts['patient_id'] = (int)$_GET['patient_id'];
        }
        $opts['pending'] = isset($_GET['pending']) && in_array((string)$_GET['pending'], ['1', 'true'], true);
        $service = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
        $page = $service->listClinicEntries($clinicId, $opts);
    } catch (\Throwable $e) {
        $foodDiaryRespondError($e, 'Paciente não encontrado.');
        return;
    }
    $service->auditProfessionalRead($clinicId, (int)$auth['user_id'], $opts['patient_id'] ?? null, [
        'returned' => count($page['diaries']),
        'pending_only' => $opts['pending'],
        'from' => $opts['from'] ?? null,
        'to' => $opts['to'] ?? null,
        'paged' => isset($opts['cursor']),
    ]);

    echo json_encode($page, JSON_UNESCAPED_UNICODE);
});

// Envio de feedback contextual em diário alimentar pelo nutricionista (até 2000 caracteres; substitui o anterior)
$router->post('/api/v1/professional/food-diary/{id}/feedback', function($params = []) use ($authenticateProfessionalApi, $db, $getJsonInput, $foodDiaryRespondError) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $auth = $authenticateProfessionalApi();
    $clinicId = (int)$auth['clinic_id'];
    $entryId = (int)($params['id'] ?? 0);

    $input = $getJsonInput();
    $feedback = is_array($input) && is_string($input['feedback'] ?? null) ? $input['feedback'] : '';

    try {
        if ($entryId <= 0) {
            throw new \InvalidArgumentException('Identificador da refeição inválido.');
        }
        $entry = (new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db))->submitProfessionalFeedback($entryId, $feedback);
    } catch (\Throwable $e) {
        $foodDiaryRespondError($e);
        return;
    }
    if ($entry === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Registro no diário não encontrado.', 'code' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $db->prepare("
        INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details, created_at)
        VALUES (:cid, :uid, 'FOOD_DIARY_FEEDBACK_GIVEN', 'food_diary_entry', :eid, :val, CURRENT_TIMESTAMP)
    ")->execute([
        'cid' => $clinicId,
        'uid' => (int)$auth['user_id'],
        'eid' => (string)$entryId,
        'val' => json_encode([
            'patient_id' => $entry['patient_id'],
            'feedback_length' => mb_strlen($entry['feedback_from_professional'], 'UTF-8'),
            'replaced_previous' => $entry['_replaced'],
        ]),
    ]);

    echo json_encode([
        'success' => true,
        'entry_id' => $entryId,
        'feedback' => $entry['feedback_from_professional'],
        'reviewed_at' => $entry['reviewed_at'],
    ], JSON_UNESCAPED_UNICODE);
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
