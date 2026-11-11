<?php
/**
 * v54.45 — P1 Code Review 360: NI2-692 header, NI2-716 clinical encryption, NI2-717 backup docs/scripts.
 * Dados só de TESTE. Sem Jira / sem push.
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5445.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\View\PatientHeader;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;

$db = Connection::getInstance();
$cA = Fx::clinic($db, 'Clínica TESTE CR P1');
$u = Fx::user($db, $cA, 'Nutri TESTE CR', 'owner');
$patientId = Fx::patient($db, $cA, 'Paciente TESTE CR Header');
Fx::act($u);
$profId = (int)$u['professional_id'];

fwrite(STDOUT, "=== v54.45 NI2-692 PatientHeader isolate agenda ===\n");

$svc = new AnamnesisService($db);
$svc->saveAnamnesis($patientId, $profId, [
    'allergy_assessment' => 'recorded',
    'allergies' => 'TESTE amendoim CR-P1',
    'objectives' => 'TESTE objetivo cifrado',
    'notes' => 'TESTE nota cifrada',
], false);

PatientHeader::$careStepResolver = static function (int $pid): array {
    throw new RuntimeException('TESTE_AGENDA_DOWN');
};
$html = PatientHeader::render($db, $patientId, 'resumo');
PatientHeader::$careStepResolver = null;

T::ok(str_contains($html, 'TESTE amendoim CR-P1'), 'alergias visíveis com agenda falhando');
T::ok(str_contains($html, 'care-step-unavailable') || str_contains($html, 'Agenda temporariamente indisponível'), 'UI agenda unavailable');
T::ok(!str_contains($html, 'Contexto clínico indisponível'), 'não mascara contexto clínico inteiro');
T::ok(str_contains($html, 'data-testid="clinical-context"') || str_contains($html, 'profile-allergy-badge'), 'contexto/selo clínico renderizado');

fwrite(STDOUT, "=== v54.45 NI2-716 ClinicalEncryptionService ===\n");

ClinicalEncryptionService::setKeyForTesting('unit-test-key-v5445');
$plain = 'Paciente TESTE: lactose + glúten';
$enc = ClinicalEncryptionService::encrypt($plain);
T::ok(ClinicalEncryptionService::isEncrypted($enc), 'encrypt produz enc:v1:');
T::ok(str_starts_with((string)$enc, 'enc:v1:'), 'prefixo enc:v1:');
T::ok(ClinicalEncryptionService::decrypt($enc) === $plain, 'round-trip decrypt');
T::ok(ClinicalEncryptionService::decrypt('texto legado claro') === 'texto legado claro', 'legado plaintext passthrough');
T::ok(ClinicalEncryptionService::encrypt($enc) === $enc, 'não dupla-cifra');
ClinicalEncryptionService::setKeyForTesting(null);

putenv('APP_ENV=production');
$_ENV['APP_ENV'] = 'production';
$_SERVER['APP_ENV'] = 'production';
putenv('CLINICAL_ENCRYPTION_KEY');
unset($_ENV['CLINICAL_ENCRYPTION_KEY'], $_SERVER['CLINICAL_ENCRYPTION_KEY']);
ClinicalEncryptionService::setKeyForTesting(null);
T::throws(
    static fn () => ClinicalEncryptionService::resolveKey(),
    'produção sem CLINICAL_ENCRYPTION_KEY falha fechado',
    'CLINICAL_ENCRYPTION_KEY'
);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod');
$_ENV['CLINICAL_ENCRYPTION_KEY'] = 'test-clinical-key-v5445-not-for-prod';
$_SERVER['CLINICAL_ENCRYPTION_KEY'] = 'test-clinical-key-v5445-not-for-prod';

$raw = $db->prepare('SELECT allergies, objectives, notes FROM anamneses WHERE patient_id = ? AND clinic_id = ? ORDER BY version DESC LIMIT 1');
$raw->execute([$patientId, $cA]);
$row = $raw->fetch(PDO::FETCH_ASSOC);
T::ok($row && ClinicalEncryptionService::isEncrypted((string)$row['allergies']), 'DB persiste allergies como enc:v1');
T::ok($row && ClinicalEncryptionService::isEncrypted((string)$row['objectives']), 'DB persiste objectives como enc:v1');
T::ok($row && ClinicalEncryptionService::isEncrypted((string)$row['notes']), 'DB persiste notes como enc:v1');

$latest = $svc->getLatestAnamnesis($patientId);
T::ok(($latest['allergies'] ?? '') === 'TESTE amendoim CR-P1', 'getLatestAnamnesis devolve texto claro');
T::ok(($latest['objectives'] ?? '') === 'TESTE objetivo cifrado', 'objectives claros na UI path');

$db->prepare("UPDATE anamneses SET allergies = 'TESTE alergia legada plaintext' WHERE patient_id = ? AND clinic_id = ?")
    ->execute([$patientId, $cA]);
$legacy = $svc->getLatestAnamnesis($patientId);
T::ok(($legacy['allergies'] ?? '') === 'TESTE alergia legada plaintext', 'legado plaintext legível via decrypt passthrough');

fwrite(STDOUT, "=== v54.45 NI2-717 backup scripts / drill doc ===\n");
T::ok(is_file(dirname(__DIR__) . '/docs/delivery/BACKUP_RESTORE_DRILL.md'), 'doc BACKUP_RESTORE_DRILL.md existe');
$backupSh = (string)file_get_contents(dirname(__DIR__) . '/bin/backup_database.sh');
T::ok(str_contains($backupSh, 'BACKUP_OFFSITE_DIR'), 'backup_database.sh suporte BACKUP_OFFSITE_DIR');
T::ok(str_contains($backupSh, 'BACKUP_OFFSITE_RSYNC'), 'backup_database.sh suporte BACKUP_OFFSITE_RSYNC');
$restoreSh = (string)file_get_contents(dirname(__DIR__) . '/bin/restore_database.sh');
T::ok(str_contains($restoreSh, '--verify-only'), 'restore_database.sh --verify-only');
T::ok(str_contains($restoreSh, 'BACKUP_RESTORE_DRILL'), 'restore aponta para drill doc');
T::ok(is_file($root . '/bin/sqlite_dump.php'), 'bin/sqlite_dump.php existe');
T::ok(is_file($root . '/bin/sqlite_import_count.php'), 'bin/sqlite_import_count.php existe');

T::done();
