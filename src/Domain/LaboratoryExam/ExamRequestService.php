<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Solicitação de exames laboratoriais para acompanhamento nutricional (NI2-529 a NI2-533; v45; v47 versões e atendimento).
 *
 * Fluxo: rascunho (draft) → revisão (review) → emitido (issued); cancelado (cancelled) a partir de qualquer um.
 *  - Rascunho: pode ser salvo e editado à vontade; guarda o valor REAL da ciência
 *    do aviso de cobertura e o sinônimo pelo qual cada exame foi achado.
 *  - Revisão: validação completa (exames elegíveis, justificativa, CRN com região). Editar volta ao rascunho.
 *  - Emissão: nutricionista elegível (CRN com região, vínculo ativo), autor do pedido e ciência confirmada.
 *    Não depende de liberação nem de aprovação externa (v46).
 *    O PDF é gerado no servidor, gravado fora de public/ e o SHA-256 dos BYTES do PDF vai para pdf_hash. O
 *    pedido só vira "issued" depois que o arquivo foi gravado; se a troca de status falhar, o arquivo é apagado.
 *  - Toda ação vai para exam_audit_logs e, espelhada, para audit_logs (AuditLogger), sem texto clínico.
 *  - Isolamento por clínica em toda leitura e escrita (clinic_id da sessão, nunca do formulário).
 */
class ExamRequestService
{
    public const STATUS_LABELS = [
        'draft' => 'Rascunho',
        'review' => 'Em revisão',
        'issued' => 'Emitido',
        'cancelled' => 'Cancelado',
    ];

    private PDO $db;
    private ExamCatalogService $catalog;
    private ?ExamRequestPdfService $pdf;

    public function __construct(?PDO $db = null, ?ExamRequestPdfService $pdf = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->catalog = new ExamCatalogService($this->db);
        $this->pdf = $pdf;
    }

    private function pdfService(): ExamRequestPdfService
    {
        return $this->pdf ??= new ExamRequestPdfService();
    }

    /**
     * Cria (requestId null) ou atualiza um rascunho. Pedido em revisão volta para rascunho ao ser editado.
     *
     * $input: exam_ids (int[]), matched_synonym ([examId => texto]), instructions ([examId => texto]),
     *         clinical_justification, diagnostic_hypothesis_nutritional, coverage_disclaimer_acknowledged (bool)
     */
    public function saveDraft(int $patientId, int $userId, array $input, ?int $requestId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $prof = ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);

