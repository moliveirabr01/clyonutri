<?php
// Banco isolado com dados sintéticos para o teste de impressão do prontuário.
declare(strict_types=1);
$root = getenv('WORK');
require $root . '/vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');
$path = $root . '/storage/prontuario_impressao.sqlite';
@unlink($path);
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=' . $path);
$db = \ClyoNutri\Core\Database\Connection::getInstance();
(new \ClyoNutri\Core\Database\Migrator($db, $root . '/database/migrations'))->run();
$q = function (string $sql, array $p = []) use ($db): int { $db->prepare($sql)->execute($p); return (int)$db->lastInsertId(); };
$pw = password_hash('Senha-Forte-2026!', PASSWORD_DEFAULT);
$clinic = $q("INSERT INTO clinics (uuid, name, email) VALUES ('pi-c', 'Clínica Impressão', 'c@ex.test')");
$other = $q("INSERT INTO clinics (uuid, name, email) VALUES ('pi-o', 'Outra Clínica', 'o@ex.test')");
$q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('off', 'Offset', 'off@ex.test', 'x', 'nutritionist', 1, 'active')");
$u = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('u', 'Dra. Ana Teste', 'pro@ex.test', ?, 'nutritionist', 1, 'active')", [$pw]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')", [$clinic, $u]);
$pro = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 12345', 'CRN-3')", [$u]);
$sec = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('s', 'Secretaria', 'sec@ex.test', ?, 'receptionist', 1, 'active')", [$pw]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'receptionist', 'active')", [$clinic, $sec]);
$patient = $q("INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, gender) VALUES (?, 'p', 'Paciente Impressão', 'p@ex.test', '11999990000', '1990-05-20', 'female')", [$clinic]);
$foreign = $q("INSERT INTO patients (clinic_id, uuid, name) VALUES (?, 'pf', 'Paciente de Outra Clínica')", [$other]);

\ClyoNutri\Core\Auth\TenantContext::set($clinic, ['id' => $u, 'name' => 'Dra. Ana Teste']);
$svc = new \ClyoNutri\Domain\Clinical\ClinicalRecordService($db);
$r1 = $svc->appendRecord($patient, $pro, 'Primeira consulta', ['text' => 'Queixa de cansaço. Recordatório com baixa ingestão de fibras.', 'goals' => 'Aumentar fibras']);
$svc->appendRecord($patient, $pro, 'Retorno <script>alert(1)</script>', ['notes' => "Linha 1\nLinha 2", 'teleconsultation_session_id' => 9, 'recorded_during_telehealth' => true]);
$svc->reviseRecord((int)$r1['id'], $patient, $pro, 'Correção de digitação', ['text' => 'Queixa de cansaço. Recordatório com baixa ingestão de fibras (corrigido).', 'goals' => 'Aumentar fibras']);
$q("INSERT INTO anthropometric_assessments (clinic_id, patient_id, professional_id, weight_kg, height_cm, bmi, waist_cm, assessed_at) VALUES (?, ?, ?, 72.4, 165, 26.6, 84, '2026-10-01 10:00:00')", [$clinic, $patient, $pro]);
echo json_encode(['patient' => $patient, 'foreign' => $foreign, 'user' => $u]);
