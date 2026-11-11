<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Database\{Connection,SchemaInfo};
ClyoNutri\Core\Security\Env::load(__DIR__.'/../.env');
$issues=[];$pending=[];
try{
 $db=Connection::getInstance();
 foreach(['appointments','patients','meal_plans','meal_plan_meals','meal_plan_items','anamneses','exam_requests','exam_request_items','migrations'] as$table){
  if(!SchemaInfo::hasTable($db,$table))$issues[]='Tabela ausente: '.$table;
  elseif($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);if(strtoupper((string)$s->fetchColumn())!=='INNODB')$issues[]='Exige InnoDB: '.$table;}
 }
 if(!SchemaInfo::hasColumn($db,'clinical_protocols','clinic_id')||!SchemaInfo::hasColumn($db,'preconsultation_questionnaires','answer_revision'))$issues[]='A migração 79 precisa estar aplicada.';
 if(!SchemaInfo::hasTable($db,'workflow_write_receipts'))$pending[]='Migração 80: workflow_write_receipts';
 foreach(['care_progress_events','care_workflow_policies'] as $table){
  if(!SchemaInfo::hasTable($db,$table))$pending[]='Migração 81: '.$table;
  elseif($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);if(strtoupper((string)$s->fetchColumn())!=='INNODB')$issues[]='Exige InnoDB: '.$table;}
 }
 if(in_array('--ready',$argv,true)&&$pending)$issues[]='Não liberar tráfego antes das migrações 80 e 81.';
}catch(Throwable$e){$issues[]='Não foi possível conferir o banco.';}
echo json_encode(['ready'=>!$issues,'pending'=>$pending,'issues'=>$issues],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit($issues?1:0);
