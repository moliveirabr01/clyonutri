<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anamnesis;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;

class AnamnesisService
{
    private PDO $db;

    public const MAX_FIELD_LENGTH = 5000;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Salva uma anamnese estruturada (finalizada ou rascunho parcial).
     * Sempre cria uma nova versão sequencial imutável (append-only).
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

        // 1. Sanitização e normalização de seções clínicas
        $cleanData = [
            'objectives' => $this->sanitizeText($data['objectives'] ?? null, 'Objetivos'),
            'routine' => $this->sanitizeText($data['routine'] ?? null, 'Rotina'),
            'food_preferences' => $this->sanitizeText($data['food_preferences'] ?? null, 'Preferências'),
            'restrictions' => $this->sanitizeText($data['restrictions'] ?? null, 'Restrições'),
            'allergies' => $this->sanitizeText($data['allergies'] ?? null, 'Alergias'),
            'symptoms' => $this->sanitizeText($data['symptoms'] ?? null, 'Sintomas'),
            'habits' => $this->sanitizeText($data['habits'] ?? null, 'Hábitos'),
            'clinical_history' => $this->sanitizeText($data['clinical_history'] ?? null, 'Histórico clínico'),
            'water_intake_ml' => isset($data['water_intake_ml']) && $data['water_intake_ml'] !== '' ? max(0, min(20000, (int)$data['water_intake_ml'])) : null,
            'notes' => $this->sanitizeText($data['notes'] ?? null, 'Observações'),
        ];

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
        if ($assessment === 'not_assessed' && !empty($cleanData['allergies'])) $assessment = 'recorded';

        // 2. Validação fail-closed se não for rascunho
        if (!$isDraft) {
            $hasAnyContent = false;
            foreach ($cleanData as $key => $val) {
                if ($val !== null && $val !== '') {
                    $hasAnyContent = true;
                    break;
                }
            }
            if (!$hasAnyContent && $assessment !== 'denied') {
                throw new InvalidArgumentException("A anamnese finalizada exige o preenchimento de ao menos uma seção clínica.");
            }
        }

        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = 'anamnesis_' . bin2hex(random_bytes(8));
        if ($ownsTransaction) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT ' . $savepoint);
        try {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $lock = $this->db->prepare('SELECT id FROM patients WHERE id = ? AND clinic_id = ? FOR UPDATE');
            $lock->execute([$patientId, $clinicId]);
        }
        // 3. Obter versão mais recente para incrementar
        $vStmt = $this->db->prepare("
            SELECT COALESCE(MAX(version), 0) + 1 FROM anamneses
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ");
        $vStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $nextVersion = (int)$vStmt->fetchColumn();

        // 4. Inserção append-only
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

        $createdAt = date('Y-m-d H:i:s');
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

        $id = (int)$this->db->lastInsertId();

        // 5. Auditoria sem PII
        $this->logAudit($clinicId, $patientId, $isDraft ? 'ANAMNESIS_DRAFT_SAVED' : 'ANAMNESIS_RECORD_CREATED', (string)$id, [
            'version' => $nextVersion,
            'is_draft' => $isDraft,
            'has_allergies' => !empty($cleanData['allergies']),
            'allergy_assessment' => $assessment,
            'has_restrictions' => !empty($cleanData['restrictions']),
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

        return $row ?: null;
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
        return $stmt->fetchAll();
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

        return $row ?: null;
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
            'objectives' => 'Objetivos & Metas',
            'routine' => 'Rotina Diária & Sono',
            'food_preferences' => 'Preferências Alimentares',
            'restrictions' => 'Restrições & Intolerâncias',
            'allergies' => 'Alergias Alimentares',
            'symptoms' => 'Sinais & Sintomas',
            'habits' => 'Hábitos Alimentares & Vida',
            'clinical_history' => 'Histórico Clínico & Patologias',
            'water_intake_ml' => 'Meta Hídrica (ml)',
        ];

        $comparison = [];
        foreach ($fields as $key => $label) {
            $comparison[$key] = [
                'label' => $label,
                'v1' => $ver1[$key] ?? null,
                'v2' => $ver2[$key] ?? null,
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
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $latest = $this->getLatestAnamnesis($patientId);

        if (!$latest) {
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

        $source = [
            'anamnesis_id' => (int)$latest['id'],
            'version' => (int)$latest['version'],
            'recorded_at' => $latest['created_at'],
            'author' => $latest['professional_name'],
            'url' => "/pacientes/{$patientId}/anamnese?version={$latest['version']}",
        ];

        $state = !empty($latest['allergies']) ? 'recorded'
            : (empty($latest['is_draft']) && ($latest['allergy_assessment'] ?? '') === 'denied' ? 'denied' : 'not_assessed');
        $criticalAlerts = [];
        if (!empty($latest['allergies'])) {
            $criticalAlerts[] = [
                'type' => 'ALERGIA',
                'severity' => 'CRITICAL',
                'description' => $latest['allergies'],
                'source_version' => $latest['version'],
            ];
        }
        if (!empty($latest['restrictions'])) {
            $criticalAlerts[] = [
                'type' => 'RESTRICAO',
                'severity' => 'HIGH',
                'description' => $latest['restrictions'],
                'source_version' => $latest['version'],
            ];
        }

        return [
            'has_data' => true,
            'allergy_state' => $state,
            'allergy_label' => ['not_assessed' => 'Não avaliado', 'denied' => 'Nega alergias', 'recorded' => 'Alergia registrada'][$state],
            'allergy_pending' => $state === 'not_assessed' || !empty($latest['is_draft']),
            'is_draft' => !empty($latest['is_draft']),
            'patient_id' => $patientId,
            'critical_alerts' => $criticalAlerts,
            'allergies' => !empty($latest['allergies']) ? $latest['allergies'] : null,
            'restrictions' => !empty($latest['restrictions']) ? $latest['restrictions'] : null,
            'objectives' => !empty($latest['objectives']) ? $latest['objectives'] : null,
            'symptoms' => !empty($latest['symptoms']) ? $latest['symptoms'] : null,
            'water_intake_ml' => $latest['water_intake_ml'] ? (int)$latest['water_intake_ml'] : null,
            'source' => $source,
        ];
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
