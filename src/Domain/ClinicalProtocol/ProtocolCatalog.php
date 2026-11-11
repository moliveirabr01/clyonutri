<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * NI2-635: governança editorial do catálogo de protocolos.
 *
 * Ciclo de uma versão: rascunho → em revisão → aprovada → aposentada.
 *  - Só o rascunho é editável. Mudou conteúdo de versão aprovada? Cria-se a versão seguinte.
 *  - Aprovar exige: referências, nutricionista aprovador com CRN, data de revisão (até 24 meses) e
 *    aprovador diferente do autor (quatro olhos). Protocolo de fase C ou marcado para especialista exige
 *    também o parecer da revisão especializada.
 *  - Aprovar uma versão aposenta a anterior. Tudo fica em clinical_protocol_events.
 *  - O conteúdo aprovado é conferido pelo hash a cada leitura: se alguém alterar o banco à mão, a versão
 *    deixa de ser usada (fail-closed).
 */
final class ProtocolCatalog
{
    public function __construct(private PDO $db)
    {
    }

    public function register(string $code, string $name, string $kind = 'condition', string $phase = 'A', bool $specialist = false): int
    {
        if (!preg_match('/^[a-z][a-z0-9_]{2,59}$/', $code)) {
            throw new InvalidArgumentException('Código do protocolo inválido.');
        }
        if (!in_array($kind, ['condition', 'profile'], true) || !in_array($phase, ['A', 'B', 'C'], true)) {
            throw new InvalidArgumentException('Tipo ou fase do protocolo inválidos.');
        }
        $st = $this->db->prepare('SELECT id FROM clinical_protocols WHERE code = ?');
        $st->execute([$code]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
        $this->db->prepare('INSERT INTO clinical_protocols (code, name, kind, catalog_phase, requires_specialist_review, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$code, trim($name), $kind, $phase, ($specialist || $phase === 'C') ? 1 : 0, self::now()]);
        return (int)$this->db->lastInsertId();
    }

    public function createDraft(int $protocolId, array $definition, string $references, ?int $authorId): int
    {
        $def = ProtocolDefinition::validate($definition);
        $st = $this->db->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM clinical_protocol_versions WHERE protocol_id = ?');
        $st->execute([$protocolId]);
        $v = (int)$st->fetchColumn();
        $this->db->prepare('INSERT INTO clinical_protocol_versions (protocol_id, version, status, definition_json, content_sha256, references_text, authored_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$protocolId, $v, 'draft', json_encode($def, JSON_UNESCAPED_UNICODE), ProtocolDefinition::hash($def), trim($references), $authorId, self::now()]);
        $id = (int)$this->db->lastInsertId();
        $this->event($id, 'created', $authorId, 'v' . $v);
        return $id;
    }

    public function updateDraft(int $versionId, array $definition, string $references, ?int $userId): void
    {
        $row = $this->version($versionId);
        if ($row['status'] !== 'draft') {
            throw new DomainException('Só rascunho pode ser editado. Para mudar uma versão aprovada, crie a próxima versão.');
        }
        $def = ProtocolDefinition::validate($definition);
        $this->db->prepare('UPDATE clinical_protocol_versions SET definition_json = ?, content_sha256 = ?, references_text = ? WHERE id = ? AND status = ?')
            ->execute([json_encode($def, JSON_UNESCAPED_UNICODE), ProtocolDefinition::hash($def), trim($references), $versionId, 'draft']);
        $this->event($versionId, 'edited', $userId, null);
    }

    public function submit(int $versionId, ?int $userId): void
    {
        $this->transition($versionId, 'draft', 'in_review', $userId, 'submitted', ', submitted_at = ?', [self::now()]);
    }

    public function approve(int $versionId, int $approverUserId, string $approverName, string $approverCrn, string $reviewDueAt, ?string $specialistNote = null): void
    {
        $this->db->beginTransaction();
        try {
            $row = $this->version($versionId);
            $lock = $this->db->prepare('SELECT id FROM clinical_protocols WHERE id = ?' . ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$row['protocol_id']]);
            $this->approveLocked($versionId, $approverUserId, $approverName, $approverCrn, $reviewDueAt, $specialistNote);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function approveLocked(int $versionId, int $approverUserId, string $approverName, string $approverCrn, string $reviewDueAt, ?string $specialistNote): void
    {
        $row = $this->version($versionId);
        if ($row['status'] !== 'in_review') {
            throw new DomainException('Só versão em revisão pode ser aprovada.');
        }
        if (trim((string)$row['references_text']) === '') {
            throw new DomainException('Aprovação exige referências clínicas na versão.');
        }
        if ((int)$row['authored_by_user_id'] === $approverUserId) {
            throw new DomainException('Quem escreveu a versão não pode aprová-la: a revisão precisa de outra pessoa habilitada.');
        }
        if (trim($approverName) === '' || !preg_match('/^CRN-(1[01]|[1-9])\s+[0-9A-Z\/.\-]{3,20}$/', trim($approverCrn))) {
            throw new InvalidArgumentException('Informe nome e CRN do aprovador (ex.: CRN-3 12345).');
        }
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $reviewDueAt);
        $today = new \DateTimeImmutable('today');
        if (!$due || $due <= $today || $due > $today->modify('+24 months')) {
            throw new InvalidArgumentException('Data de revisão deve ser futura e em até 24 meses.');
        }
        if ((int)$row['requires_specialist_review'] === 1 && trim((string)$specialistNote) === '') {
            throw new DomainException('Este protocolo exige parecer de revisão especializada antes da aprovação.');
        }
        $stored = json_decode((string)$row['definition_json'], true) ?: [];
        if (ProtocolDefinition::hash($stored) !== $row['content_sha256']) {
            throw new DomainException('O conteúdo da versão não confere com o registrado. Recrie a versão.');
        }
        $prev = $this->db->prepare("SELECT id FROM clinical_protocol_versions WHERE protocol_id = ? AND status = 'approved'");
        $prev->execute([$row['protocol_id']]);
        foreach ($prev->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $this->db->prepare("UPDATE clinical_protocol_versions SET status = 'retired', retired_at = ? WHERE id = ?")->execute([self::now(), $pid]);
            $this->event((int)$pid, 'retired', $approverUserId, 'substituída pela v' . $row['version']);
        }
        $this->db->prepare("UPDATE clinical_protocol_versions SET status = 'approved', approved_by_user_id = ?, approved_by_name = ?, approved_by_crn = ?, specialist_review_note = ?, approved_at = ?, review_due_at = ? WHERE id = ? AND status = 'in_review'")
            ->execute([$approverUserId, trim($approverName), trim($approverCrn), $specialistNote, self::now(), $reviewDueAt, $versionId]);
        $this->event($versionId, 'approved', $approverUserId, trim($approverName) . ' ' . trim($approverCrn));
    }

    public function retire(int $versionId, ?int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Informe o motivo da retirada.');
        }
        $this->transition($versionId, 'approved', 'retired', $userId, 'retired', ', retired_at = ?', [self::now()], $reason);
    }

    /**
     * Versão aprovada vigente de cada protocolo, com conteúdo conferido.
     * @return array<string, array{code:string,name:string,kind:string,version:int,version_id:int,definition:array,review_overdue:bool}>
     */
    public function approvedByCode(?int $clinicId = null): array
    {
        $scope = $clinicId === null ? 'p.clinic_id IS NULL' : '(p.clinic_id IS NULL OR p.clinic_id = ?)';
        $st = $this->db->prepare("SELECT p.code, p.name, p.kind, v.id, v.version, v.definition_json, v.content_sha256, v.review_due_at
            FROM clinical_protocols p JOIN clinical_protocol_versions v ON v.protocol_id = p.id AND v.status = 'approved' WHERE $scope ORDER BY p.code, v.version DESC");
        $st->execute($clinicId === null ? [] : [$clinicId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[$r['code']])) {
                continue;
            }
            $def = json_decode((string)$r['definition_json'], true);
            if (!is_array($def) || ProtocolDefinition::hash($def) !== $r['content_sha256']) {
                error_log('NI2-635: conteúdo do protocolo ' . $r['code'] . ' v' . $r['version'] . ' não confere com o hash; versão ignorada.');
                continue;
            }
            $out[$r['code']] = ['code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'version' => (int)$r['version'],
                'version_id' => (int)$r['id'], 'definition' => $def, 'review_overdue' => !empty($r['review_due_at']) && $r['review_due_at'] < date('Y-m-d')];
        }
        return $out;
    }

    public function version(int $versionId): array
    {
        $st = $this->db->prepare('SELECT v.*, p.requires_specialist_review, p.code FROM clinical_protocol_versions v JOIN clinical_protocols p ON p.id = v.protocol_id WHERE v.id = ?' . ($this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $st->execute([$versionId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new DomainException('Versão de protocolo não encontrada.');
        }
        return $r;
    }

    private function transition(int $versionId, string $from, string $to, ?int $userId, string $action, string $extraSet = '', array $extraArgs = [], ?string $note = null): void
    {
        $st = $this->db->prepare("UPDATE clinical_protocol_versions SET status = ?{$extraSet} WHERE id = ? AND status = ?");
        $st->execute(array_merge([$to], $extraArgs, [$versionId, $from]));
        if ($st->rowCount() !== 1) {
            throw new DomainException("A versão precisa estar em \"$from\" para esta ação.");
        }
        $this->event($versionId, $action, $userId, $note);
    }

    private function event(int $versionId, string $action, ?int $userId, ?string $note): void
    {
        $this->db->prepare('INSERT INTO clinical_protocol_events (protocol_version_id, action, actor_user_id, note, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$versionId, $action, $userId, $note, self::now()]);
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
