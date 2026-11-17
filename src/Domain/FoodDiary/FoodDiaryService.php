<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\FoodDiary;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

class FoodDiaryService
{
    public const DEFAULT_LIMIT = 30;
    public const MAX_LIMIT = 100;
    public const FEEDBACK_MAX_LENGTH = 2000;
    /** Registros anteriores a esta data são tratados como erro de digitação do aparelho. */
    public const MIN_CONSUMED_AT = '2000-01-01 00:00:00';
    /** Tolerância para relógio adiantado do aparelho. */
    public const FUTURE_TOLERANCE_SECONDS = 300;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * NI2-89 / NI2-132: valida a entrada do diário antes de gravar (erro de validação vira 422, nunca 500).
     * Devolve os campos já normalizados; consumed_at sai no horário do consultório (APP_TIMEZONE) ou null.
     */
    private function validateMealEntry(array $data): array
    {
        $name = $data['meal_name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new \InvalidArgumentException('Informe a refeição (meal_name).');
        }
        if (mb_strlen(trim($name), 'UTF-8') > 100) {
            throw new \InvalidArgumentException('O nome da refeição deve ter no máximo 100 caracteres.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name) === 1) {
            throw new \InvalidArgumentException('O nome da refeição tem caracteres inválidos.');
        }
        foreach (['description' => 2000, 'symptoms' => 1000, 'photo_path' => 255] as $field => $max) {
            if (!isset($data[$field])) continue;
            if (!is_string($data[$field])) throw new \InvalidArgumentException("Campo {$field} inválido.");
            if (mb_strlen($data[$field], 'UTF-8') > $max) throw new \InvalidArgumentException("O campo {$field} deve ter no máximo {$max} caracteres.");
        }
        // Caminho de foto: relativo ou dentro de /uploads/diary/, sem "..", nunca um caminho do servidor (/etc/passwd) nem URL.
        if (isset($data['photo_path']) && $data['photo_path'] !== '') {
            $p = $data['photo_path'];
            if (preg_match('#^(?:/uploads/diary/|(?!/))(?!.*\.\.)[A-Za-z0-9_\-./]+$#', $p) !== 1) {
                throw new \InvalidArgumentException('Caminho de foto inválido (photo_path).');
            }
        } else {
            unset($data['photo_path']);
        }
        foreach (['hunger_level', 'satiety_level'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') { unset($data[$field]); continue; }
            if (!is_numeric($data[$field]) || (int)$data[$field] != $data[$field] || (int)$data[$field] < 1 || (int)$data[$field] > 5) {
                throw new \InvalidArgumentException("O campo {$field} deve ser um número de 1 a 5.");
            }
        }

        $tzName = null;
        if (isset($data['timezone']) && $data['timezone'] !== '') {
            if (!is_string($data['timezone']) || !in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException('Fuso horário inválido (timezone). Use um nome como America/Sao_Paulo.');
            }
            $tzName = $data['timezone'];
        }
        $data['consumed_tz'] = $tzName;
        if (isset($data['consumed_at']) && $data['consumed_at'] !== '') {
            [$local, $offset] = $this->normalizeConsumedAt($data['consumed_at'], $tzName);
            $data['consumed_at'] = $local;
            if ($tzName === null && $offset !== null) {
                $data['consumed_tz'] = $offset;
            }
        } else {
            unset($data['consumed_at']);
        }
        if (isset($data['idempotency_key']) && !is_string($data['idempotency_key'])) {
            throw new \InvalidArgumentException('Chave de idempotência inválida.');
        }
        return $data;
    }

    /** @return array{0:string,1:?string} [data/hora no horário do consultório, deslocamento enviado ou null] */
    private function normalizeConsumedAt(mixed $raw, ?string $tzName): array
    {
        $invalid = 'Data e hora da refeição inválidas (consumed_at). Use o formato 2026-10-24T12:30:00-03:00.';
        if (!is_string($raw) || strlen($raw) > 40
            || preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i', trim($raw), $m) !== 1) {
            throw new \InvalidArgumentException($invalid);
        }
        $offset = isset($m[1]) && $m[1] !== '' ? (strtoupper($m[1]) === 'Z' ? '+00:00' : (strlen($m[1]) === 5 ? substr($m[1], 0, 3) . ':' . substr($m[1], 3) : $m[1])) : null;
        $server = new \DateTimeZone(date_default_timezone_get());
        try {
            $dt = new \DateTimeImmutable(trim($raw), new \DateTimeZone($tzName ?? date_default_timezone_get()));
        } catch (\Throwable) {
            throw new \InvalidArgumentException($invalid);
        }
        $err = \DateTimeImmutable::getLastErrors();
        if (is_array($err) && ($err['warning_count'] > 0 || $err['error_count'] > 0)) {
            throw new \InvalidArgumentException($invalid);
        }
        $ts = $dt->getTimestamp();
        if ($ts < strtotime(self::MIN_CONSUMED_AT . ' UTC')) {
            throw new \InvalidArgumentException('Data da refeição inválida: anterior a 2000.');
        }
        if ($ts > time() + self::FUTURE_TOLERANCE_SECONDS) {
            throw new \InvalidArgumentException('A refeição não pode estar registrada no futuro.');
        }
        return [$dt->setTimezone($server)->format('Y-m-d H:i:s'), $offset];
    }

    private function hasHardeningColumns(): bool
    {
        return SchemaInfo::hasColumn($this->db, 'food_diary_entries', 'request_hash')
            && SchemaInfo::hasColumn($this->db, 'food_diary_entries', 'consumed_tz');
    }

    /** SHA-256 do conteúdo que o paciente enviou (sem a hora do servidor quando consumed_at foi omitido). */
    private function payloadHash(array $d): string
    {
        return hash('sha256', json_encode([
            trim((string)$d['meal_name']),
            $d['description'] ?? null,
            $d['symptoms'] ?? null,
            isset($d['hunger_level']) ? (int)$d['hunger_level'] : null,
            isset($d['satiety_level']) ? (int)$d['satiety_level'] : null,
            $d['consumed_at'] ?? null,
            $d['consumed_tz'] ?? null,
            $d['photo_path'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** O registro gravado com esta chave é o mesmo conteúdo que está chegando agora? */
    private function sameContent(array $row, array $d, string $hash): bool
    {
        if (!empty($row['request_hash'])) {
            return hash_equals((string)$row['request_hash'], $hash);
        }
        // Registro anterior à migração 53: compara campo a campo.
        $nz = static fn($v) => $v === '' ? null : $v;
        return (string)$row['meal_name'] === trim((string)$d['meal_name'])
            && $nz($row['description'] ?? null) === $nz($d['description'] ?? null)
            && $nz($row['symptoms'] ?? null) === $nz($d['symptoms'] ?? null)
            && (isset($row['hunger_level']) ? (int)$row['hunger_level'] : null) === (isset($d['hunger_level']) ? (int)$d['hunger_level'] : null)
            && (isset($row['satiety_level']) ? (int)$row['satiety_level'] : null) === (isset($d['satiety_level']) ? (int)$d['satiety_level'] : null)
            && (!isset($d['consumed_at']) || (string)$row['consumed_at'] === (string)$d['consumed_at']);
    }

    /** Campos que a paciente pode ver; nada interno (clinic_id, chave, hash, caminho de arquivo). */
    private function patientEntry(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'patient_id' => (int)$r['patient_id'],
            'meal_name' => $r['meal_name'],
            'consumed_at' => $r['consumed_at'],
            'consumed_tz' => $r['consumed_tz'] ?? null,
            'description' => $r['description'] ?? null,
            'hunger_level' => isset($r['hunger_level']) ? (int)$r['hunger_level'] : null,
            'satiety_level' => isset($r['satiety_level']) ? (int)$r['satiety_level'] : null,
            'symptoms' => $r['symptoms'] ?? null,
            'feedback_from_professional' => $r['feedback_from_professional'] ?? null,
            'reviewed_at' => $r['reviewed_at'] ?? null,
        ];
    }

    private function professionalEntry(array $r): array
    {
        return ['patient_name' => $r['patient_name']] + $this->patientEntry($r);
    }

    private function findByKey(int $clinicId, int $patientId, string $key): ?array
    {
        $st = $this->db->prepare('SELECT * FROM food_diary_entries WHERE clinic_id = :c AND patient_id = :p AND idempotency_key = :k LIMIT 1');
        $st->execute(['c' => $clinicId, 'p' => $patientId, 'k' => $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Registro de refeição pelo paciente.
     * Devolve a entrada gravada + idempotent_replay (true quando a mesma chave e o mesmo conteúdo já existiam).
     * @throws \InvalidArgumentException conteúdo inválido (422)
     * @throws IdempotencyKeyReusedException mesma chave com conteúdo diferente (409)
     */
    public function logMeal(int $patientId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $data = $this->validateMealEntry($data);
        $idempotencyKey = trim((string)($data['idempotency_key'] ?? '')) ?: null;
        if ($idempotencyKey !== null && strlen($idempotencyKey) > 64) {
            throw new \InvalidArgumentException('Chave de idempotência inválida.');
        }
        $hash = $this->payloadHash($data);
        $replay = function () use ($clinicId, $patientId, $idempotencyKey, $data, $hash): ?array {
            $existing = $this->findByKey($clinicId, $patientId, (string)$idempotencyKey);
            if ($existing === null) {
                return null;
            }
            if (!$this->sameContent($existing, $data, $hash)) {
                throw new IdempotencyKeyReusedException('Esta chave de idempotência já foi usada com outro conteúdo. Gere uma chave nova para registrar uma refeição diferente.');
            }
            return $this->patientEntry($existing) + ['idempotent_replay' => true];
        };
        if ($idempotencyKey !== null && ($found = $replay()) !== null) {
            return $found;
        }

        $columns = ['clinic_id', 'patient_id', 'meal_name', 'consumed_at', 'photo_path', 'description', 'hunger_level', 'satiety_level', 'symptoms', 'idempotency_key'];
        $values = [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'meal_name' => trim($data['meal_name']),
            'consumed_at' => $data['consumed_at'] ?? date('Y-m-d H:i:s'),
            'photo_path' => $data['photo_path'] ?? null,
            'description' => $data['description'] ?? null,
            'hunger_level' => isset($data['hunger_level']) ? (int)$data['hunger_level'] : null,
            'satiety_level' => isset($data['satiety_level']) ? (int)$data['satiety_level'] : null,
            'symptoms' => $data['symptoms'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ];
        if ($this->hasHardeningColumns()) { // tolera código novo antes da migração 53
            $columns[] = 'request_hash';
            $columns[] = 'consumed_tz';
            $values['request_hash'] = $hash;
            $values['consumed_tz'] = $data['consumed_tz'] ?? null;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO food_diary_entries (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')'
        );

        try {
            $stmt->execute($values);
        } catch (\PDOException $e) {
            // O índice único arbitra requisições concorrentes: reenvia a resposta do registro vencedor (ou 409).
            if ($idempotencyKey !== null && ($found = $replay()) !== null) {
                return $found;
            }
            throw $e;
        }

        $id = (int)$this->db->lastInsertId();
        $row = $this->db->prepare('SELECT * FROM food_diary_entries WHERE id = :id AND clinic_id = :c');
        $row->execute(['id' => $id, 'c' => $clinicId]);

        // NI2-880: notifica o(s) profissional(is) da clínica na caixa de Avisos (/avisos), sem bloquear o registro.
        try {
            $notif = new \ClyoNutri\Domain\Notification\NotificationService($this->db);
            if ($notif->available()) {
                $profStmt = $this->db->prepare(
                    "SELECT DISTINCT p.id FROM professionals p
                     JOIN clinic_members cm ON cm.user_id = p.user_id AND cm.clinic_id = :c AND cm.status = 'active'
                     WHERE cm.role IN ('owner', 'admin', 'nutritionist')"
                );
                $profStmt->execute(['c' => $clinicId]);
                foreach ($profStmt->fetchAll(PDO::FETCH_COLUMN) as $profId) {
                    $notif->publish(
                        $clinicId,
                        'food_diary.entry_created',
                        'professional',
                        (int)$profId,
                        'food_diary:' . $id . ':' . (int)$profId,
                        '/pacientes/' . $patientId . '/diario'
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][food-diary] aviso de novo registro falhou: ' . get_class($e));
        }

        return $this->patientEntry($row->fetch(PDO::FETCH_ASSOC)) + ['idempotent_replay' => false];
    }

    /**
     * Feedback clínico do nutricionista na refeição (substitui o anterior; a auditoria registra a troca).
     * Devolve o registro (visão do profissional) ou null se não existir nesta clínica.
     * @throws \InvalidArgumentException feedback vazio ou maior que FEEDBACK_MAX_LENGTH (422)
     */
    public function submitProfessionalFeedback(int $entryId, string $feedback): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $feedback = trim($feedback);
        if ($feedback === '') {
            throw new \InvalidArgumentException('Escreva a mensagem de feedback.');
        }
        if (mb_strlen($feedback, 'UTF-8') > self::FEEDBACK_MAX_LENGTH) {
            throw new \InvalidArgumentException('O feedback deve ter no máximo ' . self::FEEDBACK_MAX_LENGTH . ' caracteres.');
        }
        $sel = $this->db->prepare('SELECT f.*, p.name AS patient_name FROM food_diary_entries f JOIN patients p ON p.id = f.patient_id WHERE f.id = :id AND f.clinic_id = :c');
        $sel->execute(['id' => $entryId, 'c' => $clinicId]);
        $before = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            return null;
        }
        $this->db->prepare('UPDATE food_diary_entries SET feedback_from_professional = :f, reviewed_at = :now WHERE id = :id AND clinic_id = :c')
            // reviewed_at no horário do consultório, como consumed_at (registros antigos ficaram em UTC).
            ->execute(['f' => $feedback, 'now' => date('Y-m-d H:i:s'), 'id' => $entryId, 'c' => $clinicId]);
        $sel->execute(['id' => $entryId, 'c' => $clinicId]);
        $after = $sel->fetch(PDO::FETCH_ASSOC);
        return $this->professionalEntry($after) + [
            '_replaced' => trim((string)($before['feedback_from_professional'] ?? '')) !== '',
        ];
    }

    /** Compatibilidade: devolve só se gravou. */
    public function addProfessionalFeedback(int $entryId, string $feedback): bool
    {
        return $this->submitProfessionalFeedback($entryId, $feedback) !== null;
    }

    /**
     * Linha do tempo completa (uso interno e testes antigos). A API usa listPatientEntries (paginada, só campos públicos).
     */
    public function getPatientTimeline(int $patientId, ?string $fromDate = null): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $sql = "
            SELECT * FROM food_diary_entries
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ";
        $params = ['patient_id' => $patientId, 'clinic_id' => $clinicId];

        if ($fromDate) {
            $sql .= " AND consumed_at >= :from_date";
            $params['from_date'] = $fromDate;
        }

        $sql .= " ORDER BY consumed_at DESC, id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function clampLimit(?int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit ?? self::DEFAULT_LIMIT));
    }

    /** @return array{0:string,1:int} */
    private function decodeCursor(string $cursor): array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,12})$/', $raw, $m) !== 1) {
            throw new \InvalidArgumentException('Cursor inválido.');
        }
        return [$m[1], (int)$m[2]];
    }

    private function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode($row['consumed_at'] . '|' . (int)$row['id']), '+/', '-_'), '=');
    }

    private function dayBound(?string $date, bool $end): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $e = \DateTimeImmutable::getLastErrors();
        if (!$d || $d->format('Y-m-d') !== $date || (is_array($e) && ($e['warning_count'] > 0 || $e['error_count'] > 0))) {
            throw new \InvalidArgumentException('Data inválida. Use o formato AAAA-MM-DD.');
        }
        return ($end ? $d->modify('+1 day') : $d)->format('Y-m-d H:i:s');
    }

    /**
     * Filtros comuns (cursor, período) para as duas listas.
     * @param array{from?:?string,to?:?string,cursor?:?string} $opts
     */
    private function applyListFilters(string &$sql, array &$params, array $opts, string $col): void
    {
        if (($from = $this->dayBound($opts['from'] ?? null, false)) !== null) {
            $sql .= " AND {$col}.consumed_at >= :from_at";
            $params['from_at'] = $from;
        }
        if (($to = $this->dayBound($opts['to'] ?? null, true)) !== null) {
            $sql .= " AND {$col}.consumed_at < :to_at";
            $params['to_at'] = $to;
        }
        if (!empty($opts['cursor'])) {
            [$at, $id] = $this->decodeCursor((string)$opts['cursor']);
            $sql .= " AND ({$col}.consumed_at < :cur_at1 OR ({$col}.consumed_at = :cur_at2 AND {$col}.id < :cur_id))";
            $params['cur_at1'] = $at;
            $params['cur_at2'] = $at;
            $params['cur_id'] = $id;
        }
    }

    /**
     * Diário da própria paciente: campos públicos, paginado por cursor (mais recente primeiro).
     * @param array{limit?:?int,cursor?:?string,from?:?string,to?:?string} $opts
     * @return array{entries:array,next_cursor:?string,has_more:bool}
     */
    public function listPatientEntries(int $patientId, array $opts = []): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $limit = $this->clampLimit($opts['limit'] ?? null);
        $cols = 'f.id, f.patient_id, f.meal_name, f.consumed_at, f.description, f.hunger_level, f.satiety_level, f.symptoms, f.feedback_from_professional, f.reviewed_at'
            . ($this->hasHardeningColumns() ? ', f.consumed_tz' : '');
        $sql = "SELECT {$cols} FROM food_diary_entries f WHERE f.patient_id = :patient_id AND f.clinic_id = :clinic_id";
        $params = ['patient_id' => $patientId, 'clinic_id' => $clinicId];
        $this->applyListFilters($sql, $params, $opts, 'f');
        $sql .= ' ORDER BY f.consumed_at DESC, f.id DESC LIMIT ' . ($limit + 1);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'entries' => array_map(fn($r) => $this->patientEntry($r), $rows),
            'next_cursor' => $more && $rows ? $this->encodeCursor($rows[count($rows) - 1]) : null,
            'has_more' => $more,
        ];
    }

