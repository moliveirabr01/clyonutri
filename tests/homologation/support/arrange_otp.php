<?php

declare(strict_types=1);

/**
 * Preparação de teste (NI2-409): grava um OTP para um paciente, para exercitar as regras
 * reais de login por OTP (uso único, expiração, código errado) no endpoint verdadeiro.
 * Necessário porque o produto ainda não tem canal de emissão de OTP (SMS/e-mail) —
 * isso é registrado no relatório do gate; esta ferramenta não simula o login em si.
 *
 * Uso: php arrange_otp.php <patient_id> <codigo6> <segundos_para_expirar (negativo = já expirado)>
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

[$_, $patientId, $code, $ttl] = array_pad($argv, 4, null);
if (!ctype_digit((string)$patientId) || !preg_match('/^\d{6}$/', (string)$code) || !is_numeric($ttl)) {
    fwrite(STDERR, "Uso: php arrange_otp.php <patient_id> <codigo6> <segundos>\n");
    exit(2);
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$db = \ClyoNutri\Core\Database\Connection::getInstance();
$stmt = $db->prepare('UPDATE patients SET otp_code = ?, otp_expires_at = ? WHERE id = ?');
$stmt->execute([$code, date('Y-m-d H:i:s', time() + (int)$ttl), (int)$patientId]);
echo json_encode(['updated' => $stmt->rowCount()]);
