<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Booking;
use ClyoNutri\Core\Database\SchemaInfo;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use InvalidArgumentException;

/** Shared local-calendar rules for public booking and the internal appointment domain. */
final class BookingCalendarService
{
    public const HOLIDAY_LABELS = ['Feriado municipal', 'Feriado estadual', 'Feriado nacional', 'Ponto facultativo'];
    public function __construct(private PDO $db) {}
    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db,'booking_calendar_policies') && SchemaInfo::hasTable($this->db,'booking_calendar_exceptions');
    }
    public function policy(int $clinicId): array
    {
        $defaults=['buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'waitlist_enabled'=>0,'offer_ttl_minutes'=>15];
        if (!$this->available()) return $defaults;
        $st=$this->db->prepare('SELECT * FROM booking_calendar_policies WHERE clinic_id=?');$st->execute([$clinicId]);
        return ($st->fetch(PDO::FETCH_ASSOC) ?: []) + $defaults;
    }
    public static function tz(): DateTimeZone
    {
        return new DateTimeZone((string)(getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo')));
    }
    public static function date(string $date): string
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,self::tz());
        if (!$d || $d->format('Y-m-d')!==$date) throw new InvalidArgumentException('Informe uma data válida no formato AAAA-MM-DD.');
        return $date;
    }
    public static function time(string $time): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time)) throw new InvalidArgumentException('Informe um horário válido.');
        return $time;
    }
    /**
     * Converte o formato antigo 'working_days' em 'weekly_availability' estruturada caso necessário (NI2-584).
     */
    public static function normalizeWeeklyAvailability(array $prefs): array
    {
        $weekly = $prefs['weekly_availability'] ?? [];
        if (!empty($weekly) && is_array($weekly)) {
            return $weekly;
        }
        $workingDays = is_array($prefs['working_days'] ?? null) ? $prefs['working_days'] : [];
        if (!empty($workingDays)) {
            $weekly = [];
            foreach (['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'] as $d) {
                $enabled = in_array($d, $workingDays, true);
                $weekly[$d] = [
                    'enabled' => $enabled,
                    'start' => $enabled ? '08:00' : '',
                    'end' => $enabled ? '18:00' : '',
                    'break_start' => $enabled ? '12:00' : '',
                    'break_end' => $enabled ? '13:00' : '',
                ];
            }
            return $weekly;
        }
        return [];
    }
    public function rules(int $clinicId, ?string $date=null): array
    {
        if (!$this->available()) return [];
        $st=$this->db->prepare('SELECT * FROM booking_calendar_exceptions WHERE clinic_id=?'.($date!==null?' AND rule_date=?':'').' ORDER BY rule_date,id');
        $st->execute($date!==null?[$clinicId,$date]:[$clinicId]);return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Open exceptions replace the weekly windows; closures always win. Buffers must fit the resulting windows. */
    public function windows(int $clinicId,int $professionalId,string $date,array $weekly,bool $strict=true): array
    {
        self::date($date);$day=new DateTimeImmutable($date,self::tz());
        $keys=['seg','ter','qua','qui','sex','sab','dom'];$row=$weekly[$keys[(int)$day->format('N')-1]]??[];
        $ts=static fn(string $t):int=>(new DateTimeImmutable($date.' '.$t,self::tz()))->getTimestamp();
        $windows=[];$blocked=[];$ownOpen=[];$clinicOpen=[];
        if (!$strict && $weekly===[]) $windows=[[$day->getTimestamp(),$day->modify('+1 day')->getTimestamp()]];
        elseif (!empty($row['enabled'])) {
            $windows=[[$ts($row['start']),$ts($row['end'])]];
            if (!empty($row['break_start']) && !empty($row['break_end'])) $blocked[] = [$ts($row['break_start']),$ts($row['break_end'])];
        }
        foreach ($this->rules($clinicId,$date) as $rule) {
            if ($rule['professional_id']!==null && (int)$rule['professional_id']!==$professionalId) continue;
            $range=$rule['start_time']!==null?[$ts($rule['start_time']),$ts($rule['end_time'])]:[$day->getTimestamp(),$day->modify('+1 day')->getTimestamp()];
            if ($rule['kind']==='open') {
                if ($rule['professional_id']!==null) $ownOpen[]=$range;else $clinicOpen[]=$range;
            } else $blocked[]=$range;
        }
        if ($ownOpen || $clinicOpen) {
            $windows=$ownOpen ?: $clinicOpen;
            // Special opening replaces weekly breaks, but explicit calendar closures still win.
            $blocked=[];
            foreach ($this->rules($clinicId,$date) as $rule) {
                if ($rule['kind']==='open' || ($rule['professional_id']!==null && (int)$rule['professional_id']!==$professionalId)) continue;
                $blocked[]=$rule['start_time']!==null?[$ts($rule['start_time']),$ts($rule['end_time'])]:[$day->getTimestamp(),$day->modify('+1 day')->getTimestamp()];
            }
        }
        foreach ($blocked as [$b0,$b1]) {
            $next=[];
            foreach ($windows as [$s,$e]) {
                if ($b1<=$s || $b0>=$e) $next[]=[$s,$e];
                else { if($s<$b0)$next[]=[$s,min($e,$b0)];if($b1<$e)$next[]=[max($s,$b1),$e]; }
            }$windows=$next;
        }
        sort($windows);$merged=[];
        foreach ($windows as [$s,$e]) {
            $i=count($merged)-1;
            if($i>=0 && $s<=$merged[$i][1])$merged[$i][1]=max($merged[$i][1],$e);else $merged[]=[$s,$e];
        }
        return $merged;
    }
    public function allows(int $clinicId,int $profId,string $scheduledAt,int $duration,array $weekly,bool $strict=true): bool
    {
        $dt=new DateTimeImmutable($scheduledAt,self::tz());$p=$this->policy($clinicId);
        $start=$dt->getTimestamp()-60*(int)$p['buffer_before_minutes'];$end=$dt->getTimestamp()+60*($duration+(int)$p['buffer_after_minutes']);
        foreach($this->windows($clinicId,$profId,$dt->format('Y-m-d'),$weekly,$strict) as [$s,$e])if($start>=$s && $end<=$e)return true;
        return false;
    }
    public function overlaps(int $clinicId,string $a,int $aDuration,string $b,int $bDuration,?array $policy=null): bool
    {
        $p=$policy ?? $this->policy($clinicId);$before=(int)$p['buffer_before_minutes']*60;$after=(int)$p['buffer_after_minutes']*60;
        $at=(new DateTimeImmutable($a,self::tz()))->getTimestamp();$bt=(new DateTimeImmutable($b,self::tz()))->getTimestamp();
        return $at-$before < $bt+$bDuration*60+$after && $at+$aDuration*60+$after > $bt-$before;
    }
    public function mutate(int $clinicId,callable $fn): mixed
    {
        if (!$this->available()) throw new \RuntimeException('Execute a atualização de calendário antes de configurar a agenda.');
        $own=!$this->db->inTransaction();$sp='calendar_'.bin2hex(random_bytes(5));
        if($own)$this->db->beginTransaction();else $this->db->exec('SAVEPOINT '.$sp);
        try {
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$this->db->prepare('SELECT clinic_id FROM booking_settings WHERE clinic_id=? FOR UPDATE')->execute([$clinicId]);
            $result=$fn();if($own)$this->db->commit();else $this->db->exec('RELEASE SAVEPOINT '.$sp);return $result;
        }catch(\Throwable $e){if($own && $this->db->inTransaction())$this->db->rollBack();elseif(!$own){$this->db->exec('ROLLBACK TO SAVEPOINT '.$sp);$this->db->exec('RELEASE SAVEPOINT '.$sp);}throw $e;}
    }
    public function savePolicy(int $clinicId,array $input): void
    {
        $values=[];
        foreach(['buffer_before_minutes'=>[0,120],'buffer_after_minutes'=>[0,120],'offer_ttl_minutes'=>[5,60]] as $k=>[$min,$max]) {
            if(filter_var($input[$k]??null,FILTER_VALIDATE_INT)===false || (int)$input[$k]<$min || (int)$input[$k]>$max)throw new InvalidArgumentException('Informe de '.$min.' a '.$max.' minutos para '.(['buffer_before_minutes'=>'o intervalo anterior','buffer_after_minutes'=>'o intervalo posterior','offer_ttl_minutes'=>'o prazo da oferta'][$k]).'.');
            $values[$k]=(int)$input[$k];
        }
        $this->mutate($clinicId,function()use($clinicId,$input,$values){
            $this->db->prepare('DELETE FROM booking_calendar_policies WHERE clinic_id=?')->execute([$clinicId]);
            $this->db->prepare('INSERT INTO booking_calendar_policies (clinic_id,buffer_before_minutes,buffer_after_minutes,waitlist_enabled,offer_ttl_minutes,updated_at) VALUES (?,?,?,?,?,?)')
                ->execute([$clinicId,$values['buffer_before_minutes'],$values['buffer_after_minutes'],!empty($input['waitlist_enabled'])?1:0,$values['offer_ttl_minutes'],date('Y-m-d H:i:s')]);
            if(empty($input['waitlist_enabled']) && SchemaInfo::hasTable($this->db,'booking_waitlist')) {
                $this->db->prepare("UPDATE booking_holds SET status='released' WHERE clinic_id=? AND status='held' AND id IN (SELECT hold_id FROM booking_waitlist WHERE clinic_id=? AND status='offered')")->execute([$clinicId,$clinicId]);
                $this->db->prepare("UPDATE booking_waitlist SET status='waiting',hold_id=NULL,updated_at=? WHERE clinic_id=? AND status='offered'")->execute([date('Y-m-d H:i:s'),$clinicId]);
            }
        });
    }
    private function ruleValues(array $input): array
    {
        $date=self::date((string)($input['rule_date']??''));$kind=(string)($input['kind']??'');
        if(!in_array($kind,['holiday','closed','open'],true))throw new InvalidArgumentException('Tipo de exceção inválido.');
        $period=(string)($input['period']??'');
        if(!in_array($period,['','full','partial'],true))throw new InvalidArgumentException('Período inválido.');
        $start=trim((string)($input['start_time']??''));$end=trim((string)($input['end_time']??''));
        if($period==='full' && $kind!=='open'){$start='';$end='';}
        if($kind==='open' || $period==='partial' || $start!=='' || $end!==''){
            self::time($start);self::time($end);
            if($end<=$start)throw new InvalidArgumentException('O fim precisa ser posterior ao início no mesmo dia.');
        }
        $label=trim((string)($input['label']??''));
        if($kind==='holiday'){
            // NI2-615: o feriado guarda o tipo e, opcionalmente, o nome ("Feriado nacional: Finados").
            if(!in_array($label,self::HOLIDAY_LABELS,true))throw new InvalidArgumentException('Escolha Feriado municipal, Feriado estadual, Feriado nacional ou Ponto facultativo.');
            $label=self::holidayLabel($label,(string)($input['holiday_name']??''));
        }
        if($label==='' || mb_strlen($label)>100)throw new InvalidArgumentException('Informe uma descrição de até 100 caracteres, sem dados de pacientes.');
        return [$date,$kind,$start?:null,$end?:null,$label];
    }
    /** NI2-615: "Feriado nacional" + "Finados" → "Feriado nacional: Finados". Nome opcional, até 60 caracteres. */
    public static function holidayLabel(string $type,string $name): string
    {
        if(!in_array($type,self::HOLIDAY_LABELS,true))throw new InvalidArgumentException('Escolha Feriado municipal, Feriado estadual, Feriado nacional ou Ponto facultativo.');
        $name=trim((string)preg_replace('/\s+/u',' ',$name));
        if($name==='')return $type;
        if(mb_strlen($name)>60)throw new InvalidArgumentException('O nome do feriado pode ter até 60 caracteres.');
        if(preg_match('/[<>{}\d@]/u',$name))throw new InvalidArgumentException('Use só o nome do feriado (ex.: Finados), sem números nem dados de pacientes.');
        return $type.': '.$name;
    }
    /** @return array{0:string,1:string} [tipo, nome] de um rótulo de feriado; nome vazio quando não há. */
    public static function splitHolidayLabel(string $label): array
    {
        foreach(self::HOLIDAY_LABELS as $type){
            if($label===$type)return [$type,''];
            if(str_starts_with($label,$type.': '))return [$type,mb_substr($label,mb_strlen($type)+2)];
        }
        return ['Feriado nacional',''];
    }
    /**
     * NI2-615: grava os feriados nacionais escolhidos na revisão. Só aceita datas da lista oficial do ano
     * (NationalHolidays) e pula as que já têm o mesmo feriado no mesmo escopo. Devolve [gravados, pulados].
     * @param list<string> $dates
     * @return array{0:int,1:int}
     */
    public function importNationalHolidays(int $clinicId,?int $profId,int $year,array $dates,bool $allowOptional=false): array
    {
        // v54.40: com $allowOptional, aceita também os pontos facultativos de data móvel (Carnaval e Corpus
        // Christi), gravados como "Ponto facultativo: ..." — nunca como feriado nacional.
        $official=[];foreach(NationalHolidays::forYear($year) as $h)$official[$h['date']]=['Feriado nacional',$h['name']];
        if($allowOptional)foreach(NationalHolidays::optionalForYear($year) as $h)$official[$h['date']]=['Ponto facultativo',$h['name']];
        $dates=array_values(array_unique(array_filter($dates,'is_string')));
        if($dates===[])throw new InvalidArgumentException('Selecione ao menos um feriado para importar.');
        foreach($dates as $d)if(!isset($official[$d]))throw new InvalidArgumentException('A data '.$d.' não é feriado nacional de '.$year.'.');
        $saved=0;$skipped=0;
        foreach($dates as $d){
            [$type,$name]=$official[$d];$label=$type.': '.$name;
            $st=$this->db->prepare('SELECT COUNT(*) FROM booking_calendar_exceptions WHERE clinic_id=? AND rule_date=? AND kind=\'holiday\' AND label=? AND '.($profId===null?'professional_id IS NULL':'professional_id=?'));
            $st->execute($profId===null?[$clinicId,$d,$label]:[$clinicId,$d,$label,$profId]);
            if((int)$st->fetchColumn()>0){$skipped++;continue;}
            $this->addRule($clinicId,$profId,['rule_date'=>$d,'kind'=>'holiday','period'=>'full','label'=>$type,'holiday_name'=>$name]);
            $saved++;
        }
        return [$saved,$skipped];
    }
    public function addRule(int $clinicId,?int $profId,array $input): int
    {
        $values=$this->ruleValues($input);
        if($profId!==null)\ClyoNutri\Core\Security\TenantGuard::assertProfessionalBelongsToClinic($profId,$clinicId,$this->db);
        return $this->mutate($clinicId,function()use($clinicId,$profId,$values){
            $this->db->prepare('INSERT INTO booking_calendar_exceptions (clinic_id,professional_id,rule_date,kind,start_time,end_time,label,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge([$clinicId,$profId],$values,[date('Y-m-d H:i:s')]));
            return (int)$this->db->lastInsertId();
        });
    }
    public function updateRule(int $clinicId,int $id,?int $profId,array $input,?int $ownProfessional=null): void
    {
        $values=$this->ruleValues($input);
        if($profId!==null)\ClyoNutri\Core\Security\TenantGuard::assertProfessionalBelongsToClinic($profId,$clinicId,$this->db);
        $this->mutate($clinicId,function()use($clinicId,$id,$profId,$values,$ownProfessional){
            $st=$this->db->prepare('SELECT professional_id FROM booking_calendar_exceptions WHERE clinic_id=? AND id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
            $st->execute([$clinicId,$id]);$old=$st->fetch(PDO::FETCH_ASSOC);
            if(!$old || ($ownProfessional!==null && ($old['professional_id']===null || (int)$old['professional_id']!==$ownProfessional || $profId!==$ownProfessional)))throw new InvalidArgumentException('Exceção não encontrada ou sem permissão para editar.');
            $this->db->prepare('UPDATE booking_calendar_exceptions SET professional_id=?,rule_date=?,kind=?,start_time=?,end_time=?,label=? WHERE clinic_id=? AND id=?')->execute(array_merge([$profId],$values,[$clinicId,$id]));
        });
    }
    /** One query supplies all rule markers for the displayed day/week/month. */
    public function agendaRules(int $clinicId,string $from,string $to): array
    {
        self::date($from);self::date($to);
        if($to<$from)throw new InvalidArgumentException('Período inválido.');
        if(!$this->available())return [];
        $st=$this->db->prepare('SELECT e.*,u.name AS professional_name FROM booking_calendar_exceptions e LEFT JOIN professionals p ON p.id=e.professional_id LEFT JOIN users u ON u.id=p.user_id WHERE e.clinic_id=? AND e.rule_date>=? AND e.rule_date<=? ORDER BY e.rule_date,e.start_time,e.id');
        $st->execute([$clinicId,$from,$to]);$result=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $rule){
            $rule['period_label']=$rule['start_time']===null?'Dia inteiro':$rule['start_time'].'–'.$rule['end_time'];
            $rule['scope_label']=$rule['professional_id']===null?'Toda a clínica':($rule['professional_name']?:'Agenda do profissional');
            $result[$rule['rule_date']][]=$rule;
        }
        return $result;
    }
    public function blockingReason(int $clinicId,int $profId,string $at,int $duration): ?string
    {
        $dt=new DateTimeImmutable($at,self::tz());$policy=$this->policy($clinicId);
        $start=$dt->getTimestamp()-60*(int)$policy['buffer_before_minutes'];$end=$dt->getTimestamp()+60*($duration+(int)$policy['buffer_after_minutes']);
        foreach($this->rules($clinicId,$dt->format('Y-m-d')) as $rule){
            if($rule['kind']==='open' || ($rule['professional_id']!==null && (int)$rule['professional_id']!==$profId))continue;
            $date=$rule['rule_date'];$s=(new DateTimeImmutable($date.' '.($rule['start_time']??'00:00'),self::tz()))->getTimestamp();
            $e=$rule['end_time']===null?(new DateTimeImmutable($date,self::tz()))->modify('+1 day')->getTimestamp():(new DateTimeImmutable($date.' '.$rule['end_time'],self::tz()))->getTimestamp();
            if($start<$e && $end>$s)return $rule['label'].' em '.$dt->format('d/m/Y').' ('.($rule['start_time']===null?'dia inteiro':$rule['start_time'].'–'.$rule['end_time']).'). Escolha outro período ou ajuste a exceção nas Configurações.';
        }
        return null;
    }
    public function removeRule(int $clinicId,int $id,?int $ownProfessional=null): void
    {
        $this->mutate($clinicId,function()use($clinicId,$id,$ownProfessional){$st=$this->db->prepare('DELETE FROM booking_calendar_exceptions WHERE id=? AND clinic_id=?'.($ownProfessional!==null?' AND professional_id=?':''));$st->execute($ownProfessional!==null?[$id,$clinicId,$ownProfessional]:[$id,$clinicId]);if($st->rowCount()!==1)throw new InvalidArgumentException('Exceção não encontrada.');});
    }
}
