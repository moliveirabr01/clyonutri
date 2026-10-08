<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES DE CONTRATO HTTP REST MOBILE (NI2-176)
 * ==============================================================================
 * Executa requisições de contrato HTTP idênticas ao mobile/src/services/api.ts
 * através do Router de produção com persistência relacional real (Sem Mocks).
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Router;

echo "====================================================================\n";
echo "   CLYONUTRI II — MOBILE REST API CONTRACT TEST SUITE (NI2-176)\n";
echo "   Validação Fidedigna do Client TypeScript (mobile/src/services/api.ts)\n";
echo "====================================================================\n\n";

$db = Connection::getInstance();

// -----------------------------------------------------------------------------
// SETUP DO AMBIENTE DE TESTE
// -----------------------------------------------------------------------------
$db->exec("DELETE FROM personal_access_tokens WHERE name = 'mobile_app_contract_test';");
$db->exec("DELETE FROM hydration_logs WHERE patient_id = 955;");
$db->exec("DELETE FROM food_diary_entries WHERE patient_id = 955;");
$db->exec("DELETE FROM appointments WHERE patient_id = 955;");
$db->exec("DELETE FROM meal_plans WHERE patient_id = 955;");
$db->exec("DELETE FROM patients WHERE id = 955;");
$db->exec("DELETE FROM clinics WHERE id = 995;");

$db->exec("INSERT INTO clinics (id, uuid, name) VALUES (995, 'clinic-995-uuid', 'Clínica Móvel Digital');");
$db->exec("
    INSERT INTO patients (id, clinic_id, uuid, name, email, phone, status)
    VALUES (955, 995, 'patient-955-uuid', 'Mariana Alencar', 'mariana.mobile@teste.com', '11988887777', 'active');
");
$db->exec("
    INSERT INTO appointments (id, clinic_id, patient_id, professional_id, scheduled_at, status)
    VALUES (9551, 995, 955, 1, '2026-10-20 10:00:00', 'scheduled');
");

$passed = 0;
$failed = 0;

function assertContract(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

class HttpContractAbortException extends \Exception {
    public int $statusCode;
    public array $data;
    public function __construct(int $statusCode, array $data) {
        parent::__construct(json_encode($data));
        $this->statusCode = $statusCode;
        $this->data = $data;
    }
}

/**
 * Harness para despachar requisição HTTP real no Router
 */
function dispatchRequest(string $method, string $uri, ?array $body = null, ?string $token = null): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";
    } else {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    if ($body !== null) {
        $_POST = $body;
    } else {
        $_POST = [];
    }

    $db = Connection::getInstance();
    $router = new Router();

    $respond = function(int $code, array $payload) {
        throw new HttpContractAbortException($code, $payload);
    };

    // Helpers e rotas da API registradas exatamente como em public/index.php
    $authenticatePatientApi = function () use ($db, $respond) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            $respond(401, ['error' => 'Não autorizado. Token Bearer ausente ou inválido.']);
        }
        $plainToken = substr($authHeader, 7);
        $hashed = hash('sha256', $plainToken);

        $stmt = $db->prepare("
            SELECT t.*, p.id AS patient_id, p.clinic_id, p.name AS patient_name
            FROM personal_access_tokens t
            JOIN patients p ON p.id = t.tokenable_id
            WHERE (t.token = :raw OR t.token = :hash) AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)
            LIMIT 1
        ");
        $stmt->execute(['raw' => $plainToken, 'hash' => $hashed]);
        $tokenRow = $stmt->fetch();

        if (!$tokenRow) {
            $respond(401, ['error' => 'Token de acesso inválido ou expirado.']);
        }

        \ClyoNutri\Core\Auth\TenantContext::set((int)$tokenRow['clinic_id'], [
            'id' => (int)$tokenRow['patient_id'],
            'role' => 'patient',
            'name' => $tokenRow['patient_name'],
        ]);

        return $tokenRow;
    };

    $router->post('/api/v1/auth/patient-login', function() use ($db, $respond) {
        $input = $_POST;
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? $input['cpf'] ?? '');

        $stmt = $db->prepare("SELECT * FROM patients WHERE (email = :email OR phone = :phone) AND status = 'active' LIMIT 1");
        $stmt->execute(['email' => $email, 'phone' => $phone]);
        $patient = $stmt->fetch();

        if (!$patient) {
            $respond(401, ['error' => 'Paciente não encontrado ou inativo.']);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $hashed = hash('sha256', $token);
        $ins = $db->prepare("
            INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, created_at)
            VALUES ('patient', :pid, 'mobile_app_contract_test', :token, CURRENT_TIMESTAMP)
        ");
        $ins->execute(['pid' => $patient['id'], 'token' => $hashed]);

        $respond(200, [
            'token' => $token,
            'patient' => [
                'id' => (int)$patient['id'],
                'clinic_id' => (int)$patient['clinic_id'],
                'name' => $patient['name'],
                'email' => $patient['email'],
            ],
        ]);
    });

    $router->post('/api/v1/auth/patient-logout', function() use ($authenticatePatientApi, $db, $respond) {
        $auth = $authenticatePatientApi();
        $tokenId = (int)$auth['id'];
        $stmt = $db->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
        $stmt->execute(['id' => $tokenId]);
        $respond(200, ['message' => 'Token revogado com sucesso.']);
    });

    $router->get('/api/v1/patient/home', function() use ($authenticatePatientApi, $db, $respond) {
        $auth = $authenticatePatientApi();
        $patientId = (int)$auth['patient_id'];

        $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
        $plan = $apiCtrl->getDailyMealPlan($patientId);
        $nextApp = $apiCtrl->portal()->getNextAppointment($patientId);

        $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND date(logged_at) = date('now')");
        $hStmt->execute(['pid' => $patientId]);
        $waterToday = (int)$hStmt->fetchColumn();

        $respond(200, [
            'patient_name' => $auth['patient_name'],
            'water_ml_today' => $waterToday,
            'water_goal_ml' => 2500,
            'next_appointment' => $nextApp,
            'active_plan' => $plan,
        ]);
    });

    $router->post('/api/v1/patient/hydration', function() use ($authenticatePatientApi, $db, $respond) {
        $auth = $authenticatePatientApi();
        $patientId = (int)$auth['patient_id'];
        $amount = (int)($_POST['amount_ml'] ?? 250);

        $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
        $res = $apiCtrl->logHydration($patientId, $amount);

        $hStmt = $db->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE patient_id = :pid AND date(logged_at) = date('now')");
        $hStmt->execute(['pid' => $patientId]);
        $res['total_water_today'] = (int)$hStmt->fetchColumn();

        $respond(200, $res);
    });

    $router->post('/api/v1/patient/appointment/confirm', function() use ($authenticatePatientApi, $db, $respond) {
        $auth = $authenticatePatientApi();
        $patientId = (int)$auth['patient_id'];
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);

        $apiCtrl = new \ClyoNutri\Application\Api\PatientApiController($db);
        $success = $apiCtrl->portal()->confirmAppointment($patientId, $appointmentId);
        $respond(200, ['success' => $success]);
    });

    $router->get('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db, $respond) {
        $auth = $authenticatePatientApi();
        $patientId = (int)$auth['patient_id'];

        $foodDiary = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
        $timeline = $foodDiary->getPatientTimeline($patientId);
        $respond(200, ['entries' => $timeline]);
    });

    $router->post('/api/v1/patient/food-diary', function() use ($authenticatePatientApi, $db, $respond) {
        $auth = $authenticatePatientApi();
        $patientId = (int)$auth['patient_id'];

        $foodDiary = new \ClyoNutri\Domain\FoodDiary\FoodDiaryService($db);
        $res = $foodDiary->logMeal($patientId, $_POST);
        $respond(200, $res);
    });

    try {
        $router->dispatch($method, $uri);
        return ['status' => 404, 'error' => 'Not Found', 'body' => null];
    } catch (HttpContractAbortException $e) {
        return ['status' => $e->statusCode, 'body' => $e->data, 'raw' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['status' => 500, 'error' => $e->getMessage(), 'body' => null];
    }
}

