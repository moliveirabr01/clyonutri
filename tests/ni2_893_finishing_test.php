<?php
putenv('V54_DB=' . sys_get_temp_dir() . '/ni2-893-' . getmypid() . '.sqlite');
require __DIR__ . '/_v54_bootstrap.php';
$c=Fx::clinic($pdo,'Clínica TESTE');$u=Fx::user($pdo,$c,'Nutri TESTE','owner');Fx::patient($pdo,$c,'Paciente TESTE');Fx::act($u);
error_reporting(E_ALL);
function renderExam(string $status):string {
    $patient=['id'=>1,'name'=>'Paciente TESTE'];
    $request=array_fill_keys(['id','status','request_number','updated_at','created_at','version_number','pdf_path','cancellation_reason','appointment_id','supersedes_request_id','superseded_by_id','coverage_disclaimer_acknowledged'], '');
    $request=array_replace($request,['id'=>1,'status'=>$status,'version_number'=>1,'cancellation_reason'=>$status==='cancelled'?'Motivo TESTE':'']);
    $doc=array_fill_keys(['status_label','request_number','issued_at_local','cancelled_at_local','cancellation_reason','justification','diagnostic','issued_date','coverage_notice','legal_basis','policy_version'],'');
    $doc+=['status'=>$status,'clinic'=>array_fill_keys(['name','phone','email','document','address','header_text','footer_text','city'],''),'patient'=>['name'=>'Paciente TESTE','birth_date'=>'','age'=>null],'professional'=>array_fill_keys(['name','specialty','crn'],''),'items'=>[]];
    if($status==='cancelled'){$doc['cancelled_at_local']='16/11/2026 10:00';$doc['cancellation_reason']='Motivo TESTE';}
    $policy=[];$flash=null;$csrfToken='teste';$resultDocs=[];$coverage=[];
    ob_start();require dirname(__DIR__) . '/views/patients/exam_show.php';return ob_get_clean();
}
foreach(['draft','review','issued','cancelled'] as $status){
 $html=renderExam($status);
 T::ok(str_contains($html,'Solicitação cancelada em') === ($status === 'cancelled'), "Bloco de cancelamento somente para status cancelado: $status");
 if($status==='cancelled') T::ok(str_contains($html,'16/11/2026 10:00') && str_contains($html,'Motivo TESTE'), 'Cancelamento mostra data e motivo');
}
$app=['id'=>1];$pid=1;$csrfToken='teste';
ob_start();require dirname(__DIR__).'/views/consultation/partials/realtime_transcription.php';$html=ob_get_clean();
T::ok(!str_contains($html,'Copiar link') && !str_contains($html,'stt-consent-link'), 'Sala sem botão e sem campo de link');
T::ok(str_contains($html,'btn-request-consent') && str_contains($html,'btn-revoke-consent'), 'Controles de pedir aceite e revogação preservados');
$title='Console TESTE';$content='';$breadcrumbs=[];$_SESSION=[];
ob_start();require dirname(__DIR__).'/views/layouts/admin-shell.php';$html=ob_get_clean();
$info=json_decode(file_get_contents(dirname(__DIR__).'/RELEASE_INFO.json'),true);
T::ok(str_contains($html,'data-testid="admin-installed-version">'.htmlspecialchars($info['version'],ENT_QUOTES,'UTF-8').'</span>'), 'Rodapé renderiza a versão instalada registrada no JSON');
T::ok(!str_contains($html,'v2.0 admin'), 'Versão antiga fixa ausente do HTML');
$professionals=[];$selectedProf=['id'=>1,'clinic_id'=>1,'name'=>'Nutri TESTE','clinic_name'=>'Clínica TESTE','duration_minutes'=>50];
$profId=1;$clinicId=1;$settings=['allow_presential'=>true,'hold_ttl_minutes'=>10];$waitlistEnabled=false;
ob_start();require dirname(__DIR__).'/views/booking/public_booking.php';$html=ob_get_clean();
T::ok(!str_contains($html,'Código da Consulta') && !str_contains($html,'receipt-id'), 'Comprovante público sem código sequencial e sem alvo de preenchimento');
T::ok(str_contains($html,'receipt-datetime') && str_contains($html,'receipt-modality'), 'Comprovante preserva data, horário e modalidade');
if (preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $html, $scripts)) {
    $jsFile=sys_get_temp_dir().'/ni2-893-booking-'.getmypid().'.js';
    file_put_contents($jsFile,implode("\n",$scripts[1]));
    $command='node --check '.escapeshellarg($jsFile).' 2>&1';
    exec($command,$output,$status);
    unlink($jsFile);
    T::ok($status===0,'JavaScript renderizado do agendamento tem sintaxe válida');
}
T::done();
