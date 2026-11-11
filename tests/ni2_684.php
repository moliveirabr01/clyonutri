<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ux_workflow_fixture.php';ob_end_clean();
use ClyoNutri\Domain\Settings\SettingsSaveFeedback;
use ClyoNutri\Application\View\{SettingsFormRecovery,SettingsNavigation};
use ClyoNutri\Core\Auth\TenantContext;
$checks=[];
function ncheck(string $name,bool $ok):void{global $checks;$checks[]=['test'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException($name);}
$_SESSION=[];
ncheck('URL sem recibo não confirma gravação',SettingsSaveFeedback::consume('profile')===null);
$token=SettingsSaveFeedback::issue('notifications');
ncheck('recibo é emitido no contexto ativo',SettingsSaveFeedback::consume($token)==='notifications');
ncheck('recibo consumido não reaparece no refresh',SettingsSaveFeedback::consume($token)===null);
$token=SettingsSaveFeedback::issue('profile');
TenantContext::set($c2,['id'=>$users[2],'role'=>'nutritionist']);
ncheck('outra clínica não recebe confirmação da primeira',SettingsSaveFeedback::consume($token)===null);
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);
$token=SettingsSaveFeedback::issue('profile');$_SESSION['settings_save_feedback'][$token]['expires']=time()-1;
ncheck('confirmação expirada não aparece',SettingsSaveFeedback::consume($token)===null);
$r=new SettingsFormRecovery(true,['section'=>'notifications','quiet_start'=>'19:30','current_password'=>'secret']);
ncheck('erro mantém horário digitado',$r->value('notifications','quiet_start','22:00')==='19:30');
ncheck('checkbox desmarcado não volta ao salvo',$r->checked('notifications','quiet_enabled',true)===false);
ncheck('outro domínio mantém dado salvo',$r->value('chat','quiet_start','22:00')==='22:00');
ncheck('senha não é reapresentada',$r->value('notifications','current_password','')==='');
ncheck('sem erro exibe valor canônico',(new SettingsFormRecovery(false,['section'=>'chat','chat_retention_years'=>'99']))->value('chat','chat_retention_years',20)===20);
$owner=SettingsNavigation::areas(true,true);$member=SettingsNavigation::areas(false,false);
ncheck('gestão descobre seis domínios',count($owner)===6);
ncheck('membro não recebe atalho de assinatura',!in_array('assinatura',array_column($member,'key'),true));
$tasks=[];foreach($owner as $a)foreach($a['tasks'] as $t)$tasks[]=$t;
ncheck('protocolos ligados têm caminho de descoberta',in_array('/configuracoes/protocolos',array_column($tasks,'url'),true));
$disabled=[];foreach($member as $a)foreach($a['tasks'] as $t)$disabled[]=$t;
ncheck('flag desligada não oferece protocolo',!in_array('/configuracoes/protocolos',array_column($disabled,'url'),true));
$routes=file_get_contents(__DIR__.'/../public/index.php');
foreach($tasks as $t){$path=parse_url($t['url'],PHP_URL_PATH);ncheck('destino existente '.$t['label'],str_contains($routes,"get('".$path."'"));}
$profileSvc=new ClyoNutri\Domain\Settings\ProfessionalProfile($db);$profile=$profileSvc->read();$input=$profile+['revision'=>ClyoNutri\Domain\Settings\ProfessionalProfile::revision($profile)];foreach(['name','clinic_name','specialty','bio','email','phone','crn','crn_region'] as $field)$input[$field]=(string)($input[$field]??'');$input['specialty']='TESTE NI2-684 persistido';$input['clinic_document']='24971563792';$profileSvc->save($input);
ncheck('perfil salva por domínio no backend',$profileSvc->read()['specialty']==='TESTE NI2-684 persistido');
try{$profileSvc->save($input);ncheck('perfil antigo deve conflitar',false);}catch(DomainException $e){ncheck('perfil antigo não sobrescreve',true);}
TenantContext::set($c2,['id'=>$users[2],'role'=>'nutritionist']);ncheck('perfil de outra clínica não recebe a alteração',$profileSvc->read()['specialty']!=='TESTE NI2-684 persistido');
echo json_encode(['issue'=>'NI2-684','build'=>'v54.20','driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
