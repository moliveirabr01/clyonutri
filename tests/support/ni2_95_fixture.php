<?php
// Fixture do teste real da NI2-95 (avisos da paciente). Dados fictícios (TESTE).
// Só roda em SQLite isolado (/tmp/ni2-95-*).
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;

if (!preg_match('#^/tmp/ni2-95-#', getenv('DB_DATABASE') ?: '')) {
    throw new RuntimeException('Isolated test database required');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $db->exec("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'n95-c1','Clínica TESTE Um','active'),(2,'n95-c2','Clínica TESTE Dois','active')");
    $hash = password_hash('PacienteTeste@2026', PASSWORD_DEFAULT);
    $p = $db->prepare('INSERT INTO patients(id,clinic_id,uuid,name,email,password_hash,status) VALUES(?,?,?,?,?,?,?)');
    $p->execute([1, 1, 'n95-p1', 'Ana TESTE', 'ana95@example.test', $hash, 'active']);
    $p->execute([2, 1, 'n95-p2', 'Bruno TESTE', 'bruno95@example.test', $hash, 'active']);
    $p->execute([3, 2, 'n95-p3', 'Carla TESTE', 'carla95@example.test', $hash, 'active']);
    $u = $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status,is_verified) VALUES(?,?,?,?,?,'nutritionist','active',1)");
    $u->execute([1, 'n95-u1', 'Nutri TESTE Um', 'nutri95a@example.test', password_hash('Equipe@2026', PASSWORD_DEFAULT)]);
    $u->execute([2, 'n95-u2', 'Nutri TESTE Dois', 'nutri95b@example.test', password_hash('Equipe@2026', PASSWORD_DEFAULT)]);
    $u->execute([3, 'n95-u3', 'Nutri TESTE Três', 'nutri95c@example.test', password_hash('Equipe@2026', PASSWORD_DEFAULT)]);
    $db->exec("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,1,'owner','active'),(2,2,'owner','active'),(1,3,'nutritionist','active')");
    $db->exec("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'CRN-T1','CRN-3'),(2,2,'CRN-T2','CRN-3'),(3,3,'CRN-T3','CRN-3')");
    $db->exec("INSERT INTO foods(id,name,energy_kcal) VALUES(1,'Arroz TESTE',124)");
    $soon = (new DateTimeImmutable('+20 hours'))->format('Y-m-d H:i:00');
    $a = $db->prepare("INSERT INTO appointments(id,clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type) VALUES(?,?,?,?,?,60,'scheduled','follow_up')");
    $a->execute([11, 1, 1, 1, $soon]);   // Ana: lembrete pelo cron
    $a->execute([12, 1, 2, 1, $soon]);   // Bruno (mesma clínica)
    $a->execute([13, 2, 3, 2, $soon]);   // Carla (outra clínica)
    // Bruno daqui a 2h30 no horário local: só entra na janela se o cron usar o fuso da aplicação (em UTC ela "já passou").
    $a->execute([14, 1, 2, 1, (new DateTimeImmutable('+150 minutes'))->format('Y-m-d H:i:00')]);
    foreach ([[1, 1, 1, 'Plano TESTE da Ana'], [2, 3, 2, 'Plano TESTE da Carla']] as [$clinic, $patient, $user, $title]) {
        TenantContext::set($clinic, ['id' => $user, 'role' => 'nutritionist']);
        $svc = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);
        $plan = $svc->createPlan($patient, $user, $title, null, [['name' => 'Almoço', 'time_of_day' => '12:00', 'items' => [['food_id' => 1, 'amount' => 100, 'measure_unit' => 'g']]]]);
        $svc->publishPlan((int)$plan['id']);
    }
    // Avisos da caixa web do profissional 1: um com link interno, um com link externo (não pode redirecionar), um do profissional 2.
    $ev = $db->prepare("INSERT INTO notification_events(clinic_id,event_type,category,recipient_type,recipient_id,title,body,link,critical,dedupe_key,created_at) VALUES(?,?,?,?,?,?,?,?,0,?,?)");
    $now = date('Y-m-d H:i:s');
    $ev->execute([1, 'chat.message_created', 'chat', 'professional', 1, 'Nova mensagem no ClyoNutri', 'Você recebeu uma nova mensagem no chat do consultório.', '/pacientes/1', 'web95:interno', $now]);
    $ev->execute([1, 'chat.message_created', 'chat', 'professional', 1, 'Nova mensagem no ClyoNutri', 'Você recebeu uma nova mensagem no chat do consultório.', '//fora.example.test/x', 'web95:externo', $now]);
    $ev->execute([2, 'chat.message_created', 'chat', 'professional', 2, 'Nova mensagem no ClyoNutri', 'Você recebeu uma nova mensagem no chat do consultório.', '/pacientes/3', 'web95:outro', $now]);
    echo json_encode(['ok' => true, 'reminder_at' => $soon]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) throw new RuntimeException('Somente SELECT');
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} else {
    throw new RuntimeException('Unknown fixture mode');
}
