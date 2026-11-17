<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Patient;

use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * NI2-585 (UX-002): alergia informada no cadastro do paciente (migração 75).
 *
 * Fonte única para o selo do perfil, o resumo clínico e a trava de alérgenos: a anamnese mais recente
 * vale primeiro; o cadastro só completa quando a anamnese não tem a informação.
 *
 * As observações livres NÃO viram alergia ("nega alergias" seria lido como alergia, e "não tem alergia
 * a amendoim" como conflito de amendoim). Quando elas citam alergia/intolerância, só sinalizamos
 * `notes_mention_allergy` para a tela pedir "confira as observações".
 *
 * Sem as colunas (banco ainda sem a migração 75), devolve vazio em vez de quebrar.
 */
final class PatientAllergyRecord
{
    /** @return array{allergies: string, assessment: string, notes_mention_allergy: bool} */
    public static function read(PDO $db, int $clinicId, int $patientId): array
    {
        $hasAllergies = SchemaInfo::hasColumn($db, 'patients', 'allergies');
        $hasAssessment = SchemaInfo::hasColumn($db, 'patients', 'allergy_assessment');
        $cols = 'notes'
            . ($hasAllergies ? ', allergies' : ', NULL AS allergies')
            . ($hasAssessment ? ', allergy_assessment' : ', NULL AS allergy_assessment');
        $st = $db->prepare("SELECT {$cols} FROM patients WHERE clinic_id = :clinic_id AND id = :patient_id");
        $st->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        $row = \ClyoNutri\Core\Security\ClinicalEncryptionService::decryptPatientRow($st->fetch(PDO::FETCH_ASSOC) ?: []) ?? [];
        $allergies = trim((string)($row['allergies'] ?? ''));
        $assessment = trim((string)($row['allergy_assessment'] ?? ''));
        if ($assessment === 'denied') {
            $allergies = ''; // "Nega alergias" vence texto residual
        }
        $notes = mb_strtolower((string)($row['notes'] ?? ''), 'UTF-8');
        return [
            'allergies' => $allergies,
            'assessment' => $assessment,
            'notes_mention_allergy' => $notes !== '' && (str_contains($notes, 'alerg') || str_contains($notes, 'intoler')),
        ];
    }
}
