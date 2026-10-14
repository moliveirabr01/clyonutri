<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;

class AuthController
{
    private AuthService $authService;
    private PasswordResetService $resetService;

    public function __construct(?AuthService $authService = null, ?PasswordResetService $resetService = null)
    {
        $this->authService = $authService ?? new AuthService();
        $this->resetService = $resetService ?? new PasswordResetService();
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

        $user = $this->authService->attempt($email, $password);

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

        if (strlen($password) < 8) {
            $_SESSION['flash_error'] = 'A senha deve possuir pelo menos 8 dígitos.';
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
            $res = $this->resetService->requestReset($email, $ip, $ua);

            $_SESSION['flash_success'] = $res['message'];
            header('Location: /recuperar-senha');
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

        if (strlen($password) < 8) {
            $_SESSION['flash_error'] = 'A senha deve possuir pelo menos 8 dígitos.';
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
}
