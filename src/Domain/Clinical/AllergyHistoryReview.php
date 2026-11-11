<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Domain\Patient\PatientAllergyRecord;
use PDO;

/**
 * NI2-624: regra conservadora para alergia histórica contraditória.
 *
 * Se um grupo de alérgeno aparece numa versão concluída ANTERIOR da anamnese (ou no cadastro do paciente) e não
 * aparece na versão vigente — inclusive quando a vigente "nega alergias" —, o risco não é descartado em silêncio:
 *  - o grupo continua na trava de alérgenos (protege o plano, as receitas e a lista de compras);
 *  - o estado passa a "Exige revisão clínica", dizendo qual versão registrou o quê e quando.
 *
 * A revisão termina de duas formas, ambas registradas:
 *  - a nova anamnese concluída volta a registrar a alergia (deixa de haver contradição); ou
 *  - o profissional confirma "alergia descartada" (dismiss): fica em audit_logs (ALLERGY_HISTORY_DISMISSED) com
 *    grupo, versão vigente, autor e data. Uma versão posterior que volte a registrar o grupo reabre a revisão.
 * Nada é apagado: as versões da anamnese continuam como estão.
 */
final class AllergyHistoryReview
{
    public const ACTION = 'ALLERGY_HISTORY_DISMISSED';

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param callable(string): list<string> $groupsOf  texto → grupos de alérgeno (taxonomia do validador)
     * @param callable(string): bool $isDenial
     * @param list<string> $currentGroups grupos que a versão vigente (com o rascunho somado) já protege
     * @return list<array{group:string, source:string, version:int, date:string, text:string}>
     */
    public function findings(int $clinicId, int $patientId, array $currentGroups, callable $groupsOf, callable $isDenial): array
    {
        $versions = $this->concludedVersions($clinicId, $patientId);
        $seen = []; // grupo → registro mais recente que o traz
        foreach (array_slice($versions, 1) as $v) {
            $txt = trim((string)($v['allergies'] ?? ''));
            if ($txt === '' || $isDenial($txt)) {
                continue;
            }
            foreach ($groupsOf($txt) as $g) {
                $seen[$g] ??= ['group' => $g, 'source' => 'anamnese', 'version' => (int)$v['version'], 'date' => (string)$v['created_at'], 'text' => $txt];
            }
        }
        if ($versions) { // sem anamnese concluída o cadastro já é a fonte principal: não há contradição a apontar
            $rec = PatientAllergyRecord::read($this->db, $clinicId, $patientId);
            $txt = trim((string)$rec['allergies']);
            if ($txt !== '' && !$isDenial($txt)) {
                foreach ($groupsOf($txt) as $g) {
                    $seen[$g] ??= ['group' => $g, 'source' => 'cadastro', 'version' => 0, 'date' => '', 'text' => $txt];
                }
            }
        }
        $dismissed = $this->dismissals($clinicId, $patientId);
        $out = [];
        foreach ($seen as $g => $f) {
            if (in_array($g, $currentGroups, true)) {
                continue;
            }
            if (isset($dismissed[$g]) && $dismissed[$g] > $f['version']) {
                continue; // descartada pelo profissional depois do último registro do grupo; novo registro reabre
            }
            $out[] = $f;
        }
        return $out;
    }

    /** "v1 (20/10/2026) registrou amendoim; a versão vigente não registra." */
    public static function describe(array $f): string
    {
        $who = $f['source'] === 'cadastro' ? 'O cadastro do paciente' : 'A anamnese v' . $f['version'] . ($f['date'] !== '' && strtotime($f['date']) ? ' (' . date('d/m/Y', (int)strtotime($f['date'])) . ')' : '');
        return $who . ' registrou "' . mb_strimwidth($f['text'], 0, 80, '…', 'UTF-8') . '"; a anamnese vigente não registra ' . $f['group'] . '.';
    }

    /** O profissional confirma que a alergia foi descartada clinicamente. Registra e não apaga nada. */
    public function dismiss(int $clinicId, int $patientId, string $group, int $userId, string $reason): void
    {
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new \InvalidArgumentException('Escreva o motivo (pelo menos 5 letras): ele fica no histórico do paciente.');
        }
        $versions = $this->concludedVersions($clinicId, $patientId);
        (new AuditLogger($this->db))->log(self::ACTION, 'patients', (string)$patientId, [
            'clinic_id' => $clinicId, 'patient_id' => $patientId, 'group' => $group,
            'through_version' => $versions ? (int)$versions[0]['version'] : 0, 'actor_user_id' => $userId,
            'reason' => mb_substr($reason, 0, 300, 'UTF-8'),
        ]);
    }

    /** @return list<array{version:int|string, allergies:?string, created_at:?string}> mais recente primeiro */
    private function concludedVersions(int $clinicId, int $patientId): array
    {
        $draftCond = SchemaInfo::hasColumn($this->db, 'anamneses', 'is_draft') ? ' AND (is_draft = 0 OR is_draft IS NULL)' : '';
        $st = $this->db->prepare("SELECT version, allergies, created_at FROM anamneses WHERE clinic_id = ? AND patient_id = ?{$draftCond} ORDER BY version DESC, id DESC");
        $st->execute([$clinicId, $patientId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (isset($row['allergies']) && is_string($row['allergies'])) {
                $row['allergies'] = ClinicalEncryptionService::decrypt($row['allergies']);
            }
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,int> grupo → maior versão vigente em que foi descartado */
    private function dismissals(int $clinicId, int $patientId): array
    {
        try {
            $st = $this->db->prepare("SELECT details FROM audit_logs WHERE clinic_id = ? AND action = ? AND resource = 'patients' AND resource_id = ?");
            $st->execute([$clinicId, self::ACTION, (string)$patientId]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $j = json_decode((string)$d, true);
            if (is_array($j) && isset($j['group'])) {
                $out[$j['group']] = max($out[$j['group']] ?? -1, (int)($j['through_version'] ?? 0));
            }
        }
        return $out;
    }
}
