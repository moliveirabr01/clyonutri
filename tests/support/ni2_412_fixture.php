<?php
// Fixture do E2E real da NI2-412 (UX-01 agenda e UX-02 alterações pendentes). Dados fictícios.
// Só roda em SQLite isolado (/tmp/ni2-412-*). Funciona também na árvore anterior à correção (baseline).
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\CatalogSeeder;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;

if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-412-')) {
    throw new RuntimeException('Isolated test database required');
}
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    (new CatalogSeeder($db))->run();
    $hash = password_hash('Agenda@2026', PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'ux-c1','Clínica Fictícia UX','active')")->execute();
    $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'ux-u1','Nutri Fictícia UX','ux@example.test',?,'nutritionist','active')")->execute([$hash]);
    $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,1,'owner','active')")->execute();
    $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'CRN-T','CRN-3')")->execute();
    $db->prepare("INSERT INTO onboarding_progress(clinic_id,user_id,current_step,practice_preferences,is_completed,created_at,updated_at) VALUES(1,1,3,'{}',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute();
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,status,birth_date) VALUES(1,1,'ux-p1','Paciente Fictícia Agenda','agenda@paciente.test','active','1990-05-10')")->execute();
    // Uma consulta por semana em pontos de virada, para provar que o CONTEÚDO da semana muda (não só o título).
    $ins = $db->prepare("INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type) VALUES(1,1,1,?,60,'scheduled','follow_up')");
    foreach (['2026-10-14 09:00:00', '2026-10-21 10:00:00', '2026-12-30 11:00:00', '2027-01-06 14:00:00', '2026-12-31 15:00:00', '2027-01-01 16:00:00'] as $at) {
        $ins->execute([$at]);
    }
    // Segunda clínica (isolamento) com paciente e alimento personalizado próprios.
    $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(2,'ux-c2','Clínica Fictícia Vizinha','active')")->execute();
    $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,email,status) VALUES(901,2,'ux-p901','Lucas Aluno','lucas@vizinha.test','active')")->execute();
    $food = $db->prepare("INSERT INTO foods(clinic_id,name,category,source,energy_kcal,protein_g,carbohydrate_g,lipid_g,fiber_g,is_custom) VALUES(?,?,?,?,?,?,?,?,?,1)");
    $food->execute([1, 'Arroz integral da casa (receita própria)', 'Preparações', 'Clínica', 140, 3.1, 28.0, 1.6, 2.0]);
    $food->execute([2, 'Arroz secreto da clínica vizinha', 'Preparações', 'Clínica', 150, 3.0, 30.0, 2.0, 1.0]);
    // Dois cadastros com o MESMO nome e composição (duplicata real) e um com mesmo nome e composição diferente.
    $food->execute([1, 'Feijão tropeiro fictício', 'Preparações', 'Clínica', 200, 10.0, 20.0, 9.0, 5.0]);
    $food->execute([1, 'Feijão tropeiro fictício', 'Preparações', 'Clínica', 200, 10.0, 20.0, 9.0, 5.0]);
    $food->execute([1, 'Feijão tropeiro fictício', 'Preparações', 'Clínica', 260, 12.0, 22.0, 14.0, 6.0]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'attention') {
    // Pendência real para o painel (NI2-421): falta há 3 dias sem remarcação, para a paciente fictícia 1.
    $at = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('-3 days')->format('Y-m-d') . ' 08:00:00';
    $db->prepare("INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type) VALUES(1,1,1,?,60,'no_show','follow_up')")->execute([$at]);
    echo json_encode(['ok' => true, 'no_show_at' => $at]);
} elseif ($mode === 'sql') {
    // Consulta somente leitura para conferir persistência no teste (SELECT apenas).
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) {
        throw new RuntimeException('Somente SELECT');
    }
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} elseif ($mode === 'inspect') {
    echo json_encode([
        'anamneses' => $db->query('SELECT patient_id,version,is_draft,objectives FROM anamneses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'meals' => $db->query('SELECT id,meal_plan_id,name FROM meal_plan_meals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'items' => (int)$db->query('SELECT COUNT(*) FROM meal_plan_items')->fetchColumn(),
        'plans' => $db->query('SELECT id,title FROM meal_plans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
