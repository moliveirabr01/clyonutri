<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Booking;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Domain\Notification\NotificationService;
use DateTimeImmutable;
use PDO;

/** Bounded keyset scan. A session/file lock serializes workers without locking the clinic during search. */
final class WaitlistBatchProcessor
{
    private BookingCalendarService $calendar;
    private mixed $fileLock=null;
    private ?string $mysqlLock=null;
    private ?int $oldLockTimeout=null;
    private float $deadline;
    public function __construct(private PDO $db,private ?DateTimeImmutable $fixedNow=null,private ?\Closure $timer=null){$this->calendar=new BookingCalendarService($db);}
    private function now():DateTimeImmutable{return ($this->fixedNow?:new DateTimeImmutable('now',BookingCalendarService::tz()))->setTimezone(BookingCalendarService::tz());}
    private function tick():float{return $this->timer?(float)($this->timer)():hrtime(true)/1e9;}
    private function due():bool{return $this->tick()>=$this->deadline;}
    private function mysql():bool{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    public function run(int $offerLimit=20,int $batchSize=25,int $maxExamined=100,float $seconds=3.0):array
    {
        $r=['offered'=>0,'expired'=>0,'failed'=>0,'examined'=>0,'days_checked'=>0,'pages'=>0,'max_page_rows'=>0,'budget_exhausted'=>false,'pass_complete'=>false];
        if($this->db->inTransaction())throw new \LogicException('O processador deve rodar fora de uma transação externa.');
        if(!$this->calendar->available() || !SchemaInfo::hasTable($this->db,'booking_waitlist_worker'))return $r+['status'=>'NOT_MIGRATED'];
        $offerLimit=max(1,min(100,$offerLimit));$batchSize=max(1,min(100,$batchSize));$maxExamined=max(1,min(1000,$maxExamined));
        $this->deadline=$this->tick()+max(0.01,min(10.0,$seconds));
        try{
            if(!$this->acquire())return $r+['status'=>'BUSY'];
            // Separate bounded expiry sweep: active offers never consume the scan page.
            $st=$this->db->prepare("SELECT w.id,w.clinic_id FROM booking_waitlist w LEFT JOIN booking_holds h ON h.id=w.hold_id AND h.clinic_id=w.clinic_id AND h.patient_id=w.patient_id WHERE w.status='offered' AND (h.id IS NULL OR h.status<>'held' OR h.expires_at_utc<=?) ORDER BY w.created_at,w.id LIMIT ".min($batchSize,$maxExamined));
            $st->execute([gmdate('Y-m-d H:i:s',$this->now()->getTimestamp())]);$expired=$st->fetchAll(PDO::FETCH_ASSOC);$st->closeCursor();
            foreach($expired as $item){if($this->due())break;$r['examined']++;$this->expireOffer($item,$r);}
            $state=$this->state();
            if((int)$state['pass_max_id']===0){$state['pass_max_id']=(int)$this->db->query('SELECT COALESCE(MAX(id),0) FROM booking_waitlist')->fetchColumn();$this->save($state);}
            while($r['examined']<$maxExamined && $r['offered']<$offerLimit && !$this->due()){
                $size=min($batchSize,$maxExamined-$r['examined']);
                $page=$this->db->prepare("SELECT * FROM booking_waitlist WHERE status='waiting' AND id<=? AND (created_at>? OR (created_at=? AND id>?)) ORDER BY created_at,id LIMIT ".$size);
                $page->execute([$state['pass_max_id'],$state['cursor_created'],$state['cursor_created'],$state['cursor_id']]);$rows=$page->fetchAll(PDO::FETCH_ASSOC);$page->closeCursor();$r['pages']++;$r['max_page_rows']=max($r['max_page_rows'],count($rows));
                if(!$rows){$this->save(['cursor_created'=>'1000-01-01 00:00:00','cursor_id'=>0,'pass_max_id'=>0,'resume_id'=>null,'resume_date'=>null]);$r['pass_complete']=true;break;}
                foreach($rows as $w){
                    if($this->due() || $r['offered']>=$offerLimit)break 2;
                    $r['examined']++;
                    try{
                        $complete=$this->search($w,$state,$r);
                        if(!$complete){$this->save($state);break 2;}
                        $state['cursor_created']=$w['created_at'];$state['cursor_id']=(int)$w['id'];$state['resume_id']=null;$state['resume_date']=null;$this->save($state);
                    }catch(\Throwable $e){$r['failed']++;error_log('[ClyoNutri][waitlist] '.get_class($e));$this->save($state);return $r+['status'=>'RETRY'];}
                }
            }
            $r['budget_exhausted']=!$r['pass_complete'] && ($this->due() || $r['examined']>=$maxExamined || $r['offered']>=$offerLimit);
            return $r+['status'=>'OK'];
        }finally{$this->release();}
    }
    private function search(array $w,array &$state,array &$r):bool
    {
        $now=$this->now();$clinic=(int)$w['clinic_id'];
        if($w['date_to']<$now->format('Y-m-d')){$this->calendar->mutate($clinic,function()use($w,&$r){$st=$this->db->prepare("UPDATE booking_waitlist SET status='expired',active_key=NULL,updated_at=? WHERE id=? AND clinic_id=? AND status='waiting'");$st->execute([$this->now()->format('Y-m-d H:i:s'),$w['id'],$w['clinic_id']]);$r['expired']+=$st->rowCount();});return true;}
        if(empty($this->calendar->policy($clinic)['waitlist_enabled']))return true;
        $active=$this->db->prepare("SELECT 1 FROM patients p JOIN clinics c ON c.id=p.clinic_id WHERE p.id=? AND p.clinic_id=? AND p.status='active' AND c.status='active'");$active->execute([$w['patient_id'],$clinic]);$ok=$active->fetchColumn();$active->closeCursor();
        if(!$ok){$this->calendar->mutate($clinic,function()use($w){$this->db->prepare("UPDATE booking_waitlist SET status='cancelled',active_key=NULL,updated_at=? WHERE id=? AND clinic_id=? AND status='waiting'")->execute([$this->now()->format('Y-m-d H:i:s'),$w['id'],$w['clinic_id']]);});return true;}
        $booking=new BookingService($this->db,$now);$prof=$booking->publicProfessional((int)$w['professional_id']);if(!$prof || $prof['clinic_id']!==$clinic)return true;
        $from=max($w['date_from'],$now->format('Y-m-d'));
        if((int)($state['resume_id']??0)===(int)$w['id'] && $state['resume_date'])$from=max($from,$state['resume_date']);
        for($date=new DateTimeImmutable($from,BookingCalendarService::tz());$date->format('Y-m-d')<=$w['date_to'];$date=$date->modify('+1 day')){
            $state['resume_id']=(int)$w['id'];$state['resume_date']=$date->format('Y-m-d');
            if($this->due())return false;
            // Expensive availability exploration happens with no clinic transaction/row lock.
            $slots=$booking->getAvailableSlots($clinic,(int)$w['professional_id'],$date->format('Y-m-d'),$prof['duration_minutes'],$w['modality']);$r['days_checked']++;
            if($this->due())return false;
            foreach($slots as $slot){
                $end=(new DateTimeImmutable($slot['scheduled_at'],BookingCalendarService::tz()))->modify('+'.$prof['duration_minutes'].' minutes')->format('H:i');
                if($slot['time']<$w['time_from'] || $end>$w['time_to'])continue;
                if($this->due())return false;
                try{if($this->offer($w,$slot['scheduled_at'],$end)){$r['offered']++;return true;}}
                catch(BookingConflictException|BookingUnavailableException){return true;}
                if($this->due())return false; // changed during search: retry on the next pass
            }
            $state['resume_date']=$date->modify('+1 day')->format('Y-m-d');$this->save($state);
        }return true;
    }
    private function offer(array $probe,string $at,string $end):bool
    {
        return $this->calendar->mutate((int)$probe['clinic_id'],function()use($probe,$at,$end){
            if($this->due())return false;
            $policy=$this->calendar->policy((int)$probe['clinic_id']);if(empty($policy['waitlist_enabled']))return false;
            $now=$this->now();$booking=new BookingService($this->db,$now);
            $prof=$booking->publicProfessional((int)$probe['professional_id']);
            if(!$prof || $prof['clinic_id']!==(int)$probe['clinic_id'])return false;
            $end=(new DateTimeImmutable($at,BookingCalendarService::tz()))->modify('+'.$prof['duration_minutes'].' minutes')->format('H:i');
            if($end>$probe['time_to'])return false;
            // A resumed cursor cannot let a younger request overtake an older compatible patient.
            $q=$this->db->prepare("SELECT w.* FROM booking_waitlist w JOIN patients p ON p.id=w.patient_id AND p.clinic_id=w.clinic_id JOIN clinics c ON c.id=w.clinic_id JOIN booking_settings bs ON bs.clinic_id=w.clinic_id
                WHERE w.clinic_id=? AND w.professional_id=? AND w.status='waiting' AND p.status='active' AND c.status='active' AND bs.is_enabled=1
                AND w.date_from<=? AND w.date_to>=? AND w.time_from<=? AND w.time_to>=?
                AND ((w.modality='presential' AND bs.allow_presential=1) OR (w.modality='telehealth' AND bs.allow_telehealth=1))
                AND (w.created_at<? OR (w.created_at=? AND w.id<=?)) ORDER BY w.created_at,w.id LIMIT 1".($this->mysql()?' FOR UPDATE':''));
            $q->execute([$probe['clinic_id'],$probe['professional_id'],substr($at,0,10),substr($at,0,10),substr($at,11,5),$end,$probe['created_at'],$probe['created_at'],$probe['id']]);$owner=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$owner)return false;
            // One candidate only: createHold revalidates its day, buffers, eligibility and conflicts under locks.
            $hold=$booking->createHold((int)$owner['clinic_id'],(int)$owner['professional_id'],$at,$prof['duration_minutes'],$owner['service_type'],$owner['modality'],'waitlist.'.$owner['id']);
            $expiry=gmdate('Y-m-d H:i:s',$now->getTimestamp()+60*(int)$policy['offer_ttl_minutes']);
            $this->db->prepare('UPDATE booking_holds SET patient_id=?,expires_at_utc=? WHERE id=? AND clinic_id=?')->execute([$owner['patient_id'],$expiry,$hold['id'],$owner['clinic_id']]);
            $this->db->prepare("UPDATE booking_waitlist SET status='offered',hold_id=?,updated_at=? WHERE id=? AND clinic_id=? AND status='waiting'")->execute([$hold['id'],$now->format('Y-m-d H:i:s'),$owner['id'],$owner['clinic_id']]);
            $n=new NotificationService($this->db);if(!$n->available())throw new \RuntimeException('Fila de avisos indisponível.');
            $n->publish((int)$owner['clinic_id'],'booking.waitlist_offer','patient',(int)$owner['patient_id'],'wl.'.$owner['id'].'.'.$hold['id'],'/paciente/lista-espera?prof='.$owner['professional_id']);
            $this->db->prepare("INSERT INTO audit_logs (clinic_id,action,resource,resource_id,user_agent,details) VALUES (?,'WAITLIST_OFFERED','booking_waitlist',?,'waitlist',NULL)")->execute([$owner['clinic_id'],(string)$owner['id']]);return true;
        });
    }
    private function expireOffer(array $item,array &$r):void
    {
        try{$this->calendar->mutate((int)$item['clinic_id'],function()use($item,&$r){
            $q=$this->db->prepare("SELECT w.*,h.status AS hold_status,h.expires_at_utc FROM booking_waitlist w LEFT JOIN booking_holds h ON h.id=w.hold_id AND h.clinic_id=w.clinic_id AND h.patient_id=w.patient_id WHERE w.id=? AND w.clinic_id=? AND w.status='offered'".($this->mysql()?' FOR UPDATE':''));$q->execute([$item['id'],$item['clinic_id']]);$w=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$w)return;
            if($w['hold_status']==='held' && $w['expires_at_utc']>gmdate('Y-m-d H:i:s',$this->now()->getTimestamp()))return;
            if($w['hold_status']==='held')$this->db->prepare("UPDATE booking_holds SET status='expired' WHERE id=? AND clinic_id=? AND status='held'")->execute([$w['hold_id'],$w['clinic_id']]);
            $this->db->prepare('UPDATE booking_waitlist SET status=?,active_key=NULL,updated_at=? WHERE id=? AND clinic_id=?')->execute([$w['hold_status']==='converted'?'fulfilled':'expired',$this->now()->format('Y-m-d H:i:s'),$w['id'],$w['clinic_id']]);$r['expired']++;
        });}catch(\Throwable $e){$r['failed']++;error_log('[ClyoNutri][waitlist-expire] '.get_class($e));}
    }
    private function state():array{$s=$this->db->query('SELECT * FROM booking_waitlist_worker WHERE id=1');$v=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();if(!$v)throw new \RuntimeException('Estado do processador ausente.');return $v;}
    private function save(array $s):void{$this->db->prepare('UPDATE booking_waitlist_worker SET cursor_created=?,cursor_id=?,pass_max_id=?,resume_id=?,resume_date=?,updated_at=? WHERE id=1')->execute([$s['cursor_created'],$s['cursor_id'],$s['pass_max_id'],$s['resume_id'],$s['resume_date'],$this->now()->format('Y-m-d H:i:s')]);}
    private function acquire():bool
    {
        if($this->mysql()){
            $name='clyo-waitlist:'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
            $q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);$ok=(int)$q->fetchColumn()===1;$q->closeCursor();if(!$ok)return false;$this->mysqlLock=$name;
            $this->oldLockTimeout=(int)$this->db->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();$this->db->exec('SET SESSION innodb_lock_wait_timeout=1');return true;
        }
        $rows=$this->db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);$file=$rows[0]['file']?:('memory-'.getmypid().'-'.spl_object_id($this->db));
        $this->fileLock=fopen(sys_get_temp_dir().'/clyo-waitlist-'.hash('sha256',$file).'.lock','c');return $this->fileLock!==false && flock($this->fileLock,LOCK_EX|LOCK_NB);
    }
    private function release():void
    {
        if($this->mysqlLock!==null){
            try{if($this->oldLockTimeout!==null)$this->db->exec('SET SESSION innodb_lock_wait_timeout='.$this->oldLockTimeout);}
            catch(\Throwable $e){error_log('[ClyoNutri][waitlist-release] '.get_class($e));}
            finally{try{$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$this->mysqlLock]);$q->closeCursor();}catch(\Throwable $e){error_log('[ClyoNutri][waitlist-release] '.get_class($e));}$this->mysqlLock=null;}
        }
        if(is_resource($this->fileLock)){flock($this->fileLock,LOCK_UN);fclose($this->fileLock);$this->fileLock=null;}
    }
}
