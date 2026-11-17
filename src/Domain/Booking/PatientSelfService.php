<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Booking;
use PDO;
use DateTimeImmutable;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Domain\Appointment\{AppointmentService,PatientAppointmentService,PatientAppointmentActionException};

final class PatientSelfService
{
    private BookingService $booking;
    public function __construct(private PDO $db,private ?DateTimeImmutable $fixedNow=null){$this->booking=new BookingService($db,$fixedNow);}
    private function identity(): array {return $this->booking->authenticatedPatient();}
    public function policy(int $clinic): bool
    {
        if (!SchemaInfo::hasTable($this->db,'booking_self_service_policies')) return false;
        $q=$this->db->prepare('SELECT direct_reschedule FROM booking_self_service_policies WHERE clinic_id=?');$q->execute([$clinic]);return (bool)$q->fetchColumn();
    }
    public function configure(bool $enabled): void
    {
        if (!in_array(TenantContext::getUser()['role']??'', ['owner','admin','nutritionist'],true)) throw new \DomainException('Acesso restrito à gestão da clínica.');
        $clinic=TenantContext::getClinicId();$user=(int)TenantContext::getUserId();
        $q=$this->db->prepare("SELECT 1 FROM users u JOIN clinic_members m ON m.user_id=u.id AND m.clinic_id=? JOIN clinics c ON c.id=m.clinic_id AND c.status='active' WHERE u.id=? AND u.status='active' AND m.status='active' AND m.role IN ('owner','admin') AND u.role IN ('owner','admin','nutritionist')");$q->execute([$clinic,$user]);
        if (!$q->fetchColumn())throw new \DomainException('Somente a gestão pode alterar a remarcação imediata.');
        if (!SchemaInfo::hasTable($this->db,'booking_self_service_policies'))throw new \DomainException('Aplique a migração 72 antes de configurar.');
        $this->db->beginTransaction();
        try {
            $this->lockSettings($clinic);
            $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
                ? 'INSERT INTO booking_self_service_policies (clinic_id,direct_reschedule,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE direct_reschedule=VALUES(direct_reschedule),updated_at=VALUES(updated_at)'
                : 'INSERT INTO booking_self_service_policies (clinic_id,direct_reschedule,updated_at) VALUES (?,?,?) ON CONFLICT(clinic_id) DO UPDATE SET direct_reschedule=excluded.direct_reschedule,updated_at=excluded.updated_at';
            $this->db->prepare($sql)->execute([$clinic,(int)$enabled,gmdate('Y-m-d H:i:s')]);
            (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log('DIRECT_RESCHEDULE_POLICY_UPDATED','booking_self_service_policies',(string)$clinic,['enabled'=>$enabled]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function overview(int $before=0): array
    {
        $p=$this->identity();$clinic=(int)$p['clinic_id'];
        $q=$this->db->prepare('SELECT a.id,a.scheduled_at,a.duration_minutes,a.status,a.type,u.name AS professional_name FROM appointments a JOIN professionals pr ON pr.id=a.professional_id JOIN users u ON u.id=pr.user_id WHERE a.clinic_id=? AND a.patient_id=?'.($before>0?' AND a.id<?':'').' ORDER BY a.id DESC LIMIT 21');$args=[$clinic,$p['id']];if($before>0)$args[]=$before;$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>20;$rows=array_slice($rows,0,20);
        $direct=$this->policy($clinic);$settings=$this->booking->getSettings($clinic);
        foreach($rows as &$r){$actions=$this->actions($r,$settings,$direct);$r+=['modality_label'=>\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType($r['type'])?'Teleconsulta':'Presencial','allowed_actions'=>$actions,'timezone'=>BookingCalendarService::tz()->getName()];}unset($r);
        $q=$this->db->prepare("SELECT p.id FROM professionals p JOIN users u ON u.id=p.user_id JOIN clinic_members m ON m.user_id=u.id AND m.clinic_id=? WHERE u.status='active' AND m.status='active' AND m.role IN ('owner','admin','nutritionist') ORDER BY u.name LIMIT 100");$q->execute([$clinic]);$professionals=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$pr=$this->booking->publicProfessional((int)$id);if($pr && $pr['clinic_id']===$clinic)$professionals[]=$pr;}
        return ['patient'=>['name'=>$p['name']],'appointments'=>$rows,'professionals'=>$professionals,'direct_reschedule'=>$direct,'timezone'=>BookingCalendarService::tz()->getName(),'next_before'=>$more?(int)end($rows)['id']:null];
    }
    private function owned(int $id,bool $lock=false): array
    {
        $p=$this->identity();$q=$this->db->prepare('SELECT * FROM appointments WHERE clinic_id=? AND patient_id=? AND id=?'.($lock&&$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$p['clinic_id'],$p['id'],$id]);return $q->fetch(PDO::FETCH_ASSOC) ?: throw PatientAppointmentActionException::notFound();
    }
    private function actions(array $a,array $settings,bool $direct): array
    {
        $now=$this->booking->now();$start=new DateTimeImmutable($a['scheduled_at'],BookingCalendarService::tz());$pending=false;
        if(SchemaInfo::hasTable($this->db,'appointment_change_requests')){$q=$this->db->prepare("SELECT 1 FROM appointment_change_requests WHERE clinic_id=? AND appointment_id=? AND status='pending' LIMIT 1");$q->execute([TenantContext::getClinicId(),$a['id']]);$pending=(bool)$q->fetchColumn();}
        $active=in_array($a['status'],['scheduled','confirmed'],true);
        return ['confirm'=>$a['status']==='scheduled'&&$settings['patient_can_confirm']&&$start>$now&&!$pending,
            'cancel'=>$active&&$settings['patient_can_cancel']&&$start->modify('-'.BookingService::patientMinAdvanceHours($settings,'cancel').' hours')>=$now,
            'reschedule'=>$active&&($direct||$settings['patient_can_request_reschedule'])&&!$pending&&$start->modify('-'.BookingService::patientMinAdvanceHours($settings,'reschedule').' hours')>=$now];
    }
    public function slots(int $id,string $date): array
    {
        $a=$this->owned($id);$settings=$this->booking->getSettings((int)$a['clinic_id']);
        if(!$this->actions($a,$settings,$this->policy((int)$a['clinic_id']))['reschedule'])throw new PatientAppointmentActionException('Remarcação indisponível pela política, prazo ou estado atual. Fale com o consultório.','ACTION_NOT_ALLOWED',403);
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,BookingCalendarService::tz());if(!$day||$day->format('Y-m-d')!==$date)throw new \InvalidArgumentException('Data inválida.');
        $mode=\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType($a['type'])?'telehealth':'presential';
        $slots=$this->booking->getAvailableSlots((int)$a['clinic_id'],(int)$a['professional_id'],$date,(int)$a['duration_minutes'],$mode,$id,true);
        return ['appointment_id'=>$id,'expected_scheduled_at'=>$a['scheduled_at'],'direct_reschedule'=>$this->policy((int)$a['clinic_id']),'timezone'=>BookingCalendarService::tz()->getName(),'slots'=>array_values(array_filter($slots,fn($s)=>$s['scheduled_at']!==$a['scheduled_at']))];
    }
    public function reschedule(int $id,string $from,string $to,string $key): array
    {
        $p=$this->identity();$clinic=(int)$p['clinic_id'];
        if(!preg_match('/^[A-Za-z0-9_.:-]{8,64}$/D',$key))throw new \InvalidArgumentException('Informe uma chave de solicitação de 8 a 64 caracteres.');
        foreach([$from,$to] as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,BookingCalendarService::tz());if(!$d||$d->format('Y-m-d H:i:s')!==$date)throw new \InvalidArgumentException('Use a data e hora retornadas pela disponibilidade.');}
        if($this->db->inTransaction())throw new \LogicException('Transação externa não permitida.');
        $this->db->beginTransaction();
        try {
            $this->lockSettings($clinic);
            $q=$this->db->prepare('SELECT * FROM booking_self_service_operations WHERE clinic_id=? AND patient_id=? AND idempotency_key=?');$q->execute([$clinic,$p['id'],$key]);$op=$q->fetch(PDO::FETCH_ASSOC);
            if($op){if((int)$op['appointment_id']!==$id||$op['from_scheduled_at']!==$from||$op['to_scheduled_at']!==$to)throw new BookingConflictException('Chave já utilizada para outra alteração.');$this->db->commit();return json_decode($op['result_json'],true,512,JSON_THROW_ON_ERROR)+['idempotent_replay'=>true];}
            if(!$this->policy($clinic))throw new PatientAppointmentActionException('A clínica não habilitou remarcação imediata. Use o pedido de remarcação.','ACTION_NOT_ALLOWED',403);
            $a=$this->owned($id,true);
            if($a['scheduled_at']!==$from)throw new BookingConflictException('A consulta mudou. Atualize a página antes de remarcar.');
            $available=$this->slots($id,substr($to,0,10));
            if(!in_array($to,array_column($available['slots'],'scheduled_at'),true))throw new BookingConflictException('Horário indisponível. Sua consulta original foi mantida.');
            (new AppointmentService($this->db,$this->fixedNow))->rescheduleFromPatientRequest($id,$to,null,'Remarcação imediata pelo paciente autenticado.');
            $this->db->prepare("INSERT INTO audit_logs (clinic_id,action,resource,resource_id,user_agent,details) VALUES (?,'APPOINTMENT_SELF_RESCHEDULED','appointments',?,'patient-self-service',NULL)")->execute([$clinic,(string)$id]);
            $result=['success'=>true,'appointment_id'=>$id,'scheduled_at'=>$to,'status'=>'scheduled','message'=>'Consulta remarcada. Confirme a presença no novo horário.'];
            $this->db->prepare('INSERT INTO booking_self_service_operations (clinic_id,patient_id,idempotency_key,appointment_id,from_scheduled_at,to_scheduled_at,result_json,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$clinic,$p['id'],$key,$id,$from,$to,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),gmdate('Y-m-d H:i:s')]);
            $notificationVars=['date'=>substr($to,8,2).'/'.substr($to,5,2).'/'.substr($to,0,4),'time'=>substr($to,11,5)];
            (new \ClyoNutri\Domain\Notification\NotificationService($this->db))->publish($clinic,'appointment.rescheduled','patient',(int)$p['id'],'self-reschedule.'.hash('sha256',$clinic.'.'.$p['id'].'.'.$key),'/paciente/consultas',$notificationVars);
            (new \ClyoNutri\Domain\Notification\NotificationService($this->db))->publish($clinic,'appointment.rescheduled','professional',(int)$a['professional_id'],'self-reschedule.'.hash('sha256',$clinic.'.'.$p['id'].'.'.$key),'/agenda',$notificationVars);
            (new BookingFlowMetrics($this->db))->count($clinic,'direct_rescheduled');$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function lockSettings(int $clinic): void
    {
        $q=$this->db->prepare('SELECT clinic_id FROM booking_settings WHERE clinic_id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$clinic]);if(!$q->fetchColumn())throw new BookingUnavailableException();
    }
}
