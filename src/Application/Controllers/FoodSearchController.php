<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
final class FoodSearchController
{
    public function search(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $term=$_GET['q']??'';$page=$_GET['page']??'1';
        if(!is_string($term)||mb_strlen($term)>80||!is_string($page)||!ctype_digit($page)||(int)$page<1||(int)$page>100){http_response_code(422);echo json_encode(['error'=>'Informe uma busca de até 80 caracteres e uma página válida.']);return;}
        try {
        $db=Connection::getInstance();$access=$db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id=u.id WHERE u.id=? AND cm.clinic_id=? AND u.role='nutritionist' AND u.status='active' AND cm.status='active' AND cm.role IN ('owner','admin','nutritionist')");$access->execute([TenantContext::getUserId(),TenantContext::getClinicId()]);if(!$access->fetchColumn()){http_response_code(403);echo json_encode(['error'=>'Acesso ao catálogo não autorizado.']);return;}
        $pairs=['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','É'=>'e','Ê'=>'e','Í'=>'i','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ú'=>'u','Ç'=>'c'];
        $normalize=static fn($value)=>mb_strtolower(strtr(trim($value),$pairs));
        $expression='name';foreach($pairs as $from=>$to)$expression="REPLACE($expression, '$from', '$to')";$expression="LOWER($expression)";
        $search=str_replace(['!','%','_'],['!!','!%','!_'],$normalize($term));
        $offset=((int)$page-1)*25;
        $stmt=$db->prepare("SELECT id,name,category,energy_kcal,protein_g,carbohydrate_g,lipid_g,source,(clinic_id IS NOT NULL) AS is_custom FROM foods WHERE (clinic_id IS NULL OR clinic_id=:cid) AND $expression LIKE :q ESCAPE '!' ORDER BY name,id LIMIT 26 OFFSET $offset");
        $stmt->execute(['cid'=>TenantContext::getClinicId(),'q'=>'%'.$search.'%']);$rows=array_map(static fn(array $r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'category'=>$r['category'],'energy_kcal'=>(float)$r['energy_kcal'],'protein_g'=>(float)$r['protein_g'],'carbohydrate_g'=>(float)$r['carbohydrate_g'],'lipid_g'=>(float)$r['lipid_g'],'source'=>$r['source'],'is_custom'=>(bool)$r['is_custom']],$stmt->fetchAll());$more=count($rows)>25;
        echo json_encode(['data'=>array_slice($rows,0,25),'has_more'=>$more,'page'=>(int)$page],JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { http_response_code(503); echo json_encode(['error'=>'Não foi possível carregar o catálogo. Tente novamente.']); }
    }
}
