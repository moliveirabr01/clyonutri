<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Settings\PlatformSettings;
use DomainException;
use PDO;

/**
 * NI2-635: ponto único para a tela (consulta, prontuário, pré-consulta) usar o motor.
 *
 * Um módulo aparece para o paciente só quando TODAS as travas abrem:
 *  1. chave global feature_flag_clinical_protocols = 1 (nasce 0);
 *  2. a clínica ligou aquele protocolo (clinic_protocol_settings, nasce desligado);
 *  3. o protocolo tem versão aprovada e com conteúdo íntegro;
 *  4. o paciente tem a condição confirmada por profissional (relatada, em investigação ou documentada) e válida.
 *
 * Respostas: sempre uma linha nova por módulo (histórico longitudinal, sem sobrescrever). Rascunho e concluída
 * seguem a regra da NI2-605: a concluída vale; um rascunho mais novo aparece como alteração em andamento.
 */
final class ProtocolWorkspace
{
    public const FLAG = 'feature_flag_clinical_protocols';

    public function __construct(private PDO $db)
    {
    }

    public function globallyEnabled(): bool
    {
        return (string)PlatformSettings::get(self::FLAG, '0', $this->db) === '1';
    }

    public function setClinicEnabled(int $clinicId, string $code, bool $enabled, int $userId): void
    {
        $approved = (new ProtocolCatalog($this->db))->approvedByCode($clinicId);
        if ($enabled && !isset($approved[$code])) {
            throw new DomainException('Só protocolo com versão aprovada pode ser ligado.');
        }
        $pid = $this->db->prepare('SELECT id FROM clinical_protocols WHERE code = ? AND (clinic_id IS NULL OR clinic_id = ?)');
        $pid->execute([$code, $clinicId]);
        $protocolId = (int)$pid->fetchColumn();
        if ($protocolId === 0) {
            throw new DomainException('Protocolo não encontrado.');
        }
        // MySQL conta "linhas alteradas" (0 se o valor já era o mesmo): por isso confere a existência antes.
        $ex = $this->db->prepare('SELECT id FROM clinic_protocol_settings WHERE clinic_id = ? AND protocol_id = ?');
        $ex->execute([$clinicId, $protocolId]);
        if ($ex->fetchColumn()) {
            $this->db->prepare('UPDATE clinic_protocol_settings SET enabled = ?, updated_by_user_id = ?, updated_at = ? WHERE clinic_id = ? AND protocol_id = ?')
                ->execute([$enabled ? 1 : 0, $userId, date('Y-m-d H:i:s'), $clinicId, $protocolId]);
        } else {
            $this->db->prepare('INSERT INTO clinic_protocol_settings (clinic_id, protocol_id, enabled, updated_by_user_id, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$clinicId, $protocolId, $enabled ? 1 : 0, $userId, date('Y-m-d H:i:s')]);
        }
    }

    /** @return list<array{code:string,name:string,version:int,version_id:int,definition:array,review_overdue:bool}> */
    public function activeModules(int $clinicId, int $patientId): array
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        if (!$this->globallyEnabled()) {
            return [];
        }
        $codes = (new PatientConditionService($this->db))->activatingCodes($clinicId, $patientId);
        $st = $this->db->prepare('SELECT p.code FROM clinic_protocol_settings s JOIN clinical_protocols p ON p.id = s.protocol_id WHERE s.clinic_id = ? AND s.enabled = 1');
        $st->execute([$clinicId]);
        $enabled = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        $approved = (new ProtocolCatalog($this->db))->approvedByCode($clinicId);
        foreach ($approved as $code => $module) {
            if ($module['kind'] === 'profile' && isset($enabled[$code])) $codes[] = $code;
        }
        $codes = array_values(array_unique($codes));
        $out = [];
        foreach ($codes as $c) {
            if (isset($enabled[$c], $approved[$c])) {
                $out[] = $approved[$c];
            }
        }
        usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Questionário montado com as respostas vigentes (+ rascunho mais novo por cima, para continuar editando).
     * @return array{modules: list<array>, composed: array, answers: array, draft_in_progress: bool}
     */
    public function questionnaire(int $clinicId, int $patientId, string $audience = 'professional'): array
    {
        $modules = $this->activeModules($clinicId, $patientId);
        $cur = $this->currentAnswers($clinicId, $patientId, $modules);
        return ['modules' => $modules, 'composed' => ProtocolEngine::compose($modules, $cur['answers'], $audience),
            'answers' => $cur['answers'], 'draft_in_progress' => $cur['draft'], 'revision' => $this->revision($clinicId, $patientId)];
    }

    /** Salva respostas (rascunho ou concluída). Só chaves do questionário; concluir exige módulos completos e sem erro. */
    public function save(int $clinicId, int $patientId, array $answers, string $provenance, bool $conclude, ?int $userId, ?int $anamnesisId = null, ?int $baseRevision = null): array
    {
        if (!in_array($provenance, ['patient', 'professional'], true)) {
            throw new \InvalidArgumentException('Origem das respostas inválida.');
        }
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM patients WHERE id = ? AND clinic_id = ?' . ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$patientId, $clinicId]);
            if (!$lock->fetchColumn()) throw new DomainException('Paciente não encontrado.');
            $result = $this->saveLocked($clinicId, $patientId, $answers, $provenance, $conclude, $userId, $anamnesisId, $baseRevision);
            if ($own) $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function saveLocked(int $clinicId, int $patientId, array $answers, string $provenance, bool $conclude, ?int $userId, ?int $anamnesisId, ?int $baseRevision): array
    {
        $modules = $this->activeModules($clinicId, $patientId);
        if ($modules === []) {
            throw new DomainException('Nenhum módulo clínico ativo para este paciente.');
        }
        // NI2-652: alguém gravou depois que esta tela abriu → conflito explícito, nada é sobrescrito em silêncio
        if ($baseRevision !== null && $baseRevision !== $this->revision($clinicId, $patientId)) {
            throw new ProtocolSaveConflict('Outra pessoa salvou respostas destes módulos depois que você abriu a página. Nada foi gravado: confira as respostas atuais abaixo e salve de novo.');
        }
        $base = $this->currentAnswers($clinicId, $patientId, $modules)['answers'];
        $audience = $provenance === 'patient' ? 'patient' : 'professional';
        $composed0 = ProtocolEngine::compose($modules, $base, $audience);
        $merged = array_merge($base, ProtocolEngine::sanitize($composed0, $answers));
        $composed = ProtocolEngine::compose($modules, $merged, $audience);
        if ($conclude) {
            if ($composed['errors']) {
                throw new DomainException('Corrija as respostas marcadas antes de concluir.');
            }
            $missing = array_filter($composed['completeness'], static fn($c) => !$c['complete']);
            if ($missing) {
                throw new DomainException('Faltam respostas obrigatórias em: ' . implode(', ', array_column($missing, 'name')) . '.');
            }
        }
        $visibleKeys = array_column(array_filter($composed['items'], static fn($it) => $it['visible']), 'answer_key');
        $merged = array_intersect_key($merged, array_flip($visibleKeys));
        $keysByModule = [];
        foreach ($composed['items'] as $it) {
            foreach ($it['module_codes'] as $c) {
                $keysByModule[$c][] = $it['answer_key'];
            }
        }
        $ins = $this->db->prepare('INSERT INTO protocol_responses (clinic_id, patient_id, protocol_version_id, anamnesis_id, answers_json, provenance, is_draft, answered_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($modules as $m) {
            $mine = array_intersect_key($merged, array_flip($keysByModule[$m['code']] ?? []));
            $ins->execute([$clinicId, $patientId, $m['version_id'], $anamnesisId, json_encode($mine, JSON_UNESCAPED_UNICODE), $provenance, $conclude ? 0 : 1, $userId, date('Y-m-d H:i:s')]);
        }
        return $composed;
    }

    /** NI2-652: marca de versão das respostas do paciente (última linha gravada); 0 se não há nenhuma. */
    public function revision(int $clinicId, int $patientId): int
    {
        $st = $this->db->prepare('SELECT MAX(id) FROM protocol_responses WHERE clinic_id = ? AND patient_id = ?');
        $st->execute([$clinicId, $patientId]);
        return (int)$st->fetchColumn();
    }

    /**
     * NI2-652: comparação entre as duas últimas conclusões de cada módulo ativo (só concluídas; rascunho não entra).
     * @return list<array{code:string,name:string,from:string,to:string,changes:list<array{key:string,label:string,before:string,after:string}>}>
     */
    public function compare(int $clinicId, int $patientId): array
    {
        $modules = $this->activeModules($clinicId, $patientId);
        $items = [];
        foreach (ProtocolEngine::compose($modules, [])['items'] as $it) {
            $items[$it['answer_key']] = $it;
        }
        $fmt = static function ($v, ?array $it): string {
            if ($v === null || $v === '' || $v === []) {
                return '—';
            }
            $opts = $it['options'] ?? null;
            $vals = array_map(static fn($x) => is_array($opts) && isset($opts[(string)$x]) ? (string)$opts[(string)$x] : (string)$x, (array)$v);
            return implode(', ', $vals);
        };
        $out = [];
        foreach ($modules as $m) {
            $st = $this->db->prepare('SELECT answers_json, created_at FROM protocol_responses WHERE clinic_id = ? AND patient_id = ? AND is_draft = 0 AND protocol_version_id IN (SELECT id FROM clinical_protocol_versions WHERE protocol_id = (SELECT protocol_id FROM clinical_protocol_versions WHERE id = ?)) ORDER BY id DESC LIMIT 2');
            $st->execute([$clinicId, $patientId, $m['version_id']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) < 2) {
                continue;
            }
            $new = json_decode((string)$rows[0]['answers_json'], true) ?: [];
            $old = json_decode((string)$rows[1]['answers_json'], true) ?: [];
            $changes = [];
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
                $a = $fmt($old[$k] ?? null, $items[$k] ?? null);
                $b = $fmt($new[$k] ?? null, $items[$k] ?? null);
                if ($a !== $b) {
                    $changes[] = ['key' => (string)$k, 'label' => (string)($items[$k]['text'] ?? $k), 'before' => $a, 'after' => $b];
                }
            }
            $out[] = ['code' => $m['code'], 'name' => $m['name'], 'from' => (string)$rows[1]['created_at'], 'to' => (string)$rows[0]['created_at'], 'changes' => $changes];
        }
        return $out;
    }

    /** @return array{answers: array, draft: bool} */
    private function currentAnswers(int $clinicId, int $patientId, array $modules): array
    {
        $answers = [];
        $draft = false;
        foreach ($modules as $m) {
            $st = $this->db->prepare('SELECT id, answers_json, is_draft FROM protocol_responses WHERE clinic_id = ? AND patient_id = ? AND protocol_version_id = ? ORDER BY id DESC');
            $st->execute([$clinicId, $patientId, $m['version_id']]);
            $concluded = null;
            $newerDraft = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['is_draft'] === 0) {
                    $concluded = $r;
                    break;
                }
                $newerDraft ??= $r;
            }
            foreach ([$concluded, $newerDraft] as $r) {
                if ($r !== null) {
                    $answers = array_merge($answers, json_decode((string)$r['answers_json'], true) ?: []);
                }
            }
            $draft = $draft || $newerDraft !== null;
        }
        return ['answers' => $answers, 'draft' => $draft];
    }
}
