<?php
declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\MobileWebSession;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\PatientPortal\PatientOnboardingService;

final class PatientAppController
{
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

        if ($login === '' || $password === '') {
            $error = 'Informe seu e-mail ou celular e sua senha.';
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
            $error = 'Credenciais incorretas. Confira seus dados ou use o Primeiro Acesso com código de convite.';
            require __DIR__ . '/../../../views/patient/app.php';
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        $db->prepare("
            INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, clinic_id, name, token, expires_at)
            VALUES('patient', ?, ?, 'mobile_web', ?, ?)
        ")->execute([$patient['id'], $patient['clinic_id'], hash('sha256', $token), $expires]);

        MobileWebSession::start($token, $expires);

        $step = 'logged_in';
        require __DIR__ . '/../../../views/patient/app.php';
    }
}
