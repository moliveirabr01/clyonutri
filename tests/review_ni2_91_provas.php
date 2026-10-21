<?php
// Provas do code review da NI2-91 (perfil, consentimentos e privacidade do paciente).
// Reproduz o comportamento ATUAL; cada linha "ACHADO" é um defeito confirmado. SQLite isolado, dados fictícios.
//   DB_CONNECTION=sqlite DB_DATABASE=/tmp/ni2-89-<x>/t.sqlite php tests/review_ni2_91_provas.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;

if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-89-')) throw new RuntimeException('Isolated test database required');
passthru('php ' . escapeshellarg(__DIR__ . '/support/ni2_89_fixture.php') . ' prepare > /dev/null', $rc);
if ($rc !== 0) throw new RuntimeException('fixture');
$db = Connection::getInstance();
$out = [];
$say = function (string $id, bool $defect, string $text) use (&$out) { $out[] = [$id, $defect, $text]; echo ($defect ? 'ACHADO ' : 'ok     ') . "{$id}: {$text}\n"; };

// ---------------------------------------------------------------- P1 rotas do paciente
$index = file_get_contents(__DIR__ . '/../public/index.php');
preg_match_all("/\\\$router->(get|post)\\('(\\/api\\/v1\\/(?:patient|auth\\/patient)[^']*)'/", $index, $m);
$routes = $m[2];
$privacy = array_filter($routes, fn($r) => preg_match('/profile|perfil|consent|privacy|privacidade|lgpd|export|delete|exclus/i', $r));
$say('P1', count($privacy) === 0, 'rotas da API do paciente para perfil, consentimento ou LGPD: ' . (count($privacy) ? implode(', ', $privacy) : 'nenhuma') . ' (de ' . count($routes) . ' rotas)');

// ---------------------------------------------------------------- P2 primeiro acesso sem consentimento
$db->exec("INSERT INTO patients(id,clinic_id,uuid,name,email,status) VALUES(4,1,'n91-p4','Dora Fictícia','dora@example.test','active')");
$db->exec("INSERT INTO consent_terms(version,title,purpose_code,term_text,is_active) VALUES('1.0','Termo de atendimento','clinical_care','Texto fictício do termo.',1)");
$svc = new \ClyoNutri\Domain\PatientPortal\PatientOnboardingService($db);
$inv = $svc->create(1, 1, 4);
$svc->complete(['invitation_code' => $inv['invitation_code'], 'name' => 'Dora Fictícia', 'email' => 'dora@example.test', 'phone' => '11999990000', 'password' => 'SenhaDora#2026', 'password_confirmation' => 'SenhaDora#2026']);
$consents = (int)$db->query('SELECT COUNT(*) FROM patient_consents WHERE patient_id = 4')->fetchColumn();
$say('P2', $consents === 0, "primeiro acesso concluído com termo ativo; consentimentos gravados para a paciente: {$consents}");

// ---------------------------------------------------------------- P3 exportação incompleta
TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist']);
$db->exec("INSERT INTO patient_self_metrics(clinic_id,patient_id,metric_type,value,unit,source,recorded_at) VALUES(2,3,'weight_kg',61.5,'kg','patient',CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO preconsultation_questionnaires(clinic_id,patient_id,professional_id,status,objectives_json,suggestion_json,questions_json,bank_version,answers_json) VALUES(2,3,2,'submitted','[]','{}','[]','v1','{\"objetivo\":\"Resposta fictícia da Carla\"}')");
$export = (new \ClyoNutri\Domain\Privacy\LgpdService($db))->exportPatientData(3);
$json = json_encode($export, JSON_UNESCAPED_UNICODE);
$missing = [];
foreach ([
    'mensagens do chat' => 'Mensagem privada para a Carla',
    'autorrelatos (patient_self_metrics)' => '61.5',
    'pré-consulta (respostas)' => 'Resposta fictícia da Carla',
    'receitas prescritas' => 'recipe_id',
    'pedidos LGPD anteriores (data_subject_requests)' => 'data_subject_requests',
] as $label => $needle) if (!str_contains($json, $needle)) $missing[] = $label;
$say('P3', $missing !== [], 'pacote de portabilidade não traz: ' . ($missing ? implode('; ', $missing) : '—'));

// ---------------------------------------------------------------- P4 pedido de exclusão nunca é processável
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
$dsr = new \ClyoNutri\Domain\Privacy\DataSubjectRequestService($db);
$req = $dsr->openRequest(2, 'deletion', 'Pedido fictício');
$ctl = __DIR__ . '/../src/Application/Controllers/PrivacyRequestController.php';
$callers = preg_match_all('/processDeletionWithLegalHold/', $index . file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php') . (is_file($ctl) ? file_get_contents($ctl) : ''));
$say('P4', $callers === 0, "pedido de exclusão #{$req['id']} aberto com prazo {$req['deadline_at']}; chamadas a processDeletionWithLegalHold em rotas/controladores: {$callers}");
$audit = $db->query("SELECT user_id, ip_address FROM audit_logs WHERE action = 'DATA_SUBJECT_REQUEST_OPENED' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$say('P5', $audit['user_id'] === null, 'auditoria da abertura do pedido: user_id=' . var_export($audit['user_id'], true) . ', ip=' . var_export($audit['ip_address'], true));

// ---------------------------------------------------------------- P6 resultado da exclusão contradiz o que foi feito
$db->exec("INSERT INTO clinical_records(clinic_id,patient_id,professional_id,title,content_json,record_hash,previous_hash) VALUES(1,2,1,'Evolução fictícia','{}','h1','h0')");
$res = $dsr->processDeletionWithLegalHold($req['id'], 1);
$p = $db->query('SELECT name, email, birth_date, gender, status, uuid FROM patients WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
$say('P6', $res['status'] === 'rejected_legal_hold' && $p['status'] === 'anonymized', "status do pedido = {$res['status']}, mas o cadastro foi anonimizado (status do paciente = {$p['status']})");

// ---------------------------------------------------------------- P7 anonimização deixa identificadores
$db->exec("UPDATE patients SET birth_date='1991-03-14', gender='Feminino' WHERE id=1");
(new \ClyoNutri\Domain\Privacy\LgpdService($db))->anonymizePatient(1);
$p = $db->query('SELECT name, email, phone, birth_date, gender, uuid, password_hash IS NOT NULL AS has_hash FROM patients WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$kept = array_keys(array_filter(['birth_date' => $p['birth_date'], 'gender' => $p['gender'], 'password_hash' => (int)$p['has_hash']]));
$say('P7', $kept !== [], 'depois de anonimizar continuam gravados: ' . implode(', ', $kept) . " (nome='{$p['name']}', e-mail=" . var_export($p['email'], true) . ')');

// ---------------------------------------------------------------- P8 exportação na hora pelo profissional
preg_match('/function patientPrivacyRequest.*?\n    }\n/s', file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php'), $mm);
$immediate = str_contains($mm[0] ?? '', "processExport(\$request['id']");
$say('P8', $immediate, 'pedido de exportação aberto pelo profissional é dado como concluído e baixado na hora pelo próprio profissional (sem envio ao titular)');

file_put_contents(sys_get_temp_dir() . '/review_ni2_91.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
