<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Laudos e resultados laboratoriais da solicitação de exames (v48, NI2-539).
 *
 *  - O arquivo ORIGINAL é guardado como chegou (bytes intactos, SHA-256 gravado), fora de public/, com nome aleatório.
 *    Nunca é sobrescrito nem apagado pelo sistema. Antes de guardar, ExamFileInspector confere o tipo real e recusa
 *    arquivo inseguro (nada é guardado nesse caso).
 *  - Nutricionista anexa: o laudo entra "aceito" (ela já revisou) e já diz quais exames ele cobre.
 *  - Paciente envia pelo app: entra "aguardando revisão" e NÃO vira dado clínico: não conta como resultado recebido,
 *    não aparece como aceito e a nutricionista decide (aceitar escolhendo os exames cobertos, ou recusar com motivo).
 *  - Correção: o laudo corrigido é um documento NOVO (versão N+1) que aponta para o anterior; o anterior continua
 *    guardado e aparece como "substituído". Uma correção por versão.
 *  - Abrir/baixar: só com sessão da clínica E link assinado que expira (5 min para a nutricionista, 2 min no app),
 *    conferindo o SHA-256 a cada entrega.
 *  - Isolamento por clínica em toda leitura e escrita; trilha em exam_audit_logs sem texto clínico.
 */
class ExamResultDocumentService
{
    public const STATUS_LABELS = [
        'pending_review' => 'Aguardando revisão',
        'accepted' => 'Aceito',
        'rejected' => 'Recusado',
    ];
    public const PATIENT_DAILY_LIMIT = 20;
    private const LINK_TTL_PROFESSIONAL = 300;
    private const LINK_TTL_PATIENT = 120;

    private PDO $db;
    private string $baseDir;
    private ExamFileInspector $inspector;

    public function __construct(?PDO $db = null, ?ExamFileInspector $inspector = null, ?string $baseDir = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->inspector = $inspector ?? new ExamFileInspector();
        $this->baseDir = rtrim($baseDir ?? (getenv('EXAM_PDF_STORAGE_DIR') ?: dirname(__DIR__, 3) . '/storage/app'), '/');
    }

    // ------------------------------------------------------------------ envio

    /**
     * Nutricionista anexa o laudo a uma solicitação emitida. $file no formato de $_FILES['x'].
     * $meta: laboratory_name, collected_at (Y-m-d), report_issued_at (Y-m-d), notes, item_ids (int[] da solicitação)
     */
    public function uploadByProfessional(int $requestId, int $userId, array $file, array $meta): array
    {
        $clinicId = TenantContext::getClinicId();
        ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        $request = $this->requireIssuedRequest($requestId, $clinicId);
        $meta = $this->cleanMeta($meta, $request);
        if ($meta['item_ids'] === []) {
            throw new InvalidArgumentException('Marque quais exames da solicitação este laudo traz.');
        }
        $id = $this->store($request, $file, $meta, 'professional', $userId, null, 'accepted', $userId);
        $this->audit($clinicId, $userId, 'EXAM_RESULT_UPLOADED_BY_PROFESSIONAL', $id, ['exam_request_id' => $requestId, 'items_count' => count($meta['item_ids'])]);
        return $this->get($id, $clinicId) ?? [];
    }