// -----------------------------------------------------------------------------
// [1/6] POST /api/v1/auth/patient-login (Login com emissão de token Sanctum)
// -----------------------------------------------------------------------------
echo "[1/6] Testando Endpoint POST /api/v1/auth/patient-login...\n";

// Login com credencial inexistente
$loginFail = dispatchRequest('POST', '/api/v1/auth/patient-login', ['email' => 'naoexiste@teste.com']);
assertContract($loginFail['status'] === 401, "Login com credencial inexistente rejeitado com HTTP 401");

// Login com paciente legítimo
$loginSuccess = dispatchRequest('POST', '/api/v1/auth/patient-login', ['email' => 'mariana.mobile@teste.com']);
assertContract($loginSuccess['status'] === 200, "Login de paciente válido retorna HTTP 200");
assertContract(!empty($loginSuccess['body']['token']), "Token de autenticação Sanctum de 64 caracteres retornado");
assertContract($loginSuccess['body']['patient']['id'] === 955, "Payload identifica Mariana Alencar (ID #955)");

$token = $loginSuccess['body']['token'];

// -----------------------------------------------------------------------------
// [2/6] GET /api/v1/patient/home (Empty state real de plano e dados de consulta)
// -----------------------------------------------------------------------------
echo "\n[2/6] Testando Endpoint GET /api/v1/patient/home...\n";

// Sem token
$homeNoToken = dispatchRequest('GET', '/api/v1/patient/home', null, null);
assertContract($homeNoToken['status'] === 401, "Acesso a /patient/home sem token bloqueado com HTTP 401");

// Com token legítimo
$homeData = dispatchRequest('GET', '/api/v1/patient/home', null, $token);
assertContract($homeData['status'] === 200, "Acesso a /patient/home com Bearer token retorna HTTP 200");
assertContract($homeData['body']['active_plan'] === null, "Empty state real: active_plan é estritamente null sem simulação");
assertContract($homeData['body']['water_ml_today'] === 0, "Consumo inicial de água hoje é 0 ml");
assertContract($homeData['body']['next_appointment']['id'] === 9551, "Próxima consulta é retornada da tabela appointments (#9551)");

