<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Application\Api\PatientApiController;

echo "====================================================================\n";
echo "SUÍTE DE TESTES: PLANO ALIMENTAR & PRESCRIÇÃO NUTRICIONAL (NI2-9)\n";
echo "Histórias: NI2-76, NI2-77, NI2-78, NI2-79, NI2-80\n";
echo "====================================================================\n\n";

$db = Connection::getInstance();
$service = new MealPlanService($db);

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $message, ?string $details = null): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}\n";
        if ($details) {
            echo "         Detalhes: {$details}\n";
        }
    }
}

// -------------------------------------------------------------
// SETUP DE TESTES: TENANTS, USUÁRIOS, PACIENTES E ALIMENTOS
// -------------------------------------------------------------
echo "--> Configurando ambiente de teste e sementes...\n";

// Criar Tenants
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (1, 'clinic-uuid-1', 'Clínica Nutri A', '11111111000101')");
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (2, 'clinic-uuid-2', 'Clínica Nutri B', '22222222000102')");

// Criar Usuários
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role) VALUES (1, 'user-uuid-1', 'Dra. Luiza Nutricionista', 'luiza@nutria.com', 'hash', 'nutritionist')");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role) VALUES (2, 'user-uuid-2', 'Dr. Roberto Outro Tenant', 'roberto@nutrib.com', 'hash', 'nutritionist')");

// Vincular Membros
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role) VALUES (1, 1, 'nutritionist')");
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role) VALUES (2, 2, 'nutritionist')");

// Criar Profissionais
$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (1, 1, 'CRN-1010', 'CRN-3')");
$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (2, 2, 'CRN-2020', 'CRN-3')");

// Criar Pacientes
$db->exec("DELETE FROM patients WHERE email IN ('paciente.plano@teste.com', 'paciente.tenantb@teste.com')");
$pStmt = $db->prepare("
    INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, status)
    VALUES (1, 'patient-uuid-plano-1', 'Carlos Eduardo Santos', 'paciente.plano@teste.com', '11988887777', '1990-05-15', 'active')
");
$pStmt->execute();
$patientId = (int)$db->lastInsertId();

$pStmt2 = $db->prepare("
    INSERT INTO patients (clinic_id, uuid, name, email, phone, birth_date, status)
    VALUES (2, 'patient-uuid-plano-2', 'Paciente Tenant B', 'paciente.tenantb@teste.com', '11977776666', '1985-08-20', 'active')
");
$pStmt2->execute();
$patientTenantB = (int)$db->lastInsertId();

// Criar ou garantir alimentos TACO no banco
$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens)
VALUES (8101, NULL, 'Arroz Branco Cozido TACO', 'Cereais', 'TACO', 128.00, 2.50, 28.10, 0.20, 1.60, 100.00, 'g', NULL)");

$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens)
VALUES (8102, NULL, 'Feijão Carioca Cozido TACO', 'Leguminosas', 'TACO', 76.00, 4.80, 13.60, 0.50, 8.50, 100.00, 'g', NULL)");

$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens)
VALUES (8103, NULL, 'Peito de Frango Grelhado TACO', 'Carnes', 'TACO', 159.00, 32.00, 0.00, 2.50, 0.00, 100.00, 'g', NULL)");

$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens)
VALUES (8104, NULL, 'Batata Doce Cozida TACO', 'Tubérculos', 'TACO', 77.00, 0.60, 18.40, 0.10, 2.20, 100.00, 'g', NULL)");

$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens)
VALUES (8105, NULL, 'Tilápia Grelhada TACO', 'Pescados', 'TACO', 96.00, 20.10, 0.00, 1.70, 0.00, 100.00, 'g', 'peixe')");

$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens)
VALUES (8106, NULL, 'Leite Desnatado TACO', 'Laticínios', 'TACO', 35.00, 3.40, 5.00, 0.10, 0.00, 100.00, 'ml', 'leite')");

// Definir contexto do Tenant 1
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Luiza']);

// =============================================================
// CENÁRIO 1: NI2-76 - CRIAR PLANO E ORGANIZAR REFEIÇÕES / HORÁRIOS
// =============================================================
echo "\n--> [NI2-76] Testando criação de plano, organização de refeições e validação de horários...\n";

