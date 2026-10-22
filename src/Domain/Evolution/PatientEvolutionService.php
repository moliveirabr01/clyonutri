<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Evolution;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;

/**
 * Evolução da paciente (NI2-135): métricas habilitadas por paciente, autorrelato validado,
 * linha do tempo com filtro de período e metas definidas pelo profissional.
 * Medição clínica e autorrelato nunca se misturam: cada ponto carrega a fonte.
 */
final class PatientEvolutionService
{
    public const NOTES_MAX = 500;
    public const GOAL_MESSAGE_MAX = 500;
    public const DIRECTIONS = ['decrease' => 'Reduzir', 'increase' => 'Aumentar', 'maintain' => 'Manter'];

    private PDO $db;
    private AuditLogger $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->audit = new AuditLogger($this->db);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    }

    private function guard(int $patientId): int
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        return $clinicId;
    }

    // ------------------------------------------------------------------ permissões

    /** Tipos habilitados para a paciente (só os que existem no catálogo). */
    public function enabledMetricTypes(int $patientId): array
    {
        $clinicId = $this->guard($patientId);
        $st = $this->db->prepare('SELECT metric_type FROM patient_self_metric_permissions WHERE clinic_id = ? AND patient_id = ? AND enabled = 1 ORDER BY metric_type');
        $st->execute([$clinicId, $patientId]);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), [SelfMetricCatalog::class, 'has']));
    }

    public function enabledMetrics(int $patientId): array
    {
        return array_map([SelfMetricCatalog::class, 'describe'], $this->enabledMetricTypes($patientId));
    }

    /** Define exatamente quais métricas a paciente pode autorrelatar. */
    public function setEnabledMetrics(int $patientId, array $types, int $userId): array
    {
        $clinicId = $this->guard($patientId);
        $types = array_values(array_unique(array_map('strval', $types)));
        foreach ($types as $t) {
            if (!SelfMetricCatalog::has($t)) throw new InvalidArgumentException('Métrica não reconhecida: ' . $t);
        }
        $now = $this->now()->format('Y-m-d H:i:s');
        $before = $this->enabledMetricTypes($patientId);
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            foreach (array_keys(SelfMetricCatalog::METRICS) as $type) {
                $enabled = in_array($type, $types, true) ? 1 : 0;
                $upd = $this->db->prepare('UPDATE patient_self_metric_permissions SET enabled = ?, updated_by_user_id = ?, updated_at = ? WHERE clinic_id = ? AND patient_id = ? AND metric_type = ?');
                $upd->execute([$enabled, $userId, $now, $clinicId, $patientId, $type]);
                if ($upd->rowCount() === 0 && $enabled === 1) {
                    $this->db->prepare('INSERT INTO patient_self_metric_permissions (clinic_id, patient_id, metric_type, enabled, updated_by_user_id, updated_at) VALUES (?, ?, ?, 1, ?, ?)')
                        ->execute([$clinicId, $patientId, $type, $userId, $now]);
                }
            }
            $this->audit->log('SELF_METRIC_PERMISSIONS_CHANGED', 'patients', (string)$patientId, ['before' => $before, 'after' => $types, 'catalog' => SelfMetricCatalog::VERSION]);
            if ($own) $this->db->commit();
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->enabledMetricTypes($patientId);
    }

    // ------------------------------------------------------------------ autorrelato

    /**
     * Registra autorrelato. Só métricas habilitadas para a paciente, na unidade do catálogo e dentro da faixa.
     * @throws SelfMetricNotEnabledException quando a métrica não está habilitada
     * @throws InvalidArgumentException para dados inválidos
     */
    public function recordSelfMetric(int $patientId, string $metricType, mixed $value, ?string $unit = null, ?string $notes = null): array
    {
        $clinicId = $this->guard($patientId);
        $metricType = trim($metricType);
        if ($metricType === '') throw new InvalidArgumentException('Escolha o que você está registrando (metric_type).');
        if (!SelfMetricCatalog::has($metricType)) throw new InvalidArgumentException('Esta medida não pode ser registrada pelo app.');
        $m = SelfMetricCatalog::get($metricType);
        if (!in_array($metricType, $this->enabledMetricTypes($patientId), true)) {
            throw new SelfMetricNotEnabledException('Seu nutricionista ainda não liberou o registro de ' . mb_strtolower($m['label']) . ' pelo app.');
        }
        $unit = $unit === null ? '' : trim($unit);
        if ($unit !== '' && $unit !== $m['unit']) throw new InvalidArgumentException("Informe {$m['label']} em {$m['unit']}.");
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) throw new InvalidArgumentException('Informe um número.');
        $v = (float)$value;
        if (!is_finite($v) || $v < $m['min'] || $v > $m['max']) {
            throw new InvalidArgumentException(sprintf('%s deve estar entre %s e %s %s. Confira o valor digitado.', $m['label'],
                number_format($m['min'], 0, ',', '.'), number_format($m['max'], 0, ',', '.'), $m['unit']));
        }
        $v = round($v, $m['decimals']);
        $notes = $notes === null ? null : trim($notes);
        if ($notes === '') $notes = null;
        if ($notes !== null && mb_strlen($notes) > self::NOTES_MAX) throw new InvalidArgumentException('A observação deve ter no máximo ' . self::NOTES_MAX . ' caracteres.');

        $recordedAt = $this->now()->format('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO patient_self_metrics (clinic_id, patient_id, metric_type, value, unit, source, notes, recorded_at)
            VALUES (?, ?, ?, ?, ?, 'patient_self_report', ?, ?)")->execute([$clinicId, $patientId, $metricType, $v, $m['unit'], $notes, $recordedAt]);
        $id = (int)$this->db->lastInsertId();
        return [
            'id' => $id, 'patient_id' => $patientId, 'metric_type' => $metricType, 'label' => $m['label'],
            'value' => $v, 'unit' => $m['unit'], 'notes' => $notes, 'source' => 'patient_self_report', 'is_clinical' => false,
            'recorded_at' => $recordedAt, 'date' => $recordedAt,
        ];
    }

    // ------------------------------------------------------------------ linha do tempo

    /** Normaliza o período: 'Y-m-d' ou vazio. Devolve [from, to] (inclusive) ou lança 422. */
    public static function parsePeriod(?string $from, ?string $to): array
    {
        $parse = function (?string $d, string $name): ?string {
            if ($d === null || $d === '') return null;
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if (!$dt || $dt->format('Y-m-d') !== $d) throw new InvalidArgumentException("Data inválida em {$name} (use AAAA-MM-DD).");
            return $d;
        };
        $f = $parse($from, 'from'); $t = $parse($to, 'to');
        if ($f !== null && $t !== null && $f > $t) throw new InvalidArgumentException('O início do período é depois do fim.');
        return [$f, $t];
    }

    public function timeline(int $patientId, ?string $from = null, ?string $to = null): array
    {
        $clinicId = $this->guard($patientId);
        [$from, $to] = self::parsePeriod($from, $to);
        $range = ''; $args = [$patientId, $clinicId];
        if ($from !== null) { $range .= ' AND %s >= ?'; $args[] = $from . ' 00:00:00'; }
        if ($to !== null) { $range .= ' AND %s <= ?'; $args[] = $to . ' 23:59:59'; }

        // Medições clínicas liberadas pelo profissional. `notes` é anotação interna: nunca vai para a paciente.
        $c = $this->db->prepare('SELECT id, assessed_at AS date, weight_kg, height_cm, bmi, waist_cm, hip_cm, shared_at
            FROM anthropometric_assessments WHERE patient_id = ? AND clinic_id = ? AND is_shared = 1' . sprintf($range, 'assessed_at', 'assessed_at') . ' ORDER BY assessed_at ASC, id ASC');
        $c->execute($args);
        $clinical = array_map(function (array $r) {
            foreach (['weight_kg', 'height_cm', 'bmi', 'waist_cm', 'hip_cm'] as $k) $r[$k] = $r[$k] === null ? null : (float)$r[$k];
            $r['source'] = 'clinical_official';
            $r['source_label'] = 'Registrado pelo seu nutricionista';
            return $r;
        }, $c->fetchAll(PDO::FETCH_ASSOC));

        $s = $this->db->prepare('SELECT id, recorded_at AS date, metric_type, value, unit, notes FROM patient_self_metrics
            WHERE patient_id = ? AND clinic_id = ?' . sprintf($range, 'recorded_at', 'recorded_at') . ' ORDER BY recorded_at ASC, id ASC');
        $s->execute($args);
        $self = array_map(function (array $r) {
            $r['value'] = (float)$r['value'];
            $r['label'] = SelfMetricCatalog::label((string)$r['metric_type']);
            $r['source'] = 'patient_self_report';
            $r['source_label'] = 'Registrado por você';
            return $r;
        }, $s->fetchAll(PDO::FETCH_ASSOC));

        // Séries por métrica do catálogo (para o gráfico): pontos clínicos e autorrelatos marcados pela fonte.
        $series = [];
        foreach (SelfMetricCatalog::METRICS as $type => $m) {
            $points = [];
            foreach ($clinical as $r) if ($r[$m['clinical_column']] !== null) $points[] = ['date' => $r['date'], 'value' => $r[$m['clinical_column']], 'source' => 'clinical_official'];
            foreach ($self as $r) if ($r['metric_type'] === $type) $points[] = ['date' => $r['date'], 'value' => $r['value'], 'source' => 'patient_self_report'];
            usort($points, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
            if ($points) $series[] = ['type' => $type, 'label' => $m['label'], 'unit' => $m['unit'], 'points' => $points];
        }

        return [
            'patient_id' => $patientId,
            'period' => ['from' => $from, 'to' => $to],
            'clinical_records' => $clinical,
            'self_reports' => $self,
            'series' => $series,
            'enabled_metrics' => $this->enabledMetrics($patientId),
            'goals' => $this->goals($patientId, true),
            'summary' => [
                'clinical_count' => count($clinical),
                'self_report_count' => count($self),
                'total_entries' => count($clinical) + count($self),
                'has_data' => (count($clinical) + count($self)) > 0,
            ],
        ];
    }

    // ------------------------------------------------------------------ metas

    public function createGoal(int $patientId, int $professionalId, int $userId, array $in): array
    {
        $clinicId = $this->guard($patientId);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $type = (string)($in['metric_type'] ?? '');
        if (!SelfMetricCatalog::has($type)) throw new InvalidArgumentException('Escolha a medida da meta.');
        $m = SelfMetricCatalog::get($type);
        $direction = (string)($in['direction'] ?? '');
        if (!isset(self::DIRECTIONS[$direction])) throw new InvalidArgumentException('Escolha se a meta é reduzir, aumentar ou manter.');
        $raw = $in['target_value'] ?? null;
        if (is_string($raw)) $raw = str_replace(',', '.', trim($raw));
        if (!is_numeric($raw)) throw new InvalidArgumentException('Informe o valor da meta.');
        $target = (float)$raw;
        if (!is_finite($target) || $target < $m['min'] || $target > $m['max']) throw new InvalidArgumentException("O valor da meta deve estar entre {$m['min']} e {$m['max']} {$m['unit']}.");
        $date = trim((string)($in['target_date'] ?? ''));
        if ($date !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) throw new InvalidArgumentException('Data da meta inválida.');
            if ($date < $this->now()->format('Y-m-d')) throw new InvalidArgumentException('A data da meta não pode estar no passado.');
        }
        $message = trim((string)($in['message_for_patient'] ?? ''));
        if (mb_strlen($message) > self::GOAL_MESSAGE_MAX) throw new InvalidArgumentException('A mensagem para a paciente deve ter no máximo ' . self::GOAL_MESSAGE_MAX . ' caracteres.');
        $now = $this->now()->format('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO patient_goals (clinic_id, patient_id, professional_id, metric_type, target_value, unit, direction, target_date, message_for_patient, status, created_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)")
            ->execute([$clinicId, $patientId, $professionalId, $type, round($target, $m['decimals']), $m['unit'], $direction, $date === '' ? null : $date, $message === '' ? null : $message, $userId, $now, $now]);
        $id = (int)$this->db->lastInsertId();
        $this->audit->log('PATIENT_GOAL_CREATED', 'patient_goals', (string)$id, ['patient_id' => $patientId, 'metric_type' => $type, 'direction' => $direction]);
        return $this->goal($patientId, $id);
    }

    public function closeGoal(int $patientId, int $goalId, string $status, int $userId): array
    {
        $clinicId = $this->guard($patientId);
        if (!in_array($status, ['achieved', 'cancelled'], true)) throw new InvalidArgumentException('Escolha como encerrar a meta.');
        $now = $this->now()->format('Y-m-d H:i:s');
        $st = $this->db->prepare("UPDATE patient_goals SET status = ?, closed_at = ?, closed_by_user_id = ?, updated_at = ? WHERE id = ? AND clinic_id = ? AND patient_id = ? AND status = 'active'");
        $st->execute([$status, $now, $userId, $now, $goalId, $clinicId, $patientId]);
        if ($st->rowCount() !== 1) throw new InvalidArgumentException('Meta não encontrada ou já encerrada.');
        $this->audit->log('PATIENT_GOAL_CLOSED', 'patient_goals', (string)$goalId, ['patient_id' => $patientId, 'status' => $status]);
        return $this->goal($patientId, $goalId);
    }

    private function goal(int $patientId, int $goalId): array
    {
        foreach ($this->goals($patientId, false) as $g) if ((int)$g['id'] === $goalId) return $g;
        throw new InvalidArgumentException('Meta não encontrada.');
    }

    /**
     * Metas com o registro mais recente de cada fonte (sem cálculo de "quanto falta" nem recomendação).
     */
    public function goals(int $patientId, bool $activeOnly): array
    {
        $clinicId = $this->guard($patientId);
        $st = $this->db->prepare('SELECT id, metric_type, target_value, unit, direction, target_date, message_for_patient, status, created_at, closed_at
            FROM patient_goals WHERE clinic_id = ? AND patient_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY status = \'active\' DESC, created_at DESC, id DESC');
        $st->execute([$clinicId, $patientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $type = (string)$g['metric_type'];
            $m = SelfMetricCatalog::METRICS[$type] ?? null;
            $g['target_value'] = (float)$g['target_value'];
            $g['label'] = SelfMetricCatalog::label($type);
            $g['direction_label'] = self::DIRECTIONS[$g['direction']] ?? $g['direction'];
            $g['latest_clinical'] = null; $g['latest_self_report'] = null;
            if ($m) {
                $col = $m['clinical_column'];
                $c = $this->db->prepare("SELECT assessed_at AS date, {$col} AS value FROM anthropometric_assessments WHERE clinic_id = ? AND patient_id = ? AND is_shared = 1 AND {$col} IS NOT NULL ORDER BY assessed_at DESC, id DESC LIMIT 1");
                $c->execute([$clinicId, $patientId]);
                if ($r = $c->fetch(PDO::FETCH_ASSOC)) $g['latest_clinical'] = ['date' => $r['date'], 'value' => (float)$r['value']];
                $s = $this->db->prepare('SELECT recorded_at AS date, value FROM patient_self_metrics WHERE clinic_id = ? AND patient_id = ? AND metric_type = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
                $s->execute([$clinicId, $patientId, $type]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) $g['latest_self_report'] = ['date' => $r['date'], 'value' => (float)$r['value']];
            }
            $out[] = $g;
        }
        return $out;
    }

    // ------------------------------------------------------------------ visão do profissional

    /** Painel do profissional: permissões, autorrelatos recentes (com rótulo e fonte) e metas. */
    public function professionalPanel(int $patientId): array
    {
        $clinicId = $this->guard($patientId);
        $s = $this->db->prepare('SELECT id, recorded_at, metric_type, value, unit, notes FROM patient_self_metrics WHERE clinic_id = ? AND patient_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 50');
        $s->execute([$clinicId, $patientId]);
        $reports = array_map(function (array $r) { $r['label'] = SelfMetricCatalog::label((string)$r['metric_type']); $r['value'] = (float)$r['value']; return $r; }, $s->fetchAll(PDO::FETCH_ASSOC));
        return [
            'catalog' => array_map([SelfMetricCatalog::class, 'describe'], array_keys(SelfMetricCatalog::METRICS)),
            'enabled' => $this->enabledMetricTypes($patientId),
            'self_reports' => $reports,
            'goals' => $this->goals($patientId, false),
        ];
    }
}
