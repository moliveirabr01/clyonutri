<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\ClinicalProtocol;

use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Audit\AuditLogger;
use PDO;

final class TransversalSummary
{
    public function __construct(private PDO $db) {}

    public function read(int $clinic, int $patient): array
    {
        TenantGuard::assertPatientBelongsToClinic($patient, $clinic, $this->db);
        if (!(new ProtocolWorkspace($this->db))->globallyEnabled()) return [];
        $st = $this->db->prepare('SELECT a.*, u.name AS author FROM anamneses a LEFT JOIN professionals pr ON pr.id = a.professional_id LEFT JOIN users u ON u.id = pr.user_id WHERE a.clinic_id = ? AND a.patient_id = ? AND a.is_draft = 0 ORDER BY a.version DESC, a.id DESC LIMIT 1');
        $st->execute([$clinic, $patient]);
        $base = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $conditions = array_values(array_filter((new PatientConditionService($this->db))->list($clinic, $patient), static fn($r) => $r['confirmed_by_user_id'] !== null && in_array($r['status'], PatientConditionService::ACTIVE, true) && (!$r['valid_until'] || $r['valid_until'] >= date('Y-m-d'))));
        // Historical version definitions travel with the conclusion, even after retirement or a new draft.
        $st = $this->db->prepare("SELECT r.*, v.version, v.definition_json, p.code, p.name FROM protocol_responses r JOIN clinical_protocol_versions v ON v.id = r.protocol_version_id JOIN clinical_protocols p ON p.id = v.protocol_id
            WHERE r.clinic_id = ? AND r.patient_id = ? AND r.is_draft = 0 AND r.provenance = 'professional' AND (p.clinic_id IS NULL OR p.clinic_id = ?) ORDER BY r.id DESC");
        $st->execute([$clinic, $patient, $clinic]);
        $modules = []; $seen = []; $seenAnswers = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($seen[$r['code']])) continue;
            $seen[$r['code']] = true;
            $def = json_decode($r['definition_json'], true) ?: [];
            $answers = json_decode($r['answers_json'], true) ?: [];
            $entries = [];
            foreach ($def['questions'] ?? [] as $q) {
                $key = $q['shared_key'] ?? $r['code'] . '.' . $q['key'];
                if (isset($seenAnswers[$key])) continue;
                if (!array_key_exists($key, $answers) || $answers[$key] === '' || $answers[$key] === []) continue;
                $value = implode(', ', array_map(static fn($v) => (string)($q['options'][(string)$v] ?? $v), (array)$answers[$key]));
                $seenAnswers[$key] = true;
                $entries[] = ['key' => $key, 'label' => $q['text'], 'value' => $value];
            }
            $modules[] = ['name' => $r['name'], 'version' => (int)$r['version'], 'recorded_at' => $r['created_at'], 'response_id' => (int)$r['id'], 'entries' => $entries];
        }
        (new AuditLogger($this->db))->log('TRANSVERSAL_SUMMARY_VIEWED', 'patients', (string)$patient, ['module_count' => count($modules), 'condition_count' => count($conditions)]);
        return ['base' => $base, 'conditions' => $conditions, 'modules' => $modules];
    }
}
