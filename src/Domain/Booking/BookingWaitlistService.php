<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Booking;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Domain\Notification\NotificationService;
use DateTimeImmutable;
use PDO;
use InvalidArgumentException;

final class BookingWaitlistService
{
    private BookingCalendarService $calendar;
    public function __construct(private PDO $db, private ?DateTimeImmutable $fixedNow=null, private ?\Closure $timer=null) { $this->calendar=new BookingCalendarService($db); }
    private function now(): DateTimeImmutable { return ($this->fixedNow ?: new DateTimeImmutable('now',BookingCalendarService::tz()))->setTimezone(BookingCalendarService::tz()); }
    public function available(): bool { return $this->calendar->available() && SchemaInfo::hasTable($this->db,'booking_waitlist'); }
    public function join(array $input): array
    {
        if(!$this->available())throw new BookingUnavailableException('A lista de espera está indisponível.');
        $booking=new BookingService($this->db,$this->now());$patient=$booking->authenticatedPatient();$clinicId=(int)$patient['clinic_id'];
        $profId=(int)($input['professional_id']??0);$prof=$booking->publicProfessional($profId);
        if(!$prof || $prof['clinic_id']!==$clinicId)throw new BookingUnavailableException();
        $from=BookingCalendarService::date((string)($input['date_from']??''));$to=BookingCalendarService::date((string)($input['date_to']??''));
        $start=BookingCalendarService::time((string)($input['time_from']??'08:00'));$end=BookingCalendarService::time((string)($input['time_to']??'18:00'));
        if($to<$from || $from<$this->now()->format('Y-m-d') || $to>$this->now()->modify('+365 days')->format('Y-m-d')
            || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>30 || $end<=$start)throw new InvalidArgumentException('Escolha um período futuro de até 31 dias e horários em ordem.');
        $mode=(string)($input['modality']??'presential');$type=BookingService::normalizeServiceType((string)($input['service_type']??'first_visit'));
        $settings=$booking->getSettings($clinicId);
        if(!$type || !in_array($mode,BookingService::MODALITIES,true) || !($settings[$mode==='telehealth'?'allow_telehealth':'allow_presential']))throw new InvalidArgumentException('Modalidade ou serviço indisponível.');
        return $this->calendar->mutate($clinicId,function()use($clinicId,$patient,$profId,$from,$to,$start,$end,$mode,$type){
            if(empty($this->calendar->policy($clinicId)['waitlist_enabled']))throw new BookingUnavailableException('A lista de espera não está habilitada.');
            $key=$patient['id'].'.'.$profId.'.'.$mode;
            $st=$this->db->prepare('SELECT * FROM booking_waitlist WHERE clinic_id=? AND active_key=?');$st->execute([$clinicId,$key]);
            if($existing=$st->fetch(PDO::FETCH_ASSOC)) {
                foreach(['date_from'=>$from,'date_to'=>$to,'time_from'=>$start,'time_to'=>$end,'service_type'=>$type] as $k=>$v)if($existing[$k]!==$v)throw new InvalidArgumentException('Você já tem uma solicitação ativa. Cancele-a antes de alterar o período.');
                return ['id'=>(int)$existing['id'],'status'=>$existing['status'],'duplicate'=>true];
            }
            $now=$this->now()->format('Y-m-d H:i:s');
            $this->db->prepare("INSERT INTO booking_waitlist (clinic_id,patient_id,professional_id,date_from,date_to,time_from,time_to,modality,service_type,status,active_key,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'waiting',?,?,?)")
                ->execute([$clinicId,$patient['id'],$profId,$from,$to,$start,$end,$mode,$type,$key,$now,$now]);
            $id=(int)$this->db->lastInsertId();$this->audit($clinicId,'WAITLIST_JOINED',$id);return['id'=>$id,'status'=>'waiting','duplicate'=>false];
        });
    }
    public function mine(): array
    {
        $patient=(new BookingService($this->db))->authenticatedPatient();
        if(!$this->available())return [];
        $st=$this->db->prepare("SELECT w.id,w.professional_id,u.name AS professional_name,w.date_from,w.date_to,w.time_from,w.time_to,w.modality,w.service_type,w.status,h.hold_token,h.scheduled_at,h.expires_at_utc FROM booking_waitlist w LEFT JOIN professionals pr ON pr.id=w.professional_id LEFT JOIN users u ON u.id=pr.user_id LEFT JOIN booking_holds h ON h.id=w.hold_id AND h.clinic_id=w.clinic_id AND h.patient_id=w.patient_id WHERE w.clinic_id=? AND w.patient_id=? AND w.status IN ('waiting','offered') ORDER BY w.created_at,w.id");
        $st->execute([$patient['clinic_id'],$patient['id']]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row)if($row['status']!=='offered' || (string)$row['expires_at_utc']<=gmdate('Y-m-d H:i:s',$this->now()->getTimestamp())){unset($row['hold_token']);if($row['status']==='offered')$row['status']='expired';}
        return $rows;
    }
    public function cancel(int $id): void
    {
        $patient=(new BookingService($this->db))->authenticatedPatient();$clinic=(int)$patient['clinic_id'];
        $this->calendar->mutate($clinic,function()use($patient,$clinic,$id){
            $st=$this->db->prepare('SELECT * FROM booking_waitlist WHERE id=? AND clinic_id=? AND patient_id=?'.($this->mysql()?' FOR UPDATE':''));$st->execute([$id,$clinic,$patient['id']]);$w=$st->fetch(PDO::FETCH_ASSOC);
            if(!$w)throw new InvalidArgumentException('Solicitação não encontrada.');
            if(!in_array($w['status'],['waiting','offered'],true))return;
            if($w['hold_id'])$this->db->prepare("UPDATE booking_holds SET status='released',updated_at=? WHERE id=? AND clinic_id=? AND patient_id=? AND status='held'")->execute([$this->now()->format('Y-m-d H:i:s'),$w['hold_id'],$clinic,$patient['id']]);
            $this->finish($w,'cancelled');$this->audit($clinic,'WAITLIST_CANCELLED',$id);
        });
    }
    /** Bounded resumable worker; the public request/offer contract remains unchanged. */
    public function process(int $limit=20,int $batchSize=25,int $maxExamined=100,float $seconds=3.0): array
    {
        if(!$this->available())return ['offered'=>0,'expired'=>0,'failed'=>0,'status'=>'NOT_MIGRATED'];
        return (new WaitlistBatchProcessor($this->db,$this->fixedNow,$this->timer))->run($limit,$batchSize,$maxExamined,$seconds);
    }
    public function staleOffer(array $d,int $now): ?string
    {
        if(!preg_match('/^wl\.(\d+)\.(\d+)$/D',(string)($d['dedupe_key']??''),$m))return 'offer_missing';
        $st=$this->db->prepare("SELECT w.status,w.professional_id,h.status AS hold_status,h.expires_at_utc,h.scheduled_at,h.duration_minutes,h.modality FROM booking_waitlist w JOIN booking_holds h ON h.id=w.hold_id AND h.clinic_id=w.clinic_id AND h.patient_id=w.patient_id WHERE w.id=? AND w.hold_id=? AND w.clinic_id=? AND w.patient_id=?");
        $st->execute([$m[1],$m[2],$d['clinic_id'],$d['recipient_id']]);$w=$st->fetch(PDO::FETCH_ASSOC);
        if (!$w || $d['recipient_type']!=='patient' || $w['status']!=='offered' || $w['hold_status']!=='held' || $w['expires_at_utc']<=gmdate('Y-m-d H:i:s',$now)) return 'offer_expired';
        if (empty($this->calendar->policy((int)$d['clinic_id'])['waitlist_enabled'])) return 'waitlist_paused';
        $booking=new BookingService($this->db,(new DateTimeImmutable('@'.$now))->setTimezone(BookingCalendarService::tz()));
        $prof=$booking->publicProfessional((int)$w['professional_id']);
        if(!$prof || $prof['clinic_id']!==(int)$d['clinic_id'])return 'offer_unavailable';
        $slots=$booking->getAvailableSlots((int)$d['clinic_id'],(int)$w['professional_id'],substr($w['scheduled_at'],0,10),(int)$w['duration_minutes'],$w['modality'],null,false,(int)$m[2]);
        return in_array($w['scheduled_at'],array_column($slots,'scheduled_at'),true)?null:'offer_unavailable';
    }
    private function finish(array $w,string $status): void { $this->db->prepare('UPDATE booking_waitlist SET status=?,active_key=NULL,updated_at=? WHERE id=? AND clinic_id=?')->execute([$status,$this->now()->format('Y-m-d H:i:s'),$w['id'],$w['clinic_id']]); }
    private function audit(int $clinic,string $action,int $id):void { $this->db->prepare("INSERT INTO audit_logs (clinic_id,action,resource,resource_id,user_agent,details) VALUES (?,?,'booking_waitlist',?,'waitlist',NULL)")->execute([$clinic,$action,(string)$id]); }
    private function mysql():bool {return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
}
