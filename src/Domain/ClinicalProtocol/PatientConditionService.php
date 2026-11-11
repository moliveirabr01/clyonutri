<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

use ClyoNutri\Core\Security\TenantGuard;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * NI2-635: condições do paciente com proveniência, e o que ativa um protocolo.
 *
 *  - Texto livre (queixa, observações, pré-consulta) só gera SUGESTÃO (status suggested, proveniência free_text).
 *    Sugestão nunca ativa protocolo e nunca vira diagnóstico sozinha.
 *  - O profissional confirma como relatada, em investigação (suspected) ou documentada (laudo), dizendo a origem
 *    (paciente, autorrelato, profissional, laudo). Só então o módulo pode aparecer.
 *  - Resolver ou descartar não apaga: cada mudança vira um evento (patient_condition_events).
 *  - Toda leitura e escrita é filtrada pela clínica; paciente de outra clínica é recusado.
 */
final class PatientConditionService
{
    public const ACTIVE = ['reported', 'suspected', 'documented'];
    public const STATUS_LABELS = [
        'suggested' => 'Sugestão a confirmar', 'reported' => 'Relatada', 'suspected' => 'Em investigação',
        'documented' => 'Documentada', 'resolved' => 'Resolvida', 'dismissed' => 'Descartada',
    ];
    public const PROVENANCE_LABELS = [
        'free_text' => 'Texto livre (sugestão)', 'patient' => 'Paciente (pré-consulta)', 'self_reported' => 'Autorrelato na consulta',
        'professional' => 'Avaliação do profissional', 'report' => 'Laudo ou exame',
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * Sugestões a partir de texto livre, pelos termos curados das versões APROVADAS. Não ativa nada.
     * @param array<string,array> $approved ProtocolCatalog::approvedByCode()
     * @return list<string> códigos sugeridos agora
     */
    public function suggestFromText(int $clinicId, int $patientId, string $text, array $approved, ?int $userId): array
    {
        return $this->suggestFromTextDetailed($clinicId, $patientId, $text, $approved, $userId)['made'];
    }

    /**
     * NI2-640: como suggestFromText, explicando o que foi ignorado (negação, outra pessoa) — regras de TermDetector.
     * @return array{made: list<string>, skipped: list<array{code:string,term:string,snippet:string,reason:string}>}
     */
    public function suggestFromTextDetailed(int $clinicId, int $patientId, string $text, array $approved, ?int $userId, string $provenance = 'free_text', ?string $context = null): array
    {
        if (!in_array($provenance, ['free_text', 'patient'], true)) throw new InvalidArgumentException('Origem da sugestão inválida.');
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $open = $this->openCodes($clinicId, $patientId);
        $made = [];
        $skipped = [];
        foreach ($approved as $code => $p) {
            if (isset($open[$code])) {
                continue;
            }
            $r = TermDetector::detect($text, array_values(array_filter((array)($p['definition']['suggestion_terms'] ?? []), 'is_string')));
            foreach ($r['skipped'] as $sk) {
                $skipped[] = ['code' => (string)$code] + $sk;
            }
            if ($r['match'] !== null) {
                $this->insert($clinicId, $patientId, $code, 'suggested', $provenance, mb_substr(($context ? $context . ' · ' : '') . TermDetector::explain($r['match']), 0, 500, 'UTF-8'), $userId, null);
                $made[] = $code;
            }
        }
        return ['made' => $made, 'skipped' => $skipped];
    }

    /** Profissional registra direto (já confirmada). */
    public function record(int $clinicId, int $patientId, string $code, string $status, string $provenance, int $userId, ?string $evidence = null, ?string $notedAt = null, ?string $validUntil = null): int
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        self::check($status, $provenance, $notedAt, $validUntil);
        if (isset($this->openCodes($clinicId, $patientId)[$code])) {
            throw new DomainException('Esta condição já está registrada para o paciente. Atualize o registro existente.');
        }
        return $this->insert($clinicId, $patientId, $code, $status, $provenance, $evidence, $userId, $userId, $notedAt, $validUntil);
    }

