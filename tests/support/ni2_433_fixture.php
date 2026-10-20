<?php
// Fixture do teste real do autoagendamento (NI2-433). Só roda em banco isolado:
// SQLite em /tmp/ni2-433-* ou MySQL com banco ni2_433_*.
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Appointment\AppointmentService;

$conn = getenv('DB_CONNECTION') ?: 'sqlite';
$dbName = getenv('DB_DATABASE') ?: '';
if (!($conn === 'sqlite' && str_starts_with($dbName, '/tmp/ni2-433-')) && !($conn === 'mysql' && str_starts_with($dbName, 'ni2_433_'))) {
    throw new RuntimeException('Isolated test database required');
}
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

// Disponibilidade real configurada como em Configurações: seg–sex 08–18, pausa 12–13, consulta de 60 min.
$weekly = [];
foreach (['seg', 'ter', 'qua', 'qui', 'sex'] as $d) {
    $weekly[$d] = ['enabled' => true, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'];
}
foreach (['sab', 'dom'] as $d) {
    $weekly[$d] = ['enabled' => false, 'start' => '', 'end' => '', 'break_start' => '', 'break_end' => ''];
}
$prefs = json_encode(['weekly_availability' => $weekly, 'default_duration_minutes' => 60], JSON_UNESCAPED_UNICODE);

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $hash = password_hash('Agenda@2026', PASSWORD_DEFAULT);
    // Clínica 1 (A): dona (prof 1) + nutricionista sem gestão (prof 2). Clínica 2 (B): dona (prof 3).
    // Clínica 3 (C): autoagendamento nunca configurado (prof 4).
    $people = [
        [1, 1, 'owner', 'Dra. Ana Agenda', 'dona.a@example.test'],
        [2, 1, 'nutritionist', 'Dr. Bruno Equipe', 'equipe.a@example.test'],
        [3, 2, 'owner', 'Dra. Carla Outra', 'dona.b@example.test'],
        [4, 3, 'owner', 'Dr. Diego Desligado', 'dona.c@example.test'],
    ];
    foreach ([1 => 'Clínica Agenda A', 2 => 'Clínica Outra B', 3 => 'Clínica Desligada C'] as $id => $name) {
        $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(?,?,?,'active')")->execute([$id, "ag-c$id", $name]);
    }
    foreach ($people as [$id, $clinic, $role, $name, $email]) {
        $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(?,?,?,?,?,'nutritionist','active')")->execute([$id, "ag-u$id", $name, $email, $hash]);
        $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(?,?,?,'active')")->execute([$clinic, $id, $role]);
        $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(?,?,'CRN-T','CRN-3')")->execute([$id, $id]);
        $db->prepare("INSERT INTO onboarding_progress(clinic_id,user_id,current_step,practice_preferences,is_completed,created_at,updated_at) VALUES(?,?,3,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$clinic, $id, $prefs]);
    }
    // Clínica B já usa o autoagendamento (para os testes de isolamento entre clínicas).
    $db->prepare("INSERT INTO booking_settings(clinic_id,is_enabled,min_advance_hours,max_future_days,hold_ttl_minutes,cancel_min_advance_hours,reschedule_min_advance_hours,require_cpf,allow_telehealth,allow_presential) VALUES(2,1,2,60,15,4,4,0,1,1)")->execute();
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,phone,status,birth_date) VALUES(1,1,'ag-p1','Ana Lima','ana@paciente.test','11987654321','active','1990-05-10')")->execute();
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,status,birth_date) VALUES(2,2,'ag-p2','Paciente da Clínica B','b@paciente.test','active','1985-01-01')")->execute();
    echo json_encode(['ok' => true]);
} elseif ($mode === 'patient_token') {
    // Sessão do app do paciente (mesmo formato do primeiro acesso: só o hash fica no banco).
    $patientId = (int)($argv[2] ?? 0);
    $clinic = (int)$db->query('SELECT clinic_id FROM patients WHERE id = ' . $patientId)->fetchColumn();
    $raw = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO personal_access_tokens(tokenable_type,tokenable_id,clinic_id,name,token,expires_at) VALUES('patient',?,?,'mobile_app',?,?)")
        ->execute([$patientId, $clinic, hash('sha256', $raw), date('Y-m-d H:i:s', time() + 3600)]);
    echo json_encode(['token' => $raw]);
} elseif ($mode === 'staff_appointment') {
    // A recepção marca pela agenda interna (AppointmentService real, com a trava do profissional).
    TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
    try {
        $r = (new AppointmentService($db))->schedule(1, 1, (string)$argv[2], 'follow_up', 60, 'Marcado pela recepção', false, 1);
        echo json_encode(['ok' => true, 'id' => $r['id'] ?? $r['appointment_id'] ?? null]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
    }
} elseif ($mode === 'expire_holds') {
    // Viagem no tempo controlada: o TTL real é de minutos; aqui o prazo do hold é movido para o passado.
    $db->exec("UPDATE booking_holds SET expires_at_utc = '2000-01-01 00:00:00' WHERE status = 'held'");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'reset_rate_limit') {
    // Zera o contador do limitador (todos os testes vêm do mesmo IP 127.0.0.1).
    $db->exec("DELETE FROM teleconsultation_access_attempts WHERE bucket LIKE 'booking_%'");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'inspect') {
    echo json_encode([
        'appointments' => $db->query('SELECT id,clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type FROM appointments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'holds' => $db->query('SELECT id,clinic_id,professional_id,status,scheduled_at,appointment_id,patient_id,patient_name,patient_email,patient_phone,patient_cpf FROM booking_holds ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'history' => $db->query('SELECT appointment_id,from_status,to_status FROM appointment_status_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'consents' => $db->query("SELECT clinic_id,patient_id,consent_type,granted,term_id,source_channel FROM patient_consents WHERE source_channel = 'self_service_booking' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        'patients' => $db->query('SELECT id,clinic_id,name,email FROM patients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'settings' => $db->query('SELECT clinic_id,is_enabled,allow_telehealth,allow_presential,min_advance_hours FROM booking_settings ORDER BY clinic_id')->fetchAll(PDO::FETCH_ASSOC),
        'audit' => $db->query("SELECT clinic_id,action,resource_id,details FROM audit_logs WHERE action LIKE 'BOOKING_%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    ]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
