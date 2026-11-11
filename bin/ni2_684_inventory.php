<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../vendor/autoload.php';
ob_start();require __DIR__.'/ux_view_inventory.php';$existing=json_decode(ob_get_clean(),true,64,JSON_THROW_ON_ERROR);
$shell=file_get_contents(__DIR__.'/../views/layouts/professional-shell.php');preg_match_all('/<a\b[^>]*href="([^"<]+)"[^>]*>(.*?)<\/a>/s',$shell,$matches,PREG_SET_ORDER);
$menu=[];foreach($matches as $match)$menu[]=['url'=>$match[1],'label'=>trim(preg_replace('/\s+/',' ',strip_tags(preg_replace('/<\?.*?\?>/s','',$match[2]))))];
$domains=[
 'profile'=>['route'=>'POST /configuracoes','service'=>'ProfessionalProfile::save','authorization'=>'professional active in clinic; clinic name owner/admin','concurrency'=>'profile revision'],
 'availability'=>['route'=>'POST /configuracoes','service'=>'ProfessionalProfile::saveAvailability','authorization'=>'own professional in clinic'],
 'booking'=>['route'=>'POST /configuracoes','service'=>'BookingService::updateSettings','authorization'=>'owner/admin'],
 'calendar_*'=>['route'=>'POST /configuracoes','service'=>'BookingCalendarService','authorization'=>'owner/admin clinic rules; member only own professional'],
 'chat'=>['route'=>'POST /configuracoes','service'=>'ChatSettingsService::update','authorization'=>'owner/admin'],
 'notifications'=>['route'=>'POST /configuracoes','service'=>'NotificationService::savePreferences','authorization'=>'own professional; tenant from session'],
 'reminders'=>['route'=>'POST /configuracoes','service'=>'ReminderSettingsService::save','authorization'=>'owner/admin clinic default; member own reminder'],
 'patient_actions'=>['route'=>'POST /configuracoes','service'=>'BookingService::updateSettings','authorization'=>'owner/admin'],
 'self_service'=>['route'=>'POST /configuracoes','service'=>'PatientSelfService::configure','authorization'=>'owner/admin (service)'],
 'password'=>['route'=>'POST /configuracoes','service'=>'ProfessionalProfile::changePassword','authorization'=>'current password; reauthentication and session invalidation'],
 'team'=>['route'=>'/configuracoes/equipe','service'=>'TeamService','authorization'=>'members can read; owner/admin writes'],
 'subscription'=>['route'=>'/configuracoes/assinatura','service'=>'SubscriptionService::canManageSubscription','authorization'=>'active owner/admin'],
];
echo json_encode(['issue'=>'NI2-684','build'=>'v54.20','global_menu'=>$menu,'primary_tasks'=>['/pacientes?novo=1','/planos','/configuracoes?tab=agenda&secao=horarios'],'owner_categories'=>\ClyoNutri\Application\View\SettingsNavigation::areas(true,true),'member_categories'=>\ClyoNutri\Application\View\SettingsNavigation::areas(false,false),'domain_contracts'=>$domains,'route_inventory'=>$existing['routes'],'view_inventory'=>$existing['views'],'note'=>'Visibility is discovery only. Existing server services retain authorization; authenticated POSTs retain Router CSRF checks. No ACL was relaxed.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
