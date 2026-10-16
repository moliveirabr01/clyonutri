<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Monta o prontuário do paciente para impressão.
 *
 * - Só leitura: nenhum registro clínico é criado ou alterado.
 * - Por padrão imprime a versão vigente de cada registro e indica retificações;
 *   o histórico completo (versões substituídas) é opcional.
 * - Integridade verificada e exibida no documento. Se falhar, o documento sai
 *   com aviso destacado (não é bloqueado, para não impedir o cuidado).
 * - Cada impressão gera evento de auditoria com quem imprimiu, período e opções.
 */
class MedicalRecordPrintService
{
    /** Campos técnicos que não vão para o papel. */
    private const HIDDEN_KEYS = [
        'teleconsultation_session_id', 'appointment_id', 'recorded_during_telehealth', 'source',
        'type', 'recorded_by', 'revised_by', 'scheduled_at', 'concluded_at',
    ];

    private const LABELS = [
        'text' => 'Anotação',
        'notes' => 'Anotações',
        'evolution_summary' => 'Resumo da evolução',
        'goals' => 'Metas',
        'category' => 'Categoria',
        'conduct' => 'Conduta',
        'complaint' => 'Queixa',
        'diagnosis' => 'Diagnóstico nutricional',
        'plan' => 'Plano',
        'observations' => 'Observações',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * @param ?string $from data local Y-m-d (inclusive)
     * @param ?string $to   data local Y-m-d (inclusive)
     */
    public function build(int $patientId, int $userId, ?string $from, ?string $to, bool $includeHistory): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        [$fromDate, $toDate] = $this->validateRange($from, $to);

        $patient = $this->fetchOne('SELECT id, name, birth_date, gender, email, phone FROM patients WHERE id = ? AND clinic_id = ?', [$patientId, $clinicId]);
        $clinic = $this->fetchOne('SELECT name, email FROM clinics WHERE id = ?', [$clinicId]);
        $printer = $this->fetchOne("
            SELECT u.name, p.crn, p.crn_region FROM users u
            LEFT JOIN professionals p ON p.user_id = u.id
            WHERE u.id = ?", [$userId]) ?? ['name' => 'Usuário', 'crn' => null, 'crn_region' => null];

        $records = new ClinicalRecordService($this->db);
        $integrity = $records->verifyIntegrity($patientId);
        $timeline = $records->getPatientTimeline($patientId);

        // Cadeia de retificação: qual versão substitui qual.
        $supersededBy = [];
        foreach ($timeline as $row) {
            if (!empty($row['supersedes_id'])) {
                $supersededBy[(int)$row['supersedes_id']] = $row;
            }
        }

        $entries = [];
        foreach ($timeline as $row) {
            $isSuperseded = isset($supersededBy[(int)$row['id']]);
            if ($isSuperseded && !$includeHistory) {
                continue;
            }
            $localDate = $this->localDate((string)$row['created_at']);
            if (($fromDate && $localDate < $fromDate) || ($toDate && $localDate > $toDate)) {
                continue;
            }
            $content = is_array($row['content']) ? $row['content'] : [];
            $entries[] = [
                'version' => (int)$row['version'],
                'title' => (string)$row['title'],
                'created_at_local' => $this->localDateTime((string)$row['created_at']),
                'author' => (string)$row['professional_name'],
                'fields' => $this->flatten($content),
                'via_teleconsultation' => !empty($content['recorded_during_telehealth']),
                'is_revision' => !empty($row['supersedes_id']),
                'revision_reason' => $row['revision_reason'] ?? null,
                'superseded_by_version' => $isSuperseded ? (int)$supersededBy[(int)$row['id']]['version'] : null,
                'is_retracted' => !empty($row['is_retracted']),
            ];
        }

        $anthropometry = $this->anthropometry($patientId, $clinicId, $fromDate, $toDate);
        $summary = (new AnamnesisService($this->db))->getClinicalSummaryForPrescription($patientId);

        $printedAt = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        $this->audit($clinicId, $userId, $patientId, [
            'from' => $fromDate,
            'to' => $toDate,
            'include_history' => $includeHistory,
            'records_printed' => count($entries),
            'integrity_valid' => (bool)$integrity['is_valid'],
        ]);

        return [
            'clinic' => $clinic,
            'patient' => $patient + ['age' => $this->age($patient['birth_date'] ?? null)],
            'printer' => $printer,
            'printed_at_local' => $printedAt->format('d/m/Y \à\s H:i'),
            'timezone' => date_default_timezone_get(),
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'include_history' => $includeHistory,
            'integrity' => [
                'is_valid' => (bool)$integrity['is_valid'],
                'total_records' => (int)($integrity['total_records'] ?? 0),
                'chain_fingerprint' => isset($integrity['latest_hash']) ? substr((string)$integrity['latest_hash'], 0, 16) : null,
            ],
            'summary' => [
                'has_data' => (bool)$summary['has_data'],
                'allergy_label' => $summary['allergy_label'] ?? null,
                'allergies' => $summary['allergies'] ?? null,
                'restrictions' => $summary['restrictions'] ?? null,
                'objectives' => $summary['objectives'] ?? null,
                'source' => $summary['source'] ?? null,
            ],
            'entries' => $entries,
            'anthropometry' => $anthropometry,
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function validateRange(?string $from, ?string $to): array
    {
        $check = static function (?string $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v) {
                throw new InvalidArgumentException('Data inválida no período. Use o formato AAAA-MM-DD.');
            }
            return $v;
        };
        $f = $check($from);
        $t = $check($to);
        if ($f && $t && $f > $t) {
            throw new InvalidArgumentException('A data inicial é posterior à data final.');
        }
        return [$f, $t];
    }

    /** Conteúdo do registro em pares rótulo/valor, sem campos técnicos. */
    private function flatten(array $content, string $prefix = ''): array
    {
        $out = [];
        foreach ($content as $key => $value) {
            if (in_array((string)$key, self::HIDDEN_KEYS, true) || $value === null || $value === '' || $value === []) {
                continue;
            }
            $label = self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', (string)$key));
            $label = $prefix !== '' ? $prefix . ' › ' . $label : $label;
            if (is_array($value)) {
                $out = array_merge($out, $this->flatten($value, $label));
            } elseif (is_bool($value)) {
                $out[] = ['label' => $label, 'value' => $value ? 'Sim' : 'Não'];
            } else {
                $out[] = ['label' => $label, 'value' => (string)$value];
            }
        }
        return $out;
    }

    private function anthropometry(int $patientId, int $clinicId, ?string $from, ?string $to): array
    {
        $stmt = $this->db->prepare('
            SELECT assessed_at, weight_kg, height_cm, bmi, waist_cm, hip_cm, whr, body_fat_pct, muscle_mass_pct
            FROM anthropometric_assessments
            WHERE patient_id = :p AND clinic_id = :c
            ORDER BY assessed_at ASC
        ');
        $stmt->execute(['p' => $patientId, 'c' => $clinicId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = substr((string)$row['assessed_at'], 0, 10);
            if (($from && $date < $from) || ($to && $date > $to)) {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function audit(int $clinicId, int $userId, int $patientId, array $meta): void
    {
        // Falha de auditoria impede a impressão (acesso a dado sensível precisa de rastro).
        $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details)
            VALUES (:c, :u, 'CLINICAL_RECORD_PRINTED', 'clinical_records', :r, :d)
        ")->execute([
            'c' => $clinicId,
            'u' => $userId,
            'r' => (string)$patientId,
            'd' => json_encode(['patient_id' => $patientId] + $meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** created_at dos registros é gravado no fuso da aplicação (APP_TIMEZONE), via date(). */
    private function localDateTime(string $stored): string
    {
        return (new DateTimeImmutable($stored, new DateTimeZone(date_default_timezone_get())))->format('d/m/Y H:i');
    }

    private function localDate(string $stored): string
    {
        return substr($stored, 0, 10);
    }

    private function age(?string $birthDate): ?int
    {
        if (!$birthDate) {
            return null;
        }
        try {
            return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
