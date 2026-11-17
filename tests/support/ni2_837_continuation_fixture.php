<?php
declare(strict_types=1);
// Isolated synthetic fixture only; the parent enforces /tmp/ni2-95-*.
ob_start(); require __DIR__.'/ni2_95_fixture.php'; ob_end_clean();
\ClyoNutri\Core\Auth\TenantContext::set(1, ['id'=>1,'role'=>'nutritionist']);
$service=new \ClyoNutri\Domain\Recipe\RecipeService($db);
foreach (['Receita completa TESTE','Receita histórica incompleta TESTE'] as $i=>$title) {
    $recipe=$service->createRecipe(['title'=>$title,'servings'=>2,'visibility'=>'clinic','summary'=>'Receita sintética para teste integrado.'],[['food_id'=>1,'amount'=>100,'measure_unit'=>'g']],['Separar o arroz em duas porções.']);
    $prescription=$service->prescribeRecipe(1,1,null,1,(int)$recipe['id'],1,null,'Almoço');
    if($i===1) $db->prepare("UPDATE recipe_versions SET payload_json='{}' WHERE recipe_id=?")->execute([$recipe['id']]);
}
$db->exec("INSERT INTO food_diary_entries(clinic_id,patient_id,meal_name,consumed_at,description,feedback_from_professional) VALUES(1,1,'Legado TESTE',datetime('now'),'Consumo registrado via aplicativo móvel','Comentário sintético preservado')");
echo json_encode(['ok'=>true,'recipes'=>2]);
