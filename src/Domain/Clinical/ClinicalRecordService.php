<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class ClinicalRecordService
{
    private PDO $db;
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Adiciona um registro clínico longitudinal append-only com encadeamento criptográfico.
     * NUNCA executa UPDATE ou DELETE no histórico clínico.
     */
    public function appendRecord(int $patientId, int $professionalId, string $title, array $content): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        if (mb_strlen(trim($title), 'UTF-8') > 255) throw new InvalidArgumentException('O título deve ter até 255 caracteres.');
        if (empty(trim($title))) {
            throw new InvalidArgumentException("O título do registro clínico é obrigatório.");
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->lockPatient($patientId, $clinicId);
            // 1. Obter a última versão e o último hash do paciente de forma atômica
            $lastStmt = $this->db->prepare("
                SELECT version, record_hash 
                FROM clinical_records 
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id
                ORDER BY version DESC 
                LIMIT 1
            ");
            $lastStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
            ]);
            $lastRecord = $lastStmt->fetch();

            $nextVersion = $lastRecord ? ((int)$lastRecord['version'] + 1) : 1;
            $previousHash = $lastRecord ? (string)$lastRecord['record_hash'] : self::GENESIS_HASH;

            $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $createdAt = date('Y-m-d H:i:s');

            // 2. Computar o hash SHA-256 canônico do registro
            $recordHash = $this->computeHash($clinicId, $patientId, $professionalId, $nextVersion, null, $contentJson, $previousHash, $createdAt, trim($title), null, 2);

            // 3. Inserir append-only
            $stmt = $this->db->prepare("
                INSERT INTO clinical_records (
                    clinic_id, patient_id, professional_id, version,
                    supersedes_id, revision_reason, title, content_json,
                    record_hash, previous_hash, is_retracted, created_at, hash_version
                ) VALUES (
                    :clinic_id, :patient_id, :professional_id, :version,
                    NULL, NULL, :title, :content_json,
                    :record_hash, :previous_hash, 0, :created_at, 2
                )
            ");

            $stmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'version' => $nextVersion,
                'title' => trim($title),
                'content_json' => $contentJson,
                'record_hash' => $recordHash,
                'previous_hash' => $previousHash,
                'created_at' => $createdAt,
            ]);

            $id = (int)$this->db->lastInsertId();

            // 4. Registrar na auditoria sem PII
            $this->logClinicalAudit($clinicId, $patientId, 'CLINICAL_RECORD_APPENDED', (string)$id, [
                'version' => $nextVersion,
                'hash' => $recordHash,
            ]);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'id' => $id,
                'version' => $nextVersion,
                'record_hash' => $recordHash,
                'previous_hash' => $previousHash,
                'title' => $title,
                'created_at' => $createdAt,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retifica um registro clínico criando uma NOVA versão versionada (append-only).
     * O registro original NUNCA é deletado ou alterado em seu conteúdo clínico,
     * apenas marcado como superado (is_retracted = 1) com justificativa formal registrada.
     */
    public function reviseRecord(int $targetRecordId, int $patientId, int $professionalId, string $reason, array $newContent): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $trimmedReason = trim($reason);
        if (mb_strlen($trimmedReason, 'UTF-8') < 10) {
            throw new InvalidArgumentException("A justificativa clínica da retificação deve conter no mínimo 10 caracteres explicativos.");
        }

        $this->validateRevisionContent($newContent);
        if (mb_strlen($trimmedReason, 'UTF-8') > self::MAX_NOTE_LENGTH) throw new InvalidArgumentException('A justificativa deve ter até ' . self::MAX_NOTE_LENGTH . ' caracteres.');
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = 'clinical_revision_' . bin2hex(random_bytes(8));
        if ($ownsTransaction) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT ' . $savepoint);
        try {
            $this->lockPatient($patientId, $clinicId);
            // Verificar registro alvo a ser retificado
            $targetStmt = $this->db->prepare("
                SELECT id, title, version, is_retracted 
                FROM clinical_records 
                WHERE id = :id AND clinic_id = :clinic_id AND patient_id = :patient_id
            ");
            $targetStmt->execute([
                'id' => $targetRecordId,
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
            ]);
            $target = $targetStmt->fetch();

            if (!$target) {
                throw new InvalidArgumentException("Registro clínico ID {$targetRecordId} não encontrado no histórico deste paciente.");
            }

            // Marcar o anterior como superado (retração administrativa, sem alterar conteúdo histórico)
            $retractStmt = $this->db->prepare("
                UPDATE clinical_records 
                SET is_retracted = 1 
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $retractStmt->execute(['id' => $targetRecordId, 'clinic_id' => $clinicId]);

            // Obter a versão mais recente e hash anterior
            $lastStmt = $this->db->prepare("
                SELECT version, record_hash 
                FROM clinical_records 
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id
                ORDER BY version DESC 
                LIMIT 1
            ");
            $lastStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
            ]);
            $lastRecord = $lastStmt->fetch();

            $nextVersion = ((int)$lastRecord['version']) + 1;
            $previousHash = (string)$lastRecord['record_hash'];

            $contentJson = json_encode($newContent, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $createdAt = date('Y-m-d H:i:s');
            $newTitle = $target['title'];
            if (mb_strlen($newTitle, 'UTF-8') > 255) throw new InvalidArgumentException('O título legado excede o limite permitido para a retificação.');

            $recordHash = $this->computeHash($clinicId, $patientId, $professionalId, $nextVersion, $targetRecordId, $contentJson, $previousHash, $createdAt, $newTitle, $trimmedReason, 2);

            // Inserir a nova versão retificada
            $stmt = $this->db->prepare("
                INSERT INTO clinical_records (
                    clinic_id, patient_id, professional_id, version,
                    supersedes_id, revision_reason, title, content_json,
                    record_hash, previous_hash, is_retracted, created_at, hash_version
                ) VALUES (
                    :clinic_id, :patient_id, :professional_id, :version,
                    :supersedes_id, :revision_reason, :title, :content_json,
                    :record_hash, :previous_hash, 0, :created_at, 2
                )
            ");

            $stmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'version' => $nextVersion,
                'supersedes_id' => $targetRecordId,
                'revision_reason' => $trimmedReason,
                'title' => $newTitle,
                'content_json' => $contentJson,
                'record_hash' => $recordHash,
                'previous_hash' => $previousHash,
                'created_at' => $createdAt,
            ]);

            $newId = (int)$this->db->lastInsertId();

            $this->logClinicalAudit($clinicId, $patientId, 'CLINICAL_RECORD_REVISED', (string)$newId, [
                'supersedes_id' => $targetRecordId,
                'version' => $nextVersion,
                'hash' => $recordHash,
            ]);

            if ($ownsTransaction) $this->db->commit();
            else $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);

            return [
                'id' => $newId,
                'version' => $nextVersion,
                'supersedes_id' => $targetRecordId,
                'revision_reason' => $trimmedReason,
                'record_hash' => $recordHash,
                'previous_hash' => $previousHash,
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
     * Verifica a integridade matemática da cadeia criptográfica de prontuário do paciente.
     * Detecta adulterações manuais diretas no banco de dados.
     */
    public function verifyIntegrity(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT * FROM clinical_records 
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ORDER BY version ASC
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
        ]);
        $records = $stmt->fetchAll();

        if (empty($records)) {
            return [
                'is_valid' => true,
                'total_records' => 0,
                'message' => 'Nenhum registro clínico cadastrado para o paciente.',
            ];
        }

        $expectedPreviousHash = self::GENESIS_HASH;
        $legacyRecords = 0;
        $extendedFormatSeen = false;

        foreach ($records as $index => $rec) {
            $recId = (int)$rec['id'];
            $version = (int)$rec['version'];
            $supersedesId = $rec['supersedes_id'] !== null ? (int)$rec['supersedes_id'] : null;

            $hashVersion = (int)$rec['hash_version'];
            if (!in_array($hashVersion, [1,2], true) || ($extendedFormatSeen && $hashVersion === 1)) {
                return ['is_valid'=>false, 'tampered_record_id'=>$recId, 'version'=>$version, 'error'=>'Formato de integridade inválido.'];
            }
            if ($hashVersion === 1) $legacyRecords++;
            else $extendedFormatSeen = true;
            // 1. Validar encadeamento do previous_hash
            if ($rec['previous_hash'] !== $expectedPreviousHash) {
                return [
                    'is_valid' => false,
                    'tampered_record_id' => $recId,
                    'version' => $version,
                    'error' => 'Cadeia de integridade corrompida: previous_hash inconsistente com o registro anterior.',
                ];
            }

            // 2. Recalcular e comparar o record_hash com o conteúdo persistido
            $recalculated = $this->computeHash(
                (int)$rec['clinic_id'],
                (int)$rec['patient_id'],
                (int)$rec['professional_id'],
                $version,
                $supersedesId,
                $rec['content_json'],
                $rec['previous_hash'],
                $rec['created_at'],
                $rec['title'],
                $rec['revision_reason'],
                $hashVersion
            );

            if ($recalculated !== $rec['record_hash']) {
                return [
                    'is_valid' => false,
                    'tampered_record_id' => $recId,
                    'version' => $version,
                    'error' => 'Adulteração direta de conteúdo detectada: hash criptográfico não confere com o conteúdo do banco.',
                ];
            }

            $expectedPreviousHash = $rec['record_hash'];
        }

        return [
            'is_valid' => true,
            'total_records' => count($records),
            'latest_hash' => $expectedPreviousHash,
            'legacy_records' => $legacyRecords,
            'metadata_coverage_complete' => $legacyRecords === 0,
            'message' => $legacyRecords ? 'Conteúdo e encadeamento verificados. Registros legados não incluem título e justificativa na verificação.' : 'Conteúdo, título e justificativa verificados.',
        ];
    }

    /**
     * Recupera o histórico completo cronológico de registros do paciente
     */
    public function getPatientTimeline(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT r.id, r.version, r.supersedes_id, r.revision_reason, r.title,
                   r.content_json, r.record_hash, r.is_retracted, r.created_at,
                   u.name as professional_name
            FROM clinical_records r
            JOIN professionals p ON p.id = r.professional_id
            JOIN users u ON u.id = p.user_id
            WHERE r.clinic_id = :clinic_id AND r.patient_id = :patient_id
            ORDER BY r.version ASC
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
        ]);

        return array_map(function ($row) {
            $row['content'] = json_decode($row['content_json'], true);
            unset($row['content_json']);
            return $row;
        }, $stmt->fetchAll());
    }

    public const MAX_NOTE_LENGTH = 10000;

    /**
     * Registra uma observação clínica segura com sanitização, limite de caracteres e auditoria (NI2-63).
     */
    public function addClinicalObservation(int $patientId, int $professionalId, string $title, string $observationText, string $category = 'observacao'): array
    {
        $cleanTitle = trim($title);
        if ($cleanTitle === '') {
            throw new InvalidArgumentException("O título da observação clínica é obrigatório.");
        }
        if (mb_strlen($cleanTitle, 'UTF-8') > 255) throw new InvalidArgumentException('O título deve ter até 255 caracteres.');

        $cleanText = trim($observationText);
        if ($cleanText === '') {
            throw new InvalidArgumentException("O texto da anotação clínica não pode ser vazio.");
        }
        if (mb_strlen($cleanText, 'UTF-8') > self::MAX_NOTE_LENGTH) {
            throw new InvalidArgumentException("A observação clínica excede o limite máximo permitido de " . self::MAX_NOTE_LENGTH . " caracteres.");
        }

        $content = [
            'type' => 'clinical_note',
            'category' => $category,
            'text' => $cleanText,
            'recorded_by' => $professionalId,
        ];

        return $this->appendRecord($patientId, $professionalId, $cleanTitle, $content);
    }

    /**
     * Exportação segura da linha do tempo clínica com validação de integridade e controle de acesso (NI2-63).
     */
    public function exportRecordTimelineSafe(int $patientId, int $requestingProfessionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($requestingProfessionalId, $clinicId, $this->db);

        // 1. Verificação de integridade obrigatória antes da exportação
        $integrity = $this->verifyIntegrity($patientId);
        if (!$integrity['is_valid']) {
            throw new RuntimeException("Exportação bloqueada: " . ($integrity['error'] ?? 'Falha de integridade criptográfica no prontuário.'));
        }

        // 2. Timeline cronológica
        $timeline = $this->getPatientTimeline($patientId);

        // 3. Auditoria do evento de exportação
        $this->logClinicalAudit($clinicId, $patientId, 'CLINICAL_TIMELINE_EXPORTED', (string)$patientId, [
            'records_count' => count($timeline),
            'integrity_valid' => true,
            'exported_by_professional_id' => $requestingProfessionalId,
        ]);

        return [
            'patient_id' => $patientId,
            'exported_at' => date('c'),
            'integrity' => $integrity,
            'records' => $timeline,
        ];
    }

    private function validateRevisionContent(array $content): void
    {
        if (array_key_exists('text', $content) && (!is_string($content['text']) || trim($content['text']) === '')) {
            throw new InvalidArgumentException('O texto retificado é obrigatório.');
        }
        $hasContent = false;
        array_walk_recursive($content, static function ($value, $key) use (&$hasContent): void {
            if (in_array($key, ['type','category','recorded_by','revised_by'], true)) return;
            if (is_string($value)) {
                if (mb_strlen(trim($value), 'UTF-8') > self::MAX_NOTE_LENGTH) throw new InvalidArgumentException('O conteúdo retificado deve ter até ' . self::MAX_NOTE_LENGTH . ' caracteres por campo.');
                if (trim($value) !== '') $hasContent = true;
            } elseif (is_int($value) || is_float($value)) $hasContent = true;
        });
        if (!$hasContent) throw new InvalidArgumentException('O conteúdo retificado é obrigatório.');
    }

    private function lockPatient(int $patientId, int $clinicId): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->db->prepare('SELECT id FROM patients WHERE id = ? AND clinic_id = ? FOR UPDATE');
            $stmt->execute([$patientId, $clinicId]);
        }
    }

    private function computeHash(
        int $clinicId,
        int $patientId,
        int $professionalId,
        int $version,
        ?int $supersedesId,
        string $contentJson,
        string $previousHash,
        string $createdAt,
        string $title = '',
        ?string $revisionReason = null,
        int $hashVersion = 1
    ): string {
        if ($hashVersion === 2) {
            return hash('sha256', json_encode([2, $clinicId, $patientId, $professionalId, $version, $supersedesId,
                $contentJson, $previousHash, $createdAt, $title, $revisionReason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        $dataToHash = implode('|', [
            $clinicId,
            $patientId,
            $professionalId,
            $version,
            $supersedesId ?? 'null',
            $contentJson,
            $previousHash,
            $createdAt,
        ]);

        return hash('sha256', $dataToHash);
    }

    private function logClinicalAudit(int $clinicId, int $patientId, string $action, string $resourceId, array $meta): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
            VALUES (:clinic_id, :action, 'clinical_records', :res_id, :details)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'action' => $action,
            'res_id' => $resourceId,
            'details' => json_encode(array_merge([
                'patient_id' => $patientId,
                'timestamp' => date('c'),
            ], $meta), JSON_UNESCAPED_UNICODE),
        ]);
    }
}