    /**
     * Paciente envia pelo app (TenantContext já com a clínica do token). Só para solicitação emitida DELA.
     * Entra "aguardando revisão"; limite diário por paciente.
     */
    public function uploadByPatient(int $patientId, int $requestId, array $file, array $meta): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->requireIssuedRequest($requestId, $clinicId);
        if ((int)$request['patient_id'] !== $patientId) {
            throw new DomainException('Solicitação de exames não encontrada.');
        }
        $st = $this->db->prepare("SELECT COUNT(*) FROM exam_result_documents WHERE clinic_id = ? AND uploaded_by_patient_id = ? AND created_at >= ?");
        $st->execute([$clinicId, $patientId, date('Y-m-d H:i:s', time() - 86400)]);
        if ((int)$st->fetchColumn() >= self::PATIENT_DAILY_LIMIT) {
            throw new DomainException('Limite de envios de hoje atingido. Tente de novo amanhã ou fale com sua nutricionista.');
        }
        $meta = $this->cleanMeta($meta, $request);
        $id = $this->store($request, $file, $meta, 'patient', null, $patientId, 'pending_review', null);
        $this->audit($clinicId, 0, 'EXAM_RESULT_UPLOADED_BY_PATIENT', $id, ['exam_request_id' => $requestId, 'patient_id' => $patientId]);
        return $this->get($id, $clinicId) ?? [];
    }

    /** Aceitar (escolhendo os exames cobertos) ou recusar (com motivo) um laudo aguardando revisão. */
    public function review(int $documentId, int $userId, bool $accept, string $note, array $itemIds = []): array
    {
        $clinicId = TenantContext::getClinicId();
        ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        $doc = $this->get($documentId, $clinicId);
        if (!$doc) {
            throw new DomainException('Laudo não encontrado nesta clínica.');
        }
        if ($doc['status'] !== 'pending_review') {
            throw new DomainException('Este laudo já foi revisado.');
        }
        $note = mb_substr(trim($note), 0, 1000, 'UTF-8');
        $items = [];
        if ($accept) {
            $request = $this->requireRequest((int)$doc['exam_request_id'], $clinicId);
            $items = $this->validItemIds($itemIds, $request);
            if ($items === []) {
                throw new InvalidArgumentException('Para aceitar, marque quais exames da solicitação este laudo traz.');
            }
        } elseif (mb_strlen($note, 'UTF-8') < 5) {
            throw new InvalidArgumentException('Informe o motivo da recusa (mínimo de 5 caracteres). A paciente vê esse motivo.');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("UPDATE exam_result_documents SET status = ?, reviewed_by_user_id = ?, reviewed_at = ?, review_note = ?
                WHERE id = ? AND clinic_id = ? AND status = 'pending_review'");
            $st->execute([$accept ? 'accepted' : 'rejected', $userId, $now, $note === '' ? null : $note, $documentId, $clinicId]);
            if ($st->rowCount() !== 1) {
                throw new DomainException('Este laudo já foi revisado.');
            }
            $this->db->prepare('DELETE FROM exam_result_document_items WHERE document_id = ?')->execute([$documentId]);
            $this->insertItems($documentId, $items, $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        $this->audit($clinicId, $userId, $accept ? 'EXAM_RESULT_ACCEPTED' : 'EXAM_RESULT_REJECTED', $documentId, ['items_count' => count($items)]);
        return $this->get($documentId, $clinicId) ?? [];
    }

    /**
     * Correção: anexa um arquivo novo como versão seguinte. O anterior fica guardado e passa a constar como substituído.
     * Dados do laudo (laboratório, datas, exames) vêm do formulário ou, se vazios, do anterior.
     */
    public function newVersion(int $documentId, int $userId, array $file, string $reason, array $meta = []): array
    {
        $clinicId = TenantContext::getClinicId();
        ProfessionalEligibilityValidator::validateEligibility($userId, $clinicId, $this->db);
        $prev = $this->get($documentId, $clinicId);
        if (!$prev) {
            throw new DomainException('Laudo não encontrado nesta clínica.');
        }
        if (!empty($prev['superseded_by_id'])) {
            throw new DomainException('Este laudo já foi substituído. Corrija a versão mais recente.');
        }
        if ($prev['status'] === 'rejected') {
            throw new DomainException('Laudo recusado não tem correção. Anexe um laudo novo.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new InvalidArgumentException('Informe o que está sendo corrigido (mínimo de 5 caracteres).');
        }
        $request = $this->requireRequest((int)$prev['exam_request_id'], $clinicId);
        $merged = [
            'laboratory_name' => trim((string)($meta['laboratory_name'] ?? '')) !== '' ? $meta['laboratory_name'] : $prev['laboratory_name'],
            'collected_at' => trim((string)($meta['collected_at'] ?? '')) !== '' ? $meta['collected_at'] : $prev['collected_at'],
            'report_issued_at' => trim((string)($meta['report_issued_at'] ?? '')) !== '' ? $meta['report_issued_at'] : $prev['report_issued_at'],
            'notes' => trim((string)($meta['notes'] ?? '')) !== '' ? $meta['notes'] : $prev['notes'],
            'item_ids' => !empty($meta['item_ids']) ? $meta['item_ids'] : array_map(static fn($i) => (int)$i['exam_request_item_id'], $prev['items']),
        ];
        $merged = $this->cleanMeta($merged, $request);
        if ($merged['item_ids'] === []) {
            throw new InvalidArgumentException('Marque quais exames da solicitação este laudo traz.');
        }
        $merged['version_number'] = (int)$prev['version_number'] + 1;
        $merged['supersedes_document_id'] = (int)$prev['id'];
        $merged['root_document_id'] = (int)($prev['root_document_id'] ?: $prev['id']);
        $merged['correction_reason'] = mb_substr($reason, 0, 1000, 'UTF-8');
        $id = $this->store($request, $file, $merged, 'professional', $userId, null, 'accepted', $userId, (int)$prev['id']);
        $this->audit($clinicId, $userId, 'EXAM_RESULT_NEW_VERSION', $id, ['supersedes_document_id' => (int)$prev['id'], 'version' => $merged['version_number']]);
        return $this->get($id, $clinicId) ?? [];
    }

    // ------------------------------------------------------------------ leitura

    public function get(int $documentId, int $clinicId): ?array
    {
        $st = $this->db->prepare("SELECT d.*, u.name AS uploaded_by_user_name, rv.name AS reviewed_by_name,
                nxt.id AS superseded_by_id, nxt.version_number AS superseded_by_version
            FROM exam_result_documents d
            LEFT JOIN users u ON u.id = d.uploaded_by_user_id
            LEFT JOIN users rv ON rv.id = d.reviewed_by_user_id
            LEFT JOIN exam_result_documents nxt ON nxt.supersedes_document_id = d.id AND nxt.clinic_id = d.clinic_id
            WHERE d.id = ? AND d.clinic_id = ? LIMIT 1");
        $st->execute([$documentId, $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['items'] = $this->itemsFor([(int)$row['id']])[(int)$row['id']] ?? [];
        return self::decorate($row);
    }

    /** Laudos da solicitação, mais novos primeiro, com exames cobertos e quem substituiu. */
    public function listForRequest(int $requestId, int $clinicId): array
    {
        $st = $this->db->prepare("SELECT d.*, u.name AS uploaded_by_user_name, rv.name AS reviewed_by_name,
                nxt.id AS superseded_by_id, nxt.version_number AS superseded_by_version
            FROM exam_result_documents d
            LEFT JOIN users u ON u.id = d.uploaded_by_user_id
            LEFT JOIN users rv ON rv.id = d.reviewed_by_user_id
            LEFT JOIN exam_result_documents nxt ON nxt.supersedes_document_id = d.id AND nxt.clinic_id = d.clinic_id
            WHERE d.exam_request_id = ? AND d.clinic_id = ? ORDER BY d.id DESC");
        $st->execute([$requestId, $clinicId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $items = $this->itemsFor(array_map(static fn($r) => (int)$r['id'], $rows));
        foreach ($rows as &$r) {
            $r['items'] = $items[(int)$r['id']] ?? [];
            $r = self::decorate($r);
        }
        return $rows;
    }

    /**
     * Situação por exame da solicitação (NI2-537/539): para cada item, quantos laudos ACEITOS e vigentes (não
     * substituídos) o cobrem. Laudo aguardando revisão ou recusado não conta.
     * @return array<int, array{accepted:int, last_collected_at:?string}>
     */
    public function coverageByItem(int $requestId, int $clinicId): array
    {
        $st = $this->db->prepare("SELECT di.exam_request_item_id AS item_id, COUNT(*) AS n, MAX(d.collected_at) AS last_collected
            FROM exam_result_document_items di
            JOIN exam_result_documents d ON d.id = di.document_id AND d.clinic_id = ?
            WHERE d.exam_request_id = ? AND d.status = 'accepted'
              AND NOT EXISTS (SELECT 1 FROM exam_result_documents n WHERE n.supersedes_document_id = d.id AND n.clinic_id = d.clinic_id)
            GROUP BY di.exam_request_item_id");
        $st->execute([$clinicId, $requestId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['item_id']] = ['accepted' => (int)$r['n'], 'last_collected_at' => $r['last_collected']];
        }
        return $out;
    }

    /** Contagem por solicitação para listas e linha do tempo: [request_id => [vigentes aceitos, aguardando revisão]]. */
    public function countsByRequest(array $requestIds, int $clinicId): array
    {
        $ids = array_values(array_filter(array_map('intval', $requestIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT d.exam_request_id AS rid,
                SUM(CASE WHEN d.status = 'accepted' AND NOT EXISTS (SELECT 1 FROM exam_result_documents n WHERE n.supersedes_document_id = d.id AND n.clinic_id = d.clinic_id) THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN d.status = 'pending_review' THEN 1 ELSE 0 END) AS pending
            FROM exam_result_documents d WHERE d.clinic_id = ? AND d.exam_request_id IN ({$in}) GROUP BY d.exam_request_id");
        $st->execute(array_merge([$clinicId], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['rid']] = ['accepted' => (int)$r['accepted'], 'pending' => (int)$r['pending']];
        }
        return $out;
    }

    /** Para o app: solicitações emitidas da paciente com os laudos de cada uma (sem caminho nem hash). */
    public function patientOverview(int $patientId, int $clinicId): array
    {
        $st = $this->db->prepare("SELECT r.id, r.request_number, r.issued_at, r.version_number FROM exam_requests r
            WHERE r.clinic_id = ? AND r.patient_id = ? AND r.status = 'issued' ORDER BY r.issued_at DESC, r.id DESC");
        $st->execute([$clinicId, $patientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items = $this->db->prepare('SELECT id, exam_canonical_name FROM exam_request_items WHERE exam_request_id = ? ORDER BY sort_order, id');
            $items->execute([(int)$r['id']]);
            $docs = array_map(static fn(array $d) => [
                'id' => (int)$d['id'],
                'original_name' => $d['original_name'],
                'mime_type' => $d['mime_type'],
                'size_bytes' => (int)$d['size_bytes'],
                'sent_by' => $d['uploaded_by_type'] === 'patient' ? 'paciente' : 'nutricionista',
                'status' => $d['status'],
                'status_label' => $d['status_label'],
                'review_note' => $d['status'] === 'rejected' ? $d['review_note'] : null,
                'laboratory_name' => $d['laboratory_name'],
                'collected_at' => $d['collected_at'],
                'version_number' => (int)$d['version_number'],
                'superseded' => !empty($d['superseded_by_id']),
                'created_at' => $d['created_at'],
            ], $this->listForRequest((int)$r['id'], $clinicId));
            $out[] = [
                'id' => (int)$r['id'],
                'request_number' => $r['request_number'],
                'issued_at' => $r['issued_at'],
                'exams' => array_map(static fn($i) => ['id' => (int)$i['id'], 'name' => $i['exam_canonical_name']], $items->fetchAll(PDO::FETCH_ASSOC)),
                'results' => $docs,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ entrega

    /** Link assinado (5 min) para a nutricionista abrir/baixar; amarrado ao laudo, à clínica e ao usuário. */
    public function professionalLink(int $documentId, int $userId): string
    {
        $clinicId = TenantContext::getClinicId();
        if (!$this->get($documentId, $clinicId)) {
            throw new DomainException('Laudo não encontrado nesta clínica.');
        }
        return self::sign('p', $documentId, $userId, $clinicId, self::LINK_TTL_PROFESSIONAL);
    }

    /** Link assinado (2 min) para o app da paciente; só laudos de solicitações dela. */
    public function patientLink(int $documentId, int $patientId): string
    {
        $clinicId = TenantContext::getClinicId();
        $doc = $this->get($documentId, $clinicId);
        if (!$doc || (int)$doc['patient_id'] !== $patientId) {
            throw new DomainException('Laudo não encontrado.');
        }
        return self::sign('q', $documentId, $patientId, $clinicId, self::LINK_TTL_PATIENT);
    }

    /** @return array{kind:string, document_id:int, subject_id:int, clinic_id:int} */
    public static function verifyLink(string $token): array
    {
        if (!preg_match('/^(([pq])-(\d{1,10})-(\d{1,10})-(\d{1,10})-(\d{1,12}))-([A-Za-z0-9_-]{43})$/D', $token, $m)) {
            throw new DomainException('Link de laudo inválido.');
        }
        $key = self::key();
        if ($key === null || !hash_equals(self::mac($m[1], $key), $m[7])) {
            throw new DomainException('Link de laudo inválido.');
        }
        if ((int)$m[6] < time()) {
            throw new DomainException('Este link expirou. Abra o laudo de novo.');
        }
        return ['kind' => $m[2], 'document_id' => (int)$m[3], 'subject_id' => (int)$m[4], 'clinic_id' => (int)$m[5]];
    }

    /**
     * Bytes do laudo depois de conferir o SHA-256. $actor: ['type' => 'user'|'patient', 'id' => int].
     * @return array{bytes:string, mime_type:string, file_name:string, size_bytes:int}
     */
    public function fileFor(int $documentId, int $clinicId, array $actor): array
    {
        $doc = $this->get($documentId, $clinicId);
        if (!$doc || ($actor['type'] === 'patient' && (int)$doc['patient_id'] !== (int)$actor['id'])) {
            throw new DomainException('Laudo não encontrado.');
        }
        $path = $this->resolve((string)$doc['stored_path']);
        $bytes = $path !== null && is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false || !hash_equals((string)$doc['sha256'], hash('sha256', $bytes))) {
            $this->audit($clinicId, $actor['type'] === 'user' ? (int)$actor['id'] : 0, 'EXAM_RESULT_INTEGRITY_FAILED', $documentId, $actor['type'] === 'patient' ? ['patient_id' => (int)$actor['id']] : []);
            throw new DomainException('O arquivo guardado não confere com o código de integridade registrado. Fale com o suporte.');
        }
        $this->audit($clinicId, $actor['type'] === 'user' ? (int)$actor['id'] : 0, $actor['type'] === 'user' ? 'EXAM_RESULT_OPENED' : 'EXAM_RESULT_OPENED_BY_PATIENT', $documentId, $actor['type'] === 'patient' ? ['patient_id' => (int)$actor['id']] : []);
        return ['bytes' => $bytes, 'mime_type' => (string)$doc['mime_type'], 'file_name' => (string)$doc['original_name'], 'size_bytes' => strlen($bytes)];
    }

    /** Cabeçalhos de entrega: PDF sempre como download; imagem inline; nada executa nem é guardado em cache. */
    public static function sendHeaders(array $file): void
    {
        if (headers_sent()) {
            return;
        }
        $name = preg_replace('/["\\\\\r\n]+/', '_', (string)$file['file_name']);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . $file['size_bytes']);
        header('Content-Disposition: ' . ($file['mime_type'] === 'application/pdf' ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string)$file['file_name']));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; sandbox');
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
    }

    /** Caminho absoluto de um stored_path (só o formato que o sistema grava). */
    public function resolve(string $rel): ?string
    {
        return preg_match('#^exam_results/\d+/[a-f0-9]{32}\.(pdf|jpg|png)$#', $rel) ? $this->baseDir . '/' . $rel : null;
    }

    // ------------------------------------------------------------------ internos

    private function store(array $request, array $file, array $meta, string $byType, ?int $userId, ?int $patientId, string $status, ?int $reviewer, ?int $supersedes = null): int
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $big = in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new InvalidArgumentException($big ? 'O arquivo passa do limite de envio do servidor.' : 'Escolha o arquivo do laudo.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $info = $this->inspector->inspect($tmp, (string)($file['name'] ?? 'laudo'));
        $clinicId = (int)$request['clinic_id'];
        // Mesmo arquivo (mesmo SHA-256) já anexado e não recusado nesta solicitação: não duplica.
        $dup = $this->db->prepare("SELECT COUNT(*) FROM exam_result_documents WHERE clinic_id = ? AND exam_request_id = ? AND sha256 = ? AND status <> 'rejected'");
        $dup->execute([$clinicId, (int)$request['id'], $info['sha256']]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException('Este mesmo arquivo já está anexado a esta solicitação.');
        }
        $dir = $this->baseDir . '/exam_results/' . $clinicId;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível guardar o laudo agora. Nada foi salvo; tente de novo.');
        }
        if (!is_file($this->baseDir . '/exam_results/.htaccess')) {
            @file_put_contents($this->baseDir . '/exam_results/.htaccess', "Require all denied\nDeny from all\n");
        }
        $rel = 'exam_results/' . $clinicId . '/' . bin2hex(random_bytes(16)) . '.' . $info['ext'];
        $dest = $this->baseDir . '/' . $rel;
        $ok = \ClyoNutri\Core\Http\UploadMover::move((string)$tmp, $dest); // CR-NI2-011
        if (!$ok || !hash_equals($info['sha256'], (string)hash_file('sha256', $dest))) {
            @unlink($dest);
            throw new RuntimeException('Não foi possível guardar o laudo agora. Nada foi salvo; tente de novo.');
        }
        @chmod($dest, 0640);
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            if ($supersedes !== null) {
                $chk = $this->db->prepare('SELECT COUNT(*) FROM exam_result_documents WHERE supersedes_document_id = ? AND clinic_id = ?');
                $chk->execute([$supersedes, $clinicId]);
                if ((int)$chk->fetchColumn() > 0) {
                    throw new DomainException('Este laudo já foi substituído. Corrija a versão mais recente.');
                }
            }
            $this->db->prepare("INSERT INTO exam_result_documents (clinic_id, patient_id, exam_request_id, uploaded_by_type,
                    uploaded_by_user_id, uploaded_by_patient_id, original_name, stored_path, mime_type, size_bytes, sha256,
                    security_check, laboratory_name, collected_at, report_issued_at, notes, status, reviewed_by_user_id,
                    reviewed_at, version_number, supersedes_document_id, root_document_id, correction_reason, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$clinicId, (int)$request['patient_id'], (int)$request['id'], $byType, $userId, $patientId,
                    $info['original_name'], $rel, $info['mime'], $info['size'], $info['sha256'], mb_substr($info['security_check'], 0, 255),
                    $meta['laboratory_name'], $meta['collected_at'], $meta['report_issued_at'], $meta['notes'], $status,
                    $reviewer, $reviewer !== null ? $now : null, (int)($meta['version_number'] ?? 1), $meta['supersedes_document_id'] ?? null,
                    $meta['root_document_id'] ?? null, $meta['correction_reason'] ?? null, $now]);
            $id = (int)$this->db->lastInsertId();
            $this->insertItems($id, $meta['item_ids'], $now);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($dest);
            throw $e;
        }
        return $id;
    }

    /** Valida e normaliza laboratório, datas (não futuras; laudo não antes da coleta), observação e exames cobertos. */
    private function cleanMeta(array $meta, array $request): array
    {
        $lab = mb_substr(trim((string)($meta['laboratory_name'] ?? '')), 0, 255, 'UTF-8');
        $notes = mb_substr(trim((string)($meta['notes'] ?? '')), 0, 1000, 'UTF-8');
        $collected = self::date($meta['collected_at'] ?? null, 'data da coleta');
        $issued = self::date($meta['report_issued_at'] ?? null, 'data do laudo');
        if ($collected !== null && $issued !== null && $issued < $collected) {
            throw new InvalidArgumentException('A data do laudo não pode ser antes da data da coleta.');
        }
        return [
            'laboratory_name' => $lab === '' ? null : $lab,
            'collected_at' => $collected,
            'report_issued_at' => $issued,
            'notes' => $notes === '' ? null : $notes,
            'item_ids' => $this->validItemIds((array)($meta['item_ids'] ?? []), $request),
        ];
    }

    private static function date(mixed $v, string $label): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new InvalidArgumentException("Informe a {$label} no formato dia/mês/ano.");
        }
        if ($v > date('Y-m-d')) {
            throw new InvalidArgumentException("A {$label} não pode ser no futuro.");
        }
        return $v;
    }

    /** Só ids de itens desta solicitação; o resto é descartado. */
    private function validItemIds(array $ids, array $request): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $st = $this->db->prepare('SELECT id FROM exam_request_items WHERE exam_request_id = ?');
        $st->execute([(int)$request['id']]);
        $valid = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        return array_values(array_filter($ids, static fn($id) => isset($valid[$id])));
    }

    private function insertItems(int $documentId, array $itemIds, string $now): void
    {
        $st = $this->db->prepare('INSERT INTO exam_result_document_items (document_id, exam_request_item_id, created_at) VALUES (?, ?, ?)');
        foreach ($itemIds as $itemId) {
            $st->execute([$documentId, (int)$itemId, $now]);
        }
    }

    /** @return array<int, list<array{exam_request_item_id:int, exam_canonical_name:string}>> */
    private function itemsFor(array $docIds): array
    {
        if ($docIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($docIds), '?'));
        $st = $this->db->prepare("SELECT di.document_id, di.exam_request_item_id, i.exam_canonical_name
            FROM exam_result_document_items di JOIN exam_request_items i ON i.id = di.exam_request_item_id
            WHERE di.document_id IN ({$in}) ORDER BY i.sort_order, i.id");
        $st->execute($docIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['document_id']][] = ['exam_request_item_id' => (int)$r['exam_request_item_id'], 'exam_canonical_name' => (string)$r['exam_canonical_name']];
        }
        return $out;
    }

    private function requireRequest(int $requestId, int $clinicId): array
    {
        $st = $this->db->prepare('SELECT id, clinic_id, patient_id, status, request_number FROM exam_requests WHERE id = ? AND clinic_id = ?');
        $st->execute([$requestId, $clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new DomainException('Solicitação de exames não encontrada nesta clínica.');
        }
        return $r;
    }

    private function requireIssuedRequest(int $requestId, int $clinicId): array
    {
        $r = $this->requireRequest($requestId, $clinicId);
        if ($r['status'] !== 'issued') {
            throw new DomainException('Laudos só podem ser anexados a uma solicitação emitida.');
        }
        return $r;
    }

    private static function decorate(array $row): array
    {
        $row['status_label'] = self::STATUS_LABELS[$row['status']] ?? (string)$row['status'];
        $row['is_superseded'] = !empty($row['superseded_by_id']);
        return $row;
    }

    private function audit(int $clinicId, int $userId, string $action, int $id, array $details): void
    {
        $this->db->prepare("INSERT INTO exam_audit_logs (clinic_id, user_id, action, entity_type, entity_id, ip_address, details, created_at)
            VALUES (?, ?, ?, 'exam_result_documents', ?, ?, ?, CURRENT_TIMESTAMP)")
            ->execute([$clinicId, $userId, $action, $id, $_SERVER['REMOTE_ADDR'] ?? null, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
        try {
            (new AuditLogger($this->db))->log($action, 'exam_result_document', (string)$id, $details);
        } catch (\Throwable $e) {
            error_log('EXAM_RESULT_AUDIT_MIRROR_FAILED ' . $action);
        }
    }

    private static function sign(string $kind, int $docId, int $subjectId, int $clinicId, int $ttl): string
    {
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('Abertura de laudos indisponível (configuração do servidor: APP_KEY).');
        }
        $body = $kind . '-' . $docId . '-' . $subjectId . '-' . $clinicId . '-' . (time() + $ttl);
        return $body . '-' . self::mac($body, $key);
    }

    private static function mac(string $body, string $key): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    }

    private static function key(): ?string
    {
        $appKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
        return strlen((string)$appKey) < 16 ? null : hash_hmac('sha256', 'clyonutri-laudo-exame-v1', (string)$appKey, true);
    }
}
