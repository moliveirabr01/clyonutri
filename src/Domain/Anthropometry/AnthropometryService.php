<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;

/**
 * Serviço de Avaliação Antropométrica, Indicadores Clínicos e Evolução (Épico NI2-7).
 * Cobre NI2-65 (Registrar), NI2-66 (Indicadores), NI2-67 (Evolução), NI2-68 (Comparar) e NI2-69 (Compartilhamento).
 */
class AnthropometryService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Registra uma avaliação antropométrica completa com validação de plausibilidade biológica (NI2-65).
     *
     * @throws InvalidAnthropometricDataException se os dados forem biologicamente implausíveis ou inválidos.
     */
    public function recordAssessment(int $patientId, int $professionalId, array $data, ?int $userId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        // UX-01 (v14): campos opcionais vazios chegam do formulário como '' e, com strict_types,
        // derrubavam o salvamento (TypeError). Normaliza vazio -> null e vírgula decimal -> ponto.
        foreach (['weight_kg', 'height_cm', 'waist_cm', 'hip_cm', 'neck_cm', 'chest_cm', 'arm_cm', 'thigh_cm', 'calf_cm', 'body_fat_pct', 'muscle_mass_pct'] as $numericField) {
            $data[$numericField] = self::normalizeNumber($data[$numericField] ?? null);
        }

        // 1. Validação de presença obrigatória
        if (!isset($data['weight_kg']) || !is_numeric($data['weight_kg'])) {
            throw new InvalidAnthropometricDataException("Informe o peso em kg (ex.: 72,5). Nenhum outro dado do paciente é necessário para salvar.");
        }
        if (!isset($data['height_cm']) || !is_numeric($data['height_cm'])) {
            throw new InvalidAnthropometricDataException("Informe a altura em cm (ex.: 168) ou em metros (ex.: 1,68).");
        }

        $weight = (float)$data['weight_kg'];
        $height = (float)$data['height_cm'];

        // Normalização de altura se informada em metros (ex: 1.75 -> 175.0)
        if ($height > 0 && $height <= 3.0) {
            $height = round($height * 100.0, 1);
        }

        // 2. Validações de plausibilidade biológica (NI2-65)
        if ($weight < 10.0 || $weight > 400.0) {
            throw new InvalidAnthropometricDataException("Peso biologicamente implausível: {$weight} kg. O valor deve estar entre 10 e 400 kg.");
        }
        if ($height < 45.0 || $height > 250.0) {
            throw new InvalidAnthropometricDataException("Altura biologicamente implausível: {$height} cm. O valor deve estar entre 45 e 250 cm.");
        }

        // Circunferências (se informadas)
        $waist = $this->validateCircumference($data['waist_cm'] ?? null, 'Cintura');
        $hip = $this->validateCircumference($data['hip_cm'] ?? null, 'Quadril');
        $neck = $this->validateCircumference($data['neck_cm'] ?? null, 'Pescoço');
        $chest = $this->validateCircumference($data['chest_cm'] ?? null, 'Tórax');
        $arm = $this->validateCircumference($data['arm_cm'] ?? null, 'Braço');
        $thigh = $this->validateCircumference($data['thigh_cm'] ?? null, 'Coxa');
        $calf = $this->validateCircumference($data['calf_cm'] ?? null, 'Panturrilha');

        // Composição corporal (se informada)
        $bodyFatPct = $this->validatePercentage($data['body_fat_pct'] ?? null, 'Percentual de gordura', 1.0, 75.0);
        $muscleMassPct = $this->validatePercentage($data['muscle_mass_pct'] ?? null, 'Percentual de massa muscular', 5.0, 85.0);

        // 3. Cálculos de indicadores derivados (NI2-66)
        $bmi = AnthropometryCalculator::calculateBmi($weight, $height);
        $whr = AnthropometryCalculator::calculateWhr($waist, $hip);
        $whtr = AnthropometryCalculator::calculateWhtr($waist, $height);
        $comp = AnthropometryCalculator::calculateBodyComposition($weight, $bodyFatPct, $muscleMassPct);

        $assessedAt = self::normalizeAssessedAt($data['assessed_at'] ?? null);
        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;
        $isShared = !empty($data['is_shared']) ? 1 : 0;
        $sharedAt = $isShared ? date('Y-m-d H:i:s') : null;

        // UX-01 (v14): reenvio idêntico (retry após falha/duplo clique) não duplica a avaliação.
        $dup = $this->db->prepare("SELECT id FROM anthropometric_assessments
            WHERE clinic_id = :c AND patient_id = :p AND professional_id = :pr AND assessed_at = :a
              AND weight_kg = :w AND height_cm = :h AND COALESCE(notes, '') = :n ORDER BY id LIMIT 1");
        $dup->execute(['c' => $clinicId, 'p' => $patientId, 'pr' => $professionalId, 'a' => $assessedAt,
            'w' => round($weight, 2), 'h' => round($height, 2), 'n' => (string)($notes ?? '')]);
        $existingId = $dup->fetchColumn();
        if ($existingId !== false) {
            return ['id' => (int)$existingId, 'patient_id' => $patientId, 'duplicate' => true, 'weight_kg' => $weight,
                'height_cm' => $height, 'bmi' => $bmi, 'is_shared' => (bool)$isShared, 'assessed_at' => $assessedAt];
        }

        // 4. Persistência relacional
        $stmt = $this->db->prepare("
            INSERT INTO anthropometric_assessments (
                clinic_id, patient_id, professional_id,
                weight_kg, height_cm, bmi, waist_cm, hip_cm, whr,
                body_fat_pct, muscle_mass_pct,
                neck_cm, chest_cm, arm_cm, thigh_cm, calf_cm,
                notes, is_shared, shared_at, shared_by_user_id, assessed_at
            ) VALUES (
                :clinic_id, :patient_id, :professional_id,
                :weight_kg, :height_cm, :bmi, :waist_cm, :hip_cm, :whr,
                :body_fat_pct, :muscle_mass_pct,
                :neck_cm, :chest_cm, :arm_cm, :thigh_cm, :calf_cm,
                :notes, :is_shared, :shared_at, :shared_by_user_id, :assessed_at
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => $bmi,
            'waist_cm' => $waist,
            'hip_cm' => $hip,
            'whr' => $whr,
            'body_fat_pct' => $bodyFatPct,
            'muscle_mass_pct' => $muscleMassPct,
            'neck_cm' => $neck,
            'chest_cm' => $chest,
            'arm_cm' => $arm,
            'thigh_cm' => $thigh,
            'calf_cm' => $calf,
            'notes' => $notes,
            'is_shared' => $isShared,
            'shared_at' => $sharedAt,
            'shared_by_user_id' => $userId,
            'assessed_at' => $assessedAt,
        ]);

        $id = (int)$this->db->lastInsertId();

        // Se nasceu compartilhada, registra na trilha de auditoria
        if ($isShared) {
            $this->recordShareAudit($id, 'shared', $userId, 'Compartilhado no momento do registro');
        }

        return [
            'id' => $id,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => $bmi,
            'bmi_classification' => AnthropometryCalculator::classifyBmi($bmi),
            'waist_cm' => $waist,
            'hip_cm' => $hip,
            'whr' => $whr,
            'whtr' => $whtr,
            'body_fat_pct' => $bodyFatPct,
            'muscle_mass_pct' => $muscleMassPct,
            'fat_mass_kg' => $comp['fat_mass_kg'],
            'fat_free_mass_kg' => $comp['fat_free_mass_kg'],
            'muscle_mass_kg' => $comp['muscle_mass_kg'],
            'is_shared' => (bool)$isShared,
            'assessed_at' => $assessedAt,
        ];
    }

    /**
     * Retorna uma avaliação individual com todos os indicadores derivados calculados.
     */
    public function getAssessment(int $assessmentId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT a.*, p.name AS patient_name, p.gender AS patient_gender,
                   u.name AS professional_name
            FROM anthropometric_assessments a
            JOIN patients p ON p.id = a.patient_id
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.id = :id AND a.clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $assessmentId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateAssessmentDerivatives($row);
    }

    /**
     * Retorna a evolução temporal cronológica das avaliações antropométricas (NI2-67).
     * Inclui alternativa textual acessível e deltas ponto a ponto.
     */
    public function getEvolution(
        int $patientId,
        ?string $metric = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $sql = "
            SELECT a.*, u.name AS professional_name
            FROM anthropometric_assessments a
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.patient_id = :patient_id AND a.clinic_id = :clinic_id
        ";
        $params = [
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
        ];

        if (!empty($startDate)) {
            $sql .= " AND a.assessed_at >= :start_date";
            $params['start_date'] = $startDate;
        }
        if (!empty($endDate)) {
            $sql .= " AND a.assessed_at <= :end_date";
            $params['end_date'] = $endDate;
        }

        $sql .= " ORDER BY a.assessed_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $assessments = [];
        $metricSeries = [];
        $previousValue = null;

        $targetMetric = $metric ?? 'weight_kg';

        foreach ($rows as $index => $row) {
            $hydrated = $this->hydrateAssessmentDerivatives($row);
            $assessments[] = $hydrated;

            $currentVal = $hydrated[$targetMetric] ?? null;
            $delta = null;
            $deltaPct = null;

            if ($currentVal !== null && $previousValue !== null) {
                $delta = round($currentVal - $previousValue, 2);
                $deltaPct = $previousValue > 0 ? round(($delta / $previousValue) * 100, 2) : null;
            }

            if ($currentVal !== null) {
                $metricSeries[] = [
                    'assessment_id' => (int)$hydrated['id'],
                    'date' => date('Y-m-d', strtotime($hydrated['assessed_at'])),
                    'formatted_date' => date('d/m/Y', strtotime($hydrated['assessed_at'])),
                    'value' => (float)$currentVal,
                    'delta_previous' => $delta,
                    'delta_previous_pct' => $deltaPct,
                ];
                $previousValue = (float)$currentVal;
            }
        }

        // Alternativa textual acessível (NI2-67)
        $accessibleSummary = $this->buildAccessibleEvolutionSummary($targetMetric, $metricSeries);

        return [
            'patient_id' => $patientId,
            'metric' => $targetMetric,
            'total_assessments' => count($assessments),
            'assessments' => $assessments,
            'series' => $metricSeries,
            'accessible_summary' => $accessibleSummary,
        ];
    }

    /**
     * Compara duas avaliações antropométricas (A baseline e B follow-up) calculando deltas absolutos e percentuais (NI2-68).
     * Trata estritamente missing data (dados ausentes em uma ou ambas as medições).
     */
    public function compareAssessments(int $patientId, int $assessmentIdA, int $assessmentIdB): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $evalA = $this->getAssessment($assessmentIdA);
        $evalB = $this->getAssessment($assessmentIdB);

        if (!$evalA || (int)$evalA['patient_id'] !== $patientId) {
            throw new InvalidAnthropometricDataException("Avaliação baseline #{$assessmentIdA} não encontrada para este paciente.");
        }
        if (!$evalB || (int)$evalB['patient_id'] !== $patientId) {
            throw new InvalidAnthropometricDataException("Avaliação comparativa #{$assessmentIdB} não encontrada para este paciente.");
        }

        // Ordena cronologicamente: baseline mais antiga, follow-up mais recente
        if (strtotime($evalA['assessed_at']) > strtotime($evalB['assessed_at'])) {
            $temp = $evalA;
            $evalA = $evalB;
            $evalB = $temp;
        }

        $timeA = strtotime($evalA['assessed_at']);
        $timeB = strtotime($evalB['assessed_at']);
        $daysBetween = (int)round(($timeB - $timeA) / 86400);

        $metricsToCompare = [
            'weight_kg' => ['label' => 'Peso Corporal', 'unit' => 'kg', 'desirable_trend' => 'depends'],
            'bmi' => ['label' => 'IMC', 'unit' => 'kg/m²', 'desirable_trend' => 'depends'],
            'body_fat_pct' => ['label' => 'Gordura Corporal', 'unit' => '%', 'desirable_trend' => 'decrease'],
            'muscle_mass_pct' => ['label' => 'Massa Muscular', 'unit' => '%', 'desirable_trend' => 'increase'],
            'fat_mass_kg' => ['label' => 'Massa Gorda', 'unit' => 'kg', 'desirable_trend' => 'decrease'],
            'fat_free_mass_kg' => ['label' => 'Massa Magra', 'unit' => 'kg', 'desirable_trend' => 'increase'],
            'muscle_mass_kg' => ['label' => 'Massa Muscular Absoluta', 'unit' => 'kg', 'desirable_trend' => 'increase'],
            'waist_cm' => ['label' => 'Cintura', 'unit' => 'cm', 'desirable_trend' => 'decrease'],
            'hip_cm' => ['label' => 'Quadril', 'unit' => 'cm', 'desirable_trend' => 'neutral'],
            'whr' => ['label' => 'RCQ (Cintura/Quadril)', 'unit' => '', 'desirable_trend' => 'decrease'],
            'whtr' => ['label' => 'RCE (Cintura/Estatura)', 'unit' => '', 'desirable_trend' => 'decrease'],
            'neck_cm' => ['label' => 'Pescoço', 'unit' => 'cm', 'desirable_trend' => 'neutral'],
            'chest_cm' => ['label' => 'Tórax', 'unit' => 'cm', 'desirable_trend' => 'neutral'],
            'arm_cm' => ['label' => 'Braço', 'unit' => 'cm', 'desirable_trend' => 'neutral'],
            'thigh_cm' => ['label' => 'Coxa', 'unit' => 'cm', 'desirable_trend' => 'neutral'],
            'calf_cm' => ['label' => 'Panturrilha', 'unit' => 'cm', 'desirable_trend' => 'neutral'],
        ];

        $comparisonDetails = [];

        foreach ($metricsToCompare as $key => $meta) {
            $valA = isset($evalA[$key]) && is_numeric($evalA[$key]) ? (float)$evalA[$key] : null;
            $valB = isset($evalB[$key]) && is_numeric($evalB[$key]) ? (float)$evalB[$key] : null;

            if ($valA === null && $valB === null) {
                $status = 'missing_both';
                $deltaAbs = null;
                $deltaPct = null;
                $trend = null;
            } elseif ($valA === null) {
                $status = 'missing_in_baseline';
                $deltaAbs = null;
                $deltaPct = null;
                $trend = null;
            } elseif ($valB === null) {
                $status = 'missing_in_followup';
                $deltaAbs = null;
                $deltaPct = null;
                $trend = null;
            } else {
                $status = 'evaluated';
                $deltaAbs = round($valB - $valA, 2);
                $deltaPct = $valA > 0 ? round(($deltaAbs / $valA) * 100, 2) : 0.0;

                if ($deltaAbs > 0.001) {
                    $trend = 'increase';
                } elseif ($deltaAbs < -0.001) {
                    $trend = 'decrease';
                } else {
                    $trend = 'stable';
                }
            }

            $comparisonDetails[$key] = [
                'label' => $meta['label'],
                'unit' => $meta['unit'],
                'initial_value' => $valA,
                'final_value' => $valB,
                'delta_abs' => $deltaAbs,
                'delta_pct' => $deltaPct,
                'trend' => $trend,
                'status' => $status,
            ];
        }

        return [
            'patient_id' => $patientId,
            'baseline' => [
                'id' => (int)$evalA['id'],
                'date' => $evalA['assessed_at'],
                'formatted_date' => date('d/m/Y', strtotime($evalA['assessed_at'])),
                'evaluator' => $evalA['professional_name'] ?? 'Nutricionista',
            ],
            'followup' => [
                'id' => (int)$evalB['id'],
                'date' => $evalB['assessed_at'],
                'formatted_date' => date('d/m/Y', strtotime($evalB['assessed_at'])),
                'evaluator' => $evalB['professional_name'] ?? 'Nutricionista',
            ],
            'days_between' => $daysBetween,
            'metrics' => $comparisonDetails,
        ];
    }

    /**
     * Compartilha ou revoga explicitamente o acesso do paciente a uma avaliação antropométrica (NI2-69).
     * Registra auditoria rastreável da ação.
     */
    public function shareAssessment(int $assessmentId, bool $isShared, ?int $userId = null, ?string $reason = null): bool
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT id, patient_id, is_shared
            FROM anthropometric_assessments
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $assessmentId, 'clinic_id' => $clinicId]);
        $assessment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$assessment) {
            throw new InvalidAnthropometricDataException("Avaliação antropométrica #{$assessmentId} não encontrada nesta clínica.");
        }

        $now = date('Y-m-d H:i:s');
        $sharedInt = $isShared ? 1 : 0;
        $sharedAt = $isShared ? $now : null;

        $upStmt = $this->db->prepare("
            UPDATE anthropometric_assessments
            SET is_shared = :is_shared,
                shared_at = :shared_at,
                shared_by_user_id = :user_id
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $success = $upStmt->execute([
            'is_shared' => $sharedInt,
            'shared_at' => $sharedAt,
            'user_id' => $userId,
            'id' => $assessmentId,
            'clinic_id' => $clinicId,
        ]);

        if ($success) {
            $action = $isShared ? 'shared' : 'revoked';
            $this->recordShareAudit($assessmentId, $action, $userId, $reason);
        }

        return $success;
    }

    /**
     * Retorna a trilha de auditoria de compartilhamento da avaliação (NI2-69).
     */
    public function getShareHistory(int $assessmentId): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT s.*, u.name AS user_name
            FROM anthropometry_shares_audit s
            LEFT JOIN users u ON u.id = s.changed_by_user_id
            WHERE s.assessment_id = :app_id AND s.clinic_id = :clinic_id
            ORDER BY s.id ASC
        ");
        $stmt->execute(['app_id' => $assessmentId, 'clinic_id' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna apenas as avaliações antropométricas expressamente autorizadas e compartilhadas com o paciente (NI2-69).
     */
    public function getSharedAssessmentsForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name AS professional_name
            FROM anthropometric_assessments a
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.patient_id = :patient_id
              AND a.clinic_id = :clinic_id
              AND a.is_shared = 1
            ORDER BY a.assessed_at ASC
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hydrated = [];
        foreach ($rows as $row) {
            $hydrated[] = $this->hydrateAssessmentDerivatives($row);
        }

        return $hydrated;
    }

    /**
     * Hidrata uma avaliação com cálculos derivados da OMS e dados estruturados.
     */
    private function hydrateAssessmentDerivatives(array $row): array
    {
        $weight = isset($row['weight_kg']) ? (float)$row['weight_kg'] : null;
        $height = isset($row['height_cm']) ? (float)$row['height_cm'] : null;
        $waist = isset($row['waist_cm']) ? (float)$row['waist_cm'] : null;
        $hip = isset($row['hip_cm']) ? (float)$row['hip_cm'] : null;
        $fatPct = isset($row['body_fat_pct']) ? (float)$row['body_fat_pct'] : null;
        $musclePct = isset($row['muscle_mass_pct']) ? (float)$row['muscle_mass_pct'] : null;

        $bmi = isset($row['bmi']) ? (float)$row['bmi'] : AnthropometryCalculator::calculateBmi($weight, $height);
        $whr = isset($row['whr']) ? (float)$row['whr'] : AnthropometryCalculator::calculateWhr($waist, $hip);
        $whtr = AnthropometryCalculator::calculateWhtr($waist, $height);
        $idealWeight = AnthropometryCalculator::calculateIdealWeightRange($height);
        $comp = AnthropometryCalculator::calculateBodyComposition($weight, $fatPct, $musclePct);

        $gender = $row['patient_gender'] ?? null;
        $whrClassification = AnthropometryCalculator::classifyWhr($whr, $gender);

        $row['bmi'] = $bmi;
        $row['bmi_classification'] = AnthropometryCalculator::classifyBmi($bmi);
        $row['whr'] = $whr;
        $row['whr_classification'] = $whrClassification['label'] ?? null;
        $row['whr_risk'] = $whrClassification['risk'] ?? null;
        $row['whtr'] = $whtr;
        $row['whtr_classification'] = AnthropometryCalculator::classifyWhtr($whtr);
        $row['ideal_weight_range'] = $idealWeight;
        $row['fat_mass_kg'] = $comp['fat_mass_kg'];
        $row['fat_free_mass_kg'] = $comp['fat_free_mass_kg'];
        $row['muscle_mass_kg'] = $comp['muscle_mass_kg'];
        $row['is_shared'] = !empty($row['is_shared']);

        return $row;
    }

    /** UX-01: '' / espaço -> null; "70,5" -> "70.5"; mantém outros valores para a validação existente. */
    private static function normalizeNumber(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$/', $value) === 1) {
                $value = str_replace('.', '', $value);
            }
            $value = str_replace(',', '.', $value);
            return is_numeric($value) ? (float)$value : $value;
        }
        return is_int($value) || is_float($value) ? (float)$value : $value;
    }

    /** UX-01: datetime-local ("2026-10-15T23:57") -> "2026-10-15 23:57:00"; inválido -> agora. */
    private static function normalizeAssessedAt(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime(str_replace('T', ' ', trim($value)));
        if ($ts === false) {
            throw new InvalidAnthropometricDataException('Data da avaliação inválida. Use o formato dia/mês/ano e hora.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function validateCircumference(?float $val, string $fieldName): ?float
    {
        if ($val === null || $val === '' || (is_string($val) && trim($val) === '')) {
            return null;
        }
        $num = (float)$val;
        if ($num <= 0) {
            return null;
        }
        if ($num < 10.0 || $num > 250.0) {
            throw new InvalidAnthropometricDataException("Circunferência de {$fieldName} biologicamente implausível: {$num} cm. Deve estar entre 10 e 250 cm.");
        }
        return round($num, 2);
    }

    private function validatePercentage(?float $val, string $fieldName, float $min, float $max): ?float
    {
        if ($val === null || $val === '' || (is_string($val) && trim($val) === '')) {
            return null;
        }
        $num = (float)$val;
        if ($num <= 0) {
            return null;
        }
        if ($num < $min || $num > $max) {
            throw new InvalidAnthropometricDataException("{$fieldName} biologicamente implausível: {$num}%. Deve estar entre {$min}% e {$max}%.");
        }
        return round($num, 2);
    }

    private function recordShareAudit(int $assessmentId, string $action, ?int $userId, ?string $reason): void
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            INSERT INTO anthropometry_shares_audit (clinic_id, assessment_id, action, changed_by_user_id, reason)
            VALUES (:clinic_id, :app_id, :action, :uid, :reason)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'app_id' => $assessmentId,
            'action' => $action,
            'uid' => $userId,
            'reason' => $reason,
        ]);
    }

    private function buildAccessibleEvolutionSummary(string $metric, array $series): string
    {
        $count = count($series);
        if ($count === 0) {
            return "Nenhuma medição registrada para a métrica informada.";
        }

        $units = [
            'weight_kg' => ' kg',
            'bmi' => ' kg/m²',
            'waist_cm' => ' cm',
            'hip_cm' => ' cm',
            'neck_cm' => ' cm',
            'chest_cm' => ' cm',
            'arm_cm' => ' cm',
            'thigh_cm' => ' cm',
            'calf_cm' => ' cm',
            'body_fat_pct' => '%',
            'muscle_mass_pct' => '%',
            'whr' => '',
            'whtr' => '',
        ];
        $unit = $units[$metric] ?? '';
        $fmt = static fn(float $v, int $dec = 2) => number_format($v, $dec, ',', '.');

        if ($count === 1) {
            $p = $series[0];
            return "Única medição registrada em {$p['formatted_date']} com valor de " . $fmt((float)$p['value']) . "{$unit}.";
        }

        $first = $series[0];
        $last = $series[$count - 1];
        $totalDelta = round($last['value'] - $first['value'], 2);
        $totalDeltaPct = $first['value'] > 0 ? round(($totalDelta / $first['value']) * 100, 2) : 0.0;

        $trendWord = $totalDelta > 0 ? "aumento" : ($totalDelta < 0 ? "redução" : "estabilidade");
        $absDelta = abs($totalDelta);

        return "Evolução clínica de {$first['formatted_date']} (" . $fmt((float)$first['value']) . "{$unit}) a {$last['formatted_date']} (" . $fmt((float)$last['value']) . "{$unit}): {$trendWord} total de " . $fmt($absDelta) . "{$unit} (" . ($totalDeltaPct > 0 ? '+' : '') . $fmt($totalDeltaPct) . "%) ao longo de {$count} avaliações.";
    }
}
