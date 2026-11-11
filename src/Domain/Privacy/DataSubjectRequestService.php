<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Privacy;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use InvalidArgumentException;
use PDO;

class DataSubjectRequestService
{
    private PDO $db;
    private LgpdService $lgpdService;
    private AuditLogger $audit;

    public const LEGAL_HOLD_NOTICE = "Dados cadastrais e canais de contato foram anonimizados. O prontuário clínico e as prescrições foram descaracterizados e preservados sob guarda legal obrigatória mínima de 20 anos, em estrito cumprimento ao Art. 6º da Lei Federal nº 13.787/2018 e normativas do Conselho Federal de Nutricionistas (CFN), sendo vedada a destruição física antes do término do prazo legal.";

    public const CHANNEL_PROFESSIONAL = 'professional';
    public const CHANNEL_PATIENT_APP = 'patient_app';
    public const OPEN_STATUSES = ['pending', 'in_review'];
    /** Tabelas que compõem o prontuário sob guarda legal (Lei 13.787/2018). */
    private const CLINICAL_HOLD_TABLES = ['clinical_records', 'anamneses', 'anthropometric_assessments', 'meal_plans', 'nutrition_records', 'skinfold_measurements'];
    public const DEADLINE_DAYS = 15;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->lgpdService = new LgpdService($this->db);
        $this->audit = new AuditLogger($this->db);
    }

    /**
     * Registra uma solicitação de titular de dados (Art. 18 da LGPD). Prazo padrão: 15 dias.
     * Um pedido igual ainda em aberto não é duplicado (devolve o existente com already_open = true).
     */
    public function openRequest(int $patientId, string $requestType, ?string $details = null, string $channel = self::CHANNEL_PROFESSIONAL): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $validTypes = ['access', 'export', 'rectification', 'anonymization', 'deletion'];
        if (!in_array($requestType, $validTypes, true)) {
            throw new InvalidArgumentException("Tipo de requisição LGPD inválido: '{$requestType}'.");
        }
        if (!in_array($channel, [self::CHANNEL_PROFESSIONAL, self::CHANNEL_PATIENT_APP], true)) {
            throw new InvalidArgumentException('Canal de solicitação inválido.');
        }
        $details = $details !== null ? mb_substr(trim($details), 0, 1000) : null;

        $existing = $this->db->prepare("SELECT id, status, deadline_at, created_at FROM data_subject_requests
            WHERE clinic_id = ? AND patient_id = ? AND request_type = ? AND status IN ('pending','in_review') ORDER BY id LIMIT 1");
        $existing->execute([$clinicId, $patientId, $requestType]);
        if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
            return ['id' => (int)$row['id'], 'patient_id' => $patientId, 'request_type' => $requestType, 'status' => $row['status'],
                'deadline_at' => $row['deadline_at'], 'created_at' => $row['created_at'], 'already_open' => true];
        }

        // Prazo no fuso da aplicação (B2 do code review NI2-91).
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $deadline = $now->modify('+' . self::DEADLINE_DAYS . ' days')->format('Y-m-d H:i:s');
        $prefix = $channel === self::CHANNEL_PATIENT_APP ? 'Pedido feito pela paciente no app.' : 'Pedido registrado pelo profissional.';
        $text = trim($prefix . ($details ? ' ' . $details : ''));

        $stmt = $this->db->prepare("
            INSERT INTO data_subject_requests (clinic_id, patient_id, request_type, status, details, deadline_at, created_at)
            VALUES (:clinic_id, :patient_id, :type, 'pending', :details, :deadline, :created_at)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'type' => $requestType,
            'details' => $text,
            'deadline' => $deadline,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $id = (int)$this->db->lastInsertId();

        $this->audit->log('DATA_SUBJECT_REQUEST_OPENED', 'data_subject_requests', (string)$id, [
            'patient_id' => $patientId, 'type' => $requestType, 'channel' => $channel, 'deadline_at' => $deadline,
        ]);

        return ['id' => $id, 'patient_id' => $patientId, 'request_type' => $requestType, 'status' => 'pending',
            'deadline_at' => $deadline, 'created_at' => $now->format('Y-m-d H:i:s'), 'already_open' => false];
    }

    /** Pedidos da clínica, abertos primeiro (por prazo), com nome do paciente e indicação de atraso. */
    public function listForClinic(int $limit = 200): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("SELECT r.*, p.name AS patient_name FROM data_subject_requests r
            JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
            WHERE r.clinic_id = ?
            ORDER BY CASE WHEN r.status IN ('pending','in_review') THEN 0 ELSE 1 END, r.deadline_at ASC, r.id DESC
            LIMIT " . max(1, min(500, $limit)));
        $stmt->execute([$clinicId]);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s');
        return array_map(function (array $r) use ($now) {
            $r['is_open'] = in_array($r['status'], self::OPEN_STATUSES, true);
            $r['is_overdue'] = $r['is_open'] && (string)$r['deadline_at'] < $now;
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Pedidos da própria paciente (para o app). */
    public function listForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $stmt = $this->db->prepare("SELECT id, request_type, status, deadline_at, completed_at, response_notes, created_at
            FROM data_subject_requests WHERE clinic_id = ? AND patient_id = ? ORDER BY id DESC");
        $stmt->execute([$clinicId, $patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Dono ou administrador ativo da clínica (quem responde pelos pedidos de titulares). */
    public function assertCanProcess(int $userId): void
    {
        if (!$this->memberHasRole($userId, ['owner', 'admin'])) {
            throw new TenantSecurityException('Somente a gestão do consultório pode processar pedidos de titulares.');
        }
    }

    private function memberHasRole(int $userId, array $roles): bool
    {
        $in = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->db->prepare("SELECT 1 FROM clinic_members cm JOIN users u ON u.id = cm.user_id AND u.status = 'active'
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' AND cm.role IN ($in)");
        $stmt->execute(array_merge([TenantContext::getClinicId(), $userId], $roles));
        return (bool)$stmt->fetchColumn();
    }

    private function findOpenRequest(int $requestId, array $types): array
    {
        $stmt = $this->db->prepare("SELECT * FROM data_subject_requests WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $requestId, 'clinic_id' => TenantContext::getClinicId()]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !in_array($request['request_type'], $types, true)) {
            throw new InvalidArgumentException('Solicitação LGPD não encontrada.');
        }
        if (!in_array($request['status'], self::OPEN_STATUSES, true)) {
            throw new InvalidArgumentException('Esta solicitação já foi concluída.');
        }
        return $request;
    }

    /**
     * Processa solicitação de exportação/portabilidade. Revisor: membro clínico ativo (dono, admin ou nutricionista).
     */
    public function processExport(int $requestId, ?int $reviewerId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $reviewerId = $reviewerId ?? (int)TenantContext::getUserId();
        if (!$this->memberHasRole($reviewerId, ['owner', 'admin', 'nutritionist'])) {
            throw new TenantSecurityException('Sem permissão para processar exportação de dados.');
        }
        $request = $this->findOpenRequest($requestId, ['export', 'access']);

        $patientId = (int)$request['patient_id'];
        $exportData = $this->lgpdService->exportPatientData($patientId);
        $checksum = hash('sha256', json_encode($exportData, JSON_THROW_ON_ERROR));

        $upd = $this->db->prepare("
            UPDATE data_subject_requests
            SET status = 'completed', reviewed_by = :reviewer, completed_at = :now, response_notes = 'Dados exportados.'
            WHERE id = :id AND clinic_id = :clinic_id AND status IN ('pending','in_review')
        ");
        $upd->execute(['reviewer' => $reviewerId, 'now' => date('Y-m-d H:i:s'), 'id' => $requestId, 'clinic_id' => $clinicId]);
        if ($upd->rowCount() !== 1) {
            throw new InvalidArgumentException('Esta solicitação já foi concluída.');
        }
        $this->audit->log('DATA_SUBJECT_EXPORT_COMPLETED', 'data_subject_requests', (string)$requestId, [
            'patient_id' => $patientId, 'checksum_sha256' => $checksum,
        ]);

        return [
            'request_id' => $requestId,
            'status' => 'completed',
            'export_package' => $exportData,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * Processa solicitação de eliminação / anonimização aplicando a guarda legal do prontuário.
     * NUNCA executa exclusão física de dados clínicos. Revisor: dono ou admin da clínica (usuário).
     * Resultado: 'completed' (sem prontuário) ou 'completed_legal_hold' (cadastro anonimizado, prontuário preservado).
     */
    public function processDeletionWithLegalHold(int $requestId, int $reviewerId): array
    {
        $clinicId = TenantContext::getClinicId();
        $this->assertCanProcess($reviewerId);

        $this->db->beginTransaction();
        try {
            $request = $this->findOpenRequest($requestId, ['deletion', 'anonymization']);
            $patientId = (int)$request['patient_id'];
            $claim = $this->db->prepare("UPDATE data_subject_requests SET status = 'in_review', reviewed_by = ? WHERE id = ? AND clinic_id = ? AND status IN ('pending','in_review')");
            $claim->execute([$reviewerId, $requestId, $clinicId]);
            if ($claim->rowCount() !== 1) throw new InvalidArgumentException('Esta solicitação já foi concluída.');

            $held = [];
            foreach (self::CLINICAL_HOLD_TABLES as $table) {
                $c = $this->db->prepare("SELECT COUNT(*) FROM $table WHERE clinic_id = ? AND patient_id = ?");
                $c->execute([$clinicId, $patientId]);
                if ((int)$c->fetchColumn() > 0) $held[] = $table;
            }
            $hasClinicalRecords = $held !== [];

            $this->lgpdService->anonymizePatient($patientId);

            $now = date('Y-m-d H:i:s');
            $status = $hasClinicalRecords ? 'completed_legal_hold' : 'completed';
            $justification = $hasClinicalRecords ? self::LEGAL_HOLD_NOTICE : 'Dados cadastrais anonimizados; não havia prontuário sob guarda legal.';

            $upd = $this->db->prepare("
                UPDATE data_subject_requests
                SET status = :status, reviewed_by = :reviewer, legal_justification = :justification,
                    response_notes = :justification, completed_at = :now
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $upd->execute(['status' => $status, 'reviewer' => $reviewerId, 'justification' => $justification,
                'now' => $now, 'id' => $requestId, 'clinic_id' => $clinicId]);

            $this->audit->log('DATA_SUBJECT_DELETION_PROCESSED', 'data_subject_requests', (string)$requestId, [
                'patient_id' => $patientId,
                'status' => $status,
                'legal_hold_applied' => $hasClinicalRecords,
                'held_tables' => $held,
            ]);

            $this->db->commit();

            return [
                'request_id' => $requestId,
                'patient_id' => $patientId,
                'status' => $status,
                'legal_hold_applied' => $hasClinicalRecords,
                'held_tables' => $held,
                'justification' => $justification,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