// 1.1 Criar plano em draft
$planData = $service->createPlan(
    $patientId,
    1,
    'Plano Hipertrofia & Definição',
    'Ingerir pelo menos 2.5L de água por dia. Evitar açúcar adicionado.'
);
$planId = $planData['plan_id'];
assertTest($planId > 0, "Plano alimentar criado com ID {$planId}");
assertTest($planData['status'] === 'draft', "Plano inicia com status 'draft'");

// 1.2 Adicionar refeições com horários válidos
$meal1Id = $service->addMeal($planId, 'Café da Manhã', '07:30', 'Consumir até 45 min após acordar.');
$meal2Id = $service->addMeal($planId, 'Almoço Completo', '12:30', 'Mastigar devagar. Incluir salada crua à vontade.');
$meal3Id = $service->addMeal($planId, 'Lanche da Tarde', '16:00', 'Pré-treino.');
$meal4Id = $service->addMeal($planId, 'Jantar', '20:00', 'Refeição leve.');

assertTest($meal1Id > 0 && $meal2Id > 0 && $meal3Id > 0 && $meal4Id > 0, "Quatro refeições adicionadas com sucesso");

// 1.3 Rejeição de horário inválido
$caughtTimeError = false;
try {
    $service->addMeal($planId, 'Ceia Inválida', '25:99');
} catch (InvalidArgumentException $e) {
    $caughtTimeError = true;
}
assertTest($caughtTimeError, "Horário em formato incorreto (25:99) rejeitado com InvalidArgumentException");

// 1.4 Atualizar refeição
$updated = $service->updateMeal($meal3Id, 'Lanche Pré-Treino', '16:15', 'Consumir 40 min antes do treino de força.');
assertTest($updated, "Refeição atualizada com novo nome, horário e orientações");

// 1.5 Reordenar refeições
$reordered = $service->reorderMeals($planId, [$meal2Id, $meal1Id, $meal3Id, $meal4Id]);
assertTest($reordered, "Reordenação de refeições executada com sucesso");

// 1.6 Remover refeição
$mealExtraId = $service->addMeal($planId, 'Ceia Extra', '22:30');
$deleted = $service->deleteMeal($mealExtraId);
assertTest($deleted, "Refeição extra removida com sucesso do plano");

// =============================================================
// CENÁRIO 2: NI2-77 - ADICIONAR ALIMENTOS, QUANTIDADES E TOTAIS NUTRICIONAIS
// =============================================================
echo "\n--> [NI2-77] Testando alimentos, cálculo proporcional determinístico e cascata de totais...\n";

// Adicionar itens ao Almoço (meal2Id)
// Arroz Branco: 150g (128 kcal/100g -> 192 kcal, P: 3.75g, C: 42.15g, G: 0.3g, F: 2.4g)
$itemArrozId = $service->addItem($meal2Id, 8101, 150.0, 'g', 'Arroz branco cozido bem solto');

// Feijão Carioca: 100g (76 kcal/100g -> 76 kcal, P: 4.8g, C: 13.6g, G: 0.5g, F: 8.5g)
$itemFeijaoId = $service->addItem($meal2Id, 8102, 100.0, 'g', 'Feijão temperado com alho e louro');

// Peito de Frango: 120g (159 kcal/100g -> 190.8 kcal, P: 38.4g, C: 0.0g, G: 3.0g, F: 0.0g)
$itemFrangoId = $service->addItem($meal2Id, 8103, 120.0, 'g', 'Grelhado com azeite mínimo');

// Verificar se totais do plano foram recalculados em cascata
$tree = $service->getPlanWithDetails($planId);
$expectedMealKcal = 192.0 + 76.0 + 190.8; // 458.8 kcal
$actualKcal = (float)$tree['daily_energy_kcal'];

assertTest(abs($actualKcal - $expectedMealKcal) < 0.1, "Totais do plano recalculados em cascata: {$actualKcal} kcal (esperado ~458.8 kcal)");
assertTest((float)$tree['protein_g'] > 45.0, "Total de proteínas consolidado no plano: {$tree['protein_g']}g");
assertTest((float)$tree['carbohydrate_g'] > 50.0, "Total de carboidratos consolidado no plano: {$tree['carbohydrate_g']}g");

// Atualizar porção do Frango de 120g para 150g (159 * 1.5 = 238.5 kcal)
$service->updateItem($itemFrangoId, 150.0, 'g', 'Porção aumentada para hipertrofia');
$treeUpdated = $service->getPlanWithDetails($planId);
$newExpectedKcal = 192.0 + 76.0 + 238.5; // 506.5 kcal
assertTest(abs((float)$treeUpdated['daily_energy_kcal'] - $newExpectedKcal) < 0.1, "Edição do item recalcula plano imediatamente: {$treeUpdated['daily_energy_kcal']} kcal");

