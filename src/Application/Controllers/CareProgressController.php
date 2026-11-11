<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Appointment\CareProgress;
use ClyoNutri\Domain\Clinical\WorkflowConflict;
final class CareProgressController
{
    public function save(array $params): void
    {
        header('Cache-Control: no-store');$id=(int)($params['id']??0);$patient=(int)($_POST['patient_id']??0);
        if(!Csrf::validate($_POST['_csrf_token']??'')){http_response_code(403);echo 'Sessão expirada. Reabra o atendimento antes de salvar.';return;}
        $str=static fn($key)=>is_string($_POST[$key]??null)?$_POST[$key]:'';
        try{
            (new CareProgress(Connection::getInstance()))->decide(TenantContext::getClinicId(),$patient,$id,$str('step'),$str('decision'),$str('reason'),$str('evidence'),$str('revision'),$str('request_key'));
            header('Location: /consulta/'.$id.'?progress_saved=1#care-progress',true,303);
        }catch(WorkflowConflict $e){http_response_code(409);echo 'O atendimento mudou. Nenhuma nova decisão foi gravada. <a href="/consulta/'.$id.'#care-progress">Reabrir e conferir o atendimento</a>';}
        catch(\DomainException|\InvalidArgumentException $e){http_response_code(422);echo 'Não foi possível registrar esta decisão. Confira o acesso e os dados do atendimento.';}
        catch(\Throwable $e){error_log('CARE_PROGRESS_WRITE_FAILED '.get_class($e));http_response_code(503);echo 'Não foi possível confirmar a gravação. Reabra o atendimento e confira antes de repetir.';}
    }
}
