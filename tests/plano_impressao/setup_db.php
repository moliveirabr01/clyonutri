<?php
// Banco isolado com dados sintéticos para o teste de impressão do plano alimentar.
declare(strict_types=1);
$root = getenv('WORK');
require $root . '/vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');
$path = $root . '/storage/plano_impressao.sqlite';
@unlink($path);
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=' . $path);
$db = \ClyoNutri\Core\Database\Connection::getInstance();
(new \ClyoNutri\Core\Database\Migrator($db, $root . '/database/migrations'))->run();
$q = function (string $sql, array $p = []) use ($db): int { $db->prepare($sql)->execute($p); return (int)$db->lastInsertId(); };
$pw = password_hash('Senha-Forte-2026!', PASSWORD_DEFAULT);
$clinic = $q("INSERT INTO clinics (uuid, name, email) VALUES ('mp-c', 'Clínica Plano', 'c@ex.test')");
$other = $q("INSERT INTO clinics (uuid, name, email) VALUES ('mp-o', 'Outra Clínica', 'o@ex.test')");
$q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('off', 'Offset', 'off@ex.test', 'x', 'nutritionist', 1, 'active')");
$u = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('u', 'Dra. Bia Plano', 'pro@ex.test', ?, 'nutritionist', 1, 'active')", [$pw]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')", [$clinic, $u]);
$pro = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 55555', 'CRN-3')", [$u]);
$sec = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('s', 'Secretaria', 'sec@ex.test', ?, 'receptionist', 1, 'active')", [$pw]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'receptionist', 'active')", [$clinic, $sec]);
$uo = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('uo', 'Nutri Outra', 'o2@ex.test', 'x', 'nutritionist', 1, 'active')");
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')", [$other, $uo]);
$proOther = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-1 1', 'CRN-1')", [$uo]);
$patient = $q("INSERT INTO patients (clinic_id, uuid, name, birth_date) VALUES (?, 'p', 'Paciente Plano', '1988-03-02')", [$clinic]);
$patientOther = $q("INSERT INTO patients (clinic_id, uuid, name) VALUES (?, 'po', 'Paciente Outra')", [$other]);
$food = fn(string $n, float $kcal) => $q("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g) VALUES (NULL, ?, 'teste', 'TACO', ?, 2.5, 28, 0.2, 1.6, 100)", [$n, $kcal]);
$arroz = $food('Arroz integral cozido', 124); $feijao = $food('Feijão carioca cozido', 76); $batata = $food('Batata-doce cozida', 77); $iog = $food('Iogurte natural', 51);

\ClyoNutri\Core\Auth\TenantContext::set($clinic, ['id' => $u, 'name' => 'Dra. Bia Plano']);
$svc = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);
$plan = $svc->createPlan($patient, $pro, 'Plano de reeducação', "Beba água ao longo do dia.\nEvite frituras <b>nunca</b>.");
$planId = (int)($plan['id'] ?? $plan['plan_id'] ?? 0);
$almoco = $svc->addMeal($planId, 'Almoço', '12:30', 'Comece pela salada.');
$i1 = $svc->addItem($almoco, $arroz, 120, 'g', 'Pode ser arroz branco se faltar');
$svc->addSubstitution($i1, $batata, 150, 'g', 'assada');
$svc->addItem($almoco, $feijao, 1.5, 'concha');
$lanche = $svc->addMeal($planId, 'Lanche da tarde', '16:00');
$svc->addItem($lanche, $iog, 170, 'g');
$svc->calculateAndPersistPlanTotals($planId);
$svc->publishPlan($planId, true);
// Alteração posterior no banco (fora do fluxo): a impressão deve continuar mostrando a versão publicada.
$db->prepare("UPDATE meal_plan_items SET amount = 999 WHERE id = ?")->execute([$i1]);

$draft = $svc->createPlan($patient, $pro, 'Rascunho em elaboração');
$draftId = (int)($draft['id'] ?? $draft['plan_id'] ?? 0);
$m = $svc->addMeal($draftId, 'Café da manhã', '07:00');
$svc->addItem($m, $iog, 200, 'g');

\ClyoNutri\Core\Auth\TenantContext::set($other, ['id' => $uo, 'name' => 'Nutri Outra']);
$foreignPlan = (new \ClyoNutri\Domain\MealPlan\MealPlanService($db))->createPlan($patientOther, $proOther, 'Plano de outra clínica');
echo json_encode(['plan' => $planId, 'draft' => $draftId, 'foreign' => (int)($foreignPlan['id'] ?? $foreignPlan['plan_id'] ?? 0), 'user' => $u]);
