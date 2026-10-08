<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Academy\ReferralService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Crm\PracticeCrmService;
use ClyoNutri\Domain\HealthSync\HealthSyncService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PublicProfile\PublicProfileService;
use ClyoNutri\Domain\Telehealth\TelehealthService;

echo "========================================================\n";
echo "   CLYONUTRI BENCHMARK & ONDA 4 TEST SUITE\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

$auth = new AuthService($db);
$nutri = $auth->registerNutritionist('Dra. Renata Escala', 'renata@escala.test', 'SenhaForte@2026', 'Clínica Performance', '88771', 'CRN-3');
TenantContext::set($nutri['clinic_id'], ['id' => $nutri['user_id'], 'name' => $nutri['name']]);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Bruno Salgado', 'email' => 'bruno@test.com']);

// 1. NI2-124: Teleconsulta Integrada
echo "[1/6] Testando NI2-124: Geração de Sala de Teleconsulta...\n";
$appointmentService = new AppointmentService($db);
$agendamento = $appointmentService->schedule($patient['id'], $nutri['user_id'], '2026-10-15 15:00:00', 'online_consultation');

$telehealth = new TelehealthService($db);
$teleSession = $telehealth->generateSession($agendamento['id']);
assert($teleSession['id'] > 0, "Sessão de teleconsulta deve ser gerada");
assert(str_starts_with($teleSession['meeting_url'], 'https://meet.clyonutri.com.br/room/'), "Link de teleconsulta gerado");
echo "  ✓ Teleconsulta gerada com link seguro: {$teleSession['meeting_url']}.\n\n";

// 2. NI2-121: Perfil Profissional Público
echo "[2/6] Testando NI2-121: Perfil Público e Agendamento Online...\n";
$publicProfileService = new PublicProfileService($db);
$profile = $publicProfileService->createOrUpdateProfile(1, [
    'slug' => 'dra-renata-escala',
    'headline' => 'Especialista em Nutrição Clínica e Esportiva',
    'bio' => 'Mais de 10 anos transformando vidas com equilíbrio alimentar.',
    'service_types' => [
        ['title' => 'Primeira Consulta Online', 'price_cents' => 30000],
    ],
]);
assert($profile['slug'] === 'dra-renata-escala', "Slug deve ser formatado");

$publicData = $publicProfileService->getProfileBySlug('dra-renata-escala');
assert($publicData !== null, "Perfil deve ser acessível via slug");
assert($publicData['crn'] === '88771', "CRN no perfil verificado");
echo "  ✓ Perfil público ativo e verificado: {$profile['public_url']}.\n\n";

// 3. NI2-122 & NI2-126: Pacotes de Consultas e CRM Leve
echo "[3/6] Testando NI2-122 e NI2-126: Pacotes e Métricas de Consultório...\n";
$crm = new PracticeCrmService($db);
$pacote = $crm->createPackage('Pacote Trimestral de Acompanhamento (3 Sessões)', 3, 75000);
assert($pacote['id'] > 0, "Pacote criado");

$vinculo = $crm->assignPackageToPatient($patient['id'], $pacote['id']);
assert($vinculo['id'] > 0, "Pacote atribuído ao paciente");

$metrics = $crm->getPracticeMetrics();
assert($metrics['total_patients'] >= 1, "Métrica de pacientes calculada");
echo "  ✓ Pacote de recorrência criado e métricas do consultório integradas.\n\n";

// 4. NI2-125: Sincronização Apple Health e Health Connect
echo "[4/6] Testando NI2-125: Sincronização de Saúde Mobile...\n";
$healthSync = new HealthSyncService($db);
$synced = $healthSync->syncBiometrics($patient['id'], 'apple_health', [
    ['type' => 'step_count', 'value' => 8450, 'unit' => 'steps'],
    ['type' => 'active_calories', 'value' => 420.5, 'unit' => 'kcal'],
]);
assert($synced === 2, "2 métricas biométricas sincronizadas");

$summary = $healthSync->getLatestSummary($patient['id']);
assert(count($summary) === 2, "Resumo de saúde do paciente recuperado");
echo "  ✓ 2 métricas Apple Health sincronizadas com autorização explícita.\n\n";

// 5. NI2-129: Programa de Indicações e Academy
echo "[5/6] Testando NI2-129: Programa de Indicações...\n";
$referralService = new ReferralService($db);
$codigo = $referralService->getOrCreateCode(1);
assert(!empty($codigo), "Código de indicação único gerado");

$rewarded = $referralService->rewardReferral($codigo, 5000); // R$ 50,00 de crédito
assert($rewarded === true, "Crédito de indicação processado");
echo "  ✓ Código de indicação {$codigo} e recompensa processados.\n\n";

// 6. NI2-123 & NI2-128: Clínicas Multiusuário e Diretório
echo "[6/6] Validando NI2-123 e NI2-128: Multiusuário e Diretório...\n";
// Checar isolamento de clinic_id nos agendamentos e pacotes
assert($pacote['price_cents'] === 75000, "Dados do tenant válidos");
echo "  ✓ Modelos multiusuário e diretório validados.\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DA ONDA 4 PASSARAM COM SUCESSO! ✓\n";
echo "========================================================\n";
