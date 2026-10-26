<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\RegistrationRateLimiter;

class AuthController
{
    private AuthService $authService;
    private PasswordResetService $resetService;
    private RegistrationRateLimiter $registrationRateLimiter;

    public function __construct(
        ?AuthService $authService = null,
        ?PasswordResetService $resetService = null,
        ?RegistrationRateLimiter $registrationRateLimiter = null
    ) {
        $this->authService = $authService ?? new AuthService();
        $this->resetService = $resetService ?? new PasswordResetService();
        $this->registrationRateLimiter = $registrationRateLimiter ?? new RegistrationRateLimiter(Connection::getInstance());
    }

    public function showLogin(): void
    {
        // Se já autenticado, redireciona conforme papel canônico
        if (!empty($_SESSION['user_id'])) {
            $role = (string)($_SESSION['user_role'] ?? '');
            $destination = match ($role) {
                'operator', 'support_agent' => '/suporte/fila',
                'receptionist' => '/agenda',
                default => '/dashboard',
            };
            header('Location: ' . $destination);
            exit;
        }

        $csrfToken = Csrf::token();
        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        $oldEmail = $_SESSION['old_email'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['old_email']);

        require __DIR__ . '/../../../views/auth/login.php';
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        if ($email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Preencha todos os campos para continuar.';
            $_SESSION['old_email'] = $email;
            header('Location: /login');
            exit;
        }

        // F03: limitador persistente por conta+IP e por IP, antes da verificação do hash.
        $result = $this->authService->attemptWithRateLimit($email, $password, (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        if ($result['status'] === 'locked') {
            $_SESSION['flash_error'] = 'Muitas tentativas de acesso. Por segurança, o acesso com este e-mail foi pausado temporariamente; aguarde alguns minutos e tente novamente.';
            $_SESSION['old_email'] = $email;
            header('Retry-After: ' . max(1, (int)$result['retry_after']));
            header('Location: /login');
            exit;
        }
        $user = $result['user'];

        if (!$user) {
            $_SESSION['flash_error'] = 'E-mail ou senha incorretos.';
            $_SESSION['old_email'] = $email;
            header('Location: /login');
            exit;
        }

        // Sessão segura inicializada e regenerada (prevenção de session fixation)
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['auth_version'] = (int)$user['auth_version'];
        $_SESSION['clinic_id'] = $user['clinic_id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['clinic_name'] = $user['clinic_name'] ?? 'Minha Clínica';
        $_SESSION['last_activity'] = time();

        $destination = match ($user['role']) {
            'superadmin', 'admin_operacional', 'finance_admin', 'security_auditor' => '/admin',
            'operator', 'support_agent' => '/suporte/fila',
            'receptionist' => '/agenda',
            default => '/dashboard',
        };

        header('Location: ' . $destination);
        exit;
    }

    /**
     * GET /logout não altera estado (evita logout forçado por <img>/link de terceiros).
     * Responde 405 com Allow: POST e oferece um formulário de confirmação com CSRF.
     */
    public function logoutConfirm(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/html; charset=utf-8');
        $token = htmlspecialchars(\ClyoNutri\Core\Security\Csrf::token(), ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Sair — ClyoNutri</title><link rel="stylesheet" href="/assets/css/clinical-botanical.css"></head>'
            . '<body><main class="logout-confirm" data-testid="logout-confirm"><h1 class="logout-confirm__title">Encerrar sessão?</h1>'
            . '<p class="logout-confirm__text">Por segurança, a saída do sistema precisa ser confirmada.</p>'
            . '<form method="POST" action="/logout"><input type="hidden" name="_csrf_token" value="' . $token . '">'
            . '<button type="submit" class="btn btn-primary">Sair agora</button> '
            . '<a href="/dashboard" class="btn btn-outline">Cancelar</a></form></main></body></html>';
    }

    public function logout(): void
    {
        // A rota só é registrada como POST e o Router valida o token CSRF antes de chegar aqui.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->logoutConfirm();
            return;
        }

        \ClyoNutri\Core\Auth\AuthContext::clear();
        TenantContext::clear();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        if (!headers_sent()) {
            header('Clear-Site-Data: "storage"');
        }
        header('Location: /login');
        exit;
    }

    public function showRegister(): void
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        $csrfToken = Csrf::token();
        $error = $_SESSION['flash_error'] ?? null;
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['flash_error'], $_SESSION['old_input']);

        require __DIR__ . '/../../../views/auth/register.php';
    }

    public function register(): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // 1. Anti-Bot Honeypot (campo website_url deve permanecer vazio)
        $honeypot = trim((string)($_POST['website_url'] ?? ''));
        if ($honeypot !== '') {
            try {
                $db = Connection::getInstance();
                $st = $db->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, details, ip_address, user_agent) VALUES (NULL, NULL, 'REGISTER_BOT_BLOCKED', 'auth', :d, :ip, :ua)");
                $st->execute([
                    'd' => json_encode(['reason' => 'honeypot_filled'], JSON_UNESCAPED_SLASHES),
                    'ip' => $ip,
                    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);
            } catch (\Throwable) {}

            $_SESSION['flash_error'] = 'Requisição inválida detectada.';
            header('Location: /cadastro');
            exit;
        }

        // 2. Limitador de taxa por IP (NI2-479 / SEC-01)
        $rateCheck = $this->registrationRateLimiter->attempt($ip);
        if (!$rateCheck['allowed']) {
            $_SESSION['flash_error'] = 'Muitas tentativas de cadastro a partir deste endereço IP. Por favor, aguarde alguns minutos e tente novamente.';
            $cleanOld = $_POST;
            unset($cleanOld['password'], $cleanOld['website_url']);
            $_SESSION['old_input'] = $cleanOld;
            header('Retry-After: ' . max(1, (int)$rateCheck['retry_after']));
            http_response_code(429);
            header('Location: /cadastro');
            exit;
        }

        // 3. Validação de token CSRF
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente novamente.';
            header('Location: /cadastro');
            exit;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $clinicName = trim((string)($_POST['clinic_name'] ?? ''));
        $crn = trim((string)($_POST['crn'] ?? ''));
        $crnRegion = trim((string)($_POST['crn_region'] ?? 'CRN-3'));

        if ($name === '' || $email === '' || $password === '' || $clinicName === '' || $crn === '') {
            $_SESSION['flash_error'] = 'Preencha todos os campos obrigatórios para ativar o consultório.';
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        }

        if (empty($_POST['terms_accepted'])) {
            $_SESSION['flash_error'] = 'É obrigatório ler e concordar com os Termos de Uso e a Política de Privacidade (LGPD).';
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        }

        if (mb_strlen($password, 'UTF-8') < 8) {
            $_SESSION['flash_error'] = 'A senha deve ter pelo menos 8 caracteres.';
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        }

        try {
            $user = $this->authService->registerNutritionist(
                $name,
                $email,
                $password,
                $clinicName,
                $crn,
                $crnRegion
            );

            // Login e inicialização imediata do onboarding
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'] ?? $user['id'];
            $_SESSION['clinic_id'] = $user['clinic_id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['clinic_name'] = $clinicName;
            $_SESSION['last_activity'] = time();

            header('Location: /onboarding');
            exit;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        }
    }

    /**
     * Exibe a tela de solicitação de recuperação de senha (PX-29 / NI2-322).
     */
    public function showForgotPassword(): void
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        $csrfToken = Csrf::token();
        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        $oldEmail = $_SESSION['old_email'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['old_email']);

        require __DIR__ . '/../../../views/auth/forgot-password.php';
    }

