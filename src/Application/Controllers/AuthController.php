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
}
