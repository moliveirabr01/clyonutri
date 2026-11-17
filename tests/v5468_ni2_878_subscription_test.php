<?php

declare(strict_types=1);

/**
 * Suíte NI2-878: Fim do teste pela data (sem depender do cron),
 * aviso único, "Pedir ativação" sem Asaas e ativação manual no console (/admin/comercial).
 */
require_once __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\AdminController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Mail\ArrayMailer;
use ClyoNutri\Core\Security\ContactInfo;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Subscription\SubscriptionService;

/** @var PDO $pdo */
$db = $pdo;

// Seed básico: 1 clínica, 1 titular, 1 superadmin, 1 finance_admin, 1 support_agent
$db->exec("INSERT INTO clinics (id, uuid, name, email, status) VALUES (1, 'c1', 'Clínica NI2-878', 'c878@teste.local', 'active')");
$pwHash = password_hash('SenhaForte@2026', PASSWORD_BCRYPT, ['cost' => 10]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (1, 'u1', 'Dra. Helena', 'helena@teste.local', ?, 'nutritionist', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (2, 'u2', 'Super Admin', 'super@teste.local', ?, 'superadmin', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (3, 'u3', 'Finance Admin', 'fin@teste.local', ?, 'finance_admin', 1, 'active')")->execute([$pwHash]);
$db->prepare("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (4, 'u4', 'Support Agent', 'sup@teste.local', ?, 'support_agent', 1, 'active')")->execute([$pwHash]);
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 1, 'owner', 'active'), (1, 2, 'admin', 'active'), (1, 3, 'admin', 'active'), (1, 4, 'admin', 'active')");

// Planos SaaS (caso não existam no seed do bootstrap)
$planCount = (int)$db->query('SELECT COUNT(*) FROM saas_plans')->fetchColumn();
if ($planCount === 0) {
    $db->exec("INSERT INTO saas_plans (id, code, name, billing_cycle, price_cents, max_professionals, max_patients, storage_mb, is_active) VALUES
        (1, 'STARTER-01', 'Starter', 'monthly', 7900, 1, 50, 500, 1),
        (2, 'PRO-02', 'Pro', 'monthly', 14900, 3, 250, 2048, 1)");
}
$proPlan = $db->query("SELECT id, code, price_cents FROM saas_plans WHERE is_active = 1 ORDER BY price_cents DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$proPlanId = (int)$proPlan['id'];

$svc = new SubscriptionService($db);

// 1) Unit test: trialState (trial -> grace no dia 3 -> read_only no dia 4 -> active)
$baseTs = strtotime('2026-11-01 12:00:00');
$trialEndStr = date('Y-m-d H:i:s', $baseTs);
$db->prepare("INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end, trial_ends_at)
    VALUES (1, ?, 'trialing', 'monthly', ?, '2026-10-18 12:00:00', '2026-10-18 12:00:00', ?, ?)")
    ->execute([$proPlanId, (int)$proPlan['price_cents'], $trialEndStr, $trialEndStr]);

$stTrial = $svc->trialState(1, $baseTs - 86400);
assert($stTrial['state'] === 'trial' && !$stTrial['read_only'] && !$stTrial['in_grace'], 'Dia antes do fim deve ser trial');
assert($svc->expiredNotice(1, false, $baseTs - 86400) === null, 'Trial vigente nao deve gerar expiredNotice');

$stGraceDay3 = $svc->trialState(1, $baseTs + (3 * 86400));
assert($stGraceDay3['state'] === 'grace' && $stGraceDay3['in_grace'] && !$stGraceDay3['read_only'], 'Dia 3 apos trial_ends_at deve ser grace');
$noticeGrace = $svc->expiredNotice(1, false, $baseTs + (3 * 86400));
assert($noticeGrace !== null && $noticeGrace['state'] === 'grace' && $noticeGrace['cta_label'] === 'Pedir ativação', 'Notice em grace sem cron nem Asaas');

$stReadOnlyDay4 = $svc->trialState(1, $baseTs + (4 * 86400));
assert($stReadOnlyDay4['state'] === 'read_only' && $stReadOnlyDay4['read_only'] && !$stReadOnlyDay4['in_grace'], 'Dia 4 apos trial_ends_at deve ser read_only');
$noticeRo = $svc->expiredNotice(1, false, $baseTs + (4 * 86400));
assert($noticeRo !== null && $noticeRo['state'] === 'read_only', 'Notice em read_only sem cron');
$noticeRoAsaas = $svc->expiredNotice(1, true, $baseTs + (4 * 86400));
assert($noticeRoAsaas !== null && $noticeRoAsaas['cta_label'] === 'Assinar um plano', 'Notice com Asaas ativo deve oferecer Assinar um plano');

// 2) Sem cron: status ainda 'trialing', expirado ha 1 dia -> grace + escrita permitida + UI mostra "Teste encerrado em"
$oneDayAgo = date('Y-m-d H:i:s', time() - 86400);
$db->prepare("UPDATE clinic_subscriptions SET status = 'trialing', trial_ends_at = ?, current_period_end = ? WHERE clinic_id = 1")
    ->execute([$oneDayAgo, $oneDayAgo]);
$accessGrace = $svc->checkWriteAccess(1);
assert($accessGrace['allowed'] === true && $accessGrace['in_grace'] === true, 'Expirado ha 1 dia sem cron deve permitir escrita em carencia');

$subView = $svc->getClinicSubscription(1);
assert(($subView['trial_state']['state'] ?? '') === 'grace', 'getClinicSubscription deve anexar trial_state=grace');
assert(!empty($subView['expired_notice']), 'getClinicSubscription deve anexar expired_notice');

// Renderiza view settings/subscription.php e valida "Teste encerrado em" + card #pedir-ativacao
$subscription = $subView;
$asaasOverview = ['enabled' => false, 'sandbox' => false, 'latest' => null, 'idempotency_key' => 'k', 'return' => null];
$planOverview = ['subscription' => $subView, 'can_change' => false, 'options' => [['plan' => ['id' => $proPlanId, 'code' => $proPlan['code'], 'name' => 'Pro', 'price_cents' => $proPlan['price_cents'], 'max_professionals' => 3, 'max_patients' => 250, 'storage_mb' => 2048], 'current' => true, 'direction' => 'same', 'blockers' => []]], 'history' => [], 'idempotency_key' => 'k2'];
$pendingActivations = [];
$reasons = $svc->getCancellationReasons();
$deletion = ['is_open' => false];
$deletionAvailable = false;
$legalParagraphs = [];
ob_start();
require __DIR__ . '/../views/settings/subscription.php';
$subHtml = ob_get_clean();
assert(str_contains($subHtml, 'Teste encerrado em '), 'View de assinatura deve exibir Teste encerrado em dd/mm/yyyy');
assert(!str_contains($subHtml, 'Teste até '), 'View de assinatura nao deve exibir Teste ate quando expirado');
assert(str_contains($subHtml, 'id="pedir-ativacao"') && str_contains($subHtml, 'Pedir ativação'), 'View deve exibir Pedir ativacao quando Asaas nao esta configurado');

// 3) Sem cron: status 'trialing', expirado ha 5 dias -> read_only + checkWriteAccess nega escrita
$fiveDaysAgo = date('Y-m-d H:i:s', time() - (5 * 86400));
$db->prepare("UPDATE clinic_subscriptions SET status = 'trialing', trial_ends_at = ?, current_period_end = ? WHERE clinic_id = 1")
    ->execute([$fiveDaysAgo, $fiveDaysAgo]);
$accessRo = $svc->checkWriteAccess(1);
assert($accessRo['allowed'] === false && $accessRo['read_only'] === true, 'Expirado ha 5 dias sem cron deve bloquear escrita (read_only)');

// 4) Pedir ativacao sem Asaas: grava pedido, envia e-mail ao suporte, notifica /admin/notificacoes e audita
$mailer = new ArrayMailer();
$req = $svc->requestManualActivation(1, 1, $proPlanId, 'Pix efetuado', $mailer);
assert($req['status'] === 'pending' && (int)$req['id'] > 0, 'Pedido de ativacao deve ser gravado');
assert(count($mailer->sent) === 1 && $mailer->sent[0]->toAddress === ContactInfo::supportEmail(), 'Deve enviar email para o suporte');
$notifCount = (int)$db->query("SELECT COUNT(*) FROM notification_events WHERE event_type = 'subscription.activation_requested'")->fetchColumn();
assert($notifCount >= 1, 'Deve criar notificacao em notification_events');
$adminReqs = $svc->listActivationRequestsForAdmin(10);
assert(count($adminReqs) >= 1, 'Pedido pendente deve aparecer na lista do admin');

// 5) Console comercial: support_agent recebe 403; finance_admin ativa plano, grava historico, audita e libera escrita
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION['user_id'] = 4;
$_SESSION['user_role'] = 'support_agent';
$_SESSION['clinic_id'] = 1;
$_SESSION['auth_version'] = (int)$db->query('SELECT auth_version FROM users WHERE id = 4')->fetchColumn();
$_POST = ['_csrf_token' => Csrf::token(), 'clinic_id' => 1, 'plan_id' => $proPlanId, 'valid_until' => '2026-12-31', 'reason' => 'Teste suporte'];
$_SERVER['REQUEST_URI'] = '/admin/comercial/ativar';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$adminCtrl = new AdminController($db);
ob_start();
$adminCtrl->activateSubscription();
$supOut = ob_get_clean();
assert(http_response_code() === 403, 'support_agent deve receber 403 ao tentar ativar plano');

// Agora como finance_admin -> ativa e libera escrita na hora
$validUntil = date('Y-m-d', time() + (30 * 86400));
$actRes = $svc->activateManuallyByAdmin(1, $proPlanId, $validUntil, 'Comprovante Pix #991', 3, '127.0.0.1', 'PHPUnit');
assert($actRes['status'] === 'active', 'Ativacao manual deve colocar status=active');

$accessAfter = $svc->checkWriteAccess(1);
assert($accessAfter['allowed'] === true && !$accessAfter['read_only'], 'Ativacao manual deve liberar escrita na hora');
$stAfter = $svc->trialState(1);
assert($stAfter['state'] === 'active', 'trialState deve retornar active apos ativacao manual');

$histCount = (int)$db->query("SELECT COUNT(*) FROM subscription_plan_changes WHERE clinic_id = 1 AND change_type = 'manual_activation'")->fetchColumn();
assert($histCount >= 1, 'Deve registrar historico em subscription_plan_changes');

$auditCount = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE clinic_id = 1 AND action = 'SUBSCRIPTION_MANUALLY_ACTIVATED' AND user_id = 3")->fetchColumn();
assert($auditCount >= 1, 'Deve registrar SUBSCRIPTION_MANUALLY_ACTIVATED com o ator em audit_logs');

$pendingAfter = $svc->pendingActivationRequestsForClinic(1);
assert($pendingAfter === [], 'Pedido pendente deve ser marcado como activated');

echo "NI2-878 OK: trialState, expiredNotice, pedir-ativacao, RBAC 403 e ativacao manual validados.\n";
