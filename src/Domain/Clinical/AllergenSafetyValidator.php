<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;

/**
 * Trava de alérgenos da prescrição (NI2-79), fail-closed desde o code review NI2-9 (19/10/2026).
 *
 * - Conflito: o alimento declara (campo `allergens`) ou nomeia um grupo alergênico registrado na anamnese.
 * - Não verificado: o paciente tem alergia registrada e o alimento não tem informação de alérgenos
 *   (`allergens` NULL: TACO importada e alimentos próprios da clínica). Ausência de informação
 *   NUNCA é tratada como "não contém".
 * - Alergia não reconhecida: há texto de alergia na anamnese, mas nenhum grupo conhecido foi identificado.
 *
 * Qualquer um dos três exige confirmação expressa e justificada do nutricionista para publicar.
 * Verificado sem alérgenos: allergens = '' (convenção do catálogo curado em FoodCatalogService)
 * ou 'nenhum'.
 */
class AllergenSafetyValidator
{
    public const STATE_NOT_EVALUATED = 'not_evaluated';
    public const STATE_DENIED = 'denied';
    public const STATE_CONFIRMED = 'confirmed';

    private PDO $db;

    /**
     * Termos que identificam cada grupo na anamnese do paciente (RDC 26/2015 ANVISA + moluscos).
     */
    private const ALLERGEN_TAXONOMY = [
        'gluten' => ['glúten', 'gluten', 'trigo', 'centeio', 'cevada', 'aveia', 'malte', 'celíaca', 'celiaca', 'celíaco', 'celiaco'],
        'lactose' => ['lactose', 'leite', 'queijo', 'iogurte', 'nata', 'soro de leite', 'aplv', 'proteína do leite', 'proteina do leite', 'caseína', 'caseina', 'laticínio', 'laticinio'],
        'ovo' => ['ovo', 'ovos', 'clara', 'gema', 'albumina'],
        'amendoim' => ['amendoim', 'pasta de amendoim'],
        'soja' => ['soja', 'tofu', 'edamame', 'shoyu'],
        'peixe' => ['peixe', 'atum', 'salmão', 'salmao', 'tilápia', 'tilapia', 'sardinha', 'bacalhau', 'merluza', 'pescada'],
        'crustaceos' => ['crustáceo', 'crustaceo', 'camarão', 'camarao', 'lagosta', 'siri', 'caranguejo', 'frutos do mar', 'fruto do mar'],
        'moluscos' => ['molusco', 'lula', 'polvo', 'mexilhão', 'mexilhao', 'ostra', 'marisco', 'vieira', 'frutos do mar', 'fruto do mar'],
        'castanhas' => ['castanha', 'amêndoa', 'amendoa', 'noz', 'nozes', 'pistache', 'avelã', 'avela', 'macadâmia', 'macadamia', 'pecã', 'pinoli'],
    ];

    /**
     * Termos adicionais buscados apenas no NOME do alimento (produtos que, em regra, contêm o grupo).
     * Nunca são usados para ler a anamnese.
     */
    private const FOOD_NAME_TERMS = [
        'gluten' => ['pão', 'biscoito', 'bolacha', 'bolo', 'macarrão', 'macarrao', 'torrada', 'farinha de trigo', 'pizza', 'lasanha', 'empada', 'coxinha', 'pastel', 'cuscuz marroquino', 'cerveja'],
        'lactose' => ['manteiga', 'requeijão', 'requeijao', 'creme de leite', 'doce de leite', 'chantilly'],
    ];

    /** Valores de `foods.allergens` que significam "verificado, não contém alérgenos". */
    private const VERIFIED_FREE = ['', 'nenhum', 'nenhum alérgeno', 'nenhum alergeno', 'none', 'free', 'sem alérgenos', 'sem alergenos'];

    public static function groupKeys(): array { return array_keys(self::ALLERGEN_TAXONOMY); }

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Extrai lista normalizada de grupos alergênicos do paciente a partir da anamnese mais recente.
     */
    public function getPatientAllergens(int $patientId): array
    {
        return $this->readPatientAllergies($patientId)['groups'];
    }

