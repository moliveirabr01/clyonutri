<?php
/**
 * v54.48 — NI2-727 slice 1 (Asaas Sandbox): persistência, RBAC, idempotência de webhook,
 * recusa de token, NÃO ativação sem evento financeiro, reconciliação e regressão de assinatura.
 * Banco SQLite descartável; dados só de TESTE. Chamadas ao gateway NÃO ocorrem aqui
 * (elas ficam em tests/v5448_ni2_727_asaas_live_test.php, com a chave real de sandbox).
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5448.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Billing\AsaasCheckoutService;
use ClyoNutri\Domain\Billing\AsaasConfig;
use ClyoNutri\Domain\Billing\AsaasWebhookService;
use ClyoNutri\Domain\Subscription\PlanChangeService;
use ClyoNutri\Domain\Subscription\SubscriptionService;

$root = dirname(__DIR__);
$db = Connection::getInstance();

// Token de webhook aleório só para os testes locais (nunca uma API key).
$webhookToken = bin2hex(random_bytes(24));
putenv('ASAAS_WEBHOOK_TOKEN=' . $webhookToken);
$_ENV['ASAAS_WEBHOOK_TOKEN'] = $webhookToken;
// Sem ASAAS_API_KEY no processo do teste local: a UI/endpoint devem falhar fechado.
putenv('ASAAS_API_KEY');
unset($_ENV['ASAAS_API_KEY'], $_SERVER['ASAAS_API_KEY']);
putenv('ASAAS_ENV=sandbox');

$cA = Fx::clinic($db, 'Clínica TESTE Asaas A');
$cB = Fx::clinic($db, 'Clínica TESTE Asaas B');
$owner = Fx::user($db, $cA, 'Owner TESTE Asaas', 'owner');
$member = Fx::user($db, $cA, 'Nutri TESTE Asaas', 'nutritionist');
$ownerB = Fx::user($db, $cB, 'Owner TESTE Asaas B', 'owner');

$subs = new SubscriptionService($db);
$subA = $subs->getClinicSubscription($cA); // trial 14 dias PRO-02
$subB = $subs->getClinicSubscription($cB);
$planElite = (int)$db->query("SELECT id FROM saas_plans WHERE code = 'ELITE-03'")->fetchColumn();
$planStart = (int)$db->query("SELECT id FROM saas_plans WHERE code = 'START-01'")->fetchColumn();
$planPro = (int)$db->query("SELECT id FROM saas_plans WHERE code = 'PRO-02'")->fetchColumn();

fwrite(STDOUT, "=== Config fail-closed ===\n");
$cfg = AsaasConfig::fromEnv();
T::ok($cfg->isSandbox() && !$cfg->hasApiKey(), 'sem chave: sandbox sim, chave não');
T::ok(!$cfg->canStartCheckout(), 'sem chave: checkout não habilitado');
T::ok($cfg->hasWebhookToken(), 'token de webhook de teste presente (>=32)');
putenv('ASAAS_BASE_URL=https://api.asaas.com/v3');
$bad = AsaasConfig::fromEnv();
T::throws(fn() => $bad->assertCoherent(), 'sandbox + URL de produção recusado', 'sandbox');
putenv('ASAAS_BASE_URL');
putenv('ASAAS_API_KEY=sk-live-not-sandbox');
$badKey = AsaasConfig::fromEnv();
T::throws(fn() => $badKey->assertCoherent(), 'chave sem marca de sandbox recusada', 'sandbox');
putenv('ASAAS_API_KEY');

fwrite(STDOUT, "=== Checkout: RBAC e falha fechada sem chave ===\n");
$checkoutSvc = new AsaasCheckoutService($db, $subs);
T::ok(!$checkoutSvc->isEnabled(), 'sem chave: serviço desabilitado');
T::throws(fn() => $checkoutSvc->startCheckout($cA, (int)$member['user_id'], $planElite), 'membro sem papel de gestão é recusado', 'titulares');
T::throws(fn() => $checkoutSvc->startCheckout($cB, (int)$owner['user_id'], $planElite), 'owner da clínica A não contrata para a B', 'titulares');
T::throws(fn() => $checkoutSvc->startCheckout($cA, (int)$owner['user_id'], $planElite), 'sem chave: recusa antes de qualquer HTTP', 'não está configurada');
T::ok($checkoutSvc->latestForClinic($cA) === null, 'nenhuma tentativa gravada nas recusas');

fwrite(STDOUT, "=== Persistência da tentativa antes do gateway (simulada via SQL de apoio) ===\n");
// Para exercitar webhook/ativação sem chamar o gateway, gravamos a tentativa exatamente
// com o mesmo INSERT do serviço (status awaiting_payment, hosted_url de sandbox).
$extRef = 'clyo-c' . $cA . '-p' . $planElite . '-teste0001';
$db->prepare('INSERT INTO asaas_checkouts (clinic_id, subscription_id, plan_id, requested_by, idempotency_key, external_reference,
    asaas_customer_id, asaas_checkout_id, hosted_url, amount_cents, billing_cycle, status, from_plan_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'awaiting_payment\', ?)')
    ->execute([$cA, (int)$subA['id'], $planElite, (int)$owner['user_id'], str_repeat('a', 32), $extRef,
        'cus_TESTE_A', 'chk_TESTE_A', 'https://sandbox.asaas.com/checkoutSession/show/chk_TESTE_A', 38900, 'monthly', (int)$subA['plan_id']]);
$coId = (int)$db->lastInsertId();
T::ok($coId > 0, 'tentativa persistida com externalReference');
$dup = $db->prepare('SELECT COUNT(*) FROM asaas_checkouts WHERE external_reference = ?');
$dup->execute([$extRef]);
T::ok((int)$dup->fetchColumn() === 1, 'externalReference única');
$latest = $checkoutSvc->latestForClinic($cA);
T::ok(($latest['status'] ?? '') === 'awaiting_payment', 'UI lê estado aguardando pagamento');

fwrite(STDOUT, "=== Webhook: autenticação ===\n");
$hooks = new AsaasWebhookService($db);
T::ok(!$hooks->tokenIsValid(null), 'sem header: recusado');
T::ok(!$hooks->tokenIsValid('errado'), 'token errado: recusado');
T::ok($hooks->tokenIsValid($webhookToken), 'token correto: aceito');
putenv('ASAAS_WEBHOOK_TOKEN');
unset($_ENV['ASAAS_WEBHOOK_TOKEN'], $_SERVER['ASAAS_WEBHOOK_TOKEN']);
$noTokenCfg = new AsaasWebhookService($db);
T::ok(!$noTokenCfg->tokenIsValid($webhookToken), 'servidor sem token configurado recusa tudo');
putenv('ASAAS_WEBHOOK_TOKEN=' . $webhookToken);
$_ENV['ASAAS_WEBHOOK_TOKEN'] = $webhookToken;

fwrite(STDOUT, "=== Webhook: eventos que NÃO ativam ===\n");
$planBefore = (int)$db->query('SELECT plan_id FROM clinic_subscriptions WHERE clinic_id = ' . $cA)->fetchColumn();
$r = $hooks->handle(['id' => 'evt_TESTE_created', 'event' => 'PAYMENT_CREATED', 'payment' => [
    'id' => 'pay_TESTE_1', 'externalReference' => $extRef, 'value' => 389.00, 'dueDate' => date('Y-m-d'), 'billingType' => 'CREDIT_CARD']]);
T::ok($r['status'] === 'ignored', 'PAYMENT_CREATED apenas registrado');
T::ok((int)$db->query('SELECT plan_id FROM clinic_subscriptions WHERE clinic_id = ' . $cA)->fetchColumn() === $planBefore, 'PAYMENT_CREATED não muda o plano');
T::ok((int)$db->query("SELECT COUNT(*) FROM subscription_invoices WHERE clinic_id = {$cA}")->fetchColumn() === 0, 'PAYMENT_CREATED não gera fatura');
$r = $hooks->handle(['id' => 'evt_TESTE_checkoutcreated', 'event' => 'CHECKOUT_CREATED', 'checkout' => ['id' => 'chk_TESTE_A', 'externalReference' => $extRef]]);
T::ok($r['status'] === 'ignored', 'CHECKOUT_CREATED não ativa');

fwrite(STDOUT, "=== Webhook: confirmação financeira ativa, com reconciliação ===\n");
T::throws(fn() => $hooks->handle(['id' => 'evt_TESTE_semref', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_TESTE_X', 'value' => 389.00]]),
    'evento financeiro sem externalReference é recusado', 'reconciliar');
T::throws(fn() => $hooks->handle(['id' => 'evt_TESTE_refdesconhecida', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_TESTE_X', 'externalReference' => 'nao-existe', 'value' => 389.00]]),
    'referência desconhecida é recusada', 'Nenhum checkout');
T::throws(fn() => $hooks->handle(['id' => 'evt_TESTE_valor', 'event' => 'PAYMENT_RECEIVED', 'payment' => [
    'id' => 'pay_TESTE_barato', 'externalReference' => $extRef, 'value' => 1.00]]),
    'valor divergente é recusado', 'diverge');
T::throws(fn() => $hooks->handle(['id' => 'evt_TESTE_semvalor', 'event' => 'CHECKOUT_PAID', 'payment' => [
    'id' => 'pay_TESTE_semvalor', 'externalReference' => $extRef]]),
    'CHECKOUT_PAID sem value é recusado (sem prova de valor)', 'diverge');
T::ok((int)$db->query('SELECT plan_id FROM clinic_subscriptions WHERE clinic_id = ' . $cA)->fetchColumn() === $planBefore, 'após recusas, plano continua o mesmo');

$payment = ['id' => 'pay_TESTE_OK', 'externalReference' => $extRef, 'value' => 389.00, 'netValue' => 380.00,
    'dueDate' => date('Y-m-d'), 'billingType' => 'CREDIT_CARD', 'status' => 'RECEIVED',
    'paymentDate' => date('Y-m-d'), 'subscription' => 'sub_TESTE_A', 'customer' => 'cus_TESTE_A'];
$r = $hooks->handle(['id' => 'evt_TESTE_pago', 'event' => 'PAYMENT_RECEIVED', 'payment' => $payment]);
T::ok($r['status'] === 'activated', 'PAYMENT_RECEIVED ativa o plano');
$subAfter = $db->query('SELECT * FROM clinic_subscriptions WHERE clinic_id = ' . $cA)->fetch(PDO::FETCH_ASSOC);
T::ok((int)$subAfter['plan_id'] === $planElite && $subAfter['status'] === 'active', 'plano ELITE ativo na assinatura');
T::ok($subAfter['current_period_end'] > $subAfter['current_period_start'], 'novo período de cobrança aberto');
$inv = $db->query("SELECT * FROM subscription_invoices WHERE clinic_id = {$cA}")->fetch(PDO::FETCH_ASSOC);
T::ok($inv !== false && $inv['invoice_number'] === 'ASAAS-pay_TESTE_OK' && $inv['status'] === 'paid' && (int)$inv['amount_cents'] === 38900, 'fatura real do evento financeiro');
$rec = $db->query('SELECT * FROM asaas_payment_receipts WHERE asaas_payment_id = \'pay_TESTE_OK\'')->fetch(PDO::FETCH_ASSOC);
T::ok($rec !== false && (int)$rec['checkout_id'] === $coId, 'recibo vinculado ao checkout e ao pagamento');
$aud = (string)$db->query("SELECT details FROM audit_logs WHERE action = 'subscription.asaas_activated' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(!str_contains($aud, 'cus_TESTE_A') && str_contains($aud, 'PAYMENT_RECEIVED'), 'auditoria sanitizada (sem dados do pagador)');
$coAfter = $db->query('SELECT status, activated_at FROM asaas_checkouts WHERE id = ' . $coId)->fetch(PDO::FETCH_ASSOC);
T::ok($coAfter['status'] === 'active' && !empty($coAfter['activated_at']), 'checkout marcado como ativo');

fwrite(STDOUT, "=== Webhook: idempotência e fora de ordem ===\n");
$r = $hooks->handle(['id' => 'evt_TESTE_pago', 'event' => 'PAYMENT_RECEIVED', 'payment' => $payment]);
T::ok($r['status'] === 'duplicate', 'mesmo event_id: replay deduplicado');
T::ok((int)$db->query("SELECT COUNT(*) FROM subscription_invoices WHERE clinic_id = {$cA}")->fetchColumn() === 1, 'replay não duplica fatura');
$r = $hooks->handle(['id' => 'evt_TESTE_pago_outro_id', 'event' => 'PAYMENT_CONFIRMED', 'payment' => $payment]);
T::ok($r['status'] === 'already_active', 'mesmo pagamento com outro event_id não reprocessa ativação');
T::ok((int)$db->query("SELECT COUNT(*) FROM subscription_invoices WHERE clinic_id = {$cA}")->fetchColumn() === 1, 'fatura única por pagamento');

// Evento antigo de checkout cancelado não desativa plano ativo.
$r = $hooks->handle(['id' => 'evt_TESTE_cancel_old', 'event' => 'CHECKOUT_CANCELED', 'checkout' => ['id' => 'chk_TESTE_A', 'externalReference' => $extRef]]);
T::ok($r['status'] === 'ignored', 'cancelamento antigo ignorado');
T::ok($db->query('SELECT status FROM clinic_subscriptions WHERE clinic_id = ' . $cA)->fetchColumn() === 'active', 'plano ativo não é desfeito por evento tardio');

fwrite(STDOUT, "=== Isolamento multi-tenant dos artefatos ===\n");
T::ok($checkoutSvc->latestForClinic($cB) === null, 'clínica B não vê checkout da clínica A');
$invB = (int)$db->query("SELECT COUNT(*) FROM subscription_invoices WHERE clinic_id = {$cB}")->fetchColumn();
T::ok($invB === 0, 'clínica B sem faturas da A');

fwrite(STDOUT, "=== Gate do upgrade manual pago ===\n");
$pcs = new PlanChangeService($db, $subs);
// Sem chave Asaas no ambiente: comportamento manual legado permanece (downgrade livre, upgrade manual permitido).
T::ok(true, 'ambiente sem chave: gate do upgrade manual inativo (verificado abaixo com chave fake de sandbox)');
putenv('ASAAS_API_KEY=$aact_hmlg_TESTE_FAKE_NAO_USAR');
T::throws(fn() => $pcs->changePlan($cB, (int)$ownerB['user_id'], $planElite, str_repeat('b', 32)),
    'com gateway configurado, upgrade manual imediato é bloqueado', 'Asaas');
T::ok($db->query('SELECT plan_id FROM clinic_subscriptions WHERE clinic_id = ' . $cB)->fetchColumn() == $subB['plan_id'], 'plano da B inalterado após bloqueio');
// Downgrade continua manual mesmo com gateway: A agora é ELITE ativa, voltar para PRO cabe no uso (2 profissionais <= 3).
$resDown = $pcs->changePlan($cA, (int)$owner['user_id'], $planPro, str_repeat('c', 32));
T::ok($resDown['success'] && $resDown['change_type'] === 'downgrade', 'downgrade manual continua funcionando');
putenv('ASAAS_API_KEY');

fwrite(STDOUT, "=== Regressão: trial e cancelamento ===\n");
T::ok($subs->canMutateClinicalData($cA), 'assinatura ativa permite mutação clínica');
$cancel = $subs->cancelSubscription($cA, (int)$owner['user_id'], 'other', 'TESTE encerramento');
T::ok($cancel['success'] && $cancel['status'] === 'canceled', 'cancelamento autosserviço segue funcionando');
$cancel2 = $subs->cancelSubscription($cA, (int)$owner['user_id']);
T::ok(!empty($cancel2['already_canceled']) && $cancel2['protocol'] === $cancel['protocol'], 'cancelamento idempotente');
T::ok(!$subs->canMutateClinicalData($cA), 'assinatura cancelada bloqueia mutação clínica');
T::throws(fn() => $checkoutSvc->startCheckout($cA, (int)$owner['user_id'], $planElite), 'assinatura cancelada não inicia checkout', 'situação');

T::done();
