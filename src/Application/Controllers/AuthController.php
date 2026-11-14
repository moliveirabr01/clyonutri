<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\RegistrationRateLimiter;

class AuthController
{
    /** NI2-479: campo-armadilha do cadastro (fica escondido; gente não preenche, robô de formulário sim). */
    public const REGISTER_TRAP_FIELD = 'cn_campo_extra';

    /**
     * Catálogo estrito de códigos de planos permitidos no fluxo de cadastro (NI2-804 / ZAP-40033).
     */
    public const ALLOWED_REGISTER_PLANS = ['START-01', 'PRO-02', 'ELITE-03'];

    private AuthService $authService;
    private PasswordResetService $resetService;
    private ?RegistrationRateLimiter $registrationLimiter;

    public function __construct(?AuthService $authService = null, ?PasswordResetService $resetService = null, ?RegistrationRateLimiter $registrationLimiter = null)
    {
        $this->authService = $authService ?? new AuthService();
        $this->resetService = $resetService ?? new PasswordResetService();
        $this->registrationLimiter = $registrationLimiter;
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

        // NI2-604: motivo do fim da sessão e página para voltar depois de entrar.
        $sessionNotice = null;
        $reason = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';
        if ($error === null && $reason !== '') {
            $minutes = 120;
            try {
                $minutes = \ClyoNutri\Core\Settings\PlatformSettings::getSessionTimeoutMinutes();
            } catch (\Throwable $e) {
            }
            $sessionNotice = match ($reason) {
                'session_timeout' => "Sua sessão foi encerrada depois de {$minutes} minutos sem uso, para proteger os dados dos pacientes. Entre de novo para continuar de onde parou.",
                'session_expired' => 'Sua sessão terminou. Entre de novo para continuar de onde parou.',
                'session_revoked' => 'Seu acesso foi encerrado neste navegador (senha alterada ou acesso revisto pela clínica). Entre de novo.',
                default => null,
            };
        }
        $back = \ClyoNutri\Core\Http\SafeReturnPath::clean(is_string($_GET['voltar'] ?? null) ? $_GET['voltar'] : null);
        if ($back !== null) {
            $_SESSION['login_return_to'] = $back;
        }

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
            // v33: mesma frase para conta suspensa ou bloqueada (não diz qual, nem o motivo).
            $_SESSION['flash_error'] = $result['status'] === 'inactive'
                ? 'Seu acesso está indisponível no momento. Fale com o suporte da Clyo Nutri para saber como regularizar.'
                : 'E-mail ou senha incorretos.';
            $_SESSION['old_email'] = $email;
            header('Location: /login');
            exit;
        }

        // NI2-606: quem foi desativado pela clínica (e não tem outro vínculo ativo) não entra; antes caía num
        // vai-e-volta entre /login e /dashboard sem explicação.
        if (empty($user['clinic_id'])) {
            try {
                $rv = \ClyoNutri\Core\Database\Connection::getInstance()->prepare("SELECT COUNT(*) FROM clinic_members WHERE user_id = ? AND status IN ('revoked', 'inactive')");
                $rv->execute([(int)$user['id']]);
                if ((int)$rv->fetchColumn() > 0) {
                    $_SESSION['flash_error'] = 'Seu acesso a esta clínica foi desativado. Se isso não era esperado, fale com o gestor da clínica.';
                    $_SESSION['old_email'] = $email;
                    header('Location: /login');
                    exit;
                }
            } catch (\Throwable $e) {
                error_log('NI2-606 login check: ' . $e->getMessage());
            }
        }