    /**
     * @return array{raw: string, groups: list<string>}
     */
    private function readPatientAllergies(int $patientId): array
    {
        $cur = $this->readCurrentPatientAllergies($patientId);
        // NI2-624: alergia de versão anterior (ou do cadastro) que a vigente não traz continua na trava
        try {
            $f = (new AllergyHistoryReview($this->db))->findings(TenantContext::getClinicId(), $patientId, $cur['groups'],
                fn(string $t) => $this->groupsOf($t), static fn(string $t) => self::isDenialText($t));
        } catch (\Throwable $e) {
            error_log('NI2-624 history review: ' . $e->getMessage());
            $f = [];
        }
        if ($f === []) {
            return $cur;
        }
        return [
            'raw' => trim($cur['raw'] . ' ' . implode('; ', array_unique(array_column($f, 'text')))),
            'groups' => array_values(array_unique(array_merge($cur['groups'], array_column($f, 'group')))),
        ];
    }

    /** @return array{raw: string, groups: list<string>} só a versão vigente (+ rascunho) ou o cadastro */
    private function readCurrentPatientAllergies(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // NI2-605: a última anamnese concluída + o que um rascunho mais novo acrescentar (nunca menos).
        $merged = \ClyoNutri\Domain\Anamnesis\AnamnesisVersions::mergeForSafety(
            \ClyoNutri\Domain\Anamnesis\AnamnesisVersions::pick($this->db, $clinicId, $patientId, 'a.allergies, a.version, a.created_at'));
        $raw = trim((string)($merged['row']['allergies'] ?? ''));
        if ($raw === '') {
            // Cadastro do paciente (migração 75) só completa quando a anamnese não informa; observações não contam.
            $raw = \ClyoNutri\Domain\Patient\PatientAllergyRecord::read($this->db, $clinicId, $patientId)['allergies'];
        }
        if ($raw === '') {
            return ['raw' => '', 'groups' => []];
        }

        $lowerText = mb_strtolower($raw, 'UTF-8');
        $found = [];
        foreach (self::ALLERGEN_TAXONOMY as $canonical => $terms) {
            foreach ($terms as $term) {
                if (mb_strpos($lowerText, $term) !== false) {
                    $found[$canonical] = true;
                    break;
                }
            }
        }

        return ['raw' => $raw, 'groups' => array_keys($found)];
    }

