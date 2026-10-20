<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Core\Security\TenantGuard;
use PDO;

/**
 * Dobras cutâneas por protocolo validado (NI2-439), integradas à avaliação antropométrica (NI2-7).
 *
 * - Registro imutável com proveniência (protocolo, versão do catálogo, equação, coeficientes, referência).
 * - Correção cria nova versão (mesmo record_group_id) e marca a anterior como 'superseded'; exige motivo.
 * - Alertas de medição improvável: valor incomum, leituras discrepantes, mudança brusca desde a medição anterior.
 * - Série temporal só compara medições do MESMO protocolo (equações diferentes não são intercambiáveis).
 * - Não estima composição por foto nem envia imagens a terceiros.
 */
class SkinfoldService
{
    /** Mudança relativa de uma dobra, desde a medição anterior, que gera alerta (e mínimo absoluto em mm). */
    private const SITE_CHANGE_RATIO = 0.25;
    private const SITE_CHANGE_MIN_MM = 3.0;
    private const SITE_CHANGE_WINDOW_DAYS = 120;
    /** Variação de % de gordura em até 30 dias que gera alerta (pontos percentuais). */
    private const FAT_CHANGE_PP_30D = 5.0;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** Sexo para a equação a partir do cadastro; null quando não informado ou "Outro" (o profissional escolhe). */
    public static function sexFromPatient(?string $gender): ?string
    {
        return match (LabelMap::canonicalGender($gender)) {
            'Masculino' => 'male',
            'Feminino' => 'female',
            default => null,
        };
    }

    /** Idade em anos completos na data da avaliação; null sem data de nascimento válida. */
    public static function ageAt(?string $birthDate, string $assessedAt): ?int
    {
        if (!$birthDate || !preg_match('/^\d{4}-\d{2}-\d{2}/', $birthDate)) {
            return null;
        }
        try {
            $birth = new \DateTimeImmutable(substr($birthDate, 0, 10));
            $at = new \DateTimeImmutable(substr($assessedAt, 0, 10));
        } catch (\Throwable) {
            return null;
        }
        if ($birth > $at) {
            return null;
        }
        return (int)$birth->diff($at)->y;
    }

    /**
     * Registra uma medição de dobras.
     *
     * @param array{protocol?: string, equation_sex?: string, assessed_at?: string, assessment_id?: int|string|null,
     *              weight_kg?: float|string|null, readings?: array, notes?: string|null} $data
     * @throws InvalidAnthropometricDataException
     */
    public function record(int $patientId, int $professionalId, array $data, ?int $userId = null): array
    {
        return $this->transaction(fn() => $this->insertMeasurement($patientId, $professionalId, $data, $userId, null, null));
    }

    /**
     * Corrige uma medição: grava nova versão e mantém a anterior como 'superseded' (nada é apagado).
     */
    public function correct(int $measurementId, int $professionalId, array $data, string $reason, ?int $userId = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidAnthropometricDataException('Descreva o motivo da correção (mínimo de 10 caracteres). Ele fica no histórico da medição.');
        }

        return $this->transaction(function () use ($measurementId, $professionalId, $data, $reason, $userId) {
            $current = $this->lockActive($measurementId);
            $up = $this->db->prepare("UPDATE skinfold_measurements SET status = 'superseded'
                WHERE id = :id AND clinic_id = :c AND status = 'active'");
            $up->execute(['id' => $measurementId, 'c' => TenantContext::getClinicId()]);
            if ($up->rowCount() !== 1) {
                throw new InvalidAnthropometricDataException('Esta medição já foi corrigida por outra pessoa. Recarregue a página.');
            }
            return $this->insertMeasurement((int)$current['patient_id'], $professionalId, $data, $userId, $current, $reason);
        });
    }

    private function insertMeasurement(int $patientId, int $professionalId, array $data, ?int $userId, ?array $previous, ?string $reason): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $patient = $this->db->prepare('SELECT birth_date, gender FROM patients WHERE id = :id AND clinic_id = :c');
        $patient->execute(['id' => $patientId, 'c' => $clinicId]);
        $patientRow = $patient->fetch(PDO::FETCH_ASSOC) ?: [];

