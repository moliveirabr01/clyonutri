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

        // Existing deep links and every POST keep their original validation and destination.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['tab'])) {
            $title='Configurações'; $activeNav='configuracoes';
            $clinicName=$_SESSION['clinic_name']??''; $userName=$_SESSION['user_name']??'';
            ob_start(); require __DIR__.'/../../../views/settings/home.php';
            $content=ob_get_clean(); require __DIR__.'/../../../views/layouts/professional-shell.php'; return;
        }

        $activeTab = is_string($_GET['tab'] ?? null) ? trim($_GET['tab']) : 'perfil';
        if (!in_array($activeTab, ['perfil', 'agenda', 'paciente-app', 'conta'], true)) {
            $activeTab = 'perfil';
        }

        // NI2-612: a aba Agenda é dividida em seções (uma por vez): horarios, link, feriados, regras.
        $agendaSection = self::agendaSection(is_string($_GET['secao'] ?? null) ? $_GET['secao'] : '', '');

        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $postedTab = is_string($_POST['tab'] ?? null) ? trim($_POST['tab']) : $activeTab;
            if (!in_array($postedTab, ['perfil', 'agenda', 'paciente-app', 'conta'], true)) {
                $postedTab = $activeTab;
            }
            try{
                $section=$_POST['section']??'profile';
                $sectionTabs=['profile'=>'perfil','availability'=>'agenda','booking'=>'agenda','password'=>'conta','chat'=>'paciente-app','notifications'=>'paciente-app','reminders'=>'paciente-app','self_service'=>'paciente-app','patient_actions'=>'paciente-app'];
                if(is_string($section)&&isset($sectionTabs[$section]))$postedTab=$sectionTabs[$section];
                if(is_string($section)&&str_starts_with($section,'calendar_'))$postedTab='agenda'; // NI2-612: erro volta à aba e seção certas
                if($section==='password'){
                    $service->changePassword($_POST);
                    // NI2-481 P1c: aviso de segurança ao profissional (não bloqueia a troca se o e-mail falhar).
                    $uid=(int)\ClyoNutri\Core\Auth\TenantContext::getUserId();
                    try{ \ClyoNutri\Core\Auth\ProfessionalSecurityNotifier::make(Connection::getInstance())->passwordChanged($uid, $_SERVER['REMOTE_ADDR']??null, $_SERVER['HTTP_USER_AGENT']??null); }
                    catch(\Throwable $e){ error_log('[ClyoNutri][settings] aviso de senha alterada falhou: '.$e->getMessage()); }
                    $_SESSION=[];session_regenerate_id(true);$_SESSION['flash_success']='Senha alterada com sucesso. Entre com a nova senha.';header('Location: /login');return;
                }elseif($section==='availability'){$service->saveAvailability($_POST);if($service->droppedBreaks)$_SESSION['availability_dropped_breaks']=$service->droppedBreaks;}
                elseif($section==='profile'){
                    $oldEmail=strtolower(trim((string)($_SESSION['user_email']??'')));
                    $uid=(int)\ClyoNutri\Core\Auth\TenantContext::getUserId();
                    $service->save($_POST);
                    // NI2-481 P1d: se o e-mail de acesso mudou, avisa o endereço antigo e confirma no novo.
                    $newEmail=strtolower(trim((string)($_SESSION['user_email']??'')));
                    if($oldEmail!==''&&$newEmail!==''&&$oldEmail!==$newEmail){
                        try{ \ClyoNutri\Core\Auth\ProfessionalSecurityNotifier::make(Connection::getInstance())->emailChanged($uid,$oldEmail,$newEmail,$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null); }
                        catch(\Throwable $e){ error_log('[ClyoNutri][settings] aviso de troca de e-mail falhou: '.$e->getMessage()); }
                    }
                }
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
                        'public_show_address'=>!empty($_POST['booking_public_show_address']),
                    ]);
                    (new \ClyoNutri\Core\Audit\AuditLogger(Connection::getInstance()))->log('BOOKING_SETTINGS_UPDATED','booking_settings',(string)TenantContext::getClinicId(),['before'=>$before,'after'=>$after]);
                }
                elseif(in_array($section,['calendar_policy','calendar_rule','calendar_update','calendar_remove','calendar_import'],true)){
                    if(!\ClyoNutri\Core\Security\Csrf::validate($_POST['_csrf_token']??''))throw new \DomainException('Sua sessão expirou. Atualize a página e tente novamente.');
                    $calendar=new \ClyoNutri\Domain\Booking\BookingCalendarService(Connection::getInstance());
                    $manager=in_array($profile['member_role'],['owner','admin'],true);
                    $ownProf=self::professionalId();$clinic=TenantContext::getClinicId();
                    $calendarSavedRuleId=0;
                    if($section==='calendar_policy'){
                        if(!$manager)throw new \DomainException('Somente a gestão altera os intervalos e a lista de espera.');
                        $calendar->savePolicy($clinic,$_POST);
                    }elseif(in_array($section,['calendar_rule','calendar_update','calendar_import'],true)){
                        $scope=(string)($_POST['calendar_scope']??'own');
                        if($scope==='clinic')$prof=null;
                        elseif($scope==='own')$prof=$ownProf;
                        elseif($manager && preg_match('/^prof:(\d+)$/D',$scope,$match))$prof=(int)$match[1];
                        else throw new \DomainException('Escopo da agenda não autorizado.');
                        if(($prof===null&&!$manager)||($prof!==null&&$prof<=0))throw new \DomainException('Escopo da agenda não autorizado.');
                        if($section==='calendar_update'){$calendarSavedRuleId=(int)($_POST['rule_id']??0);$calendar->updateRule($clinic,$calendarSavedRuleId,$prof,$_POST,$manager?null:$ownProf);}
                        elseif($section==='calendar_import'){
                            // NI2-615: grava só o que a nutricionista revisou e marcou na prévia.
                            // v54.40: "todos" = anos oferecidos juntos (ex.: 2026 e 2027), incluindo os pontos facultativos marcados.
                            $impDates=is_array($_POST['holiday_dates']??null)?array_values(array_filter($_POST['holiday_dates'],'is_string')):[];
                            if(($_POST['import_year']??'')==='todos'){
                                if($impDates===[])throw new \InvalidArgumentException('Selecione ao menos um feriado para importar.');
                                $imported=0;$importSkipped=0;
                                foreach(\ClyoNutri\Domain\Booking\NationalHolidays::importYears() as $impY){$sel=array_values(array_filter($impDates,static fn($d)=>str_starts_with($d,$impY.'-')));if($sel===[])continue;[$a,$b]=$calendar->importNationalHolidays($clinic,$prof,$impY,$sel,true);$imported+=$a;$importSkipped+=$b;}
                                if($imported+$importSkipped!==count(array_unique($impDates)))throw new \InvalidArgumentException('Há datas fora dos anos oferecidos.');
                            }else [$imported,$importSkipped]=$calendar->importNationalHolidays($clinic,$prof,(int)($_POST['import_year']??0),$impDates,true);
                            $_SESSION['calendar_import_result']=[$imported,$importSkipped];
                        }
                        else $calendarSavedRuleId=$calendar->addRule($clinic,$prof,$_POST);
                    }else{$calendar->removeRule($clinic,(int)($_POST['rule_id']??0),$manager?null:$ownProf);}
                    (new \ClyoNutri\Core\Audit\AuditLogger(Connection::getInstance()))->log('BOOKING_CALENDAR_UPDATED','booking_calendar',(string)$clinic,['section'=>$section,'rule_id'=>$calendarSavedRuleId?:null]);
                    $savedToken=\ClyoNutri\Domain\Settings\SettingsSaveFeedback::issue($section);
                    header('Location: /configuracoes?tab=agenda&saved='.$savedToken.'&secao='.self::agendaSection('',$section).'&calendar_saved=1#'.($calendarSavedRuleId>0?'calendar-rule-'.$calendarSavedRuleId:'calendar-heading'));
                    return;
                }
                elseif($section==='chat'){
                    // NI2-138: tempo de resposta informado à paciente e prazo de guarda do chat. Só a gestão altera.
                    if(!in_array($profile['member_role'],['owner','admin'],true))throw new \DomainException('Somente a gestão do consultório pode alterar as regras do chat.');
                    (new \ClyoNutri\Domain\Chat\ChatSettingsService(Connection::getInstance()))->update(TenantContext::getClinicId(),is_string($_POST['chat_response_time']??null)?$_POST['chat_response_time']:null,$_POST['chat_retention_years']??'',(int)TenantContext::getUserId());
                }
                elseif($section==='notifications'){
                    // NI2-95: avisos do próprio profissional (e-mail de nova mensagem do chat, de check-in recebido e horário de silêncio).
                    $profId=self::professionalId();
                    if($profId<=0)throw new \DomainException('Somente profissionais com cadastro completo recebem avisos.');
                    $res=(new \ClyoNutri\Domain\Notification\NotificationService(Connection::getInstance()))->savePreferences(TenantContext::getClinicId(),'professional',$profId,[
                        'categories'=>['chat'=>['email_enabled'=>!empty($_POST['notify_chat_email'])],'checkin'=>['email_enabled'=>!empty($_POST['notify_checkin_email'])]],
                        'quiet_hours'=>['enabled'=>!empty($_POST['quiet_enabled']),'start'=>is_string($_POST['quiet_start']??null)&&$_POST['quiet_start']!==''?$_POST['quiet_start']:'22:00','end'=>is_string($_POST['quiet_end']??null)&&$_POST['quiet_end']!==''?$_POST['quiet_end']:'07:00'],
                    ]);
                    (new \ClyoNutri\Core\Audit\AuditLogger(Connection::getInstance()))->log('NOTIFICATION_PREFERENCES_UPDATED','professionals',(string)$profId,['before'=>$res['before'],'after'=>$res['after']]);
                }
                elseif($section==='reminders'){
                    // NI2-95: antecedência do lembrete de consulta (1 a 72 h). A gestão define o da clínica; cada
                    // profissional pode ter o seu (em branco = segue o da clínica).
                    $rs=new \ClyoNutri\Domain\Notification\ReminderSettingsService(Connection::getInstance());
                    $clinicId=TenantContext::getClinicId();$profId=self::professionalId();$changes=[];
                    $isManager=in_array($profile['member_role'],['owner','admin'],true);
                    if(array_key_exists('reminder_clinic_hours',$_POST)&&!$isManager)throw new \DomainException('Somente a gestão do consultório pode alterar o lembrete padrão da clínica.');
                    if(array_key_exists('reminder_own_hours',$_POST)&&$profId<=0)throw new \DomainException('Somente profissionais com cadastro completo têm lembrete próprio.');
                    // Os dois campos juntos: ou salva os dois, ou nenhum (um valor inválido não deixa o outro gravado pela metade).
                    $rdb=Connection::getInstance();$rdb->beginTransaction();
                    try{
                        if(array_key_exists('reminder_clinic_hours',$_POST))$changes['clinic']=$rs->save($clinicId,0,is_string($_POST['reminder_clinic_hours'])?trim($_POST['reminder_clinic_hours']):null,(int)TenantContext::getUserId());
                        if(array_key_exists('reminder_own_hours',$_POST))$changes['professional']=$rs->save($clinicId,$profId,is_string($_POST['reminder_own_hours'])?trim($_POST['reminder_own_hours']):null,(int)TenantContext::getUserId());
                        if($changes===[])throw new \InvalidArgumentException('Informe com quantas horas de antecedência o lembrete deve sair.');
                        $rdb->commit();
                    }catch(\Throwable $e){if($rdb->inTransaction())$rdb->rollBack();throw $e;}
                    (new \ClyoNutri\Core\Audit\AuditLogger($rdb))->log('APPOINTMENT_REMINDER_SETTINGS_UPDATED','notification_reminder_settings',(string)$clinicId,['professional_id'=>$profId,'changes'=>$changes]);
                }
                elseif($section==='self_service'){
                    (new \ClyoNutri\Domain\Booking\PatientSelfService(Connection::getInstance()))->configure(!empty($_POST['direct_reschedule']));
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

                $savedToken=\ClyoNutri\Domain\Settings\SettingsSaveFeedback::issue($section);
                header("Location: /configuracoes?tab={$postedTab}&saved={$savedToken}" . ($postedTab === 'agenda' ? '&secao=' . self::agendaSection('', (string)$section) : '') . ($postedTab==='paciente-app'?'&secao='.urlencode($section).'#task-'.urlencode($section):''));
                return;
            }
            catch(\InvalidArgumentException|\DomainException $e){
                http_response_code($e instanceof \DomainException?409:422);
                $error=$e->getMessage();
                $activeTab = $postedTab;
                $agendaSection = self::agendaSection('', (string)($_POST['section'] ?? ''));
            }
            catch(\Throwable $e){
                http_response_code(503);
                $error='Não foi possível confirmar o salvamento. Seu preenchimento foi mantido; confira os valores salvos antes de tentar novamente.';
                $activeTab = $postedTab;
            }
        }
        $values=$profile;
        if($error)foreach(['name','clinic_name','specialty','bio','email','phone','crn','crn_region','clinic_email','clinic_document','clinic_phone','clinic_address_street','clinic_address_number','clinic_address_complement','clinic_address_neighborhood','clinic_address_city','clinic_address_state','clinic_address_zip'] as $field)if(is_string($_POST[$field]??null))$values[$field]=$_POST[$field];
        $preferences=json_decode($profile['practice_preferences'],true)?:[];
        $weekly=$preferences['weekly_availability']??[];
        // NI2-584: conta antiga (só dias marcados). O formulário vem pré-preenchido com 08:00–18:00 e pausa
        // 12:00–13:00 nos dias marcados, mas NADA é gravado ao abrir a tela: o link público só oferece horários
        // depois que a nutricionista confere e clica em "Confirmar estes horários".
        $legacyHoursPending=false;
        if (empty($weekly) && !empty($preferences['working_days'])) {
            $weekly = \ClyoNutri\Domain\Booking\BookingCalendarService::normalizeWeeklyAvailability($preferences);
            $legacyHoursPending = !empty($weekly);
        }
        $duration=$preferences['default_duration_minutes']??60;
        $defaultPrice=$preferences['default_price']??250.0;
        $modalities=$preferences['modalities']??['presencial', 'online'];
        if($error&&($_POST['section']??'')==='availability'){if(is_array($_POST['weekly']??null))$weekly=$_POST['weekly'];if(is_string($_POST['default_duration_minutes']??null))$duration=$_POST['default_duration_minutes'];}
        $lastSec=(($_SERVER['REQUEST_METHOD']??'GET')==='GET')?\ClyoNutri\Domain\Settings\SettingsSaveFeedback::consume($_GET['saved']??null):null;
        $success=!$error&&$lastSec!==null;
        $successMessage=null;
        if($success){
            $messages=['availability'=>'Horários de atendimento atualizados.','profile'=>'Dados cadastrais e profissionais atualizados.','booking'=>'Agendamento online atualizado.','patient_actions'=>'Regras de consulta no aplicativo atualizadas.','chat'=>'Regras do chat atualizadas.','notifications'=>'Avisos atualizados.','reminders'=>'Lembrete de consulta atualizado.','self_service'=>'Regras de remarcação atualizadas.'];
            $successMessage=str_starts_with($lastSec,'calendar_')?'Calendário atualizado. Confira as datas e os períodos na agenda.':($messages[$lastSec]??'Configurações salvas.');
        }
        if($success&&$lastSec==='availability'&&is_array($_SESSION['availability_dropped_breaks']??null)){$successMessage=($successMessage??'Configurações salvas.').' A pausa de '.implode(', ',$_SESSION['availability_dropped_breaks']).' ficava fora do horário de atendimento e foi removida.';unset($_SESSION['availability_dropped_breaks']);}
        if($success&&$lastSec==='calendar_import'&&is_array($_SESSION['calendar_import_result']??null)){[$impN,$impS]=$_SESSION['calendar_import_result'];$successMessage=($impN===1?'1 feriado nacional importado':$impN.' feriados nacionais importados').($impS>0?'; '.$impS.' já estava'.($impS===1?'':'m').' cadastrado'.($impS===1?'':'s').' e não foi'.($impS===1?'':'ram').' duplicado'.($impS===1?'':'s'):'').'.';}
        $bookingSettings=(new BookingService(Connection::getInstance()))->getSettings(TenantContext::getClinicId());
        $chatSettings=(new \ClyoNutri\Domain\Chat\ChatSettingsService(Connection::getInstance()))->get(TenantContext::getClinicId());
        $bookingProfessionalId=self::professionalId();
        $notificationPrefs=null;
        try{if($bookingProfessionalId>0)$notificationPrefs=(new \ClyoNutri\Domain\Notification\NotificationService(Connection::getInstance()))->preferences(TenantContext::getClinicId(),'professional',$bookingProfessionalId);}catch(\Throwable $e){$notificationPrefs=null;}
        $reminderSettings=null;
        try{$rsSvc=new \ClyoNutri\Domain\Notification\ReminderSettingsService(Connection::getInstance());if($rsSvc->available())$reminderSettings=$rsSvc->values(TenantContext::getClinicId(),max(0,$bookingProfessionalId))+['effective'=>$rsSvc->effective(TenantContext::getClinicId(),max(0,$bookingProfessionalId))];}catch(\Throwable $e){$reminderSettings=null;}
        if($error&&($_POST['section']??'')==='reminders'&&$reminderSettings){foreach(['clinic'=>'reminder_clinic_hours','professional'=>'reminder_own_hours'] as $k=>$f)if(is_string($_POST[$f]??null))$reminderSettings['posted_'.$k]=$_POST[$f];}
        $bookingBase=rtrim((string)(getenv('APP_URL')?:($_ENV['APP_URL']??'')),'/');
        // NI2-596: o link mostrado é sempre o apelido único (gerado aqui na primeira vez). Sem a migração 77, o número.
        $bookingLink=null;
        if($bookingProfessionalId>0){
            $slugSvc=new \ClyoNutri\Domain\Booking\PublicBookingSlug(Connection::getInstance());
            $slug=$slugSvc->ensure($bookingProfessionalId,(string)($profile['name']??''));
            $bookingLink=$bookingBase.'/agendar/'.($slug??$bookingProfessionalId);
        }
        $bookingHasAvailability=empty($legacyHoursPending)&&!empty($weekly)&&count(array_filter($weekly,static fn($r)=>is_array($r)&&!empty($r['enabled'])))>0;
        $noSlotsIn14Days=false;
        if(!empty($bookingSettings['is_enabled']) && $bookingProfessionalId>0){
            $noSlotsIn14Days=!(new BookingService(Connection::getInstance()))->hasAvailableSlotsInNextDays(TenantContext::getClinicId(),$bookingProfessionalId,14);
        }
        $canManageBooking=in_array($profile['member_role'],['owner','admin'],true);
        if($agendaSection==='regras' && !$canManageBooking)$agendaSection='horarios';
        // NI2-612: um aviso só por pendência do agendamento online (o mais específico), no topo da aba.
        $bookingSetupState=\ClyoNutri\Domain\Booking\BookingSetupState::classify(!empty($bookingSettings['is_enabled']),$preferences,static fn()=>!$noSlotsIn14Days);
        $calendarService=new \ClyoNutri\Domain\Booking\BookingCalendarService(Connection::getInstance());
        $calendarAvailable=$calendarService->available();
        $calendarPolicy=$calendarService->policy(TenantContext::getClinicId());
        $calendarProfessionals=[];$calendarProfessionalNames=[];
        if($canManageBooking){
            $cp=Connection::getInstance()->prepare("SELECT DISTINCT p.id,u.name FROM professionals p JOIN users u ON u.id=p.user_id JOIN clinic_members cm ON cm.user_id=u.id WHERE cm.clinic_id=? AND cm.status='active' AND u.status='active' AND cm.role IN ('owner','admin','nutritionist') ORDER BY u.name");$cp->execute([TenantContext::getClinicId()]);$calendarProfessionals=$cp->fetchAll(\PDO::FETCH_ASSOC);
            foreach($calendarProfessionals as $cpRow)$calendarProfessionalNames[(int)$cpRow['id']]=$cpRow['name'];
        }elseif($bookingProfessionalId>0)$calendarProfessionalNames[$bookingProfessionalId]='Minha agenda';
        $calendarRules=array_values(array_filter($calendarService->rules(TenantContext::getClinicId()),fn($r)=>$canManageBooking || $r['professional_id']===null || (int)$r['professional_id']===$bookingProfessionalId));
        // NI2-615: prévia da importação de feriados nacionais (?importar=2027): nada é gravado ao abrir.
        $holidayImportYears=\ClyoNutri\Domain\Booking\NationalHolidays::importYears();
        $holidayImportAll=($_GET['importar']??'')==='todos';
        $holidayImportYear=$holidayImportAll?0:(int)($_GET['importar']??0);
        $holidayImportPreview=null;
        if($holidayImportAll||in_array($holidayImportYear,$holidayImportYears,true)){
            // v54.40: feriados nacionais (marcados) + pontos facultativos de Carnaval e Corpus Christi (desmarcados).
            $existing=[];foreach($calendarRules as $r)if($r['kind']==='holiday')$existing[$r['rule_date']][]=$r['label'];
            $list=[];foreach($holidayImportAll?$holidayImportYears:[$holidayImportYear] as $impY)$list=array_merge($list,\ClyoNutri\Domain\Booking\NationalHolidays::forYear($impY),\ClyoNutri\Domain\Booking\NationalHolidays::optionalForYear($impY));
            usort($list,static fn($a,$b)=>strcmp($a['date'],$b['date']));
            $holidayImportPreview=array_map(static fn($h)=>$h+['optional'=>false,'already'=>in_array((!empty($h['optional'])?'Ponto facultativo: ':'Feriado nacional: ').$h['name'],$existing[$h['date']]??[],true),'other_rules'=>count($existing[$h['date']]??[])],$list);
        }
        $calendarImportResult=$_SESSION['calendar_import_result']??null;unset($_SESSION['calendar_import_result']);
        $waitlistSummary=[];
        if($calendarAvailable && $canManageBooking && \ClyoNutri\Core\Database\SchemaInfo::hasTable(Connection::getInstance(),'booking_waitlist')){
            $ws=Connection::getInstance()->prepare('SELECT status,COUNT(*) AS total FROM booking_waitlist WHERE clinic_id=? GROUP BY status');$ws->execute([TenantContext::getClinicId()]);$waitlistSummary=$ws->fetchAll(\PDO::FETCH_ASSOC);
        }
        // v37: resumo do plano no perfil. Os botões só aparecem para titular/administrador (mesma regra da tela de assinatura).
        $planSummary=null;$planCanManage=false;
        try{$subSvc=new \ClyoNutri\Domain\Subscription\SubscriptionService(Connection::getInstance());$planCanManage=$subSvc->canManageSubscription(TenantContext::getClinicId(),TenantContext::getUserId());$planSummary=$subSvc->getClinicSubscription(TenantContext::getClinicId());$planOpenDeletion=$planCanManage?(new \ClyoNutri\Domain\Privacy\AccountDeletionRequestService(Connection::getInstance(),$subSvc))->current(TenantContext::getClinicId()):null;}catch(\Throwable $e){$planSummary=null;$planOpenDeletion=null;}
        $communicationKeys=['patient_actions','self_service','chat','notifications','reminders'];
        $communicationSection=is_string($_GET['secao']??null)&&in_array($_GET['secao'],$communicationKeys,true)?$_GET['secao']:'';
        if($error&&is_string($_POST['section']??null)&&in_array($_POST['section'],$communicationKeys,true))$communicationSection=$_POST['section'];
        $settingsRecovery=new \ClyoNutri\Application\View\SettingsFormRecovery((bool)$error,$_POST);
        $revision=ProfessionalProfile::revision($profile);$title='Configurações';$activeNav='configuracoes';$clinicName=$_SESSION['clinic_name']??'';$userName=$_SESSION['user_name']??'';
        ob_start();require __DIR__.'/../../../views/settings/profile.php';$content=ob_get_clean();require __DIR__.'/../../../views/layouts/professional-shell.php';
    }

    /** NI2-612: seção da aba Agenda pelo parâmetro ?secao= ou, depois de um POST, pela seção do formulário. */
    public static function agendaSection(string $requested, string $postedSection): string
    {
        $map = ['availability' => 'horarios', 'booking' => 'link', 'calendar_rule' => 'feriados', 'calendar_update' => 'feriados',
            'calendar_remove' => 'feriados', 'calendar_import' => 'feriados', 'calendar_policy' => 'regras'];
        if (in_array($requested, ['horarios', 'link', 'feriados', 'regras'], true)) {
            return $requested;
        }
        return $map[$postedSection] ?? 'horarios';
    }

    private static function professionalId(): int
    {
        $st=Connection::getInstance()->prepare('SELECT id FROM professionals WHERE user_id=?');$st->execute([TenantContext::getUserId()]);
        return (int)$st->fetchColumn();
    }
}