    /** Confirma uma sugestão (ou muda o status de uma condição ativa). */
    public function confirm(int $clinicId, int $conditionId, string $status, string $provenance, int $userId, ?string $note = null, ?string $notedAt = null, ?string $validUntil = null): void
    {
        self::check($status, $provenance, $notedAt, $validUntil);
        $row = $this->row($clinicId, $conditionId);
        if (in_array($row['status'], ['resolved', 'dismissed'], true)) {
            throw new DomainException('Condição encerrada não pode ser reaberta: registre de novo, com a origem atual.');
        }
        $this->db->prepare('UPDATE patient_conditions SET status = ?, provenance = ?, confirmed_by_user_id = ?, confirmed_at = ?, noted_at = COALESCE(?, noted_at), valid_until = ?, updated_at = ? WHERE id = ? AND clinic_id = ?')
            ->execute([$status, $provenance, $userId, self::now(), $notedAt, $validUntil, self::now(), $conditionId, $clinicId]);
        $this->event($clinicId, $conditionId, $row['status'], $status, $provenance, $userId, $note);
    }

    /** Descarta sugestão ou encerra condição (resolvida). */
    public function close(int $clinicId, int $conditionId, string $to, int $userId, string $note): void
    {
        if (!in_array($to, ['resolved', 'dismissed'], true)) {
            throw new InvalidArgumentException('Encerramento inválido.');
        }
        if (mb_strlen(trim($note), 'UTF-8') < 5) {
            throw new InvalidArgumentException('Escreva o motivo (pelo menos 5 letras).');
        }
        $row = $this->row($clinicId, $conditionId);
        $this->db->prepare('UPDATE patient_conditions SET status = ?, updated_at = ? WHERE id = ? AND clinic_id = ?')->execute([$to, self::now(), $conditionId, $clinicId]);
        $this->event($clinicId, $conditionId, $row['status'], $to, null, $userId, $note);
    }