// Rejeição de quantidade negativa ou zero
$caughtNegativeError = false;
try {
    $service->addItem($meal2Id, 8101, -50.0, 'g');
} catch (InvalidArgumentException $e) {
    $caughtNegativeError = true;
}
assertTest($caughtNegativeError, "Tentativa de prescrever quantidade negativa rejeitada fail-closed");

// =============================================================
// CENÁRIO 3: NI2-78 - CONFIGURAR SUBSTITUIÇÕES E ORIENTAÇÕES CLÍNICAS
// =============================================================
echo "\n--> [NI2-78] Testando substituições vinculadas e isolamento de consumo alternativo...\n";

// Adicionar Batata Doce (104) como substituição para o Arroz Branco (itemArrozId)
// 150g de arroz (~192 kcal) -> 200g de batata doce (77 * 2.0 = 154 kcal)
$subBatataId = $service->addSubstitution($itemArrozId, 8104, 200.0, 'g', 'Opção para variar o carboidrato do almoço');
assertTest($subBatataId > 0, "Substituição vinculada ao item cadastrada com ID {$subBatataId}");

// Adicionar Tilápia (105) como substituição para o Frango (itemFrangoId)
$subPeixeId = $service->addSubstitution($itemFrangoId, 8105, 160.0, 'g', 'Opção de peixe magro');
assertTest($subPeixeId > 0, "Substituição de proteína vinculada ao peito de frango");

// Garantir que a substituição NÃO soma no total diário da refeição (consumo alternativo)
$treeWithSubs = $service->getPlanWithDetails($planId);
assertTest(
    abs((float)$treeWithSubs['daily_energy_kcal'] - $newExpectedKcal) < 0.1,
    "Substituições NÃO inflam a soma diária do plano: total permanece {$treeWithSubs['daily_energy_kcal']} kcal"
);

// Obter preview e verificar distinção clara entre itens e substituições
$preview = $service->getPlanPreview($planId);
$mealWithSubs = null;
foreach ($preview['plan']['meals'] as $m) {
    if ($m['id'] === $meal2Id) {
        $mealWithSubs = $m;
        break;
    }
}
assertTest($mealWithSubs !== null, "Refeição recuperada no preview");
assertTest(count($mealWithSubs['items'][0]['substitutions']) === 1, "Item do arroz contém exatamente 1 substituição configurada");
assertTest($mealWithSubs['items'][0]['substitutions'][0]['food_name'] === 'Batata Doce Cozida TACO', "Nome da substituição exibido com precisão");

// Remover uma substituição
$removedSub = $service->deleteSubstitution($subBatataId);
assertTest($removedSub, "Substituição removida com sucesso mantendo item principal intacto");

// =============================================================
// CENÁRIO 4: NI2-79 - REVISAR E PUBLICAR VERSÃO COM SNAPSHOT IMUTÁVEL
// =============================================================
echo "\n--> [NI2-79] Testando revisão, validações clínicas, snapshot imutável e acesso do paciente...\n";

// 4.1 Bloqueio de plano vazio
$emptyPlan = $service->createPlan($patientId, 1, 'Plano Vazio Teste');
$caughtEmptyError = false;
try {
    $service->publishPlan($emptyPlan['plan_id']);
} catch (InvalidArgumentException $e) {
    $caughtEmptyError = true;
}
assertTest($caughtEmptyError, "Publicação de plano alimentar vazio bloqueada fail-closed");

