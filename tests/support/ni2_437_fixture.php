<?php
// Fixture isolada do teste E2E da NI2-437 (pré-consulta). Exige DB_DATABASE em /tmp/ni2-437-*.
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;

if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-437-')) {
    throw new RuntimeException('Isolated test database required');
}
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';
if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $hash = password_hash('PreTeste@2026', PASSWORD_DEFAULT);
    foreach ([1, 2] as $i) {
        $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(?,?,?,'active')")->execute([$i, "pc-c$i", "Clínica sintética $i"]);
        $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(?,?,?,?,?,'nutritionist','active')")->execute([$i, "pc-u$i", "Nutricionista sintética $i", "pre$i@example.test", $hash]);
        $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(?,?,'nutritionist','active')")->execute([$i, $i]);
        $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(?,?,'CRN-T','CRN-3')")->execute([$i, $i]);
    }
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,status,birth_date) VALUES(1,1,'pc-p1','Paciente Sintética Um','p1@paciente.test','active','1988-02-29')")->execute();
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,status,birth_date) VALUES(2,2,'pc-p2','Paciente Sintético Dois','p2@paciente.test','active','1990-01-01')")->execute();
    $db->prepare("INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type) VALUES(1,1,1,?,60,'scheduled','first_visit')")->execute([date('Y-m-d H:i:s', time() + 5 * 86400)]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'inspect') {
    echo json_encode([
        'questionnaires' => $db->query('SELECT id,clinic_id,patient_id,status,token_hash,token_expires_at,answers_json,consent_id,imported_anamnesis_id,questions_json FROM preconsultation_questionnaires ORDER BY id')->fetchAll(),
        'consents' => $db->query("SELECT * FROM patient_consents WHERE consent_type = 'pre_consultation' ORDER BY id")->fetchAll(),
        'anamneses' => $db->query('SELECT id,patient_id,is_draft,objectives,allergies,clinical_history,allergy_assessment FROM anamneses ORDER BY id')->fetchAll(),
        'audit' => $db->query("SELECT action FROM audit_logs WHERE resource = 'preconsultation_questionnaires' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        'snapshots' => $db->query('SELECT kind FROM preconsultation_answer_snapshots ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
    ]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
