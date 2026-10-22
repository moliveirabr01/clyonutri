<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Settings\ProfessionalProfile;
final class SettingsController
{
    public function show(): void
    {
        $service=new ProfessionalProfile(Connection::getInstance());$error=null;$success=false;
        try{$profile=$service->read();}catch(\DomainException $e){http_response_code(403);echo 'Acesso ao perfil não autorizado.';return;}catch(\Throwable $e){http_response_code(503);echo 'Não foi possível carregar o perfil. Tente novamente.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try{
                $section=$_POST['section']??'profile';
                if($section==='password'){
                    $service->changePassword($_POST);$_SESSION=[];session_regenerate_id(true);$_SESSION['flash_success']='Senha alterada com sucesso. Entre com a nova senha.';header('Location: /login');return;
                }elseif($section==='availability')$service->saveAvailability($_POST);
                elseif($section==='profile')$service->save($_POST);
                elseif($section==='booking'){
                    // Política de autoagendamento é da clínica: só a gestão altera (NI2-433).
                    if(!in_array($profile['member_role'],['owner','admin'],true))throw new \DomainException('Somente a gestão do consultório pode alterar o agendamento online.');
                    $bookingSvc=new BookingService(Connection::getInstance());
                    $before=$bookingSvc->getSettings(TenantContext::getClinicId());
                    $after=$bookingSvc->updateSettings(TenantContext::getClinicId(),[
                        'is_enabled'=>!empty($_POST['booking_enabled']),
                        'allow_presential'=>!empty($_POST['booking_allow_presential']),
                        'allow_telehealth'=>!empty($_POST['booking_allow_telehealth']),
                        'min_advance_hours'=>$_POST['booking_min_advance_hours']??'',
                        'max_future_days'=>$_POST['booking_max_future_days']??'',
                        'hold_ttl_minutes'=>$_POST['booking_hold_ttl_minutes']??'',
                    ]);
                    (new \ClyoNutri\Core\Audit\AuditLogger(Connection::getInstance()))->log('BOOKING_SETTINGS_UPDATED','booking_settings',(string)TenantContext::getClinicId(),['before'=>$before,'after'=>$after]);
                }
                elseif($section==='chat'){
                    // NI2-138: tempo de resposta informado à paciente e prazo de guarda do chat. Só a gestão altera.
                    if(!in_array($profile['member_role'],['owner','admin'],true))throw new \DomainException('Somente a gestão do consultório pode alterar as regras do chat.');
                    (new \ClyoNutri\Domain\Chat\ChatSettingsService(Connection::getInstance()))->update(TenantContext::getClinicId(),is_string($_POST['chat_response_time']??null)?$_POST['chat_response_time']:null,$_POST['chat_retention_years']??'',(int)TenantContext::getUserId());
                }
                elseif($section==='notifications'){
                    // NI2-95: avisos do próprio profissional (e-mail de nova mensagem do chat e horário de silêncio).
                    $profId=self::professionalId();
                    if($profId<=0)throw new \DomainException('Somente profissionais com cadastro completo recebem avisos.');
                    $res=(new \ClyoNutri\Domain\Notification\NotificationService(Connection::getInstance()))->savePreferences(TenantContext::getClinicId(),'professional',$profId,[
                        'categories'=>['chat'=>['email_enabled'=>!empty($_POST['notify_chat_email'])]],
                        'quiet_hours'=>['enabled'=>!empty($_POST['quiet_enabled']),'start'=>is_string($_POST['quiet_start']??null)&&$_POST['quiet_start']!==''?$_POST['quiet_start']:'22:00','end'=>is_string($_POST['quiet_end']??null)&&$_POST['quiet_end']!==''?$_POST['quiet_end']:'07:00'],
                    ]);
                    (new \ClyoNutri\Core\Audit\AuditLogger(Connection::getInstance()))->log('NOTIFICATION_PREFERENCES_UPDATED','professionals',(string)$profId,['before'=>$res['before'],'after'=>$res['after']]);
                }
                elseif($section==='patient_actions'){
                    // NI2-137: o que a paciente pode fazer com a consulta pelo app. Regra da clínica: só a gestão altera.
                    if(!in_array($profile['member_role'],['owner','admin'],true))throw new \DomainException('Somente a gestão do consultório pode alterar o que a paciente faz pelo app.');
                    $bookingSvc=new BookingService(Connection::getInstance());
                    $before=$bookingSvc->getSettings(TenantContext::getClinicId());
                    $after=$bookingSvc->updateSettings(TenantContext::getClinicId(),[
                        'patient_can_confirm'=>!empty($_POST['patient_can_confirm']),
                        'patient_can_cancel'=>!empty($_POST['patient_can_cancel']),
                        'patient_can_request_reschedule'=>!empty($_POST['patient_can_request_reschedule']),
                        'patient_show_address'=>!empty($_POST['patient_show_address']),
                        'cancel_min_advance_hours'=>$_POST['booking_cancel_min_advance_hours']??'',
                        'reschedule_min_advance_hours'=>$_POST['booking_reschedule_min_advance_hours']??'',
                    ]);
                    (new \ClyoNutri\Core\Audit\AuditLogger(Connection::getInstance()))->log('PATIENT_APPOINTMENT_ACTIONS_UPDATED','booking_settings',(string)TenantContext::getClinicId(),['before'=>$before,'after'=>$after]);
                }
                else throw new \InvalidArgumentException('Selecione uma seção válida.');
                $profile=$service->read();$success=true;
            }
            catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
            catch(\Throwable $e){http_response_code(503);$error='Não foi possível salvar. Seus dados foram mantidos. Tente novamente.';}
        }
        $values=$profile;
        if($error)foreach(['name','clinic_name','specialty','bio','email','phone','crn','crn_region'] as $field)if(is_string($_POST[$field]??null))$values[$field]=$_POST[$field];
        $preferences=json_decode($profile['practice_preferences'],true)?:[];
        $weekly=$preferences['weekly_availability']??[];
        if (empty($weekly) && !empty($preferences['working_days'])) {
            $workingDays = is_array($preferences['working_days']) ? $preferences['working_days'] : [];
            $weekly = [];
            foreach (['seg','ter','qua','qui','sex','sab','dom'] as $d) {
                $enabled = in_array($d, $workingDays, true);
                $weekly[$d] = [
                    'enabled' => $enabled,
                    'start' => $enabled ? '08:00' : '',
                    'end' => $enabled ? '18:00' : '',
                    'break_start' => $enabled ? '12:00' : '',
                    'break_end' => $enabled ? '13:00' : '',
                ];
            }
        }
        $duration=$preferences['default_duration_minutes']??60;
        $defaultPrice=$preferences['default_price']??250.0;
        $modalities=$preferences['modalities']??['presencial', 'online'];
        if($error&&($_POST['section']??'')==='availability'){if(is_array($_POST['weekly']??null))$weekly=$_POST['weekly'];if(is_string($_POST['default_duration_minutes']??null))$duration=$_POST['default_duration_minutes'];}
        $successMessage=null;
        if($success){
            $lastSec=$_POST['section']??'';
            $successMessage=$lastSec==='availability'?'Disponibilidade de atendimento atualizada com sucesso.':($lastSec==='profile'?'Dados cadastrais e profissionais atualizados com sucesso.':($lastSec==='booking'?'Agendamento online atualizado.':($lastSec==='patient_actions'?'Ações da paciente no app atualizadas.':($lastSec==='chat'?'Regras do chat atualizadas.':($lastSec==='notifications'?'Avisos atualizados.':'Configurações atualizadas com sucesso.')))));
        }
        $bookingSettings=(new BookingService(Connection::getInstance()))->getSettings(TenantContext::getClinicId());
        $chatSettings=(new \ClyoNutri\Domain\Chat\ChatSettingsService(Connection::getInstance()))->get(TenantContext::getClinicId());
        $bookingProfessionalId=self::professionalId();
        $notificationPrefs=null;
        try{if($bookingProfessionalId>0)$notificationPrefs=(new \ClyoNutri\Domain\Notification\NotificationService(Connection::getInstance()))->preferences(TenantContext::getClinicId(),'professional',$bookingProfessionalId);}catch(\Throwable $e){$notificationPrefs=null;}
        $bookingBase=rtrim((string)(getenv('APP_URL')?:($_ENV['APP_URL']??'')),'/');
        $bookingLink=$bookingProfessionalId>0?$bookingBase.'/agendar/'.$bookingProfessionalId:null;
        $bookingHasAvailability=!empty($weekly)&&count(array_filter($weekly,static fn($r)=>is_array($r)&&!empty($r['enabled'])))>0;
        $canManageBooking=in_array($profile['member_role'],['owner','admin'],true);
        $revision=ProfessionalProfile::revision($profile);$title='Configurações';$activeNav='configuracoes';$clinicName=$_SESSION['clinic_name']??'';$userName=$_SESSION['user_name']??'';
        ob_start();require __DIR__.'/../../../views/settings/profile.php';$content=ob_get_clean();require __DIR__.'/../../../views/layouts/professional-shell.php';
    }

    private static function professionalId(): int
    {
        $st=Connection::getInstance()->prepare('SELECT id FROM professionals WHERE user_id=?');$st->execute([TenantContext::getUserId()]);
        return (int)$st->fetchColumn();
    }
}
