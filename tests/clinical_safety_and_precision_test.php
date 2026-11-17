<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Intelligence\ConsultationSummaryAssistant;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Nutrition\NutritionCalculator;
use ClyoNutri\Domain\Patient\PatientRepository;

echo "========================================================\n";
echo "   CLYONUTRI CLINICAL SAFETY & PRECISION TEST SUITE\n";
echo "   Validação do Épico NI2-144 e Histórias NI2-157 a NI2-161\n";
echo "========================================================\n\n";

// 1. Setup in-memory SQLite e Migrations
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->run();

// 2. Setup do Tenant e Usuários
$auth = new AuthService($db);
$tenant = $auth->registerNutritionist('Dra. Helena Segurança', 'helena@clyo.test', 'SenhaForte@1', 'Clínica Segurança Clínica', '77777', 'CRN-7777');
$clinicId = (int)$tenant['clinic_id'];
$profId = (int)$tenant['user_id'];

TenantContext::set($clinicId, ['id' => $profId, 'name' => 'Dra. Helena Segurança']);

$patientRepo = new PatientRepository($db);
$patient = $patientRepo->create(['name' => 'Carlos Silva', 'email' => 'carlos@test.com']);
$patientId = (int)$patient['id'];

// Seed do Catálogo de Alimentos TACO
$foodCatalog = new FoodCatalogService($db);
$foodCatalog->seedTacoReference();

echo "✓ Setup de infraestrutura e dados concluído.\n\n";

// -----------------------------------------------------------------------------
// [1/5] NI2-157: Prontuário Append-Only e Correções Versionadas
// -----------------------------------------------------------------------------
echo "[1/5] Testando NI2-157: Prontuário Append-Only e Retificações Versionadas...\n";
$clinicalRecordService = new ClinicalRecordService($db);

// Registro inicial
$rec1 = $clinicalRecordService->appendRecord($patientId, $profId, 'Primeira Consulta Nutricional', [
    'queixa' => 'Ganho de peso gradual nos últimos 2 anos',
    'conduta' => 'Plano alimentar hipocalórico de 1800 kcal',
]);
assert($rec1['version'] === 1, "Versão inicial deve ser 1");
assert($rec1['previous_hash'] === ClinicalRecordService::GENESIS_HASH, "Primeiro hash deve ser a raiz gênesis");
echo "  ✓ Registro v1 append-only criado com sucesso (Hash: " . substr($rec1['record_hash'], 0, 12) . "...).\n";

// Retificação formal com justificativa
$rec2 = $clinicalRecordService->reviseRecord($rec1['id'], $patientId, $profId, 'Correção de digitação na meta calórica acordada', [
    'queixa' => 'Ganho de peso gradual nos últimos 2 anos',
    'conduta' => 'Plano alimentar ajustado para 1900 kcal conforme TMB',
]);
assert($rec2['version'] === 2, "Versão corrigida deve ser 2");
assert($rec2['supersedes_id'] === $rec1['id'], "supersedes_id deve apontar para o registro v1");
assert($rec2['previous_hash'] === $rec1['record_hash'], "previous_hash da v2 deve ser o hash da v1");

// Validar que o registro v1 permaneceu no banco com conteúdo intacto
$stmtCheck = $db->prepare("SELECT * FROM clinical_records WHERE id = :id");
$stmtCheck->execute(['id' => $rec1['id']]);
$orig = $stmtCheck->fetch();
assert((int)$orig['is_retracted'] === 1, "Registro original v1 deve estar marcado como superado");
// v54.6x: content_json fica cifrado em repouso (enc:v1); a imutabilidade é conferida no conteúdo decifrado.
assert(\ClyoNutri\Core\Security\ClinicalEncryptionService::isEncrypted($orig['content_json']), "content_json da v1 cifrado em repouso");
$origContent = json_decode(\ClyoNutri\Core\Security\ClinicalEncryptionService::decrypt($orig['content_json']), true);
assert($origContent['conduta'] === 'Plano alimentar hipocalórico de 1800 kcal', "Conteúdo original da v1 NUNCA pode ser sobrescrito");
echo "  ✓ Retificação versionada v2 criada com justificativa. Histórico v1 100% preservado e imutável.\n\n";

