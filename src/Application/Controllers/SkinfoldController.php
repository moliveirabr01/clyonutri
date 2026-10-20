<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException;
use ClyoNutri\Domain\Anthropometry\SkinfoldProtocolCatalog;
use ClyoNutri\Domain\Anthropometry\SkinfoldService;
use ClyoNutri\Domain\Patient\PatientRepository;
use PDO;

/**
 * Dobras cutâneas por protocolo (NI2-439): /pacientes/{id}/antropometria/dobras
 */
class SkinfoldController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function show(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $patient = (new PatientRepository())->findById($patientId);
        if (!$patient) {
            header('Location: /pacientes');
            exit;
        }
        $service = new SkinfoldService($this->db);
        $measurements = $service->listForPatient($patientId);
        $canEdit = $this->prescriberProfessionalId() !== null;

        $correcting = null;
        if (!empty($_GET['corrigir'])) {
            $correcting = $service->find((int)$_GET['corrigir']);
            if ($correcting && ((int)$correcting['patient_id'] !== $patientId || $correcting['status'] !== 'active')) {
                $correcting = null;
            }
        }
        $history = null;
        if (!empty($_GET['historico'])) {
            try {
                $history = $service->history((int)$_GET['historico']);
                if ($history && (int)$history[0]['patient_id'] !== $patientId) {
                    $history = null;
                }
            } catch (\Throwable) {
                $history = null;
            }
        }

        $patientSex = SkinfoldService::sexFromPatient($patient['gender'] ?? null);
        $sex = in_array($_GET['sexo'] ?? null, ['male', 'female'], true) ? $_GET['sexo'] : ($correcting['equation_sex'] ?? $patientSex);
        $today = date('Y-m-d');
        $age = SkinfoldService::ageAt($patient['birth_date'] ?? null, $correcting ? (string)$correcting['assessed_at'] : $today);
        $eligibility = SkinfoldProtocolCatalog::eligibleFor($sex, $age);
        $protocol = (string)($_GET['protocolo'] ?? ($correcting['protocol_code'] ?? ''));
        if (!isset(SkinfoldProtocolCatalog::PROTOCOLS[$protocol])) {
            $protocol = '';
            foreach ($eligibility as $e) {
                if ($e['eligible']) {
                    $protocol = $e['code'];
                    break;
                }
            }
        }
        $chartProtocol = (string)($_GET['grafico'] ?? ($measurements[0]['protocol_code'] ?? $protocol));
        $evolution = isset(SkinfoldProtocolCatalog::PROTOCOLS[$chartProtocol]) ? $service->evolution($patientId, $chartProtocol) : null;

        $aStmt = $this->db->prepare("SELECT id, assessed_at, weight_kg FROM anthropometric_assessments WHERE clinic_id = :c AND patient_id = :p ORDER BY assessed_at DESC LIMIT 20");
        $aStmt->execute(['c' => TenantContext::getClinicId(), 'p' => $patientId]);
        $assessments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $flash = $_SESSION['skinfold_flash'] ?? null;
        unset($_SESSION['skinfold_flash']);

        $title = 'Dobras cutâneas — ' . $patient['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $num = static fn($v, int $d = 1): string => number_format((float)$v, $d, ',', '.');
        $sexLabel = ['male' => 'Masculino', 'female' => 'Feminino'];

        ob_start();
        require __DIR__ . '/../../../views/anthropometry/skinfolds.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function store(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $professionalId = $this->prescriberProfessionalId();
        if ($professionalId === null) {
            $this->deny();
            return;
        }
        if (!(new PatientRepository())->findById($patientId)) {
            http_response_code(404);
            echo 'Paciente não encontrado.';
            return;
        }
        try {
            $res = (new SkinfoldService($this->db))->record($patientId, $professionalId, $this->formData(), TenantContext::getUserId());
            $_SESSION['skinfold_flash'] = ['type' => 'success', 'message' => $this->resultMessage($res, 'Medição registrada'), 'warnings' => $res['warnings']];
            $this->redirect("/pacientes/{$patientId}/antropometria/dobras?grafico=" . urlencode($res['protocol']));
        } catch (InvalidAnthropometricDataException $ex) {
            $_SESSION['skinfold_flash'] = ['type' => 'error', 'message' => $ex->getMessage(), 'old' => $_POST];
            $this->redirect("/pacientes/{$patientId}/antropometria/dobras?protocolo=" . urlencode((string)($_POST['protocol'] ?? '')));
        }
    }

    public function correct(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $measurementId = (int)($params['mid'] ?? 0);
        $professionalId = $this->prescriberProfessionalId();
        if ($professionalId === null) {
            $this->deny();
            return;
        }
        $service = new SkinfoldService($this->db);
        $current = $service->find($measurementId);
        if (!$current || (int)$current['patient_id'] !== $patientId) {
            http_response_code(404);
            echo 'Medição não encontrada.';
            return;
        }
        try {
            $res = $service->correct($measurementId, $professionalId, $this->formData(), (string)($_POST['correction_reason'] ?? ''), TenantContext::getUserId());
            $_SESSION['skinfold_flash'] = ['type' => 'success', 'message' => $this->resultMessage($res, "Correção registrada como versão {$res['version']}"), 'warnings' => $res['warnings']];
            $this->redirect("/pacientes/{$patientId}/antropometria/dobras?historico={$res['id']}");
        } catch (InvalidAnthropometricDataException $ex) {
            $_SESSION['skinfold_flash'] = ['type' => 'error', 'message' => $ex->getMessage(), 'old' => $_POST];
            $this->redirect("/pacientes/{$patientId}/antropometria/dobras?corrigir={$measurementId}");
        }
    }

    private function formData(): array
    {
        $readings = [];
        foreach (array_keys(SkinfoldProtocolCatalog::SITES) as $site) {
            $raw = $_POST['readings'][$site] ?? [];
            $readings[$site] = is_array($raw) ? array_values(array_filter($raw, fn($v) => is_string($v) && trim($v) !== '')) : [];
        }
        return [
            'protocol' => is_string($_POST['protocol'] ?? null) ? $_POST['protocol'] : '',
            'equation_sex' => is_string($_POST['equation_sex'] ?? null) ? $_POST['equation_sex'] : null,
            'assessed_at' => is_string($_POST['assessed_at'] ?? null) ? $_POST['assessed_at'] : null,
            'assessment_id' => is_numeric($_POST['assessment_id'] ?? null) ? (int)$_POST['assessment_id'] : null,
            'weight_kg' => is_string($_POST['weight_kg'] ?? null) ? $_POST['weight_kg'] : null,
            'notes' => is_string($_POST['notes'] ?? null) ? $_POST['notes'] : null,
            'readings' => $readings,
        ];
    }

    private function resultMessage(array $res, string $prefix): string
    {
        return sprintf('%s: %s%% de gordura (densidade %s g/mL, soma %s mm).', $prefix,
            number_format($res['body_fat_pct'], 1, ',', '.'), number_format($res['body_density'], 4, ',', '.'), number_format($res['sum_mm'], 1, ',', '.'));
    }

    /** Mesma regra da prescrição: nutricionista ativo, membro ativo da clínica, com CRN cadastrado. */
    private function prescriberProfessionalId(): ?int
    {
        $st = $this->db->prepare("SELECT pr.id FROM clinic_members cm JOIN users u ON u.id = cm.user_id
            JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active'
              AND cm.role IN ('owner', 'admin', 'nutritionist')
              AND u.status = 'active' AND u.role = 'nutritionist'
              AND pr.crn IS NOT NULL AND TRIM(pr.crn) <> ''
            ORDER BY pr.id LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Apenas nutricionistas com CRN cadastrado na clínica registram medições de dobras.';
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }
}