    /**
     * Diários da clínica para o profissional, com filtros e cursor.
     * Não traz e-mail, chave de idempotência, clinic_id nem caminho de foto. Pacientes anonimizados ficam de fora
     * (o texto livre deles é apagado na anonimização) — a mesma regra de countPendingDiaries.
     * @param array{patient_id?:?int,pending?:bool,limit?:?int,cursor?:?string,from?:?string,to?:?string} $opts
     * @return array{diaries:array,next_cursor:?string,has_more:bool,pending_total:int}
     */
    public function listClinicEntries(int $clinicId, array $opts = []): array
    {
        $limit = $this->clampLimit($opts['limit'] ?? null);
        $patientId = $opts['patient_id'] ?? null;
        if ($patientId !== null) {
            \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic((int)$patientId, $clinicId, $this->db);
        }
        $cols = 'f.id, f.patient_id, p.name AS patient_name, f.meal_name, f.consumed_at, f.description, f.hunger_level, f.satiety_level, f.symptoms, f.feedback_from_professional, f.reviewed_at'
            . ($this->hasHardeningColumns() ? ', f.consumed_tz' : '');
        $sql = "SELECT {$cols} FROM food_diary_entries f JOIN patients p ON p.id = f.patient_id AND p.clinic_id = f.clinic_id
            WHERE f.clinic_id = :clinic_id AND p.status <> 'anonymized'";
        $params = ['clinic_id' => $clinicId];
        if ($patientId !== null) {
            $sql .= ' AND f.patient_id = :patient_id';
            $params['patient_id'] = (int)$patientId;
        }
        if (!empty($opts['pending'])) {
            $sql .= " AND (f.feedback_from_professional IS NULL OR f.feedback_from_professional = '')";
        }
        $this->applyListFilters($sql, $params, $opts, 'f');
        $sql .= ' ORDER BY f.consumed_at DESC, f.id DESC LIMIT ' . ($limit + 1);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'diaries' => array_map(fn($r) => $this->professionalEntry($r), $rows),
            'next_cursor' => $more && $rows ? $this->encodeCursor($rows[count($rows) - 1]) : null,
            'has_more' => $more,
            'pending_total' => $this->countPendingDiaries($clinicId, $patientId !== null ? (int)$patientId : null),
        ];
    }

    /** Compatibilidade: primeira página, mesmos campos reduzidos. */
    public function getClinicDiaries(int $clinicId, int $limit = 50): array
    {
        return $this->listClinicEntries($clinicId, ['limit' => $limit])['diaries'];
    }

    /**
     * Total de registros pendentes de feedback; mesma regra e mesmo universo da lista do profissional.
     */
    public function countPendingDiaries(int $clinicId, ?int $patientId = null): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM food_diary_entries f
            JOIN patients p ON p.id = f.patient_id AND p.clinic_id = f.clinic_id
            WHERE f.clinic_id = :clinic_id AND p.status <> 'anonymized'
              AND (f.feedback_from_professional IS NULL OR f.feedback_from_professional = '')
        ";
        $params = ['clinic_id' => $clinicId];
        if ($patientId !== null) {
            $sql .= ' AND f.patient_id = :patient_id';
            $params['patient_id'] = $patientId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int)($row['total'] ?? 0);
    }

    /**
     * Auditoria de leitura do diário pelo profissional (dado de saúde): quem, qual paciente, quantos registros.
     * Falha na auditoria não derruba a leitura, mas vai para o log do servidor.
     */
    public function auditProfessionalRead(int $clinicId, int $userId, ?int $patientId, array $details): void
    {
        try {
            $this->db->prepare("
                INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                VALUES (:cid, :uid, 'FOOD_DIARY_VIEWED', 'food_diary', :rid, :ip, :ua, :details)
            ")->execute([
                'cid' => $clinicId,
                'uid' => $userId,
                'rid' => $patientId !== null ? (string)$patientId : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][food-diary] auditoria de leitura falhou: ' . get_class($e));
        }
    }

    /**
     * NI2-880: registra envio de devolutiva clínica em audit_logs.
     */
    public function auditFeedbackSent(int $clinicId, int $userId, int $patientId, int $entryId, bool $replaced = false): void
    {
        try {
            $this->db->prepare("
                INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
                VALUES (:cid, :uid, 'FOOD_DIARY_FEEDBACK_SENT', 'food_diary', :rid, :ip, :ua, :details)
            ")->execute([
                'cid' => $clinicId,
                'uid' => $userId,
                'rid' => (string)$entryId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Web'), 0, 255),
                'details' => json_encode(['patient_id' => $patientId, 'replaced' => $replaced], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][food-diary] auditoria de devolutiva falhou: ' . get_class($e));
        }
    }

    /**
     * NI2-880: visão web do diário do paciente para Preparar consulta e aba Atendimento.
     * Reusa listPatientEntries() e countPendingDiaries(), calcula adesão dos check-ins no período
     * e registra FOOD_DIARY_VIEWED em audit_logs.
     */
    public function professionalWebPanel(int $patientId, int $userId, ?string $fromDate = null, ?string $toDate = null, bool $showAll = false): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $sinceDefault = null;
        if (!$showAll && ($fromDate === null || trim($fromDate) === '')) {
            $lcStmt = $this->db->prepare(
                "SELECT COALESCE(MAX(h.created_at), MAX(a.scheduled_at)) AS ref_dt
                 FROM appointments a
                 LEFT JOIN appointment_status_history h ON h.appointment_id = a.id AND h.to_status = 'completed'
                 WHERE a.clinic_id = ? AND a.patient_id = ? AND a.status = 'completed'"
            );
            $lcStmt->execute([$clinicId, $patientId]);
            $lastRef = $lcStmt->fetchColumn();
            $sinceDefault = $lastRef ? substr((string)$lastRef, 0, 10) : date('Y-m-d', strtotime('-30 days'));
            $fromDate = $sinceDefault;
        }

        $opts = ['limit' => 50];
        if ($fromDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fromDate))) {
            $opts['from'] = trim($fromDate);
        } else {
            $fromDate = null;
        }
        if ($toDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($toDate))) {
            $opts['to'] = trim($toDate);
        } else {
            $toDate = null;
        }

        $list = $this->listPatientEntries($patientId, $opts);
        $entries = $list['entries'];

        // Enriquecer com photo_path apenas quando houver (para exibição segura na web do profissional)
        if ($entries !== []) {
            $eIds = array_map(static fn(array $e): int => (int)$e['id'], $entries);
            $ph = implode(',', array_fill(0, count($eIds), '?'));
            $pStmt = $this->db->prepare("SELECT id, photo_path FROM food_diary_entries WHERE clinic_id = ? AND patient_id = ? AND id IN ({$ph})");
            $pStmt->execute(array_merge([$clinicId, $patientId], $eIds));
            $photoMap = [];
            foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                if (!empty($pr['photo_path'])) {
                    $photoMap[(int)$pr['id']] = (string)$pr['photo_path'];
                }
            }
            foreach ($entries as &$entry) {
                $entry['photo_path'] = $photoMap[(int)$entry['id']] ?? null;
            }
            unset($entry);
        }

        // Adesão média dos check-ins no período
        $adhSql = 'SELECT COUNT(*) AS total_checkins, AVG(meal_adherence_pct) AS avg_adherence FROM checkins WHERE clinic_id = ? AND patient_id = ?';
        $adhParams = [$clinicId, $patientId];
        if ($fromDate !== null) {
            $adhSql .= ' AND checkin_date >= ?';
            $adhParams[] = $fromDate;
        }
        if ($toDate !== null) {
            $adhSql .= ' AND checkin_date <= ?';
            $adhParams[] = $toDate;
        }
        $adhStmt = $this->db->prepare($adhSql);
        $adhStmt->execute($adhParams);
        $adhRow = $adhStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $avgAdherence = isset($adhRow['avg_adherence']) && $adhRow['avg_adherence'] !== null
            ? (int)round((float)$adhRow['avg_adherence'])
            : null;

        $pendingCount = $this->countPendingDiaries($clinicId, $patientId);
        $this->auditProfessionalRead($clinicId, $userId, $patientId, [
            'source' => 'web_panel',
            'count' => count($entries),
            'from' => $fromDate,
            'to' => $toDate,
        ]);

        return [
            'entries' => $entries,
            'count' => count($entries),
            'pending_count' => $pendingCount,
            'from' => $fromDate,
            'to' => $toDate,
            'show_all' => $showAll,
            'since_label' => $fromDate ? date('d/m', strtotime($fromDate)) : 'o início',
            'avg_adherence_pct' => $avgAdherence,
            'checkins_count' => (int)($adhRow['total_checkins'] ?? 0),
        ];
    }
}
