<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../database/migrations/2026_10_08_000021_create_onboarding_and_activation_tables.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Onboarding\OnboardingService;
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
(new CreateOnboardingAndActivationTables())->up($db);
// Any accidental patient write fails: this isolated database has no patients table.
TenantContext::set(1, ['id' => 1]);
$service = new OnboardingService($db);
$progress = $service->getOrCreateProgress(1, 1);
$db->exec("UPDATE onboarding_progress SET completed_steps='[1,2,3]', current_step=4");
function verifyDefer(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$progress = $service->saveStep(1, 1, 4, ['skip_patient' => '1', 'name' => 'Ignored partial form', 'birth_date' => 'invalid']);
verifyDefer($progress['current_step'] === 5 && $progress['first_patient_id'] === null, 'Defer advances without patient');
verifyDefer($progress['progress_pct'] === 80, 'Optional step counts as resolved');
$progress = $service->saveStep(1, 1, 5, []);
verifyDefer($progress['is_completed'] && $progress['progress_pct'] === 100, 'Onboarding completes without patient');
$db->exec('UPDATE onboarding_progress SET first_patient_id=42');
$progress = $service->saveStep(1, 1, 4, ['skip_patient' => '1']);
verifyDefer($progress['first_patient_id'] === 42, 'Defer preserves an existing patient');
try {
    $service->saveStep(1, 1, 4, ['name' => '']);
    throw new RuntimeException('Empty creation should require a name');
} catch (InvalidArgumentException $e) {}
TenantContext::set(2, ['id' => 2]);
try {
    $service->saveStep(1, 1, 4, ['skip_patient' => '1']);
    throw new RuntimeException('Foreign tenant should be denied');
} catch (ClyoNutri\Core\Security\TenantSecurityException $e) {}
echo "PASS: defer, partial form ignored, completion, existing patient preserved, creation validation, tenant isolation\n";
