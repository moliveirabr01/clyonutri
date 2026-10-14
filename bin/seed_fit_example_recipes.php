<?php
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Recipe\RecipeService;
use ClyoNutri\Core\Audit\AuditLogger;
$clinicId=filter_var($argv[1]??'',FILTER_VALIDATE_INT);$userId=filter_var($argv[2]??'',FILTER_VALIDATE_INT);
if(!$clinicId || !$userId) throw new InvalidArgumentException('Informe clinic_id e user_id explicitamente.');
$db=Connection::getInstance();$member=$db->prepare("SELECT u.id,u.role,cm.role AS member_role FROM users u JOIN clinic_members cm ON cm.user_id=u.id JOIN clinics c ON c.id=cm.clinic_id WHERE u.id=? AND cm.clinic_id=? AND u.status='active' AND cm.status='active' AND c.status='active' AND u.role IN ('nutritionist','admin','owner') AND cm.role IN ('nutritionist','admin','owner')");$member->execute([$userId,$clinicId]);$actor=$member->fetch();if(!$actor)throw new RuntimeException('Profissional não autorizado para este consultório.');
TenantContext::set($clinicId,$actor);$service=new RecipeService($db);$created=[];
foreach(require __DIR__.'/../database/seeds/fit_example_recipes.php' as $sample){
 $exists=$db->prepare('SELECT id FROM recipes WHERE clinic_id=? AND title=?');$exists->execute([$clinicId,$sample['title']]);if($exists->fetchColumn())continue;
 $ingredients=[];foreach($sample['ingredients'] as [$name,$amount]){
  $food=$db->prepare('SELECT id FROM foods WHERE name=? AND clinic_id IS NULL ORDER BY id LIMIT 1');$food->execute([$name]);$id=$food->fetchColumn();if(!$id)throw new RuntimeException('Ingrediente de catálogo ausente: '.$name);
  $ingredients[]=['food_id'=>(int)$id,'amount'=>$amount,'measure_unit'=>'g'];
 }
 $recipe=$service->createRecipe(['title'=>$sample['title'],'summary'=>'Receita de exemplo para explorar a biblioteca. Quantidades em gramas de parte comestível; ingredientes cozidos são pesados após o preparo. Nutrientes estimados pelo catálogo, rendimento aproximado. Alergênicos aguardam conferência profissional e dos rótulos.','meal_type'=>$sample['meal_type'],'category'=>$sample['category'],'prep_time_minutes'=>$sample['prep'],'cook_time_minutes'=>$sample['cook'],'servings'=>$sample['servings'],'visibility'=>'clinic','allergens_verified'=>false],$ingredients,$sample['steps'],['Exemplo','Preparação simples'],array_map(static fn($group)=>['group'=>$group,'notes'=>'Exemplo: conferir rótulos e possibilidade de contato cruzado antes de prescrever.'],$sample['allergens']));
 (new AuditLogger($db))->log('EXAMPLE_RECIPE_CREATED','recipes',(string)$recipe['id'],['example'=>true]);$created[]=['id'=>$recipe['id'],'title'=>$recipe['title']];
}
echo json_encode(['clinic_id'=>$clinicId,'created'=>$created],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
