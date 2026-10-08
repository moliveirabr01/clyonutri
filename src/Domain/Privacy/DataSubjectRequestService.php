<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Privacy;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;

class DataSubjectRequestService
{
    private PDO $db;
    private LgpdService $lgpdService;

    public const LEGAL_HOLD_NOTICE = "Dados cadastrais e canais de contato foram anonimizados com sucesso. O prontuário clínico e as prescrições foram descaracterizados e preservados sob guarda legal obrigatória mínima de 20 anos, em estrito cumprimento ao Art. 6º da Lei Federal nº 13.787/2018 e normativas do Conselho Federal de Nutricionistas (CFN), sendo vedada a destruição física antes do término do prazo legal.";

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->lgpdService = new LgpdService($this->db);
    }

    /**
     * Registra uma solicitação de titular de dados (Art. 18 da LGPD).
     * Prazo legal padrão da LGPD: 15 dias.
     */
    public function openRequest(int $patientId, string $requestType, ?string $details = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $validTypes = ['access', 'export', 'rectification', 'anonymization', 'deletion'];
        if (!in_array($requestType, $validTypes, true)) {
            throw new InvalidArgumentException("Tipo de requisição LGPD inválido: '{$requestType}'.");
        }

        $deadline = date('Y-m-d H:i:s', strtotime('+15 days'));

        $stmt = $this->db->prepare("
            INSERT INTO data_subject_requests (clinic_id, patient_id, request_type, status, details, deadline_at)
            VALUES (:clinic_id, :patient_id, :type, 'pending', :details, :deadline)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'type' => $requestType,
            'details' => $details,
            'deadline' => $deadline,
        ]);

        $id = (int)$this->db->lastInsertId();

        $aStmt = $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
            VALUES (:clinic_id, 'DATA_SUBJECT_REQUEST_OPENED', 'data_subject_requests', :res_id, :details)
        ");
        $aStmt->execute([
            'clinic_id' => $clinicId,
            'res_id' => (string)$id,
            'details' => json_encode([
                'patient_id' => $patientId,
                'type' => $requestType,
                'deadline_at' => $deadline,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'id' => $id,
            'patient_id' => $patientId,
            'request_type' => $requestType,
            'status' => 'pending',
            'deadline_at' => $deadline,
        ];
    }

    /**
     * Processa solicitação de exportação/portabilidade de dados.
     */
    public function processExport(int $requestId, ?int $reviewerId = null): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("SELECT * FROM data_subject_requests WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $requestId, 'clinic_id' => $clinicId]);
        $request = $stmt->fetch();

        if (!$request) {
            throw new InvalidArgumentException("Solicitação LGPD não encontrada.");
        }

        $patientId = (int)$request['patient_id'];
        $exportData = $this->lgpdService->exportPatientData($patientId);

        $now = date('Y-m-d H:i:s');
        $upd = $this->db->prepare("
            UPDATE data_subject_requests
            SET status = 'completed', reviewed_by = :reviewer, completed_at = :now, response_notes = 'Dados exportados com sucesso.'
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $upd->execute([
            'reviewer' => $reviewerId,
            'now' => $now,
            'id' => $requestId,
            'clinic_id' => $clinicId,
        ]);

        return [
            'request_id' => $requestId,
            'status' => 'completed',
            'export_package' => $exportData,
            'checksum_sha256' => hash('sha256', json_encode($exportData, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Processa solicitação de eliminação / anonimização aplicando o Legal Hold de Prontuário.
     * NUNCA executa exclusão física em cascata de dados clínicos (preservação obrigatória de 20 anos).
     */
    public function processDeletionWithLegalHold(int $requestId, int $reviewerId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($reviewerId, $clinicId, $this->db);

        $stmt = $this->db->prepare("SELECT * FROM data_subject_requests WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $requestId, 'clinic_id' => $clinicId]);
        $request = $stmt->fetch();

        if (!$request) {
            throw new InvalidArgumentException("Solicitação LGPD não encontrada.");
        }

        $patientId = (int)$request['patient_id'];

        $this->db->beginTransaction();
        try {
            // 1. Anonimizar dados pessoais cadastrais (nome, email, telefone)
            $this->lgpdService->anonymizePatient($patientId);

            // 2. Verificar se há registros clínicos sob guarda legal
            $cStmt = $this->db->prepare("
                SELECT COUNT(*) FROM clinical_records 
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ");
            $cStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
            $hasClinicalRecords = ((int)$cStmt->fetchColumn()) > 0;

            $now = date('Y-m-d H:i:s');
            $status = $hasClinicalRecords ? 'rejected_legal_hold' : 'completed';
            $justification = $hasClinicalRecords ? self::LEGAL_HOLD_NOTICE : "Dados excluídos e anonimizados integralmente.";

            $upd = $this->db->prepare("
                UPDATE data_subject_requests
                SET status = :status,
                    reviewed_by = :reviewer,
                    legal_justification = :justification,
                    response_notes = :justification,
                    completed_at = :now
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $upd->execute([
                'status' => $status,
                'reviewer' => $reviewerId,
                'justification' => $justification,
                'now' => $now,
                'id' => $requestId,
                'clinic_id' => $clinicId,
            ]);

            // Auditoria da ação
            $aStmt = $this->db->prepare("
                INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
                VALUES (:clinic_id, 'DATA_SUBJECT_DELETION_PROCESSED', 'data_subject_requests', :res_id, :details)
            ");
            $aStmt->execute([
                'clinic_id' => $clinicId,
                'res_id' => (string)$requestId,
                'details' => json_encode([
                    'patient_id' => $patientId,
                    'status' => $status,
                    'has_clinical_records' => $hasClinicalRecords,
                    'legal_hold_applied' => $hasClinicalRecords,
                    'timestamp' => $now,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->db->commit();

            return [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'status' => $status,
                'legal_hold_applied' => $hasClinicalRecords,
                'justification' => $justification,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
