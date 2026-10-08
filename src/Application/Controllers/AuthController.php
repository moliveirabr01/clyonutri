<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;

class AuthController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function showLogin(): void
    {
        // Se já autenticado, redireciona para o dashboard
        if (!empty($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        $csrfToken = Csrf::token();
        $error = $_SESSION['flash_error'] ?? null;
        $oldEmail = $_SESSION['old_email'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['old_email']);

        require __DIR__ . '/../../../views/auth/login.php';
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

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

        // Sessão segura inicializada
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['clinic_id'] = $user['clinic_id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['clinic_name'] = $user['clinic_name'] ?? 'Minha Clínica';

        header('Location: /dashboard');
        exit;
    }

    public function logout(): void
    {
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

            header('Location: /dashboard');
            exit;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        }
    }
}
