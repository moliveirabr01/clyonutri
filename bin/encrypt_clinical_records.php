#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script CLI de Migração e Re-encriptação de Registros Clínicos (NI2-480 / SEC-02).
 *
 * Criptografa dados clínicos legados em repouso com AES-256-GCM de forma idempotente.
 * Uso:
 *   php bin/encrypt_clinical_records.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';

// v54.68: carrega o .env (antes caía no SQLite padrão e, sem APP_ENV, na chave DEV-ONLY). Sem .env, para.
$envPath = __DIR__ . '/../.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "ERRO: .env não encontrado em {$envPath}. Nada foi alterado.\n");
    exit(1);
}
\ClyoNutri\Core\Security\Env::load($envPath);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService;

$dryRun = in_array('--dry-run', $argv, true);

echo "AVISO (NI2-716 / v54.45): rode este batch SOMENTE depois que o runtime já descriptografa\n";
echo "  (AnamnesisService / ClinicalContext / LGPD). NÃO executar contra produção sem esse pré-requisito.\n";
echo "  Preferir --dry-run primeiro. Backup completo + CLINICAL_ENCRYPTION_KEY estável são obrigatórios.\n\n";

echo "========================================================================\n";
echo ">>> CLYONUTRI II — MIGRAÇÃO DE CRIPTOGRAFIA EM REPOUSO (SEC-02) <<<\n";
echo "    Algoritmo: AES-256-GCM | Envelope: " . ClinicalEncryptionService::PREFIX . "\n";
echo "    Modo: " . ($dryRun ? "SIMULAÇÃO (DRY-RUN - sem gravação)" : "EXECUÇÃO REAL") . "\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

$fields = ClinicalEncryptionService::anamnesisFieldsPresent($db); // v54.46: inclui structured_data_json quando a coluna existe
$totalScanned = 0;
$totalEncrypted = 0;
$totalSkipped = 0;

$stmt = $db->query("SELECT id, clinic_id, patient_id, version, " . implode(', ', $fields) . " FROM anamneses ORDER BY id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total de registros de anamnese encontrados: " . count($rows) . "\n\n";

foreach ($rows as $row) {
    $totalScanned++;
    $id = (int)$row['id'];
    $needsUpdate = false;
    $updates = [];

    foreach ($fields as $field) {
        $val = $row[$field];
        if ($val !== null && $val !== '' && !ClinicalEncryptionService::isEncrypted($val)) {
            $needsUpdate = true;
            $updates[$field] = ClinicalEncryptionService::encrypt($val);
        }
    }

    if ($needsUpdate) {
        $totalEncrypted++;
        echo "  [ENCRYPT] Anamnese #{$id} (Clínica #{$row['clinic_id']}, Paciente #{$row['patient_id']}, v{$row['version']}) -> " . count($updates) . " campo(s) criptografado(s)\n";

        if (!$dryRun) {
            $setParts = [];
            $params = ['id' => $id];
            foreach ($updates as $f => $encVal) {
                $setParts[] = "{$f} = :{$f}";
                $params[$f] = $encVal;
            }
            $sql = "UPDATE anamneses SET " . implode(', ', $setParts) . " WHERE id = :id";
            $db->prepare($sql)->execute($params);
        }
    } else {
        $totalSkipped++;
    }
}

// NI2-862 (F-04): Cifrar também patients (notes, allergies), consultation_note_drafts (evolution_summary) e clinical_records (content_json).
$extraTables = [
    ['table' => 'patients', 'fields' => ['notes', 'allergies']],
    ['table' => 'consultation_note_drafts', 'fields' => ['evolution_summary']],
    ['table' => 'clinical_records', 'fields' => ['content_json']],
];
foreach ($extraTables as $spec) {
    $tbl = $spec['table'];
    $cols = [];
    foreach ($spec['fields'] as $f) {
        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($db, $tbl, $f)) {
            $cols[] = $f;
        }
    }
    if ($cols === []) {
        continue;
    }
    $selCols = implode(', ', $cols);
    $stExtra = $db->query("SELECT id, {$selCols} FROM {$tbl} ORDER BY id ASC");
    foreach ($stExtra->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $totalScanned++;
        $updates = [];
        foreach ($cols as $f) {
            $val = $row[$f] ?? null;
            if ($val !== null && $val !== '' && !ClinicalEncryptionService::isEncrypted((string)$val)) {
                $updates[$f] = ClinicalEncryptionService::encrypt((string)$val);
            }
        }
        if ($updates !== []) {
            $totalEncrypted++;
            if (!$dryRun) {
                $setParts = [];
                $params = ['id' => (int)$row['id']];
                foreach ($updates as $f => $encVal) {
                    $setParts[] = "{$f} = :{$f}";
                    $params[$f] = $encVal;
                }
                $db->prepare("UPDATE {$tbl} SET " . implode(', ', $setParts) . " WHERE id = :id")->execute($params);
            }
        } else {
            $totalSkipped++;
        }
    }
}

echo "\n------------------------------------------------------------------------\n";
echo "RESUMO DA EXECUÇÃO:\n";
echo "  Registros examinados:        {$totalScanned}\n";
echo "  Registros re-criptografados: {$totalEncrypted}\n";
echo "  Registros já criptografados: {$totalSkipped}\n";
echo "========================================================================\n";
