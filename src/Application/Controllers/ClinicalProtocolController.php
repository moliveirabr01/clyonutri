<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\ClinicalProtocol\PatientConditionService;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace;
use ClyoNutri\Domain\Team\TeamService;
use PDO;

/**
 * NI2-635 (NI2-639/640/642/652): uso da anamnese adaptativa pela clínica. Tudo responde 404 com a chave global desligada.
 *
 *   GET  /configuracoes/protocolos                         protocolos aprovados e quais a clínica usa (gestor liga/desliga)
 *   POST /configuracoes/protocolos                         ligar/desligar
 *   POST /pacientes/{id}/condicoes                         registrar condição (já confirmada pelo profissional)
 *   POST /pacientes/{id}/condicoes/sugerir                 procurar sugestões no texto da anamnese vigente (só sugere)
 *   POST /pacientes/{id}/condicoes/{cid}/confirmar         confirmar sugestão / mudar status, com origem
 *   POST /pacientes/{id}/condicoes/{cid}/encerrar          descartar sugestão ou marcar resolvida (com motivo)
 *   POST /pacientes/{id}/protocolos                        salvar respostas (rascunho ou concluir)
 */
class ClinicalProtocolController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function settings(): void
    {
        $ws = new ProtocolWorkspace($this->db);
        if (!$ws->globallyEnabled()) {
            $this->notFound();
            return;
        }
        $clinicId = (int)TenantContext::getClinicId();
        $canManage = (new TeamService($this->db))->canManage($clinicId, (int)TenantContext::getUserId());
        $approved = (new ProtocolCatalog($this->db))->approvedByCode($clinicId);
        $st = $this->db->prepare('SELECT p.code FROM clinic_protocol_settings s JOIN clinical_protocols p ON p.id = s.protocol_id WHERE s.clinic_id = ? AND s.enabled = 1');
        $st->execute([$clinicId]);
        $enabled = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        $flash = $_SESSION['cp_flash'] ?? null;
        unset($_SESSION['cp_flash']);
        $csrfToken = Csrf::token();
        $title = 'Protocolos clínicos';
        $activeNav = 'configuracoes';
        $clinicName = $_SESSION['clinic_name'] ?? '';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        require __DIR__ . '/../../../views/settings/protocols.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function toggle(): void
    {
        $this->guard('/configuracoes/protocolos', function (int $c, int $u) {
            if (!(new TeamService($this->db))->canManage($c, $u)) {
                throw new \DomainException('Só o titular ou um nutricionista gestor liga protocolos.');
            }
            $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
            $on = ($_POST['enabled'] ?? '') === '1';
            (new ProtocolWorkspace($this->db))->setClinicEnabled($c, $code, $on, $u);
            (new AuditLogger($this->db))->log('CLINIC_PROTOCOL_TOGGLED', 'clinical_protocols', $code, ['clinic_id' => $c, 'enabled' => $on, 'actor_user_id' => $u]);
            return $on ? 'Protocolo ligado: aparece nos pacientes com a condição confirmada.' : 'Protocolo desligado. As respostas já gravadas continuam guardadas.';
        });
    }

    public function recordCondition(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($pid) {
            $this->assertClinical($c, $u);
            $code = $this->str('code');
            if (!isset((new ProtocolCatalog($this->db))->approvedByCode($c)[$code])) {
                throw new \InvalidArgumentException('Escolha um protocolo aprovado.');
            }
            (new PatientConditionService($this->db))->record($c, $pid, $code, $this->str('status'), $this->str('provenance'), $u, $this->str('evidence') ?: null, $this->str('noted_at') ?: null, $this->str('valid_until') ?: null);
            return 'Condição registrada.';
        });
    }

    public function suggest(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($pid) {
            $this->assertClinical($c, $u);
            $sum = (new \ClyoNutri\Domain\Anamnesis\AnamnesisService($this->db))->getClinicalSummaryForPrescription($pid);
            $text = implode(' ', array_filter([(string)($sum['chief_complaint'] ?? ''), (string)($sum['pathologies'] ?? ''), (string)($sum['notes'] ?? ''), (string)($sum['symptoms'] ?? '')]));
            if (trim($text) === '') {
                return 'A anamnese vigente não tem texto livre para procurar.';
            }
            $r = (new PatientConditionService($this->db))->suggestFromTextDetailed($c, $pid, $text, (new ProtocolCatalog($this->db))->approvedByCode($c), $u);
            $msg = $r['made'] ? count($r['made']) . ' sugestão(ões) para você confirmar ou descartar. Nada foi ativado.' : 'Nenhuma condição sugerida.';
            if ($r['skipped']) { // NI2-640: diz o que foi ignorado e por quê
                $msg .= ' Ignorado: ' . implode('; ', array_map(static fn($s) => '"' . $s['snippet'] . '" (' . $s['reason'] . ')', array_slice($r['skipped'], 0, 3))) . '.';
            }
            return $msg;
        });
    }

    public function confirmCondition(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($params, $pid) {
            $this->assertClinical($c, $u);
            $cid = $this->conditionOf($c, $pid, (int)($params['cid'] ?? 0));
            (new PatientConditionService($this->db))->confirm($c, $cid, $this->str('status'), $this->str('provenance'), $u, $this->str('note') ?: null, null, $this->str('valid_until') ?: null);
            return 'Condição confirmada.';
        });
    }

    public function closeCondition(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($params, $pid) {
            $this->assertClinical($c, $u);
            $cid = $this->conditionOf($c, $pid, (int)($params['cid'] ?? 0));
            (new PatientConditionService($this->db))->close($c, $cid, $this->str('to'), $u, $this->str('note'));
            return $this->str('to') === 'dismissed' ? 'Sugestão descartada (fica no histórico).' : 'Condição marcada como resolvida.';
        });
    }

    public function saveAnswers(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($pid) {
            $this->assertClinical($c, $u);
            $answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
            $conclude = ($_POST['conclude'] ?? '') === '1';
            $_SESSION['cp_answers'][$c . ':' . $pid] = $answers; // devolve o que foi digitado se a conclusão for recusada
            $base = isset($_POST['base_revision']) && is_numeric($_POST['base_revision']) ? (int)$_POST['base_revision'] : null;
            try {
                $r = (new ProtocolWorkspace($this->db))->save($c, $pid, $answers, 'professional', $conclude, $u, null, $base);
            } catch (\ClyoNutri\Domain\ClinicalProtocol\ProtocolSaveConflict $e) {
                // NI2-652: mostra as respostas atuais e, à parte, o que esta pessoa digitou e não foi gravado
                unset($_SESSION['cp_answers'][$c . ':' . $pid]);
                $_SESSION['cp_conflict'][$c . ':' . $pid] = array_filter($answers, static fn($v) => $v !== '' && $v !== []);
                throw $e;
            }
            unset($_SESSION['cp_answers'][$c . ':' . $pid]);
            $flags = count($r['red_flags']);
            return ($conclude ? 'Módulos concluídos.' : 'Rascunho dos módulos salvo.') . ($flags ? ' Atenção: ' . $flags . ' sinal(is) de alerta para avaliação.' : '');
        });
    }

    public function dismissModule(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($params, $pid) {
            $this->assertClinical($c, $u);
            (new PatientConditionService($this->db))->dismissModule($c, $pid, (string)($params['code'] ?? ''), $u, $this->str('reason'));
            return 'Módulo dispensado para este paciente (a condição continua registrada). O motivo fica no histórico.';
        });
    }

    public function restoreModule(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        $this->guard($this->back($pid), function (int $c, int $u) use ($params, $pid) {
            $this->assertClinical($c, $u);
            (new PatientConditionService($this->db))->restoreModule($c, $pid, (string)($params['code'] ?? ''), $u);
            return 'Módulo reativado.';
        });
    }

    // ------------------------------------------------------------------ apoio

    /** Bloco da anamnese adaptativa para a página da anamnese (vazio com a chave desligada). */
    public static function renderSection(PDO $db, int $patientId): string
    {
        try {
            $ws = new ProtocolWorkspace($db);
            if (!$ws->globallyEnabled()) {
                return '';
            }
            $clinicId = (int)TenantContext::getClinicId();
            $conds = (new PatientConditionService($db))->list($clinicId, $patientId);
            $approved = (new ProtocolCatalog($db))->approvedByCode($clinicId);
            $st = $db->prepare('SELECT p.code FROM clinic_protocol_settings s JOIN clinical_protocols p ON p.id = s.protocol_id WHERE s.clinic_id = ? AND s.enabled = 1');
            $st->execute([$clinicId]);
            $enabledCodes = $st->fetchAll(PDO::FETCH_COLUMN);
            $available = array_intersect_key($approved, array_flip($enabledCodes));
            if (!$available && !$conds) {
                return '';
            }
            $q = $ws->questionnaire($clinicId, $patientId);
            if (!empty($_SESSION['cp_answers'][$clinicId . ':' . $patientId]) && is_array($_SESSION['cp_answers'][$clinicId . ':' . $patientId])) {
                $q['answers'] = array_merge($q['answers'], \ClyoNutri\Domain\ClinicalProtocol\ProtocolEngine::sanitize($q['composed'], $_SESSION['cp_answers'][$clinicId . ':' . $patientId]));
                $q['composed'] = \ClyoNutri\Domain\ClinicalProtocol\ProtocolEngine::compose($q['modules'], $q['answers']);
                unset($_SESSION['cp_answers'][$clinicId . ':' . $patientId]);
            }
            $pcs = new PatientConditionService($db);
            $dismissed = $pcs->dismissedModules($clinicId, $patientId);
            $compare = $ws->compare($clinicId, $patientId);
            $conflict = is_array($_SESSION['cp_conflict'][$clinicId . ':' . $patientId] ?? null) ? $_SESSION['cp_conflict'][$clinicId . ':' . $patientId] : [];
            unset($_SESSION['cp_conflict'][$clinicId . ':' . $patientId]);
            // NI2-655: registro de leitura de dado sensível — só quantidades, nem o código do protocolo (revela a condição)
            (new AuditLogger($db))->log('PROTOCOL_DATA_VIEWED', 'patients', (string)$patientId, ['patient_id' => $patientId, 'modules' => count($q['modules']), 'conditions' => count($conds)]);
            $flash = $_SESSION['cp_flash'] ?? null;
            unset($_SESSION['cp_flash']);
            $csrfToken = Csrf::token();
            ob_start();
            require __DIR__ . '/../../../views/patients/_adaptive_anamnesis.php';
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            error_log('NI2-635 seção adaptativa: ' . $e->getMessage());
            return '';
        }
    }

    private function guard(string $back, callable $fn): void
    {
        if (!(new ProtocolWorkspace($this->db))->globallyEnabled()) {
            $this->notFound();
            return;
        }
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            $_SESSION['cp_flash'] = ['type' => 'error', 'text' => 'Formulário expirado. Recarregue a página.'];
        } else {
            try {
                $_SESSION['cp_flash'] = ['type' => 'success', 'text' => $fn((int)TenantContext::getClinicId(), (int)TenantContext::getUserId())];
            } catch (\InvalidArgumentException | \DomainException $e) {
                $_SESSION['cp_flash'] = ['type' => 'error', 'text' => $e->getMessage()];
            } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
                $this->notFound();
                return;
            }
        }
        header('Location: ' . $back);
        exit;
    }

    /** Só profissional clínico ativo da clínica (com CRN) mexe em condição e resposta. */
    private function assertClinical(int $clinicId, int $userId): void
    {
        $st = $this->db->prepare("SELECT 1 FROM clinic_members cm JOIN professionals p ON p.user_id = cm.user_id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' AND cm.role IN ('owner','admin','nutritionist')");
        $st->execute([$clinicId, $userId]);
        if (!$st->fetchColumn()) {
            throw new \DomainException('Só nutricionista da clínica registra dados clínicos.');
        }
    }

    private function conditionOf(int $clinicId, int $patientId, int $cid): int
    {
        $st = $this->db->prepare('SELECT id FROM patient_conditions WHERE id = ? AND clinic_id = ? AND patient_id = ?');
        $st->execute([$cid, $clinicId, $patientId]);
        if (!$st->fetchColumn()) {
            throw new \DomainException('Condição não encontrada.');
        }
        return $cid;
    }

    private function back(int $pid): string
    {
        return '/pacientes/' . $pid . '/anamnese#anamnese-adaptativa';
    }

    private function str(string $k): string
    {
        return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo 'Página não encontrada.';
    }
}