        $items = $this->validateItems($input, $clinicId);
        $appointmentId = $this->resolveAppointment($input, $patientId, $clinicId);
        $justification = trim((string)($input['clinical_justification'] ?? ''));
        if (mb_strlen($justification, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('A justificativa clínica excede o limite máximo de 4.000 caracteres.');
        }
        $diag = trim((string)($input['diagnostic_hypothesis_nutritional'] ?? ''));
        $diag = $diag === '' ? null : mb_substr($diag, 0, 255, 'UTF-8');
        $coverage = !empty($input['coverage_disclaimer_acknowledged']) ? 1 : 0;
        $now = date('Y-m-d H:i:s');

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) $this->db->beginTransaction();
        try {
            if ($requestId !== null && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
                $lock=$this->db->prepare('SELECT id FROM exam_requests WHERE id=? AND clinic_id=? FOR UPDATE');$lock->execute([$requestId,$clinicId]);
            }
            if ($requestId === null) {
                $number = $this->generateRequestNumber($clinicId);
                $this->db->prepare("INSERT INTO exam_requests (
                        clinic_id, patient_id, professional_id, policy_version, request_number, clinical_justification,
                        diagnostic_hypothesis_nutritional, status, professional_snapshot, patient_snapshot,
                        coverage_disclaimer_acknowledged, appointment_id, version_number, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, 1, ?, ?)")
                    ->execute([
                        $clinicId, $patientId, $prof['professional_id'], (string)ExamRegulatoryPolicy::getActivePolicy($this->db)['policy_version'], $number,
                        $justification, $diag, self::json($prof), self::json($this->capturePatientSnapshot($patientId, $clinicId)),
                        $coverage, $appointmentId, $now, $now,
                    ]);
                $requestId = (int)$this->db->lastInsertId();
                $action = 'EXAM_REQUEST_DRAFT_CREATED';
            } else {
                $current = $this->requireEditable($requestId, $clinicId, $patientId, (int)$prof['professional_id']);
                $this->db->prepare("UPDATE exam_requests SET clinical_justification = ?, diagnostic_hypothesis_nutritional = ?,
                        status = 'draft', reviewed_at = NULL, professional_snapshot = ?, patient_snapshot = ?,
                        coverage_disclaimer_acknowledged = ?, appointment_id = ?, updated_at = ?
                    WHERE id = ? AND clinic_id = ? AND status IN ('draft', 'review')")
                    ->execute([$justification, $diag, self::json($prof), self::json($this->capturePatientSnapshot($patientId, $clinicId)),
                        $coverage, $appointmentId, $now, $requestId, $clinicId]);
                $this->db->prepare('DELETE FROM exam_request_items WHERE exam_request_id = ?')->execute([$requestId]);
                $action = $current['status'] === 'review' ? 'EXAM_REQUEST_BACK_TO_DRAFT' : 'EXAM_REQUEST_DRAFT_UPDATED';
            }
            $this->insertItems($requestId, $items, $now);
            $this->recordAudit($clinicId, $userId, $action, $requestId, [
                'patient_id' => $patientId,
                'items_count' => count($items),
                'coverage_disclaimer_acknowledged' => $coverage,
                'appointment_id' => $appointmentId,
            ]);
            if ($ownTransaction) $this->db->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $this->getById($requestId, $clinicId) ?? [];
    }

    /** Rascunho → revisão, com a validação completa. */
    public function submitForReview(int $requestId, int $userId): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->getById($requestId, $clinicId);
        if (!$request) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        $prof = ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        if ($request['status'] !== 'draft') {
            throw new DomainException('Só um rascunho pode ir para revisão.');
        }
        $this->assertAuthor($request, (int)$prof['professional_id']);
        $this->assertComplete($request);

        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare("UPDATE exam_requests SET status = 'review', reviewed_at = ?, updated_at = ? WHERE id = ? AND clinic_id = ? AND status = 'draft'");
        $st->execute([$now, $now, $requestId, $clinicId]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('A solicitação mudou enquanto você revisava. Abra de novo e confira.');
        }
        $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_SUBMITTED_FOR_REVIEW', $requestId, ['items_count' => count($request['items'])]);
        return $this->getById($requestId, $clinicId) ?? [];
    }

    /**
     * Revisão → emitido. Ordem: validações → PDF gerado e gravado → hash dos bytes → troca de status
     * condicionada a status = 'review'. Se qualquer passo falhar, o pedido continua em revisão e nenhum arquivo fica.
     */
    public function issue(int $requestId, int $userId, bool $coverageAcknowledged): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->getById($requestId, $clinicId);
        if (!$request) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        TenantGuard::assertPatientBelongsToClinic((int)$request['patient_id'], $clinicId, $this->db);
        $prof = ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic((int)$prof['professional_id'], $clinicId, $this->db);
        if ($request['status'] === 'draft') {
            $this->submitForReview($requestId, $userId);
            $request = $this->getById($requestId, $clinicId);
        }
        if ($request['status'] !== 'review') {
            throw new DomainException('Revise a solicitação antes de emitir (só uma solicitação em revisão pode ser emitida).');
        }
        $this->assertAuthor($request, (int)$prof['professional_id']);
        ExamRegulatoryPolicy::assertCoverageDisclaimerAcknowledged($coverageAcknowledged);
        $this->assertComplete($request);

        $policy = ExamRegulatoryPolicy::getActivePolicy($this->db);
        $now = date('Y-m-d H:i:s');
        $prof['clinic'] = $this->clinicHeader($clinicId);
        $patient = $this->capturePatientSnapshot((int)$request['patient_id'], $clinicId);

        $issued = $request;
        $issued['status'] = 'issued';
        $issued['issued_at'] = $now;
        $issued['policy_version'] = (string)$policy['policy_version'];
        $issued['professional_snapshot'] = $prof;
        $issued['patient_snapshot'] = $patient;
        $issued['coverage_disclaimer_acknowledged'] = 1;
        $doc = self::buildDocument($issued, $policy);

