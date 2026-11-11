<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anamnesis;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;

class AnamnesisService
{
    private PDO $db;

    public const MAX_FIELD_LENGTH = 5000;

    /** NI2-220: faixas clínicas plausíveis; valor fora delas é recusado com mensagem, nunca cortado. */
    public const WATER_ML_RANGE = [0, 10000];
    public const SLEEP_HOURS_RANGE = [0, 24];
    public const ACTIVITY_FREQUENCY_RANGE = [1, 7];
    public const ACTIVITY_DURATION_RANGE = [5, 720];

    /**
     * NI2-220: perguntas fechadas da anamnese. Sem resposta = NULL = "Não avaliado". Nenhuma opção é
     * pré-selecionada e nenhuma negativa é presumida.
     */
    public const ENUM_OPTIONS = [
        'has_surgeries' => ['no' => 'Não realizou cirurgias', 'yes' => 'Realizou cirurgias'],
        'medication_usage' => ['none' => 'Não faz uso', 'recorded' => 'Faz uso contínuo'],
        'supplement_usage' => ['none' => 'Não faz uso', 'recorded' => 'Faz uso de suplementos'],
        'intolerance_assessment' => ['none' => 'Não relata restrições ou intolerâncias', 'recorded' => 'Possui restrições ou intolerâncias'],
        'alcohol_consumption' => ['none' => 'Não consome', 'social' => 'Consumo social ou nos fins de semana', 'frequent' => 'Consumo frequente (3 vezes ou mais por semana)'],
        'smoking_status' => ['non_smoker' => 'Não fumante', 'former_smoker' => 'Ex-fumante', 'smoker' => 'Fumante ativo'],
        'physical_activity' => ['sedentary' => 'Sedentário (não pratica)', 'active' => 'Fisicamente ativo (pratica exercício)'],
        'sleep_quality' => ['good' => 'Boa, reparador', 'regular' => 'Regular', 'poor' => 'Ruim, não reparador'],
    ];
    public const NOT_ASSESSED_LABEL = 'Não avaliado';

