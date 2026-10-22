<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Analytics;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Settings\PlatformSettings;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * NI2-96: métricas de adesão do produto com privacidade.
 *
 * O que é guardado: por dia, clínica e métrica, quantas vezes aconteceu (event_count) e quantas pacientes
 * diferentes (unique_patients). Nada além disso: nenhum id de paciente, nenhum valor (ml, % de adesão,
 * observação, refeição), nenhum dado do plano.
 *
 * Contagem de pacientes distintos: HMAC-SHA256 de "clínica:paciente" com um sal aleatório do dia
 * (analytics_daily_salts). O sal e os hashes do dia são apagados no dia seguinte (purge()); a partir daí
 * não há como saber quem foi contado. Base legal: legítimo interesse (LGPD art. 7º, IX e art. 10),
 * documentada em docs/privacy/METRICAS_DE_ADESAO_NI2-96.md. Chave de desligar:
 * platform_settings.analytics_adherence_enabled = 0 (Admin > Configurações).
 *
 * A coleta nunca interrompe a ação da paciente: qualquer erro é engolido e registrado sem dados.
 */
final class AdherenceAnalytics
{
    public const PLAN_VIEWED = 'plan_viewed';
    public const CHECKIN_SUBMITTED = 'checkin_submitted';
    public const HYDRATION_LOGGED = 'hydration_logged';
    public const METRICS = [self::PLAN_VIEWED, self::CHECKIN_SUBMITTED, self::HYDRATION_LOGGED];
    public const LABELS = [
        self::PLAN_VIEWED => 'Plano visto no app',
        self::CHECKIN_SUBMITTED => 'Check-in enviado',
        self::HYDRATION_LOGGED => 'Hidratação registrada',
    ];
    /** Corte mínimo: números de pacientes abaixo disso não aparecem (só "menos de 5"). */
    public const K_MIN = 5;
    public const AGGREGATE_RETENTION_MONTHS = 24;
    public const PERIODS = [7, 30, 90];

