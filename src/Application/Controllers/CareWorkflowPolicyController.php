<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Appointment\CareWorkflowPolicy;
final class CareWorkflowPolicyController
{
    public function page(): void
    {
        header('Cache-Control: no-store');$error=null;$saved=false;$db=Connection::getInstance();$service=new CareWorkflowPolicy($db);$clinic=TenantContext::getClinicId();
        try{
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
                if(!Csrf::validate($_POST['_csrf_token']??'')){http_response_code(403);echo 'Sessão expirada. Reabra as configurações.';return;}
                try{$service->save($clinic,(string)($_POST['type']??''),is_array($_POST['steps']??null)?$_POST['steps']:[],(string)($_POST['revision']??''),(string)($_POST['request_key']??''));$saved=true;}
                catch(\ClyoNutri\Domain\Clinical\WorkflowConflict $e){$error=$e->getMessage();http_response_code(409);}
                catch(\DomainException|\InvalidArgumentException $e){$error=$e->getMessage();http_response_code(422);}
            }
            $policies=[];foreach(CareWorkflowPolicy::TYPES as $key=>$label)$policies[$key]=$service->read($clinic,$key);
            $title='Etapas por tipo de consulta';$activeNav='configuracoes';ob_start();require __DIR__.'/../../../views/settings/care_workflow.php';$content=ob_get_clean();require __DIR__.'/../../../views/layouts/professional-shell.php';
        }catch(\Throwable $e){error_log('CARE_POLICY_UNAVAILABLE '.get_class($e));http_response_code(503);echo 'Configuração indisponível. Nenhuma gravação foi confirmada.';}
    }
}