    /**
     * Valida um conjunto de alimentos contra as alergias do paciente.
     *
     * @return array{
     *   has_conflict: bool, patient_allergens: list<string>, conflicts: list<array>,
     *   allergy_recorded: bool, unmapped_allergy_text: bool, unverified_foods: list<array>,
     *   requires_confirmation: bool
     * }
     */
    public function validateFoodsForPatient(int $patientId, array $foodIds): array
    {
        $patient = $this->readPatientAllergies($patientId);
        $patientAllergens = $patient['groups'];
        $recorded = $patient['raw'] !== '';
        $result = [
            'has_conflict' => false,
            'patient_allergens' => $patientAllergens,
            'conflicts' => [],
            'allergy_recorded' => $recorded,
            'unmapped_allergy_text' => $recorded && $patientAllergens === [],
            'unverified_foods' => [],
            'requires_confirmation' => false,
        ];

        $uniqueIds = array_values(array_unique(array_filter(array_map('intval', $foodIds))));
        if (!$recorded || $uniqueIds === []) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        // Reauditoria (varredura): alimentos particulares de outra clínica nunca entram no resultado.
        $stmt = $this->db->prepare("SELECT id, name, category, allergens FROM foods WHERE id IN ({$placeholders}) AND (clinic_id IS NULL OR clinic_id = ?) ORDER BY id");
        $stmt->execute([...$uniqueIds, TenantContext::getClinicId()]);
        $foods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($foods as $food) {
            $foodNameLower = mb_strtolower((string)$food['name'], 'UTF-8');
            $isUnknown = $food['allergens'] === null;
            $declared = mb_strtolower(trim((string)($food['allergens'] ?? '')), 'UTF-8');
            $isVerifiedFree = !$isUnknown && in_array($declared, self::VERIFIED_FREE, true);
            $hasConflict = false;

            foreach ($patientAllergens as $allergen) {
                $terms = self::ALLERGEN_TAXONOMY[$allergen] ?? [$allergen];
                $nameTerms = array_merge($terms, self::FOOD_NAME_TERMS[$allergen] ?? []);
                $matchedTerm = null;

                foreach ($nameTerms as $term) {
                    if (mb_strpos($foodNameLower, $term) !== false) {
                        $matchedTerm = $term;
                        break;
                    }
                }
                if ($matchedTerm === null && $declared !== '' && !$isVerifiedFree) {
                    foreach ($terms as $term) {
                        if (mb_strpos($declared, $term) !== false || $declared === $allergen) {
                            $matchedTerm = $term;
                            break;
                        }
                    }
                }

                if ($matchedTerm !== null) {
                    $hasConflict = true;
                    $result['conflicts'][] = [
                        'food_id' => (int)$food['id'],
                        'food_name' => $food['name'],
                        'allergen_group' => $allergen,
                        'matched_term' => $matchedTerm,
                        'message' => "Alerta clínico: '{$food['name']}' pode conter {$allergen} ('{$matchedTerm}'), conflitante com a anamnese do paciente.",
                    ];
                }
            }

            if (!$hasConflict && $isUnknown) {
                $result['unverified_foods'][] = ['food_id' => (int)$food['id'], 'food_name' => $food['name']];
            }
        }

        $result['has_conflict'] = $result['conflicts'] !== [];
        $result['requires_confirmation'] = $result['has_conflict'] || $result['unverified_foods'] !== [] || $result['unmapped_allergy_text'];
        return $result;
    }

    /** Expressões que indicam alergia avaliada e explicitamente negada na anamnese. */
    public const DENIED_TERMS = [
        'nega', 'nenhuma', 'nenhum', 'sem alergia', 'sem alergias', 'não possui', 'nao possui', 'ndn', 'ausente', 'ausentes', 'não refere', 'nao refere',
    ];

    /**
     * NI2-525: Modela o estado de alergias e restrições do paciente ('confirmed', 'denied', 'not_evaluated').
     *
     * @return array{
     *   patient_id: int, state: string, state_label: string,
     *   allergies_raw: string, restrictions_raw: string,
     *   allergen_groups: list<string>, unmapped_allergy_text: bool
     * }
     */
    public function evaluatePatientAllergyState(int $patientId): array
    {
        $state = $this->evaluateCurrentAllergyState($patientId);
        return $this->applyHistoryReview($patientId, $state);
    }

    /**
     * NI2-624: alergia de versão anterior (ou do cadastro) que a vigente não traz continua protegendo e marca
     * "Exige revisão clínica". Nunca rebaixa um estado: só acrescenta grupos.
     */
    private function applyHistoryReview(int $patientId, array $state): array
    {
        $state['review_required'] = false;
        $state['review_findings'] = [];
        try {
            $findings = (new AllergyHistoryReview($this->db))->findings(TenantContext::getClinicId(), $patientId, $state['allergen_groups'] ?? [],
                fn(string $t) => $this->groupsOf($t), static fn(string $t) => self::isDenialText($t));
        } catch (\Throwable $e) {
            error_log('NI2-624 history review: ' . $e->getMessage());
            return $state;
        }
        if ($findings === []) {
            return $state;
        }
        $groups = array_values(array_unique(array_merge($state['allergen_groups'] ?? [], array_column($findings, 'group'))));
        $state['state'] = self::STATE_CONFIRMED;
        $state['state_label'] = 'Exige revisão clínica: alergia de versão anterior não confirmada na vigente';
        $state['allergen_groups'] = $groups;
        $state['patient_allergens'] = $groups;
        $state['review_required'] = true;
        $state['review_findings'] = array_map(static fn($f) => $f + ['message' => AllergyHistoryReview::describe($f)], $findings);
        return $state;
    }