        // SEC-F005: Encerramento de sessões concorrentes (Single Active Session)
        $terminateOtherSessions = !empty($_POST['terminate_other_sessions'])
            || \ClyoNutri\Core\Settings\PlatformSettings::isSingleSessionEnforced($this->authService->getDb());
        if ($terminateOtherSessions) {
            $newVersion = $this->authService->bumpAuthVersion((int)$user['id']);
            $user['auth_version'] = $newVersion;
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
        // NI2-604: volta para a página onde a sessão terminou (caminho interno revalidado; o controle de acesso da
        // própria página continua valendo).
        $back = \ClyoNutri\Core\Http\SafeReturnPath::clean($_SESSION['login_return_to'] ?? null);
        unset($_SESSION['login_return_to']);
        if ($back !== null) {
            $destination = $back;
        }

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
            . '<title>Sair · Clyo Nutri</title><link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9"></head>'
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

        // NI2-804 / ZAP-40033: validação estrita do parâmetro plano.
        // Rejeita tentativas de injeção de parâmetros estruturados/array (ex: plano[$gt]=0)
        $selectedPlan = null;
        if (isset($_GET['plano'])) {
            if (!is_string($_GET['plano'])) {
                if (!headers_sent()) {
                    http_response_code(400);
                }
                $csrfToken = Csrf::token();
                $error = 'Parâmetro de plano com formato inválido.';
                $old = [];
                require __DIR__ . '/../../../views/auth/register.php';
                return;
            }

            $candidatePlan = strtoupper(trim($_GET['plano']));
            if (in_array($candidatePlan, self::ALLOWED_REGISTER_PLANS, true)) {
                $selectedPlan = $candidatePlan;
            }
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

        // NI2-804 / ZAP-40033: validação de tipo e whitelist do plano
        $selectedPlan = null;
        if (isset($_POST['plano'])) {
            if (!is_string($_POST['plano'])) {
                if (!headers_sent()) {
                    http_response_code(400);
                }
                $_SESSION['flash_error'] = 'Formato de plano inválido.';
                header('Location: /cadastro');
                exit;
            }
            $candidatePlan = strtoupper(trim($_POST['plano']));
            if (in_array($candidatePlan, self::ALLOWED_REGISTER_PLANS, true)) {
                $selectedPlan = $candidatePlan;
            }
        }

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

        // NI2-449: o formulário envia a versão dos textos que a pessoa viu. Se mudou entre abrir a página
        // e enviar, ela precisa ler a versão nova antes de aceitar.
        $seenVersions = [];
        foreach (\ClyoNutri\Domain\Legal\LegalDocuments::currentVersions() as $document => $current) {
            $seen = trim((string)($_POST[$document . '_version'] ?? $current));
            if ($seen !== $current) {
                $_SESSION['flash_error'] = 'Os Termos de Uso ou a Política de Privacidade foram atualizados enquanto você preenchia o cadastro. Leia a versão nova e marque o aceite de novo.';
                unset($_POST['terms_accepted']);
                $_SESSION['old_input'] = $_POST;
                header('Location: /cadastro');
                exit;
            }
            $seenVersions[$document] = $seen;
        }

        if (mb_strlen($password, 'UTF-8') < 8) {
            $_SESSION['flash_error'] = 'A senha deve ter pelo menos 8 caracteres.';
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        }

        // NI2-479: daqui em diante o cadastro roda o bcrypt e cria a conta. Só agora entram o campo-armadilha
        // e o limite por IP, para que erro de digitação ou token vencido não gaste a cota de ninguém.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $keepInput = $_POST;
        unset($keepInput['password'], $keepInput[self::REGISTER_TRAP_FIELD]);
        if (trim((string)($_POST[self::REGISTER_TRAP_FIELD] ?? '')) !== '') {
            $this->auditRegisterBlock('REGISTER_BOT_BLOCKED', $ip);
            $_SESSION['flash_error'] = 'Não foi possível concluir o cadastro. Confira os dados e tente de novo.';
            $_SESSION['old_input'] = $keepInput;
            header('Location: /cadastro');
            exit;
        }
        $limiter = $this->registrationLimiter ?? new RegistrationRateLimiter(\ClyoNutri\Core\Database\Connection::getInstance());
        $gate = $limiter->attempt($ip);
        if (!$gate['allowed']) {
            $minutes = max(1, (int)ceil(((int)$gate['retry_after']) / 60));
            $_SESSION['flash_error'] = "Muitos cadastros feitos a partir desta rede na última hora. Tente de novo em {$minutes} min.";
            $_SESSION['old_input'] = $keepInput;
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
                $crnRegion,
                [
                    'source' => 'web_signup',
                    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'versions' => $seenVersions,
                ],
                $selectedPlan
            );

            // Login e inicialização imediata do onboarding
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'] ?? $user['id'];
            $_SESSION['clinic_id'] = $user['clinic_id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['clinic_name'] = $clinicName;
            $this->authService->recordLogin((int)($user['user_id'] ?? $user['id']));

            header('Location: /onboarding');
            exit;
        } catch (\InvalidArgumentException $e) {
            // Mensagens de validação do cadastro (ex.: e-mail já cadastrado), escritas para a pessoa.
            $_SESSION['flash_error'] = $e->getMessage();
            $_SESSION['old_input'] = $_POST;
            header('Location: /cadastro');
            exit;
        } catch (\Throwable $e) {
            // Erro interno (banco, gravação do aceite): o cadastro foi desfeito; detalhe só no log.
            error_log('[cadastro] falha ao registrar: ' . get_class($e) . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Não foi possível concluir o cadastro agora. Nada foi gravado; tente de novo em alguns minutos.';
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

            // NI2-481 P1c: aviso de segurança de senha alterada (não bloqueia o fluxo se o e-mail falhar).
            try {
                \ClyoNutri\Core\Auth\ProfessionalSecurityNotifier::make()->passwordChanged((int)($res['user_id'] ?? 0), $ip, $ua);
            } catch (\Throwable $e) {
                error_log('[ClyoNutri][password-reset] aviso de senha alterada falhou: ' . $e->getMessage());
            }

            $_SESSION['flash_success'] = 'Senha redefinida. Já dá para entrar com a nova senha.';
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

    /**
     * NI2-213 / ET08 / ET04: Verificação de e-mail profissional via token HMAC.
     */
    public function verifyEmail(array $params = []): void
    {
        $token = trim((string)($params['token'] ?? $_GET['token'] ?? ''));
        if ($token === '') {
            $_SESSION['flash_error'] = 'Token de verificação ausente.';
            header('Location: /login');
            exit;
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $profSec = \ClyoNutri\Core\Auth\ProfessionalSecurityNotifier::make();
        $userId = $profSec->verifyEmailToken($token, $ip, $ua);

        if ($userId !== null) {
            $_SESSION['flash_success'] = 'E-mail confirmado com sucesso! Você já pode acessar sua conta.';
        } else {
            $_SESSION['flash_error'] = 'Link de verificação inválido ou expirado. Acesse as configurações para solicitar um novo link.';
        }

        header('Location: /login');
        exit;
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

    /** NI2-479: registro de cadastro recusado (campo-armadilha); nunca derruba a resposta. */
    private function auditRegisterBlock(string $action, string $ip): void
    {
        try {
            \ClyoNutri\Core\Database\Connection::getInstance()->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                VALUES (NULL, NULL, ?, 'auth', NULL, ?, ?, ?)")->execute([
                $action, $ip, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), json_encode(['reason' => 'trap_field_filled']),
            ]);
        } catch (\Throwable) {
        }
    }
}
