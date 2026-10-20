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
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT allergies
            FROM anamneses
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ORDER BY version DESC
            LIMIT 1
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        $raw = trim((string)($stmt->fetchColumn() ?: ''));
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
}
