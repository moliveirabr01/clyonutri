<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Engagement;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class EngagementService
{
    public const HYDRATION_MIN_ML = 1;
    public const HYDRATION_MAX_ML = 5000;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function logHydration(int $patientId, int $amountMl): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        // NI2-89: registro precisa ser um volume plausível (um copo a uma garrafa grande).
        if ($amountMl < self::HYDRATION_MIN_ML || $amountMl > self::HYDRATION_MAX_ML) {
            throw new \InvalidArgumentException('Informe um volume de água entre ' . self::HYDRATION_MIN_ML . ' e ' . self::HYDRATION_MAX_ML . ' ml.');
        }

        // Horário no fuso da aplicação: o total "de hoje" (date(logged_at) = hoje) usa o mesmo fuso.
        // Com CURRENT_TIMESTAMP (UTC no SQLite), registros feitos depois das 21h contavam para o dia seguinte.
        $stmt = $this->db->prepare("
            INSERT INTO hydration_logs (clinic_id, patient_id, amount_ml, logged_at)
            VALUES (:clinic_id, :patient_id, :amount_ml, :logged_at)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'amount_ml' => $amountMl,
            'logged_at' => (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s'),
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'amount_ml' => $amountMl,
        ];
    }

    /** NI2-96: escala do check-in do app ("quanto do plano você seguiu hoje"). */
    public const CHECKIN_ADHERENCE_STEPS = [0, 25, 50, 75, 100];
    public const CHECKIN_NOTES_MAX = 500;

    /**
     * NI2-96: check-in diário da paciente pelo app. Um por dia (no fuso da aplicação); um segundo envio no
     * mesmo dia corrige o primeiro. O dia vem sempre do servidor. Auditado só com ids.
     * @return array{checkin: array, created: bool}
     */
    public function submitDailyCheckin(int $patientId, array $input): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $adh = $input['meal_adherence_pct'] ?? null;
        if (!is_int($adh) || !in_array($adh, self::CHECKIN_ADHERENCE_STEPS, true)) {
            throw new \InvalidArgumentException('Escolha quanto do plano você seguiu hoje (0, 25, 50, 75 ou 100%).');
        }
        $level = static function ($v, string $label): ?int {
            if ($v === null) {
                return null;
            }
            if (!is_int($v) || $v < 1 || $v > 5) {
                throw new \InvalidArgumentException("{$label} vai de 1 a 5.");
            }
            return $v;
        };
        $energy = $level($input['energy_level'] ?? null, 'A energia');
        $hunger = $level($input['hunger_level'] ?? null, 'A fome');
        $notes = $input['notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            throw new \InvalidArgumentException('A observação precisa ser um texto.');
        }
        $notes = $notes !== null ? trim($notes) : null;
        if ($notes === '') {
            $notes = null;
        }
        if ($notes !== null && mb_strlen($notes) > self::CHECKIN_NOTES_MAX) {
            throw new \InvalidArgumentException('A observação pode ter até ' . self::CHECKIN_NOTES_MAX . ' caracteres.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $today = $now->format('Y-m-d');
        $stamp = $now->format('Y-m-d H:i:s');
        $existing = $this->findCheckin($clinicId, $patientId, $today);
        if ($existing) {
            $this->db->prepare('UPDATE checkins SET energy_level = ?, hunger_level = ?, meal_adherence_pct = ?, notes = ?, updated_at = ? WHERE id = ? AND clinic_id = ?')
                ->execute([$energy, $hunger, $adh, $notes, $stamp, (int)$existing['id'], $clinicId]);
            $id = (int)$existing['id'];
        } else {
            $this->db->prepare('INSERT INTO checkins (clinic_id, patient_id, energy_level, hunger_level, meal_adherence_pct, notes, checkin_date, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$clinicId, $patientId, $energy, $hunger, $adh, $notes, $today, $stamp, $stamp]);
            $id = (int)$this->db->lastInsertId();
        }
        (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log($existing ? 'PATIENT_CHECKIN_UPDATED' : 'PATIENT_CHECKIN_RECORDED', 'checkins', (string)$id, ['patient_id' => $patientId]);

        return ['checkin' => $this->presentCheckin($this->findCheckin($clinicId, $patientId, $today)), 'created' => !$existing];
    }

    /** Check-in de hoje da paciente (ou null). */
    public function todayCheckin(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        $row = $this->findCheckin($clinicId, $patientId, (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d'));
        return $row ? $this->presentCheckin($row) : null;
    }

    private function findCheckin(int $clinicId, int $patientId, string $day): ?array
    {
        $st = $this->db->prepare('SELECT * FROM checkins WHERE clinic_id = ? AND patient_id = ? AND checkin_date = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$clinicId, $patientId, $day]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st->closeCursor();
        return $row;
    }

    private function presentCheckin(?array $r): ?array
    {
        if (!$r) {
            return null;
        }
        return [
            'id' => (int)$r['id'],
            'checkin_date' => (string)$r['checkin_date'],
            'meal_adherence_pct' => $r['meal_adherence_pct'] !== null ? (int)$r['meal_adherence_pct'] : null,
            'energy_level' => $r['energy_level'] !== null ? (int)$r['energy_level'] : null,
            'hunger_level' => $r['hunger_level'] !== null ? (int)$r['hunger_level'] : null,
            'notes' => $r['notes'] !== null ? (string)$r['notes'] : null,
        ];
    }

    public function recordCheckin(int $patientId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            INSERT INTO checkins (clinic_id, patient_id, energy_level, hunger_level, meal_adherence_pct, notes, checkin_date)
            VALUES (:clinic_id, :patient_id, :energy, :hunger, :adherence, :notes, :date)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'energy' => $data['energy_level'] ?? null,
            'hunger' => $data['hunger_level'] ?? null,
            'adherence' => $data['meal_adherence_pct'] ?? null,
            'notes' => $data['notes'] ?? null,
            'date' => $data['checkin_date'] ?? date('Y-m-d'),
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'patient_id' => $patientId,
            'meal_adherence_pct' => $data['meal_adherence_pct'] ?? null,
        ];
    }

    public function getPatientAdherenceSummary(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // Total de água registrada hoje
        $today = date('Y-m-d');
        $hStmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND date(logged_at) = :today
        ");
        $hStmt->execute([
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
            'today' => $today,
        ]);
        $waterToday = (int)$hStmt->fetchColumn();

        // Média de adesão dos últimos check-ins
        $cStmt = $this->db->prepare("
            SELECT AVG(meal_adherence_pct) FROM checkins
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ");
        $cStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $avgAdherence = round((float)$cStmt->fetchColumn(), 1);

        return [
            'patient_id' => $patientId,
            'water_intake_today_ml' => $waterToday,
            'average_adherence_pct' => $avgAdherence,
        ];
    }

    /**
     * Análise longitudinal semanal (NI2-117)
     */
    public function getLongitudinalTrends(int $patientId, int $weeks = 4): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT checkin_date, energy_level, hunger_level, meal_adherence_pct, notes
            FROM checkins
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
            ORDER BY checkin_date ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':patient_id', $patientId, PDO::PARAM_INT);
        $stmt->bindValue(':clinic_id', $clinicId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $weeks * 7, PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();

        return [
            'patient_id' => $patientId,
            'period_weeks' => $weeks,
            'total_checkins' => count($records),
            'history' => $records,
        ];
    }

    /**
     * Retorna métricas de adesão alimentar consolidada da clínica para o Dashboard (NI2-297 / PX-04).
     * Elimina fallbacks fictícios (como 94.0%) e deriva estritamente de dados elegíveis reais.
     */
    public function getClinicAdherenceMetrics(int $clinicId, int $days = 30): array
    {
        if ($clinicId <= 0) {
            throw new \InvalidArgumentException("Clinic ID inválido para cálculo de adesão.");
        }

        // População elegível: total de pacientes cadastrados no tenant
        $stmtPatients = $this->db->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = :cid");
        $stmtPatients->execute(['cid' => $clinicId]);
        $totalEligiblePatients = (int)$stmtPatients->fetchColumn();

        // Limite da janela temporal em dias corridos
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) AS total_checkins,
                COUNT(meal_adherence_pct) AS adherence_responses_count,
                COUNT(DISTINCT patient_id) AS responding_patients_count,
                AVG(meal_adherence_pct) AS avg_adherence
            FROM checkins 
            WHERE clinic_id = :cid 
              AND checkin_date >= :start_date
              AND meal_adherence_pct IS NOT NULL
        ");
        $stmt->execute(['cid' => $clinicId, 'start_date' => $startDate]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $responsesCount = (int)($row['adherence_responses_count'] ?? 0);
        $respondingPatients = (int)($row['responding_patients_count'] ?? 0);
        $rawAvg = $row['avg_adherence'] !== null ? (float)$row['avg_adherence'] : null;

        $hasData = ($responsesCount > 0 && $rawAvg !== null);

        return [
            'has_data' => $hasData,
            'average_pct' => $hasData ? round($rawAvg, 1) : null,
            'formatted_value' => $hasData ? number_format(round($rawAvg, 1), 1, ',', '.') . '%' : 'Sem dados',
            'responses_count' => $responsesCount,
            'responding_patients_count' => $respondingPatients,
            'total_eligible_patients' => $totalEligiblePatients,
            'period_days' => $days,
            'period_label' => "Últimos {$days} dias",
        ];
    }
}