// -----------------------------------------------------------------------------
// [2/5] NI2-158: Trilha Verificável de Integridade Clínica e Detecção de Adulteração
// -----------------------------------------------------------------------------
echo "[2/5] Testando NI2-158: Trilha Verificável de Integridade Clínica e Detecção de Adulteração...\n";
$integrityCheck = $clinicalRecordService->verifyIntegrity($patientId);
assert($integrityCheck['is_valid'] === true, "A cadeia criptográfica inicial deve ser 100% válida");
echo "  ✓ Verificação de integridade da cadeia: VÁLIDA (Total de registros auditados: {$integrityCheck['total_records']}).\n";

// Simular ataque ou adulteração direta no banco de dados (ex: update manual de conteúdo malicioso)
$db->exec("UPDATE clinical_records SET content_json = '{\"conduta\":\"conteudo forjado indevidamente\"}' WHERE id = {$rec1['id']}");
$tamperedCheck = $clinicalRecordService->verifyIntegrity($patientId);
assert($tamperedCheck['is_valid'] === false, "Adulteração direta DEVE ser detectada matematicamente");
assert($tamperedCheck['tampered_record_id'] === $rec1['id'], "ID do registro adulterado deve ser identificado com precisão");
echo "  ✓ Detecção de adulteração comprovada: violação no registro {$tamperedCheck['tampered_record_id']} interceptada com sucesso!\n";

// Restaurar o conteúdo correto para os demais testes
$db->prepare("UPDATE clinical_records SET content_json = :json WHERE id = :id")
   ->execute(['json' => $orig['content_json'], 'id' => $rec1['id']]);
$restoredCheck = $clinicalRecordService->verifyIntegrity($patientId);
assert($restoredCheck['is_valid'] === true, "Após restauração correta, integridade volta a ser válida");
echo "  ✓ Integridade restabelecida com sucesso.\n\n";

// -----------------------------------------------------------------------------
// [3/5] NI2-159: Calculadora por 100 g e Medidas Caseiras
// -----------------------------------------------------------------------------
echo "[3/5] Testando NI2-159: Calculadora por 100 g e Conversão de Medidas Caseiras...\n";
$calc = new NutritionCalculator($db);
$converter = new HouseholdMeasureConverter($db);

// Buscar Aveia em flocos no catálogo
$stmtAveia = $db->prepare("SELECT * FROM foods WHERE name = 'Aveia em flocos'");
$stmtAveia->execute();
$aveia = $stmtAveia->fetch();
assert((float)$aveia['energy_kcal'] === 394.0, "Aveia 100g deve ter 394 kcal na TACO");

// Caso Canônico de Aceite: 30g de Aveia em Flocos
$res30g = $calc->calculateForPortion($aveia, 30.0, 'g');
assert($res30g['weight_grams'] === 30.0, "Peso deve ser exatamente 30g");
assert($res30g['energy_kcal'] === 118.2, "30g de aveia deve ter 118.20 kcal (394 * 0.3)");
assert($res30g['protein_g'] === 4.17, "Proteína deve ser 4.17g (13.9 * 0.3)");
assert($res30g['carbohydrate_g'] === 19.98, "Carboidrato deve ser 19.98g (66.6 * 0.3)");
assert($res30g['lipid_g'] === 2.55, "Lipídios devem ser 2.55g (8.5 * 0.3)");
echo "  ✓ Caso canônico aprovado: 30g de Aveia em Flocos = {$res30g['energy_kcal']} kcal, {$res30g['protein_g']}g prot, {$res30g['carbohydrate_g']}g carb.\n";

