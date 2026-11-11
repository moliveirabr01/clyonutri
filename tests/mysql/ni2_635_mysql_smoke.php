<?php
// NI2-635: prova no MySQL/MariaDB real (php tests/mysql/ni2_635_mysql_smoke.php <socket> [banco]). Banco descartável com instalacao_mysql8.sql + migração 78. Apaga o que gravou.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use ClyoNutri\Domain\ClinicalProtocol\{ProtocolCatalog, PatientConditionService, ProtocolWorkspace};
// v54.22: o banco vem do 2º argumento (padrão clyonutri_smoke_ni2_635). O nome do banco de produção é recusado, a não ser com ALLOW_PROD_DB_NAME=1.
$smokeDb = $argv[2] ?? 'clyonutri_smoke_ni2_635';
if (strcasecmp($smokeDb, 'maic6824_Clyonutri2') === 0 && getenv('ALLOW_PROD_DB_NAME') !== '1') { fwrite(STDERR, "Recusado: este teste grava e apaga dados; use um banco descartável (ou ALLOW_PROD_DB_NAME=1 numa cópia local com esse nome).\n"); exit(2); }
$pdo = new PDO('mysql:unix_socket='.$argv[1].';dbname='.$smokeDb.';charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
\ClyoNutri\Core\Database\Connection::setInstance($pdo);
require dirname(__DIR__, 2) . '/database/migrations/2026_11_03_000078_create_clinical_protocols.php';
(new CreateClinicalProtocols())->up($pdo); echo "migração PHP idempotente no MariaDB: ok\n";
$pdo->exec("INSERT INTO clinics (uuid, name, status) VALUES (UUID(), 'Clinica TESTE MY', 'active')"); $c = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (?, UUID(), 'Paciente TESTE MY', '1990-01-01', 'F', 'active')")->execute([$c]); $p = (int)$pdo->lastInsertId();
$def = ['title'=>'TESTE','suggestion_terms'=>['termo teste'],'questions'=>[['key'=>'q1','type'=>'yesno','options'=>['sim'=>'Sim','nao'=>'Não'],'required'=>true,'text'=>'Pergunta TESTE ção?']]];
$cat = new ProtocolCatalog($pdo); $id = $cat->register('teste_my','TESTE MY'); $v = $cat->createDraft($id,$def,'Ref TESTE',1); $cat->submit($v,1);
$cat->approve($v,2,'Revisora TESTE','CRN-3 12345',date('Y-m-d',strtotime('+1 year')));
echo "aprovada e íntegra (acentos no hash): ".(isset($cat->approvedByCode()['teste_my'])?'ok':'FALHA')."\n";
$pcs = new PatientConditionService($pdo); $pcs->suggestFromText($c,$p,'tem TERMO TESTE',$cat->approvedByCode(),1);
$cid = (int)$pdo->query("SELECT id FROM patient_conditions WHERE patient_id=$p")->fetchColumn(); $pcs->confirm($c,$cid,'reported','self_reported',1);
$pdo->exec("UPDATE platform_settings SET setting_value='1' WHERE setting_key='feature_flag_clinical_protocols'"); \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
$ws = new ProtocolWorkspace($pdo); $ws->setClinicEnabled($c,'teste_my',true,1); $ws->setClinicEnabled($c,'teste_my',true,1);
$ws->save($c,$p,['teste_my.q1'=>'sim'],'professional',true,1);
$q = $ws->questionnaire($c,$p); echo "módulo ativo e resposta concluída: ".($q['answers']==['teste_my.q1'=>'sim'] && $q['composed']['completeness']['teste_my']['complete']?'ok':'FALHA')."\n";
// v54.15: bloco da anamnese, LGPD e listagem do console no MariaDB
$_SESSION = []; \ClyoNutri\Core\Auth\TenantContext::set($c, ['id' => 1, 'role' => 'nutritionist']);
$h = \ClyoNutri\Application\Controllers\ClinicalProtocolController::renderSection($pdo, $p);
echo "bloco da anamnese renderiza: ".(str_contains($h,'data-testid="adaptive-form"') && str_contains($h,'teste_my.q1')?'ok':'FALHA')."\n";
$exp = (new \ClyoNutri\Domain\Privacy\LgpdService($pdo))->exportPatientData($p);
echo "exportação LGPD com condições, histórico e respostas: ".(count($exp['patient_conditions'])===1 && count($exp['patient_condition_events'])===2 && count($exp['protocol_responses'])===1?'ok':'FALHA')."\n";
$rows = $pdo->query("SELECT p.*, (SELECT MAX(version) FROM clinical_protocol_versions v WHERE v.protocol_id = p.id) AS last_version,
    (SELECT version FROM clinical_protocol_versions v WHERE v.protocol_id = p.id AND v.status = 'approved' ORDER BY version DESC LIMIT 1) AS approved_version,
    (SELECT COUNT(*) FROM clinical_protocol_versions v WHERE v.protocol_id = p.id AND v.status = 'in_review') AS in_review
    FROM clinical_protocols p ORDER BY p.catalog_phase, p.name")->fetchAll();
$ev = $pdo->prepare('SELECT e.*, v.version FROM clinical_protocol_events e JOIN clinical_protocol_versions v ON v.id = e.protocol_version_id WHERE v.protocol_id = ? ORDER BY e.id DESC LIMIT 50'); $ev->execute([$id]);
echo "consultas do console: ".(count($rows)===1 && (int)$rows[0]['approved_version']===1 && count($ev->fetchAll())>=3?'ok':'FALHA')."\n";
// v54.16: dispensa de módulo, marca de versão, conflito e comparação no MariaDB
$pcs->dismissModule($c,$p,'teste_my',1,'Motivo TESTE MY'); $d1 = $ws->activeModules($c,$p)===[]; $pcs->restoreModule($c,$p,'teste_my',1);
echo "dispensar e reativar módulo: ".($d1 && count($ws->activeModules($c,$p))===1 ? 'ok':'FALHA')."\n";
$rv = $ws->revision($c,$p); $ws->save($c,$p,['teste_my.q1'=>'nao'],'professional',true,1,null,$rv);
try { $ws->save($c,$p,['teste_my.q1'=>'sim'],'professional',false,1,null,$rv); $cf='FALHA'; } catch (\ClyoNutri\Domain\ClinicalProtocol\ProtocolSaveConflict $e) { $cf='ok'; }
echo "conflito de gravação: $cf\n";
$cmp = $ws->compare($c,$p); echo "comparação entre conclusões: ".(($cmp[0]['changes'][0]['before'] ?? '')==='Sim' && ($cmp[0]['changes'][0]['after'] ?? '')==='Não' ? 'ok':'FALHA')."\n";
$pdo->beginTransaction(); (new \ClyoNutri\Domain\Privacy\LgpdService($pdo))->anonymizePatient($p); $pdo->commit();
echo "anonimização limpa textos das condições: ".($pdo->query("SELECT COUNT(*) FROM patient_condition_events e JOIN patient_conditions c ON c.id=e.condition_id WHERE c.patient_id=$p AND e.note IS NOT NULL")->fetchColumn()==0?'ok':'FALHA')."\n";
$pdo->exec("DELETE FROM audit_logs WHERE clinic_id=$c"); $pdo->exec("DELETE FROM patients WHERE id=$p"); $pdo->exec("DELETE FROM clinics WHERE id=$c");
foreach (['protocol_responses','patient_condition_events','patient_conditions','clinic_protocol_settings','clinical_protocol_events','clinical_protocol_versions','clinical_protocols'] as $t) $pdo->exec("DELETE FROM $t");
$pdo->exec("UPDATE platform_settings SET setting_value='0' WHERE setting_key='feature_flag_clinical_protocols'"); echo "limpo\n";
