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

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService;

$dryRun = in_array('--dry-run', $argv, true);

echo "========================================================================\n";
echo ">>> CLYONUTRI II — MIGRAÇÃO DE CRIPTOGRAFIA EM REPOUSO (SEC-02) <<<\n";
echo "    Algoritmo: AES-256-GCM | Envelope: " . ClinicalEncryptionService::PREFIX . "\n";
echo "    Modo: " . ($dryRun ? "SIMULAÇÃO (DRY-RUN - sem gravação)" : "EXECUÇÃO REAL") . "\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

$fields = ClinicalEncryptionService::ANAMNESIS_ENCRYPTED_FIELDS;
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

echo "\n------------------------------------------------------------------------\n";
echo "RESUMO DA EXECUÇÃO:\n";
echo "  Registros examinados:        {$totalScanned}\n";
echo "  Registros re-criptografados: {$totalEncrypted}\n";
echo "  Registros já criptografados: {$totalSkipped}\n";
echo "========================================================================\n";
