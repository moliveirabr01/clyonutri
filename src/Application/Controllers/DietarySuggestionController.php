<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Dietary\DietarySuggestionService;
use ClyoNutri\Domain\Clinical\WorkflowConflict;
use PDO;

final class DietarySuggestionController
{
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Connection::getInstance();}
    public function page(array $params=[]):void
    {
        header('Cache-Control: no-store, private');
        $planId=(int)($params['id']??0);$service=new DietarySuggestionService($this->db);
        $error=null;$notice=null;$posted=[];
        try{
            $preview=$service->preview($planId);
            if($_SERVER['REQUEST_METHOD']==='POST'){
                $posted=$_POST;
                if(!Csrf::validate(is_string($_POST['_csrf_token']??null)?$_POST['_csrf_token']:'')){http_response_code(403);$error='A sessão do formulário expirou. Recarregue para confirmar.';}
                else{
                    $str=static fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
                    $result=$service->confirm($planId,$str('request_key'),$str('revision'),$str('context_sha256'),
                        is_array($_POST['selected']??null)?array_values($_POST['selected']):[],is_array($_POST['texts']??null)?$_POST['texts']:[],
                        $str('reason'),$str('confirmed')==='1');
                    $notice='Orientações confirmadas e incluídas no rascunho do plano. Nenhum alimento, porção ou publicação foi alterado.';
                    if(!empty($result['replayed']))$notice='Esta confirmação já havia sido salva. As orientações não foram duplicadas.';
                    $posted=[];$preview=$service->preview($planId);
                }
            }
        }catch(WorkflowConflict $e){http_response_code(409);$error=$e->getMessage();}
        catch(\InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
        catch(\DomainException|\ClyoNutri\Core\Security\TenantSecurityException $e){http_response_code(403);$error='Não foi possível acessar ou confirmar estas sugestões. Verifique o vínculo profissional e o contexto clínico.';}
        catch(\PDOException $e){http_response_code(503);$error='Não foi possível salvar agora. Confira o estado salvo antes de tentar novamente.';}
        if(!isset($preview)){echo '<h1>Sugestões dietéticas</h1><p>'.htmlspecialchars($error??'Não disponível.',ENT_QUOTES,'UTF-8').'</p>';return;}
        $title='Sugestões por comorbidade';$activeNav='planos';
        $clinicName=$_SESSION['clinic_name']??'';$userName=$_SESSION['user_name']??'';
        $csrfToken=Csrf::token();$requestKey=bin2hex(random_bytes(16));
        ob_start();require dirname(__DIR__,3).'/views/meal_plans/dietary_suggestions.php';$content=ob_get_clean();
        require dirname(__DIR__,3).'/views/layouts/professional-shell.php';
    }
}
