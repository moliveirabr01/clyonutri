<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Chat\ChatService;
use ClyoNutri\Domain\Engagement\EngagementService;
use ClyoNutri\Domain\FoodDiary\FoodDiaryService;
use ClyoNutri\Domain\Patient\PatientRepository;

echo "========================================================\n";
echo "   CLYONUTRI BENCHMARK & ONDA 2 TEST SUITE\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

$auth = new AuthService($db);
$nutri = $auth->registerNutritionist('Dra. Vanessa Nutri', 'vanessa@onda2.test', 'SenhaForte@2026', 'Clínica Integrada', '55443', 'CRN-3');
TenantContext::set($nutri['clinic_id'], ['id' => $nutri['user_id'], 'name' => $nutri['name']]);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Gabriel Ramos', 'email' => 'gabriel@test.com']);

// 1. NI2-115: Diário Alimentar com Foto, Sintomas e Feedback
echo "[1/3] Testando NI2-115: Diário Alimentar com Foto e Sintomas...\n";
$diaryService = new FoodDiaryService($db);
$entry = $diaryService->logMeal($patient['id'], [
    'meal_name' => 'Almoço Saudável',
    'description' => 'Salada, arroz integral e filé de frango',
    'photo_path' => 'uploads/diary_photos/prato_gabriel_01.jpg',
    'hunger_level' => 3,
    'satiety_level' => 4,
    'symptoms' => 'Digestão tranquila, sem desconforto',
]);
assert($entry['id'] > 0, "Registro de refeição deve ser criado");

$feedbackAdded = $diaryService->addProfessionalFeedback($entry['id'], 'Ótima composição de prato, proporção de fibras ideal!');
assert($feedbackAdded === true, "Feedback do profissional deve ser registrado");

$timeline = $diaryService->getPatientTimeline($patient['id']);
assert(count($timeline) === 1, "Timeline deve conter 1 refeição registrada");
assert($timeline[0]['feedback_from_professional'] !== null, "Feedback do nutricionista visível na timeline");
echo "  ✓ Diário alimentar registrado com foto, sintomas e feedback clínico validado.\n\n";

// 2. NI2-117: Check-in Longitudinal e Acompanhamento Colaborativo
echo "[2/3] Testando NI2-117: Tendências Longitudinais de Acompanhamento...\n";
$engagementService = new EngagementService($db);
// Simular histórico semanal de check-ins
$engagementService->recordCheckin($patient['id'], ['meal_adherence_pct' => 85, 'energy_level' => 4, 'checkin_date' => '2026-09-20']);
$engagementService->recordCheckin($patient['id'], ['meal_adherence_pct' => 90, 'energy_level' => 5, 'checkin_date' => '2026-09-27']);
$engagementService->recordCheckin($patient['id'], ['meal_adherence_pct' => 95, 'energy_level' => 5, 'checkin_date' => '2026-10-04']);

$trends = $engagementService->getLongitudinalTrends($patient['id'], 4);
assert($trends['total_checkins'] === 3, "Total de check-ins no histórico deve ser 3");
assert(count($trends['history']) === 3, "Histórico detalhado recuperado");
echo "  ✓ Histórico longitudinal recuperado com sucesso para visualização clínica de tendências.\n\n";

// 3. NI2-118: Chat Seguro Profissional-Paciente
echo "[3/3] Testando NI2-118: Chat Seguro Profissional-Paciente...\n";
$chatService = new ChatService($db);
$convId = $chatService->getOrCreateConversation($patient['id'], $nutri['user_id']);
assert($convId > 0, "Conversa segura deve ser criada ou recuperada");

// Paciente envia dúvida
$msg1 = $chatService->sendMessage($convId, 'patient', $patient['id'], 'Dra., posso trocar o arroz da janta por batata doce?');
assert($msg1['id'] > 0, "Mensagem do paciente gravada");

// Nutricionista responde
$msg2 = $chatService->sendMessage($convId, 'professional', $nutri['user_id'], 'Pode sim, Gabriel! Cerca de 150g de batata doce equivalem perfeitamente.');
assert($msg2['id'] > 0, "Mensagem do profissional gravada");

$mensagens = $chatService->getMessages($convId);
assert(count($mensagens) === 2, "Devem existir 2 mensagens na conversa");

$chatService->markAsRead($convId, 'patient'); // Paciente lê resposta
$mensagensAtualizadas = $chatService->getMessages($convId);
assert($mensagensAtualizadas[1]['read_at'] !== null, "Mensagem do profissional marcada como lida pelo paciente");
echo "  ✓ Conversa segura, envio bidirecional e confirmação de leitura validados com sucesso.\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DA ONDA 2 PASSARAM COM SUCESSO! ✓\n";
echo "========================================================\n";
