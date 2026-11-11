<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../database/migrations/2026_10_10_000030_create_saas_subscriptions_and_cancellation_tables.php';

use ClyoNutri\Domain\Subscription\SubscriptionService;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
(new CreateSaasSubscriptionsAndCancellationTables())->up($db);
$db->exec('ALTER TABLE clinic_subscriptions ADD COLUMN trial_ends_at DATETIME');
$db->exec('CREATE TABLE clinic_members (clinic_id INTEGER, user_id INTEGER, role TEXT, status TEXT)');
$db->exec("INSERT INTO clinic_members VALUES (1,1,'owner','active'),(1,2,'nutritionist','active'),(2,3,'admin','active')");
$service = new SubscriptionService($db);
function checkPlan(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function rejectPlan(callable $action): void {
    try { $action(); } catch (DomainException|InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected selection rejection');
}
checkPlan(count($service->getOnboardingPlans()) === 3, 'Active catalog');
$db->beginTransaction();
$service->chooseOnboardingPlan(1, 1, 'START-01');
$db->commit();
$before = $db->query('SELECT * FROM clinic_subscriptions WHERE clinic_id=1')->fetch(PDO::FETCH_ASSOC);
$service->chooseOnboardingPlan(1, 1, 'ELITE-03');
$after = $db->query('SELECT * FROM clinic_subscriptions WHERE clinic_id=1')->fetch(PDO::FETCH_ASSOC);
checkPlan((int)$after['price_cents'] === 38900, 'Catalog price persisted');
checkPlan($after['trial_ends_at'] === $before['trial_ends_at'], 'Trial deadline preserved');
checkPlan($after['status'] === 'trialing', 'Selection does not activate billing');
checkPlan((int)$db->query('SELECT COUNT(*) FROM subscription_invoices')->fetchColumn() === 0, 'No invoice created');
rejectPlan(fn() => $service->chooseOnboardingPlan(1, 2, 'PRO-02'));
rejectPlan(fn() => $service->chooseOnboardingPlan(2, 1, 'PRO-02'));
rejectPlan(fn() => $service->chooseOnboardingPlan(1, 1, ''));
$db->exec("UPDATE saas_plans SET is_active=0 WHERE code='PRO-02'");
rejectPlan(fn() => $service->chooseOnboardingPlan(1, 1, 'PRO-02'));
$db->exec("UPDATE clinic_subscriptions SET status='active' WHERE clinic_id=1");
rejectPlan(fn() => $service->chooseOnboardingPlan(1, 1, 'START-01'));
echo "PASS: catalog, persistence, trial, billing, roles, tenant isolation, invalid/inactive plans and active subscriptions\n";
