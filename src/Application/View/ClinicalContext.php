<?php
declare(strict_types=1);
namespace ClyoNutri\Application\View;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Anamnesis\{AnamnesisService,AnamnesisVersions};
use ClyoNutri\Domain\Patient\PatientAllergyRecord;
use PDO;
/** Presentation adapter over the existing canonical services. No writes or clinical inference. */
final class ClinicalContext
{
    private static int $instance=0;
    public static function read(PDO $db,int $patient): array
    {
        $clinic=TenantContext::getClinicId();$user=(int)TenantContext::getUserId();
        $st=$db->prepare("SELECT c.name AS clinic_name FROM clinic_members m JOIN users u ON u.id=m.user_id JOIN clinics c ON c.id=m.clinic_id WHERE m.clinic_id=? AND m.user_id=? AND m.status='active' AND u.status='active' AND c.status='active'");
        $st->execute([$clinic,$user]);$clinicName=$st->fetchColumn();if($clinicName===false)throw new \DomainException('Contexto não autorizado.');
        TenantGuard::assertPatientBelongsToClinic($patient,$clinic,$db);
        $st=$db->prepare('SELECT id,name FROM patients WHERE id=? AND clinic_id=?');$st->execute([$patient,$clinic]);$p=$st->fetch(PDO::FETCH_ASSOC);if(!$p)throw new \DomainException('Registro não encontrado.');
        $summary=(new AnamnesisService($db))->getClinicalSummaryForPrescription($patient);
        $versions=AnamnesisVersions::pick($db,$clinic,$patient,'a.*, u.name AS professional_name','LEFT JOIN professionals pr ON pr.id=a.professional_id LEFT JOIN users u ON u.id=pr.user_id');
        $record=PatientAllergyRecord::read($db,$clinic,$patient);$sources=[];
        foreach(['concluded'=>'Concluída','draft'=>'Rascunho — revisão pendente'] as $key=>$status){
            $row=$versions[$key];if(!$row)continue;
            $text=trim((string)($row['allergies']??''));$denied=($row['allergy_assessment']??'')==='denied';
            if($text===''&&!$denied)continue;
            $sources[]=['kind'=>$key,'label'=>'Anamnese v'.(int)$row['version'].' · '.$status,'value'=>$text!==''?$text:'Nega alergias (informação registrada)','date'=>$row['created_at'],'author'=>$row['professional_name']??null,'url'=>'/pacientes/'.$patient.'/anamnese?version='.(int)$row['version']];
        }
        if($record['allergies']!==''||$record['assessment']==='denied')$sources[]=['kind'=>'registration','label'=>'Cadastro do paciente — informação registrada, confirmar em consulta','value'=>$record['allergies']!==''?$record['allergies']:'Nega alergias (no cadastro)','date'=>null,'author'=>null,'url'=>'/pacientes/'.$patient.'/editar'];
        $st=$db->prepare('SELECT a.id,a.assessed_at,a.created_at,a.weight_kg,a.bmi FROM anthropometric_assessments a WHERE a.clinic_id=? AND a.patient_id=? ORDER BY COALESCE(a.assessed_at,a.created_at) DESC,a.id DESC LIMIT 1');$st->execute([$clinic,$patient]);$assessment=$st->fetch(PDO::FETCH_ASSOC)?:null;
        $st=$db->prepare("SELECT id,meal_plan_id,version_number,published_at FROM meal_plan_versions WHERE clinic_id=? AND patient_id=? AND status='published' ORDER BY published_at DESC,id DESC LIMIT 1");$st->execute([$clinic,$patient]);$published=$st->fetch(PDO::FETCH_ASSOC)?:null;
        $base=$versions['concluded'];$draft=$versions['draft'];
        $consultation=self::consultation($db,$clinic,$patient);
        $safetySources=[];if($base)$safetySources[]='Anamnese v'.(int)$base['version'].' · Concluída';if($draft)$safetySources[]='Anamnese v'.(int)$draft['version'].' · Rascunho — revisão pendente';if($record['allergies']!==''||$record['assessment']==='denied')$safetySources[]='Cadastro do paciente';
        // Do not promote registration notes or draft objectives to a current clinical objective.
        $current=$base&&!$summary['is_draft'];
        return ['patient'=>$p,'clinic_id'=>$clinic,'clinic_name'=>$clinicName,'summary'=>$summary,'base'=>$base,'draft'=>$draft,'allergy_sources'=>$sources,'safety_sources'=>$safetySources,'registration_hint'=>$record['notes_mention_allergy'],'restrictions'=>$current?($summary['restrictions']??null):null,'objectives'=>$current?($summary['objectives']??null):null,'medications'=>$current?($summary['medications_details']??null):null,'assessment'=>$assessment,'published'=>$published,'consultation'=>$consultation,'revision'=>hash('sha256',json_encode([$summary,$sources,$assessment,$published],JSON_UNESCAPED_UNICODE))];
    }
    /**
     * v54.26: item "Consulta" do painel (estava no painel da NI2-629 e saiu na NI2-685).
     * Mesma regra do cabeçalho do paciente (AppointmentService::careStepForPatient: em andamento, hoje, próxima),
     * mais a última consulta concluída. Só lê agendamentos da clínica atual e do paciente já conferido acima;
     * devolve apenas data, situação e id (sem observações, valores ou dados de outro paciente).
     * @return array{state:string,appointment:?array,last:?array}|null  null = agenda indisponível
     */
    private static function consultation(PDO $db,int $clinic,int $patient): ?array
    {
        try{
            $step=(new \ClyoNutri\Domain\Appointment\AppointmentService($db))->careStepForPatient($patient);
            $app=$step['appointment']??null;
            if($app!==null&&((int)$app['patient_id']!==$patient))$app=null;
            $eff=\ClyoNutri\Domain\Appointment\AppointmentService::effectiveAtSql('a'); // NI2-879: data em que a consulta aconteceu
            $st=$db->prepare("SELECT a.id,a.scheduled_at,{$eff} AS effective_at FROM appointments a WHERE a.clinic_id=? AND a.patient_id=? AND a.status='completed' ORDER BY effective_at DESC,a.id DESC LIMIT 1");
            $st->execute([$clinic,$patient]);$last=$st->fetch(PDO::FETCH_ASSOC)?:null;
            return ['state'=>$app?(string)$step['state']:'none','appointment'=>$app?['id'=>(int)$app['id'],'scheduled_at'=>(string)$app['scheduled_at']]:null,'last'=>$last?['id'=>(int)$last['id'],'scheduled_at'=>(string)$last['scheduled_at'],'effective_at'=>(string)($last['effective_at']??$last['scheduled_at'])]:null];
        }catch(\Throwable $e){error_log('CLINICAL_CONTEXT_APPOINTMENT_UNAVAILABLE '.get_class($e));return null;}
    }
    public static function render(PDO $db,int $patient): string
    {
        try{return self::renderData($db,self::read($db,$patient));}
        catch(\DomainException|\ClyoNutri\Core\Security\TenantSecurityException $e){return '';}
        catch(\Throwable $e){error_log('CLINICAL_CONTEXT_UNAVAILABLE '.get_class($e));return '<p class="op-message op-message--warning" role="status">Contexto clínico indisponível. Reabra o prontuário para conferir os dados antes de concluir.</p>';}
    }
    public static function renderData(PDO $db,array $context): string
    {
        $contextId='clinical-context-'.(int)$context['patient']['id'].'-'.(++self::$instance);
        $level=ob_get_level();ob_start();
        try{require __DIR__.'/../../../views/patients/_clinical_context.php';return (string)ob_get_clean();}
        catch(\Throwable $e){while(ob_get_level()>$level)ob_end_clean();throw $e;}
    }
}
