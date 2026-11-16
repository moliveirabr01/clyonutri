<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Clinical\{WorkflowWrites,WorkflowConflict};
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use PDO;

final class WorkflowController
{
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Connection::getInstance();}
    public function state(array $params): void {$this->handle($params,false);}
    public function mutate(array $params): void {$this->handle($params,true);}
    private function handle(array $params,bool $write): void
    {
        if(!headers_sent()){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');}
        $kind=(string)($params['kind']??'');$id=(int)($params['id']??0);$patient=(int)($_POST['patient_id']??$_GET['patient_id']??0);
        try{
            $ws=new WorkflowWrites($this->db);
            if(!$write){echo json_encode(['workflow'=>$ws->state($kind,$id,$patient)]);return;}
            $payload=$_POST;unset($payload['_csrf_token'],$payload['_request_key'],$payload['_base_revision'],$payload['atendimento']);
            $key=is_string($_POST['_request_key']??null)?$_POST['_request_key']:'';$base=is_string($_POST['_base_revision']??null)?$_POST['_base_revision']:'';
            $result=$ws->write($kind,$id,$patient,$key,$base,$payload,function()use($kind,$id,$patient,$payload){
                if($kind==='anamnesis'){
                    // NI2-220 (I4): autor do rascunho pela mesma regra clínica (clínica ativa, papel e CRN), nunca o
                    // primeiro professionals.user_id encontrado em qualquer clínica.
                    $author=\ClyoNutri\Domain\Clinical\ClinicalAuthor::resolve($this->db,(int)TenantContext::getClinicId(),(int)TenantContext::getUserId());
                    if($author===null)throw new \DomainException(\ClyoNutri\Domain\Clinical\ClinicalAuthor::SEM_CRN_MSG);
                    $saved=(new AnamnesisService($this->db))->saveAnamnesis($id,$author,$payload,true);
                    return ['resource_id'=>$id,'record_id'=>(int)$saved['id']];
                }
                if($kind==='exam'){
                    if(isset($payload['specific_instructions']))$payload['instructions']=$payload['specific_instructions'];
                    $saved=(new ExamRequestService($this->db))->saveDraft($patient,(int)TenantContext::getUserId(),$payload,$id?:null);
                    return ['resource_id'=>(int)$saved['id']];
                }
                if($kind==='plan')return $this->planOperation($id,$payload);
                throw new \InvalidArgumentException('Recurso inválido.');
            });
            echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }catch(WorkflowConflict $e){if(!headers_sent())http_response_code(409);echo json_encode(['status'=>'conflict','error'=>$e->getMessage()]);}
        catch(\ClyoNutri\Core\Security\TenantSecurityException $e){if(!headers_sent())http_response_code(404);echo json_encode(['error'=>'Registro não encontrado.']);}
        catch(\ClyoNutri\Domain\Clinical\ClinicalInputException $e){if(!headers_sent())http_response_code(422);echo json_encode(['error'=>$e->getMessage(),'field_errors'=>$e->fieldErrors()],JSON_UNESCAPED_UNICODE);}
        catch(\DomainException|\InvalidArgumentException $e){if(!headers_sent())http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
        catch(\Throwable $e){error_log('Workflow write failed: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine());if(!headers_sent())http_response_code(503);echo json_encode(['error'=>'Não foi possível confirmar o salvamento. Confira o estado salvo antes de repetir.']);}
    }
    private function planOperation(int $plan,array $p): array
    {
        $s=new MealPlanService($this->db);$action=$p['action']??'';
        $str=static fn($v)=>is_string($v)?trim($v):'';
        if($action==='meal')return ['resource_id'=>$plan,'meal_id'=>$s->addMeal($plan,$str($p['name']??''),$str($p['time_of_day']??'')?:null)];
        $meal=(int)($p['meal_id']??0);
        if(in_array($action,['item','copy'],true)){
            $st=$this->db->prepare('SELECT id FROM meal_plan_meals WHERE id=? AND meal_plan_id=?');$st->execute([$meal,$plan]);
            if(!$st->fetchColumn())throw new \InvalidArgumentException('Refeição não encontrada neste plano.');
        }
        if($action==='item'){
            $amount=\ClyoNutri\Domain\Nutrition\QuantityInput::parse($str($p['amount']??''));if($amount===null)throw new \InvalidArgumentException('Quantidade inválida. Use números como 1,5 ou 100.');
            $foodId=(int)($p['food_id']??0);
            $prevSt=$this->db->prepare('SELECT id, amount, measure_unit FROM meal_plan_items WHERE meal_id=? AND food_id=? ORDER BY id ASC LIMIT 1');
            $prevSt->execute([$meal,$foodId]);
            $prevRow=$prevSt->fetch(PDO::FETCH_ASSOC)?:null;
            $item=$s->addItem($meal,$foodId,$amount,$str($p['measure_unit']??'g'),$str($p['notes']??'')?:null,!empty($p['confirm_estimated']),true,!empty($p['confirm_allergen_conflict']),$str($p['allergen_ack_reason']??'')?:null);
            $res=['resource_id'=>$plan,'item_id'=>$item,'meal_id'=>$meal];
            // Reteste UX v54.62 (R-001/R-005): confirma qual alimento entrou e com quanto; avisa porção implausível.
            $infoSt=$this->db->prepare('SELECT f.name AS food_name, m.name AS meal_name, i.amount, i.measure_unit, i.weight_grams, i.energy_kcal FROM meal_plan_items i JOIN foods f ON f.id=i.food_id JOIN meal_plan_meals m ON m.id=i.meal_id WHERE i.id=?');
            $infoSt->execute([$item]);
            if($info=$infoSt->fetch(PDO::FETCH_ASSOC)){
                $grams=$info['weight_grams']!==null?(float)$info['weight_grams']:null;
                $res['feedback']=sprintf('Incluído em %s: %s (%s %s%s).',$info['meal_name'],$info['food_name'],\ClyoNutri\Domain\Nutrition\QuantityInput::format((float)$info['amount']),\ClyoNutri\Domain\Nutrition\QuantityInput::unitLabel((float)$info['amount'],(string)$info['measure_unit']),$grams!==null&&$info['measure_unit']!=='g'?', ≈ '.number_format($grams,0,',','.').' g':'');
                $warn=\ClyoNutri\Domain\Nutrition\QuantityInput::plausibilityWarning((string)$info['food_name'],(float)$info['amount'],(string)$info['measure_unit'],$grams,$info['energy_kcal']!==null?(float)$info['energy_kcal']:null);
                if($warn!==null)$res['warning']=$warn;
            }
            if($prevRow){
                $res['duplicate_in_meal']=['existing_item_id'=>(int)$prevRow['id'],'new_item_id'=>$item,'food_id'=>$foodId,'meal_id'=>$meal,'message'=>'Já existe na refeição — somar quantidades?'];
            }
            return $res;
        }
        if($action==='merge_duplicate'){
            $sourceId=(int)($p['item_id']??0);
            $targetId=(int)($p['target_item_id']??0);
            $st=$this->db->prepare('SELECT i.id, i.meal_id, i.food_id, i.amount, i.measure_unit, i.weight_grams, i.notes FROM meal_plan_items i JOIN meal_plan_meals m ON m.id=i.meal_id WHERE i.id=? AND m.meal_plan_id=?');
            $st->execute([$sourceId,$plan]);
            $src=$st->fetch(PDO::FETCH_ASSOC);
            if(!$src)throw new \InvalidArgumentException('Item duplicado não encontrado neste plano.');
            if($targetId<=0){
                $tSt=$this->db->prepare('SELECT id, meal_id, food_id, amount, measure_unit, weight_grams, notes FROM meal_plan_items WHERE meal_id=? AND food_id=? AND id<>? ORDER BY id ASC LIMIT 1');
                $tSt->execute([(int)$src['meal_id'],(int)$src['food_id'],$sourceId]);
                $tgt=$tSt->fetch(PDO::FETCH_ASSOC);
            }else{
                $st->execute([$targetId,$plan]);
                $tgt=$st->fetch(PDO::FETCH_ASSOC);
            }
            if(!$tgt||((int)$tgt['id']===$sourceId))throw new \InvalidArgumentException('Item principal para soma não encontrado.');
            $sameUnit=\ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::sameUnit((string)$tgt['measure_unit'],(string)$src['measure_unit']);
            if($sameUnit){
                $newAmt=round((float)$tgt['amount']+(float)$src['amount'],2);
                $newUnit=(string)$tgt['measure_unit'];
            }else{
                $newAmt=round((float)($tgt['weight_grams']??$tgt['amount'])+(float)($src['weight_grams']??$src['amount']),2);
                $newUnit='g';
            }
            $s->updateItem((int)$tgt['id'],$newAmt,$newUnit,$tgt['notes']?:$src['notes']?:null,null,$plan,true);
            $s->deleteItem($sourceId);
            return ['resource_id'=>$plan,'merged_into'=>(int)$tgt['id']];
        }
        if($action==='remove'){
            $item=(int)($p['item_id']??0);$st=$this->db->prepare('SELECT i.id FROM meal_plan_items i JOIN meal_plan_meals m ON m.id=i.meal_id WHERE i.id=? AND m.meal_plan_id=?');$st->execute([$item,$plan]);
            if(!$st->fetchColumn())throw new \InvalidArgumentException('Item não encontrado neste plano.');$s->deleteItem($item);return ['resource_id'=>$plan];
        }
        if(in_array($action,['edit_item','add_substitution'],true)){
            $item=(int)($p['item_id']??0);$st=$this->db->prepare('SELECT i.id FROM meal_plan_items i JOIN meal_plan_meals m ON m.id=i.meal_id WHERE i.id=? AND m.meal_plan_id=?');$st->execute([$item,$plan]);if(!$st->fetchColumn())throw new \InvalidArgumentException('Item não encontrado neste plano.');
            $amount=\ClyoNutri\Domain\Nutrition\QuantityInput::parse($str($p['amount']??''));if($amount===null)throw new \InvalidArgumentException('Quantidade inválida. Use números como 1,5 ou 100.');
            if($action==='edit_item')$s->updateItem($item,$amount,$str($p['measure_unit']??'g'),$str($p['notes']??'')?:null,null,$plan,!empty($p['confirm_estimated']));
            else $s->addSubstitution($item,(int)($p['food_id']??0),$amount,$str($p['measure_unit']??'g'),$str($p['notes']??'')?:null,!empty($p['confirm_estimated']),true,!empty($p['confirm_allergen_conflict']),$str($p['allergen_ack_reason']??'')?:null);
            return ['resource_id'=>$plan];
        }
        if(in_array($action,['edit_meal','remove_meal'],true)){
            $st=$this->db->prepare('SELECT id FROM meal_plan_meals WHERE id=? AND meal_plan_id=?');$st->execute([$meal,$plan]);if(!$st->fetchColumn())throw new \InvalidArgumentException('Refeição não encontrada neste plano.');
            if($action==='edit_meal')$s->updateMeal($meal,$str($p['name']??''),$str($p['time_of_day']??'')?:null,$str($p['notes']??'')?:null,$plan);else $s->deleteMeal($meal);
            return ['resource_id'=>$plan];
        }
        if(in_array($action,['edit_substitution','remove_substitution'],true)){
            $sub=(int)($p['sub_id']??0);$st=$this->db->prepare('SELECT s.id FROM meal_plan_substitutions s JOIN meal_plan_items i ON i.id=s.item_id JOIN meal_plan_meals m ON m.id=i.meal_id WHERE s.id=? AND m.meal_plan_id=?');$st->execute([$sub,$plan]);if(!$st->fetchColumn())throw new \InvalidArgumentException('Substituição não encontrada neste plano.');
            if($action==='edit_substitution'){
                $amount=\ClyoNutri\Domain\Nutrition\QuantityInput::parse($str($p['amount']??''));if($amount===null)throw new \InvalidArgumentException('Quantidade inválida. Use números como 1,5 ou 100.');
                $s->updateSubstitution($sub,$amount,$str($p['measure_unit']??'g'),$str($p['notes']??'')?:null,$plan,!empty($p['confirm_estimated']));
            }else $s->deleteSubstitution($sub);
            return ['resource_id'=>$plan];
        }
        if($action==='copy'){
            $st=$this->db->prepare('SELECT * FROM meal_plan_items WHERE meal_id=? ORDER BY ordering,id');$st->execute([$meal]);$items=$st->fetchAll(PDO::FETCH_ASSOC);
            if(!$items)throw new \InvalidArgumentException('Escolha uma refeição com alimentos para copiar.');
            $target=$s->addMeal($plan,$str($p['name']??''),$str($p['time_of_day']??'')?:null);
            foreach($items as $it){
                $new=$s->addItem($target,(int)$it['food_id'],(float)$it['amount'],$it['measure_unit'],$it['notes'],!empty($p['confirm_estimated']),true,!empty($p['confirm_allergen_conflict']),$str($p['allergen_ack_reason']??'')?:null);
                $sub=$this->db->prepare('SELECT * FROM meal_plan_substitutions WHERE item_id=? ORDER BY id');$sub->execute([$it['id']]);
                foreach($sub->fetchAll(PDO::FETCH_ASSOC) as $v)$s->addSubstitution($new,(int)$v['food_id'],(float)$v['amount'],$v['measure_unit'],$v['notes'],!empty($p['confirm_estimated']),true,!empty($p['confirm_allergen_conflict']),$str($p['allergen_ack_reason']??'')?:null);
            }
            return ['resource_id'=>$plan,'meal_id'=>$target];
        }
        throw new \InvalidArgumentException('Ação inválida.');
    }
}