    /** @return list<array> */
    public function list(int $clinicId, int $patientId): array
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare('SELECT * FROM patient_conditions WHERE clinic_id = ? AND patient_id = ? ORDER BY id');
        $st->execute([$clinicId, $patientId]);
        return array_map(static fn($r) => $r + ['status_label' => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'provenance_label' => self::PROVENANCE_LABELS[$r['provenance']] ?? $r['provenance']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Códigos que ativam módulo: confirmados por profissional, status ativo e dentro da validade. */
    public function activatingCodes(int $clinicId, int $patientId): array
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare("SELECT DISTINCT protocol_code FROM patient_conditions WHERE clinic_id = ? AND patient_id = ? AND status IN ('reported','suspected','documented')
            AND confirmed_by_user_id IS NOT NULL AND (valid_until IS NULL OR valid_until >= ?)");
        $st->execute([$clinicId, $patientId, date('Y-m-d')]);
        $dismissed = $this->dismissedModules($clinicId, $patientId); // NI2-642
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), static fn($c) => !isset($dismissed[$c])));
    }

    public function history(int $clinicId, int $conditionId): array
    {
        $this->row($clinicId, $conditionId);
        $st = $this->db->prepare('SELECT * FROM patient_condition_events WHERE clinic_id = ? AND condition_id = ? ORDER BY id');
        $st->execute([$clinicId, $conditionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * NI2-642: dispensar o módulo de uma condição ativa, com motivo (a condição continua registrada). Fica no histórico
     * da condição como evento module_dismissed; reativar grava module_restored. Sem mudança de schema.
     */
    public function dismissModule(int $clinicId, int $patientId, string $code, int $userId, string $reason): void
    {
        if (mb_strlen(trim($reason), 'UTF-8') < 5) {
            throw new InvalidArgumentException('Escreva o motivo da dispensa (pelo menos 5 letras).');
        }
        $this->moduleEvent($clinicId, $patientId, $code, 'module_dismissed', $userId, trim($reason));
    }

    public function restoreModule(int $clinicId, int $patientId, string $code, int $userId): void
    {
        $this->moduleEvent($clinicId, $patientId, $code, 'module_restored', $userId, null);
    }

    /** @return array<string,array{note:?string,at:string,actor_user_id:?int}> módulos dispensados (pelas condições ativas) */
    public function dismissedModules(int $clinicId, int $patientId): array
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare("SELECT c.protocol_code, e.to_status, e.note, e.created_at, e.actor_user_id FROM patient_condition_events e
            JOIN patient_conditions c ON c.id = e.condition_id AND c.clinic_id = e.clinic_id
            WHERE c.clinic_id = ? AND c.patient_id = ? AND c.status IN ('reported','suspected','documented') AND e.to_status IN ('module_dismissed','module_restored')
            ORDER BY e.id");
        $st->execute([$clinicId, $patientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['to_status'] === 'module_dismissed') {
                $out[$r['protocol_code']] = ['note' => $r['note'], 'at' => $r['created_at'], 'actor_user_id' => $r['actor_user_id'] !== null ? (int)$r['actor_user_id'] : null];
            } else {
                unset($out[$r['protocol_code']]);
            }
        }
        return $out;
    }

    private function moduleEvent(int $clinicId, int $patientId, string $code, string $to, int $userId, ?string $note): void
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare("SELECT id, status FROM patient_conditions WHERE clinic_id = ? AND patient_id = ? AND protocol_code = ? AND status IN ('reported','suspected','documented') AND confirmed_by_user_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$clinicId, $patientId, $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Não há condição confirmada que ative este módulo.');
        }
        $isDismissed = isset($this->dismissedModules($clinicId, $patientId)[$code]);
        if (($to === 'module_dismissed') === $isDismissed) {
            throw new DomainException($isDismissed ? 'Este módulo já está dispensado.' : 'Este módulo já está ativo.');
        }
        $this->event($clinicId, (int)$row['id'], $row['status'], $to, null, $userId, $note);
    }

    private static function check(string $status, string $provenance, ?string $notedAt, ?string $validUntil): void
    {
        if (!in_array($status, self::ACTIVE, true)) {
            throw new InvalidArgumentException('Status deve ser relatada, em investigação ou documentada.');
        }
        if (!in_array($provenance, ['patient', 'self_reported', 'professional', 'report'], true)) {
            throw new InvalidArgumentException('Informe a origem: paciente, autorrelato, profissional ou laudo.');
        }
        if ($status === 'documented' && $provenance !== 'report' && $provenance !== 'professional') {
            throw new InvalidArgumentException('Condição documentada precisa de laudo ou avaliação profissional como origem.');
        }
        foreach ([$notedAt, $validUntil] as $d) {
            if ($d !== null && (!($x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d)) || $x->format('Y-m-d') !== $d)) {
                throw new InvalidArgumentException('Data inválida.');
            }
        }
        if ($notedAt !== null && $notedAt > date('Y-m-d')) {
            throw new InvalidArgumentException('A data da informação não pode ser futura.');
        }
    }

    private function openCodes(int $clinicId, int $patientId): array
    {
        $st = $this->db->prepare("SELECT protocol_code FROM patient_conditions WHERE clinic_id = ? AND patient_id = ? AND status NOT IN ('resolved','dismissed')");
        $st->execute([$clinicId, $patientId]);
        return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    }

    private function row(int $clinicId, int $conditionId): array
    {
        $st = $this->db->prepare('SELECT * FROM patient_conditions WHERE id = ? AND clinic_id = ?');
        $st->execute([$conditionId, $clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new DomainException('Condição não encontrada.'); // inclusive de outra clínica: mesma resposta
        }
        return $r;
    }

    private function insert(int $clinicId, int $patientId, string $code, string $status, string $prov, ?string $evidence, ?int $createdBy, ?int $confirmedBy, ?string $notedAt = null, ?string $validUntil = null): int
    {
        $now = self::now();
        $this->db->prepare('INSERT INTO patient_conditions (clinic_id, patient_id, protocol_code, status, provenance, evidence_text, noted_at, valid_until, created_by_user_id, confirmed_by_user_id, confirmed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$clinicId, $patientId, $code, $status, $prov, $evidence !== null ? mb_substr($evidence, 0, 500, 'UTF-8') : null, $notedAt ?? date('Y-m-d'), $validUntil, $createdBy, $confirmedBy, $confirmedBy ? $now : null, $now, $now]);
        $id = (int)$this->db->lastInsertId();
        $this->event($clinicId, $id, null, $status, $prov, $createdBy, $evidence);
        return $id;
    }

    private function event(int $clinicId, int $conditionId, ?string $from, string $to, ?string $prov, ?int $userId, ?string $note): void
    {
        $this->db->prepare('INSERT INTO patient_condition_events (clinic_id, condition_id, from_status, to_status, provenance, actor_user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$clinicId, $conditionId, $from, $to, $prov, $userId, $note !== null ? mb_substr($note, 0, 500, 'UTF-8') : null, self::now()]);
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
