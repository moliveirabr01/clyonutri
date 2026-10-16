<?php
// Semeia um banco SQLite isolado para o teste HTTP (dados sintéticos).
declare(strict_types=1);
$root = getenv('WORK');
require $root . '/vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');
$path = $root . '/storage/teleconsulta_navegador.sqlite';
@unlink($path);
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=' . $path);
$db = \ClyoNutri\Core\Database\Connection::getInstance();
(new \ClyoNutri\Core\Database\Migrator($db, $root . '/database/migrations'))->run();
$q = function (string $sql, array $p = []) use ($db): int { $db->prepare($sql)->execute($p); return (int)$db->lastInsertId(); };
$clinic = $q("INSERT INTO clinics (uuid, name, email) VALUES ('http-c', 'Clínica HTTP', 'c@ex.test')");
$q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('off', 'Offset', 'off@ex.test', 'x', 'nutritionist', 1, 'active')");
$user = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('u-pro', 'Nutri HTTP', 'pro@ex.test', ?, 'nutritionist', 1, 'active')", [password_hash('Senha-Forte-2026!', PASSWORD_DEFAULT)]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')", [$clinic, $user]);
$pro = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 1', 'CRN-3')", [$user]);
$sec = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('u-sec', 'Secretaria', 'sec@ex.test', ?, 'receptionist', 1, 'active')", [password_hash('Senha-Forte-2026!', PASSWORD_DEFAULT)]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'receptionist', 'active')", [$clinic, $sec]);
$patient = $q("INSERT INTO patients (clinic_id, uuid, name, email, password_hash) VALUES (?, 'p-http', 'Paciente HTTP', 'p@ex.test', ?)", [$clinic, password_hash('Senha-Forte-2026!', PASSWORD_DEFAULT)]);
// v13: consulta online que já passou (fim + tolerância): não é "próxima consulta" e não ganha sala.
$past = $q("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (?, ?, ?, ?, 50, 'online', 'scheduled')",
    [$clinic, $patient, $pro, date('Y-m-d H:i:00', time() - 3 * 3600)]);
$appt = $q("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (?, ?, ?, ?, 50, 'online', 'scheduled')",
    [$clinic, $patient, $pro, date('Y-m-d H:i:00', time() + 300)]);
echo json_encode(['appointment' => $appt, 'past_appointment' => $past, 'user' => $user, 'professional' => $pro]);