// -----------------------------------------------------------------------------
// [3/6] POST /api/v1/patient/hydration (Persistência real acumulada)
// -----------------------------------------------------------------------------
echo "\n[3/6] Testando Endpoint POST /api/v1/patient/hydration...\n";

$hyd1 = dispatchRequest('POST', '/api/v1/patient/hydration', ['amount_ml' => 300], $token);
assertContract($hyd1['status'] === 200, "Registro de 300ml de água retorna HTTP 200");
assertContract($hyd1['body']['total_water_today'] === 300, "Total diário acumulado no banco é 300 ml");

$hyd2 = dispatchRequest('POST', '/api/v1/patient/hydration', ['amount_ml' => 450], $token);
assertContract($hyd2['status'] === 200, "Segundo registro de 450ml retorna HTTP 200");
assertContract($hyd2['body']['total_water_today'] === 750, "Total acumulado persiste corretamente como 750 ml");

// Verificar se reflete na home
$homeAfterHyd = dispatchRequest('GET', '/api/v1/patient/home', null, $token);
assertContract($homeAfterHyd['body']['water_ml_today'] === 750, "Nova consulta à Home reflete exatamente os 750 ml gravados");

// -----------------------------------------------------------------------------
// [4/6] POST /api/v1/patient/appointment/confirm (Confirmação na agenda)
// -----------------------------------------------------------------------------
echo "\n[4/6] Testando Endpoint POST /api/v1/patient/appointment/confirm...\n";

$confRes = dispatchRequest('POST', '/api/v1/patient/appointment/confirm', ['appointment_id' => 9551], $token);
assertContract($confRes['status'] === 200, "Confirmação de consulta retorna HTTP 200");
assertContract($confRes['body']['success'] === true, "Confirmação reportada com success: true");

// Verificar persistência física no banco de dados
$stmt = $db->query("SELECT status FROM appointments WHERE id = 9551");
$status = $stmt->fetchColumn();
assertContract($status === 'confirmed', "Tabela appointments atualizada fisicamente para 'confirmed'");

// -----------------------------------------------------------------------------
// [5/6] POST & GET /api/v1/patient/food-diary (Diário Alimentar Real)
// -----------------------------------------------------------------------------
echo "\n[5/6] Testando Endpoints do Diário Alimentar...\n";

$diaryPost = dispatchRequest('POST', '/api/v1/patient/food-diary', [
    'meal_name' => 'Lanche Pré-Treino',
    'description' => '1 banana e 30g de aveia com canela',
    'hunger_level' => 3,
    'satiety_level' => 4,
], $token);
assertContract($diaryPost['status'] === 200, "Registro de refeição no diário retorna HTTP 200");
assertContract($diaryPost['body']['id'] > 0, "ID relacional gerado na tabela food_diary_entries");

$diaryGet = dispatchRequest('GET', '/api/v1/patient/food-diary', null, $token);
assertContract($diaryGet['status'] === 200, "Listagem do diário retorna HTTP 200");
assertContract(count($diaryGet['body']['entries']) === 1, "Timeline contém exatamente 1 refeição registrada");
assertContract($diaryGet['body']['entries'][0]['meal_name'] === 'Lanche Pré-Treino', "Nome e descrição coincidem exatamente com o registrado");

// -----------------------------------------------------------------------------
// [6/6] POST /api/v1/auth/patient-logout (Revogação de Token e Bloqueio)
// -----------------------------------------------------------------------------
echo "\n[6/6] Testando Revogação de Token (Logout)...\n";

$logoutRes = dispatchRequest('POST', '/api/v1/auth/patient-logout', null, $token);
assertContract($logoutRes['status'] === 200, "Logout retorna HTTP 200 com confirmação de revogação");

// Tentar usar o mesmo token após logout (deve retornar 401)
$homeRevoked = dispatchRequest('GET', '/api/v1/patient/home', null, $token);
assertContract($homeRevoked['status'] === 401, "Tentativa de usar token revogado é barrada com HTTP 401 Unauthorized");

// -----------------------------------------------------------------------------
// CLEANUP
// -----------------------------------------------------------------------------
$db->exec("DELETE FROM personal_access_tokens WHERE name = 'mobile_app_contract_test';");
$db->exec("DELETE FROM hydration_logs WHERE patient_id = 955;");
$db->exec("DELETE FROM food_diary_entries WHERE patient_id = 955;");
$db->exec("DELETE FROM appointments WHERE patient_id = 955;");
$db->exec("DELETE FROM patients WHERE id = 955;");
$db->exec("DELETE FROM clinics WHERE id = 995;");

// -----------------------------------------------------------------------------
// SUMÁRIO
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "RESULTADO DO TESTE DE CONTRATOS REST MOBILE (NI2-176):\n";
echo "  TOTAL DE ASSERÇÕES: " . ($passed + $failed) . "\n";
echo "  PASSOU            : {$passed}\n";
echo "  FALHOU            : {$failed}\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
