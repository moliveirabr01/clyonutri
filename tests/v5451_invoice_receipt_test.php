<?php
/**
 * v54.51 — Fatura/recibo clicável: webhook grava receipt_url do payment Asaas, getInvoices expõe,
 * fallback via GET /payments/{id} (fetcher injetado, sem HTTP), view só linka https Asaas, isolamento por clínica.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5451.sqlite');
require __DIR__ . '/_v54_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Billing\AsaasReceiptUrl;
use ClyoNutri\Domain\Billing\AsaasWebhookService;
use ClyoNutri\Domain\Subscription\SubscriptionService;

$db = Connection::getInstance();
$tok = bin2hex(random_bytes(24));
putenv('ASAAS_WEBHOOK_TOKEN=' . $tok); $_ENV['ASAAS_WEBHOOK_TOKEN'] = $tok;
putenv('ASAAS_API_KEY'); unset($_ENV['ASAAS_API_KEY'], $_SERVER['ASAAS_API_KEY']);
putenv('ASAAS_ENV=sandbox');

fwrite(STDOUT, "=== Migração 90 ===\n");
$cols = array_column($db->query('PRAGMA table_info(asaas_payment_receipts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
T::ok(in_array('receipt_url', $cols, true), 'coluna receipt_url existe');
require_once dirname(__DIR__) . '/database/migrations/2026_11_05_000090_asaas_receipt_url.php';
(new \AsaasReceiptUrl())->up($db);
T::ok(true, 'migração 90 idempotente (segunda execução sem erro)');

fwrite(STDOUT, "=== Escolha/validação da URL ===\n");
T::ok(AsaasReceiptUrl::pick(['transactionReceiptUrl' => 'https://sandbox.asaas.com/comprovantes/1', 'invoiceUrl' => 'https://sandbox.asaas.com/i/1']) === 'https://sandbox.asaas.com/comprovantes/1', 'prioriza transactionReceiptUrl');
T::ok(AsaasReceiptUrl::pick(['transactionReceiptUrl' => null, 'invoiceUrl' => 'https://www.asaas.com/i/2', 'bankSlipUrl' => 'https://www.asaas.com/b/pdf/2']) === 'https://www.asaas.com/i/2', 'depois invoiceUrl');
T::ok(AsaasReceiptUrl::pick(['bankSlipUrl' => 'https://sandbox.asaas.com/b/pdf/3']) === 'https://sandbox.asaas.com/b/pdf/3', 'por último bankSlipUrl');
T::ok(AsaasReceiptUrl::pick(['invoiceUrl' => 'http://sandbox.asaas.com/i/1']) === null, 'http recusado');
T::ok(AsaasReceiptUrl::pick(['invoiceUrl' => 'https://evil.example/i/1']) === null, 'host fora do Asaas recusado');
T::ok(!AsaasReceiptUrl::isSafe('https://sandbox.asaas.com.evil.example/x') && !AsaasReceiptUrl::isSafe('javascript:alert(1)') && !AsaasReceiptUrl::isSafe('https://u:p@sandbox.asaas.com/x'), 'sufixo, javascript: e credenciais recusados');
T::ok(AsaasReceiptUrl::pick([]) === null, 'payload sem URL: null (não inventa)');

$cA = Fx::clinic($db, 'Clínica TESTE Recibo A');
$cB = Fx::clinic($db, 'Clínica TESTE Recibo B');
$oA = Fx::user($db, $cA, 'Owner TESTE Recibo A', 'owner');
$oB = Fx::user($db, $cB, 'Owner TESTE Recibo B', 'owner');
$subs = new SubscriptionService($db);
$subA = $subs->getClinicSubscription($cA);
$subB = $subs->getClinicSubscription($cB);
$plan = (int)$db->query("SELECT id FROM saas_plans WHERE code = 'START-01'")->fetchColumn();
$amount = (int)$db->query("SELECT price_cents FROM saas_plans WHERE id = {$plan}")->fetchColumn();
$mkCheckout = function (int $clinic, array $sub, string $ref, int $uid) use ($db, $plan, $amount): void {
    $db->prepare('INSERT INTO asaas_checkouts (clinic_id, subscription_id, plan_id, requested_by, idempotency_key, external_reference,
        asaas_customer_id, asaas_checkout_id, hosted_url, amount_cents, billing_cycle, status, from_plan_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'monthly\', \'awaiting_payment\', ?)')
        ->execute([$clinic, (int)$sub['id'], $plan, $uid, bin2hex(random_bytes(16)), $ref, 'cus_TESTE', 'chk_' . bin2hex(random_bytes(4)),
            'https://sandbox.asaas.com/checkoutSession/show/x', $amount, (int)$sub['plan_id']]);
};
$refA = 'clyo-c' . $cA . '-p' . $plan . '-recibo01'; $mkCheckout($cA, $subA, $refA, (int)$oA['user_id']);
$refB = 'clyo-c' . $cB . '-p' . $plan . '-recibo02'; $mkCheckout($cB, $subB, $refB, (int)$oB['user_id']);
$hooks = new AsaasWebhookService($db);

fwrite(STDOUT, "=== Webhook grava a URL ===\n");
$r = $hooks->handle(['id' => 'evt_TESTE_rec_A', 'event' => 'PAYMENT_RECEIVED', 'payment' => [
    'id' => 'pay_TESTERECA', 'externalReference' => $refA, 'value' => $amount / 100, 'dueDate' => date('Y-m-d'),
    'billingType' => 'CREDIT_CARD', 'paymentDate' => date('Y-m-d'),
    'invoiceUrl' => 'https://sandbox.asaas.com/i/TESTERECA', 'transactionReceiptUrl' => 'https://sandbox.asaas.com/comprovantes/TESTERECA']]);
T::ok($r['status'] === 'activated', 'evento financeiro ativa (fluxo inalterado)');
$u = (string)$db->query("SELECT receipt_url FROM asaas_payment_receipts WHERE asaas_payment_id = 'pay_TESTERECA'")->fetchColumn();
T::ok($u === 'https://sandbox.asaas.com/comprovantes/TESTERECA', 'receipt_url = transactionReceiptUrl do payload');

$r = $hooks->handle(['id' => 'evt_TESTE_rec_B', 'event' => 'PAYMENT_RECEIVED', 'payment' => [
    'id' => 'pay_TESTERECB', 'externalReference' => $refB, 'value' => $amount / 100, 'dueDate' => date('Y-m-d'),
    'billingType' => 'PIX', 'paymentDate' => date('Y-m-d')]]);
T::ok($r['status'] === 'activated', 'clínica B ativa sem URL no payload');
$u = $db->query("SELECT receipt_url FROM asaas_payment_receipts WHERE asaas_payment_id = 'pay_TESTERECB'")->fetchColumn();
T::ok($u === null, 'sem URL no payload: receipt_url NULL');

fwrite(STDOUT, "=== getInvoices expõe receipt_url e isola por clínica ===\n");
$invA = $subs->getInvoices($cA, (int)$subA['id']);
T::ok(count($invA) === 1 && $invA[0]['receipt_url'] === 'https://sandbox.asaas.com/comprovantes/TESTERECA', 'clínica A recebe a própria URL');
T::ok(!array_key_exists('asaas_payment_id', $invA[0]), 'id do gateway não vai para a view');
T::ok(count($subs->getInvoices($cA, (int)$subB['id'])) === 0, 'clínica A não lê faturas da assinatura da B');

fwrite(STDOUT, "=== Fallback GET /payments/{id} ===\n");
$calls = [];
$subs->setPaymentFetcher(function (string $id) use (&$calls): array { $calls[] = $id; return ['id' => $id, 'invoiceUrl' => 'https://sandbox.asaas.com/i/' . substr($id, 4)]; });
$invB = $subs->getInvoices($cB, (int)$subB['id']);
T::ok($calls === ['pay_TESTERECB'] && $invB[0]['receipt_url'] === 'https://sandbox.asaas.com/i/TESTERECB', 'sem URL salva: consulta o gateway e expõe');
T::ok($db->query("SELECT receipt_url FROM asaas_payment_receipts WHERE asaas_payment_id = 'pay_TESTERECB'")->fetchColumn() === 'https://sandbox.asaas.com/i/TESTERECB', 'URL do gateway persistida');
$calls = [];
$subs->getInvoices($cB, (int)$subB['id']);
T::ok($calls === [], 'URL já salva: não chama o gateway de novo');

// Fatura de teste/local (como pay_test_3 do evt_manual_3): receipt com id fora do padrão do gateway.
$invId = (int)$db->query("SELECT id FROM subscription_invoices WHERE clinic_id = {$cA}")->fetchColumn();
$coA = (int)$db->query("SELECT id FROM asaas_checkouts WHERE clinic_id = {$cA}")->fetchColumn();
$db->prepare("INSERT INTO subscription_invoices (clinic_id, subscription_id, invoice_number, amount_cents, status, due_date, paid_at, payment_method) VALUES (?, ?, 'ASAAS-pay_test_3', 9900, 'paid', ?, ?, 'CREDIT_CARD')")
    ->execute([$cA, (int)$subA['id'], date('Y-m-d', strtotime('-1 day')), date('Y-m-d H:i:s')]);
$testInv = (int)$db->lastInsertId();
$db->prepare("INSERT INTO asaas_payment_receipts (clinic_id, checkout_id, invoice_id, asaas_payment_id, event_type, amount_cents) VALUES (?, ?, ?, 'pay_test_3', 'PAYMENT_RECEIVED', 9900)")
    ->execute([$cA, $coA, $testInv]);
$calls = [];
$invA = $subs->getInvoices($cA, (int)$subA['id']);
$t3 = array_values(array_filter($invA, fn($i) => $i['invoice_number'] === 'ASAAS-pay_test_3'))[0] ?? null;
T::ok($t3 !== null && $t3['receipt_url'] === null && $calls === [], 'pay_test_3: sem link e sem chamada ao gateway');
$subs->setPaymentFetcher(function (string $id): array { throw new \RuntimeException('404 do gateway'); });
$db->exec("UPDATE asaas_payment_receipts SET receipt_url = NULL WHERE asaas_payment_id = 'pay_TESTERECB'");
$invB = $subs->getInvoices($cB, (int)$subB['id']);
T::ok(count($invB) === 1 && $invB[0]['receipt_url'] === null, 'gateway falha: fatura listada sem link, sem exceção');
$subs->setPaymentFetcher(fn(string $id): array => ['invoiceUrl' => 'https://evil.example/i/x']);
$invB = $subs->getInvoices($cB, (int)$subB['id']);
T::ok($invB[0]['receipt_url'] === null, 'gateway devolve host estranho: não linka');
$db->exec("UPDATE asaas_payment_receipts SET receipt_url = 'http://sandbox.asaas.com/i/x' WHERE asaas_payment_id = 'pay_TESTERECA'");
$subs->setPaymentFetcher(fn(string $id): array => []);
$invA = $subs->getInvoices($cA, (int)$subA['id']);
$ra = array_values(array_filter($invA, fn($i) => $i['invoice_number'] === 'ASAAS-pay_TESTERECA'))[0];
T::ok($ra['receipt_url'] === null, 'URL http gravada no banco não vai para a view');
$db->exec("UPDATE asaas_payment_receipts SET receipt_url = 'https://sandbox.asaas.com/comprovantes/TESTERECA' WHERE asaas_payment_id = 'pay_TESTERECA'");

fwrite(STDOUT, "=== View ===\n");
$sub = ['id' => 1, 'status' => 'active', 'plan_name' => 'Start', 'plan_code' => 'start', 'billing_cycle' => 'monthly', 'price_cents' => 9900,
  'trial_ends_at' => '2026-10-22 00:00:00', 'current_period_end' => '2026-12-05 00:00:00', 'started_at' => '2026-10-08 00:00:00',
  'max_professionals' => 1, 'max_patients' => 100, 'storage_mb' => 1024,
  'usage' => ['active_professionals' => 1, 'active_patients' => 3, 'storage_mb' => 2],
  'invoices' => [
    ['invoice_number' => 'ASAAS-pay_OK', 'due_date' => '2026-11-05', 'paid_at' => '2026-11-05', 'amount_cents' => 9900, 'payment_method' => 'CREDIT_CARD', 'status' => 'paid', 'receipt_url' => 'https://sandbox.asaas.com/comprovantes/OK?a=1&b=2'],
    ['invoice_number' => 'ASAAS-pay_test_3', 'due_date' => '2026-11-04', 'paid_at' => '2026-11-04', 'amount_cents' => 9900, 'payment_method' => 'CREDIT_CARD', 'status' => 'paid', 'receipt_url' => null],
    ['invoice_number' => 'ASAAS-pay_BAD', 'due_date' => '2026-11-03', 'paid_at' => null, 'amount_cents' => 9900, 'payment_method' => 'PIX', 'status' => 'open', 'receipt_url' => 'javascript:alert(1)'],
    ['invoice_number' => 'F-legado', 'due_date' => '2026-11-02', 'paid_at' => null, 'amount_cents' => 9900, 'payment_method' => 'boleto', 'status' => 'open'],
  ]];
$vars = ['subscription' => $sub, 'reasons' => [], 'planOverview' => ['subscription' => $sub, 'options' => [], 'can_change' => true, 'idempotency_key' => 'k', 'history' => []],
  'deletion' => null, 'deletionAvailable' => true, 'legalParagraphs' => ['Aviso.'], 'flashBlockers' => null];
$html = (static function (array $vars): string { extract($vars); ob_start(); require dirname(__DIR__) . '/views/settings/subscription.php'; return (string)ob_get_clean(); })($vars);
T::ok(substr_count($html, 'data-invoice-receipt') === 1, 'exatamente um link de recibo (só a URL válida)');
T::ok(str_contains($html, 'href="https://sandbox.asaas.com/comprovantes/OK?a=1&amp;b=2" target="_blank" rel="noopener noreferrer"'), 'link https escapado, nova aba, noopener noreferrer');
T::ok(str_contains($html, 'Ver recibo'), 'CTA "Ver recibo" visível');
T::ok(!str_contains($html, 'javascript:alert'), 'URL javascript: não renderiza');
T::ok(str_contains($html, '<code>ASAAS-pay_test_3</code>') && str_contains($html, '<code>F-legado</code>') && str_contains($html, '<code>ASAAS-pay_BAD</code>'), 'faturas sem URL continuam legíveis');
T::ok(!preg_match('/\$aact_|access_token/', $html), 'nenhuma chave/token na página');

T::done();
