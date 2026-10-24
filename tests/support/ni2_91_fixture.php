<?php
// Fixture do teste real da NI2-91 (privacidade da paciente e fila LGPD). Dados fictícios. Só /tmp/ni2-91-*.
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_(73|91|96|137|138)_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !preg_match('#^/tmp/ni2-(73|91|96|137|138)-#', getenv('DB_DATABASE') ?: '')) throw new RuntimeException('Isolated test database required');
$mode = $argv[1] ?? 'prepare';
if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_89_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) throw new RuntimeException('base fixture');
    $db = Connection::getInstance();
    $hash = password_hash('Equipe@2026', PASSWORD_DEFAULT);
    // Nutri Um (dona da clínica 1) passa a ter senha; equipe: nutricionista e recepção.
    $db->prepare("UPDATE users SET password_hash = ? WHERE id IN (1,2)")->execute([$hash]);
    $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(3,'n91-u3','Nutri Equipe','equipe@example.test',?,'nutritionist','active'),(4,'n91-u4','Recepção Fictícia','recepcao@example.test',?,'receptionist','active')")->execute([$hash, $hash]);
    $db->exec("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,3,'nutritionist','active'),(1,4,'receptionist','active')");
    $db->exec("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(3,3,'CRN-T3','CRN-3')");
    $db->exec("INSERT INTO onboarding_progress(clinic_id,user_id,current_step,practice_preferences,is_completed,created_at,updated_at) VALUES(1,1,3,'{}',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),(1,3,3,'{}',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),(1,4,3,'{}',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    // Termo e consentimento da Ana; prontuário do Bruno (guarda legal); dados da Ana para a portabilidade.
    $termId = (int)$db->query("SELECT id FROM consent_terms WHERE purpose_code = 'clinical_care' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($termId === 0) {
        $db->exec("INSERT INTO consent_terms(version,title,purpose_code,term_text,is_active) VALUES('1.0','Termo de atendimento nutricional','clinical_care','Texto fictício.',1)");
        $termId = (int)$db->lastInsertId();
    }
    $c = $db->prepare("INSERT INTO patient_consents(clinic_id,patient_id,consent_type,granted,term_id,source_channel,granted_at,ip_address,user_agent) VALUES(1,?,'clinical_care',1,?,'professional_attestation','2026-10-01 10:00:00','10.0.0.9','Navegador fictício')");
    $c->execute([1, $termId]); $c->execute([2, $termId]);
    $db->exec("INSERT INTO clinical_records(clinic_id,patient_id,professional_id,title,content_json,record_hash,previous_hash) VALUES(1,2,1,'Evolução fictícia do Bruno','{}','h1','h0')");
    $db->exec("INSERT INTO patient_self_metrics(clinic_id,patient_id,metric_type,value,unit,source,recorded_at) VALUES(1,1,'weight_kg',63.2,'kg','patient',CURRENT_TIMESTAMP)");
    $db->exec("INSERT INTO chat_conversations(id,clinic_id,patient_id,professional_id,status) VALUES(33,1,1,1,'active')");
    $db->exec("INSERT INTO chat_messages(conversation_id,clinic_id,sender_type,sender_id,message) VALUES(33,1,'patient',1,'Mensagem fictícia da Ana para a nutri')");
    $db->exec("UPDATE patients SET birth_date='1990-04-02', gender='Feminino' WHERE id IN (1,2)");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) throw new RuntimeException('Somente SELECT');
    echo json_encode(Connection::getInstance()->query($q)->fetchAll(PDO::FETCH_ASSOC));
} elseif ($mode === 'age-request') {
    // Move o prazo de um pedido para o passado (o prazo real é de 15 dias).
    Connection::getInstance()->prepare("UPDATE data_subject_requests SET deadline_at = '2026-01-01 00:00:00' WHERE id = ?")->execute([(int)($argv[2] ?? 0)]);
} else {
    throw new RuntimeException('Unknown fixture mode');
}
