<?php
declare(strict_types=1);
putenv('V54_DB='.sys_get_temp_dir().'/ni2-888-'.getmypid().'.sqlite');
require __DIR__.'/_v54_bootstrap.php';
ob_start();
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Application\Controllers\{DashboardController,SettingsController};
use ClyoNutri\Domain\Booking\{BookingService,BookingManagementAccess};
use ClyoNutri\Application\View\NewConsultationButton;
$c=Fx::clinic($pdo,'Clínica TESTE 888');$owner=Fx::user($pdo,$c,'Gestor TESTE','owner');$reception=Fx::user($pdo,$c,'Recepção TESTE','receptionist');$nutri=Fx::user($pdo,$c,'Nutri TESTE','nutritionist');$admin=Fx::user($pdo,$c,'Admin TESTE','admin');
$p=Fx::patient($pdo,$c,'Paciente TESTE');Fx::act($owner);
$_SERVER['REQUEST_METHOD']='GET';$_SESSION=['user_name'=>'Gestor TESTE','clinic_name'=>'Clínica TESTE'];$_POST=[];
$b=new BookingService($pdo);$b->updateSettings($c,['is_enabled'=>false,'min_advance_hours'=>7,'max_future_days'=>55,'hold_ttl_minutes'=>12,'allow_presential'=>true,'allow_telehealth'=>false]);
$render=static function(callable $fn):string{http_response_code(200);ob_start();$fn();return ob_get_clean();};
$dash=new DashboardController();
foreach(['day','week','month'] as $view){
    $_GET=['view'=>$view];$html=$render(fn()=>$dash->agenda());
    T::ok(substr_count($html,'data-testid="agenda-configuration-menu"')===1,"$view: exatamente um menu de configuração");
    T::ok(str_contains($html,'Agendamento online: desativado · Ativar') && str_contains($html,'/configuracoes?tab=agenda&amp;secao=link'),"$view: atalho direto para ativação");
    T::ok(str_contains($html,NewConsultationButton::dialog('card-novo-agendamento')) && !str_contains($html,'+ Nova Consulta') && !str_contains($html,'+ Novo Atendimento'),"$view: botão usa componente e texto único");
    T::ok(str_contains($html,'secao=horarios') && str_contains($html,'secao=feriados') && str_contains($html,'secao=link'),"$view: menu aponta para as três seções");
}
$_GET=[];$html=$render(fn()=>$dash->index());T::ok(str_contains($html,NewConsultationButton::link('/agenda?novo=1','dash-new-appointment')),'Painel usa mesmo componente e classe');
$html=$render(fn()=>$dash->profile360(['id'=>$p]));T::ok(str_contains($html,'new-consultation-cta') && str_contains($html,'+ Nova consulta'),'Ficha usa componente de nova consulta');
$_GET=['tab'=>'agenda','secao'=>'link'];$settings=new SettingsController();$html=$render(fn()=>$settings->show());
T::ok(str_contains($html,'data-testid="activate-online-booking"') && str_contains($html,'name="booking_enabled" value="1" class="btn btn-primary"'),'Segundo clique envia ativação explícita pelo formulário existente');
$token=Csrf::token();
$_SERVER['REQUEST_METHOD']='POST';$_POST=['tab'=>'agenda','section'=>'booking','booking_enabled'=>'1','booking_allow_presential'=>'1','booking_min_advance_hours'=>'7','booking_max_future_days'=>'55','booking_hold_ttl_minutes'=>'12','_csrf_token'=>$token];
$render(fn()=>$settings->show());$saved=$b->getSettings($c);
T::ok($saved['is_enabled'] && $saved['min_advance_hours']===7 && $saved['max_future_days']===55 && $saved['hold_ttl_minutes']===12 && !$saved['allow_telehealth'],'POST real do controller ativa e preserva parâmetros do formulário');
$_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_GET=['view'=>'day'];$html=$render(fn()=>$dash->agenda());
T::ok(str_contains($html,'Agendamento online: ativo') && str_contains($html,'data-copy-booking-link') && str_contains($html,'data-booking-url='),'Ativo oferece copiar link e configurar');
foreach([$reception,$nutri] as $who){
    foreach([false,true] as $enabled){
        $b->updateSettings($c,['is_enabled'=>$enabled]);
        Fx::act($who);$_GET=['view'=>'day'];$html=$render(fn()=>$dash->agenda());
        T::ok(!BookingManagementAccess::allowed($pdo) && !str_contains($html,'data-testid="agenda-booking-activate"') && !str_contains($html,'data-testid="agenda-configuration-menu"') && !str_contains($html,'data-copy-booking-link'),'Sem permissão não vê controles: '.$who['user_id'].' ativo='.($enabled?'sim':'não'));
    }
}
Fx::act($admin);T::ok(BookingManagementAccess::allowed($pdo),'Administrador ativo pode configurar');
Fx::act($owner);$_GET=['tab'=>'agenda','secao'=>'link'];$_SERVER['REQUEST_METHOD']='POST';$_POST=['tab'=>'agenda','section'=>'booking','_csrf_token'=>'invalid'];
$render(fn()=>$settings->show());T::ok($b->getSettings($c)['is_enabled'],'POST sem CSRF válido não muda configuração');
Fx::act($reception);$_POST=['tab'=>'agenda','section'=>'booking','_csrf_token'=>$token];$render(fn()=>$settings->show());T::ok(http_response_code()===403 && $b->getSettings($c)['is_enabled'],'Recepção não altera configuração pelo controller');
$css=file_get_contents(dirname(__DIR__).'/public/assets/css/clinical-botanical.css');preg_match('/--botanical-green-600:\s*(#[0-9a-f]{6})/i',$css,$m);
$lum=static function(string $hex):float{$v=[];foreach(str_split(ltrim($hex,'#'),2) as $h){$n=hexdec($h)/255;$v[]=$n<=.04045?$n/12.92:(($n+.055)/1.055)**2.4;}return .2126*$v[0]+.7152*$v[1]+.0722*$v[2];};
$contrast=1.05/($lum($m[1])+.05);T::ok($contrast>=4.5,'Contraste da primária verde com branco atende AA: '.round($contrast,2).':1');
T::done();