    private PDO $db;
    private DateTimeZone $tz;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->tz = new DateTimeZone(getenv('APP_TIMEZONE') ?: date_default_timezone_get());
    }

    public function enabled(): bool
    {
        return (string)PlatformSettings::get('analytics_adherence_enabled', '1', $this->db) === '1';
    }

    /** Registra um evento. Nunca lança exceção. */
    public function record(string $metric, int $clinicId, int $patientId, ?DateTimeImmutable $now = null): void
    {
        if (!in_array($metric, self::METRICS, true) || $clinicId <= 0 || $patientId <= 0) {
            return;
        }
        try {
            if (!$this->enabled()) {
                return;
            }
            $day = ($now ?? new DateTimeImmutable('now', $this->tz))->setTimezone($this->tz)->format('Y-m-d');
            $hash = hash_hmac('sha256', $clinicId . ':' . $patientId, $this->saltFor($day));
            $isNew = $this->insertIgnoringDuplicate(
                'INSERT INTO analytics_daily_subjects (metric_date, clinic_id, metric, subject_hash) VALUES (?, ?, ?, ?)',
                [$day, $clinicId, $metric, $hash]
            );
            $this->bump($day, $clinicId, $metric, $isNew ? 1 : 0);
        } catch (Throwable $e) {
            error_log('[analytics] coleta ignorada: ' . get_class($e));
        }
    }

    private function saltFor(string $day): string
    {
        $get = $this->db->prepare('SELECT salt FROM analytics_daily_salts WHERE metric_date = ?');
        $get->execute([$day]);
        $salt = $get->fetchColumn();
        $get->closeCursor();
        if (is_string($salt) && $salt !== '') {
            return $salt;
        }
        $this->insertIgnoringDuplicate('INSERT INTO analytics_daily_salts (metric_date, salt, created_at) VALUES (?, ?, ?)',
            [$day, bin2hex(random_bytes(32)), (new DateTimeImmutable('now', $this->tz))->format('Y-m-d H:i:s')]);
        $get->execute([$day]);
        $salt = (string)$get->fetchColumn();
        $get->closeCursor();
        return $salt;
    }

    private function bump(string $day, int $clinicId, string $metric, int $newPatient): void
    {
        $now = (new DateTimeImmutable('now', $this->tz))->format('Y-m-d H:i:s');
        $upd = $this->db->prepare('UPDATE analytics_daily_metrics SET event_count = event_count + 1, unique_patients = unique_patients + ?, updated_at = ?
            WHERE metric_date = ? AND clinic_id = ? AND metric = ?');
        $upd->execute([$newPatient, $now, $day, $clinicId, $metric]);
        if ($upd->rowCount() > 0) {
            return;
        }
        $inserted = $this->insertIgnoringDuplicate('INSERT INTO analytics_daily_metrics (metric_date, clinic_id, metric, event_count, unique_patients, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$day, $clinicId, $metric, $newPatient, $now]);
        if (!$inserted) {
            $upd->execute([$newPatient, $now, $day, $clinicId, $metric]); // outra requisição criou a linha no meio
        }
    }

    private function insertIgnoringDuplicate(string $sql, array $params): bool
    {
        try {
            $this->db->prepare($sql)->execute($params);
            return true;
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Apaga os pseudônimos e sais de dias encerrados e os agregados com mais de 24 meses.
     * @return array{subjects:int,salts:int,aggregates:int}
     */
    public function purge(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', $this->tz))->setTimezone($this->tz);
        $today = $now->format('Y-m-d');
        $oldest = $now->modify('-' . self::AGGREGATE_RETENTION_MONTHS . ' months')->format('Y-m-d');
        $res = [];
        foreach (['subjects' => ['analytics_daily_subjects', $today], 'salts' => ['analytics_daily_salts', $today], 'aggregates' => ['analytics_daily_metrics', $oldest]] as $k => [$table, $limit]) {
            $st = $this->db->prepare("DELETE FROM {$table} WHERE metric_date < ?");
            $st->execute([$limit]);
            $res[$k] = $st->rowCount();
        }
        return $res;
    }

    /**
     * Relatório agregado para o painel da plataforma. Só números; números de pacientes abaixo de K_MIN
     * são devolvidos como null ("menos de 5").
     */
    public function report(int $days, ?DateTimeImmutable $now = null): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $now = ($now ?? new DateTimeImmutable('now', $this->tz))->setTimezone($this->tz);
        $to = $now->format('Y-m-d');
        $from = $now->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $k = static fn(int $n): ?int => $n >= self::K_MIN ? $n : null;

        $st = $this->db->prepare('SELECT metric, SUM(event_count) AS events, SUM(unique_patients) AS patient_days,
                MAX(unique_patients) AS best_day, COUNT(DISTINCT clinic_id) AS clinics
            FROM analytics_daily_metrics WHERE metric_date BETWEEN ? AND ? GROUP BY metric');
        $st->execute([$from, $to]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['metric']] = $r;
        }

        $appPatients = (int)$this->db->query("SELECT COUNT(*) FROM patients WHERE status = 'active' AND password_hash IS NOT NULL AND password_hash <> ''")->fetchColumn();
        $totals = [];
        foreach (self::METRICS as $m) {
            $r = $rows[$m] ?? null;
            $patientDays = (int)($r['patient_days'] ?? 0);
            $totals[$m] = [
                'label' => self::LABELS[$m],
                'events' => $k((int)($r['events'] ?? 0)),
                'patient_days' => $k($patientDays),
                'avg_patients_per_day' => $patientDays >= self::K_MIN ? round($patientDays / $days, 1) : null,
                'clinics' => (int)($r['clinics'] ?? 0),
            ];
        }

        // Série por semana (segunda a domingo), plataforma inteira.
        $series = [];
        $st = $this->db->prepare('SELECT metric_date, metric, SUM(unique_patients) AS p FROM analytics_daily_metrics
            WHERE metric_date BETWEEN ? AND ? GROUP BY metric_date, metric');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = new DateTimeImmutable((string)$r['metric_date'], $this->tz);
            $week = $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d');
            $series[$week][$r['metric']] = ($series[$week][$r['metric']] ?? 0) + (int)$r['p'];
        }
        ksort($series);
        $weeks = [];
        foreach ($series as $week => $vals) {
            $weeks[] = ['week_start' => $week] + array_combine(self::METRICS, array_map(static fn($m) => $k((int)($vals[$m] ?? 0)), self::METRICS));
        }

        // Por clínica: aparece só com pelo menos K_MIN pacientes com acesso ao app; cada número segue o corte.
        $st = $this->db->prepare("SELECT c.id, c.name,
                (SELECT COUNT(*) FROM patients p WHERE p.clinic_id = c.id AND p.status = 'active' AND p.password_hash IS NOT NULL AND p.password_hash <> '') AS app_patients
            FROM clinics c WHERE EXISTS (SELECT 1 FROM analytics_daily_metrics a WHERE a.clinic_id = c.id AND a.metric_date BETWEEN ? AND ?)
            ORDER BY c.name");
        $st->execute([$from, $to]);
        $clinics = $st->fetchAll(PDO::FETCH_ASSOC);
        $per = $this->db->prepare('SELECT metric, SUM(unique_patients) AS p FROM analytics_daily_metrics WHERE clinic_id = ? AND metric_date BETWEEN ? AND ? GROUP BY metric');
        $byClinic = [];
        $hidden = 0;
        foreach ($clinics as $c) {
            if ((int)$c['app_patients'] < self::K_MIN) {
                $hidden++;
                continue;
            }
            $per->execute([(int)$c['id'], $from, $to]);
            $vals = $per->fetchAll(PDO::FETCH_KEY_PAIR);
            $byClinic[] = ['clinic' => (string)$c['name'], 'app_patients' => (int)$c['app_patients']]
                + array_combine(self::METRICS, array_map(static fn($m) => $k((int)($vals[$m] ?? 0)), self::METRICS));
        }

        return [
            'enabled' => $this->enabled(),
            'period_days' => $days,
            'from' => $from,
            'to' => $to,
            'k_min' => self::K_MIN,
            'app_patients' => $k($appPatients),
            'totals' => $totals,
            'weeks' => $weeks,
            'clinics' => $byClinic,
            'clinics_hidden' => $hidden,
        ];
    }
}