// Medida caseira: 2 colheres de sopa de aveia (15g cada cadastrada = 30g)
$resColheres = $calc->calculateForPortion($aveia, 2.0, 'colher de sopa', (int)$aveia['id']);
assert($resColheres['weight_grams'] === 30.0, "2 colheres de sopa de aveia devem equivaler a 30g");
assert($resColheres['energy_kcal'] === 118.2, "Calorias equivalentes a 30g");
echo "  ✓ Medida caseira validada: 2 colheres de sopa = {$resColheres['weight_grams']}g e {$resColheres['energy_kcal']} kcal.\n";

// Teste de rejeição de unidade desconhecida
$unitRejected = false;
try {
    $calc->calculateForPortion($aveia, 2.0, 'unidade_inexistente');
} catch (\InvalidArgumentException $e) {
    $unitRejected = true;
}
assert($unitRejected === true, "Unidade desconhecida deve obrigatoriamente gerar InvalidArgumentException");

// Teste de rejeição de valor negativo
$negRejected = false;
try {
    $calc->calculateForPortion($aveia, -5.0, 'g');
} catch (\InvalidArgumentException $e) {
    $negRejected = true;
}
assert($negRejected === true, "Quantidade negativa deve obrigatoriamente gerar InvalidArgumentException");
echo "  ✓ Validações de limites e rejeição de unidades desconhecidas aprovadas 100%.\n\n";

// -----------------------------------------------------------------------------
// [4/5] NI2-160: Publicação Transacional e Histórico Imutável de Planos
// -----------------------------------------------------------------------------
echo "[4/5] Testando NI2-160: Publicação Transacional e Snapshot Imutável de Planos...\n";
$mealPlanService = new MealPlanService($db);

// Criar Plano 1 (Rascunho)
$plan1 = $mealPlanService->createPlan($patientId, $profId, 'Plano Inicial Hipertrofia', [
    [
        'name' => 'Café da Manhã',
        'time_of_day' => '08:00',
        'items' => [
            ['food_id' => (int)$aveia['id'], 'amount' => 40, 'measure_unit' => 'g'],
        ],
    ],
]);
assert($plan1['status'] === 'draft', "Plano recém-criado nasce como rascunho");

// Publicar Plano 1
$published1 = $mealPlanService->publishPlan($plan1['plan_id'], false, false, null, true, 'Estrutura e metas do plano de teste conferidas (TESTE, NI2-884).');
assert($published1 === true, "Plano 1 deve ser publicado com sucesso");

// Verificar snapshot imutável em meal_plan_versions
$activePlan = $mealPlanService->getActivePublishedPlan($patientId);
assert($activePlan !== null, "Deve existir plano publicado ativo para o paciente");
assert((int)$activePlan['meal_plan_id'] === $plan1['plan_id'], "ID do plano ativo deve corresponder ao plano 1");
assert((int)$activePlan['version_number'] === 1, "Versão do snapshot deve ser 1");
assert(!empty($activePlan['payload']), "Payload do snapshot deve conter refeições completas");
echo "  ✓ Plano 1 publicado com snapshot imutável v1 (ID {$activePlan['id']}).\n";

// Criar Plano 2 e publicar para o mesmo paciente
$plan2 = $mealPlanService->createPlan($patientId, $profId, 'Plano Ajustado Fase 2', [
    [
        'name' => 'Café da Manhã Reforçado',
        'time_of_day' => '07:30',
        'items' => [
            ['food_id' => (int)$aveia['id'], 'amount' => 60, 'measure_unit' => 'g'],
        ],
    ],
]);
$published2 = $mealPlanService->publishPlan($plan2['plan_id'], false, false, null, true, 'Estrutura e metas do plano de teste conferidas (TESTE, NI2-884).');
assert($published2 === true, "Plano 2 publicado com sucesso");

// O Plano ativo agora deve ser o Plano 2 e o Plano 1 deve ser superseded
$activePlan2 = $mealPlanService->getActivePublishedPlan($patientId);
assert((int)$activePlan2['meal_plan_id'] === $plan2['plan_id'], "Plano ativo deve ser o plano 2");
assert((int)$activePlan2['version_number'] === 2, "Versão deve ser incrementada para 2");

