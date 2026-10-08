<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;

class AllergenSafetyValidator
{
    private PDO $db;

    /**
     * Mapeamento de termos e sinônimos dos 14 principais grupos alergênicos (RDC 26/2015 ANVISA)
     */
    private const ALLERGEN_TAXONOMY = [
        'gluten' => ['glúten', 'gluten', 'trigo', 'centeio', 'cevada', 'aveia', 'malte'],
        'lactose' => ['lactose', 'leite', 'queijo', 'iogurte', 'nata', 'soro de leite'],
        'ovo' => ['ovo', 'ovos', 'clara', 'gema', 'albumina'],
        'amendoim' => ['amendoim', 'pasta de amendoim'],
        'soja' => ['soja', 'tofu', 'edamame', 'shoyu'],
        'peixe' => ['peixe', 'atum', 'salmão', 'tilápia', 'sardinha', 'bacalhau'],
        'crustaceos' => ['crustáceos', 'crustaceos', 'camarão', 'camarao', 'lagosta', 'siri', 'caranguejo'],
        'castanhas' => ['castanha', 'castanha-do-pará', 'castanha-de-caju', 'amêndoa', 'amendoa', 'noz', 'nozes', 'pistache', 'avelã', 'avela', 'macadâmia'],
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Extrai lista normalizada de alergias e intolerâncias do paciente a partir da anamnese.
     */
    public function getPatientAllergens(int $patientId): array
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
        $rawAllergies = $stmt->fetchColumn();

        if (!$rawAllergies) {
            return [];
        }

        $allergensFound = [];
        $lowerText = mb_strtolower((string)$rawAllergies, 'UTF-8');

        foreach (self::ALLERGEN_TAXONOMY as $canonical => $terms) {
            foreach ($terms as $term) {
                if (mb_strpos($lowerText, $term) !== false) {
                    $allergensFound[$canonical] = true;
                    break;
                }
            }
        }

        return array_keys($allergensFound);
    }

    /**
     * Valida um conjunto de alimentos contra as alergias do paciente.
     * Retorna array com os conflitos detectados.
     */
    public function validateFoodsForPatient(int $patientId, array $foodIds): array
    {
        $patientAllergens = $this->getPatientAllergens($patientId);
        if (empty($patientAllergens) || empty($foodIds)) {
            return [
                'has_conflict' => false,
                'patient_allergens' => $patientAllergens,
                'conflicts' => [],
            ];
        }

        $uniqueIds = array_unique(array_filter(array_map('intval', $foodIds)));
        if (empty($uniqueIds)) {
            return [
                'has_conflict' => false,
                'patient_allergens' => $patientAllergens,
                'conflicts' => [],
            ];
        }

        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $stmt = $this->db->prepare("SELECT id, name, category, allergens FROM foods WHERE id IN ({$placeholders})");
        $stmt->execute($uniqueIds);
        $foods = $stmt->fetchAll();

        $conflicts = [];

        foreach ($foods as $food) {
            $foodNameLower = mb_strtolower($food['name'], 'UTF-8');
            $foodAllergensLower = mb_strtolower((string)($food['allergens'] ?? ''), 'UTF-8');

            foreach ($patientAllergens as $allergen) {
                $terms = self::ALLERGEN_TAXONOMY[$allergen] ?? [$allergen];
                $matchedTerm = null;

                foreach ($terms as $term) {
                    if (mb_strpos($foodNameLower, $term) !== false || mb_strpos($foodAllergensLower, $term) !== false) {
                        $matchedTerm = $term;
                        break;
                    }
                }

                if ($matchedTerm !== null) {
                    $conflicts[] = [
                        'food_id' => (int)$food['id'],
                        'food_name' => $food['name'],
                        'allergen_group' => $allergen,
                        'matched_term' => $matchedTerm,
                        'message' => "Alerta clínico: '{$food['name']}' pode conter {$allergen} ('{$matchedTerm}'), conflitante com a anamnese do paciente.",
                    ];
                }
            }
        }

        return [
            'has_conflict' => count($conflicts) > 0,
            'patient_allergens' => $patientAllergens,
            'conflicts' => $conflicts,
        ];
    }
}
