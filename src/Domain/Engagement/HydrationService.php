<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Engagement;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\TenantGuard;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Registro de hidratação da paciente (NI2-92).
 *
 * Regras:
 * - Volume por registro: inteiro de 1 a 5000 ml. Total do dia: até HYDRATION_DAILY_MAX_ML (padrão 10000 ml).
 * - Dia e horário no fuso da aplicação (APP_TIMEZONE, America/Sao_Paulo). logged_at é gravado nesse fuso,
 *   e o dia de um registro é a data local dele (21h–23h59 contam para o próprio dia, não para o seguinte).
 * - logged_at opcional: no máximo 5 minutos no futuro e no mínimo desde 00h00 de 7 dias atrás
 *   (HYDRATION_BACKDATE_DAYS).
 * - Idempotência: a mesma idempotency_key (toque + reenvios) grava uma única linha; repetir com volume
 *   diferente é recusado (409).
 * - Edição e exclusão: só a própria paciente, só registros feitos por ela no app e até 24 h depois de criados.
 *   Exclusão é lógica (deleted_at). Criação, edição e exclusão vão para audit_logs.
 */
class HydrationService
{
    public const MIN_ML = 1;
    public const MAX_ML = 5000;
    public const DEFAULT_DAILY_MAX_ML = 10000;
    public const DEFAULT_BACKDATE_DAYS = 7;
    public const FUTURE_TOLERANCE_SECONDS = 300;
    public const EDIT_WINDOW_HOURS = 24;
    public const HISTORY_DEFAULT_DAYS = 7;
    public const HISTORY_MAX_DAYS = 31;

    private PDO $db;
    /** @var callable|null */
    private $clock;

    public function __construct(?PDO $db = null, ?callable $clock = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->clock = $clock;
    }

    // ------------------------------------------------------------------ configuração

    public static function dailyMaxMl(): int
    {
        $v = self::envInt('HYDRATION_DAILY_MAX_ML');
        return $v !== null && $v >= 1000 && $v <= 50000 ? $v : self::DEFAULT_DAILY_MAX_ML;
    }

    public static function backdateDays(): int
    {
        $v = self::envInt('HYDRATION_BACKDATE_DAYS');
        return $v !== null && $v >= 0 && $v <= 31 ? $v : self::DEFAULT_BACKDATE_DAYS;
    }