$stmtOldVer = $db->prepare("SELECT status FROM meal_plan_versions WHERE meal_plan_id = :id");
$stmtOldVer->execute(['id' => $plan1['plan_id']]);
assert($stmtOldVer->fetchColumn() === 'superseded', "Versão anterior deve estar marcada como superseded");
echo "  ✓ Substituição atômica de versão: v1 marcada como superseded e v2 ativa. Preservação histórica garantida.\n\n";

// -----------------------------------------------------------------------------
// [5/5] NI2-161: Prevenção de Simulações Clínicas e Validação de Alergênicos
// -----------------------------------------------------------------------------
echo "[5/5] Testando NI2-161: Prevenção de Simulações Clínicas e Validação de Alergênicos...\n";

// 5.1 Teste de remoção de simulações em ConsultationSummaryAssistant
$summaryAssistant = new ConsultationSummaryAssistant($db);
$structuredEmpty = $summaryAssistant->generateStructuredDraft($patientId, 1, "Paciente relata sensação de bem-estar geral.");
assert($structuredEmpty['is_simulation'] === false, "Flag explícita de ausência de simulação");
assert($structuredEmpty['soap']['objective'] === null, "Seção de objetivo ausente NÃO pode inventar dados clínicos fictícios");
assert(!empty($structuredEmpty['soap']['subjective']), "Seção subjetiva deve conter o relato real");
echo "  ✓ Verificação de integridade: nenhuma simulação de dados SOAP inventada pelo sistema.\n";

// 5.2 Validador de Alergênicos
$anamnesisService = new AnamnesisService($db);
$anamnesisService->saveAnamnesis($patientId, $profId, [
    'allergies' => 'Paciente com alergia severa a leite e derivados (lactose). Evitar lácteos.',
]);

// Buscar alimento lácteo
$stmtLeite = $db->prepare("SELECT * FROM foods WHERE name = 'Leite desnatado'");
$stmtLeite->execute();
$leite = $stmtLeite->fetch();

// Tentar criar e publicar plano contendo alimento alergênico conflitante
$planInseguro = $mealPlanService->createPlan($patientId, $profId, 'Plano com Alergênico Conflitante', [
    [
        'name' => 'Lanche da Tarde',
        'time_of_day' => '16:00',
        'items' => [
            ['food_id' => (int)$leite['id'], 'amount' => 200, 'measure_unit' => 'ml', 'confirm_estimated' => true], // NI2-74: ml sem medida cadastrada exige confirmação
        ],
    ],
]);

$allergenBlocked = false;
try {
    // Tentativa de publicação padrão sem autorização/override explícito de risco
    $mealPlanService->publishPlan($planInseguro['plan_id']);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'conflito de alergênicos detectado')) {
        $allergenBlocked = true;
    }
}
assert($allergenBlocked === true, "Publicação de prescrição com alergênico conflitante DEVE ser bloqueada");
echo "  ✓ Alerta e bloqueio de segurança clínica ativados: tentativa de prescrever Leite desnatado para paciente com alergia a lactose foi barrada.\n";

// Publicação supervisionada expressa (quando o profissional insere substituto ou confirmação clínica formal)
$publishedWithOverride = $mealPlanService->publishPlan($planInseguro['plan_id'], true, false, 'Leite sem lactose orientado; conferido com a paciente.', true, 'Estrutura e metas do plano de teste conferidas (TESTE, NI2-884).');
assert($publishedWithOverride === true, "Com autorização explícita de override, publicação supervisionada é permitida");
echo "  ✓ Supervisão profissional com confirmação explícita registrada com sucesso.\n\n";

echo "========================================================\n";
echo "   RESULTADO FINAL DA SUÍTE CLÍNICA: APROVADO COM 100%\n";
echo "   Todas as 5 histórias de NI2-144 validadas com sucesso!\n";
echo "========================================================\n";
