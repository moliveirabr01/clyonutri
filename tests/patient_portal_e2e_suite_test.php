<?php
declare(strict_types=1);
/** NI2-902: Composer + contratos canônicos do portal; serviços/SQLite, não browser/mobile E2E. */
require_once __DIR__ . '/../vendor/autoload.php';
if ((int)ini_get('zend.assertions') !== 1 || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || !getenv('TEST_DB_DATABASE') || getenv('DB_DATABASE') !== getenv('TEST_DB_DATABASE')) {
    throw new RuntimeException('Use o runner com asserções e SQLite explicitamente isolado em testing.');
}
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;
use ClyoNutri\Domain\PatientPortal\PatientOnboardingService;
use ClyoNutri\Domain\FoodDiary\FoodDiaryService;
use ClyoNutri\Application\Api\PatientApiController;
$db = Connection::getInstance(); $passed=0;
function ck902(bool $ok, string $label): void { global $passed; assert($ok,$label); $passed++; echo "PASS $label\n"; }
function rejects902(callable $fn, string $label, string $type=Throwable::class): void {
    $exception=null;try {$fn();}catch(Throwable $e){$exception=$e;}
    ck902($exception instanceof $type,$label);
}
ck902($db instanceof \ClyoNutri\Core\Database\ProfiledPdo, 'Composer resolve Connection/ProfiledPdo e dependências');
$auth=new AuthService($db);
$a=$auth->registerNutritionist('Nutri Portal A TESTE','portal902a@example.test','TestePortal@2026','Clínica Portal A TESTE','90201','CRN-3');
$b=$auth->registerNutritionist('Nutri Portal B TESTE','portal902b@example.test','TestePortal@2026','Clínica Portal B TESTE','90202','CRN-3');
$clinicA=(int)$a['clinic_id'];$clinicB=(int)$b['clinic_id'];$userA=(int)$a['user_id'];$userB=(int)$b['user_id'];
$st=$db->prepare('SELECT id FROM professionals WHERE user_id=?');$st->execute([$userA]);$profA=(int)$st->fetchColumn();
TenantContext::set($clinicA,['id'=>$userA,'role'=>'nutritionist']);
$patient=(new PatientRepository($db))->create(['name'=>'Paciente Portal A TESTE','email'=>'paciente902@example.test','phone'=>'21999990000']);$pid=(int)$patient['id'];
$portal=new PatientPortalService($db);$diary=new FoodDiaryService($db);$api=new PatientApiController($db);
// 1: fluxo de primeiro acesso atual (token só como hash no banco, senha e aceite legais).
$onboarding=new PatientOnboardingService($db);$inv=$onboarding->create($clinicA,$userA,$pid);$code=$inv['invitation_code'];
ck902(strlen($code)===64,'convite canônico gerado');
$st=$db->prepare('SELECT token_hash FROM patient_app_invitations WHERE clinic_id=? AND patient_id=?');$st->execute([$clinicA,$pid]);ck902($st->fetchColumn()===hash('sha256',$code),'convite persiste somente hash');
$accepted=$onboarding->complete(['invitation_code'=>$code,'name'=>$patient['name'],'email'=>$patient['email'],'phone'=>$patient['phone'],'password'=>'Paciente902@2026','password_confirmation'=>'Paciente902@2026'],['channel'=>'app','ip'=>'127.0.0.1','user_agent'=>'TESTE']);
ck902((int)$accepted['patient']['id']===$pid&&!empty($accepted['token']),'primeiro acesso persiste credencial e gera token');
$st=$db->prepare('SELECT password_hash FROM patients WHERE id=?');$st->execute([$pid]);ck902(password_verify('Paciente902@2026',$st->fetchColumn()),'senha do primeiro acesso persistida com hash');
$st=$db->prepare('SELECT COUNT(*) FROM patient_consents WHERE clinic_id=? AND patient_id=?');$st->execute([$clinicA,$pid]);ck902((int)$st->fetchColumn()>0,'aceite legal persistido');
rejects902(fn()=>$onboarding->preview($code),'convite consumido não pode ser reutilizado',DomainException::class);
// 2/3: registro e timeline reais, com validações negativas.
$meal=$diary->logMeal($pid,['meal_name'=>'Almoço TESTE','consumed_at'=>date('Y-m-d 12:30:00'),'description'=>'Arroz e salada TESTE']);
ck902((int)$meal['id']>0,'diário persiste refeição');
foreach([['meal_name'=>['x']],['meal_name'=>''],['meal_name'=>'Almoço','consumed_at'=>'ontem'],['meal_name'=>'Almoço','photo_path'=>'/etc/passwd']] as $invalid) rejects902(fn()=>$diary->logMeal($pid,$invalid),'diário rejeita entrada inválida',InvalidArgumentException::class);
$timeline=$diary->getPatientTimeline($pid);ck902(count($timeline)===1&&$timeline[0]['meal_name']==='Almoço TESTE','timeline contém refeição do paciente');
// 4: ausência real de prescrição não produz substituições fictícias.
$sub=$portal->getSubstitutionsAndRecipesForPlan($pid);ck902($sub['plan']===null&&$sub['substitutions']===[]&&$sub['recipes']===[],'sem plano publicado não inventa substituições ou receitas');
// 5: autorrelato só após liberação do profissional.
rejects902(fn()=>$portal->recordSelfReportedMetric($pid,'weight_kg',61.8,'kg'),'métrica não liberada é recusada',\ClyoNutri\Domain\Evolution\SelfMetricNotEnabledException::class);
(new \ClyoNutri\Domain\Evolution\PatientEvolutionService($db))->setEnabledMetrics($pid,['weight_kg'],$userA);
$metric=$portal->recordSelfReportedMetric($pid,'weight_kg',61.8,'kg','TESTE');ck902($metric['source']==='patient_self_report'&&$metric['is_clinical']===false,'peso é autorrelato, não medição clínica');
$evo=$portal->getEvolutionTimeline($pid);ck902(isset($evo['clinical_records'],$evo['self_reports'])&&count($evo['self_reports'])===1,'evolução separa autorrelatos dos registros clínicos');
// 6: registro/listagem de metadados de documento; não testa upload HTTP de arquivo.
$doc=$portal->uploadDocument($pid,['title'=>'Exame TESTE','category'=>'exam','file_path'=>'storage/teste902/exame.pdf','mime_type'=>'application/pdf','size_bytes'=>1024],'patient',$pid);
ck902(!empty($doc['access_token'])&&count($portal->listDocuments($pid))===1,'documento registra metadados e token de acesso');
// 7: consulta futura e confirmação, sem outra agenda compartilhada na fixture.
$db->prepare("INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type) VALUES(?,?,?,datetime('now','+3 days'),45,'scheduled','follow_up')")->execute([$clinicA,$pid,$profA]);$appointment=(int)$db->lastInsertId();
ck902((int)$portal->getNextAppointment($pid)['id']===$appointment,'próxima consulta pertence ao paciente');ck902($portal->confirmAppointment($pid,$appointment),'confirmação persistida');
// 8: chat persiste mensagem vinculada à clínica.
$message=$portal->sendMessageToProfessional($pid,$profA,'Dúvida de TESTE');ck902((int)$message['id']>0&&$message['sender_type']==='patient','chat registra mensagem do paciente');
// 9: reenvio offline atual usa as chaves das operações canônicas, sem offline_sync_queue legada.
$payload=['meal_name'=>'Jantar Offline TESTE','consumed_at'=>date('Y-m-d 19:00:00'),'description'=>'TESTE','idempotency_key'=>'ni2902-refeicao'];
$first=$diary->logMeal($pid,$payload);$again=$diary->logMeal($pid,$payload);ck902($first['id']===$again['id'],'reenvio de refeição é idempotente');
$api->logHydration($pid,300,['idempotency_key'=>'ni2902-agua','source'=>'app']);$api->logHydration($pid,300,['idempotency_key'=>'ni2902-agua','source'=>'app']);
$st=$db->prepare('SELECT COUNT(*) FROM hydration_logs WHERE clinic_id=? AND patient_id=?');$st->execute([$clinicA,$pid]);ck902((int)$st->fetchColumn()===1,'reenvio de hidratação não duplica registro');
// 10: inspeção de tokens/propriedades; não é homologação WCAG/mobile.
$screen=file_get_contents(__DIR__.'/../mobile/src/screens/PatientHomeScreen.tsx');$tokens=file_get_contents(__DIR__.'/../mobile/src/theme/tokens.ts');
ck902(str_contains($tokens,"navy: '#0F2747'")&&str_contains($tokens,"copper: '#B94F1F'"),'tokens esperados presentes');ck902(str_contains($screen,'minHeight: 44')&&str_contains($screen,'accessibilityRole'),'propriedades mínimas de toque/acessibilidade no fonte');
// 11: a mesma conexão/serviços continuam bloqueando outra clínica.
TenantContext::set($clinicB,['id'=>$userB,'role'=>'nutritionist']);
rejects902(fn()=>$portal->listDocuments($pid),'outra clínica não lista documentos',\ClyoNutri\Core\Security\TenantSecurityException::class);
rejects902(fn()=>$diary->getPatientTimeline($pid),'outra clínica não lê diário',\ClyoNutri\Core\Security\TenantSecurityException::class);
echo "Resultado portal local: $passed verificações PASS. HTTP/browser/mobile e upload de arquivo não homologados.\n";