    /** Texto para exibir uma resposta fechada; NULL ou desconhecido vira "Não avaliado". */
    public static function answerLabel(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NOT_ASSESSED_LABEL;
        }
        return self::ENUM_OPTIONS[$field][(string)$value] ?? self::NOT_ASSESSED_LABEL;
    }

    /** @param array<string,string> $errors */
    private function enumAnswer(array $data, string $field, array &$errors): ?string
    {
        $raw = $data[$field] ?? null;
        if ($raw === null || $raw === '' || $raw === 'not_assessed') {
            return null;
        }
        if (!is_string($raw) || !array_key_exists($raw, self::ENUM_OPTIONS[$field])) {
            $errors[$field] = 'Opção inválida. Escolha uma das opções da lista ou deixe como "Não avaliado".';
            return null;
        }
        return $raw;
    }

    private static function intOrNull(?float $v): ?int
    {
        return $v === null ? null : (int)$v;
    }

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Salva uma anamnese estruturada (finalizada ou rascunho parcial).
     * Sempre cria uma nova versão sequencial imutável (append-only).
     * Atende aos 12 blocos clínicos e campos condicionais da NI2-223.
     */
    public function saveAnamnesis(int $patientId, int $professionalId, array $data, bool $isDraft = false): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $sourceVersion = null;
        if (isset($data['revision_from'])) {
            $rawVersion = $data['revision_from'];
            if ((!is_string($rawVersion) && !is_int($rawVersion)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$rawVersion)) {
                throw new InvalidArgumentException('Versão de origem inválida.');
            }
            $sourceVersion = (int)$rawVersion;
            if (!$this->getAnamnesisVersion($patientId, $sourceVersion)) {
                throw new InvalidArgumentException('Versão de origem não encontrada.');
            }
        }

        // 1. Sanitização e normalização dos 12 blocos clínicos (NI2-223)
        // NI2-220: pergunta fechada sem resposta fica NULL ("não avaliado"); número fora da faixa clínica é recusado
        // com mensagem por campo, nunca cortado para caber. Nada é gravado enquanto houver erro.
        $fieldErrors = [];
        $cleanData = [
            'chief_complaint' => $this->sanitizeText($data['chief_complaint'] ?? null, 'Queixa principal'),
            'objectives' => $this->sanitizeText($data['objectives'] ?? null, 'Objetivos'),
            'family_history' => $this->sanitizeText($data['family_history'] ?? null, 'Histórico familiar'),
            'pathologies' => $this->sanitizeText($data['pathologies'] ?? null, 'Patologias diagnosticadas'),
            'has_surgeries' => $this->enumAnswer($data, 'has_surgeries', $fieldErrors),
            'surgeries_details' => null,
            'medication_usage' => $this->enumAnswer($data, 'medication_usage', $fieldErrors),
            'medications_details' => null,
            'supplement_usage' => $this->enumAnswer($data, 'supplement_usage', $fieldErrors),
            'supplements_details' => null,
            'intolerance_assessment' => $this->enumAnswer($data, 'intolerance_assessment', $fieldErrors),
            'restrictions' => $this->sanitizeText($data['restrictions'] ?? null, 'Restrições e intolerâncias'),
            'allergies' => $this->sanitizeText($data['allergies'] ?? null, 'Alergias'),
            'food_preferences' => $this->sanitizeText($data['food_preferences'] ?? null, 'Preferências alimentares'),
            'habits' => $this->sanitizeText($data['habits'] ?? null, 'Hábitos alimentares'),
            'alcohol_consumption' => $this->enumAnswer($data, 'alcohol_consumption', $fieldErrors),
            'alcohol_details' => null,
            'smoking_status' => $this->enumAnswer($data, 'smoking_status', $fieldErrors),
            'smoking_details' => null,
            'water_intake_ml' => self::intOrNull(\ClyoNutri\Domain\Clinical\ClinicalInputException::number($data['water_intake_ml'] ?? null, 'water_intake_ml', 'Água por dia', self::WATER_ML_RANGE[0], self::WATER_ML_RANGE[1], 'ml', $fieldErrors, true)),
            'routine' => $this->sanitizeText($data['routine'] ?? null, 'Rotina diária'),
            'sleep_hours' => \ClyoNutri\Domain\Clinical\ClinicalInputException::number($data['sleep_hours'] ?? null, 'sleep_hours', 'Horas de sono por noite', self::SLEEP_HOURS_RANGE[0], self::SLEEP_HOURS_RANGE[1], 'horas', $fieldErrors),
            'sleep_quality' => $this->enumAnswer($data, 'sleep_quality', $fieldErrors),
            'sleep_notes' => $this->sanitizeText($data['sleep_notes'] ?? null, 'Notas sobre o sono'),
            'physical_activity' => $this->enumAnswer($data, 'physical_activity', $fieldErrors),
            'activity_modality' => null,
            'activity_frequency_weekly' => null,
            'activity_duration_minutes' => null,
            'symptoms' => $this->sanitizeText($data['symptoms'] ?? null, 'Sinais e sintomas'),
            'referenced_exams' => $this->sanitizeText($data['referenced_exams'] ?? null, 'Exames referenciados'),
            'notes' => $this->sanitizeText($data['notes'] ?? null, 'Observações'),
            'clinical_history' => $this->sanitizeText($data['clinical_history'] ?? null, 'Histórico clínico'),
        ];

        if ($fieldErrors) {
            throw new \ClyoNutri\Domain\Clinical\ClinicalInputException($fieldErrors);
        }

        // 2. Validação e resolução de campos clínicos condicionais (NI2-223)
        // 2.1 Alergias
        $assessment = $data['allergy_assessment'] ?? (!empty($cleanData['allergies']) ? 'recorded' : 'not_assessed');
        if (!is_string($assessment) || !in_array($assessment, ['not_assessed', 'denied', 'recorded'], true)) {
            throw new InvalidArgumentException('Selecione a avaliação de alergias.');
        }
        if ($assessment === 'denied' && !empty($cleanData['allergies'])) {
            throw new InvalidArgumentException('A negativa de alergias não pode conter alergias registradas. Confira a seleção.');
        }
        if ($assessment === 'recorded' && empty($cleanData['allergies'])) {
            throw new InvalidArgumentException('Descreva as alergias registradas.');
        }
        if ($assessment === 'not_assessed' && !empty($cleanData['allergies'])) {
            $assessment = 'recorded';
        }

        // 2.2 Cirurgias prévias
        if ($cleanData['has_surgeries'] === 'yes') {
            $cleanData['surgeries_details'] = $this->sanitizeText($data['surgeries_details'] ?? null, 'Detalhes de cirurgias');
            if (!$isDraft && empty($cleanData['surgeries_details'])) {
                throw new InvalidArgumentException('Informe os detalhes ou o ano das cirurgias prévias realizadas.');
            }
        }

        // 2.3 Medicamentos contínuos
        if ($cleanData['medication_usage'] === 'recorded') {
            $cleanData['medications_details'] = $this->sanitizeText($data['medications_details'] ?? null, 'Medicamentos em uso');
            if (!$isDraft && empty($cleanData['medications_details'])) {
                throw new InvalidArgumentException('Descreva os medicamentos em uso (nome, dose ou horário).');
            }
        }

        // 2.4 Suplementos alimentares
        if ($cleanData['supplement_usage'] === 'recorded') {
            $cleanData['supplements_details'] = $this->sanitizeText($data['supplements_details'] ?? null, 'Suplementos em uso');
            if (!$isDraft && empty($cleanData['supplements_details'])) {
                throw new InvalidArgumentException('Descreva os suplementos alimentares em uso.');
            }
        }

        // 2.5 Intolerâncias e restrições
        if ($cleanData['intolerance_assessment'] === 'recorded' && empty($cleanData['restrictions']) && !$isDraft) {
            throw new InvalidArgumentException('Descreva as restrições ou intolerâncias alimentares registradas.');
        }

        // 2.6 Álcool e Tabagismo
        if (in_array($cleanData['alcohol_consumption'], ['social', 'frequent'], true)) {
            $cleanData['alcohol_details'] = $this->sanitizeText($data['alcohol_details'] ?? null, 'Consumo de álcool');
        }
        if (in_array($cleanData['smoking_status'], ['former_smoker', 'smoker'], true)) {
            $cleanData['smoking_details'] = $this->sanitizeText($data['smoking_details'] ?? null, 'Tabagismo');
        }

        // 2.7 Atividade física
        if ($cleanData['physical_activity'] === 'active') {
            $cleanData['activity_modality'] = $this->sanitizeText($data['activity_modality'] ?? null, 'Modalidade da atividade física');
            $cleanData['activity_frequency_weekly'] = self::intOrNull(\ClyoNutri\Domain\Clinical\ClinicalInputException::number($data['activity_frequency_weekly'] ?? null, 'activity_frequency_weekly', 'Frequência semanal', self::ACTIVITY_FREQUENCY_RANGE[0], self::ACTIVITY_FREQUENCY_RANGE[1], 'dias', $fieldErrors, true));
            $cleanData['activity_duration_minutes'] = self::intOrNull(\ClyoNutri\Domain\Clinical\ClinicalInputException::number($data['activity_duration_minutes'] ?? null, 'activity_duration_minutes', 'Duração da atividade', self::ACTIVITY_DURATION_RANGE[0], self::ACTIVITY_DURATION_RANGE[1], 'minutos', $fieldErrors, true));
            if ($fieldErrors) {
                throw new \ClyoNutri\Domain\Clinical\ClinicalInputException($fieldErrors);
            }
            if (!$isDraft && (empty($cleanData['activity_modality']) || $cleanData['activity_frequency_weekly'] === null)) {
                throw new InvalidArgumentException('Para pacientes fisicamente ativos, informe a modalidade e a frequência semanal.');
            }
        }

        // 3. Validação fail-closed se não for rascunho
        if (!$isDraft) {
            $hasAnyContent = false;
            foreach ($cleanData as $key => $val) {
                // NI2-220: as perguntas fechadas já não têm resposta padrão; resposta marcada conta como conteúdo.
                if ($val !== null && $val !== '') {
                    $hasAnyContent = true;
                    break;
                }
            }
            if (!$hasAnyContent && $assessment !== 'denied') {
                throw new InvalidArgumentException("A anamnese finalizada exige o preenchimento de ao menos uma seção clínica.");
            }
        }

        // Snapshot JSON estruturado completo
        $cleanData['structured_data_json'] = json_encode($cleanData, JSON_UNESCAPED_UNICODE);

        // NI2-716 / CR-NI2-001 + v54.46 / CR2-NI2-001: cifrar campos clínicos em repouso (AES-256-GCM),
        // inclusive o snapshot structured_data_json (não fica mais cópia clínica em claro). Leitura legada em claro segue.
        $cleanData = ClinicalEncryptionService::encryptAnamnesisRow($cleanData);

        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = 'anamnesis_' . bin2hex(random_bytes(8));
        if ($ownsTransaction) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT ' . $savepoint);
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->db->prepare('SELECT id FROM patients WHERE id = ? AND clinic_id = ? FOR UPDATE');
                $lock->execute([$patientId, $clinicId]);
            }
            // 4. Obter rascunho existente ou próxima versão
            $checkDraft = $this->db->prepare("
                SELECT id, version, professional_id, created_at FROM anamneses
                WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND is_draft = 1
                ORDER BY id DESC LIMIT 1
            ");
            $checkDraft->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
            $existingDraft = $checkDraft->fetch();
            if ($existingDraft) {
                // v54.57 (UX-103): nunca sobrescrever um rascunho com campo cifrado ilegível; o conteúdo poderia ser
                // recuperável com a chave certa. O nutricionista decide na tela (recoverUnreadableDraft).
                $rawDraft = $this->db->prepare('SELECT * FROM anamneses WHERE id = :id AND clinic_id = :c');
                $rawDraft->execute(['id' => (int)$existingDraft['id'], 'c' => $clinicId]);
                $bad = ClinicalEncryptionService::unreadableAnamnesisFields($rawDraft->fetch(PDO::FETCH_ASSOC) ?: null);
                if ($bad !== []) {
                    throw new UnreadableAnamnesisDraftException($bad, $patientId);
                }
            }

            $createdAt = date('Y-m-d H:i:s');
            $hasStructured = $this->hasStructuredColumns();

            if ($existingDraft) {
                $id = (int)$existingDraft['id'];
                $nextVersion = (int)$existingDraft['version'];
                // NI2-220 (I1): salvar o rascunho de novo não troca quem o começou nem quando. A última edição fica em
                // updated_at / updated_by_professional_id (migração 86). Ao concluir, a versão passa a ser do profissional
                // que concluiu, com a data da conclusão, e a origem do rascunho fica registrada no JSON estruturado.
                $trackEditor = $this->hasColumn('updated_by_professional_id');
                if (!$isDraft && $hasStructured) {
                    $snapshot = json_decode((string)ClinicalEncryptionService::decrypt((string)$cleanData['structured_data_json']), true) ?: [];
                    $snapshot['draft_origin'] = ['professional_id' => (int)$existingDraft['professional_id'], 'started_at' => (string)$existingDraft['created_at']];
                    $cleanData['structured_data_json'] = ClinicalEncryptionService::encrypt((string)json_encode($snapshot, JSON_UNESCAPED_UNICODE));
                }
                if ($hasStructured) {
                    $stmt = $this->db->prepare("
                        UPDATE anamneses SET
                            " . ($isDraft ? '' : 'professional_id = :professional_id,') . "
                            " . ($trackEditor ? 'updated_at = :updated_at, updated_by_professional_id = :updated_by,' : '') . "
                            chief_complaint = :chief_complaint,
                            objectives = :objectives,
                            family_history = :family_history,
                            pathologies = :pathologies,
                            has_surgeries = :has_surgeries,
                            surgeries_details = :surgeries_details,
                            medication_usage = :medication_usage,
                            medications_details = :medications_details,
                            supplement_usage = :supplement_usage,
                            supplements_details = :supplements_details,
                            intolerance_assessment = :intolerance_assessment,
                            routine = :routine,
                            food_preferences = :food_preferences,
                            restrictions = :restrictions,
                            allergies = :allergies,
                            allergy_assessment = :allergy_assessment,
                            symptoms = :symptoms,
                            habits = :habits,
                            alcohol_consumption = :alcohol_consumption,
                            alcohol_details = :alcohol_details,
                            smoking_status = :smoking_status,
                            smoking_details = :smoking_details,
                            sleep_hours = :sleep_hours,
                            sleep_quality = :sleep_quality,
                            sleep_notes = :sleep_notes,
                            physical_activity = :physical_activity,
                            activity_modality = :activity_modality,
                            activity_frequency_weekly = :activity_frequency_weekly,
                            activity_duration_minutes = :activity_duration_minutes,
                            referenced_exams = :referenced_exams,
                            clinical_history = :clinical_history,
                            water_intake_ml = :water_intake_ml,
                            is_draft = :is_draft,
                            notes = :notes,
                            structured_data_json = :structured_data_json
                            " . ($isDraft ? '' : ', created_at = :created_at') . "
                        WHERE id = :id AND clinic_id = :clinic_id
                    ");
                    $stmt->execute(array_merge(
                        $isDraft ? [] : ['professional_id' => $professionalId, 'created_at' => $createdAt],
                        $trackEditor ? ['updated_at' => $createdAt, 'updated_by' => $professionalId] : [], [
                        'chief_complaint' => $cleanData['chief_complaint'],
                        'objectives' => $cleanData['objectives'],
                        'family_history' => $cleanData['family_history'],
                        'pathologies' => $cleanData['pathologies'],
                        'has_surgeries' => $cleanData['has_surgeries'],
                        'surgeries_details' => $cleanData['surgeries_details'],
                        'medication_usage' => $cleanData['medication_usage'],
                        'medications_details' => $cleanData['medications_details'],
                        'supplement_usage' => $cleanData['supplement_usage'],
                        'supplements_details' => $cleanData['supplements_details'],
                        'intolerance_assessment' => $cleanData['intolerance_assessment'],
                        'routine' => $cleanData['routine'],
                        'food_preferences' => $cleanData['food_preferences'],
                        'restrictions' => $cleanData['restrictions'],
                        'allergies' => $cleanData['allergies'],
                        'allergy_assessment' => $assessment,
                        'symptoms' => $cleanData['symptoms'],
                        'habits' => $cleanData['habits'],
                        'alcohol_consumption' => $cleanData['alcohol_consumption'],
                        'alcohol_details' => $cleanData['alcohol_details'],
                        'smoking_status' => $cleanData['smoking_status'],
                        'smoking_details' => $cleanData['smoking_details'],
                        'sleep_hours' => $cleanData['sleep_hours'],
                        'sleep_quality' => $cleanData['sleep_quality'],
                        'sleep_notes' => $cleanData['sleep_notes'],
                        'physical_activity' => $cleanData['physical_activity'],
                        'activity_modality' => $cleanData['activity_modality'],
                        'activity_frequency_weekly' => $cleanData['activity_frequency_weekly'],
                        'activity_duration_minutes' => $cleanData['activity_duration_minutes'],
                        'referenced_exams' => $cleanData['referenced_exams'],
                        'clinical_history' => $cleanData['clinical_history'],
                        'water_intake_ml' => $cleanData['water_intake_ml'],
                        'is_draft' => $isDraft ? 1 : 0,
                        'notes' => $cleanData['notes'],
                        'structured_data_json' => $cleanData['structured_data_json'],
                        'id' => $id,
                        'clinic_id' => $clinicId,
                    ]));
                } else {
                    // Fallback para schema antes da migração 73
                    $stmt = $this->db->prepare("
                        UPDATE anamneses SET
                            " . ($isDraft ? '' : 'professional_id = :professional_id, created_at = :created_at,') . "
                            objectives = :objectives,
                            routine = :routine,
                            food_preferences = :food_preferences,
                            restrictions = :restrictions,
                            allergies = :allergies,
                            allergy_assessment = :allergy_assessment,
                            symptoms = :symptoms,
                            habits = :habits,
                            clinical_history = :clinical_history,
                            water_intake_ml = :water_intake_ml,
                            is_draft = :is_draft,
                            notes = :notes
                        WHERE id = :id AND clinic_id = :clinic_id
                    ");
                    $stmt->execute(array_merge($isDraft ? [] : ['professional_id' => $professionalId, 'created_at' => $createdAt], [
                        'objectives' => $cleanData['objectives'],
                        'routine' => $cleanData['routine'],
                        'food_preferences' => $cleanData['food_preferences'],
                        'restrictions' => $cleanData['restrictions'],
                        'allergies' => $cleanData['allergies'],
                        'allergy_assessment' => $assessment,
                        'symptoms' => $cleanData['symptoms'],
                        'habits' => $cleanData['habits'],
                        'clinical_history' => $cleanData['clinical_history'],
                        'water_intake_ml' => $cleanData['water_intake_ml'],
                        'is_draft' => $isDraft ? 1 : 0,
                        'notes' => $cleanData['notes'],
                        'id' => $id,
                        'clinic_id' => $clinicId,
                    ]));
                }
                if ($isDraft) {
                    $createdAt = (string)$existingDraft['created_at'];
                }
            } else {
                $vStmt = $this->db->prepare("
                    SELECT COALESCE(MAX(version), 0) + 1 FROM anamneses
                    WHERE patient_id = :patient_id AND clinic_id = :clinic_id
                ");
                $vStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
                $nextVersion = (int)$vStmt->fetchColumn();

                if ($hasStructured) {
                    $stmt = $this->db->prepare("
                        INSERT INTO anamneses (
                            clinic_id, patient_id, professional_id, version,
                            chief_complaint, objectives, family_history, pathologies,
                            has_surgeries, surgeries_details, medication_usage, medications_details,
                            supplement_usage, supplements_details, intolerance_assessment,
                            routine, food_preferences, restrictions,
                            allergies, allergy_assessment, symptoms, habits,
                            alcohol_consumption, alcohol_details, smoking_status, smoking_details,
                            sleep_hours, sleep_quality, sleep_notes, physical_activity,
                            activity_modality, activity_frequency_weekly, activity_duration_minutes,
                            referenced_exams, clinical_history, water_intake_ml, is_draft, notes,
                            structured_data_json, created_at
                        ) VALUES (
                            :clinic_id, :patient_id, :professional_id, :version,
                            :chief_complaint, :objectives, :family_history, :pathologies,
                            :has_surgeries, :surgeries_details, :medication_usage, :medications_details,
                            :supplement_usage, :supplements_details, :intolerance_assessment,
                            :routine, :food_preferences, :restrictions,
                            :allergies, :allergy_assessment, :symptoms, :habits,
                            :alcohol_consumption, :alcohol_details, :smoking_status, :smoking_details,
                            :sleep_hours, :sleep_quality, :sleep_notes, :physical_activity,
                            :activity_modality, :activity_frequency_weekly, :activity_duration_minutes,
                            :referenced_exams, :clinical_history, :water_intake_ml, :is_draft, :notes,
                            :structured_data_json, :created_at
                        )
                    ");
                    $stmt->execute([
                        'clinic_id' => $clinicId,
                        'patient_id' => $patientId,
                        'professional_id' => $professionalId,
                        'version' => $nextVersion,
                        'chief_complaint' => $cleanData['chief_complaint'],
                        'objectives' => $cleanData['objectives'],
                        'family_history' => $cleanData['family_history'],
                        'pathologies' => $cleanData['pathologies'],
                        'has_surgeries' => $cleanData['has_surgeries'],
                        'surgeries_details' => $cleanData['surgeries_details'],
                        'medication_usage' => $cleanData['medication_usage'],
                        'medications_details' => $cleanData['medications_details'],
                        'supplement_usage' => $cleanData['supplement_usage'],
                        'supplements_details' => $cleanData['supplements_details'],
                        'intolerance_assessment' => $cleanData['intolerance_assessment'],
                        'routine' => $cleanData['routine'],
                        'food_preferences' => $cleanData['food_preferences'],
                        'restrictions' => $cleanData['restrictions'],
                        'allergies' => $cleanData['allergies'],
                        'allergy_assessment' => $assessment,
                        'symptoms' => $cleanData['symptoms'],
                        'habits' => $cleanData['habits'],
                        'alcohol_consumption' => $cleanData['alcohol_consumption'],
                        'alcohol_details' => $cleanData['alcohol_details'],
                        'smoking_status' => $cleanData['smoking_status'],
                        'smoking_details' => $cleanData['smoking_details'],
                        'sleep_hours' => $cleanData['sleep_hours'],
                        'sleep_quality' => $cleanData['sleep_quality'],
                        'sleep_notes' => $cleanData['sleep_notes'],
                        'physical_activity' => $cleanData['physical_activity'],
                        'activity_modality' => $cleanData['activity_modality'],
                        'activity_frequency_weekly' => $cleanData['activity_frequency_weekly'],
                        'activity_duration_minutes' => $cleanData['activity_duration_minutes'],
                        'referenced_exams' => $cleanData['referenced_exams'],
                        'clinical_history' => $cleanData['clinical_history'],
                        'water_intake_ml' => $cleanData['water_intake_ml'],
                        'is_draft' => $isDraft ? 1 : 0,
                        'notes' => $cleanData['notes'],
                        'structured_data_json' => $cleanData['structured_data_json'],
                        'created_at' => $createdAt,
                    ]);
                } else {
                    $stmt = $this->db->prepare("
                        INSERT INTO anamneses (
                            clinic_id, patient_id, professional_id, version,
                            objectives, routine, food_preferences, restrictions,
                            allergies, allergy_assessment, symptoms, habits, clinical_history,
                            water_intake_ml, is_draft, notes, created_at
                        ) VALUES (
                            :clinic_id, :patient_id, :professional_id, :version,
                            :objectives, :routine, :food_preferences, :restrictions,
                            :allergies, :allergy_assessment, :symptoms, :habits, :clinical_history,
                            :water_intake_ml, :is_draft, :notes, :created_at
                        )
                    ");
                    $stmt->execute([
                        'clinic_id' => $clinicId,
                        'patient_id' => $patientId,
                        'professional_id' => $professionalId,
                        'version' => $nextVersion,
                        'objectives' => $cleanData['objectives'],
                        'routine' => $cleanData['routine'],
                        'food_preferences' => $cleanData['food_preferences'],
                        'restrictions' => $cleanData['restrictions'],
                        'allergies' => $cleanData['allergies'],
                        'allergy_assessment' => $assessment,
                        'symptoms' => $cleanData['symptoms'],
                        'habits' => $cleanData['habits'],
                        'clinical_history' => $cleanData['clinical_history'],
                        'water_intake_ml' => $cleanData['water_intake_ml'],
                        'is_draft' => $isDraft ? 1 : 0,
                        'notes' => $cleanData['notes'],
                        'created_at' => $createdAt,
                    ]);
                }
                $id = (int)$this->db->lastInsertId();
            }

            // 5. Auditoria sem PII
            $this->logAudit($clinicId, $patientId, $isDraft ? 'ANAMNESIS_DRAFT_SAVED' : 'ANAMNESIS_RECORD_CREATED', (string)$id, [
                'version' => $nextVersion,
                'is_draft' => $isDraft,
                'has_allergies' => !empty($cleanData['allergies']),
                'allergy_assessment' => $assessment,
                'has_restrictions' => !empty($cleanData['restrictions']),
                'has_medications' => $cleanData['medication_usage'] === 'recorded',
                'has_supplements' => $cleanData['supplement_usage'] === 'recorded',
                'physical_activity' => $cleanData['physical_activity'],
                'source_version' => $sourceVersion,
            ]);

            if ($ownsTransaction) $this->db->commit();
            else $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            return [
                'id' => $id,
                'version' => $nextVersion,
                'patient_id' => $patientId,
                'is_draft' => $isDraft,
                'created_at' => $createdAt,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            elseif (!$ownsTransaction && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
    }

    /**
     * Recupera a anamnese vigente mais recente do paciente.
     */
    public function getLatestAnamnesis(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name as professional_name
            FROM anamneses a
            JOIN professionals p ON p.id = a.professional_id
            JOIN users u ON u.id = p.user_id
            WHERE a.clinic_id = :clinic_id AND a.patient_id = :patient_id
            ORDER BY a.version DESC
            LIMIT 1
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        $row = $stmt->fetch();

        return $row ? ClinicalEncryptionService::decryptAnamnesisRow($row) : null;
    }

    /** v54.57 (UX-103): rótulo em português de um campo cifrado da anamnese. */
    public static function fieldLabel(string $field): string
    {
        return [
            'objectives' => 'Objetivos', 'routine' => 'Rotina', 'food_preferences' => 'Preferências alimentares',
            'restrictions' => 'Restrições', 'allergies' => 'Alergias', 'symptoms' => 'Sintomas', 'habits' => 'Hábitos',
            'clinical_history' => 'Histórico clínico', 'notes' => 'Observações', 'structured_data_json' => 'Respostas estruturadas',
        ][$field] ?? $field;
    }

    /**
     * v54.57 (UX-103): campos ilegíveis do rascunho em andamento (vazio quando o rascunho está íntegro ou não existe).
     * @return array{id:int,version:int,fields:list<string>}|null
     */
    public function unreadableDraft(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        if (!$this->hasColumn('is_draft')) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM anamneses WHERE patient_id = :p AND clinic_id = :c AND is_draft = 1 ORDER BY id DESC LIMIT 1');
        $st->execute(['p' => $patientId, 'c' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $bad = ClinicalEncryptionService::unreadableAnamnesisFields($row);
        return $bad === [] ? null : ['id' => (int)$row['id'], 'version' => (int)$row['version'], 'fields' => $bad];
    }

    /**
     * v54.57 (UX-103): recupera o rascunho em andamento. Mantém tudo o que está legível e esvazia SÓ os campos
     * ilegíveis. O conteúdo cifrado descartado fica guardado na auditoria (ANAMNESIS_DRAFT_UNREADABLE_DISCARDED), ainda
     * cifrado, para uma eventual recuperação com a chave correta. Versões concluídas nunca são tocadas.
     * @return list<string> campos descartados (vazio = nada a recuperar)
     */
    public function recoverUnreadableDraft(int $patientId, int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $info = $this->unreadableDraft($patientId);
        if ($info === null) {
            return [];
        }
        $st = $this->db->prepare('SELECT * FROM anamneses WHERE id = :id AND clinic_id = :c AND is_draft = 1');
        $st->execute(['id' => $info['id'], 'c' => $clinicId]);
        $raw = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $quarantine = [];
        $sets = [];
        foreach ($info['fields'] as $f) {
            $quarantine[$f] = ['sha256' => hash('sha256', (string)$raw[$f]), 'length' => strlen((string)$raw[$f]), 'ciphertext' => (string)$raw[$f]];
            $sets[] = "{$f} = NULL"; // nomes vêm da lista fixa ANAMNESIS_ENCRYPTED_FIELDS
        }
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE anamneses SET ' . implode(', ', $sets) . ' WHERE id = :id AND clinic_id = :c AND is_draft = 1')
                ->execute(['id' => $info['id'], 'c' => $clinicId]);
            $this->logAudit($clinicId, $patientId, 'ANAMNESIS_DRAFT_UNREADABLE_DISCARDED', (string)$info['id'], [
                'version' => $info['version'], 'fields' => $info['fields'], 'professional_id' => $professionalId, 'quarantine' => $quarantine,
            ]);
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $info['fields'];
    }

    /**
     * NI2-605: a versão que vale para exibição (a última concluída; sem ela, o rascunho). Para editar, use
     * getLatestAnamnesis, que devolve o rascunho em andamento.
     */
    public function getEffectiveAnamnesis(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        return AnamnesisVersions::effective(AnamnesisVersions::pick($this->db, $clinicId, $patientId, 'a.*, u.name AS professional_name',
            'JOIN professionals p ON p.id = a.professional_id JOIN users u ON u.id = p.user_id'));
    }

    /**
     * Recupera o histórico completo cronológico de todas as versões da anamnese.
     */
    public function getAnamnesisHistory(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name as professional_name
            FROM anamneses a
            JOIN professionals p ON p.id = a.professional_id
            JOIN users u ON u.id = p.user_id
            WHERE a.clinic_id = :clinic_id AND a.patient_id = :patient_id
            ORDER BY a.version DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        return ClinicalEncryptionService::decryptAnamnesisRows($stmt->fetchAll());
    }

    /**
     * Recupera uma versão específica imutável da anamnese.
     */
    public function getAnamnesisVersion(int $patientId, int $version): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name as professional_name
            FROM anamneses a
            JOIN professionals p ON p.id = a.professional_id
            JOIN users u ON u.id = p.user_id
            WHERE a.clinic_id = :clinic_id AND a.patient_id = :patient_id AND a.version = :version
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'version' => $version,
        ]);
        $row = $stmt->fetch();

        return $row ? ClinicalEncryptionService::decryptAnamnesisRow($row) : null;
    }

    /**
     * Compara duas versões da anamnese lado a lado para auditoria e evolução clínica.
     */
    public function compareVersions(int $patientId, int $v1, int $v2): array
    {
        $ver1 = $this->getAnamnesisVersion($patientId, $v1);
        $ver2 = $this->getAnamnesisVersion($patientId, $v2);

        if (!$ver1 || !$ver2) {
            throw new InvalidArgumentException("Uma ou ambas as versões de anamnese não foram encontradas para comparação.");
        }

        $fields = [
            'chief_complaint' => 'Queixa Principal / Motivo da Consulta',
            'objectives' => 'Objetivos & Metas',
            'family_history' => 'Histórico Familiar',
            'pathologies' => 'Patologias Diagnosticadas',
            'has_surgeries' => 'Cirurgias Prévias (Realizou?)',
            'surgeries_details' => 'Detalhes das Cirurgias',
            'medication_usage' => 'Uso de Medicamentos',
            'medications_details' => 'Medicamentos em Uso',
            'supplement_usage' => 'Uso de Suplementos',
            'supplements_details' => 'Suplementos em Uso',
            'allergy_assessment' => 'Avaliação de Alergias',
            'allergies' => 'Alergias Alimentares',
            'intolerance_assessment' => 'Avaliação de Intolerâncias',
            'restrictions' => 'Restrições & Intolerâncias',
            'food_preferences' => 'Preferências & Aversões',
            'habits' => 'Hábitos Alimentares',
            'water_intake_ml' => 'Meta Hídrica (ml)',
            'routine' => 'Rotina Diária & Horários',
            'sleep_hours' => 'Sono (Horas/noite)',
            'sleep_quality' => 'Qualidade do Sono',
            'sleep_notes' => 'Notas sobre o Sono',
            'physical_activity' => 'Atividade Física',
            'activity_modality' => 'Modalidade Esportiva',
            'activity_frequency_weekly' => 'Frequência Semanal (dias)',
            'activity_duration_minutes' => 'Duração da Atividade (min)',
            'alcohol_consumption' => 'Consumo de Álcool',
            'smoking_status' => 'Tabagismo',
            'symptoms' => 'Sinais & Sintomas',
            'referenced_exams' => 'Exames Referenciados',
            'clinical_history' => 'Histórico Clínico & Patologias',
            'notes' => 'Notas & Conduta Inicial',
        ];

        $comparison = [];
        $show = static function (string $key, mixed $v): ?string {
            if ($key === 'allergy_assessment') {
                return ['not_assessed' => 'Não avaliado', 'denied' => 'Nega alergias', 'recorded' => 'Alergia registrada'][(string)$v] ?? 'Não avaliado';
            }
            if (array_key_exists($key, self::ENUM_OPTIONS)) {
                return self::answerLabel($key, $v);
            }
            return $v === null || $v === '' ? null : (string)$v;
        };
        foreach ($fields as $key => $label) {
            $comparison[$key] = [
                'label' => $label,
                // NI2-220: a tela mostra o rótulo em português (ou "Não avaliado"), não o código gravado.
                'v1' => $show($key, $ver1[$key] ?? null),
                'v2' => $show($key, $ver2[$key] ?? null),
                'changed' => ($ver1[$key] ?? null) !== ($ver2[$key] ?? null),
            ];
        }

        return [
            'patient_id' => $patientId,
            'v1_meta' => [
                'version' => $ver1['version'],
                'created_at' => $ver1['created_at'],
                'professional_name' => $ver1['professional_name'],
            ],
            'v2_meta' => [
                'version' => $ver2['version'],
                'created_at' => $ver2['created_at'],
                'professional_name' => $ver2['professional_name'],
            ],
            'diff' => $comparison,
        ];
    }

    /**
     * Resumo clínico para prescrição estruturada (NI2-64).
     * Destaca alergias, restrições e objetivos críticos com origem rastreável.
     * NUNCA infere ou inventa dados não cadastrados.
     */
    public function getClinicalSummaryForPrescription(int $patientId): array
    {
        $summary = $this->baseClinicalSummary($patientId);
        $summary['adaptive_summary'] = (new \ClyoNutri\Domain\ClinicalProtocol\TransversalSummary($this->db))->read(TenantContext::getClinicId(), $patientId);
        return $summary;
    }

    private function baseClinicalSummary(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // NI2-605: vale a última versão CONCLUÍDA; um rascunho mais novo aparece como "alteração em andamento".
        $versions = AnamnesisVersions::pick($this->db, $clinicId, $patientId, 'a.*, u.name AS professional_name',
            'JOIN professionals p ON p.id = a.professional_id JOIN users u ON u.id = p.user_id');
        $latest = AnamnesisVersions::effective($versions);
        $draftInProgress = null;
        if ($versions['concluded'] !== null && $versions['draft'] !== null) {
            $d = $versions['draft'];
            $draftInProgress = [
                'version' => (int)$d['version'],
                'saved_at' => $d['created_at'],
                'label' => AnamnesisVersions::draftLabel($d),
                'url' => "/pacientes/{$patientId}/anamnese",
                'allergies' => trim((string)($d['allergies'] ?? '')) !== '' ? trim((string)$d['allergies']) : null,
            ];
        }

        if (!$latest) {
            $pRec = \ClyoNutri\Domain\Patient\PatientAllergyRecord::read($this->db, $clinicId, $patientId);
            $pAllergies = $pRec['allergies'];
            $pAssessment = $pRec['assessment'];
            $nStmt = $this->db->prepare("SELECT notes FROM patients WHERE clinic_id = :clinic_id AND id = :patient_id");
            $nStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
            $pNotes = trim((string)($nStmt->fetchColumn() ?: ''));

            if ($pAllergies !== '' || $pAssessment === 'denied') {
                $pState = $pAllergies !== '' ? 'recorded' : 'denied';
                $criticalAlerts = [];
                if ($pAllergies !== '') {
                    $criticalAlerts[] = [
                        'type' => 'ALERGIA',
                        'severity' => 'CRITICAL',
                        'description' => $pAllergies,
                        'source_version' => 'Cadastro',
                    ];
                }
                return [
                    'has_data' => true,
                    'allergy_state' => $pState,
                    'allergy_label' => $pState === 'recorded' ? 'Alergia registrada (Origem: Cadastro)' : 'Nega alergias (Origem: Cadastro)',
                    'allergy_pending' => false,
                    'is_draft' => false,
                    'patient_id' => $patientId,
                    'critical_alerts' => $criticalAlerts,
                    'chief_complaint' => null,
                    'allergies' => $pAllergies ?: null,
                    'restrictions' => null,
                    'objectives' => $pNotes ?: null,
                    'pathologies' => null,
                    'family_history' => null,
                    // NI2-220: sem anamnese não há resposta; nada de negativa presumida.
                    'has_surgeries' => null,
                    'surgeries_details' => null,
                    'medication_usage' => null,
                    'medications_details' => null,
                    'supplement_usage' => null,
                    'supplements_details' => null,
                    'physical_activity' => null,
                    'activity_modality' => null,
                    'activity_frequency_weekly' => null,
                    'activity_duration_minutes' => null,
                    'sleep_hours' => null,
                    'sleep_quality' => null,
                    'alcohol_consumption' => null,
                    'smoking_status' => null,
                    'symptoms' => null,
                    'water_intake_ml' => null,
                    'referenced_exams' => null,
                    'notes' => $pNotes ?: null,
                    // NI2-220 (I3): a origem é o cadastro do paciente. Não existe "Anamnese v1" nem data de registro aqui.
                    'source' => [
                        'kind' => 'cadastro',
                        'anamnesis_id' => null,
                        'version' => null,
                        'recorded_at' => null,
                        'author' => null,
                        'label' => 'Cadastro do paciente (ainda sem anamnese registrada)',
                        'url' => "/pacientes/{$patientId}",
                    ],
                ];
            }

            return [
                'has_data' => false,
                'allergy_state' => 'not_assessed',
                'allergy_label' => 'Não avaliado',
                'allergy_pending' => true,
                'is_draft' => false,
                'patient_id' => $patientId,
                'message' => 'Nenhuma anamnese registrada para este paciente. Dados críticos não informados.',
                'critical_alerts' => [],
                'allergies' => null,
                'restrictions' => null,
                'objectives' => null,
                'symptoms' => null,
                'source' => null,
            ];
        }

        if (empty($latest['allergies']) && ($latest['allergy_assessment'] ?? '') !== 'denied') {
            $pAllergies = \ClyoNutri\Domain\Patient\PatientAllergyRecord::read($this->db, $clinicId, $patientId)['allergies'];
            if ($pAllergies !== '') {
                $latest['allergies'] = $pAllergies;
            }
        }

        $source = [
            'kind' => 'anamnese',
            'label' => 'Anamnese v' . (int)$latest['version'],
            'anamnesis_id' => (int)$latest['id'],
            'version' => (int)$latest['version'],
            'recorded_at' => $latest['created_at'],
            'author' => $latest['professional_name'],
            'url' => "/pacientes/{$patientId}/anamnese?version={$latest['version']}",
        ];

        $state = !empty($latest['allergies']) ? 'recorded'
            : (empty($latest['is_draft']) && ($latest['allergy_assessment'] ?? '') === 'denied' ? 'denied' : 'not_assessed');
        // NI2-709: fonte única. A anamnese não avaliou alergias, mas o cadastro registra "Nega alergias":
        // vale a negativa do cadastro (com a origem visível), em vez de "não registradas".
        $allergyFromRegistration = false;
        if ($state === 'not_assessed' && empty($latest['allergies']) && ($latest['allergy_assessment'] ?? '') !== 'recorded'
            && \ClyoNutri\Domain\Patient\PatientAllergyRecord::read($this->db, $clinicId, $patientId)['assessment'] === 'denied') {
            $state = 'denied';
            $allergyFromRegistration = true;
        }
        $criticalAlerts = [];
        if (!empty($latest['allergies'])) {
            $criticalAlerts[] = [
                'type' => 'ALERGIA',
                'severity' => 'CRITICAL',
                'description' => $latest['allergies'],
                'source_version' => $latest['version'],
            ];
        }
        // NI2-605: alergia digitada na alteração em andamento também aparece (e vale para a trava), marcada como não concluída.
        $merged = AnamnesisVersions::mergeForSafety($versions);
        if ($draftInProgress !== null && $merged['draft_added'] && $draftInProgress['allergies'] !== null) {
            $criticalAlerts[] = [
                'type' => 'ALERGIA',
                'severity' => 'CRITICAL',
                'description' => $draftInProgress['allergies'] . ' (informada na alteração em andamento v' . $draftInProgress['version'] . ', ainda não concluída)',
                'source_version' => $draftInProgress['version'],
            ];
            if ($state !== 'recorded') {
                $state = 'recorded';
            }
        }
        if (!empty($latest['restrictions'])) {
            $criticalAlerts[] = [
                'type' => 'RESTRICAO',
                'severity' => 'HIGH',
                'description' => $latest['restrictions'],
                'source_version' => $latest['version'],
            ];
        }

        // NI2-624: alergia de versão anterior (ou do cadastro) que a vigente não traz: alerta de revisão, nunca silêncio
        $historyReview = [];
        try {
            $hs = (new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($this->db))->evaluatePatientAllergyState($patientId);
            $historyReview = $hs['review_findings'] ?? [];
        } catch (\Throwable $e) {
            error_log('NI2-624 summary review: ' . $e->getMessage());
        }
        foreach ($historyReview as $hf) {
            $criticalAlerts[] = [
                'type' => 'ALERGIA_REVISAO',
                'severity' => 'CRITICAL',
                'description' => 'Exige revisão clínica: ' . $hf['message'] . ' A alergia continua valendo na trava até ser confirmada ou descartada.',
                'source_version' => $hf['version'],
            ];
        }
        if ($historyReview && $state !== 'recorded') {
            $state = 'recorded';
        }

        return [
            'allergy_review' => $historyReview,
            'has_data' => true,
            'allergy_state' => $state,
            'allergy_label' => ['not_assessed' => 'Não avaliado', 'denied' => 'Nega alergias', 'recorded' => 'Alergia registrada'][$state] . ($allergyFromRegistration ? ' (Origem: Cadastro)' : ''),
            'allergy_state_source' => $allergyFromRegistration ? 'cadastro' : 'anamnese',
            'allergy_pending' => $state === 'not_assessed' || (!empty($latest['is_draft']) && !$allergyFromRegistration),
            'is_draft' => !empty($latest['is_draft']),
            'draft_in_progress' => $draftInProgress,
            'patient_id' => $patientId,
            'critical_alerts' => $criticalAlerts,
            'chief_complaint' => $latest['chief_complaint'] ?? null,
            'allergies' => self::allergiesWithDraft($latest['allergies'] ?? null, $merged['draft_added'] ? ($draftInProgress['allergies'] ?? null) : null, $draftInProgress),
            'restrictions' => !empty($latest['restrictions']) ? $latest['restrictions'] : null,
            'objectives' => !empty($latest['objectives']) ? $latest['objectives'] : null,
            'pathologies' => $latest['pathologies'] ?? null,
            'family_history' => $latest['family_history'] ?? null,
            'has_surgeries' => $latest['has_surgeries'] ?? null,
            'surgeries_details' => $latest['surgeries_details'] ?? null,
            'medication_usage' => $latest['medication_usage'] ?? null,
            'medications_details' => $latest['medications_details'] ?? null,
            'supplement_usage' => $latest['supplement_usage'] ?? null,
            'supplements_details' => $latest['supplements_details'] ?? null,
            'physical_activity' => $latest['physical_activity'] ?? null,
            'activity_modality' => $latest['activity_modality'] ?? null,
            'activity_frequency_weekly' => isset($latest['activity_frequency_weekly']) ? (int)$latest['activity_frequency_weekly'] : null,
            'activity_duration_minutes' => isset($latest['activity_duration_minutes']) ? (int)$latest['activity_duration_minutes'] : null,
            'sleep_hours' => isset($latest['sleep_hours']) ? (float)$latest['sleep_hours'] : null,
            'sleep_quality' => $latest['sleep_quality'] ?? null,
            'alcohol_consumption' => $latest['alcohol_consumption'] ?? null,
            'smoking_status' => $latest['smoking_status'] ?? null,
            'symptoms' => !empty($latest['symptoms']) ? $latest['symptoms'] : null,
            'water_intake_ml' => $latest['water_intake_ml'] ? (int)$latest['water_intake_ml'] : null,
            'referenced_exams' => $latest['referenced_exams'] ?? null,
            'notes' => $latest['notes'] ?? null,
            'source' => $source,
        ];
    }

    /** NI2-605: alergias da versão concluída + a informada na alteração em andamento (marcada). */
    private static function allergiesWithDraft(?string $concluded, ?string $draft, ?array $draftInfo): ?string
    {
        $concluded = trim((string)$concluded);
        $draft = trim((string)$draft);
        if ($draft === '' || $draftInfo === null) {
            return $concluded !== '' ? $concluded : null;
        }
        $tag = $draft . ' (alteração em andamento v' . (int)$draftInfo['version'] . ')';
        return $concluded !== '' ? $concluded . '; ' . $tag : $tag;
    }

    private function hasColumn(string $column): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $st = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $st->execute(['anamneses', $column]);
                return (int)$st->fetchColumn() > 0;
            }
            foreach ($this->db->query('PRAGMA table_info(anamneses)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    private function hasStructuredColumns(): bool
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $st = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $st->execute(['anamneses', 'chief_complaint']);
                return (int)$st->fetchColumn() > 0;
            }
            foreach ($this->db->query('PRAGMA table_info(anamneses)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === 'chief_complaint') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    private function sanitizeText(?string $text, string $label): ?string
    {
        if ($text === null) {
            return null;
        }
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }
        // Limitar tamanho para prevenir abusos / DoS
        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_FIELD_LENGTH) {
            throw new InvalidArgumentException($label . ' excede o limite de ' . self::MAX_FIELD_LENGTH . ' caracteres. O preenchimento não foi alterado.');
        }
        return $trimmed;
    }

    private function logAudit(int $clinicId, int $patientId, string $action, string $resourceId, array $meta): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details)
            VALUES (:clinic_id, :user_id, :action, 'anamneses', :res_id, :details)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'user_id' => TenantContext::getUserId(),
            'action' => $action,
            'res_id' => $resourceId,
            'details' => json_encode(array_merge([
                'patient_id' => $patientId,
                'timestamp' => date('c'),
            ], $meta), JSON_UNESCAPED_UNICODE),
        ]);
    }
}
