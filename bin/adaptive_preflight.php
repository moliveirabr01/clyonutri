<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
use ClyoNutri\Core\Database\{Connection,SchemaInfo};
use ClyoNutri\Core\Security\Env;
Env::load(__DIR__ . '/../.env');
$issues = []; $pending = [];
try {
    $db = Connection::getInstance();
    foreach (['clinical_protocols','clinical_protocol_versions','protocol_responses','patient_conditions','preconsultation_questionnaires','preconsultation_answer_snapshots','consent_terms'] as $table) {
        if (!SchemaInfo::hasTable($db, $table)) $issues[] = 'Tabela obrigatória ausente: '.$table;
        elseif ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $st = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            if (strtoupper((string)$st->fetchColumn()) !== 'INNODB') $issues[] = 'Tabela deve usar InnoDB: '.$table;
        }
    }
    foreach ([['clinical_protocols','clinic_id'], ['preconsultation_questionnaires','access_hash'], ['preconsultation_questionnaires','answer_revision']] as [$table,$column]) if (!SchemaInfo::hasColumn($db,$table,$column)) $pending[] = "$table.$column";
    if (in_array('--ready', $argv, true) && ($pending || !SchemaInfo::hasTable($db, 'clinic_protocol_shares'))) $issues[] = 'Migração 79 incompleta; não liberar o tráfego.';
} catch (Throwable $e) { $issues[] = 'Não foi possível conferir o banco.'; }
echo json_encode(['ready'=>!$issues,'pending_migration_79'=>$pending,'issues'=>$issues], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
exit($issues ? 1 : 0);
