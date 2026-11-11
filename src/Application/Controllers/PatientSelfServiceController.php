<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use PDO;
use ClyoNutri\Domain\Booking\{PatientSelfService,BookingService,BookingConflictException,BookingUnavailableException,BookingFlowMetrics};
use ClyoNutri\Domain\Appointment\{PatientAppointmentActionException,AppointmentConflictException};
final class PatientSelfServiceController
{
    private PatientSelfService $service;
    public function __construct(private PDO $db){$this->service=new PatientSelfService($db);}
    public function page(): void
    {
        header('Cache-Control: no-store, private');
        try {$this->service->overview();require dirname(__DIR__,3).'/views/booking/patient_appointments.php';}
        catch(\Throwable $e){$this->error($e);}
    }
    public function list(): void {$this->run(fn()=>$this->service->overview(max(0,(int)($_GET['before']??0))));}
    public function slots(int $id): void {$this->run(function()use($id){$this->rate('slots',120);return $this->service->slots($id,(string)($_GET['date']??''));});}
    public function reschedule(int $id,array $body): void
    {
        $this->run(function()use($id,$body){
            $this->rate('reschedule',20);
            foreach(['expected_scheduled_at','scheduled_at','idempotency_key'] as $key)if(!is_string($body[$key]??null))throw new \InvalidArgumentException('Informe o horário original, o novo horário e a chave da solicitação.');
            return $this->service->reschedule($id,$body['expected_scheduled_at'],$body['scheduled_at'],$body['idempotency_key']);
        });
    }
    public function catalog(): void
    {
        $this->run(function(){
            $this->rate('catalog',120);$booking=new BookingService($this->db);$p=$booking->publicProfessional((int)($_GET['professional_id']??0));if(!$p)throw new BookingUnavailableException();$settings=$booking->getSettings($p['clinic_id']);
            return ['professional_id'=>$p['id'],'clinic_id'=>$p['clinic_id'],'timezone'=>\ClyoNutri\Domain\Booking\BookingCalendarService::tz()->getName(),'duration_minutes'=>$p['duration_minutes'],'modalities'=>array_values(array_filter([!empty($settings['allow_presential'])?'presential':null,!empty($settings['allow_telehealth'])?'telehealth':null])),'services'=>[['id'=>'first_visit','label'=>'Primeira consulta','duration_minutes'=>$p['duration_minutes']],['id'=>'follow_up','label'=>'Retorno','duration_minutes'=>$p['duration_minutes']]],'hold_ttl_minutes'=>$settings['hold_ttl_minutes'],'min_advance_hours'=>$settings['min_advance_hours'],'max_future_days'=>$settings['max_future_days']];
        });
    }
    public function metrics(): void {$this->run(fn()=>['period'=>'30 dias UTC','metrics'=>(new BookingFlowMetrics($this->db))->summary(\ClyoNutri\Core\Auth\TenantContext::getClinicId())]);}
    public function metricsPage(): void
    {
        header('Cache-Control: no-store, private');
        try {
            $metrics=(new BookingFlowMetrics($this->db))->summary(\ClyoNutri\Core\Auth\TenantContext::getClinicId());
            $title='Indicadores de autoagendamento';$activeNav='agenda';$actor=\ClyoNutri\Core\Auth\TenantContext::getUser();$clinicName=$actor['clinic_name']??'';$userName=$actor['name']??'';
            ob_start();require dirname(__DIR__,3).'/views/booking/metrics.php';$content=ob_get_clean();require dirname(__DIR__,3).'/views/layouts/professional-shell.php';
        }catch(\Throwable $e){$this->error($e);}
    }
    private function rate(string $action,int $max): void
    {
        (new \ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter($this->db))->hit('self_service_'.$action,(string)($_SERVER['REMOTE_ADDR']??'cli'),$max,600);
    }
    private function run(callable $fn): void
    {
        header('Cache-Control: no-store, private');header('Content-Type: application/json; charset=utf-8');
        try {echo json_encode($fn(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}catch(\Throwable $e){$this->error($e);}
    }
    private function error(\Throwable $e): void
    {
        if($e instanceof PatientAppointmentActionException){http_response_code($e->httpStatus);$body=$e->toArray();}
        elseif($e instanceof BookingUnavailableException){http_response_code(404);$body=['error'=>'Agendamento indisponível neste contexto.'];}
        elseif($e instanceof BookingConflictException || $e instanceof AppointmentConflictException){http_response_code(409);$body=['error'=>'A consulta ou o horário mudou. Atualize a disponibilidade; sua tentativa não foi confirmada.','code'=>'SLOT_CONFLICT'];}
        elseif($e instanceof \ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException){http_response_code(429);$body=['error'=>'Muitas tentativas. Aguarde antes de tentar novamente.'];}
        elseif($e instanceof \DomainException){http_response_code(403);$body=['error'=>$e->getMessage()];}
        elseif($e instanceof \InvalidArgumentException){http_response_code(422);$body=['error'=>$e->getMessage()];}
        else {http_response_code(503);error_log('PATIENT_SELF_SERVICE_FAILED '.get_class($e));$body=['error'=>'Não foi possível concluir agora. Atualize a página antes de tentar novamente.'];}
        header('Content-Type: application/json; charset=utf-8');echo json_encode($body,JSON_UNESCAPED_UNICODE);
    }
}
