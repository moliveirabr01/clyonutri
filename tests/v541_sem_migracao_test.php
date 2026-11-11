<?php
/** v54.1: código novo com banco SEM as migrações 75 e 77 (deploy em que o SQL ainda não rodou). Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v541-sem.sqlite');
require __DIR__ . '/_v54_bootstrap.php';
$pdo->exec('DROP INDEX IF EXISTS uq_professionals_booking_slug');
foreach ([['patients', 'allergies'], ['patients', 'allergy_assessment'], ['professionals', 'booking_slug'], ['booking_settings', 'public_show_address']] as [$t, $c]) {
    $pdo->exec("ALTER TABLE {$t} DROP COLUMN {$c}");
}
\ClyoNutri\Core\Database\SchemaInfo::reset();
$c = Fx::clinic($pdo, 'Clínica TESTE');
$o = Fx::user($pdo, $c, 'Nutri TESTE', 'owner');
$pdo->prepare('INSERT INTO booking_settings (clinic_id, is_enabled, allow_presential, allow_telehealth) VALUES (?, 1, 1, 1)')->execute([$c]);
Fx::act($o);
echo "== Sem migrações 75 e 77\n";
$repo = new \ClyoNutri\Domain\Patient\PatientRepository($pdo);
$p = null;
try { $p = $repo->create(['name' => 'Paciente TESTE', 'allergies' => 'amendoim']); } catch (\Throwable $e) { echo $e->getMessage(), "\n"; }
T::ok(is_array($p) && (int)$p['id'] > 0, 'cadastro de paciente funciona');
T::ok($p && $repo->update((int)$p['id'], ['name' => 'Paciente TESTE 2', 'allergies' => 'ovo']), 'edição de paciente funciona');
$ok = true; try { (new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($pdo))->evaluatePatientAllergyState((int)$p['id']); (new \ClyoNutri\Domain\Anamnesis\AnamnesisService($pdo))->getClinicalSummaryForPrescription((int)$p['id']); } catch (\Throwable $e) { $ok = false; echo $e->getMessage(), "\n"; }
T::ok($ok, 'trava de alérgeno e resumo clínico funcionam');
$bs = new \ClyoNutri\Domain\Booking\BookingService($pdo);
$prof = $bs->publicProfessional($o['professional_id']);
T::ok($prof !== null && $prof['slug'] === null && $prof['clinic_address'] === null, 'profissional público por número (como na v53), sem apelido e sem endereço');
T::ok((new \ClyoNutri\Domain\Booking\PublicBookingSlug($pdo))->ensure($o['professional_id'], 'Nutri') === null, 'sem coluna, não tenta gerar apelido');
$ok = true; try { $bs->updateSettings($c, ['public_show_address' => true]); } catch (\Throwable $e) { $ok = false; }
T::ok($ok, 'salvar agendamento online funciona sem a coluna nova');
T::done();
