<?php
/**
 * v54.49 — cadastro de cobrança do consultório.
 * Com LIVE_ASAAS=1 e a chave sandbox no ambiente, exercita cliente existente, atualização
 * e checkout reais. Sem LIVE_ASAAS, faz apenas as verificações locais (sem HTTP).
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5449.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Billing\AsaasCheckoutService;
use ClyoNutri\Domain\Settings\ProfessionalProfile;
use ClyoNutri\Domain\Subscription\SubscriptionService;

$db = Connection::getInstance();
$clinic = Fx::clinic($db, 'Consultório TESTE cobrança');
$owner = Fx::user($db, $clinic, 'Owner TESTE cobrança', 'owner');
$member = Fx::user($db, $clinic, 'Membro TESTE cobrança', 'nutritionist');
$db->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute(['(21) 98127-1767', $owner['user_id']]);
$plan = (int)$db->query("SELECT id FROM saas_plans WHERE code = 'START-01'")->fetchColumn();
$subs = new SubscriptionService($db);
$subs->getClinicSubscription($clinic);
putenv('ASAAS_ENV=sandbox');
putenv('ASAAS_API_KEY=$aact_hmlg_TESTE_LOCAL_NAO_USAR');
putenv('APP_URL=https://clyonutri-teste.invalid');
$live = getenv('LIVE_ASAAS') === '1';
if ($live) {
    $key = getenv('ASAAS_SANDBOX_API_KEY') ?: getenv('ASAAS_API_KEY') ?: '';
    if ($key === '') throw new RuntimeException('LIVE_ASAAS=1 exige uma credencial sandbox.');
    putenv('ASAAS_API_KEY=' . $key);
    $_ENV['ASAAS_API_KEY'] = $key;
}

fwrite(STDOUT, "=== Pré-validação sem chamada ao Asaas ===\n");
$svc = new AsaasCheckoutService($db, $subs);
try {
    $svc->startCheckout($clinic, (int)$owner['user_id'], $plan);
    T::ok(false, 'cadastro vazio lança DomainException');
} catch (DomainException $e) {
    T::ok(str_contains($e->getMessage(), 'Configurações → Perfil → Dados do consultório para cobrança'), 'erro aponta o caminho em Configurações');
    T::ok(str_contains($e->getMessage(), 'CPF/CNPJ') && str_contains($e->getMessage(), 'CEP') && str_contains($e->getMessage(), 'Nenhuma cobrança'), 'erro lista campos ausentes e confirma ausência de cobrança');
}
T::ok((int)$db->query('SELECT COUNT(*) FROM asaas_checkouts WHERE clinic_id = ' . $clinic)->fetchColumn() === 0, 'cadastro incompleto não persiste tentativa failed');

fwrite(STDOUT, "=== Fallback do celular do profissional ===\n");
$db->prepare('UPDATE clinics SET document = ?, email = ?, phone = NULL, address_street = ?, address_number = ?, address_complement = ?, address_neighborhood = ?, address_city = ?, address_state = ?, address_zip = ? WHERE id = ?')
    ->execute(['24.971.563/792', 'teste.cobranca@example.com', 'Avenida Paulista', '1000', 'Sala 1', 'Bela Vista', 'São Paulo', 'SP', '01310-100', $clinic]);
$method = new ReflectionMethod(AsaasCheckoutService::class, 'customerPayload');
$payload = $method->invoke($svc, $clinic, (int)$owner['user_id']);
T::ok($payload['phone'] === '21981271767' && $payload['mobilePhone'] === '21981271767', 'clinics.phone vazio usa users.phone do usuário ativo');
T::ok($payload['cpfCnpj'] === '24971563792' && $payload['postalCode'] === '01310100', 'documento e CEP normalizados');
T::ok($payload['city'] === 'São Paulo' && $payload['state'] === 'SP' && $payload['address'] === 'Avenida Paulista', 'payload contém endereço completo, inclusive cidade e UF');

fwrite(STDOUT, "=== Configurações: gestão salva dados da clínica ===\n");
$profileSvc = new ProfessionalProfile($db);
$profileInput = function (array $current, array $changes = []): array {
    $input = $current + ['revision' => ProfessionalProfile::revision($current)];
    foreach (['name', 'clinic_name', 'specialty', 'bio', 'email', 'phone', 'crn', 'crn_region'] as $field) $input[$field] = (string)($input[$field] ?? '');
    return array_merge($input, $changes);
};
Fx::act($owner);
$current = $profileSvc->read();
T::ok(array_key_exists('clinic_document', $current) && array_key_exists('clinic_address_zip', $current), 'perfil lê identidade e endereço do consultório');
$input = $profileInput($current, ['clinic_phone' => '(21) 4002-8922', 'clinic_address_number' => '2000']);
$profileSvc->save($input);
$row = $db->query('SELECT * FROM clinics WHERE id = ' . $clinic)->fetch(PDO::FETCH_ASSOC);
T::ok($row['document'] === '24971563792' && $row['phone'] === '(21) 4002-8922' && $row['address_number'] === '2000', 'CPF, telefone e número persistem na clínica');
T::ok($row['email'] === 'teste.cobranca@example.com' && $row['address_city'] === 'São Paulo' && $row['address_zip'] === '01310100', 'e-mail, cidade e CEP persistem na clínica');

fwrite(STDOUT, "=== Fallback persistido ao salvar perfil da gestão ===\n");
$db->prepare('UPDATE clinics SET phone = NULL WHERE id = ?')->execute([$clinic]);
$current = $profileSvc->read();
$input = $profileInput($current, ['clinic_phone' => '']);
$profileSvc->save($input);
T::ok($db->query('SELECT phone FROM clinics WHERE id = ' . $clinic)->fetchColumn() === '(21) 98127-1767', 'salvar com telefone da clínica vazio aproveita o celular do perfil');

fwrite(STDOUT, "=== RBAC ===\n");
Fx::act($member);
$current = $profileSvc->read();
$input = $profileInput($current, ['clinic_address_city' => 'Rio de Janeiro']);
T::throws(fn() => $profileSvc->save($input), 'membro comum não altera dados de cobrança', 'Somente a gestão');
T::throws(fn() => $profileSvc->save($profileInput($current, ['clinic_document' => ''])), 'documento obrigatório também para membro sem permissão', 'CPF (11 dígitos) ou CNPJ (14 dígitos)');
$current = $profileSvc->read();
T::throws(fn() => $profileSvc->save($profileInput($current, ['clinic_document' => ''])), 'documento obrigatório ao salvar perfil', 'CPF (11 dígitos) ou CNPJ (14 dígitos)');
T::ok($db->query('SELECT document FROM clinics WHERE id = ' . $clinic)->fetchColumn() === '24971563792', 'salvar sem documento não apaga o cadastro');
T::ok($db->query('SELECT address_city FROM clinics WHERE id = ' . $clinic)->fetchColumn() === 'São Paulo', 'tentativa sem permissão não altera a clínica');

fwrite(STDOUT, "=== Validações ao salvar ===\n");
Fx::act($owner);
foreach (['clinic_document' => ['123', 'CPF (11 dígitos)'], 'clinic_address_zip' => ['123', 'CEP válido'], 'clinic_email' => ['invalido', 'e-mail válido']] as $field => [$value, $expected]) {
    $current = $profileSvc->read();
    $input = $profileInput($current, [$field => $value]);
    T::throws(fn() => $profileSvc->save($input), "recusa {$field} inválido", $expected);
    if ($field === 'clinic_document') T::ok(str_contains((string)$expected, 'CPF'), 'validação exige CPF/CNPJ mesmo com e-mail preenchido');
}
if ($live) {
    fwrite(STDOUT, "=== LIVE: atualiza cliente Asaas existente e cria checkout ===\n");
    $liveClinic = Fx::clinic($db, 'Consultório TESTE v54.49 live');
    $liveOwner = Fx::user($db, $liveClinic, 'Owner TESTE v54.49 live', 'owner');
    $liveSubs = new SubscriptionService($db);
    $liveSubs->getClinicSubscription($liveClinic);
    $unique = bin2hex(random_bytes(4));
    $client = new \ClyoNutri\Domain\Billing\AsaasClient(\ClyoNutri\Domain\Billing\AsaasConfig::fromEnv());
    $customer = $client->createCustomer([
        'name' => 'TESTE v54.49 antes ' . $unique,
        'cpfCnpj' => '24971563792',
        'email' => 'teste.v5449.' . $unique . '@example.com',
        'notificationDisabled' => true,
    ]);
    T::ok(str_starts_with((string)($customer['id'] ?? ''), 'cus_'), 'live: cliente de teste criado sem telefone/endereço');
    $db->prepare('INSERT INTO asaas_customers (clinic_id, asaas_customer_id) VALUES (?, ?)')->execute([$liveClinic, $customer['id']]);
    $db->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute(['11999990000', $liveOwner['user_id']]);
    $db->prepare('UPDATE clinics SET document = ?, email = ?, phone = NULL, address_street = ?, address_number = ?, address_complement = ?, address_neighborhood = ?, address_city = ?, address_state = ?, address_zip = ? WHERE id = ?')
        ->execute(['24971563792', 'teste.v5449.' . $unique . '@example.com', 'Avenida Paulista', '1000', 'Sala 1', 'Bela Vista', 'São Paulo', 'SP', '01310100', $liveClinic]);
    $liveSvc = new AsaasCheckoutService($db, $liveSubs);
    try {
        $result = $liveSvc->startCheckout($liveClinic, (int)$liveOwner['user_id'], $plan, str_repeat('e', 32));
        T::ok(str_starts_with($result['hosted_url'], 'https://sandbox.asaas.com/'), 'live: cliente existente atualizado; checkout RECURRENT criado');
        T::ok($result['status'] === 'awaiting_payment', 'live: checkout aguarda pagamento, sem ativação');
        $row = $db->query('SELECT * FROM asaas_checkouts WHERE clinic_id = ' . $liveClinic)->fetch(PDO::FETCH_ASSOC);
        T::ok($row['asaas_customer_id'] === $customer['id'] && $row['asaas_checkout_id'] !== '', 'live: mesmo cliente Asaas vinculado ao novo checkout');
        T::ok($liveSubs->getClinicSubscription($liveClinic)['status'] === 'trialing', 'live: plano continua em trial após criar checkout');
        $dir = '/workspace/release/clyonutri-v54-49-asaas-clinic-fields-2026-11-05';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents($dir . '/live-update-customer-evidence.json', json_encode([
            'environment' => 'Asaas Sandbox', 'existing_customer_updated' => true,
            'checkout_created' => true, 'checkout_status' => $row['status'], 'plan_unchanged' => true,
            'hosted_url_domain' => parse_url($result['hosted_url'], PHP_URL_HOST),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    } catch (\ClyoNutri\Domain\Billing\AsaasException $e) {
        T::ok(false, 'live: checkout após atualizar cliente (' . $e->getMessage() . ')');
    }
}
T::done();
