<?php
// Fixture do teste real de CSRF no app do paciente (/app). Só roda em banco isolado:
// SQLite em /tmp/app-csrf-* ou MySQL com banco app_csrf_*. Dados sintéticos (TESTE).
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\PatientPortal\PatientOnboardingService;

$conn = getenv('DB_CONNECTION') ?: 'sqlite';
$dbName = getenv('DB_DATABASE') ?: '';
if (!($conn === 'sqlite' && str_starts_with($dbName, '/tmp/app-csrf-')) && !($conn === 'mysql' && str_starts_with($dbName, 'app_csrf_'))) {
    throw new RuntimeException('Isolated test database required');
}
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'csrf-c1','Clínica TESTE CSRF','active')")->execute();
    $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'csrf-u1','Dra. TESTE Nutri','nutri.teste@example.test',?,'nutritionist','active')")->execute([password_hash('Equipe@2026', PASSWORD_DEFAULT)]);
    $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,1,'owner','active')")->execute();
    $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'CRN-T','CRN-3')")->execute();
    $hash = password_hash('PacienteTeste@2026', PASSWORD_DEFAULT);
    // 1: paciente ativo com senha (login). 2: ativo, ainda sem senha (primeiro acesso). 3: inativo com senha.
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,phone,status,password_hash) VALUES(1,1,'csrf-p1','Ana TESTE','ana.teste@example.test','11988887777','active',?)")->execute([$hash]);
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,phone,status) VALUES(2,1,'csrf-p2','Bruno TESTE','bruno.teste@example.test','11977776666','active')")->execute();
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,phone,status,password_hash) VALUES(3,1,'csrf-p3','Carla TESTE','carla.teste@example.test','11966665555','inactive',?)")->execute([$hash]);
    $invite = (new PatientOnboardingService($db))->create(1, 1, 2);
    echo json_encode(['ok' => true, 'invitation_code' => $invite['invitation_code']]);
} elseif ($mode === 'count_tokens') {
    echo json_encode(['tokens' => (int)$db->query("SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_type='patient'")->fetchColumn()]);
} elseif ($mode === 'new_invite') {
    $db->prepare("UPDATE patients SET password_hash=NULL WHERE id=2")->execute();
    echo json_encode(['invitation_code' => (new PatientOnboardingService($db))->create(1, 1, 2)['invitation_code']]);
} else {
    throw new RuntimeException('modo desconhecido');
}