        $assessedAt = self::normalizeDate($data['assessed_at'] ?? null);
        $protocol = (string)($data['protocol'] ?? '');
        $sex = in_array($data['equation_sex'] ?? null, ['male', 'female'], true) ? $data['equation_sex'] : self::sexFromPatient($patientRow['gender'] ?? null);
        $age = self::ageAt($patientRow['birth_date'] ?? null, $assessedAt);

        // Vínculo opcional com a avaliação antropométrica (NI2-7): o peso dela entra no cálculo de massa gorda/magra.
        $assessmentId = isset($data['assessment_id']) && (int)$data['assessment_id'] > 0 ? (int)$data['assessment_id'] : null;
        $weight = null;
        if ($assessmentId !== null) {
            $a = $this->db->prepare('SELECT weight_kg FROM anthropometric_assessments WHERE id = :id AND clinic_id = :c AND patient_id = :p');
            $a->execute(['id' => $assessmentId, 'c' => $clinicId, 'p' => $patientId]);
            $w = $a->fetchColumn();
            if ($w === false) {
                throw new InvalidAnthropometricDataException('A avaliação antropométrica escolhida não pertence a este paciente.');
            }
            $weight = (float)$w;
        } elseif (isset($data['weight_kg']) && trim((string)$data['weight_kg']) !== '') {
            $weight = (float)str_replace(',', '.', (string)$data['weight_kg']);
            if (!is_finite($weight) || $weight < 10 || $weight > 400) {
                throw new InvalidAnthropometricDataException('Peso fora da faixa plausível (10 a 400 kg).');
            }
        }

        $calc = SkinfoldCalculator::calculate($protocol, $sex, $age, (array)($data['readings'] ?? []), $weight);
        $warnings = array_merge($calc['warnings'], $this->trendWarnings($patientId, $protocol, $calc, $assessedAt, $previous));

        $notes = isset($data['notes']) && trim((string)$data['notes']) !== '' ? mb_substr(trim((string)$data['notes']), 0, 2000) : null;
        $version = $previous ? (int)$previous['version'] + 1 : 1;
        $groupId = $previous ? (int)($previous['record_group_id'] ?: $previous['id']) : null;

        $ins = $this->db->prepare("
            INSERT INTO skinfold_measurements (
                clinic_id, patient_id, professional_id, assessment_id, record_group_id, version, status,
                supersedes_id, correction_reason, protocol_code, catalog_version, equation_sex, age_years, assessed_at,
                sites_json, sum_mm, body_density, body_fat_pct, weight_kg, fat_mass_kg, lean_mass_kg,
                warnings_json, provenance_json, notes, created_by_user_id
            ) VALUES (
                :clinic_id, :patient_id, :professional_id, :assessment_id, :group_id, :version, 'active',
                :supersedes_id, :reason, :protocol, :catalog_version, :sex, :age, :assessed_at,
                :sites, :sum_mm, :density, :fat_pct, :weight, :fat_mass, :lean_mass,
                :warnings, :provenance, :notes, :user_id
            )
        ");
        $ins->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'assessment_id' => $assessmentId,
            'group_id' => $groupId,
            'version' => $version,
            'supersedes_id' => $previous ? (int)$previous['id'] : null,
            'reason' => $reason,
            'protocol' => $protocol,
            'catalog_version' => $calc['catalog_version'],
            'sex' => $calc['sex'],
            'age' => $calc['age'],
            'assessed_at' => $assessedAt,
            'sites' => json_encode($calc['sites'], JSON_THROW_ON_ERROR),
            'sum_mm' => $calc['sum_mm'],
            'density' => $calc['body_density'],
            'fat_pct' => $calc['body_fat_pct'],
            'weight' => $weight,
            'fat_mass' => $calc['fat_mass_kg'],
            'lean_mass' => $calc['lean_mass_kg'],
            'warnings' => json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'provenance' => json_encode($calc['provenance'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'notes' => $notes,
            'user_id' => $userId,
        ]);
        $id = (int)$this->db->lastInsertId();
        if ($groupId === null) {
            $this->db->prepare('UPDATE skinfold_measurements SET record_group_id = :id WHERE id = :id2')->execute(['id' => $id, 'id2' => $id]);
            $groupId = $id;
        }

        (new AuditLogger($this->db))->log($previous ? 'SKINFOLD_CORRECTED' : 'SKINFOLD_RECORDED', 'skinfold_measurements', (string)$id, [
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'protocol' => $protocol,
            'catalog_version' => $calc['catalog_version'],
            'version' => $version,
            'record_group_id' => $groupId,
            'supersedes_id' => $previous ? (int)$previous['id'] : null,
            'correction_reason' => $reason,
            'body_fat_pct' => $calc['body_fat_pct'],
            'previous_body_fat_pct' => $previous ? (float)$previous['body_fat_pct'] : null,
            'warnings' => array_column($warnings, 'code'),
        ]);

        return ['id' => $id, 'record_group_id' => $groupId, 'version' => $version, 'warnings' => $warnings] + $calc;
    }

