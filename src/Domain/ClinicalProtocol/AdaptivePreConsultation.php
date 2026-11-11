<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\ClinicalProtocol;

use PDO;

/** Immutable patient-facing copy; no answers are written into the clinical workspace. */
final class AdaptivePreConsultation
{
    public function __construct(private PDO $db) {}

    public function questions(int $clinic, int $patient): array
    {
        $ws = new ProtocolWorkspace($this->db);
        if (!$ws->globallyEnabled()) return [];
        $modules = $ws->activeModules($clinic, $patient);
        $composed = ProtocolEngine::compose($modules, [], 'patient');
        $out = [];
        foreach ($composed['items'] as $it) {
            $conditions = [];
            $unconditional = false;
            $required = false;
            foreach ($modules as $m) {
                foreach ($m['definition']['questions'] as $q) {
                    $ak = $q['shared_key'] ?? $m['code'] . '.' . $q['key'];
                    if ($ak !== $it['answer_key'] || ($q['audience'] ?? 'both') === 'professional') continue;
                    $required = $required || $q['required'];
                    if (!isset($q['condition'])) $unconditional = true;
                    else $conditions[] = self::mapCondition($q['condition'], $m);
                }
            }
            $out[] = ['id' => self::id($it['answer_key']), 'text' => $it['text'], 'type' => $it['type'],
                'section' => $it['section'], 'options' => $it['options'], 'min' => $it['min'], 'max' => $it['max'],
                'required' => $required, 'condition' => $unconditional ? null : ['any' => $conditions],
                'maps_to' => 'notes', 'adaptive' => true, 'module_versions' => array_map(static fn($m) => ['code' => $m['code'], 'version_id' => $m['version_id'], 'version' => $m['version']], array_values(array_filter($modules, static fn($m) => in_array($m['code'], $it['module_codes'], true)))),
                'reason' => 'Módulo aprovado selecionado pela clínica; respostas serão revisadas pelo profissional.'];
        }
        $out[] = ['id' => 'adaptive_declared_conditions', 'type' => 'textarea', 'text' => 'Há alguma condição de saúde que deseja relatar ao nutricionista?',
            'section' => 'Informações para revisão', 'required' => false, 'maps_to' => 'clinical_history', 'adaptive' => true,
            'reason' => 'Relato opcional; não confirma diagnóstico.'];
        return $out;
    }

    private static function id(string $key): string { return 'cp_' . str_replace('.', '__', $key); }

    private static function mapCondition(array $c, array $m): array
    {
        foreach (['all','any'] as $op) if (isset($c[$op])) return [$op => array_map(static fn($v) => self::mapCondition($v, $m), $c[$op])];
        $ref = $c['question'];
        if (!str_contains($ref, '.')) {
            foreach ($m['definition']['questions'] as $q) if ($q['key'] === $ref) { $ref = $q['shared_key'] ?? $m['code'] . '.' . $ref; break; }
        }
        $c['question'] = self::id($ref);
        return $c;
    }

    /** Explicit professional action; suggestions remain unconfirmed until accepted or dismissed. */
    public function suggestSubmitted(int $clinic, int $patient, array $questionnaire, int $actor): array
    {
        (new ClinicProtocolEditor($this->db))->authorize($clinic, $actor);
        if ((int)$questionnaire['clinic_id'] !== $clinic || (int)$questionnaire['patient_id'] !== $patient || !in_array($questionnaire['status'], ['submitted','reviewed'], true)) throw new \DomainException('Pré-consulta não disponível para revisão.');
        $text = (string)($questionnaire['answers']['adaptive_declared_conditions'] ?? '');
        return (new PatientConditionService($this->db))->suggestFromTextDetailed($clinic, $patient, $text, (new ProtocolCatalog($this->db))->approvedByCode($clinic), $actor, 'patient', 'Pré-consulta #' . (int)$questionnaire['id'] . ' enviada em ' . (string)$questionnaire['submitted_at']);
    }
}
