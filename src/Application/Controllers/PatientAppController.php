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

                // Inicia sessão web mobile segura
                MobileWebSession::start($result['token'], $result['expires_at']);

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

        MobileWebSession::start($token, $expires);

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
        require __DIR__ . '/../../../views/patient/app.php';
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