    /**
     * Alertas comparando com a medição ativa anterior do mesmo protocolo.
     *
     * @return list<array{code: string, site: ?string, message: string}>
     */
    private function trendWarnings(int $patientId, string $protocol, array $calc, string $assessedAt, ?array $replacing): array
    {
        $st = $this->db->prepare("SELECT id, assessed_at, sites_json, body_fat_pct FROM skinfold_measurements
            WHERE clinic_id = :c AND patient_id = :p AND protocol_code = :pr AND status = 'active'
              AND assessed_at <= :at AND id <> :exclude
            ORDER BY assessed_at DESC, id DESC LIMIT 1");
        $st->execute(['c' => TenantContext::getClinicId(), 'p' => $patientId, 'pr' => $protocol, 'at' => $assessedAt,
            'exclude' => $replacing ? (int)$replacing['id'] : 0]);
        $prev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prev) {
            return [];
        }
        $days = (int)abs((new \DateTimeImmutable(substr((string)$prev['assessed_at'], 0, 10)))->diff(new \DateTimeImmutable(substr($assessedAt, 0, 10)))->days);
        $out = [];
        if ($days <= self::SITE_CHANGE_WINDOW_DAYS) {
            $prevSites = json_decode((string)$prev['sites_json'], true) ?: [];
            foreach ($calc['sites'] as $site => $s) {
                $before = (float)($prevSites[$site]['value_mm'] ?? 0);
                if ($before <= 0) {
                    continue;
                }
                $diff = abs($s['value_mm'] - $before);
                if ($diff >= self::SITE_CHANGE_MIN_MM && $diff / $before > self::SITE_CHANGE_RATIO) {
                    $out[] = ['code' => 'site_jump', 'site' => $site, 'message' => sprintf(
                        'Dobra %s mudou de %s para %s mm em %d dias. Confira o ponto anatômico e a técnica.',
                        SkinfoldProtocolCatalog::SITES[$site]['label'],
                        number_format($before, 1, ',', '.'), number_format($s['value_mm'], 1, ',', '.'), $days
                    )];
                }
            }
        }
        $deltaFat = $calc['body_fat_pct'] - (float)$prev['body_fat_pct'];
        if ($days <= 30 && abs($deltaFat) >= self::FAT_CHANGE_PP_30D) {
            $out[] = ['code' => 'fat_jump', 'site' => null, 'message' => sprintf(
                'Variação de %s pontos percentuais de gordura em %d dias é improvável. Confira as medidas.',
                number_format($deltaFat, 1, ',', '.'), $days
            )];
        }
        return $out;
    }