        $bytes = $this->pdfService()->render($doc);
        $stored = $this->pdfService()->store($clinicId, (string)$request['request_number'], $bytes);
        $hash = hash('sha256', $bytes);

        try {
            $st = $this->db->prepare("UPDATE exam_requests SET status = 'issued', issued_at = ?, issued_by_user_id = ?,
                    policy_version = ?, professional_id = ?, professional_snapshot = ?, patient_snapshot = ?,
                    coverage_disclaimer_acknowledged = 1, pdf_hash = ?, pdf_path = ?, pdf_size = ?, pdf_generated_at = ?, updated_at = ?
                WHERE id = ? AND clinic_id = ? AND status = 'review'");
            $st->execute([$now, $userId, $policy['policy_version'], $prof['professional_id'], self::json($prof), self::json($patient),
                $hash, $stored, strlen($bytes), $now, $now, $requestId, $clinicId]);
            if ($st->rowCount() !== 1) {
                throw new DomainException('A solicitação mudou enquanto era emitida. Abra de novo e confira.');
            }
        } catch (\Throwable $e) {
            $this->pdfService()->delete($stored);
            throw $e;
        }
        $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_ISSUED', $requestId, [
            'request_number' => $request['request_number'],
            'patient_id' => (int)$request['patient_id'],
            'professional_crn' => $prof['crn_formatted'],
            'items_count' => count($request['items']),
            'pdf_sha256' => $hash,
            'pdf_size' => strlen($bytes),
            'policy_version' => $policy['policy_version'],
        ]);
        return $this->getById($requestId, $clinicId) ?? [];
    }

    /** Cancela (de rascunho, revisão ou emitido), com motivo. O PDF emitido fica guardado como histórico. */
    public function cancelRequest(int $requestId, int $userId, string $cancellationReason): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->getById($requestId, $clinicId);
        if (!$request) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        if ($request['status'] === 'cancelled') {
            throw new DomainException('Esta solicitação de exames já está cancelada.');
        }
        $prof = ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        if ((int)$request['professional_id'] !== (int)$prof['professional_id'] && !$this->isClinicManager($userId, $clinicId)) {
            throw new DomainException('Só quem fez a solicitação ou a administração da clínica pode cancelá-la.');
        }
        $reason = trim($cancellationReason);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new InvalidArgumentException('Informe um motivo válido para o cancelamento (mínimo de 5 caracteres).');
        }
        $reason = mb_substr($reason, 0, 1000, 'UTF-8');
        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare("UPDATE exam_requests SET status = 'cancelled', cancelled_at = ?, cancellation_reason = ?, updated_at = ?
            WHERE id = ? AND clinic_id = ? AND status <> 'cancelled'");
        $st->execute([$now, $reason, $now, $requestId, $clinicId]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('Esta solicitação de exames já está cancelada.');
        }
        $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_CANCELLED', $requestId, [
            'request_number' => $request['request_number'],
            'previous_status' => $request['status'],
        ]);
        return $this->getById($requestId, $clinicId) ?? [];
    }

    /**
     * Bytes do PDF emitido, depois de conferir o SHA-256 gravado. Isolado por clínica.
     *
     * @return array{bytes:string,filename:string,hash:string}
     */
    public function pdfForDownload(int $requestId, int $patientId, int $userId): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->getById($requestId, $clinicId);
        if (!$request || (int)$request['patient_id'] !== $patientId) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        if ($request['status'] === 'cancelled') {
            throw new DomainException('Solicitação cancelada: o PDF não é mais entregue. A versão para impressão mostra o cancelamento.');
        }
        if ($request['status'] !== 'issued' || empty($request['pdf_path'])) {
            throw new DomainException('Esta solicitação ainda não tem PDF emitido.');
        }
        $bytes = $this->pdfService()->read((string)$request['pdf_path']);
        if ($bytes === null || !hash_equals((string)$request['pdf_hash'], hash('sha256', $bytes))) {
            $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_PDF_INTEGRITY_FAILED', $requestId, ['request_number' => $request['request_number']]);
            throw new DomainException('O PDF guardado não confere com o código de integridade registrado na emissão. Fale com o suporte.');
        }
        $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_PDF_DOWNLOADED', $requestId, ['request_number' => $request['request_number']]);
        return [
            'bytes' => $bytes,
            'filename' => 'solicitacao-exames-' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$request['request_number']) . '.pdf',
            'hash' => (string)$request['pdf_hash'],
        ];
    }

    /** Registra a abertura da versão para impressão (só pedidos emitidos ou cancelados). */
    public function recordPrintView(array $request, int $userId): void
    {
        $this->recordAudit((int)$request['clinic_id'], $userId, 'EXAM_REQUEST_PRINT_VIEWED', (int)$request['id'], ['request_number' => $request['request_number']]);
    }

    public function getById(int $requestId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, p.name AS patient_name, u.name AS professional_name,
                   prev.request_number AS supersedes_request_number,
                   nxt.id AS superseded_by_id, nxt.request_number AS superseded_by_number,
                   a.scheduled_at AS appointment_scheduled_at
            FROM exam_requests r
            JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
            LEFT JOIN professionals prof ON prof.id = r.professional_id
            LEFT JOIN users u ON u.id = prof.user_id
            LEFT JOIN exam_requests prev ON prev.id = r.supersedes_request_id AND prev.clinic_id = r.clinic_id
            LEFT JOIN exam_requests nxt ON nxt.supersedes_request_id = r.id AND nxt.clinic_id = r.clinic_id
            LEFT JOIN appointments a ON a.id = r.appointment_id AND a.clinic_id = r.clinic_id
            WHERE r.id = :id AND r.clinic_id = :clinic_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $requestId, 'clinic_id' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['professional_snapshot'] = json_decode((string)$row['professional_snapshot'], true) ?: [];
        $row['patient_snapshot'] = json_decode((string)$row['patient_snapshot'], true) ?: [];
        $stmtItems = $this->db->prepare("
            SELECT id, exam_id, exam_canonical_name, exam_code, sample_type, specific_instructions, matched_synonym, sort_order
            FROM exam_request_items
            WHERE exam_request_id = :req_id
            ORDER BY sort_order ASC, id ASC
        ");
        $stmtItems->execute(['req_id' => $requestId]);
        $row['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function listByPatient(int $patientId, int $clinicId): array
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $stmt = $this->db->prepare("
            SELECT r.id, r.request_number, r.status, r.policy_version, r.issued_at, r.created_at, r.updated_at,
                   r.pdf_hash, r.pdf_path, r.professional_snapshot, u.name AS professional_name,
                   r.version_number, r.supersedes_request_id, r.appointment_id, r.cancelled_at, r.cancellation_reason,
                   r.clinical_justification, a.scheduled_at AS appointment_scheduled_at,
                   (SELECT COUNT(*) FROM exam_request_items WHERE exam_request_id = r.id) AS total_items
            FROM exam_requests r
            LEFT JOIN professionals prof ON prof.id = r.professional_id
            LEFT JOIN users u ON u.id = prof.user_id
            LEFT JOIN appointments a ON a.id = r.appointment_id AND a.clinic_id = r.clinic_id
            WHERE r.patient_id = :patient_id AND r.clinic_id = :clinic_id
            ORDER BY r.id DESC
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $snap = json_decode((string)$r['professional_snapshot'], true) ?: [];
            $r['professional_crn'] = (string)($snap['crn_formatted'] ?? '');
            unset($r['professional_snapshot']);
        }
        return $rows;
    }

    /**
     * Dados do documento (mesma fonte para a página de impressão e para o PDF). Só usa os snapshots gravados.
     */
    public static function buildDocument(array $request, array $policy): array
    {
        $prof = (array)($request['professional_snapshot'] ?? []);
        $patient = (array)($request['patient_snapshot'] ?? []);
        $clinic = (array)($prof['clinic'] ?? ['name' => (string)($prof['clinic_name'] ?? '')]);
        $tz = new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
        $fmt = static function (?string $v, string $f) use ($tz): string {
            if ($v === null || trim($v) === '') {
                return '';
            }
            try {
                return (new \DateTimeImmutable($v, $tz))->format($f);
            } catch (\Throwable $e) {
                return '';
            }
        };
        $birth = (string)($patient['birth_date'] ?? '');
        $issuedAt = (string)($request['issued_at'] ?? '');
        return [
            'request_number' => (string)$request['request_number'],
            'status' => (string)$request['status'],
            'status_label' => self::STATUS_LABELS[$request['status']] ?? (string)$request['status'],
            'issued_date' => $fmt($issuedAt, 'd/m/Y'),
            'issued_at_local' => $fmt($issuedAt, 'd/m/Y H:i'),
            'cancelled_at_local' => $fmt($request['cancelled_at'] ?? null, 'd/m/Y H:i'),
            'cancellation_reason' => (string)($request['cancellation_reason'] ?? ''),
            'policy_version' => (string)($request['policy_version'] ?? ''),
            'version_number' => (int)($request['version_number'] ?? 1),
            'supersedes_request_number' => (string)($request['supersedes_request_number'] ?? ''),
            'clinic' => [
                'name' => (string)($clinic['name'] ?? ''),
                'document' => (string)($clinic['document'] ?? ''),
                'address' => (string)($clinic['address'] ?? ''),
                'city' => (string)($clinic['city'] ?? ''),
                'phone' => (string)($clinic['phone'] ?? ''),
                'email' => (string)($clinic['email'] ?? ''),
                'header_text' => (string)($clinic['header_text'] ?? ''),
                'footer_text' => (string)($clinic['footer_text'] ?? ''),
            ],
            'patient' => [
                'name' => (string)($patient['name'] ?? $request['patient_name'] ?? ''),
                'birth_date' => $fmt($birth, 'd/m/Y'),
                'age' => self::ageAt($birth, $issuedAt !== '' ? $issuedAt : 'now'),
            ],
            'professional' => [
                'name' => (string)($prof['name'] ?? ''),
                'crn' => (string)($prof['crn_formatted'] ?? ''),
                'specialty' => (string)($prof['specialty'] ?? ''),
            ],
            'items' => array_map(static fn(array $i) => [
                'name' => (string)$i['exam_canonical_name'],
                'sample_type' => (string)$i['sample_type'],
                'instructions' => (string)($i['specific_instructions'] ?? ''),
            ], (array)($request['items'] ?? [])),
            'justification' => (string)($request['clinical_justification'] ?? ''),
            'diagnostic' => (string)($request['diagnostic_hypothesis_nutritional'] ?? ''),
            'coverage_notice' => \ClyoNutri\Core\Brand::textoExibido((string)($policy['disclaimer_coverage'] ?? '')),
            'legal_basis' => (string)($policy['legal_basis'] ?? ''),
        ];
    }

    public static function ageAt(string $birth, string $at): ?int
    {
        if (trim($birth) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($birth))->diff(new \DateTimeImmutable($at))->y;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Corrigir uma solicitação emitida (NI2-537, v47): a emitida NUNCA muda. Ela é cancelada com o motivo
     * "substituída pela versão N" e nasce um rascunho com os mesmos exames, justificativa e atendimento, que aponta
     * para a anterior (supersedes_request_id) e para a primeira da cadeia (root_request_id). Só o autor.
     */
    public function createNewVersion(int $requestId, int $userId, string $reason): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->getById($requestId, $clinicId);
        if (!$request) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        $prof = ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        $this->assertAuthor($request, (int)$prof['professional_id']);
        if ($request['status'] !== 'issued') {
            throw new DomainException('Só uma solicitação emitida gera nova versão. Rascunho e revisão podem ser editados direto.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new InvalidArgumentException('Informe o que será corrigido (mínimo de 5 caracteres).');
        }
        $reason = mb_substr($reason, 0, 800, 'UTF-8');
        $newVersion = (int)($request['version_number'] ?? 1) + 1;
        $rootId = (int)($request['root_request_id'] ?: $request['id']);
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $cancel = $this->db->prepare("UPDATE exam_requests SET status = 'cancelled', cancelled_at = ?, cancellation_reason = ?, updated_at = ?
                WHERE id = ? AND clinic_id = ? AND status = 'issued'");
            $cancel->execute([$now, "Substituída pela versão {$newVersion}: {$reason}", $now, $requestId, $clinicId]);
            if ($cancel->rowCount() !== 1) {
                throw new DomainException('A solicitação mudou enquanto você corrigia. Abra de novo e confira.');
            }
            $number = $this->generateRequestNumber($clinicId);
            $this->db->prepare("INSERT INTO exam_requests (
                    clinic_id, patient_id, professional_id, policy_version, request_number, clinical_justification,
                    diagnostic_hypothesis_nutritional, status, professional_snapshot, patient_snapshot,
                    coverage_disclaimer_acknowledged, appointment_id, supersedes_request_id, root_request_id, version_number,
                    created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, 0, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $clinicId, (int)$request['patient_id'], (int)$prof['professional_id'],
                    (string)ExamRegulatoryPolicy::getActivePolicy($this->db)['policy_version'], $number,
                    (string)$request['clinical_justification'], $request['diagnostic_hypothesis_nutritional'],
                    self::json($prof), self::json($this->capturePatientSnapshot((int)$request['patient_id'], $clinicId)),
                    $request['appointment_id'] ?: null, $requestId, $rootId, $newVersion, $now, $now,
                ]);
            $newId = (int)$this->db->lastInsertId();
            $items = array_map(static fn(array $i) => [
                'exam_id' => (int)$i['exam_id'],
                'canonical_name' => (string)$i['exam_canonical_name'],
                'code' => (string)$i['exam_code'],
                'sample_type' => (string)$i['sample_type'],
                'specific_instructions' => $i['specific_instructions'],
                'matched_synonym' => $i['matched_synonym'],
            ], $request['items']);
            $this->insertItems($newId, $items, $now);
            if ((int)($request['root_request_id'] ?? 0) === 0) {
                $this->db->prepare('UPDATE exam_requests SET root_request_id = ? WHERE id = ? AND clinic_id = ?')->execute([$requestId, $requestId, $clinicId]);
            }
            $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_SUPERSEDED', $requestId, [
                'request_number' => $request['request_number'], 'new_request_id' => $newId, 'new_version' => $newVersion,
            ]);
            $this->recordAudit($clinicId, $userId, 'EXAM_REQUEST_NEW_VERSION_CREATED', $newId, [
                'request_number' => $number, 'supersedes_request_id' => $requestId, 'version' => $newVersion, 'items_count' => count($items),
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $this->getById($newId, $clinicId) ?? [];
    }

    /**
     * Linha do tempo para o prontuário e a consulta (NI2-537): solicitações com exames, autor, atendimento,
     * situação e versão. $appointmentId restringe às do atendimento.
     */
    public function timeline(int $patientId, int $clinicId, ?int $appointmentId = null): array
    {
        $rows = $this->listByPatient($patientId, $clinicId);
        if ($appointmentId !== null) {
            $rows = array_values(array_filter($rows, static fn(array $r) => (int)($r['appointment_id'] ?? 0) === $appointmentId));
        }
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $r) => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT i.exam_request_id, i.exam_canonical_name FROM exam_request_items i
            JOIN exam_requests r ON r.id = i.exam_request_id AND r.clinic_id = ?
            WHERE i.exam_request_id IN ({$in}) ORDER BY i.sort_order ASC, i.id ASC");
        $st->execute(array_merge([$clinicId], $ids));
        $names = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $names[(int)$i['exam_request_id']][] = (string)$i['exam_canonical_name'];
        }
        try {
            $counts = (new ExamResultDocumentService($this->db))->countsByRequest($ids, $clinicId); // v48 (NI2-539)
        } catch (\PDOException $e) {
            $counts = []; // banco ainda sem a migração 67
        }
        foreach ($rows as &$r) {
            $r['exam_names'] = $names[(int)$r['id']] ?? [];
            $r['status_label'] = self::STATUS_LABELS[$r['status']] ?? (string)$r['status'];
            $r['results_accepted'] = $counts[(int)$r['id']]['accepted'] ?? 0;
            $r['results_pending'] = $counts[(int)$r['id']]['pending'] ?? 0;
        }
        return $rows;
    }

    /** Registra a abertura da tela da solicitação (NI2-542). */
    public function recordView(array $request, int $userId): void
    {
        $this->recordAudit((int)$request['clinic_id'], $userId, 'EXAM_REQUEST_VIEWED', (int)$request['id'], ['request_number' => $request['request_number'], 'status' => $request['status']]);
    }

    /** Atendimentos recentes do paciente para vincular a solicitação (mais novos primeiro). */
    public function appointmentsForPatient(int $patientId, int $clinicId, int $limit = 15): array
    {
        $st = $this->db->prepare("SELECT id, scheduled_at, status, type FROM appointments
            WHERE clinic_id = ? AND patient_id = ? AND status <> 'cancelled'
            ORDER BY scheduled_at DESC LIMIT " . max(1, min(50, $limit)));
        $st->execute([$clinicId, $patientId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------ internos

    /** appointment_id do formulário: vazio = sem vínculo; senão tem de ser da clínica E do mesmo paciente. */
    private function resolveAppointment(array $input, int $patientId, int $clinicId): ?int
    {
        $id = (int)($input['appointment_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $st = $this->db->prepare('SELECT patient_id FROM appointments WHERE id = ? AND clinic_id = ?');
        $st->execute([$id, $clinicId]);
        $owner = $st->fetchColumn();
        if ($owner === false || (int)$owner !== $patientId) {
            throw new InvalidArgumentException('Atendimento não encontrado para este paciente.');
        }
        return $id;
    }

    /** Reordena os ids conforme exam_order (lista de ids, array ou "1,2,3"). Ids fora da ordem vão para o fim. */
    public static function applyOrder(array $ids, mixed $order): array
    {
        if (is_string($order)) {
            $order = explode(',', $order);
        }
        if (!is_array($order) || $order === []) {
            return $ids;
        }
        $pos = [];
        foreach (array_values($order) as $i => $v) {
            $v = (int)$v;
            if ($v > 0 && !isset($pos[$v])) {
                $pos[$v] = $i;
            }
        }
        $indexed = [];
        foreach ($ids as $i => $id) {
            $indexed[] = [$pos[$id] ?? (10000 + $i), $id];
        }
        usort($indexed, static fn($a, $b) => $a[0] <=> $b[0]);
        return array_map(static fn($x) => $x[1], $indexed);
    }

    private function validateItems(array $input, int $clinicId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['exam_ids'] ?? [])), fn($v) => $v > 0)));
        $ids = self::applyOrder($ids, $input['exam_order'] ?? null);
        if ($ids === []) {
            throw new InvalidArgumentException('Selecione ao menos um exame laboratorial.');
        }
        if (count($ids) > 60) {
            throw new InvalidArgumentException('Uma solicitação aceita no máximo 60 exames.');
        }
        $synonyms = (array)($input['matched_synonym'] ?? []);
        $instructions = (array)($input['instructions'] ?? []);
        $items = [];
        foreach ($ids as $examId) {
            $exam = $this->catalog->assertExamEligible($examId, $clinicId);
            $instr = trim((string)($instructions[$examId] ?? ''));
            $items[] = [
                'exam_id' => $examId,
                'canonical_name' => $exam['canonical_name'],
                'code' => $exam['code'],
                'sample_type' => $exam['sample_type'],
                'specific_instructions' => $instr !== '' ? mb_substr($instr, 0, 500, 'UTF-8') : null,
                'matched_synonym' => ExamCatalogService::resolveMatchedSynonym($exam, isset($synonyms[$examId]) ? (string)$synonyms[$examId] : null),
            ];
        }
        return $items;
    }

    private function insertItems(int $requestId, array $items, string $now): void
    {
        $st = $this->db->prepare("INSERT INTO exam_request_items (exam_request_id, exam_id, exam_canonical_name, exam_code,
                sample_type, specific_instructions, matched_synonym, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $order => $item) {
            $st->execute([$requestId, $item['exam_id'], $item['canonical_name'], $item['code'], $item['sample_type'],
                $item['specific_instructions'], $item['matched_synonym'], $order + 1, $now]);
        }
    }

    private function assertComplete(array $request): void
    {
        if (empty($request['items'])) {
            throw new InvalidArgumentException('Selecione ao menos um exame laboratorial.');
        }
        foreach ($request['items'] as $item) {
            $this->catalog->assertExamEligible((int)$item['exam_id'], (int)$request['clinic_id']);
        }
        ExamRegulatoryPolicy::assertNutritionalJustification((string)$request['clinical_justification']);
    }

    private function requireEditable(int $requestId, int $clinicId, int $patientId, int $professionalId): array
    {
        $st = $this->db->prepare('SELECT id, patient_id, professional_id, status FROM exam_requests WHERE id = ? AND clinic_id = ?');
        $st->execute([$requestId, $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['patient_id'] !== $patientId) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        if (!in_array($row['status'], ['draft', 'review'], true)) {
            throw new DomainException('Solicitação emitida ou cancelada não pode ser editada. Para corrigir uma emitida, use "Corrigir (nova versão)".');
        }
        $this->assertAuthor($row, $professionalId);
        return $row;
    }

    private function assertAuthor(array $request, int $professionalId): void
    {
        if ((int)$request['professional_id'] !== $professionalId) {
            throw new DomainException('Só o nutricionista que fez a solicitação pode editar, revisar, emitir ou corrigir.');
        }
    }

    private function isClinicManager(int $userId, int $clinicId): bool
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM clinic_members WHERE clinic_id = ? AND user_id = ? AND status = 'active' AND role IN ('owner', 'admin')");
        $st->execute([$clinicId, $userId]);
        return (int)$st->fetchColumn() > 0;
    }

    /** Identificação do paciente no documento: nome e data de nascimento (o cadastro de pacientes não tem CPF). */
    private function capturePatientSnapshot(int $patientId, int $clinicId): array
    {
        $stmt = $this->db->prepare('SELECT id, name, birth_date, gender FROM patients WHERE id = ? AND clinic_id = ? LIMIT 1');
        $stmt->execute([$patientId, $clinicId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            throw new DomainException('Paciente não encontrado nesta clínica.');
        }
        return [
            'patient_id' => (int)$p['id'],
            'name' => (string)$p['name'],
            'birth_date' => (string)($p['birth_date'] ?? ''),
            'gender' => (string)($p['gender'] ?? ''),
        ];
    }

    /** Cabeçalho da clínica no momento da emissão (gravado no snapshot do profissional). */
    private function clinicHeader(int $clinicId): array
    {
        $st = $this->db->prepare('SELECT * FROM clinics WHERE id = ? LIMIT 1');
        $st->execute([$clinicId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $street = trim(implode(', ', array_filter([
            trim((string)($c['address_street'] ?? '') . ' ' . (string)($c['address_number'] ?? '')),
            (string)($c['address_complement'] ?? ''),
            (string)($c['address_neighborhood'] ?? ''),
        ], fn($v) => trim((string)$v) !== '')));
        $city = trim(implode(' / ', array_filter([(string)($c['address_city'] ?? ''), (string)($c['address_state'] ?? '')], fn($v) => trim($v) !== '')));
        $zip = trim((string)($c['address_zip'] ?? ''));
        $address = trim(implode(' · ', array_filter([$street, $city, $zip !== '' ? 'CEP ' . $zip : ''], fn($v) => $v !== '')));
        return [
            'name' => (string)($c['name'] ?? ''),
            'document' => (string)($c['document'] ?? ''),
            'address' => $address,
            'city' => (string)($c['address_city'] ?? ''),
            'phone' => (string)($c['phone'] ?? ''),
            'email' => (string)($c['email'] ?? ''),
            'header_text' => (string)($c['document_header_text'] ?? ''),
            'footer_text' => (string)($c['document_footer_text'] ?? ''),
        ];
    }

    private function generateRequestNumber(int $clinicId): string
    {
        return 'EXAM-' . date('Y') . "-C{$clinicId}-" . strtoupper(bin2hex(random_bytes(3)));
    }

    private static function json(array $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** exam_audit_logs (trilha do módulo) + audit_logs (AuditLogger), sem texto clínico nos detalhes. */
    private function recordAudit(int $clinicId, int $userId, string $action, int $id, array $details): void
    {
        $this->db->prepare("INSERT INTO exam_audit_logs (clinic_id, user_id, action, entity_type, entity_id, ip_address, details, created_at)
            VALUES (?, ?, ?, 'exam_requests', ?, ?, ?, CURRENT_TIMESTAMP)")
            ->execute([$clinicId, $userId, $action, $id, $_SERVER['REMOTE_ADDR'] ?? null, json_encode($details, JSON_UNESCAPED_UNICODE)]);
        try {
            (new AuditLogger($this->db))->log($action, 'exam_request', (string)$id, $details);
        } catch (\Throwable $e) {
            error_log('EXAM_AUDIT_MIRROR_FAILED ' . $action);
        }
    }
}
