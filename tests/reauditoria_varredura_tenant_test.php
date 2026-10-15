<?php

declare(strict_types=1);

// Reauditoria v2 — itens corrigidos na varredura de consultas multi-tenant (além de V2-01).
// Duas clínicas sintéticas em SQLite isolado + HTTP real (API móvel do paciente).
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\Recipe\RecipeService;

echo "Varredura — favoritos do paciente e leituras alergênicas com escopo de clínica\n";
$fx = aud_fixture();
$db = $fx['db'];
$svc = new RecipeService($db);

// Receita PRIVADA da clínica B e receita pública da B
TenantContext::set($fx['clinicB'], ['id' => $fx['userB'], 'name' => 'Dr. Beta', 'clinic_id' => $fx['clinicB']]);
$recipeBPriv = (int)$svc->createRecipe(['title' => 'Receita secreta da Beta', 'servings' => 1, 'status' => 'published', 'visibility' => 'clinic'],
    [['food_id' => $fx['foodGlobal'], 'amount' => 100, 'measure_unit' => 'g']], [['instruction' => 'x']])['id'];
$recipeBPub = (int)$svc->createRecipe(['title' => 'Receita publica da Beta', 'servings' => 1, 'status' => 'published', 'visibility' => 'public'],
    [['food_id' => $fx['foodGlobal'], 'amount' => 100, 'measure_unit' => 'g']], [['instruction' => 'x']])['id'];
$db->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, restrictions, is_draft) VALUES (?, ?, ?, 1, 'trigo', '', 0)")
    ->execute([$fx['clinicB'], $fx['patientB'], $fx['profB']]);
TenantContext::set($fx['clinicA'], ['id' => $fx['userA'], 'name' => 'Dra. Alfa', 'clinic_id' => $fx['clinicA']]);
$db->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, restrictions, is_draft) VALUES (?, ?, ?, 1, 'trigo', '', 0)")
    ->execute([$fx['clinicA'], $fx['patientA'], $fx['profA']]);
$db->prepare("UPDATE foods SET name = 'Trigo secreto da Beta' WHERE id = ?")->execute([$fx['foodB']]);
$db->prepare("UPDATE foods SET name = 'Farinha de trigo global' WHERE id = ?")->execute([$fx['foodGlobal']]);

// ---------------------------------------------------------------- unidade
$thrown = false;
try { $svc->togglePatientFavorite($fx['patientA'], $recipeBPriv); } catch (TenantSecurityException) { $thrown = true; }
aud_check($thrown, 'favorito: paciente A não pode favoritar receita privada da clínica B');
$thrown = false;
try { $svc->togglePatientFavorite($fx['patientA'], 999999); } catch (TenantSecurityException) { $thrown = true; }
aud_check($thrown, 'favorito: receita inexistente recebe a mesma recusa');
aud_check($svc->togglePatientFavorite($fx['patientA'], $fx['recipeA']) === true, 'favorito: receita da própria clínica continua permitida');
aud_check($svc->togglePatientFavorite($fx['patientA'], $recipeBPub) === true, 'favorito: receita pública continua permitida');
// registro legado (gravado antes da correção) não vaza na listagem
$db->prepare('INSERT INTO recipe_patient_favorites (patient_id, recipe_id) VALUES (?, ?)')->execute([$fx['patientA'], $recipeBPriv]);
$titles = array_column($svc->listPatientFavorites($fx['patientA']), 'title');
aud_check(!in_array('Receita secreta da Beta', $titles, true) && in_array('Receita Alfa', $titles, true), 'favoritos: listagem não devolve receita privada de outra clínica (mesmo com registro legado)');
$db->prepare('DELETE FROM recipe_patient_favorites WHERE recipe_id = ?')->execute([$recipeBPriv]);

$v = (new AllergenSafetyValidator($db))->validateFoodsForPatient($fx['patientA'], [$fx['foodB'], $fx['foodGlobal']]);
$names = array_column($v['conflicts'], 'food_name');
aud_check(in_array('Farinha de trigo global', $names, true) && !in_array('Trigo secreto da Beta', $names, true), 'alergênicos: validador não lê alimento particular de outra clínica');

$m = new ReflectionMethod(RecipeService::class, 'checkPatientAllergenConflicts');
aud_check($m->invoke($svc, $fx['patientB'], [['allergen_group' => 'gluten']], $fx['clinicA']) === [], 'alergênicos: anamnese de paciente da clínica B não é lida com clinic_id da A');

// ---------------------------------------------------------------- HTTP (API do paciente)
$db->prepare('UPDATE patients SET password_hash = ? WHERE id = ?')->execute([password_hash('SenhaPaciente@1', PASSWORD_BCRYPT), $fx['patientA']]);
$port = 18000 + random_int(0, 900);
$srv = aud_http_server($fx['path'], $port);
$base = $srv['base'];
[$ls, , $lb] = aud_http('POST', "{$base}/api/v1/auth/patient-login", json_encode(['email' => 'paciente.alfa@aud.test', 'password' => 'SenhaPaciente@1']), ['Content-Type: application/json']);
$token = json_decode($lb, true)['token'] ?? '';
aud_check($ls === 200 && $token !== '', 'HTTP: paciente A autenticado na API');
$fav = static fn(int $rid) => aud_http('POST', "{$base}/api/v1/patient/recipes/{$rid}/favorite", '{}', ['Content-Type: application/json', "Authorization: Bearer {$token}"]);
$strip = static function (string $b): array { $j = json_decode($b, true) ?: []; unset($j['request_id']); return $j; }; // request_id é aleatório por requisição
$before = (int)$db->query('SELECT COUNT(*) FROM recipe_patient_favorites')->fetchColumn();
[$s1, , $b1] = $fav($recipeBPriv);
[$s2, , $b2] = $fav(999999);
aud_check($s1 === 404 && $s2 === 404 && $strip($b1) === $strip($b2), 'HTTP: favoritar receita privada de B e receita inexistente -> 404 idêntico (exceto request_id)');
aud_check(!str_contains($b1, 'Beta') && (int)$db->query('SELECT COUNT(*) FROM recipe_patient_favorites')->fetchColumn() === $before, 'HTTP: nenhum dado de B e nenhum registro criado');
[$s3, , $b3] = $fav($recipeBPub);
aud_check($s3 === 200 && isset(json_decode($b3, true)['is_favorite']), 'HTTP: receita pública continua favoritável');

echo "Varredura: OK\n";