    /** Medições ativas do paciente (mais recentes primeiro), com proveniência decodificada. */
    public function listForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $st = $this->db->prepare("SELECT s.*, u.name AS professional_name
            FROM skinfold_measurements s
            JOIN professionals pr ON pr.id = s.professional_id
            LEFT JOIN users u ON u.id = pr.user_id
            WHERE s.clinic_id = :c AND s.patient_id = :p AND s.status = 'active'
            ORDER BY s.assessed_at DESC, s.id DESC");
        $st->execute(['c' => $clinicId, 'p' => $patientId]);
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Todas as versões de uma medição (da mais nova para a original). */
    public function history(int $measurementId): array
    {
        $clinicId = TenantContext::getClinicId();
        $st = $this->db->prepare('SELECT record_group_id FROM skinfold_measurements WHERE id = :id AND clinic_id = :c');
        $st->execute(['id' => $measurementId, 'c' => $clinicId]);
        $group = $st->fetchColumn();
        if ($group === false) {
            throw new InvalidAnthropometricDataException('Medição não encontrada.');
        }
        $h = $this->db->prepare("SELECT s.*, u.name AS professional_name FROM skinfold_measurements s
            JOIN professionals pr ON pr.id = s.professional_id LEFT JOIN users u ON u.id = pr.user_id
            WHERE s.clinic_id = :c AND s.record_group_id = :g ORDER BY s.version DESC");
        $h->execute(['c' => $clinicId, 'g' => (int)$group]);
        return array_map([self::class, 'hydrate'], $h->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $measurementId): ?array
    {
        $st = $this->db->prepare("SELECT s.*, u.name AS professional_name FROM skinfold_measurements s
            JOIN professionals pr ON pr.id = s.professional_id LEFT JOIN users u ON u.id = pr.user_id
            WHERE s.id = :id AND s.clinic_id = :c");
        $st->execute(['id' => $measurementId, 'c' => TenantContext::getClinicId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Série temporal de % de gordura de UM protocolo, com proveniência por ponto e texto acessível.
     */
    public function evolution(int $patientId, string $protocol): array
    {
        $p = SkinfoldProtocolCatalog::protocol($protocol);
        $points = [];
        foreach (array_reverse($this->listForPatient($patientId)) as $m) {
            if ($m['protocol_code'] !== $protocol) {
                continue;
            }
            $points[] = [
                'id' => (int)$m['id'],
                'date' => substr((string)$m['assessed_at'], 0, 10),
                'body_fat_pct' => (float)$m['body_fat_pct'],
                'sum_mm' => (float)$m['sum_mm'],
                'body_density' => (float)$m['body_density'],
                'version' => (int)$m['version'],
                'catalog_version' => $m['catalog_version'],
                'professional_name' => $m['professional_name'],
                'age_years' => (int)$m['age_years'],
            ];
        }
        $summary = 'Sem medições com este protocolo.';
        if ($points !== []) {
            $first = $points[0];
            $last = end($points);
            $summary = count($points) === 1
                ? sprintf('Uma medição (%s): %s%% de gordura.', self::br($first['date']), number_format($first['body_fat_pct'], 1, ',', '.'))
                : sprintf('%d medições de %s a %s: de %s%% para %s%% de gordura (%s pontos percentuais).',
                    count($points), self::br($first['date']), self::br($last['date']),
                    number_format($first['body_fat_pct'], 1, ',', '.'), number_format($last['body_fat_pct'], 1, ',', '.'),
                    ($last['body_fat_pct'] - $first['body_fat_pct'] >= 0 ? '+' : '') . number_format($last['body_fat_pct'] - $first['body_fat_pct'], 1, ',', '.'));
        }
        return [
            'protocol' => $protocol,
            'protocol_name' => $p['name'],
            'provenance' => ['equation' => $p['equation_text'], 'reference' => $p['reference'], 'conversion' => SkinfoldProtocolCatalog::CONVERSIONS[$p['conversion']]['equation_text']],
            'points' => $points,
            'accessible_summary' => $summary,
        ];
    }

    private function lockActive(int $id): array
    {
        $sql = "SELECT * FROM skinfold_measurements WHERE id = :id AND clinic_id = :c";
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $st = $this->db->prepare($sql);
        $st->execute(['id' => $id, 'c' => TenantContext::getClinicId()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidAnthropometricDataException('Medição não encontrada.');
        }
        if ($row['status'] !== 'active') {
            throw new InvalidAnthropometricDataException('Esta medição já foi corrigida. Corrija a versão mais recente.');
        }
        return $row;
    }

    private function transaction(callable $fn): array
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $r = $fn();
            if ($own) {
                $this->db->commit();
            }
            return $r;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private static function hydrate(array $row): array
    {
        $row['sites'] = json_decode((string)$row['sites_json'], true) ?: [];
        $row['warnings'] = json_decode((string)($row['warnings_json'] ?? '[]'), true) ?: [];
        $row['provenance'] = json_decode((string)$row['provenance_json'], true) ?: [];
        return $row;
    }

    private static function normalizeDate(mixed $value): string
    {
        $v = trim((string)$value);
        if ($v === '') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime(str_replace('T', ' ', $v));
        if ($ts === false) {
            throw new InvalidAnthropometricDataException('Data da avaliação inválida.');
        }
        if ($ts > time() + 300) {
            throw new InvalidAnthropometricDataException('A data da avaliação não pode estar no futuro.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function br(string $date): string
    {
        return implode('/', array_reverse(explode('-', substr($date, 0, 10))));
    }
}