// 4.2 Alergênicos na anamnese: configurar anamnese do paciente com alergia a 'peixe'
$db->exec("DELETE FROM anamneses WHERE patient_id = {$patientId}");
$aStmt = $db->prepare("
    INSERT INTO anamneses (clinic_id, patient_id, professional_id, allergies, clinical_history)
    VALUES (1, :pid, 1, 'Alergia grave a peixes e frutos do mar', 'Paciente relata choque anafilático prévio.')
");
$aStmt->execute(['pid' => $patientId]);

// O plano contém a tilápia (peixe) na substituição do frango!
// Testar tentativa de publicação sem override -> DEVE FALHAR
$caughtAllergenError = false;
try {
    $service->publishPlan($planId, false);
} catch (InvalidArgumentException $e) {
    $caughtAllergenError = true;
    assertTest(str_contains($e->getMessage(), 'peixe'), "Mensagem de erro cita especificamente o alérgeno 'peixe'");
}
assertTest($caughtAllergenError, "Publicação com conflito alergênico bloqueada preventivamente");

// 4.3 Publicação com autorização/override do nutricionista
$publishRes = $service->publishPlan($planId, true, true);
assertTest($publishRes['status'] === 'published', "Plano publicado com sucesso após validação clínica");
assertTest($publishRes['version'] === 1, "Primeira versão publicada registrada como v1");

// 4.4 Verificar snapshot imutável em meal_plan_versions
$verStmt = $db->prepare("SELECT * FROM meal_plan_versions WHERE meal_plan_id = :id AND status = 'published'");
$verStmt->execute(['id' => $planId]);
$verSnapshot = $verStmt->fetch();
assertTest(!empty($verSnapshot), "Snapshot imutável gravado em meal_plan_versions");
$snapshotData = json_decode($verSnapshot['payload_json'], true);
assertTest(isset($snapshotData['meals']), "Snapshot contém payload JSON completo de refeições e itens");

// 4.5 Verificar registro de auditoria
$auditStmt = $db->prepare("SELECT * FROM audit_logs WHERE action = 'MEAL_PLAN_PUBLISHED' AND resource_id = :id ORDER BY id DESC LIMIT 1");
$auditStmt->execute(['id' => (string)$planId]);
$auditLog = $auditStmt->fetch();
assertTest(!empty($auditLog), "Trilha de auditoria MEAL_PLAN_PUBLISHED gerada com detalhes do paciente e versão");

// 4.6 Imutabilidade: tentar adicionar refeição ou item ao plano publicado DEVE FALHAR
$caughtImmutableError = false;
try {
    $service->addMeal($planId, 'Refeição Proibida em Publicado');
} catch (RuntimeException $e) {
    $caughtImmutableError = true;
}
assertTest($caughtImmutableError, "Alterações em plano publicado bloqueadas por imutabilidade");

// 4.7 Paciente só acessa versão publicada
// Testar via API do paciente
$patientPublished = $service->getPatientPublishedPlan($patientId);
assertTest($patientPublished !== null, "API do paciente localiza plano alimentar publicado");
assertTest($patientPublished['version_number'] === 1, "Versão entregue ao paciente é a versão 1 ativa");

// Rascunho NÃO aparece para o paciente
$draftPlanOnly = $service->createPlan($patientId, 1, 'Rascunho Secreto Não Publicado');
$draftMealId = $service->addMeal($draftPlanOnly['plan_id'], 'Café Secreto');
$service->addItem($draftMealId, 8101, 100.0, 'g');

$patientViewAfterDraft = $service->getPatientPublishedPlan($patientId);
assertTest($patientViewAfterDraft['meal_plan_id'] === $planId, "Rascunhos são estritamente invisíveis ao paciente; plano publicado v1 mantido");

// =============================================================
// CENÁRIO 5: NI2-80 - DUPLICAR E AJUSTAR PRESERVANDO HISTÓRICO
// =============================================================
echo "\n--> [NI2-80] Testando duplicação de plano, clonagem profunda e integridade retroativa...\n";

// 5.1 Duplicar plano publicado
$duplicateRes = $service->duplicatePlan($planId, 'Plano Hipertrofia Fase 2 (Ajustado)');
$newDraftId = $duplicateRes['plan_id'];

assertTest($newDraftId > 0 && $newDraftId !== $planId, "Novo rascunho criado com ID independente {$newDraftId}");
assertTest($duplicateRes['status'] === 'draft', "Novo plano duplicado inicia como 'draft'");
assertTest($duplicateRes['parent_plan_id'] === $planId, "Linhagem parent_plan_id preservada apontando para o plano de origem #{$planId}");

// 5.2 Conferir clonagem profunda de refeições, itens e substituições
$newTree = $service->getPlanWithDetails($newDraftId);
assertTest(count($newTree['meals']) === count($tree['meals']), "Todas as refeições foram clonadas para o novo draft");

$hasClonedItems = false;
$hasClonedSubs = false;
foreach ($newTree['meals'] as $m) {
    if (!empty($m['items'])) {
        $hasClonedItems = true;
        foreach ($m['items'] as $it) {
            if (!empty($it['substitutions'])) {
                $hasClonedSubs = true;
            }
        }
    }
}
assertTest($hasClonedItems, "Itens e porções clonados com precisão");
assertTest($hasClonedSubs, "Substituições vinculadas clonadas com precisão");

// 5.3 Modificar o novo rascunho sem alterar o plano publicado original
$firstMealNewDraft = $newTree['meals'][0]['id'];
$service->addItem($firstMealNewDraft, 8106, 200.0, 'ml', 'Leite desnatado adicionado ao novo rascunho');

$newTreeModified = $service->getPlanWithDetails($newDraftId);
$origPlanPostCheck = $service->getPlanWithDetails($planId);

assertTest($origPlanPostCheck['status'] === 'published', "Plano original permanece PUBLICADO e inalterado");
assertTest(
    $newTreeModified['daily_energy_kcal'] > $origPlanPostCheck['daily_energy_kcal'],
    "Apenas o novo rascunho teve seus totais recalculados ({$newTreeModified['daily_energy_kcal']} kcal vs original {$origPlanPostCheck['daily_energy_kcal']} kcal)"
);

// 5.4 Publicar a nova versão duplicada e conferir superseding
$pubV2 = $service->publishPlan($newDraftId, true, true);
assertTest($pubV2['version'] === 2, "Nova versão duplicada publicada como v2");

// Checar status do plano anterior: tornou-se superseded
$origStatusStmt = $db->prepare("SELECT status FROM meal_plans WHERE id = :id");
$origStatusStmt->execute(['id' => $planId]);
$origStatus = $origStatusStmt->fetchColumn();
assertTest($origStatus === 'superseded', "Plano anterior marcado como 'superseded' no histórico");

// Paciente agora recebe a nova versão v2
$patientV2 = $service->getPatientPublishedPlan($patientId);
assertTest($patientV2['version_number'] === 2, "Paciente agora tem acesso à versão v2 vigente");
assertTest($patientV2['meal_plan_id'] === $newDraftId, "ID do plano ativo do paciente atualizado para #{$newDraftId}");

// =============================================================
// CENÁRIO 6: PEN-TEST MULTI-TENANT FAIL-CLOSED
// =============================================================
echo "\n--> [Pen-Test] Testando isolamento estrito entre clínicas (Clínica A vs Clínica B)...\n";

// Mudar para o contexto da Clínica 2 (Tenant B)
TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist', 'name' => 'Dr. Roberto']);

// 6.1 Tenant B tentando acessar plano da Clínica 1
$caughtCrossAccess = false;
try {
    $service->getPlanWithDetails($planId);
} catch (\Throwable $e) {
    $caughtCrossAccess = true;
}
assertTest($caughtCrossAccess, "Tenant B bloqueado ao tentar visualizar plano da Clínica A");

// 6.2 Tenant B tentando adicionar refeição a plano da Clínica 1
$caughtCrossMeal = false;
try {
    $service->addMeal($newDraftId, 'Refeição Invasiva');
} catch (\Throwable $e) {
    $caughtCrossMeal = true;
}
assertTest($caughtCrossMeal, "Tenant B bloqueado ao tentar alterar plano da Clínica A");

// 6.3 Tenant B tentando duplicar plano da Clínica 1
$caughtCrossDup = false;
try {
    $service->duplicatePlan($newDraftId);
} catch (\Throwable $e) {
    $caughtCrossDup = true;
}
assertTest($caughtCrossDup, "Tenant B bloqueado ao tentar duplicar plano da Clínica A");

// 6.4 Tenant B tentando prescrever para paciente da Clínica 1
$caughtCrossPatient = false;
try {
    $service->createPlan($patientId, 2, 'Tentativa Cruzada');
} catch (\Throwable $e) {
    $caughtCrossPatient = true;
}
assertTest($caughtCrossPatient, "Tenant B bloqueado ao tentar prescrever para paciente da Clínica A");

// Restaurar contexto do Tenant 1
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'Dra. Luiza']);

// =============================================================
// RESULTADO FINAL
// =============================================================
echo "\n====================================================================\n";
echo "RESULTADOS DOS TESTES DE PLANO ALIMENTAR & PRESCRIÇÃO (NI2-9):\n";
echo "  TOTAL EXECUTADOS : " . ($passed + $failed) . "\n";
echo "  PASSOU           : {$passed}\n";
echo "  FALHOU           : {$failed}\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