    private static function envInt(string $key): ?int
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = Env::get($key);
        }
        return is_string($raw) && ctype_digit(trim($raw)) ? (int)trim($raw) : (is_int($raw) ? $raw : null);
    }

    // ------------------------------------------------------------------ validação de entrada

    /** Volume em ml: inteiro (aceita "250" e 250.0), de 1 a 5000. Ausente ou inválido → 422. */
    public static function parseAmount(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            throw new HydrationException('Informe o volume de água em ml.', 'VALIDATION_ERROR');
        }
        if (is_int($raw)) {
            $amount = $raw;
        } elseif (is_float($raw) && is_finite($raw) && floor($raw) === $raw && abs($raw) < 1e9) {
            $amount = (int)$raw;
        } elseif (is_string($raw) && preg_match('/^\s*(\d{1,9})(?:\.0+)?\s*$/', $raw, $m)) {
            $amount = (int)$m[1];
        } elseif (is_string($raw) && preg_match('/^\s*-\d+(?:\.0+)?\s*$/', $raw)) {
            $amount = -1;
        } else {
            throw new HydrationException('Informe o volume de água em ml (número inteiro).', 'VALIDATION_ERROR');
        }
        if ($amount < self::MIN_ML || $amount > self::MAX_ML) {
            throw new HydrationException('Informe um volume de água entre ' . self::MIN_ML . ' e ' . self::MAX_ML . ' ml.', 'VALIDATION_ERROR');
        }
        return $amount;
    }

    /** Horário do consumo. Sem valor = agora. ISO 8601; sem fuso, vale o fuso da aplicação. */
    public function parseLoggedAt(mixed $raw): DateTimeImmutable
    {
        $tz = $this->tz();
        $now = $this->now();
        if ($raw === null || $raw === '') {
            return $now;
        }
        if (!is_string($raw) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/', trim($raw), $m)) {
            throw new HydrationException('Informe a data e a hora do consumo no formato AAAA-MM-DDTHH:MM (ex.: 2026-10-21T14:30-03:00).', 'VALIDATION_ERROR');
        }
        try {
            $parsed = new DateTimeImmutable(trim($raw), $tz);
        } catch (\Throwable) {
            throw new HydrationException('Data e hora do consumo inválidas.', 'VALIDATION_ERROR');
        }
        // Rejeita datas que o PHP "corrige" (ex.: 2026-02-30, 25:00).
        if ($parsed->format('Y-m-d') !== $m[1] || (int)$parsed->format('H') !== (int)$m[2] || (int)$parsed->format('i') !== (int)$m[3]) {
            throw new HydrationException('Data e hora do consumo inválidas.', 'VALIDATION_ERROR');
        }
        $local = $parsed->setTimezone($tz);
        if ($local->getTimestamp() > $now->getTimestamp() + self::FUTURE_TOLERANCE_SECONDS) {
            throw new HydrationException('O horário do consumo não pode estar no futuro.', 'HYDRATION_DATE_IN_FUTURE');
        }
        $oldest = $now->setTime(0, 0)->modify('-' . self::backdateDays() . ' days');
        if ($local < $oldest) {
            throw new HydrationException('Só é possível registrar água dos últimos ' . self::backdateDays() . ' dias.', 'HYDRATION_DATE_TOO_OLD');
        }
        return $local;
    }

    public static function parseIdempotencyKey(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $raw)) {
            throw new HydrationException('Chave de idempotência inválida (8 a 64 caracteres: letras, números, ".", "_", ":" ou "-").', 'IDEMPOTENCY_KEY_INVALID');
        }
        return $raw;
    }

    // ------------------------------------------------------------------ operações da paciente

    /**
     * Registra água. $options: idempotency_key, logged_at, source ('app').
     * Retorna o registro, o total do dia do registro e o total de hoje.
     */
    public function create(int $patientId, mixed $amountRaw, array $options = []): array
    {
        $clinicId = $this->guard($patientId);
        $amount = self::parseAmount($amountRaw);
        $loggedAt = $this->parseLoggedAt($options['logged_at'] ?? null);
        $key = self::parseIdempotencyKey($options['idempotency_key'] ?? null);
        $source = in_array($options['source'] ?? 'app', ['app', 'import'], true) ? ($options['source'] ?? 'app') : 'app';

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->lockPatient($clinicId, $patientId);
            if ($key !== null && ($existing = $this->findByKey($clinicId, $patientId, $key))) {
                $result = $this->replay($existing, $amount);
                if ($own) {
                    $this->db->commit();
                }
                return $result;
            }
            $day = $loggedAt->format('Y-m-d');
            $this->assertDailyLimit($clinicId, $patientId, $day, $amount, null);

            $now = $this->now()->format('Y-m-d H:i:s');
            $this->db->prepare('
                INSERT INTO hydration_logs (clinic_id, patient_id, amount_ml, logged_at, created_at, idempotency_key, source)
                VALUES (:clinic_id, :patient_id, :amount_ml, :logged_at, :created_at, :idempotency_key, :source)
            ')->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'amount_ml' => $amount,
                'logged_at' => $loggedAt->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'idempotency_key' => $key,
                'source' => $source,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->audit('HYDRATION_LOGGED', $id, ['patient_id' => $patientId, 'amount_ml' => $amount, 'logged_at' => $loggedAt->format('Y-m-d H:i:s'), 'source' => $source, 'idempotent' => $key !== null]);
            $row = $this->findById($clinicId, $patientId, $id);
            $result = $this->result($row, false);
            if ($own) {
                $this->db->commit();
            }
            return $result;
        } catch (\PDOException $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Corrida entre dois envios da mesma operação: o índice único decide; devolvemos o que venceu.
            if ($key !== null && ($existing = $this->findByKey($clinicId, $patientId, $key))) {
                return $this->replay($existing, $amount);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Corrige volume e/ou horário de um registro da própria paciente, dentro da janela de edição. */
    public function update(int $patientId, int $entryId, array $changes): array
    {
        $clinicId = $this->guard($patientId);
        $hasAmount = array_key_exists('amount_ml', $changes) && $changes['amount_ml'] !== null && $changes['amount_ml'] !== '';
        $hasTime = array_key_exists('logged_at', $changes) && $changes['logged_at'] !== null && $changes['logged_at'] !== '';
        if (!$hasAmount && !$hasTime) {
            throw new HydrationException('Informe o novo volume (amount_ml) ou o novo horário (logged_at).', 'VALIDATION_ERROR');
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->lockPatient($clinicId, $patientId);
            $row = $this->findById($clinicId, $patientId, $entryId);
            if (!$row || $row['deleted_at'] !== null) {
                throw new HydrationException('Registro de água não encontrado.', 'HYDRATION_NOT_FOUND', 404);
            }
            $this->assertEditable($row);
            $amount = $hasAmount ? self::parseAmount($changes['amount_ml']) : (int)$row['amount_ml'];
            $loggedAt = $hasTime ? $this->parseLoggedAt($changes['logged_at']) : $this->localDate((string)$row['logged_at']);
            $this->assertDailyLimit($clinicId, $patientId, $loggedAt->format('Y-m-d'), $amount, $entryId);

            $this->db->prepare('UPDATE hydration_logs SET amount_ml = :a, logged_at = :l, updated_at = :u WHERE id = :id AND clinic_id = :c AND patient_id = :p')
                ->execute(['a' => $amount, 'l' => $loggedAt->format('Y-m-d H:i:s'), 'u' => $this->now()->format('Y-m-d H:i:s'), 'id' => $entryId, 'c' => $clinicId, 'p' => $patientId]);
            $this->audit('HYDRATION_UPDATED', $entryId, [
                'patient_id' => $patientId,
                'before' => ['amount_ml' => (int)$row['amount_ml'], 'logged_at' => (string)$row['logged_at']],
                'after' => ['amount_ml' => $amount, 'logged_at' => $loggedAt->format('Y-m-d H:i:s')],
            ]);
            $result = $this->result($this->findById($clinicId, $patientId, $entryId), false);
            $result['previous_day'] = $this->localDate((string)$row['logged_at'])->format('Y-m-d');
            if ($own) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Exclusão lógica. Repetir a exclusão do mesmo registro devolve sucesso (idempotente). */
    public function delete(int $patientId, int $entryId): array
    {
        $clinicId = $this->guard($patientId);
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->lockPatient($clinicId, $patientId);
            $row = $this->findById($clinicId, $patientId, $entryId);
            if (!$row) {
                throw new HydrationException('Registro de água não encontrado.', 'HYDRATION_NOT_FOUND', 404);
            }
            $already = $row['deleted_at'] !== null;
            if (!$already) {
                $this->assertEditable($row);
                $now = $this->now()->format('Y-m-d H:i:s');
                $this->db->prepare('UPDATE hydration_logs SET deleted_at = :d, updated_at = :d WHERE id = :id AND clinic_id = :c AND patient_id = :p AND deleted_at IS NULL')
                    ->execute(['d' => $now, 'id' => $entryId, 'c' => $clinicId, 'p' => $patientId]);
                $this->audit('HYDRATION_DELETED', $entryId, ['patient_id' => $patientId, 'amount_ml' => (int)$row['amount_ml'], 'logged_at' => (string)$row['logged_at']]);
            }
            $day = $this->localDate((string)$row['logged_at'])->format('Y-m-d');
            $result = [
                'id' => $entryId,
                'deleted' => true,
                'already_deleted' => $already,
                'day' => $day,
                'day_total_ml' => $this->dayTotal($clinicId, $patientId, $day),
                'total_water_today' => $this->dayTotal($clinicId, $patientId, $this->now()->format('Y-m-d')),
            ];
            if ($own) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Total de hoje (dia local), sem registros excluídos. */
    public function todayTotal(int $patientId): int
    {
        $clinicId = $this->guard($patientId);
        return $this->dayTotal($clinicId, $patientId, $this->now()->format('Y-m-d'));
    }

    /**
     * Histórico paginado por dia, do mais recente para o mais antigo. Só dias com registros.
     * $before (AAAA-MM-DD, exclusivo) é o cursor; sem ele, começa por hoje.
     */
    public function history(int $patientId, mixed $before = null, mixed $days = null): array
    {
        $clinicId = $this->guard($patientId);
        $today = $this->now()->format('Y-m-d');
        if ($before === null || $before === '') {
            $beforeDate = $this->now()->setTime(0, 0)->modify('+1 day');
        } else {
            if (!is_string($before) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
                throw new HydrationException('Parâmetro "before" deve ser uma data AAAA-MM-DD.', 'VALIDATION_ERROR');
            }
            $beforeDate = DateTimeImmutable::createFromFormat('!Y-m-d', $before, $this->tz());
            if (!$beforeDate || $beforeDate->format('Y-m-d') !== $before) {
                throw new HydrationException('Parâmetro "before" deve ser uma data AAAA-MM-DD válida.', 'VALIDATION_ERROR');
            }
        }
        if ($days === null || $days === '') {
            $limit = self::HISTORY_DEFAULT_DAYS;
        } elseif ((is_int($days) || (is_string($days) && ctype_digit($days))) && (int)$days >= 1 && (int)$days <= self::HISTORY_MAX_DAYS) {
            $limit = (int)$days;
        } else {
            throw new HydrationException('Parâmetro "days" deve ser um inteiro de 1 a ' . self::HISTORY_MAX_DAYS . '.', 'VALIDATION_ERROR');
        }

        $upper = $beforeDate->format('Y-m-d 00:00:00');
        $st = $this->db->prepare('
            SELECT DATE(logged_at) AS d, SUM(amount_ml) AS total, COUNT(*) AS n
            FROM hydration_logs
            WHERE clinic_id = :c AND patient_id = :p AND deleted_at IS NULL AND logged_at < :upper
            GROUP BY DATE(logged_at)
            ORDER BY d DESC
            LIMIT ' . ($limit + 1));
        $st->execute(['c' => $clinicId, 'p' => $patientId, 'upper' => $upper]);
        $dayRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($dayRows) > $limit;
        $dayRows = array_slice($dayRows, 0, $limit);

        $goal = (new \ClyoNutri\Domain\PatientPortal\PatientPortalService($this->db))->getPrescribedWaterGoal($patientId);
        $daysOut = [];
        if ($dayRows) {
            $oldest = (string)end($dayRows)['d'];
            $es = $this->db->prepare('
                SELECT id, clinic_id, patient_id, amount_ml, logged_at, created_at, updated_at, deleted_at, source
                FROM hydration_logs
                WHERE clinic_id = :c AND patient_id = :p AND deleted_at IS NULL AND logged_at >= :lower AND logged_at < :upper
                ORDER BY logged_at DESC, id DESC');
            $es->execute(['c' => $clinicId, 'p' => $patientId, 'lower' => $oldest . ' 00:00:00', 'upper' => $upper]);
            $byDay = [];
            foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byDay[substr((string)$r['logged_at'], 0, 10)][] = $this->presentEntry($r);
            }
            foreach ($dayRows as $dr) {
                $d = (string)$dr['d'];
                $total = (int)$dr['total'];
                $daysOut[] = [
                    'date' => $d,
                    'total_ml' => $total,
                    'entries_count' => (int)$dr['n'],
                    'goal_ml' => $goal,
                    'goal_reached' => $goal !== null ? $total >= $goal : null,
                    'entries' => $byDay[$d] ?? [],
                ];
            }
        }

        return [
            'timezone' => $this->tz()->getName(),
            'today' => $today,
            'today_total_ml' => $this->dayTotal($clinicId, $patientId, $today),
            'goal_ml' => $goal,
            'daily_max_ml' => self::dailyMaxMl(),
            'edit_window_hours' => self::EDIT_WINDOW_HOURS,
            'backdate_days' => self::backdateDays(),
            'days' => $daysOut,
            'next_before' => $hasMore && $daysOut ? $daysOut[count($daysOut) - 1]['date'] : null,
        ];
    }

    // ------------------------------------------------------------------ visão do profissional

    /** Resumo diário (somente leitura) para o perfil da paciente: últimos N dias, inclusive os sem registro. */
    public function professionalSummary(int $patientId, int $days = 14): array
    {
        $clinicId = $this->guard($patientId);
        $days = max(1, min(60, $days));
        $todayStart = $this->now()->setTime(0, 0);
        $first = $todayStart->modify('-' . ($days - 1) . ' days');
        $st = $this->db->prepare('
            SELECT DATE(logged_at) AS d, SUM(amount_ml) AS total, COUNT(*) AS n, MAX(logged_at) AS last_at
            FROM hydration_logs
            WHERE clinic_id = :c AND patient_id = :p AND deleted_at IS NULL AND logged_at >= :lower AND logged_at < :upper
            GROUP BY DATE(logged_at)');
        $st->execute(['c' => $clinicId, 'p' => $patientId, 'lower' => $first->format('Y-m-d 00:00:00'), 'upper' => $todayStart->modify('+1 day')->format('Y-m-d 00:00:00')]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string)$r['d']] = ['total' => (int)$r['total'], 'n' => (int)$r['n'], 'last_at' => (string)$r['last_at']];
        }
        $goal = (new \ClyoNutri\Domain\PatientPortal\PatientPortalService($this->db))->getPrescribedWaterGoal($patientId);
        $out = [];
        $recorded = 0; $sum = 0; $reached = 0;
        for ($d = $todayStart; $d >= $first; $d = $d->modify('-1 day')) {
            $key = $d->format('Y-m-d');
            $total = $map[$key]['total'] ?? 0;
            if (isset($map[$key])) {
                $recorded++;
                $sum += $total;
                if ($goal !== null && $total >= $goal) {
                    $reached++;
                }
            }
            $out[] = ['date' => $key, 'total_ml' => $total, 'entries_count' => $map[$key]['n'] ?? 0, 'goal_reached' => $goal !== null && isset($map[$key]) ? $total >= $goal : null];
        }
        $last = $this->db->prepare('SELECT MAX(logged_at) FROM hydration_logs WHERE clinic_id = :c AND patient_id = :p AND deleted_at IS NULL');
        $last->execute(['c' => $clinicId, 'p' => $patientId]);
        $lastAt = $last->fetchColumn();

        return [
            'timezone' => $this->tz()->getName(),
            'period_days' => $days,
            'goal_ml' => $goal,
            'days' => $out,
            'days_with_records' => $recorded,
            'average_ml_on_recorded_days' => $recorded ? (int)round($sum / $recorded) : null,
            'days_goal_reached' => $goal !== null ? $reached : null,
            'last_logged_at' => $lastAt ? $this->localDate((string)$lastAt)->format(DATE_ATOM) : null,
        ];
    }

    // ------------------------------------------------------------------ internos

    private function guard(int $patientId): int
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        return $clinicId;
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock ? ($this->clock)() : new DateTimeImmutable('now');
        return $now->setTimezone($this->tz());
    }

    private function localDate(string $stored): DateTimeImmutable
    {
        return new DateTimeImmutable($stored, $this->tz());
    }

    private function lockPatient(int $clinicId, int $patientId): void
    {
        // MySQL: serializa os registros da mesma paciente (limite diário e idempotência). SQLite já serializa escritas.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->db->prepare('SELECT id FROM patients WHERE id = :p AND clinic_id = :c FOR UPDATE')->execute(['p' => $patientId, 'c' => $clinicId]);
        }
    }

    private function dayTotal(int $clinicId, int $patientId, string $day, ?int $excludeId = null): int
    {
        $start = new DateTimeImmutable($day . ' 00:00:00', $this->tz());
        $sql = 'SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs WHERE clinic_id = :c AND patient_id = :p AND deleted_at IS NULL AND logged_at >= :lower AND logged_at < :upper';
        $params = ['c' => $clinicId, 'p' => $patientId, 'lower' => $start->format('Y-m-d H:i:s'), 'upper' => $start->modify('+1 day')->format('Y-m-d H:i:s')];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :x';
            $params['x'] = $excludeId;
        }
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    private function assertDailyLimit(int $clinicId, int $patientId, string $day, int $amount, ?int $excludeId): void
    {
        $max = self::dailyMaxMl();
        $current = $this->dayTotal($clinicId, $patientId, $day, $excludeId);
        if ($current + $amount > $max) {
            $liters = rtrim(rtrim(number_format($max / 1000, 1, ',', ''), '0'), ',');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $this->tz());
            throw new HydrationException(
                'Com este registro, o total de ' . ($d ? $d->format('d/m') : $day) . ' passaria de ' . $liters . ' L. Confira o valor; se estiver certo, fale com seu nutricionista.',
                'HYDRATION_DAILY_LIMIT'
            );
        }
    }

    private function assertEditable(array $row): void
    {
        if (($row['source'] ?? 'app') !== 'app') {
            throw new HydrationException('Este registro não foi feito por você no app e não pode ser alterado aqui.', 'HYDRATION_NOT_EDITABLE', 409);
        }
        $deadline = $this->editDeadline($row);
        if ($deadline === null || $this->now() > $deadline) {
            throw new HydrationException('Registros de água só podem ser corrigidos ou excluídos até ' . self::EDIT_WINDOW_HOURS . ' horas depois de feitos.', 'HYDRATION_EDIT_WINDOW_CLOSED', 409);
        }
    }

    private function editDeadline(array $row): ?DateTimeImmutable
    {
        $created = (string)($row['created_at'] ?? '');
        if ($created === '') {
            return null;
        }
        return $this->localDate($created)->modify('+' . self::EDIT_WINDOW_HOURS . ' hours');
    }

    private function findById(int $clinicId, int $patientId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT id, clinic_id, patient_id, amount_ml, logged_at, created_at, updated_at, deleted_at, source, idempotency_key FROM hydration_logs WHERE id = :id AND clinic_id = :c AND patient_id = :p');
        $st->execute(['id' => $id, 'c' => $clinicId, 'p' => $patientId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function findByKey(int $clinicId, int $patientId, string $key): ?array
    {
        $st = $this->db->prepare('SELECT id, clinic_id, patient_id, amount_ml, logged_at, created_at, updated_at, deleted_at, source, idempotency_key FROM hydration_logs WHERE clinic_id = :c AND patient_id = :p AND idempotency_key = :k');
        $st->execute(['c' => $clinicId, 'p' => $patientId, 'k' => $key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function replay(array $existing, int $amount): array
    {
        if ((int)$existing['amount_ml'] !== $amount && $existing['updated_at'] === null) {
            throw new HydrationException('Esta chave de idempotência já foi usada para outro volume.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }
        return $this->result($existing, true);
    }

    private function result(array $row, bool $replay): array
    {
        $clinicId = (int)$row['clinic_id'];
        $patientId = (int)$row['patient_id'];
        $day = substr((string)$row['logged_at'], 0, 10);
        return $this->presentEntry($row) + [
            'day' => $day,
            'day_total_ml' => $this->dayTotal($clinicId, $patientId, $day),
            'total_water_today' => $this->dayTotal($clinicId, $patientId, $this->now()->format('Y-m-d')),
            'idempotent_replay' => $replay,
            'deleted' => $row['deleted_at'] !== null,
        ];
    }

    private function presentEntry(array $r): array
    {
        $deadline = $this->editDeadline($r);
        $editable = $r['deleted_at'] === null && ($r['source'] ?? 'app') === 'app' && $deadline !== null && $this->now() <= $deadline;
        return [
            'id' => (int)$r['id'],
            'amount_ml' => (int)$r['amount_ml'],
            'logged_at' => $this->localDate((string)$r['logged_at'])->format(DATE_ATOM),
            'source' => (string)($r['source'] ?? 'app'),
            'editable' => $editable,
            'editable_until' => $editable && $deadline ? $deadline->format(DATE_ATOM) : null,
            'updated_at' => !empty($r['updated_at']) ? $this->localDate((string)$r['updated_at'])->format(DATE_ATOM) : null,
        ];
    }

    private function audit(string $action, int $id, array $details): void
    {
        (new AuditLogger($this->db))->log($action, 'hydration_logs', (string)$id, $details);
    }
}
