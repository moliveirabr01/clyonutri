<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Prescription\PrescriptionService;
use PDO;
final class PrescriptionController
{
    private PrescriptionService $service;
    public function __construct(PDO $db) {$this->service=new PrescriptionService($db);}
    public function index(array $params): void {$this->page($params);}
    public function show(array $params): void {$this->page($params);}
    public function mutate(array $params): void
    {
        $this->headers();
        if (!Csrf::validate($_POST['_csrf_token']??'')) {http_response_code(403);echo 'Formulário expirado. Recarregue a página.';return;}
        $patient=(int)($params['id']??0);$id=(int)($params['rxId']??0);
        try {
            switch ((string)($_POST['action']??'')) {
                case 'template':$this->service->saveTemplate($patient,$id,(string)($_POST['template_name']??''));break;
                case 'save':$id=$this->service->save($patient,$_POST,$id ?: null);break;
                case 'review':$this->service->review($patient,$id,(int)($_POST['revision']??0));break;
                case 'publish':
                    $f=$_FILES['signature']??[];
                    if (($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']??'') || ($f['size']??0)>1048576) throw new \DomainException('Envie a assinatura destacada .p7s de até 1 MB.');
                    $this->service->publish($patient,$id,(string)file_get_contents($f['tmp_name']),($_POST['confirmed']??'')==='1');break;
                case 'renew':$id=$this->service->renew($patient,$id);break;
                case 'cancel':$this->service->cancel($patient,$id,(string)($_POST['reason']??''));break;
                case 'followup':$this->service->followup($patient,$id,(string)($_POST['note']??''));break;
                default:throw new \DomainException('Ação inválida.');
            }
            header('Location: /pacientes/'.$patient.'/prescricoes/'.$id,true,303);
        } catch (\Throwable $e) {$this->page($params,$this->message($e),($_POST['action']??'')==='save'?$_POST:[]);}
    }
    public function download(array $params): void
    {
        $this->headers();
        try {self::send($this->service->download((int)$params['id'],(int)$params['rxId'],($params['file']??'')==='assinatura'));}
        catch (\Throwable $e) {http_response_code(422);echo htmlspecialchars($this->message($e),ENT_QUOTES,'UTF-8');}
    }
    private function page(array $params,?string $error=null,array $old=[]): void
    {
        $this->headers();
        try {
            $data=$this->service->overview((int)($params['id']??0));
            if (!$old && empty($params['rxId']) && !empty($_GET['modelo'])) $old=$this->service->template((int)$params['id'],(int)$_GET['modelo']);
            $rx=!empty($params['rxId'])?$this->service->detail((int)$params['id'],(int)$params['rxId']):null;
            $title='Prescrições de manipulados e suplementação';$activeNav='pacientes';$clinicName=$data['actor']['clinic_name'];$userName=$data['actor']['name'];$csrf=Csrf::token();
            if ($error) http_response_code(422);
            ob_start();require dirname(__DIR__,3).'/views/prescriptions/index.php';$content=ob_get_clean();
            require dirname(__DIR__,3).'/views/layouts/professional-shell.php';
        } catch (\Throwable $e) {http_response_code($e instanceof \DomainException?403:503);echo htmlspecialchars($this->message($e),ENT_QUOTES,'UTF-8');}
    }
    private function headers(): void {header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');}
    private function message(\Throwable $e): string
    {
        if ($e instanceof \DomainException) return $e->getMessage();
        error_log('PRESCRIPTION_OPERATION_FAILED '.get_class($e));return 'Não foi possível concluir. Confira a instalação do módulo ou tente novamente. Nenhuma emissão foi confirmada.';
    }
    public static function send(array $file): void
    {
        header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Type: '.$file['type']);header('Content-Disposition: attachment; filename="'.$file['filename'].'"');echo $file['bytes'];
    }
}
