<?php
require __DIR__ . '/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Patient\PatientRepository;
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE patients (id INTEGER PRIMARY KEY, clinic_id INTEGER, name TEXT, email TEXT, phone TEXT, birth_date TEXT, gender TEXT, notes TEXT, status TEXT, uuid TEXT)');
$db->exec("INSERT INTO patients VALUES (1, 10, 'Original', 'old@example.com', '123', NULL, NULL, 'Notas', 'active', 'stable-id'), (2, 20, 'Outra clínica', NULL, NULL, NULL, NULL, NULL, 'active', 'other-id')");
TenantContext::setClinicId(10);
$repo = new PatientRepository($db);
$data = ['name' => 'Nome atualizado', 'email' => 'new@example.com', 'phone' => null, 'birth_date' => '1990-01-20', 'gender' => 'Feminino', 'notes' => 'Atualizadas'];
if (!$repo->update(1, $data)) throw new RuntimeException('Atualização falhou');
$p = $repo->findById(1);
foreach ($data as $key => $value) if ($p[$key] !== $value) throw new RuntimeException('Persistência incorreta: ' . $key);
if ($p['uuid'] !== 'stable-id' || $p['status'] !== 'active') throw new RuntimeException('Identidade alterada');
if ($repo->update(2, $data) || $repo->update(999, $data)) throw new RuntimeException('Acesso indevido');
if ($db->query('SELECT name FROM patients WHERE id = 2')->fetchColumn() !== 'Outra clínica') throw new RuntimeException('Outra clínica alterada');
echo "PASS: edição persistida, campos opcionais limpos, identidade preservada e isolamento entre clínicas.\n";
