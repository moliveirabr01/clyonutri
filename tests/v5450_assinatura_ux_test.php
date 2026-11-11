<?php
/** v54.50 — Assinatura enxuta: um caminho de upgrade, um aviso pendente, resumo coerente (render das views). */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5450.sqlite');
require __DIR__ . '/_v54_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$plans = [
  ['id' => 1, 'name' => 'Start', 'price_cents' => 9900, 'max_professionals' => 1, 'max_patients' => 100, 'storage_mb' => 1024],
  ['id' => 2, 'name' => 'Profissional', 'price_cents' => 19900, 'max_professionals' => 3, 'max_patients' => 0, 'storage_mb' => 5120],
  ['id' => 3, 'name' => 'Clínica', 'price_cents' => 39900, 'max_professionals' => 0, 'max_patients' => 0, 'storage_mb' => 20480],
];
$sub = ['id' => 1, 'status' => 'active', 'plan_name' => 'Start', 'plan_code' => 'start', 'billing_cycle' => 'monthly', 'price_cents' => 9900,
  'trial_ends_at' => '2026-10-22 00:00:00', 'current_period_end' => '2026-12-05 00:00:00', 'started_at' => '2026-10-08 00:00:00',
  'max_professionals' => 1, 'max_patients' => 100, 'storage_mb' => 1024,
  'usage' => ['active_professionals' => 1, 'active_patients' => 3, 'storage_mb' => 2],
  'invoices' => [['invoice_number' => 'F-1', 'due_date' => '2026-11-05', 'paid_at' => '2026-11-05', 'amount_cents' => 9900, 'payment_method' => 'CREDIT_CARD', 'status' => 'paid']]];
$opts = [];
foreach ($plans as $p) { $opts[] = ['plan' => $p, 'current' => $p['id'] === 1, 'direction' => $p['id'] === 1 ? 'lateral' : 'upgrade', 'blockers' => [], 'price_diff_cents' => $p['price_cents'] - 9900]; }
$base = ['subscription' => $sub, 'reasons' => [], 'planOverview' => ['subscription' => $sub, 'options' => $opts, 'can_change' => true, 'idempotency_key' => 'k', 'history' => [
    ['effective_at' => '2026-11-05 10:00:00', 'change_type' => 'downgrade', 'from_name' => 'Profissional', 'to_name' => 'Start', 'protocol' => 'P-1']]],
  'deletion' => null, 'deletionAvailable' => true, 'legalParagraphs' => ['Aviso.'], 'flashBlockers' => null];
$render = static function (array $vars): string {
    extract($vars);
    ob_start();
    require dirname(__DIR__) . '/views/settings/subscription.php';
    return (string)ob_get_clean();
};

$pending = ['status' => 'awaiting_payment', 'plan_id' => 2, 'plan_name' => 'Profissional', 'hosted_url' => 'https://sandbox.asaas.com/c/x'];
$h = $render($base + ['asaasOverview' => ['enabled' => true, 'latest' => $pending, 'idempotency_key' => 'a', 'return' => null]]);
T::ok(!str_contains($h, 'Fazer upgrade'), 'Asaas on: sem "Fazer upgrade" manual');
T::ok(!str_contains($h, 'Ainda não existe cobrança automática'), 'Asaas on: sem texto contraditório de cobrança');
T::ok(!str_contains($h, 'via Asaas (Sandbox)'), 'sem card Asaas separado');
T::ok(substr_count($h, 'Continuar pagamento') === 2, 'pendente: aviso único + CTA no card do plano pendente');
T::ok(!str_contains($h, 'Assinar Profissional'), 'pendente: não oferece Assinar o mesmo plano');
T::ok(str_contains($h, 'Assinar Clínica') && str_contains($h, 'action="/configuracoes/assinatura/asaas"'), 'upgrade pelos Planos → checkout Asaas');
T::ok(!str_contains($h, 'Uso hoje'), 'uso não duplicado');
T::ok(str_contains($h, 'Próxima cobrança em 05/12/2026') && !str_contains($h, '22/10/2026'), 'resumo: ativa usa current_period_end');
T::ok(str_contains($h, 'Cartão de crédito') && !str_contains($h, '>CREDIT_CARD<'), 'forma de pagamento legível');
T::ok(str_contains($h, 'Profissional → Start'), 'histórico de trocas legível');
T::ok(str_contains($h, 'href="/termos#reembolso"') && str_contains($h, 'href="/privacidade"'), 'links termos/privacidade');
T::ok(!str_contains($h, 'Voltar às Configurações'), 'um caminho de volta (abas)');
T::ok(substr_count($h, 'nenhuma cobrança é real') <= 1 && str_contains($h, 'asaas-sandbox-badge'), 'sandbox discreto');

$h2 = $render($base + ['asaasOverview' => ['enabled' => false, 'latest' => null, 'idempotency_key' => 'a', 'return' => null]]);
T::ok(str_contains($h2, 'Fazer upgrade') && !str_contains($h2, '/configuracoes/assinatura/asaas'), 'Asaas off: só troca manual');
T::ok(!str_contains($h2, 'asaas-sandbox-badge'), 'Asaas off: sem selo sandbox');
T::done();