    /**
     * Processa a solicitação de envio do link de recuperação (não-enumerável).
     */
    public function sendResetLink(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente novamente.';
            header('Location: /recuperar-senha');
            exit;
        }

        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '') {
            $_SESSION['flash_error'] = 'Por favor, informe seu endereço de e-mail.';
            header('Location: /recuperar-senha');
            exit;
        }

        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            // O serviço envia o link por e-mail; a resposta é sempre a mesma mensagem genérica
            // (não revela se a conta existe nem se o envio falhou) e nunca contém o token.
            // O envio é agendado e só roda depois que o redirecionamento sai, para que o tempo de
            // resposta também não revele se a conta existe.
            $pending = [];
            $this->resetService->deferEmailDelivery(static function (\Closure $job) use (&$pending): void {
                $pending[] = $job;
            });
            $res = $this->resetService->requestReset($email, $ip, $ua);
            $this->resetService->deferEmailDelivery(null);

            $_SESSION['flash_success'] = $res['message'];
            if ($pending) {
                $this->redirectAndContinue('/recuperar-senha');
                foreach ($pending as $job) {
                    $job(); // nunca lança: falhas vão para error_log + auditoria
                }
            } else {
                header('Location: /recuperar-senha');
            }
            exit;
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $_SESSION['old_email'] = $email;
            header('Location: /recuperar-senha');
            exit;
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $_SESSION['old_email'] = $email;
            header('Location: /recuperar-senha');
            exit;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Ocorreu uma instabilidade ao processar a solicitação. Tente novamente.';
            header('Location: /recuperar-senha');
            exit;
        }
    }

    /**
     * Exibe o formulário para criação da nova senha com base no token.
     */
    public function showResetPassword(array $params = []): void
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        // O token está na URL: impede que vaze via cabeçalho Referer para recursos/links externos.
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
        }

        $token = trim((string)($params['token'] ?? $_GET['token'] ?? ''));
        $csrfToken = Csrf::token();
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $tokenData = $this->resetService->validateToken($token);
        $isValidToken = ($tokenData !== null);

        require __DIR__ . '/../../../views/auth/reset-password.php';
    }

    /**
     * Redefine a senha do usuário e revoga sessões anteriores.
     */
    public function resetPassword(array $params = []): void
    {
        $tokenParam = trim((string)($params['token'] ?? $_POST['token'] ?? ''));
        $csrf = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($csrf)) {
            $_SESSION['flash_error'] = 'Token de segurança expirado. Tente novamente.';
            header("Location: /recuperar-senha/{$tokenParam}");
            exit;
        }

        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

        if ($password === '' || $passwordConfirmation === '') {
            $_SESSION['flash_error'] = 'Preencha a nova senha e a confirmação.';
            header("Location: /recuperar-senha/{$tokenParam}");
            exit;
        }

        if ($password !== $passwordConfirmation) {
            $_SESSION['flash_error'] = 'A confirmação de senha não coincide com a nova senha.';
            header("Location: /recuperar-senha/{$tokenParam}");
            exit;
        }

        if (mb_strlen($password, 'UTF-8') < 8) {
            $_SESSION['flash_error'] = 'A senha deve ter pelo menos 8 caracteres.';
            header("Location: /recuperar-senha/{$tokenParam}");
            exit;
        }

        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $res = $this->resetService->resetPassword($tokenParam, $password, $ip, $ua);

            $_SESSION['flash_success'] = 'Sua senha foi redefinida com sucesso! Você já pode entrar com a nova senha.';
            header('Location: /login');
            exit;
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: /recuperar-senha/{$tokenParam}");
            exit;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Falha ao redefinir a credencial. Solicite um novo link de recuperação.';
            header('Location: /recuperar-senha');
            exit;
        }
    }

    /** Envia o redirecionamento ao navegador e continua o processamento em segundo plano. */
    private function redirectAndContinue(string $location): void
    {
        header('Location: ' . $location, true, 302);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        header('Connection: close');
        header('Content-Length: 0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
