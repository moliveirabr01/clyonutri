<?php
declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\MobileWebSession;
use ClyoNutri\Core\Security\LoginRateLimiter;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Legal\LegalAcceptanceService;
use ClyoNutri\Domain\Legal\LegalDocuments;
use ClyoNutri\Domain\PatientPortal\PatientOnboardingService;

final class PatientAppController
{
    private const LEGAL_SESSION = 'patient_legal_acceptance';

    public function index(array $params = []): void
    {
        // O formulário carrega um token CSRF ligado à sessão: página em cache traria token vencido.
        header('Cache-Control: no-store');
        $db = Connection::getInstance();
        $code = trim((string)($_GET['code'] ?? ''));
        $preview = null;
        $error = null;
        $step = 'landing';

        // v54.57 (UX-102): com a sessão do portal (cookie HttpOnly do login web), a página abre a área logada com o
        // plano publicado, a próxima consulta e a água de hoje, em vez de voltar ao formulário de entrada.
        if ($code === '' && ($session = self::portalSession($db)) !== null) {
            $step = 'logged_in';
            $portal = self::portalData($db, $session);
            if (isset($_GET['agua']) && is_string($_GET['agua'])) {
                $portalNotice = $_GET['agua'] === 'ok' ? 'Água registrada.' : 'Não foi possível registrar a água. Tente de novo.';
            }
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }
        if (isset($_GET['sessao']) && $code === '') {
            $error = 'Entre no portal para ver esta página. Sua sessão terminou ou ainda não foi iniciada neste navegador.';
        }

        if ($code !== '') {
            $step = 'first_access';
            try {
                $service = new PatientOnboardingService($db);
                $preview = $service->preview($code);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                $error = 'Não foi possível verificar o código de convite. Tente novamente.';
            }
        }

        require __DIR__ . '/../../../views/patient/app.php';
    }

