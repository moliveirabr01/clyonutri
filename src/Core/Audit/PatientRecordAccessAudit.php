<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Audit;

use ClyoNutri\Core\Observability\StructuredLogger;
use PDO;

/**
 * v54.46 / CR-NI2-006 (NI2-719): trilha de LEITURA do prontuário (LGPD art. 37 / CFN).
 *
 * - Ação PATIENT_RECORD_VIEWED em audit_logs, resource=patients, resource_id=<id>.
 * - details só com a superfície lida (ex.: profile360, medical_record) — nunca conteúdo clínico.
 * - Dedupe por sessão: mesma superfície do mesmo paciente não grava de novo em DEDUPE_SECONDS.
 * - Fail-open para a tela (leitura clínica não pode cair por falha de auditoria), mas a falha vai para
 *   o log estruturado (AUDIT_READ_PERSIST_FAILED) para alerta.
 */
final class PatientRecordAccessAudit
{
    public const ACTION = 'PATIENT_RECORD_VIEWED';
    public const DEDUPE_SECONDS = 300;
    public const SURFACES = ['profile360', 'medical_record', 'medical_record_export', 'medical_record_print', 'anamnesis', 'exam'];

    public static function record(int $patientId, string $surface, ?PDO $db = null): bool
    {
        if ($patientId <= 0 || !in_array($surface, self::SURFACES, true)) {
            return false;
        }
        $key = $patientId . ':' . $surface;
        $now = time();
        if (isset($_SESSION) && is_array($_SESSION)) { // sessão ativa (web) ou array de teste
            $seen = $_SESSION['_record_view_audit'] ?? [];
            if (isset($seen[$key]) && ($now - (int)$seen[$key]) < self::DEDUPE_SECONDS) {
                return false;
            }
        }
        try {
            (new AuditLogger($db))->log(self::ACTION, 'patients', (string)$patientId, ['surface' => $surface]);
        } catch (\Throwable $e) {
            StructuredLogger::error('AUDIT_READ_PERSIST_FAILED', ['patient_id' => $patientId, 'surface' => $surface, 'exception' => $e]);
            return false;
        }
        if (isset($_SESSION) && is_array($_SESSION)) { // sessão ativa (web) ou array de teste
            $seen = $_SESSION['_record_view_audit'] ?? [];
            foreach ($seen as $k => $t) {
                if (($now - (int)$t) >= self::DEDUPE_SECONDS) unset($seen[$k]);
            }
            $seen[$key] = $now;
            $_SESSION['_record_view_audit'] = $seen;
        }
        return true;
    }
}
