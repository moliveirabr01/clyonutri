<?php
/**
 * v54.48 — NI2-727 slice 1: testes LIVE contra https://api-sandbox.asaas.com/v3.
 * Requer ASAAS_API_KEY (ou ASAAS_SANDBOX_API_KEY) de sandbox no ambiente do processo.
 * Sem chave: todos os casos são BLOCKED (nunca PASS por inspeção).
 * A chave NUNCA é impressa, gravada em log ou empacotada. Nomes/e-mails/CPF são de TESTE do sandbox.
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5448-live.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Billing\AsaasCheckoutService;
use ClyoNutri\Domain\Billing\AsaasClient;
use ClyoNutri\Domain\Billing\AsaasConfig;
use ClyoNutri\Domain\Billing\AsaasException;
use ClyoNutri\Domain\Subscription\SubscriptionService;

$root = dirname(__DIR__);
$key = getenv('ASAAS_API_KEY') ?: getenv('ASAAS_SANDBOX_API_KEY') ?: '';
if ($key === '') {
    // Sem chave real: o caso é BLOCKED, nunca PASS. O gate não executa esta suíte sem credencial.
    fwrite(STDOUT, "  BLOCKED  live-asaas-customer: ASAAS_API_KEY ausente no ambiente\n");
    fwrite(STDOUT, "  BLOCKED  live-asaas-checkout: ASAAS_API_KEY ausente no ambiente\n");
    fwrite(STDOUT, "  BLOCKED  live-asaas-bad-auth: ASAAS_API_KEY ausente no ambiente\n");
    fwrite(STDOUT, "0 PASS, 0 FAIL\n");
    fwrite(STDOUT, "3 BLOCKED (dependência externa: credencial sandbox ausente)\n");
    exit(0);
}
putenv('ASAAS_API_KEY=' . $key);
$_ENV['ASAAS_API_KEY'] = $key;
putenv('ASAAS_ENV=sandbox');
putenv('APP_URL=https://clyonutri-teste.invalid');
$_ENV['APP_URL'] = 'https://clyonutri-teste.invalid';
unset($_ENV['ASAAS_BASE_URL'], $_SERVER['ASAAS_BASE_URL']);
putenv('ASAAS_BASE_URL');

$db = Connection::getInstance();
$subs = new SubscriptionService($db);
$client = AsaasClient::fromEnv();
$evidence = ['sandbox' => AsaasConfig::SANDBOX_BASE_URL, 'cases' => []];

// ---------------------------------------------------------------- 1) Cliente real no sandbox
$unique = bin2hex(random_bytes(4));
try {
    $customer = $client->createCustomer([
        'name' => 'Clinica TESTE NI2-727 ' . $unique,
        'cpfCnpj' => '24971563792', // CPF de exemplo da própria documentação do sandbox
        'email' => 'teste.ni2727.' . $unique . '@example.com',
        'phone' => '11999990000',
        'mobilePhone' => '11999990000',
        'address' => 'Avenida Paulista',
        'addressNumber' => '1000',
        'complement' => 'Sala 1',
        'province' => 'Bela Vista',
        'postalCode' => '01310100',
        'externalReference' => 'clyo-teste-' . $unique,
        'notificationDisabled' => true,
    ]);
    $ok = !empty($customer['id']) && str_starts_with((string)$customer['id'], 'cus_');
    T::ok($ok, 'live: cliente criado no sandbox Asaas');
    $evidence['cases']['create_customer'] = ['status' => $ok ? 'PASS' : 'FAIL', 'customer_id' => $customer['id'] ?? null];
} catch (AsaasException $e) {
    T::ok(false, 'live: cliente criado no sandbox Asaas (' . $e->getMessage() . ')');
    $evidence['cases']['create_customer'] = ['status' => 'FAIL', 'error' => $e->getMessage(), 'http' => $e->httpStatus];
    $customer = null;
}

// ---------------------------------------------------------------- 2) Checkout RECURRENT real no sandbox
if (!empty($customer['id'])) {
    try {
        $checkout = $client->createCheckout([
            'billingTypes' => ['CREDIT_CARD'],
            'chargeTypes' => ['RECURRENT'],
            'minutesToExpire' => 60,
            'externalReference' => 'clyo-teste-' . $unique,
            'customer' => $customer['id'],
            'callback' => [
                'successUrl' => 'https://clyonutri-teste.invalid/configuracoes/assinatura?asaas=retorno',
                'cancelUrl' => 'https://clyonutri-teste.invalid/configuracoes/assinatura?asaas=cancelado',
                'expiredUrl' => 'https://clyonutri-teste.invalid/configuracoes/assinatura?asaas=expirado',
            ],
            'items' => [[
                'name' => 'Clyo Nutri TESTE',
                'description' => 'Assinatura mensal de TESTE sandbox NI2-727 (valor minimo do gateway)',
                'quantity' => 1,
                'value' => 5.00,
                'externalReference' => 'clyo-teste-' . $unique,
            ]],
            'subscription' => ['cycle' => 'MONTHLY', 'nextDueDate' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')],
        ]);
        $ok = !empty($checkout['id']) && !empty($checkout['link'])
            && str_starts_with((string)$checkout['link'], 'https://sandbox.asaas.com/');
        T::ok($ok, 'live: checkout RECURRENT criado com link hospedado de sandbox');
        T::ok(($checkout['status'] ?? '') === 'ACTIVE', 'live: checkout nasce ACTIVE (aguardando pagamento, não pago)');
        $evidence['cases']['create_checkout'] = [
            'status' => $ok ? 'PASS' : 'FAIL',
            'checkout_id' => $checkout['id'] ?? null,
            'checkout_status' => $checkout['status'] ?? null,
            'hosted_link_domain' => isset($checkout['link']) ? parse_url((string)$checkout['link'], PHP_URL_HOST) : null,
        ];
    } catch (AsaasException $e) {
        T::ok(false, 'live: checkout RECURRENT (' . $e->getMessage() . ')');
        $evidence['cases']['create_checkout'] = ['status' => 'FAIL', 'error' => $e->getMessage(), 'http' => $e->httpStatus];
    }
}

// ---------------------------------------------------------------- 3) Chave inválida é recusada (negativo)
try {
    putenv('ASAAS_API_KEY=' . '$aact_hmlg_CHAVE_INVALIDA_TESTE');
    $_ENV['ASAAS_API_KEY'] = '$aact_hmlg_CHAVE_INVALIDA_TESTE';
    $badClient = AsaasClient::fromEnv();
    // Telefone válido evita 400 de validação: queremos isolar a recusa por autenticação.
    $badClient->createCustomer(['name' => 'TESTE negativo', 'cpfCnpj' => '24971563792', 'phone' => '11999990000']);
    T::ok(false, 'live: chave inválida deveria ser recusada');
    $evidence['cases']['bad_auth'] = ['status' => 'FAIL', 'error' => 'gateway aceitou chave inválida'];
} catch (AsaasException $e) {
    $ok = $e->httpStatus === 401;
    T::ok($ok, 'live: chave inválida recusada com HTTP 401');
    $evidence['cases']['bad_auth'] = ['status' => $ok ? 'PASS' : 'FAIL', 'http' => $e->httpStatus];
} finally {
    putenv('ASAAS_API_KEY=' . $key);
    $_ENV['ASAAS_API_KEY'] = $key;
}

// ---------------------------------------------------------------- 4) Fluxo do serviço: checkout persistido + URL real, plano NÃO muda
$c = Fx::clinic($db, 'Clínica TESTE live Asaas');
$db->prepare('UPDATE clinics SET document = ?, email = ?, phone = ?, address_street = ?, address_number = ?, address_complement = ?, address_neighborhood = ?, address_city = ?, address_state = ?, address_zip = ? WHERE id = ?')
    ->execute(['24971563792', 'teste.ni2727.' . $unique . '@example.com', '11999990000',
        'Avenida Paulista', '1000', 'Sala 1', 'Bela Vista', 'São Paulo', 'SP', '01310100', $c]);
$owner = Fx::user($db, $c, 'Owner TESTE live', 'owner');
$subBefore = $subs->getClinicSubscription($c);
$planElite = (int)$db->query("SELECT id FROM saas_plans WHERE code = 'ELITE-03'")->fetchColumn();
$svc = new AsaasCheckoutService($db, $subs);
try {
    $result = $svc->startCheckout($c, (int)$owner['user_id'], $planElite, str_repeat('d', 32));
    $ok = !empty($result['hosted_url']) && str_starts_with($result['hosted_url'], 'https://sandbox.asaas.com/');
    T::ok($ok, 'live: startCheckout devolve URL hospedada de sandbox');
    $row = $db->query('SELECT * FROM asaas_checkouts WHERE clinic_id = ' . $c)->fetch(PDO::FETCH_ASSOC);
    T::ok($row !== false && $row['status'] === 'awaiting_payment' && $row['external_reference'] === $result['external_reference'], 'live: tentativa persistida e reconciliável');
    $subNow = $db->query('SELECT plan_id, status FROM clinic_subscriptions WHERE clinic_id = ' . $c)->fetch(PDO::FETCH_ASSOC);
    T::ok((int)$subNow['plan_id'] === (int)$subBefore['plan_id'] && $subNow['status'] === 'trialing', 'live: plano NÃO muda ao criar checkout');
    // Idempotência: mesma chave devolve o mesmo checkout sem nova chamada.
    $again = $svc->startCheckout($c, (int)$owner['user_id'], $planElite, str_repeat('d', 32));
    T::ok($again['reused'] && $again['external_reference'] === $result['external_reference'], 'live: mesma chave reutiliza o checkout');
    T::ok((int)$db->query('SELECT COUNT(*) FROM asaas_checkouts WHERE clinic_id = ' . $c)->fetchColumn() === 1, 'live: nenhuma duplicidade no gateway nem no banco');
    $evidence['cases']['service_flow'] = ['status' => 'PASS', 'checkout_id' => $row['asaas_checkout_id'],
        'external_reference' => $row['external_reference'], 'plan_unchanged' => true];
} catch (\Throwable $e) {
    T::ok(false, 'live: fluxo do serviço (' . $e->getMessage() . ')');
    $evidence['cases']['service_flow'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
}

$dir = '/workspace/release/clyonutri-ni2-727-asaas-2026-11-05';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
file_put_contents($dir . '/live-sandbox-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
T::done();