    public function completeFirstAccess(): void
    {
        header('Cache-Control: no-store');
        $db = Connection::getInstance();
        $code = trim((string)($_POST['invitation_code'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
        $termsAccepted = !empty($_POST['terms_accepted']);

        $error = null;
        $step = 'first_access';
        $preview = null;

        // NI2-806 / ZAP-20012: proteção CSRF no primeiro acesso do paciente
        if (!\ClyoNutri\Core\Security\Csrf::validate($_POST['_csrf_token'] ?? '')) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            $error = 'Token de segurança expirado ou inválido. Atualize a página e tente novamente.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }

        if (!$termsAccepted) {
            $error = 'Você precisa concordar com os Termos de Uso e Política de Privacidade para continuar.';
        }

        if ($error === null) {
            try {
                $service = new PatientOnboardingService($db);
                $result = $service->complete([
                    'invitation_code' => $code,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $password,
                    'password_confirmation' => $passwordConfirmation,
                ], [
                    // NI2-449: aceite gravado em patient_consents na mesma transação do primeiro acesso.
                    'channel' => 'web',
                    'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                ]);

                // Inicia sessão web segura (v54.57 / UX-102: formulário HTML grava o cookie do portal também)
                self::startWebSession($result['token'], $result['expires_at']);

                $step = 'activated';
                $patient = $result['patient'];
                require __DIR__ . '/../../../views/patient/app.php';
                return;
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                $error = 'Não foi possível concluir o primeiro acesso. Tente novamente.';
            }
        }

        if ($code !== '') {
            try {
                $preview = (new PatientOnboardingService($db))->preview($code);
            } catch (\Throwable) {
                // continua com preview null
            }
        }

        require __DIR__ . '/../../../views/patient/app.php';
    }

    public function login(): void
    {
        header('Cache-Control: no-store');
        $db = Connection::getInstance();
        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $error = null;
        $step = 'landing';

        // NI2-806 / ZAP-20012: proteção CSRF e Login CSRF no portal do paciente com validação estrita de tipo
        $csrfCandidate = $_POST['_csrf_token'] ?? null;
        if (!is_string($csrfCandidate) || !\ClyoNutri\Core\Security\Csrf::validate($csrfCandidate)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            $error = 'Token de segurança expirado ou inválido. Atualize a página e tente novamente.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }

        if ($login === '' || $password === '') {
            $error = 'Informe seu e-mail ou celular e sua senha.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }

        $limiter = new LoginRateLimiter($db);
        $account = LoginRateLimiter::resolvePatientIdentity($db, $login, $login);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $gate = $limiter->attempt('patient', $account, $ip);
        if (!$gate['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . max(1, (int)$gate['retry_after']));
            $error = 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }

        // A tabela patients não tem coluna cpf (nem no instalacao_mysql8.sql nem nas migrations): o login é por e-mail ou celular.
        $stmt = $db->prepare("
            SELECT p.*, c.name AS clinic_name 
            FROM patients p 
            JOIN clinics c ON c.id = p.clinic_id 
            WHERE (LOWER(p.email) = LOWER(:login_email) OR p.phone = :login_phone)
              AND p.status = 'active' AND c.status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['login_email' => $login, 'login_phone' => $login]);
        $patient = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$patient || empty($patient['password_hash']) || !password_verify($password, $patient['password_hash'])) {
            $limiter->failure('patient', $account, $ip);
            $error = 'Credenciais incorretas. Confira seus dados ou use o Primeiro Acesso com código de convite.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }

        $limiter->success('patient', $account, $ip);
        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        $db->prepare("
            INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, clinic_id, name, token, expires_at)
            VALUES('patient', ?, ?, 'mobile_web', ?, ?)
        ")->execute([$patient['id'], $patient['clinic_id'], hash('sha256', $token), $expires]);

        self::startWebSession($token, $expires);

        // NI2-449: sem aceite da versão vigente dos Termos/Política, a paciente confirma antes de seguir.
        $pendingLegal = $this->pendingLegal($db, (int)$patient['id'], (int)$patient['clinic_id']);
        $step = $pendingLegal === [] ? 'logged_in' : 'accept_terms';
        if ($step === 'accept_terms') {
            // A página do app não usa o cookie do token (ele é restrito a /api/v1): quem acabou de entrar com a
            // senha fica marcado na sessão do servidor por 15 minutos para confirmar o aceite.
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (!headers_sent()) {
                session_regenerate_id(true);
            }
            $_SESSION[self::LEGAL_SESSION] = ['patient_id' => (int)$patient['id'], 'clinic_id' => (int)$patient['clinic_id'], 'until' => time() + 900];
        }
        if ($step === 'logged_in' && !headers_sent() && ($_SERVER['HTTP_X_CLYONUTRI_WEB'] ?? '') !== '1') {
            header('Location: /paciente/app', true, 303); // v54.57: PRG; a área logada lê a sessão do cookie
            return;
        }
        require __DIR__ . '/../../../views/patient/app.php';
    }

    /**
     * v54.57 (UX-102): o login e o primeiro acesso do portal são formulários HTML (CSRF de sessão já validado no
     * roteador). Antes só o cliente da API (cabeçalho X-ClyoNutri-Web) recebia o cookie, e o portal web ficava sem
     * sessão: "Minhas consultas" respondia 401 em JSON.
     */
    private static function startWebSession(string $token, string $expiresAt): void
    {
        if (($_SERVER['HTTP_X_CLYONUTRI_WEB'] ?? '') === '1') {
            MobileWebSession::start($token, $expiresAt);
            return;
        }
        try {
            MobileWebSession::startFromPortal($token, $expiresAt);
        } catch (\DomainException $e) {
            error_log('[portal] cookie do portal não gravado: ' . $e->getMessage());
        }
    }

    /**
     * v54.57 (UX-102): paciente da sessão do portal (cookie), com as mesmas regras do token da API: token de
     * paciente, não expirado, paciente e clínica ativos. Define o TenantContext da paciente.
     */
    public static function portalSession(\PDO $db): ?array
    {
        $plain = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? MobileWebSession::portalFormToken() : MobileWebSession::token();
        if ($plain === null) {
            return null;
        }
        $st = $db->prepare("SELECT t.id AS token_id, p.id AS patient_id, p.clinic_id, p.name AS patient_name, c.name AS clinic_name
            FROM personal_access_tokens t JOIN patients p ON p.id = t.tokenable_id JOIN clinics c ON c.id = p.clinic_id
            WHERE t.tokenable_type = 'patient' AND t.clinic_id = p.clinic_id AND p.status = 'active' AND c.status = 'active'
              AND t.token = :h AND t.expires_at IS NOT NULL AND t.expires_at > CURRENT_TIMESTAMP LIMIT 1");
        $st->execute(['h' => hash('sha256', $plain)]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        \ClyoNutri\Core\Auth\TenantContext::set((int)$row['clinic_id'], ['id' => (int)$row['patient_id'], 'role' => 'patient', 'name' => $row['patient_name']]);
        return $row;
    }

    /** v54.57 (UX-102): dados reais da área logada (plano publicado, próxima consulta, água de hoje e meta). */
    private static function portalData(\PDO $db, array $session): array
    {
        $pid = (int)$session['patient_id'];
        $api = new \ClyoNutri\Application\Api\PatientApiController($db);
        $out = ['patient_name' => (string)$session['patient_name'], 'clinic_name' => (string)$session['clinic_name'], 'plan' => null, 'next' => null, 'water_today' => 0, 'water_goal' => null];
        try { $out['plan'] = $api->getDailyMealPlan($pid); } catch (\Throwable $e) { error_log('[portal] plano: ' . get_class($e)); }
        try { $out['next'] = $api->portal()->getNextAppointment($pid); } catch (\Throwable $e) { error_log('[portal] consulta: ' . get_class($e)); }
        try { $out['water_today'] = $api->getHydrationTodayTotal($pid); $out['water_goal'] = $api->getPrescribedWaterGoal($pid); } catch (\Throwable $e) { error_log('[portal] água: ' . get_class($e)); }
        return $out;
    }

    /** v54.57 (UX-102): plano alimentar publicado, em leitura, no portal web. */
    public function plan(array $params = []): void
    {
        header('Cache-Control: no-store, private');
        $db = Connection::getInstance();
        $session = self::portalSession($db);
        if ($session === null) {
            header('Location: /paciente/app?sessao=1', true, 303);
            return;
        }
        $portal = self::portalData($db, $session);
        if ($portal['plan'] !== null) {
            (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($db))->record(\ClyoNutri\Domain\Analytics\AdherenceAnalytics::PLAN_VIEWED, (int)$session['clinic_id'], (int)$session['patient_id']);
        }
        require __DIR__ . '/../../../views/patient/portal_plan.php';
    }

    /** v54.57 (UX-102): registro de água pelo portal web (mesmo serviço e regras da API; CSRF de sessão no roteador). */
    public function logWater(array $params = []): void
    {
        header('Cache-Control: no-store, private');
        $db = Connection::getInstance();
        $session = self::portalSession($db);
        if ($session === null) {
            header('Location: /paciente/app?sessao=1', true, 303);
            return;
        }
        $amount = $_POST['amount_ml'] ?? null;
        $ok = false;
        try {
            (new \ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter($db))->hit('hydration_write', 'patient:' . (int)$session['clinic_id'] . ':' . (int)$session['patient_id'], 30, 60);
            $key = is_string($_POST['idempotency_key'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $_POST['idempotency_key']) ? $_POST['idempotency_key'] : null;
            $res = (new \ClyoNutri\Application\Api\PatientApiController($db))->logHydration((int)$session['patient_id'], is_string($amount) && ctype_digit($amount) ? (int)$amount : $amount, ['idempotency_key' => $key, 'source' => 'app']);
            if (empty($res['idempotent_replay'])) {
                (new \ClyoNutri\Domain\Analytics\AdherenceAnalytics($db))->record(\ClyoNutri\Domain\Analytics\AdherenceAnalytics::HYDRATION_LOGGED, (int)$session['clinic_id'], (int)$session['patient_id']);
            }
            $ok = true;
        } catch (\Throwable $e) {
            error_log('[portal] água não registrada: ' . get_class($e));
        }
        header('Location: /paciente/app?agua=' . ($ok ? 'ok' : 'erro') . '#agua', true, 303);
    }

    /** v54.57 (UX-102): sair do portal web (revoga o token e apaga os cookies). */
    public function logout(array $params = []): void
    {
        $db = Connection::getInstance();
        $plain = MobileWebSession::portalFormToken();
        if ($plain !== null) {
            $db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type = 'patient' AND token = ?")->execute([hash('sha256', $plain)]);
        }
        MobileWebSession::clear();
        header('Location: /paciente/app', true, 303);
    }

    /** NI2-449: grava o aceite da paciente logada (sessão web do app) e segue para a área logada. */
    public function acceptTerms(): void
    {
        header('Cache-Control: no-store');
        $db = Connection::getInstance();
        $error = null;
        $patient = $this->legalSessionPatient($db);
        if ($patient === null) {
            $step = 'landing';
            $error = 'Sua sessão terminou. Entre de novo para continuar.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }
        $pendingLegal = $this->pendingLegal($db, (int)$patient['id'], (int)$patient['clinic_id']);
        $step = 'accept_terms';
        if ($pendingLegal === []) {
            $step = 'logged_in';
        } elseif (!is_string($_POST['_csrf_token'] ?? null) || !\ClyoNutri\Core\Security\Csrf::validate($_POST['_csrf_token'])) {
            $error = 'Sua sessão expirou. Marque o aceite e envie de novo.';
        } elseif (empty($_POST['accepted'])) {
            $error = 'Para continuar, marque que leu e aceita os textos.';
        } else {
            foreach ($pendingLegal as $document) {
                if (trim((string)($_POST[$document . '_version'] ?? '')) !== LegalDocuments::currentVersion($document)) {
                    $error = 'Os textos foram atualizados enquanto esta página estava aberta. Leia a versão nova e confirme de novo.';
                    break;
                }
            }
            if ($error === null) {
                (new LegalAcceptanceService($db))->recordPatientAcceptance(
                    (int)$patient['id'],
                    (int)$patient['clinic_id'],
                    'web',
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
                );
                unset($_SESSION[self::LEGAL_SESSION]);
                $step = 'logged_in';
            }
        }
        if ($error !== null) {
            http_response_code(422);
        }
        require __DIR__ . '/../../../views/patient/app.php';
    }

    /** @return list<string> */
    private function pendingLegal(\PDO $db, int $patientId, int $clinicId): array
    {
        if (!\ClyoNutri\Domain\Legal\LegalAcceptanceGate::enabled()) {
            return []; // reaceite desligado até a revisão jurídica (LEGAL_REACCEPTANCE_ENABLED)
        }
        try {
            return (new LegalAcceptanceService($db))->pendingPatientDocuments($patientId, $clinicId);
        } catch (\PDOException $e) {
            error_log('[aceite-termos] verificação do paciente ignorada: ' . $e->getMessage());
            return [];
        }
    }

    /** Paciente que acabou de entrar com a senha e ainda precisa aceitar (marcado na sessão, 15 min). */
    private function legalSessionPatient(\PDO $db): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $mark = $_SESSION[self::LEGAL_SESSION] ?? null;
        if (!is_array($mark) || (int)($mark['until'] ?? 0) < time()) {
            unset($_SESSION[self::LEGAL_SESSION]);
            return null;
        }
        $stmt = $db->prepare("SELECT p.id, p.clinic_id FROM patients p JOIN clinics c ON c.id = p.clinic_id
            WHERE p.id = ? AND p.clinic_id = ? AND p.status = 'active' AND c.status = 'active' LIMIT 1");
        $stmt->execute([(int)$mark['patient_id'], (int)$mark['clinic_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
