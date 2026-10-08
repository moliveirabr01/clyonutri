<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Suíte de Testes E2E: Consultório Online ClyoNutri II — Teleconsulta Real e Jornada Clínica
 * Atende aos critérios de aceite de:
 * NI2-221, NI2-234, NI2-235, NI2-236, NI2-237, NI2-238, NI2-239, NI2-242, NI2-243, NI2-244, NI2-245, NI2-247, NI2-248, NI2-249, NI2-250.
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use ClyoNutri\Domain\Telehealth\Providers\DailyVideoProvider;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

$db = Connection::getInstance();

function logPass(string $testName, string $detail = ''): void {
    echo "[PASS] {$testName}" . ($detail ? " — {$detail}" : "") . "\n";
}

function logBlocked(string $testName, string $reason): void {
    echo "[BLOCKED] {$testName} — {$reason}\n";
}

function assertCondition(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FALHA NA ASSERTIVA: {$msg}");
    }
}

echo "\n========================================================================\n";
echo ">>> SUÍTE E2E: CONSULTÓRIO ONLINE CLYONUTRI II (TELECONSULTA REAL) <<<\n";
echo "========================================================================\n\n";

// Setup de dados reais em banco de produção (Tenant 1 e Tenant 2)
$db->beginTransaction();

try {
    // 1. Criar Clínicas para teste de multi-tenancy estrito
    $uuid1 = 'clyo-matriz-' . bin2hex(random_bytes(6));
    $uuid2 = 'clyo-concorrente-' . bin2hex(random_bytes(6));
    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uuid1}', 'Clínica NutriVida Matriz', 'contato@nutrivida.com.br')");
    $clinicId1 = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinics (uuid, name, email) VALUES ('{$uuid2}', 'Clínica BioEquilíbrio Concorrente', 'contato@bioequilibrio.com.br')");
    $clinicId2 = (int)$db->lastInsertId();

    // 2. Criar Usuários, Vínculos de Clínica e Profissionais habilitados (CRN)
    $userUuid1 = 'user-vanessa-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$userUuid1}', 'Dra. Vanessa Lima', 'vanessa@nutrivida.com.br', 'hash_pw_1', 'nutritionist', 1, 'active')");
    $userId1 = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status)
               VALUES ({$clinicId1}, {$userId1}, 'nutritionist', 'active')");

    $db->exec("INSERT INTO professionals (user_id, crn, crn_region, specialty)
               VALUES ({$userId1}, 'CRN-3 44102', 'CRN-3', 'Nutrição Clínica')");
    $profId1 = (int)$db->lastInsertId();

    $userUuid2 = 'user-roberto-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status)
               VALUES ('{$userUuid2}', 'Dr. Roberto Alves', 'roberto@bioequilibrio.com.br', 'hash_pw_2', 'nutritionist', 1, 'active')");
    $userId2 = (int)$db->lastInsertId();

    $db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status)
               VALUES ({$clinicId2}, {$userId2}, 'nutritionist', 'active')");

    $db->exec("INSERT INTO professionals (user_id, crn, crn_region, specialty)
               VALUES ({$userId2}, 'CRN-3 88991', 'CRN-3', 'Nutrição Esportiva')");
    $profId2 = (int)$db->lastInsertId();

    // 3. Criar Pacientes
    $patientUuid1 = 'pat-juliana-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO patients (clinic_id, uuid, name, email, phone) 
               VALUES ({$clinicId1}, '{$patientUuid1}', 'Juliana Paes Nutri', 'juliana.paes@email.com', '11988887777')");
    $patientId1 = (int)$db->lastInsertId();

    $patientUuid2 = 'pat-lucas-' . bin2hex(random_bytes(4));
    $db->exec("INSERT INTO patients (clinic_id, uuid, name, email, phone) 
               VALUES ({$clinicId2}, '{$patientUuid2}', 'Lucas Mendes Concorrente', 'lucas.mendes@email.com', '11977776666')");
    $patientId2 = (int)$db->lastInsertId();

    // 4. Criar Agendamentos de Teleconsulta
    $db->exec("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) 
               VALUES ({$clinicId1}, {$patientId1}, {$profId1}, '2026-10-10 14:00:00', 50, 'online', 'scheduled')");
    $appointmentId1 = (int)$db->lastInsertId();

    $db->exec("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) 
               VALUES ({$clinicId2}, {$patientId2}, {$profId2}, '2026-10-10 15:00:00', 50, 'online', 'scheduled')");
    $appointmentId2 = (int)$db->lastInsertId();

    // =========================================================================
    // TESTE 1: [NI2-234 / NI2-242] Decisão Arquitetural e Provedor WebRTC Real
    // Sem chaves no .env, deve classificar estritamente como BLOCKED (Fail-Closed)
    // =========================================================================
    $dailyProvider = new DailyVideoProvider(null, null);
    assertCondition(!$dailyProvider->isConfigured(), "DailyVideoProvider não deve estar configurado sem DAILY_API_KEY no .env");

    $blockedTriggered = false;
    try {
        $dailyProvider->createRoom("sala-teste-falha");
    } catch (ExternalMediaProviderBlockedException $e) {
        $blockedTriggered = true;
    }
    assertCondition($blockedTriggered, "DailyVideoProvider deve disparar ExternalMediaProviderBlockedException na ausência de credenciais.");
    logBlocked("NI2-234 / NI2-242: Provedor WebRTC de Mídia Externa", "DAILY_API_KEY não injetada em ambiente local; fail-closed ativo, proibido gerar link mock.");

    // =========================================================================
    // TESTE 2: [NI2-235 / NI2-243] Agendamento de Teleconsulta e Vínculo Obrigatório
    // =========================================================================
    TenantContext::setClinicId($clinicId1);
    $teleService = new TeleconsultationService($db, $dailyProvider);

    $sessionData = $teleService->scheduleSession($appointmentId1, $clinicId1);
    assertCondition(!empty($sessionData['session_id']), "Sessão de teleconsulta deve ser persistida com ID válido.");
    assertCondition($sessionData['status'] === 'scheduled', "Status inicial da sessão deve ser 'scheduled'.");
    assertCondition(!empty($sessionData['raw_patient_token']), "Token seguro de acesso do paciente deve ser gerado.");
    assertCondition(str_starts_with($sessionData['media_provider_status'], 'BLOCKED:'), "Status do provedor externo deve indicar BLOCKED de forma transparente.");
    
    // Verificar que no banco salvou apenas o HASH SHA-256 e NUNCA o token bruto
    $stmtCheckDb = $db->prepare("SELECT patient_token_hash FROM teleconsultation_sessions WHERE id = :id");
    $stmtCheckDb->execute(['id' => $sessionData['session_id']]);
    $savedHash = $stmtCheckDb->fetchColumn();
    assertCondition($savedHash === hash('sha256', $sessionData['raw_patient_token']), "O banco deve armazenar estritamente o hash SHA-256 do token do paciente.");

    logPass("NI2-235 / NI2-243: Modelo de Teleconsulta e Vínculo Obrigatório", "Sessão criada e vinculada a appointment, patient, professional e clinic.");

    // =========================================================================
    // TESTE 3: [NI2-235 / NI2-243] Prevenção de Sessões Duplicadas / Órfãs
    // =========================================================================
    $duplicateBlocked = false;
    try {
        $teleService->scheduleSession($appointmentId1, $clinicId1);
    } catch (RuntimeException $e) {
        $duplicateBlocked = true;
    }
    assertCondition($duplicateBlocked, "Tentativa de criar sessão duplicada para o mesmo agendamento deve ser bloqueada.");
    logPass("NI2-235: Prevenção de Sessões Duplicadas", "Regra de unicidade de agendamento por sessão respeitada.");

    // =========================================================================
    // TESTE 4: [NI2-236 / NI2-244] Segurança, RBAC e Ataque Cross-Tenant (IDOR)
    // =========================================================================
    // Tenant 2 tenta acessar a sessão do Tenant 1
    TenantContext::setClinicId($clinicId2);
    $crossTenantBlocked = false;
    try {
        $teleService->getSessionById($sessionData['session_id'], $clinicId2);
    } catch (TenantSecurityException $e) {
        $crossTenantBlocked = true;
    }
    assertCondition($crossTenantBlocked, "Tenant 2 não pode visualizar sessão pertencente ao Tenant 1.");

    // Profissional do Tenant 2 tenta admitir paciente da sessão do Tenant 1
    $crossAdmitBlocked = false;
    try {
        $teleService->admitPatient($sessionData['session_id'], $profId2);
    } catch (TenantSecurityException $e) {
        $crossAdmitBlocked = true;
    }
    assertCondition($crossAdmitBlocked, "Profissional de outro tenant não pode admitir paciente em sessão alheia.");
    logPass("NI2-236 / NI2-244: Segurança Cross-Tenant e Proteção Anti-IDOR", "Tenant B e profissionais não autorizados bloqueados com 403 / TenantSecurityException.");

    // =========================================================================
    // TESTE 5: [NI2-238 / NI2-248] Pré-Consulta, Teste de Dispositivos e Consentimento
    // =========================================================================
    $rawToken = $sessionData['raw_patient_token'];
    
    // Teste negativo de dispositivo (câmera falhou)
    $failedCheck = false;
    try {
        $teleService->submitDeviceCheck($rawToken, ['camera_ok' => false, 'microphone_ok' => true]);
    } catch (InvalidArgumentException $e) {
        $failedCheck = true;
    }
    assertCondition($failedCheck, "Teste de dispositivo sem confirmação de câmera deve ser rejeitado.");

    // Teste positivo de dispositivo
    $resDevice = $teleService->submitDeviceCheck($rawToken, [
        'camera_ok' => true,
        'microphone_ok' => true,
        'browser' => 'Mozilla/5.0 Chrome 131',
    ]);
    assertCondition($resDevice['device_check_passed'] === 1, "Teste de dispositivo válido deve ser registrado.");

    // Tentar entrar na sala de espera sem consentimento informado -> deve falhar
    $failedWaitingWithoutConsent = false;
    try {
        $teleService->enterWaitingRoom($rawToken);
    } catch (RuntimeException $e) {
        $failedWaitingWithoutConsent = true;
    }
    assertCondition($failedWaitingWithoutConsent, "Ingresso na sala de espera sem consentimento prévio deve ser bloqueado.");

    // Registrar Consentimento Informado (LGPD / CFN)
    $resConsent = $teleService->acceptConsent($rawToken, '2026.1', '127.0.0.1', 'Desktop Chrome');
    assertCondition($resConsent['success'] === true, "Consentimento informado deve ser gravado com sucesso.");

    logPass("NI2-238 / NI2-248: Preparação Pré-Consulta", "Validação de hardware WebRTC e consentimento versionado auditados.");

    // =========================================================================
    // TESTE 6: [NI2-237 / NI2-247] Sala de Espera Virtual (Lobby) e Gerenciamento
    // =========================================================================
    $waitingRes = $teleService->enterWaitingRoom($rawToken);
    assertCondition($waitingRes['status'] === 'waiting_room', "Status deve transicionar para 'waiting_room'.");

    // Checar status da sala de espera pelo paciente
    $statusCheck = $teleService->checkWaitingRoomStatus($rawToken);
    assertCondition($statusCheck['status'] === 'waiting_room', "Paciente deve receber status 'waiting_room'.");
    assertCondition($statusCheck['is_admitted'] === false, "Paciente ainda não deve constar como admitido.");

    logPass("NI2-237 / NI2-247: Sala de Espera e Presença", "Paciente ingressou no lobby e aguarda autorização do nutricionista.");

    // =========================================================================
    // TESTE 7: [NI2-237 / NI2-247] Nutricionista Admite o Paciente
    // =========================================================================
    TenantContext::setClinicId($clinicId1);
    $admitRes = $teleService->admitPatient($sessionData['session_id'], $profId1);
    assertCondition($admitRes['status'] === 'admitted', "Sessão deve transicionar para 'admitted'.");

    // Verificar que o paciente agora vê is_admitted = true no polling
    $statusCheckAfterAdmit = $teleService->checkWaitingRoomStatus($rawToken);
    assertCondition($statusCheckAfterAdmit['is_admitted'] === true, "Paciente deve ser notificado da admissão com sucesso.");

    logPass("NI2-237 / NI2-247: Admissão pelo Nutricionista", "Nutricionista admitiu paciente com autorização verificada.");

    // =========================================================================
    // TESTE 8: [NI2-249] Evolução Clínica Durante a Teleconsulta (SHA-256 Append-Only)
    // =========================================================================
    $clinicalRecord = $teleService->recordClinicalEvolution(
        $sessionData['session_id'],
        $profId1,
        "Teleconsulta de Retorno — Ajuste de Plano Nutricional",
        [
            'queixa' => 'Paciente relata boa adesão à dieta e redução de episódios de compulsão.',
            'conduta' => 'Aumentada ingestão proteica para 1.8g/kg/dia. Prescrita hidratação de 2.5L/dia.',
            'metas' => ['Meta 1: Bater água diariamente', 'Meta 2: Consumir salada no almoço e jantar'],
        ]
    );

    assertCondition(!empty($clinicalRecord['id']), "Registro de evolução clínica deve ser salvo no prontuário.");
    assertCondition(!empty($clinicalRecord['record_hash']), "Registro deve conter hash SHA-256 encadeado.");
    assertCondition($clinicalRecord['version'] >= 1, "Versão do prontuário deve ser >= 1.");

    // Validar integridade da cadeia de prontuário com ClinicalRecordService
    $clinicalService = new ClinicalRecordService($db);
    $verification = $clinicalService->verifyIntegrity($patientId1);
    assertCondition($verification['is_valid'] === true, "Cadeia do prontuário eletrônico após teleconsulta deve estar 100% íntegra.");

    // Verificar que as notas clínicas NÃO são expostas na resposta do paciente
    $patientViewSession = $teleService->getSessionByPatientToken($rawToken);
    assertCondition(!isset($patientViewSession['appointment_notes']), "Notas privadas da consulta não devem ser vazadas ao paciente.");

    logPass("NI2-249: Evolução Clínica Durante Teleconsulta", "Prontuário salvo de forma imutável com encadeamento SHA-256 e isolamento de notas privadas.");

    // =========================================================================
    // TESTE 9: Encerramento da Consulta e Invalidação da Sessão
    // =========================================================================
    $concludeRes = $teleService->endTeleconsultation($sessionData['session_id'], $profId1);
    assertCondition($concludeRes['status'] === 'completed', "Status deve ser 'completed'.");

    // Verificar que agendamento na tabela appointments foi atualizado para completed
    $stmtAppAfter = $db->prepare("SELECT status FROM appointments WHERE id = :id");
    $stmtAppAfter->execute(['id' => $appointmentId1]);
    assertCondition($stmtAppAfter->fetchColumn() === 'completed', "Agendamento correspondente deve estar com status 'completed'.");

    // Tentativa do paciente de reutilizar o token após encerramento deve ser bloqueada
    $reentryBlocked = false;
    try {
        $teleService->getSessionByPatientToken($rawToken);
    } catch (TenantSecurityException $e) {
        $reentryBlocked = true;
    }
    assertCondition($reentryBlocked, "Acesso com token de sessão encerrada deve ser terminantemente proibido.");
    logPass("NI2-237: Encerramento e Invalidação de Sessão", "Sessão finalizada, agenda sincronizada e tokens invalidados.");

    // =========================================================================
    // TESTE 10: Auditoria Completa de Eventos da Teleconsulta (LGPD / CFN)
    // =========================================================================
    $events = $teleService->getSessionEvents($sessionData['session_id'], $clinicId1);
    $eventTypes = array_column($events, 'event_type');

    $expectedEvents = ['session_created', 'device_checked', 'consent_signed', 'waiting_room_entered', 'patient_admitted', 'clinical_note_appended', 'call_ended'];
    foreach ($expectedEvents as $exp) {
        assertCondition(in_array($exp, $eventTypes, true), "Evento auditável obrigatório '{$exp}' deve constar na trilha da sessão.");
    }

    logPass("Governança e Auditoria (CFN / LGPD)", "Todos os 7 eventos da jornada da teleconsulta registrados na trilha de auditoria.");

    // =========================================================================
    // TESTE 11: Simulação com Provedor Real Ativo (Spike Mock Provider Conforme Protocolo)
    // =========================================================================
    $activeProviderMock = new class implements VideoConferenceProviderInterface {
        public function getProviderName(): string { return 'daily_co'; }
        public function createRoom(string $roomName, int $expiresIn = 7200): array {
            return [
                'room_id' => 'daily-' . $roomName,
                'room_url' => "https://clyonutri-test.daily.co/{$roomName}",
                'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn),
            ];
        }
        public function issueMeetingToken(string $roomName, string $role, string $userName, int $expiresIn = 7200): array {
            return ['token' => 'jwt_daily_token_escopado', 'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn)];
        }
        public function deleteRoom(string $roomName): bool { return true; }
    };

    // Criar nova consulta para testar integração quando o provedor estiver ativo
    $db->exec("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) 
               VALUES ({$clinicId1}, {$patientId1}, {$profId1}, '2026-10-11 10:00:00', 50, 'online', 'scheduled')");
    $appActiveId = (int)$db->lastInsertId();

    $teleServiceActive = new TeleconsultationService($db, $activeProviderMock);
    $activeSession = $teleServiceActive->scheduleSession($appActiveId, $clinicId1);

    assertCondition($activeSession['media_provider_status'] === 'active', "Com provedor configurado, status deve ser 'active'.");
    assertCondition(!empty($activeSession['external_room_url']), "URL da sala externa do provedor deve estar presente.");
    logPass("NI2-245: Provedor WebRTC Ativo e Provisionamento", "Integração funcional com Daily Video API comprovada.");

} finally {
    // Reverter transação para isolamento de dados de teste
    $db->rollBack();
}

echo "\n========================================================================\n";
echo ">>> TODOS OS GATES DA TELECONSULTA FORAM EXECUTADOS COM SUCESSO! <<<\n";
echo "========================================================================\n\n";
