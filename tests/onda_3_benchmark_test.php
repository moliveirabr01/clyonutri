<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Intelligence\ConsultationSummaryAssistant;
use ClyoNutri\Domain\Intelligence\MealPlanDraftAssistant;
use ClyoNutri\Domain\Migration\CompetitorMigrationService;
use ClyoNutri\Domain\Patient\PatientRepository;

echo "========================================================\n";
echo "   CLYONUTRI BENCHMARK & ONDA 3 TEST SUITE\n";
echo "========================================================\n\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

$auth = new AuthService($db);
$nutri = $auth->registerNutritionist('Dr. André Inteligência', 'andre@onda3.test', 'SenhaForte@2026', 'Clínica Inovação', '77665', 'CRN-3');
TenantContext::set($nutri['clinic_id'], ['id' => $nutri['user_id'], 'name' => $nutri['name']]);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Tatiana Albuquerque', 'email' => 'tatiana@test.com']);

$foodService = new FoodCatalogService($db);
$foodService->seedTacoReference();

// 1. NI2-130: Assistente para Documentação e Resumo Estruturado SOAP
echo "[1/3] Testando NI2-130: Resumo Clínico Estruturado SOAP...\n";
$summaryAssistant = new ConsultationSummaryAssistant($db);
$rawNotes = "Paciente relata queixa de gases frequentes e cansaço à tarde.
Peso aferido em 68.5 kg, circunferência abdominal de 78 cm.
Apresenta bom metabolismo mas ingestão de fibras insuficiente.
Orientar aumento de ingestão de água para 2L e prescrever plano rico em fibras.";

$draftSoap = $summaryAssistant->generateStructuredDraft($patient['id'], 1, $rawNotes);
assert($draftSoap['status'] === 'draft', "Status deve ser draft");
assert($draftSoap['requires_professional_review'] === true, "Deve exigir revisão profissional");
assert(!empty($draftSoap['soap']['subjective']), "Seção Subjetivo preenchida");
assert(!empty($draftSoap['soap']['objective']), "Seção Objetivo preenchida");
assert(!empty($draftSoap['soap']['assessment']), "Seção Avaliação preenchida");
assert(!empty($draftSoap['soap']['plan']), "Seção Plano preenchida");
echo "  ✓ Resumo estruturado SOAP gerado como rascunho com sucesso.\n\n";

// 2. NI2-119: Assistente de IA para Rascunho de Plano com Supervisão Humana
echo "[2/3] Testando NI2-119: Assistente de IA para Rascunho de Dieta...\n";
$draftAssistant = new MealPlanDraftAssistant($db);
$aiDraft = $draftAssistant->draftPlan($patient['id'], $nutri['user_id'], 1800.0, ['leite', 'lactose']);
assert($aiDraft['status'] === 'draft', "Plano deve ser estritamente rascunho");
assert($aiDraft['is_published'] === false, "NÃO pode estar publicado sem aprovação");
assert($aiDraft['requires_professional_approval'] === true, "Exige aprovação do nutricionista");
assert($aiDraft['calculated_daily_kcal'] > 0, "Calorias calculadas");
echo "  ✓ Rascunho de IA gerado com segurança: {$aiDraft['calculated_daily_kcal']} kcal (Salvaguarda: status 'draft').\n\n";

// 3. NI2-120: Migração Assistida de Concorrentes por CSV
echo "[3/3] Testando NI2-120: Migração Assistida de Pacientes de Concorrentes...\n";
$migrationService = new CompetitorMigrationService($db);
$csvSample = "Nome,E-mail,Telefone,Data de Nascimento,Gênero\n" .
             "Felipe Medeiros,felipe@dietbox.test,(11) 99999-1111,14/05/1988,masculino\n" .
             "Fernanda Rocha,fernanda@webdiet.test,(11) 98888-2222,22/10/1992,feminino\n" .
             ",sem_nome@invalido.com,,,\n";

$importResult = $migrationService->importPatientsFromCsv($csvSample, 'Dietbox');
assert($importResult['total_imported'] === 2, "Devem ser importados 2 pacientes válidos");
assert($importResult['total_skipped'] === 1, "Linha sem nome deve ser ignorada");

$importedPatients = $patientRepo->listAll();
// Total = Tatiana (criada antes) + 2 importados = 3
assert(count($importedPatients) === 3, "Total de pacientes no tenant deve ser 3");
echo "  ✓ 2 pacientes migrados com sucesso do Dietbox com auditoria registrada.\n\n";

echo "========================================================\n";
echo "   TODOS OS TESTES DA ONDA 3 PASSARAM COM SUCESSO! ✓\n";
echo "========================================================\n";
