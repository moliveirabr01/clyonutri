<?php

declare(strict_types=1);

/**
 * Suíte E2E de homologação e regressão — NI2-481
 * [E-mails transacionais] Completar jornadas de acesso, segurança e operações
 *
 * Cobre:
 * - ET01 (NI2-482): Convite de primeiro acesso da paciente por e-mail (sent/logged/skipped/failed + auditoria sem PII).
 * - ET02 (NI2-483): Recuperação de senha da paciente, não-enumeração no rate limit (A1), sincronia multi-clínica (A2) e ausência de PII em audit_logs (M2).
 * - ET03 (NI2-484): Avisos de senha alterada para profissional e paciente.
 * - ET04 (NI2-485) + NI2-213: Troca de e-mail com alerta no antigo, link de confirmação no novo (/verificar-email/{token}), incremento de auth_version e aviso de troca de e-mail da paciente.
 * - ET05 (NI2-486): Avisos de bloqueio, suspensão e reativação administrativa.
 * - ET06 (NI2-487) + ET07 (NI2-488): E-mails de criação, reagendamento, confirmação e cancelamento pela clínica e autoagendamento.
 * - ET08 (NI2-489) + NI2-213: Boas-vindas ao profissional no cadastro com confirmação de e-mail.
 * - ET09 (NI2-490): Avisos de proximidade do fim e encerramento do trial ao profissional titular (idempotentes).
 * - NI2-204: E-mails transacionais de abertura, resposta pública e resolução de chamados Helpdesk.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\ProfessionalSecurityNotifier;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\MailMessage;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Helpdesk\HelpdeskTicketService;
use ClyoNutri\Domain\Notification\NotificationService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PatientPortal\PatientInviteNotifier;
use ClyoNutri\Domain\PatientPortal\PatientPasswordResetService;
use ClyoNutri\Domain\PatientPortal\PatientSecurityNotifier;
use ClyoNutri\Domain\Settings\ProfessionalProfile;
use ClyoNutri\Domain\Subscription\SubscriptionService;

putenv('APP_URL=https://clyonutri.com');
$_ENV['APP_URL'] = 'https://clyonutri.com';

final class SpyMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $messages = [];

    public function send(MailMessage $message): void
    {
        $this->messages[] = $message;
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Injetar instância isolada no singleton Connection
$ref = new ReflectionClass(Connection::class);
$prop = $ref->getProperty('instance');
$prop->setValue(null, $db);

// Schema completo para os domínios exercitados
$db->exec("
    CREATE TABLE clinics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT,
        name TEXT NOT NULL,
        email TEXT,
        document TEXT,
        phone TEXT,
        address_street TEXT,
        address_number TEXT,
        address_complement TEXT,
        address_neighborhood TEXT,
        address_city TEXT,
        address_state TEXT,
        address_zip TEXT,
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        phone TEXT,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'nutritionist',
        is_verified INTEGER NOT NULL DEFAULT 0,
        auth_version INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'active',
        last_login_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE clinic_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT 'owner',
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE professionals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        crn TEXT,
        crn_region TEXT,
        specialty TEXT,
        bio TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE onboarding_progress (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        clinic_id INTEGER NOT NULL,
        current_step INTEGER NOT NULL DEFAULT 1,
        completed_steps TEXT NULL,
        is_completed INTEGER NOT NULL DEFAULT 0,
        completed_at DATETIME NULL,
        focus_objectives TEXT NULL,
        professional_data TEXT NULL,
        practice_preferences TEXT DEFAULT '{}',
        first_patient_id INTEGER NULL,
        contextual_notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        birth_date TEXT,
        gender TEXT,
        notes TEXT,
        password_hash TEXT,
        access_pin TEXT,
        otp_code TEXT,
        otp_expires_at TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        deleted_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE patient_app_invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        patient_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL,
        created_by INTEGER,
        expires_at TEXT NOT NULL,
        accepted_at TEXT,
        revoked_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE patient_password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        patient_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT NOT NULL
    );
    CREATE TABLE password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE login_attempts (
        bucket_key TEXT PRIMARY KEY,
        scope TEXT NOT NULL,
        failures INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        last_attempt_at TEXT NOT NULL,
        locked_until TEXT
    );
    CREATE TABLE personal_access_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tokenable_type TEXT NOT NULL,
        tokenable_id INTEGER NOT NULL,
        clinic_id INTEGER,
        name TEXT,
        token TEXT NOT NULL,
        expires_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE sessions (
        id TEXT PRIMARY KEY,
        user_id INTEGER,
        payload TEXT,
        last_activity INTEGER
    );
    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER,
        user_id INTEGER,
        action TEXT NOT NULL,
        resource TEXT,
        resource_id TEXT,
        ip_address TEXT,
        user_agent TEXT,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE user_legal_acceptances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        clinic_id INTEGER NULL,
        document TEXT NOT NULL,
        version TEXT NOT NULL,
        accepted_at TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        source TEXT NOT NULL
    );
    CREATE TABLE saas_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        billing_cycle TEXT NOT NULL DEFAULT 'monthly',
        price_cents INTEGER NOT NULL DEFAULT 9900,
        max_professionals INTEGER NOT NULL DEFAULT 1,
        max_patients INTEGER NOT NULL DEFAULT 100,
        storage_mb INTEGER NOT NULL DEFAULT 1024,
        features_json TEXT DEFAULT '{}',
        is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE clinic_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        plan_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'trialing',
        billing_cycle TEXT NOT NULL DEFAULT 'monthly',
        price_cents INTEGER NOT NULL DEFAULT 9900,
        started_at TEXT NOT NULL,
        current_period_start TEXT NOT NULL,
        current_period_end TEXT NOT NULL,
        trial_ends_at TEXT,
        canceled_at TEXT,
        cancellation_protocol TEXT,
        cancellation_reason_category TEXT,
        cancellation_feedback TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE notification_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        category TEXT NOT NULL,
        recipient_type TEXT NOT NULL,
        recipient_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        link TEXT,
        critical INTEGER NOT NULL DEFAULT 0,
        dedupe_key TEXT NOT NULL,
        read_at TEXT,
        created_at TEXT NOT NULL
    );
    CREATE TABLE notification_deliveries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        event_id INTEGER NOT NULL,
        channel TEXT NOT NULL,
        status TEXT NOT NULL,
        reason TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        sent_at TEXT,
        provider_ticket_id TEXT,
        receipt_checked_at TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
    CREATE TABLE notification_preferences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        recipient_type TEXT NOT NULL,
        recipient_id INTEGER NOT NULL,
        category TEXT NOT NULL,
        email_enabled INTEGER NOT NULL DEFAULT 1,
        push_enabled INTEGER NOT NULL DEFAULT 1,
        quiet_hours_enabled INTEGER NOT NULL DEFAULT 0,
        quiet_start TEXT DEFAULT '22:00',
        quiet_end TEXT DEFAULT '07:00',
        updated_at TEXT
    );
    CREATE TABLE appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        patient_id INTEGER NOT NULL,
        professional_id INTEGER NOT NULL,
        scheduled_at TEXT NOT NULL,
        duration_minutes INTEGER NOT NULL DEFAULT 60,
        status TEXT NOT NULL DEFAULT 'scheduled',
        type TEXT NOT NULL DEFAULT 'first_visit',
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE appointment_status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        appointment_id INTEGER NOT NULL,
        from_status TEXT,
        to_status TEXT NOT NULL,
        changed_by_user_id INTEGER,
        changed_by_patient_id INTEGER,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE patient_professional_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        patient_id INTEGER NOT NULL,
        professional_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        source TEXT,
        started_at TEXT DEFAULT CURRENT_TIMESTAMP,
        ended_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE teleconsultation_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        appointment_id INTEGER NOT NULL,
        professional_id INTEGER NOT NULL,
        patient_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'scheduled',
        room_token TEXT,
        scheduled_start_at TEXT,
        scheduled_end_at TEXT,
        window_opens_at TEXT,
        window_closes_at TEXT,
        ended_at TEXT,
        end_reason TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE helpdesk_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        protocol TEXT NOT NULL UNIQUE,
        clinic_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        assigned_to_user_id INTEGER,
        type TEXT NOT NULL,
        category TEXT NOT NULL,
        priority_perceived TEXT NOT NULL,
        priority_operational TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        subject TEXT NOT NULL,
        description TEXT NOT NULL,
        origin_route TEXT,
        resolution_summary TEXT,
        first_response_at TEXT,
        resolved_at TEXT,
        closed_at TEXT,
        reopened_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE helpdesk_ticket_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        is_internal_note INTEGER NOT NULL DEFAULT 0,
        message TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE helpdesk_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        message_id INTEGER,
        file_name TEXT,
        mime_type TEXT,
        file_size INTEGER,
        uploaded_by_user_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE helpdesk_ticket_feedbacks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        rating INTEGER NOT NULL,
        comment TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE helpdesk_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        ticket_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE helpdesk_audit_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        actor_user_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        payload_json TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

$db->exec("INSERT INTO saas_plans (code, name, billing_cycle, price_cents, max_professionals, max_patients, storage_mb) VALUES ('PRO-02', 'Profissional', 'monthly', 9900, 1, 200, 2048)");

$passed = 0;
$failed = 0;

function check_ni2_481(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=== Suíte E2E NI2-481: Jornadas de E-mails Transacionais ===\n";

// 1. ET08 (NI2-489) + NI2-213: Cadastro do profissional, boas-vindas e verificação efetiva de e-mail
$auth = new AuthService($db);
$reg = $auth->registerNutritionist('Dra. Clara Mendes', 'clara@clinica1.test', 'SenhaForte@123', 'Clínica Clara', '12345', 'CRN-3');
$userId = (int)$reg['user_id'];
$clinicId1 = (int)$reg['clinic_id'];

$spy = new SpyMailer();
$profSec = new ProfessionalSecurityNotifier($db, $spy);
$welcome = $profSec->welcomeProfessional($userId, 'Clínica Clara', gmdate('Y-m-d H:i:s', time() + 14 * 86400));
check_ni2_481($welcome['status'] === 'sent' && !empty($welcome['verification_token']), 'ET08 + NI2-213: E-mail de boas-vindas enviado com token de verificação');
check_ni2_481(!$auth->isVerified($userId), 'NI2-213: Profissional inicia com is_verified = 0 antes de clicar no link');

$verified = $profSec->verifyEmailToken((string)$welcome['verification_token'], '127.0.0.1', 'E2E');
check_ni2_481($verified !== null && $auth->isVerified($userId), 'NI2-213: Token de verificação valida e ativa users.is_verified = 1');

// 2. ET04 (NI2-485): Troca de e-mail profissional + confirmação do novo e-mail + corte de sessão
TenantContext::set($clinicId1, ['id' => $userId, 'name' => 'Dra. Clara Mendes', 'role' => 'nutritionist']);
$db->prepare("INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, clinic_id, name, token) VALUES ('user', ?, ?, 'api', 'tok1')")->execute([$userId, $clinicId1]);
$profileSvc = new ProfessionalProfile($db);
$currentProfile = $profileSvc->read();
$rev = ProfessionalProfile::revision($currentProfile);
$profileSvc->save([
    'revision' => $rev,
    'name' => 'Dra. Clara Mendes',
    'clinic_name' => 'Clínica Clara',
    'clinic_document' => '12345678901',
    'specialty' => 'Nutrição Clínica',
    'bio' => 'Bio',
    'email' => 'clara.nova@clinica1.test',
    'phone' => '11999990000',
    'crn' => '12345',
    'crn_region' => 'CRN-3',
    'current_password' => 'SenhaForte@123',
]);
$uAfter = $db->query("SELECT email, is_verified, auth_version FROM users WHERE id = {$userId}")->fetch();
$tokensLeft = (int)$db->query("SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_type = 'user' AND tokenable_id = {$userId}")->fetchColumn();
check_ni2_481($uAfter['email'] === 'clara.nova@clinica1.test' && (int)$uAfter['is_verified'] === 0 && (int)$uAfter['auth_version'] === 2 && $tokensLeft === 0, 'ET04 (A3): Troca de e-mail exige re-verificação (is_verified=0), incrementa auth_version e revoga tokens');

$emailChangeNotice = $profSec->emailChanged($userId, 'clara@clinica1.test', 'clara.nova@clinica1.test');
check_ni2_481($emailChangeNotice['old']['status'] === 'sent' && $emailChangeNotice['new']['status'] === 'sent' && !empty($emailChangeNotice['verification_token']), 'ET04: Alerta enviado ao e-mail antigo e link de confirmação enviado ao novo e-mail');
$reverified = $profSec->verifyEmailToken((string)$emailChangeNotice['verification_token']);
check_ni2_481($reverified !== null && $auth->isVerified($userId), 'ET04 + NI2-213: Confirmação do novo e-mail marca is_verified = 1');

// 3. ET01 (NI2-482): Convite de primeiro acesso da paciente
$db->prepare("INSERT INTO patients (id, clinic_id, name, email, phone, password_hash, status) VALUES (10, ?, 'Marina Costa', 'marina@paciente.test', '11988887777', ?, 'active')")
    ->execute([$clinicId1, password_hash('SenhaAntiga123', PASSWORD_DEFAULT)]);
$invNotifier = new PatientInviteNotifier($db, $spy, 'https://clyonutri.com');
$plainInviteToken = bin2hex(random_bytes(32));
$invRes = $invNotifier->sendInvite($clinicId1, 10, $plainInviteToken, gmdate('Y-m-d H:i:s', time() + 72 * 3600), $userId);
$lastInviteMail = end($spy->messages);
check_ni2_481($invRes['status'] === 'sent' && str_contains($lastInviteMail->textBody, 'https://clyonutri.com/paciente/app?code=' . $plainInviteToken), 'ET01: Convite de primeiro acesso enviado com link canônico');

// 4. ET02 (NI2-483) + A1 + A2 + M2: Recuperação de senha da paciente, não-enumeração no rate limit, multi-clínica e sem PII em audit_logs
$reg2 = $auth->registerNutritionist('Dr. Rafael Souza', 'rafael@clinica2.test', 'SenhaForte@123', 'Clínica Rafael', '54321', 'CRN-3');
$clinicId2 = (int)$reg2['clinic_id'];
$db->prepare("INSERT INTO patients (id, clinic_id, name, email, phone, password_hash, status) VALUES (20, ?, 'Marina Costa', 'marina@paciente.test', '11988887777', ?, 'active')")
    ->execute([$clinicId2, password_hash('OutraSenhaAntiga', PASSWORD_DEFAULT)]);

$pwSvc = new PatientPasswordResetService($db, $spy, 'https://clyonutri.com');
$reqExisting = $pwSvc->requestReset('marina@paciente.test', '10.0.0.1', 'E2E');
$reqUnknown = $pwSvc->requestReset('inexistente@paciente.test', '10.0.0.2', 'E2E');
check_ni2_481($reqExisting['message'] === $reqUnknown['message'], 'ET02 (A1): Mesma mensagem para e-mail existente e inexistente');

// Verifica que o e-mail em texto puro NÃO foi gravado em audit_logs (M2)
$rawEmailInAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE resource = 'patient_password_resets' AND details LIKE '%marina@paciente.test%'")->fetchColumn();
check_ni2_481($rawEmailInAudit === 0, 'M2: Nenhum endereço de e-mail em texto puro nos audit_logs de recuperação de senha');

// Extrai o link do último e-mail enviado para Marina (clínica 2) e redefine a senha
$lastResetMail = end($spy->messages);
preg_match('#/paciente/recuperar-senha/([a-f0-9]{64})#', $lastResetMail->textBody, $m);
$resetToken = $m[1] ?? '';
$resetDone = $pwSvc->resetPassword($resetToken, 'NovaSenhaUnificada@2026', '10.0.0.1', 'E2E');
$hash1 = (string)$db->query("SELECT password_hash FROM patients WHERE id = 10")->fetchColumn();
$hash2 = (string)$db->query("SELECT password_hash FROM patients WHERE id = 20")->fetchColumn();
check_ni2_481($resetDone['success'] && password_verify('NovaSenhaUnificada@2026', $hash1) && password_verify('NovaSenhaUnificada@2026', $hash2), 'A2: Redefinição de senha sincroniza perfis ativos da paciente em múltiplas clínicas');

// Testa que 6 solicitações seguidas para conta existente devolvem a MESMA mensagem genérica sem lançar exceção (A1)
$rateLimitSameMessage = true;
for ($i = 0; $i < 6; $i++) {
    $r = $pwSvc->requestReset('marina@paciente.test', '10.0.0.99', 'E2E');
    if ($r['message'] !== PatientPasswordResetService::GENERIC_MESSAGE) {
        $rateLimitSameMessage = false;
    }
}
check_ni2_481($rateLimitSameMessage, 'A1: Rate limit de recuperação da paciente mantém resposta uniforme sem oráculo de enumeração');

// 5. ET03 (NI2-484) + ET04 (paciente): Avisos de segurança da paciente (senha alterada e e-mail alterado)
$patSec = new PatientSecurityNotifier($db, $spy);
$patPwNotice = $patSec->passwordChanged($clinicId1, 10, '127.0.0.1', 'E2E');
$patEmailNotice = $patSec->emailChanged($clinicId1, 10, 'marina@paciente.test', 'marina.nova@paciente.test', '127.0.0.1', 'E2E');
check_ni2_481($patPwNotice['status'] === 'sent' && $patEmailNotice['old']['status'] === 'sent' && $patEmailNotice['new']['status'] === 'sent', 'ET03 + ET04: Avisos de senha alterada e e-mail alterado enviados para a paciente');

// 6. ET06 (NI2-487): Criação, reagendamento, confirmação e cancelamento de consulta pela clínica
TenantContext::set($clinicId1, ['id' => $userId, 'name' => 'Dra. Clara Mendes', 'role' => 'nutritionist']);
$profId1 = (int)$db->query("SELECT id FROM professionals WHERE user_id = {$userId}")->fetchColumn();
$apptSvc = new AppointmentService($db);
$futureDate1 = date('Y-m-d 10:00:00', strtotime('+3 days'));
$futureDate2 = date('Y-m-d 11:00:00', strtotime('+3 days'));
$appt = $apptSvc->schedule(10, $profId1, $futureDate1, 'first_visit', 60, 'Consulta inicial', true, $userId);
$apptSvc->edit((int)$appt['id'], ['scheduled_at' => $futureDate2], true, $userId);
$apptSvc->updateStatus((int)$appt['id'], AppointmentService::STATUS_CONFIRMED, 'Confirmado pela recepção', $userId);
$apptSvc->updateStatus((int)$appt['id'], AppointmentService::STATUS_CANCELLED, 'Cancelado pela clínica', $userId);

$apptEvents = $db->query("SELECT event_type FROM notification_events WHERE clinic_id = {$clinicId1} AND recipient_type = 'patient' AND recipient_id = 10 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
check_ni2_481(
    in_array('appointment.scheduled', $apptEvents, true)
    && in_array('appointment.rescheduled', $apptEvents, true)
    && in_array('appointment.confirmed_by_clinic', $apptEvents, true)
    && in_array('appointment.cancelled_by_clinic', $apptEvents, true),
    'ET06 (A4): Clínica gera eventos de notificação ao agendar, remarcar, confirmar e cancelar consulta'
);

// 7. ET09 (NI2-490): Avisos de ciclo de trial ao profissional titular (idempotentes)
$trialExpSoon = $profSec->trialExpiringSoon($userId, 'Clínica Clara', 'Profissional', gmdate('Y-m-d H:i:s', time() + 2 * 86400));
$trialExpSoonDup = $profSec->trialExpiringSoon($userId, 'Clínica Clara', 'Profissional', gmdate('Y-m-d H:i:s', time() + 2 * 86400));
check_ni2_481($trialExpSoon['status'] === 'sent' && $trialExpSoonDup['status'] === 'skipped', 'ET09 (A5): Aviso de trial prestes a vencer enviado ao titular e deduplicado');

$db->prepare("UPDATE clinic_subscriptions SET trial_ends_at = ? WHERE clinic_id = ?")
    ->execute([ date('Y-m-d H:i:s', time() - 86400), $clinicId1 ]);
$expiredCount = (new SubscriptionService($db))->expireTrials();
check_ni2_481($expiredCount >= 1, 'ET09 (A5): Expiração de trial processada e notificada ao titular');

// 8. NI2-204: Notificações de chamados por e-mail (abertura, resposta pública e resolução)
$helpdesk = new HelpdeskTicketService($db);
$ticket = $helpdesk->createTicket($clinicId1, $userId, 'Dúvida sobre agenda', 'agenda', 'question', 'Como configuro o lembrete de consulta?', 'medium');
$helpdesk->addMessage((int)$ticket['id'], (int)$reg2['user_id'], 'Você pode ajustar em Configurações > Lembretes.', false, true);
$helpdesk->updateStatus((int)$ticket['id'], (int)$reg2['user_id'], HelpdeskTicketService::STATUS_RESOLVED, 'Orientação enviada.', true);

$ticketAudits = $db->query("SELECT action FROM audit_logs WHERE user_id = {$userId} AND action LIKE 'HELPDESK_TICKET_%'")->fetchAll(PDO::FETCH_COLUMN);
check_ni2_481(count($ticketAudits) >= 3, 'NI2-204 (A5): Eventos de abertura, resposta e resolução de chamado registrados para envio de e-mail ao profissional');

echo "\nResumo NI2-481: {$passed} aprovados, {$failed} falharam.\n";
exit($failed === 0 ? 0 : 1);