    /** @return list<string> */
    private function groupsOf(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $found = [];
        foreach (self::ALLERGEN_TAXONOMY as $canonical => $terms) {
            foreach ($terms as $term) {
                if (mb_strpos($lower, $term) !== false) { $found[] = $canonical; break; }
            }
        }
        return $found;
    }

    public static function isDenialText(string $text): bool
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        foreach (self::DENIED_TERMS as $dt) {
            if ($t === $dt || str_starts_with($t, $dt . ' ') || str_starts_with($t, $dt . '.') || str_starts_with($t, $dt . ',')) {
                return true;
            }
        }
        return false;
    }

    private function evaluateCurrentAllergyState(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $hasRestrictionsCol = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'anamneses', 'restrictions');
        $hasDietaryRestrictionsCol = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'anamneses', 'dietary_restrictions');
        $hasAssessmentCol = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'anamneses', 'allergy_assessment');
        $cols = ['allergies'];
        $cols[] = $hasRestrictionsCol ? 'restrictions' : 'NULL AS restrictions';
        $cols[] = $hasDietaryRestrictionsCol ? 'dietary_restrictions' : 'NULL AS dietary_restrictions';
        $cols[] = $hasAssessmentCol ? 'allergy_assessment' : 'NULL AS allergy_assessment';
        // NI2-605: a última anamnese concluída + o que um rascunho mais novo acrescentar (nunca menos).
        $colsSql = implode(', ', array_map(static fn($c) => str_starts_with($c, 'NULL') ? $c : 'a.' . $c, $cols)) . ', a.version, a.created_at';
        $row = \ClyoNutri\Domain\Anamnesis\AnamnesisVersions::mergeForSafety(
            \ClyoNutri\Domain\Anamnesis\AnamnesisVersions::pick($this->db, $clinicId, $patientId, $colsSql))['row'] ?? false;

        $isFromPatientRecord = false;
        $allergiesRaw = $row ? trim((string)($row['allergies'] ?? '')) : '';
        $restrictionsRaw = $row ? trim((string)($row['restrictions'] ?? '') . ' ' . (string)($row['dietary_restrictions'] ?? '')) : '';
        $assessmentCol = $row ? trim((string)($row['allergy_assessment'] ?? '')) : '';

        if (!$row || ($allergiesRaw === '' && $restrictionsRaw === '' && $assessmentCol !== 'denied')) {
            $pRec = \ClyoNutri\Domain\Patient\PatientAllergyRecord::read($this->db, $clinicId, $patientId);
            $pRow = $pRec;
            if ($pRow) {
                $pAllergies = $pRec['allergies'];
                $pAssessment = $pRec['assessment'];
                if ($pAllergies !== '' || $pAssessment === 'denied') {
                    $row = [
                        'allergies' => $pAllergies,
                        'restrictions' => '',
                        'dietary_restrictions' => '',
                        'allergy_assessment' => $pAssessment,
                    ];
                    $allergiesRaw = $pAllergies;
                    $restrictionsRaw = '';
                    $assessmentCol = $pAssessment;
                    $isFromPatientRecord = true;
                }
            }
        }

        if (!$row) {
            return [
                'patient_id' => $patientId,
                'state' => self::STATE_NOT_EVALUATED,
                'state_label' => 'Não avaliado (sem anamnese)',
                'allergies_raw' => '',
                'restrictions_raw' => '',
                'allergen_groups' => [],
                'patient_allergens' => [],
                'unmapped_allergy_text' => false,
            ];
        }

        if ($allergiesRaw === '' && $restrictionsRaw === '' && $assessmentCol !== 'denied') {
            return [
                'patient_id' => $patientId,
                'state' => self::STATE_NOT_EVALUATED,
                'state_label' => 'Não avaliado',
                'allergies_raw' => '',
                'restrictions_raw' => '',
                'allergen_groups' => [],
                'patient_allergens' => [],
                'unmapped_allergy_text' => false,
            ];
        }

        $lowerAllergies = mb_strtolower($allergiesRaw, 'UTF-8');
        $isExplicitlyDenied = $assessmentCol === 'denied' || ($allergiesRaw !== '' && in_array($lowerAllergies, self::DENIED_TERMS, true));
        if (!$isExplicitlyDenied && $allergiesRaw !== '') {
            foreach (self::DENIED_TERMS as $dt) {
                if (str_starts_with($lowerAllergies, $dt . ' ') || str_starts_with($lowerAllergies, $dt . '.') || str_starts_with($lowerAllergies, $dt . ',')) {
                    $isExplicitlyDenied = true;
                    break;
                }
            }
        }

        $combinedLower = mb_strtolower($allergiesRaw . ' ' . $restrictionsRaw, 'UTF-8');
        $found = [];
        if (!$isExplicitlyDenied || $restrictionsRaw !== '') {
            $targetText = $isExplicitlyDenied ? mb_strtolower($restrictionsRaw, 'UTF-8') : $combinedLower;
            foreach (self::ALLERGEN_TAXONOMY as $canonical => $terms) {
                foreach ($terms as $term) {
                    if (mb_strpos($targetText, $term) !== false) {
                        $found[$canonical] = true;
                        break;
                    }
                }
            }
        }

        $groups = array_keys($found);
        if ($isExplicitlyDenied && $restrictionsRaw === '' && $groups === []) {
            return [
                'patient_id' => $patientId,
                'state' => self::STATE_DENIED,
                'state_label' => $isFromPatientRecord ? 'Negado no cadastro' : 'Negado em anamnese',
                'allergies_raw' => $allergiesRaw,
                'restrictions_raw' => '',
                'allergen_groups' => [],
                'patient_allergens' => [],
                'unmapped_allergy_text' => false,
            ];
        }

        return [
            'patient_id' => $patientId,
            'state' => self::STATE_CONFIRMED,
            'state_label' => $isFromPatientRecord ? 'Alergia registrada (Origem: Cadastro)' : 'Alergia / restrição registrada',
            'allergies_raw' => $allergiesRaw,
            'restrictions_raw' => $restrictionsRaw,
            'allergen_groups' => $groups,
            'patient_allergens' => $groups,
            'unmapped_allergy_text' => !$isExplicitlyDenied && $allergiesRaw !== '' && $groups === [],
        ];
    }

    /**
     * NI2-525: Verifica um único alimento no ato de seleção/adição na refeição para o paciente.
     * Nunca declara o alimento "seguro" apenas pela ausência de dados.
     */
    public function inspectFoodForPatient(int $patientId, int $foodId): array
    {
        $patientState = $this->evaluatePatientAllergyState($patientId);
        $stmt = $this->db->prepare("SELECT id, name, category, source, allergens FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :cid)");
        $stmt->execute(['id' => $foodId, 'cid' => TenantContext::getClinicId()]);
        $food = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$food) {
            throw new \InvalidArgumentException("Alimento #{$foodId} não encontrado no catálogo.");
        }
        return $this->inspectFoodRowForState($patientState, $food);
    }

    /**
     * NI2-525: Avalia uma linha de alimento já carregada frente ao estado de alergia do paciente.
     *
     * @return array{
     *   food_id: int, food_name: string, allergy_state: string,
     *   status: string, requires_override: bool, requires_attention: bool,
     *   matched_allergens: list<array{group: string, matched_term: string}>,
     *   provenance: string, message: string
     * }
     */
    public function inspectFoodRowForState(array $patientState, array $food): array
    {
        $foodId = (int)($food['id'] ?? 0);
        $foodName = (string)($food['name'] ?? '');
        $foodNameLower = mb_strtolower($foodName, 'UTF-8');
        $rawAllergens = $food['allergens'] ?? null;
        $isUnknown = $rawAllergens === null;
        $declared = mb_strtolower(trim((string)($rawAllergens ?? '')), 'UTF-8');
        $isVerifiedFree = !$isUnknown && in_array($declared, self::VERIFIED_FREE, true);
        $source = (string)($food['source'] ?? 'Catálogo');
        $provenance = $isUnknown
            ? "Fonte {$source}: composição de alérgenos não detalhada na tabela nutricional."
            : ($isVerifiedFree ? "Fonte {$source}: declarada sem os 9 grupos alergênicos mapeados (RDC 26/2015 ANVISA)." : "Fonte {$source}: alérgenos declarados ({$rawAllergens}).");

        $state = $patientState['state'] ?? 'not_evaluated';
        $groups = $patientState['allergen_groups'] ?? [];

        if ($state === 'not_evaluated') {
            return [
                'food_id' => $foodId,
                'food_name' => $foodName,
                'allergy_state' => 'not_evaluated',
                'status' => 'allergy_not_evaluated',
                'requires_override' => false,
                'requires_attention' => true,
                'matched_allergens' => [],
                'provenance' => $provenance,
                'message' => 'Alergias e restrições do paciente ainda não foram avaliadas na anamnese. Nunca presuma ausência de risco.',
            ];
        }

        $matched = [];
        foreach ($groups as $allergen) {
            $terms = self::ALLERGEN_TAXONOMY[$allergen] ?? [$allergen];
            $nameTerms = array_merge($terms, self::FOOD_NAME_TERMS[$allergen] ?? []);
            $matchedTerm = null;
            foreach ($nameTerms as $term) {
                if (mb_strpos($foodNameLower, $term) !== false) {
                    $matchedTerm = $term;
                    break;
                }
            }
            if ($matchedTerm === null && $declared !== '' && !$isVerifiedFree) {
                foreach ($terms as $term) {
                    if (mb_strpos($declared, $term) !== false || $declared === $allergen) {
                        $matchedTerm = $term;
                        break;
                    }
                }
            }
            if ($matchedTerm !== null) {
                $matched[] = ['group' => $allergen, 'matched_term' => $matchedTerm];
            }
        }

        if ($matched !== []) {
            $groupList = implode(', ', array_map(static fn(array $m) => $m['group'] . " ('" . $m['matched_term'] . "')", $matched));
            $matchedGroups = array_values(array_unique(array_map(static fn(array $m) => $m['group'], $matched)));
            return [
                'food_id' => $foodId,
                'food_name' => $foodName,
                'allergy_state' => $state,
                'status' => 'conflict',
                'requires_override' => true,
                'requires_attention' => true,
                'matched_allergens' => $matchedGroups,
                'matched_details' => $matched,
                'provenance' => $provenance,
                'message' => "Alerta clínico de alérgeno/restrição: '{$foodName}' contém ou está associado a {$groupList}, incompatível com a anamnese do paciente. Exige confirmação clínica e justificativa.",
            ];
        }

        if ($state === 'confirmed' && ($isUnknown || !empty($patientState['unmapped_allergy_text']))) {
            $reasonMsg = !empty($patientState['unmapped_allergy_text'])
                ? "O paciente possui alergia/restrição não mapeada automaticamente ('{$patientState['allergies_raw']}'). Confira a compatibilidade de '{$foodName}'."
                : "Composição de alérgenos não detalhada para '{$foodName}' ({$source}). O paciente possui alergia registrada (" . implode(', ', $groups) . "); incerteza de composição não significa ausência de alérgeno.";
            return [
                'food_id' => $foodId,
                'food_name' => $foodName,
                'allergy_state' => $state,
                'status' => 'unverified',
                'requires_override' => false,
                'requires_attention' => true,
                'matched_allergens' => [],
                'provenance' => $provenance,
                'message' => $reasonMsg,
            ];
        }

        return [
            'food_id' => $foodId,
            'food_name' => $foodName,
            'allergy_state' => $state,
            'status' => 'verified_no_match',
            'requires_override' => false,
            'requires_attention' => false,
            'matched_allergens' => [],
            'provenance' => $provenance,
            'message' => 'Nenhum conflito direto nos grupos mapeados (RDC 26/2015 ANVISA). Confira sempre rótulos de produtos industrializados.',
        ];
    }
}
