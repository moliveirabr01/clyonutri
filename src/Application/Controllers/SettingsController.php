<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Database\Connection;
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
            $successMessage=$lastSec==='availability'?'Disponibilidade de atendimento atualizada com sucesso.':($lastSec==='profile'?'Dados cadastrais e profissionais atualizados com sucesso.':'Configurações atualizadas com sucesso.');
        }
        $revision=ProfessionalProfile::revision($profile);$title='Configurações';$activeNav='configuracoes';$clinicName=$_SESSION['clinic_name']??'';$userName=$_SESSION['user_name']??'';
        ob_start();require __DIR__.'/../../../views/settings/profile.php';$content=ob_get_clean();require __DIR__.'/../../../views/layouts/professional-shell.php';
    }
}
