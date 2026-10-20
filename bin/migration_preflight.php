<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;

$envPath = getenv('PREFLIGHT_ENV_FILE') ?: (__DIR__ . '/../.env');
if (is_file($envPath)) {
    Env::load($envPath);
}

$jsonMode = in_array('--json', $argv ?? [], true);
if (!$jsonMode) {
    echo "== ClyoNutri Migration Preflight (Read-Only / Fail-Fast) ==\n";
}

try {
    $dsnOverride = getenv('PREFLIGHT_SQLITE_PATH');
    if (is_string($dsnOverride) && $dsnOverride !== '') {
        $db = new PDO('sqlite:' . $dsnOverride);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        $db = Connection::getInstance();
    }

    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tableExists = static function (PDO $pdo, string $driver, string $table): bool {
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $issues = [];
    foreach (['clinics', 'appointments', 'users', 'teleconsultation_sessions', 'consent_terms'] as $reqTable) {
        if (!$tableExists($db, $driver, $reqTable)) {
            $issues[] = "Tabela obrigatória ausente: {$reqTable}";
        }
    }

    $badEngines = 0;
    if ($driver === 'mysql') {
        $stmtEng = $db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('clinics', 'appointments', 'users', 'teleconsultation_sessions', 'consent_terms', 'consultation_note_drafts')
              AND UPPER(ENGINE) <> 'INNODB'");
        $badEngines = (int)$stmtEng->fetchColumn();
        if ($badEngines > 0) {
            $issues[] = "Tabela não-InnoDB detectada ({$badEngines} tabelas). InnoDB é obrigatório para suporte a chaves estrangeiras.";
        }
    }

    $dups = 0;
    if ($tableExists($db, $driver, 'teleconsultation_sessions')) {
        $dups = (int)$db->query(
            'SELECT COUNT(*) FROM (SELECT appointment_id FROM teleconsultation_sessions GROUP BY appointment_id HAVING COUNT(*) > 1) d'
        )->fetchColumn();
        if ($dups > 0) {
            $issues[] = "Existem {$dups} agendamento(s) com sessões duplicadas em teleconsultation_sessions";
        }
    }

    $orphansClinic = 0;
    $orphansAppt = 0;
    $hasDraftsTable = $tableExists($db, $driver, 'consultation_note_drafts');
    $fksRestrict = 0;
    $fksNonRestrict = 0;
    $draftsCount = 0;

    if ($hasDraftsTable && $tableExists($db, $driver, 'clinics') && $tableExists($db, $driver, 'appointments')) {
        $orphansClinic = (int)$db->query(
            'SELECT COUNT(*) FROM consultation_note_drafts d LEFT JOIN clinics c ON c.id = d.clinic_id WHERE c.id IS NULL'
        )->fetchColumn();
        $orphansAppt = (int)$db->query(
            'SELECT COUNT(*) FROM consultation_note_drafts d LEFT JOIN appointments a ON a.id = d.appointment_id WHERE a.id IS NULL'
        )->fetchColumn();
        $totalOrphans = $orphansClinic + $orphansAppt;
        if ($totalOrphans > 0) {
            $issues[] = "Detectados {$totalOrphans} rascunho(s) órfão(s) em consultation_note_drafts (clínica={$orphansClinic}, consulta={$orphansAppt}). Nenhum dado foi removido; faça quarentena manual auditada antes de aplicar DDL/FKs";
        }
        $draftsCount = (int)$db->query('SELECT COUNT(*) FROM consultation_note_drafts')->fetchColumn();

        if ($driver === 'mysql') {
            $fksRestrict = (int)$db->query("SELECT COUNT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_note_drafts'
                  AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE = 'RESTRICT'")->fetchColumn();
            $fksNonRestrict = (int)$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_note_drafts'
                  AND REFERENCED_TABLE_NAME IN ('clinics', 'appointments') AND DELETE_RULE <> 'RESTRICT'")->fetchColumn();
        } else {
            $fksList = $db->query("PRAGMA foreign_key_list('consultation_note_drafts')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($fksList as $fkl) {
                if (in_array($fkl['table'] ?? '', ['clinics', 'appointments'], true)) {
                    if (strtoupper((string)($fkl['on_delete'] ?? '')) === 'RESTRICT') {
                        $fksRestrict++;
                    } else {
                        $fksNonRestrict++;
                    }
                }
            }
        }
    }

    $initState = 'CLEAN_INSTALL_039';
    if ($hasDraftsTable) {
        if ($fksRestrict === 2 && $fksNonRestrict === 0) {
            $initState = 'ALREADY_RESTRICT_V17';
        } elseif ($fksNonRestrict > 0) {
            $initState = 'LEGACY_CASCADE_V15';
        } else {
            $initState = 'PARTIAL_MISSING_FK';
        }
    }

    if ($jsonMode) {
        echo json_encode([
            'preflight_ok' => $issues === [],
            'driver' => $driver,
            'initial_state_40' => $initState,
            'has_drafts_table' => $hasDraftsTable,
            'drafts_count' => $draftsCount,
            'fks_restrict' => $fksRestrict,
            'fks_non_restrict' => $fksNonRestrict,
            'orphans_clinic' => $orphansClinic,
            'orphans_appointment' => $orphansAppt,
            'duplicate_sessions' => $dups,
            'bad_engines' => $badEngines,
            'issues' => $issues,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        exit($issues === [] ? 0 : 1);
    }

    echo sprintf(" - Driver: %s\n", $driver);
    echo sprintf(" - Tabela consultation_note_drafts existente: %s\n", $hasDraftsTable ? 'sim' : 'não');
    echo sprintf(" - Estado inicial 040: %s\n", $initState);
    echo sprintf(" - Rascunhos existentes preservados: %d\n", $draftsCount);
    echo sprintf(" - FKs RESTRICT / Outras: %d / %d\n", $fksRestrict, $fksNonRestrict);
    echo sprintf(" - Rascunhos órfãos (clínica / consulta): %d / %d\n", $orphansClinic, $orphansAppt);
    echo sprintf(" - Sessões duplicadas em teleconsultation_sessions: %d\n", $dups);

    if ($issues !== []) {
        echo "\nStatus Preflight: BLOQUEADO (Fail-Fast) — Migração abortada antes de qualquer DDL:\n";
        foreach ($issues as $issue) {
            echo " * {$issue}\n";
        }
        exit(1);
    }

    echo "\nStatus Preflight: OK — Banco íntegro e pronto para migração.\n";
    exit(0);
} catch (\Throwable $e) {
    if ($jsonMode) {
        echo json_encode(['preflight_ok' => false, 'error' => get_class($e)], JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    echo "\nStatus Preflight: ERRO DE CONEXÃO/LEITURA (" . get_class($e) . ")\n";
    exit(1);
}
