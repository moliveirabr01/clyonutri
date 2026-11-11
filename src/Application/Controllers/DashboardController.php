<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Domain\Anthropometry\AnthropometryCalculator;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\InvalidAppointmentTransitionException;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Engagement\EngagementService;
use ClyoNutri\Domain\Finance\FinanceService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\DraftPlanSummary;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Domain\Patient\PatientRepository;

class DashboardController
{
    /** Quantidade máxima de itens exibidos na lista "Pacientes & Prioridades" do Dashboard. */
    private const DASHBOARD_ATTENTION_LIMIT = 5;

    /** Total de passos do onboarding (OnboardingService: progress_pct = passos concluídos × 20). */
    private const ONBOARDING_TOTAL_STEPS = 5;

    public function savePatient(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /pacientes?error=csrf');
            exit;
        }

        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        if (!$this->canEditPlanItems(Connection::getInstance())) { http_response_code(403); echo 'Cadastro não autorizado.'; return; }
        $submission = $_POST['_submission_id'] ?? '';
        if (!is_string($submission) || !isset($_SESSION['patient_submissions'][$submission])) { http_response_code(422); echo 'Recarregue o formulário antes de cadastrar.'; return; }
        if ($_SESSION['patient_submissions'][$submission] === 'completed') { header('Location: /pacientes?success=1'); return; }
        $_SESSION['patient_form'] = array_filter(array_intersect_key($_POST, array_flip(['name','email','phone','birth_date','gender','notes','allergies','allergy_assessment'])), 'is_string');
        try {
        if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Informe um nome válido.');
        foreach (['email','phone','birth_date','gender','notes','allergies','allergy_assessment','consent_choice','consent_term_id'] as $field) if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new \InvalidArgumentException('Confira os campos do cadastro.');
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Informe um e-mail válido.');
        if (!empty($_POST['birth_date'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $_POST['birth_date']);
            if (!$date || $date->format('Y-m-d') !== $_POST['birth_date'] || $date > new \DateTimeImmutable('today')) throw new \InvalidArgumentException('Informe uma data de nascimento válida.');
        }
        if (!empty($_POST['gender']) && !in_array($_POST['gender'], ['Feminino','Masculino','Outro','female','male','other'], true)) throw new \InvalidArgumentException('Selecione um gênero válido.');

        $allergyAssessment = null;
        if (!empty($_POST['allergy_denied']) || ($_POST['allergy_assessment'] ?? '') === 'denied') {
            $allergyAssessment = 'denied';
        } elseif (!empty($_POST['allergies'])) {
            $allergyAssessment = 'recorded';
        }

        $repo = new PatientRepository();
        $createdPatient = $repo->create([
            'name' => $name,
            'email' => !empty($_POST['email']) ? trim((string)$_POST['email']) : null,
            'phone' => !empty($_POST['phone']) ? trim((string)$_POST['phone']) : null,
            'birth_date' => !empty($_POST['birth_date']) ? $_POST['birth_date'] : null,
            'gender' => !empty($_POST['gender']) ? $_POST['gender'] : null,
            'notes' => !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null,
            'allergies' => !empty($_POST['allergies']) ? trim((string)$_POST['allergies']) : null,
            'allergy_assessment' => $allergyAssessment,
            'consent_choice' => $_POST['consent_choice'] ?? 'not_collected',
            'consent_term_id' => $_POST['consent_term_id'] ?? null,
        ]);

        $_SESSION['patient_created_id'] = (int)$createdPatient['id'];
        $_SESSION['patient_created_name'] = $name;
        $_SESSION['patient_submissions'][$submission] = 'completed';
        unset($_SESSION['patient_form']);
        } catch (\InvalidArgumentException $e) {
            if ($e instanceof \ClyoNutri\Domain\Patient\DuplicatePatientException) $_SESSION['patient_duplicate_id'] = $e->patientId;
            $_SESSION['patient_error'] = $e->getMessage();
            header('Location: /pacientes?error=cadastro');
            return;
        } catch (\Throwable $e) {
            $_SESSION['patient_error'] = 'Não foi possível cadastrar o paciente. Os dados foram mantidos. Confira o termo e tente novamente.';
            header('Location: /pacientes?error=cadastro');
            return;
        }
        // Só caminho interno: "/agenda?..." sim; "//site" e "/\site" (redirecionamento aberto) não.
        if (!empty($_POST['return_to']) && is_string($_POST['return_to']) && preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-?=&%.:]*$#', $_POST['return_to'])) {
            $redirectUrl = $_POST['return_to'];
            $sep = str_contains($redirectUrl, '?') ? '&' : '?';
            header('Location: ' . $redirectUrl . $sep . 'paciente=' . (int)$createdPatient['id']);
            exit;
        }
        header('Location: /pacientes?success=1');
        exit;
    }

    public function editPatient(array $params): void
    {
        if (!$this->canEditPlanItems(Connection::getInstance())) { http_response_code(403); echo 'Edição não autorizada.'; return; }
        $patient = (new PatientRepository())->findById((int)($params['id'] ?? 0));
        if (!$patient) {
            http_response_code(404);
            echo 'Paciente não encontrado.';
            return;
        }
        $errors = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
                http_response_code(403);
                echo 'Sessão expirada. Recarregue a página.';
                return;
            }
            $data = [];
            foreach (['name', 'email', 'phone', 'birth_date', 'gender', 'notes', 'allergies'] as $field) {
                $data[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
                if ($field !== 'name' && $data[$field] === '') $data[$field] = null;
            }
            if (!empty($_POST['allergy_denied']) || ($_POST['allergy_assessment'] ?? '') === 'denied') {
                $data['allergy_assessment'] = 'denied';
                $data['allergies'] = null;
            } elseif (!empty($data['allergies'])) {
                $data['allergy_assessment'] = 'recorded';
            } else {
                $data['allergy_assessment'] = null;
            }
            if (mb_strlen($data['name']) > 150) $errors[] = 'O nome deve ter até 150 caracteres.';
            if ($data['name'] === '') $errors[] = 'Informe o nome completo.';
            if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail válido.';
            if ($data['birth_date'] !== null) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['birth_date']);
                if (!$date || $date->format('Y-m-d') !== $data['birth_date'] || $date > new \DateTimeImmutable('today')) $errors[] = 'Informe uma data de nascimento válida.';
            }
            if ($data['gender'] !== null && !in_array($data['gender'], ['Feminino', 'Masculino', 'Outro', 'female', 'male', 'other'], true)) $errors[] = 'Selecione um gênero válido.';
            // UX-10 (v14): se a opção equivale ao valor legado gravado (ex.: 'female' = 'Feminino'), mantém o gravado (sem alteração silenciosa).
            if ($data['gender'] !== null && LabelMap::canonicalGender($data['gender']) === LabelMap::canonicalGender($patient['gender'] ?? null)) $data['gender'] = $patient['gender'];
            if (!$errors) {
                try {
                    (new PatientRepository())->update((int)$patient['id'], $data);
                    header('Location: /pacientes?success=atualizado');
                    return;
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                } catch (\Throwable $e) {
                    $errors[] = 'Não foi possível salvar. Seus dados foram mantidos. Tente novamente.';
                }
            }
            http_response_code(422);
            $patient = array_replace($patient, $data);
        }
        $title = 'Editar paciente';
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        require __DIR__ . '/../../../views/patients/edit.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function patientStatus(array $params): void
    {
        if (!$this->canEditPlanItems(Connection::getInstance()) || !Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Ação não autorizada.'; return; }
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        if (!$repo->findById($id)) { http_response_code(404); echo 'Paciente não encontrado.'; return; }
        try {
            if (($_POST['confirm_status'] ?? '') !== 'yes') throw new \InvalidArgumentException('Confirme a alteração de status.');
            $repo->setStatus($id, is_string($_POST['status'] ?? null) ? $_POST['status'] : '');
            header('Location: /pacientes/'.$id.'/editar?success=status');
        } catch (\InvalidArgumentException $e) { http_response_code(422); echo htmlspecialchars($e->getMessage()); }
        catch (\Throwable $e) { http_response_code(500); echo 'Não foi possível alterar o status. Tente novamente.'; }
    }

    public function patientConsent(array $params): void
    {
        if (!$this->canEditPlanItems(Connection::getInstance()) || !Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Ação não autorizada.'; return; }
        $id = (int)($params['id'] ?? 0);
        if (!(new PatientRepository())->findById($id)) { http_response_code(404); echo 'Paciente não encontrado.'; return; }
        try {
            $service = new \ClyoNutri\Domain\Privacy\ConsentManagementService();
            $purpose = is_string($_POST['purpose'] ?? null) ? trim($_POST['purpose']) : '';
            $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
            if ($purpose === '' || $reason === '' || mb_strlen($reason) > 1000 || ($_POST['confirm_revoke'] ?? '') !== 'yes') throw new \InvalidArgumentException('Informe a finalidade, o motivo e confirme a retirada.');
            $service->revokeConsent($id, $purpose, $reason);
            header('Location: /pacientes/'.$id.'?success=consentimento');
        } catch (\InvalidArgumentException $e) { http_response_code(422); echo htmlspecialchars($e->getMessage()); }
        catch (\Throwable $e) { http_response_code(500); echo 'Não foi possível retirar o consentimento. Tente novamente.'; }
    }

    public function patientPrivacyRequest(array $params): void
    {
        if (!$this->canEditPlanItems(Connection::getInstance()) || !Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Ação não autorizada.'; return; }
        $id = (int)($params['id'] ?? 0);
        if (!(new PatientRepository())->findById($id)) { http_response_code(404); echo 'Paciente não encontrado.'; return; }
        $type = $_POST['request_type'] ?? '';
        if (!in_array($type, ['export','deletion'], true) || ($_POST['confirm_request'] ?? '') !== 'yes') { http_response_code(422); echo 'Selecione e confirme a solicitação.'; return; }
        // Requests require professional review; never erase records directly from this form.
        $service = new \ClyoNutri\Domain\Privacy\DataSubjectRequestService();
        $db = Connection::getInstance();
        $db->beginTransaction();
        try {
            $request = $service->openRequest($id, $type, 'Registrado na gestão de pacientes.');
            $export = $type === 'export' ? $service->processExport($request['id'], (int)TenantContext::getUserId()) : null;
            $json = $export ? json_encode($export['export_package'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null;
            $db->commit();
            if ($json !== null) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="dados-paciente-'.$id.'.json"');
                header('Cache-Control: no-store');
                echo $json; return;
            }
            // Exclusão segue para a fila da clínica (/privacidade/pedidos), processada pela gestão.
            header('Location: /pacientes/'.$id.'?success=' . ($request['already_open'] ? 'privacidade-ja-aberto' : 'privacidade'));
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(403); echo 'Ação não autorizada.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500); echo 'Não foi possível registrar a solicitação. Tente novamente.';
        }
    }

    public function profile360(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $profile = $repo->getProfile360($id);

        if (!$profile) {
            header('Location: /pacientes');
            exit;
        }

        $p = $profile['patient'];
        \ClyoNutri\Core\Audit\PatientRecordAccessAudit::record($id, 'profile360'); // CR-NI2-006 / NI2-719
        $consents = (new \ClyoNutri\Domain\Privacy\ConsentManagementService())->getConsentAuditTrail($id);
        $title = 'Perfil do paciente · ' . $p['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::token();

        $anthroService = new AnthropometryService();
        $evolutionData = $anthroService->getEvolution($id);
        $assessments = $evolutionData['assessments'];

        $clinicalService = new ClinicalRecordService();
        $integrity = $clinicalService->verifyIntegrity($id);
        $clinicalRecords = $profile['clinical_records'] ?? [];

        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();
        $latestAnamnesis = $anamnesisService->getEffectiveAnamnesis($id); // NI2-605: a concluída vale; o rascunho novo é "em andamento"
        $clinicalSummary = $anamnesisService->getClinicalSummaryForPrescription($id);
        $careStep = (new AppointmentService())->careStepForPatient($id);
        // NI2-135: autorrelatos, medidas liberadas e metas do app da paciente.
        $appEvolution = (new \ClyoNutri\Domain\Evolution\PatientEvolutionService())->professionalPanel($id);
        $canEditAppEvolution = $this->canEditPlanItems(Connection::getInstance());
        $appEvolutionFlash = $_SESSION['app_evolution_flash'] ?? null; unset($_SESSION['app_evolution_flash']);
        // NI2-138: vínculo que libera o chat com a paciente.
        $chatLinkSvc = new \ClyoNutri\Domain\Chat\PatientLinkService();
        $chatLinks = $chatLinkSvc->linksForPatient($id);
        $chatProfessionals = $chatLinkSvc->clinicProfessionals();
        $chatActor = ChatLinkController::actor();
        $chatLinkFlash = $_SESSION['chat_link_flash'] ?? null; unset($_SESSION['chat_link_flash']);
        // NI2-92: hidratação registrada pela paciente no app (somente leitura, escopo da clínica).
        try {
            $appHydration = (new \ClyoNutri\Domain\Engagement\HydrationService())->professionalSummary($id, 14);
        } catch (\Throwable $e) {
            $appHydration = null;
        }

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['error'])): ?>
          <div class="op-message op-message--error" role="alert">
            <strong>Erro:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na validação dos dados clínicos.' ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['success'])): ?>
          <div role="status" class="op-message op-message--success u-mb-4">
            <?= $_GET['success'] === 'avaliacao_registrada' ? 'Avaliação salva no prontuário.' : ($_GET['success'] === 'prontuario_salvo' ? 'Registro salvo no prontuário.' : ($_GET['success'] === 'reagendado' ? 'Consulta reagendada. O horário anterior e o novo ficam registrados na trilha da consulta.' : ($_GET['success'] === 'consulta_concluida' ? 'Consulta concluída. A evolução foi registrada no prontuário.' : ($_GET['success'] === 'agendado' ? 'Consulta agendada. Ela aparece na agenda e no atendimento do paciente.' : 'Pronto, alteração salva.')))) ?>
          </div>
        <?php endif; ?>
        <?php if (($_GET['aviso'] ?? '') === 'medidas'): /* NI2-220: a evolução foi gravada, mas a avaliação antropométrica não */ ?>
          <div class="op-message op-message--warning" role="status" data-testid="aviso-medidas">
            <strong>Atenção:</strong> a evolução foi registrada, mas o peso e a cintura informados não puderam ser salvos na avaliação antropométrica. Confira em Antropometria e registre de novo, se precisar.
          </div>
        <?php endif; ?>

        <p class="patient-back"><a href="/pacientes">← Pacientes</a></p>
        <?php
          $activePlan = null;
          if (!empty($profile['meal_plans'])) {
              foreach ($profile['meal_plans'] as $mp) {
                  if ($mp['status'] === 'active') {
                      $activePlan = $mp;
                      break;
                  }
              }
              if (!$activePlan) {
                  $activePlan = $profile['meal_plans'][0];
              }
          }
        ?>
        <?php
          // NI2-710/712: último atendimento concluído + pendências de exame para a faixa operacional
          $pendingExamCount = 0;
          try {
              $pe = Connection::getInstance()->prepare("SELECT COUNT(*) FROM exam_requests WHERE clinic_id = ? AND patient_id = ? AND status IN ('draft','review')");
              $pe->execute([(int)\ClyoNutri\Core\Auth\TenantContext::getClinicId(), (int)$p['id']]);
              $pendingExamCount = (int)$pe->fetchColumn();
          } catch (\Throwable $e) { $pendingExamCount = 0; }
          $lastCompleted = null;
          try {
              $lc = Connection::getInstance()->prepare("SELECT id, scheduled_at, type, status FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status = 'completed' ORDER BY COALESCE(completed_at, scheduled_at) DESC, id DESC LIMIT 1");
              $lc->execute([(int)\ClyoNutri\Core\Auth\TenantContext::getClinicId(), (int)$p['id']]);
              $lastCompleted = $lc->fetch(\PDO::FETCH_ASSOC) ?: null;
          } catch (\Throwable $e) { $lastCompleted = null; }
          $priorityActions = [];
          $st = (string)($careStep['state'] ?? 'none');
          if ($st === 'continue' && !empty($careStep['appointment']['id'])) {
              $priorityActions[] = ['label' => 'Continuar atendimento', 'href' => '/consulta/' . (int)$careStep['appointment']['id']];
          } elseif ($st === 'upcoming' || $st === 'start_today') {
              $priorityActions[] = ['label' => 'Preparar consulta', 'href' => '/pacientes/' . (int)$p['id'] . '/preparar'];
          }
          if (!empty($clinicalSummary['draft_in_progress'])) {
              $priorityActions[] = ['label' => 'Continuar anamnese', 'href' => '/pacientes/' . (int)$p['id'] . '/anamnese'];
          }
          if (($activePlan['status'] ?? '') === 'draft') {
              $priorityActions[] = ['label' => 'Continuar plano', 'href' => '/planos/' . (int)$activePlan['id']];
          } elseif (!$activePlan) {
              $priorityActions[] = ['label' => 'Criar plano', 'href' => '/pacientes/' . (int)$p['id'] . '/plano'];
          }
          if (!empty($clinicalSummary['allergy_review'])) {
              $priorityActions[] = ['label' => 'Revisar alergia', 'href' => '#alergia-revisao'];
          }
        ?>
        <?= \ClyoNutri\Application\View\PatientHeader::render(Connection::getInstance(), (int)$p['id'], 'resumo', $p, $careStep, $clinicalSummary, $activePlan, true) // NI2-622/661 ?>
        <?php /* NI2-624: alergia de versão anterior (ou do cadastro) que a vigente não confirma: revisão clínica, sem descartar o risco. */ ?>
        <?php $allergyReview = $clinicalSummary['allergy_review'] ?? []; ?>
        <?php if (!empty($_GET['alergia_revisada'])): ?>
          <div class="op-message op-message--info card allergy-review allergy-review--done" role="status" data-testid="allergy-review-done">Revisão registrada no histórico do paciente.</div>
        <?php endif; ?>
        <?php if ($allergyReview): ?>
          <section class="card allergy-review" id="alergia-revisao" role="alert" aria-labelledby="allergy-review-title" data-testid="allergy-review">
            <h2 id="allergy-review-title" class="allergy-review__title"><?= \ClyoNutri\Application\View\Icon::svg('triangle-alert', 20) ?> Alergia exige revisão clínica</h2>
            <p class="allergy-review__lead">A anamnese vigente não confirma uma alergia registrada antes. Até a revisão, ela <strong>continua valendo</strong> na trava de alérgenos do plano, das receitas e da lista de compras.</p>
            <ul class="allergy-review__list">
              <?php foreach ($allergyReview as $rf): ?>
                <li data-testid="allergy-review-item" data-group="<?= htmlspecialchars($rf['group']) ?>">
                  <p class="u-mt-0 u-mb-2 u-mx-0"><?= htmlspecialchars($rf['message']) ?></p>
                  <?php if ($this->canEditPlanItems(Connection::getInstance())): ?>
                  <details class="allergy-review__dismiss">
                    <summary>A alergia foi descartada clinicamente</summary>
                    <form method="POST" action="/pacientes/<?= (int)$p['id'] ?>/alergias/revisao">
                      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                      <input type="hidden" name="group" value="<?= htmlspecialchars($rf['group']) ?>">
                      <label class="form-label" for="ar-<?= htmlspecialchars($rf['group']) ?>">Motivo (fica no histórico do paciente)</label>
                      <textarea class="form-control" id="ar-<?= htmlspecialchars($rf['group']) ?>" name="reason" rows="2" minlength="5" maxlength="300" required placeholder="Ex.: teste de provocação oral negativo em 10/2026"></textarea>
                      <button type="submit" class="btn btn-secondary btn-sm u-mt-2">Registrar que foi descartada</button>
                    </form>
                  </details>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="u-mt-2 u-mb-0 u-mx-0"><a class="btn btn-secondary btn-sm" href="/pacientes/<?= (int)$p['id'] ?>/anamnese">Confirmar na anamnese</a> <span class="allergy-review__hint">Se a alergia continua, registre-a de novo na anamnese.</span></p>
          </section>
        <?php endif; ?>


        <?php require __DIR__ . '/../../../views/patients/_operational_strip.php'; ?>
        <details class="profile-details" data-testid="profile-details" open>
          <summary>Detalhes do prontuário, planos, avaliações e app</summary>
          <div class="profile-details__body">
        <div class="cn-split">
          <!-- Coluna 1: Dados Cadastrais -->
          <div class="card" id="secao-dados">
            <h3 class="u-mt-0 u-mb-4">Dados essenciais</h3>
            <p class="u-mb-2"><strong>E-mail:</strong> <?= htmlspecialchars($p['email'] ?? '—') ?></p>
            <p class="u-mb-2"><strong>Telefone:</strong> <?= htmlspecialchars($p['phone'] ?? '—') ?></p>
            <p class="u-mb-2"><strong>Nascimento:</strong> <?= $p['birth_date'] ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></p>
            <p class="u-mb-2"><strong>Sexo registrado para referências clínicas:</strong> <?= htmlspecialchars(LabelMap::patientGender($p['gender'] ?? null)) ?></p>
            <?php if (!empty($p['notes'])): ?>
              <div class="u-mt-4 u-pt-4 u-border-t">
                <strong>Observações:</strong>
                <p class="u-color-neutral-gray-500 u-mt-1"><?= nl2br(htmlspecialchars($p['notes'])) ?></p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Coluna 2: Prontuário, Anamnese, Avaliações e Planos -->
          <div>
            <!-- Card 1: Prontuário Eletrônico & Evoluções -->
            <div class="card u-mb-6" id="secao-prontuario">
              <div class="card__header u-flex u-justify-between u-items-center u-mb-3 u-wrap u-gap-2">
                <div>
                  <h3 class="u-m-0 u-inline-block">Prontuário</h3>
                  <?php if ($integrity['is_valid'] && (int)$integrity['total_records'] > 0): ?>
                    <span class="badge badge-success u-text-caption u-ml-2">✓ <?= !empty($integrity['legacy_records']) ? 'Conteúdo e encadeamento verificados' : 'Integridade dos registros verificada' ?></span>
                  <?php elseif (!$integrity['is_valid']): ?>
                    <span class="badge u-bg-semantic-error-light u-color-semantic-error u-text-caption u-ml-2">Os registros precisam de verificação</span>
                  <?php endif; ?>
                </div>
                <a href="/pacientes/<?= $p['id'] ?>/prontuario" class="btn btn-sm btn-outline u-text-compact u-no-underline u-py-0 u-px-2">
                  Abrir prontuário completo →
                </a>
              </div>

              <?php if (empty($clinicalRecords)): ?>
                <p class="u-color-neutral-gray-500 u-text-compact">Nenhum registro clínico adicionado ao prontuário até o momento.</p>
              <?php else: ?>
                <div class="u-flex u-flex-col u-gap-2">
                  <?php foreach (array_slice($clinicalRecords, 0, 3) as $cr): ?>
                    <div class="u-py-2 u-px-3 u-bg-neutral-gray-50 u-border u-radius-input u-text-compact">
                      <div class="u-flex u-justify-between u-items-center u-mb-1">
                        <strong class="u-color-botanical-forest-900"><?= htmlspecialchars($cr['title']) ?></strong>
                        <div class="u-flex u-gap-1 u-items-center">
                          <span class="u-text-caption u-color-neutral-gray-500">v<?= $cr['version'] ?> • <?= date('d/m/Y H:i', strtotime($cr['created_at'])) ?></span>
                          <?php if ($cr['is_retracted']): ?>
                            <span class="badge badge-warning u-text-caption">Retificado</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="u-color-neutral-gray-600 u-text-compact u-nowrap u-overflow-hidden u-ellipsis">
                        <?= htmlspecialchars(\ClyoNutri\Domain\Clinical\ClinicalRecordContent::plainText($cr['content'] ?? ($cr['content_json'] ?? null))) // v54.27: texto, nunca JSON ?>
                      </div>
                      <div class="u-mt-1 u-text-caption u-color-neutral-gray-600 u-font-mono">
                        Responsável: <?= htmlspecialchars($cr['professional_name']) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Card 2: Anamnese Nutricional Vigente & Resumo Crítico -->
            <div class="card u-mb-6" id="secao-anamnese">
              <div class="card__header u-flex u-justify-between u-items-center u-mb-3 u-wrap u-gap-2">
                <div>
                  <h3 class="u-m-0 u-inline-block">Anamnese nutricional</h3>
                  <?php
                    // NI2-605: o selo mostra a versão que vale (a última concluída); o rascunho mais novo é "alteração em andamento".
                    $anVigente = !empty($clinicalSummary['source']['anamnesis_id']) ? $clinicalSummary['source'] : null;
                    $anDraft = $clinicalSummary['draft_in_progress'] ?? null;
                  ?>
                  <?php if ($anVigente && empty($clinicalSummary['is_draft'])): ?>
                    <span class="badge badge-info u-text-caption u-ml-2" data-testid="profile-anamnesis-version">
                      v<?= (int)$anVigente['version'] ?> (Vigente) • <?= date('d/m/Y', strtotime($anVigente['recorded_at'])) ?>
                    </span>
                  <?php elseif ($latestAnamnesis): ?>
                    <span class="badge badge-warning u-text-caption u-ml-2" data-testid="profile-anamnesis-version">
                      v<?= (int)$latestAnamnesis['version'] ?> (Rascunho, nenhuma versão concluída) • <?= date('d/m/Y', strtotime($latestAnamnesis['created_at'])) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($anDraft): ?>
                    <span class="badge badge-warning u-text-caption u-ml-1" data-testid="profile-anamnesis-draft"><?= htmlspecialchars($anDraft['label']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="u-flex u-gap-1">
                  <a href="/pacientes/<?= $p['id'] ?>/anamnese" class="btn btn-sm btn-outline u-text-compact u-no-underline u-py-0 u-px-2">
                    <?= !empty($anDraft) ? 'Continuar alteração (v' . (int)$anDraft['version'] . ') →' : ($latestAnamnesis ? 'Atualizar / ver histórico →' : '+ Realizar anamnese →') ?>
                  </a>
                </div>
              </div>

              <?php if (!$latestAnamnesis): ?>
                <p class="u-color-neutral-gray-500 u-text-compact">Nenhuma anamnese cadastrada para este paciente. Clique acima para coletar objetivos e restrições.</p>
              <?php else: ?>
                <!-- Destaques Críticos da Anamnese -->
                <div class="u-grid u-cols-auto-md u-gap-2 u-mb-2">
                  <!-- Alergias -->
                  <div class="ds-clinical-flag<?= !empty($latestAnamnesis['allergies']) ? ' ds-clinical-flag--critical op-message--critical' : '' ?>" data-testid="profile-allergy-flag"<?= !empty($latestAnamnesis['allergies']) ? ' data-severity="critical"' : '' ?>>
                    <span class="ds-clinical-flag__title"><?php if (!empty($latestAnamnesis['allergies'])): ?><span class="cn-flag-icon" aria-hidden="true"></span><span class="sr-only">Alerta clínico: </span><?php endif; ?>
                      <?= !empty($latestAnamnesis['allergies']) ? 'ALERGIAS (CRÍTICO)' : 'ALERGIAS' ?>
                    </span>
                    <span class="u-text-compact u-color-neutral-ink-900">
                      <?= htmlspecialchars($clinicalSummary['allergy_label']) ?><?= !empty($clinicalSummary['allergies']) ? ': ' . htmlspecialchars($clinicalSummary['allergies']) : '' ?>
                    </span>
                  </div>

                  <!-- Restrições / Intolerâncias -->
                  <div class="ds-clinical-flag<?= !empty($latestAnamnesis['restrictions']) ? ' ds-clinical-flag--warning op-message--warning' : '' ?>" data-testid="profile-restriction-flag"<?= !empty($latestAnamnesis['restrictions']) ? ' data-severity="warning"' : '' ?>>
                    <span class="ds-clinical-flag__title"><?php if (!empty($latestAnamnesis['restrictions'])): ?><span class="cn-flag-icon" aria-hidden="true"></span><span class="sr-only">Atenção: </span><?php endif; ?>
                      <?= !empty($latestAnamnesis['restrictions']) ? 'RESTRIÇÕES ALIMENTARES' : 'RESTRIÇÕES' ?>
                    </span>
                    <span class="u-text-compact u-color-neutral-ink-900">
                      <?= htmlspecialchars(LabelMap::recorded($latestAnamnesis['restrictions'] ?? null)) ?>
                    </span>
                  </div>

                  <!-- Objetivos -->
                  <div class="u-py-2 u-px-3 u-radius-input u-bg-neutral-gray-50 u-border">
                    <span class="u-text-caption u-font-bold u-color-semantic-info u-block">OBJETIVOS & METAS</span>
                    <span class="u-text-compact u-color-neutral-ink-900">
                      <?= htmlspecialchars($latestAnamnesis['objectives'] ?? 'Não especificados') ?>
                    </span>
                  </div>
                </div>

                <?php if (!empty($latestAnamnesis['symptoms'])): ?>
                  <div class="u-py-1 u-px-3 u-bg-neutral-gray-50 u-border u-radius-input u-text-compact u-mt-1">
                    <strong>Sinais e sintomas:</strong> <?= htmlspecialchars($latestAnamnesis['symptoms']) ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <!-- Card 3: Planos Alimentares -->
            <div class="card u-mb-6" id="secao-planos">
              <div class="card__header u-flex u-justify-between u-items-center u-mb-3">
                <h3 class="u-m-0">Planos alimentares (<?= count($profile['meal_plans'] ?? []) ?>)</h3>
                <button type="button" class="btn btn-secondary u-text-compact u-py-1 u-px-2" data-show="modal-new-plan-p360">
                  + Novo Plano
                </button>
              </div>
              <?php if (empty($profile['meal_plans'])): ?>
                <p class="u-color-neutral-gray-500 u-text-compact">Nenhum plano alimentar prescrito ainda para este paciente.</p>
              <?php else: ?>
                <div class="u-flex u-flex-col u-gap-2">
                  <?php foreach ($profile['meal_plans'] as $mp): ?>
                    <div class="u-flex u-justify-between u-items-center u-py-2 u-px-3 u-bg-neutral-gray-50 u-border u-radius-input">
                      <div>
                        <strong class="u-color-botanical-forest-900 u-text-body"><?= htmlspecialchars($mp['title']) ?></strong>
                        <span class="u-text-caption u-color-neutral-gray-500 u-ml-1">v<?= $mp['version'] ?></span>
                        <span class="u-ml-1"><?= LabelMap::mealPlanStatusBadge($mp['status']) ?></span>
                      </div>
                      <a href="/planos/<?= $mp['id'] ?>" class="btn btn-sm btn-outline u-text-compact u-no-underline u-py-0 u-px-2">
                        Abrir editor →
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Modal Novo Plano para Paciente -->
            <div id="modal-new-plan-p360" data-dialog="modal" class="modal-overlay">
              <div class="cn-dialog__panel u-bg-neutral-white u-radius-card u-w-full u-maxw-form u-p-6 u-shadow-lg">
                <h3 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-2">Nova prescrição alimentar</h3>
                <p class="u-color-neutral-gray-500 u-text-compact u-mb-4">Paciente: <strong><?= htmlspecialchars($p['name']) ?></strong></p>
                <form method="POST" action="/planos/novo">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <input type="hidden" name="patient_id" value="<?= $p['id'] ?>">
                  <div class="u-mb-4">
                    <label for="dc1-title" class="u-block u-font-semibold u-mb-1 u-text-compact">Título do Plano *</label>
                    <input id="dc1-title" type="text" name="title" required placeholder="Ex: Plano Alimentar Fase 1" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  </div>
                  <div class="u-mb-5">
                    <label for="dc2-notes" class="u-block u-font-semibold u-mb-1 u-text-compact">Orientações iniciais (opcional)</label>
                    <textarea id="dc2-notes" name="notes" rows="3" placeholder="Recomendações clínicas, horários, hidratação..." class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
                  </div>
                  <div class="u-flex u-justify-end u-gap-2">
                    <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-plan-p360">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Abrir editor</button>
                  </div>
                </form>
              </div>
            </div>

            <!-- Card de Avaliações Antropométricas com Indicadores OMS -->
            <div class="card" id="secao-avaliacoes">
              <div class="card__header u-flex u-justify-between u-items-center u-mb-3">
                <h3 class="u-m-0">Avaliações antropométricas (<?= count($assessments) ?>)</h3>
                <a href="/pacientes/<?= $p['id'] ?>/antropometria" class="u-text-compact u-color-botanical-copper-600 u-font-semibold u-no-underline">Análise completa →</a>
              </div>

              <?php if (empty($assessments)): ?>
                <div class="u-text-center u-p-6 u-color-neutral-gray-500">
                  <p>Nenhuma avaliação antropométrica registrada ainda para este paciente.</p>
                  <button type="button" class="btn btn-secondary u-mt-2" data-call="openAnthroModal">Registrar 1ª avaliação</button>
                </div>
              <?php else: ?>
                <div class="u-flex u-flex-col u-gap-3">
                  <?php foreach (array_reverse(array_slice($assessments, -5)) as $as): ?>
                    <div class="u-bg-neutral-gray-50 u-border u-radius-input u-py-3 u-px-4">
                      <div class="u-flex u-justify-between u-items-start u-mb-2 u-wrap u-gap-2">
                        <div>
                          <strong class="u-color-botanical-forest-900 u-text-body"><?= date('d/m/Y', strtotime($as['assessed_at'])) ?></strong>
                          <span class="u-text-compact u-color-neutral-gray-500 u-ml-2">Avaliador: <?= htmlspecialchars($as['professional_name'] ?? 'Nutricionista') ?></span>
                        </div>
                        <div class="u-flex u-items-center u-gap-2">
                          <?php if ($as['is_shared']): ?>
                            <span class="badge badge-success u-text-caption">✓ Liberado ao Paciente</span>
                          <?php else: ?>
                            <span class="badge badge-secondary u-text-caption u-bg-neutral-gray-200 u-color-neutral-gray-600">Privado (clínico)</span>
                          <?php endif; ?>
                          
                          <form action="/pacientes/<?= $p['id'] ?>/antropometria/<?= $as['id'] ?>/compartilhar" method="POST" class="u-inline">
                            <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="is_shared" value="<?= $as['is_shared'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-secondary u-text-caption u-py-0 u-px-2 u-minh-touch">
                              <?= $as['is_shared'] ? 'Revogar' : 'Liberar' ?>
                            </button>
                          </form>
                        </div>
                      </div>

                      <div class="u-grid u-cols-auto-sm u-gap-2 u-text-compact">
                        <div>
                          <span class="u-color-neutral-gray-500 u-text-caption u-block">PESO & ALTURA</span>
                          <strong class="tabular-nums u-color-botanical-forest-900"><?= htmlspecialchars(LabelMap::num($as['weight_kg'] ?? null, 1)) ?> kg</strong> / <span class="tabular-nums"><?= htmlspecialchars(LabelMap::num($as['height_cm'] ?? null, 1)) ?> cm</span>
                        </div>
                        <div>
                          <span class="u-color-neutral-gray-500 u-text-caption u-block">IMC (OMS)</span>
                          <strong class="tabular-nums u-color-botanical-forest-900"><?= htmlspecialchars(LabelMap::num($as['bmi'] ?? null, 2)) ?></strong>
                          <span class="badge badge-info u-text-caption u-py-0 u-px-1"><?= $as['bmi_classification'] ?></span>
                        </div>
                        <?php if ($as['whr'] !== null): ?>
                          <div>
                            <span class="u-color-neutral-gray-500 u-text-caption u-block">RCQ (CINTURA/QUADRIL)</span>
                            <strong class="tabular-nums u-color-botanical-forest-900"><?= $as['whr'] ?></strong>
                            <span class="u-text-caption u-color-neutral-gray-600 u-block"><?= $as['whr_classification'] ?></span>
                          </div>
                        <?php endif; ?>
                        <?php if ($as['body_fat_pct'] !== null): ?>
                          <div>
                            <span class="u-color-neutral-gray-500 u-text-caption u-block">GORDURA CORPORAL</span>
                            <strong class="tabular-nums u-color-botanical-copper-600"><?= htmlspecialchars(LabelMap::num($as['body_fat_pct'] ?? null, 1)) ?>%</strong>
                            <?php if ($as['fat_mass_kg'] !== null): ?>
                              <span class="u-text-caption u-color-neutral-gray-500">(<?= $as['fat_mass_kg'] ?> kg)</span>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($as['notes'])): ?>
                        <div class="u-mt-2 u-text-compact u-color-neutral-gray-600 u-bg-neutral-white u-py-1 u-px-2 u-radius-input u-border">
                          <strong>Notas:</strong> <?= htmlspecialchars($as['notes']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <section class="card u-mb-6" id="secao-documentos" aria-labelledby="docs-title">
          <h3 id="docs-title" class="u-mt-0">Documentos</h3>
          <p class="u-color-neutral-gray-600 u-text-compact">A área completa está em <a href="/pacientes/<?= (int)$p['id'] ?>/documentos">Documentos</a>. Manipulados = fórmulas/suplementação.</p>
          <ul class="cn-profile-links" data-testid="profile-docs">
            <li><a href="/pacientes/<?= (int)$p['id'] ?>/prescricoes" data-testid="profile-manipulados-btn">Manipulados (fórmulas)</a></li>
            <li><a href="/pacientes/<?= (int)$p['id'] ?>/prontuario/imprimir">Prontuário para impressão</a></li>
            <li><a href="/pacientes/<?= (int)$p['id'] ?>/prontuario/exportar">Exportar prontuário</a></li>
            <li><a href="#patient-consents">Termos e consentimentos</a></li>
          </ul>
        </section>
        <?php require __DIR__ . '/../../../views/patients/longitudinal.php'; ?>
        <?php $pid = (int)$p['id']; require __DIR__ . '/../../../views/patients/app-evolution.php'; ?>
        <?php if ($chatLinkSvc->available()) require __DIR__ . '/../../../views/patients/chat-link.php'; ?>
        <?php require __DIR__ . '/../../../views/patients/app-hydration.php'; ?>
          </div>
        </details>

        <!-- Modal Nova Avaliação Antropométrica -->
        <div id="anthroModal" data-dialog="modal" class="modal-overlay" data-call-self="closeAnthroModal">
          <div class="cn-dialog__panel modal-dialog u-maxw-prose">
            <div class="modal-header">
              <h3>Nova avaliação antropométrica</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closeAnthroModal" aria-label="Fechar modal">✕</button>
            </div>
            <?php /* NI2-602: id do paciente lido de $profile, não de $p (views incluídas acima podem reaproveitar nomes). */ $anthroPatientId = (int)$profile['patient']['id']; ?>
            <?= \ClyoNutri\Application\View\ClinicalContext::render(Connection::getInstance(),$anthroPatientId) ?>
            <form method="POST" action="/pacientes/<?= $anthroPatientId ?>/antropometria" data-testid="profile-anthro-form" data-patient-id="<?= $anthroPatientId ?>">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="patient_id" value="<?= $anthroPatientId ?>">

              <div class="modal-body u-maxh-dialog u-overflow-y-auto">
                <div class="u-grid u-cols-2 u-gap-4">
                  <div class="form-group">
                    <label class="form-label" for="ant_weight">Peso corporal (kg) *</label>
                    <input type="number" step="0.1" min="10" max="400" id="ant_weight" name="weight_kg" class="form-control" placeholder="Ex: 72.5" required autofocus>
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_height">Altura (cm ou m) *</label>
                    <input type="number" step="any" min="0.5" max="260" id="ant_height" name="height_cm" class="form-control" placeholder="Ex: 175 (ou 1,75)" required>
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="ant_date">Data da avaliação</label>
                  <input type="datetime-local" id="ant_date" name="assessed_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                </div>

                <h4 class="u-text-body u-mt-4 u-mb-2 u-color-botanical-forest-900">Circunferências Corporais (cm)</h4>
                <div class="u-grid u-cols-3 u-gap-3">
                  <div class="form-group">
                    <label class="form-label" for="ant_waist">Cintura (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_waist" name="waist_cm" class="form-control" placeholder="Ex: 82.0">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_hip">Quadril (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_hip" name="hip_cm" class="form-control" placeholder="Ex: 98.0">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_neck">Pescoço (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_neck" name="neck_cm" class="form-control" placeholder="Ex: 38.0">
                  </div>
                </div>

                <div class="u-grid u-cols-4 u-gap-3">
                  <div class="form-group">
                    <label class="form-label" for="ant_arm">Braço (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_arm" name="arm_cm" class="form-control" placeholder="Ex: 32.5">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_chest">Tórax (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_chest" name="chest_cm" class="form-control" placeholder="Ex: 96.0">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_thigh">Coxa (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_thigh" name="thigh_cm" class="form-control" placeholder="Ex: 55.0">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_calf">Panturrilha (cm)</label>
                    <input type="number" step="0.1" min="10" max="250" id="ant_calf" name="calf_cm" class="form-control" placeholder="Ex: 37.0">
                  </div>
                </div>

                <h4 class="u-text-body u-mt-4 u-mb-2 u-color-botanical-forest-900">Composição corporal informada (bioimpedância / dobras)</h4>
                <div class="u-grid u-cols-2 u-gap-4">
                  <div class="form-group">
                    <label class="form-label" for="ant_fat">% Gordura corporal</label>
                    <input type="number" step="0.1" min="1" max="75" id="ant_fat" name="body_fat_pct" class="form-control" placeholder="Ex: 18.5">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_muscle">% Massa muscular</label>
                    <input type="number" step="0.1" min="5" max="85" id="ant_muscle" name="muscle_mass_pct" class="form-control" placeholder="Ex: 42.0">
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="ant_notes">Anotações clínicas e metas</label>
                  <textarea id="ant_notes" name="notes" class="form-control u-minh-field" placeholder="Observações do protocolo, balança utilizada ou conduta..."></textarea>
                </div>

                <div class="u-mt-3 u-p-3 u-bg-neutral-gray-50 u-border u-radius-input">
                  <label class="u-flex u-items-center u-gap-2 u-pointer u-text-compact u-color-botanical-forest-900 u-font-medium">
                    <input type="checkbox" name="is_shared" value="1" class="ds-check"><?php /* UX-02 (v14): liberação ao app é opt-in (padrão privado) */ ?>
                    Liberar imediatamente para o paciente visualizar no aplicativo
                  </label>
                </div>
              </div>

              <div class="modal-footer">
                <button data-dialog-close type="button" class="btn btn-secondary" data-call="closeAnthroModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar avaliação antropométrica</button>
              </div>
            </form>
          </div>
        </div>

        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          function openAnthroModal() {
            document.getElementById('anthroModal').classList.add('active');
            document.getElementById('ant_weight').focus();
          }
          function closeAnthroModal() {
            document.getElementById('anthroModal').classList.remove('active');
          }
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Tela dedicada de Evolução Temporal, Indicadores e Comparação de Avaliações (NI2-67, NI2-68, NI2-69).
     */
    public function patientAnthropometry(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $patient = $repo->findById($id);

        if (!$patient) {
            header('Location: /pacientes');
            exit;
        }

        $metric = $_GET['metric'] ?? 'weight_kg';
        $anthroService = new AnthropometryService();
        $evolution = $anthroService->getEvolution($id, $metric);
        $assessments = $evolution['assessments'];
        $series = $evolution['series'];

        // Comparativo entre avaliações (NI2-68)
        $comparison = null;
        $evalAId = isset($_GET['eval_a']) ? (int)$_GET['eval_a'] : 0;
        $evalBId = isset($_GET['eval_b']) ? (int)$_GET['eval_b'] : 0;

        if ($evalAId > 0 && $evalBId > 0 && $evalAId !== $evalBId) {
            try {
                $comparison = $anthroService->compareAssessments($id, $evalAId, $evalBId);
            } catch (\Throwable $e) {
                $comparison = null;
            }
        } elseif (count($assessments) >= 2) {
            // Comparação padrão automática: primeira (baseline) vs última (atual)
            $firstEval = $assessments[0];
            $lastEval = end($assessments);
            try {
                $comparison = $anthroService->compareAssessments($id, (int)$firstEval['id'], (int)$lastEval['id']);
                $evalAId = (int)$firstEval['id'];
                $evalBId = (int)$lastEval['id'];
            } catch (\Throwable $e) {
                $comparison = null;
            }
        }

        $latestEval = !empty($assessments) ? end($assessments) : null;
        $firstEval = !empty($assessments) ? $assessments[0] : null;

        $title = 'Antropometria e avaliações · ' . $patient['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['error'])): ?>
          <div class="op-message op-message--error" role="alert">
            <strong>Erro:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na operação.' ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['success'])): ?>
          <div role="status" class="op-message op-message--success u-mb-4">
            <?= $_GET['success'] === 'avaliacao_registrada' ? 'Avaliação salva.' : ($_GET['success'] === 'avaliacao_ja_registrada' ? 'Esta avaliação já estava registrada; o reenvio não criou duplicata.' : 'Visibilidade no app do paciente atualizada.') ?>
          </div>
        <?php endif; ?>

        <!-- Cabeçalho -->
        <?= \ClyoNutri\Application\View\PatientHeader::render(Connection::getInstance(), (int)$patient['id'], 'avaliacoes') // NI2-661 ?>
        <div class="u-mb-6 u-flex u-justify-between u-items-center u-wrap u-gap-3">
          <div>
            <h2 class="patient-page-title">Antropometria e avaliações</h2>
            <p class="u-color-neutral-gray-500">Histórico dos indicadores, composição corporal e comparação entre avaliações.</p>
          </div>
          <div class="u-flex u-gap-2 u-wrap">
            <a class="btn btn-outline" href="/pacientes/<?= (int)$patient['id'] ?>/antropometria/dobras" data-testid="link-skinfolds">Dobras cutâneas</a>
            <button type="button" class="btn btn-primary" data-call="openAnthroModal">
              + Nova Avaliação
            </button>
          </div>
        </div>

        <!-- KPIs Resumo Clínico -->
        <?php if ($latestEval): ?>
          <div class="u-grid u-cols-auto-md u-gap-4 u-mb-6">
            <div class="card u-mb-0">
              <div class="u-text-caption u-uppercase u-color-neutral-gray-500 u-font-bold">ÚLTIMO PESO REGISTRADO</div>
              <div class="tabular-nums u-text-h1 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">
                <?= htmlspecialchars(LabelMap::num($latestEval['weight_kg'] ?? null, 1)) ?> kg
              </div>
              <?php if ($firstEval && $firstEval['id'] !== $latestEval['id']): ?>
                <?php $deltaTot = round($latestEval['weight_kg'] - $firstEval['weight_kg'], 1); ?>
                <span class="u-text-compact u-font-semibold <?= $deltaTot < 0 ? 'ds-delta--down' : 'ds-delta--up' ?>">
                  <?= $deltaTot > 0 ? "+{$deltaTot}" : $deltaTot ?> kg desde a 1ª consulta
                </span>
              <?php else: ?>
                <span class="u-text-compact u-color-neutral-gray-500">Primeira avaliação</span>
              <?php endif; ?>
            </div>

            <div class="card u-mb-0">
              <div class="u-text-caption u-uppercase u-color-neutral-gray-500 u-font-bold">IMC ATUAL (OMS)</div>
              <div class="tabular-nums u-text-h1 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">
                <?= htmlspecialchars(LabelMap::num($latestEval['bmi'] ?? null, 2)) ?>
              </div>
              <span class="badge badge-info"><?= $latestEval['bmi_classification'] ?></span>
            </div>

            <?php if ($latestEval['whr'] !== null): ?>
              <div class="card u-mb-0">
                <div class="u-text-caption u-uppercase u-color-neutral-gray-500 u-font-bold">RCQ (CINTURA / QUADRIL)</div>
                <div class="tabular-nums u-text-h1 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">
                  <?= htmlspecialchars(LabelMap::num($latestEval['whr'] ?? null, 2)) ?>
                </div>
                <span class="badge badge-<?= $latestEval['whr_risk'] === 'baixo' ? 'success' : ($latestEval['whr_risk'] === 'moderado' ? 'warning' : 'danger') ?>">
                  <?= $latestEval['whr_classification'] ?>
                </span>
              </div>
            <?php endif; ?>

            <?php if ($latestEval['body_fat_pct'] !== null): ?>
              <div class="card u-mb-0" data-testid="kpi-body-fat-measured">
                <div class="u-text-caption u-uppercase u-color-neutral-gray-500 u-font-bold">% GORDURA (MEDIDA / BIA)</div>
                <div class="tabular-nums u-text-h1 u-font-bold u-color-botanical-copper-600 u-my-1 u-mx-0">
                  <?= htmlspecialchars(LabelMap::num($latestEval['body_fat_pct'] ?? null, 1)) ?>%
                </div>
                <span class="u-text-compact u-color-neutral-gray-500 u-block">
                  <?= $latestEval['fat_mass_kg'] !== null ? "{$latestEval['fat_mass_kg']} kg massa gorda · " : '' ?>Origem: <?= htmlspecialchars($latestEval['body_fat_source_label'] ?? 'Informado manualmente') ?>
                </span>
              </div>
            <?php endif; ?>

            <?php if (!empty($latestEval['derived_skinfold'])): $dsf = $latestEval['derived_skinfold']; ?>
              <div class="card u-mb-0" data-testid="kpi-body-fat-skinfold">
                <div class="u-text-caption u-uppercase u-color-neutral-gray-500 u-font-bold">% GORDURA (ESTIMADA POR DOBRAS)</div>
                <div class="tabular-nums u-text-h1 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">
                  <?= htmlspecialchars(LabelMap::num($dsf['body_fat_pct'] ?? null, 1)) ?>%
                </div>
                <span class="u-text-compact u-color-neutral-gray-500 u-block">
                  <?= $dsf['fat_mass_kg'] !== null ? "{$dsf['fat_mass_kg']} kg · " : '' ?><?= htmlspecialchars($dsf['source_label']) ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Alternativa Textual Acessível -->
        <div class="card u-accent u-accent-brand-copper-500 u-bg-botanical-cream-50 u-mb-6">
          <div class="u-flex u-gap-3 u-items-center">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="u-color-botanical-copper-600 u-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
              <strong class="u-color-botanical-forest-900 u-text-body u-block">Resumo clínico da evolução (acessibilidade e auditoria):</strong>
              <span class="u-text-compact u-color-neutral-ink-900"><?= htmlspecialchars($evolution['accessible_summary']) ?></span>
            </div>
          </div>
        </div>

        <!-- Seletor de Métrica & Linha do Tempo -->
        <div class="card u-mb-6">
          <div class="card__header u-flex u-justify-between u-items-center u-wrap u-gap-3">
            <h3>Série histórica ponto a ponto</h3>
            <div class="u-flex u-gap-2 u-items-center">
              <label for="dc3-campo" class="u-text-compact u-font-semibold u-color-neutral-gray-600">Métrica:</label>
              <select id="dc3-campo" aria-label="Medida da evolução antropométrica" data-nav-prefix="/pacientes/<?= (int)$patient['id'] ?>/antropometria?metric=" class="u-py-1 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                <option value="weight_kg" <?= $metric === 'weight_kg' ? 'selected' : '' ?>>Peso Corporal (kg)</option>
                <option value="bmi" <?= $metric === 'bmi' ? 'selected' : '' ?>>IMC (kg/m²)</option>
                <option value="waist_cm" <?= $metric === 'waist_cm' ? 'selected' : '' ?>>Cintura (cm)</option>
                <option value="hip_cm" <?= $metric === 'hip_cm' ? 'selected' : '' ?>>Quadril (cm)</option>
                <option value="whr" <?= $metric === 'whr' ? 'selected' : '' ?>>RCQ (cintura/quadril)</option>
                <option value="whtr" <?= $metric === 'whtr' ? 'selected' : '' ?>>RCE (cintura/estatura)</option>
                <option value="body_fat_pct" <?= $metric === 'body_fat_pct' ? 'selected' : '' ?>>Gordura corporal (%)</option>
                <option value="muscle_mass_pct" <?= $metric === 'muscle_mass_pct' ? 'selected' : '' ?>>Massa muscular (%)</option>
              </select>
            </div>
          </div>

          <?php if (empty($series)): ?>
            <p class="u-color-neutral-gray-500 u-text-center u-p-6">Nenhuma medição encontrada para esta métrica.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>Variação ponto anterior</th>
                    <th>Variação %</th>
                    <th>Status paciente</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($series as $pnt): ?>
                    <tr>
                      <td class="u-font-semibold u-color-botanical-forest-900"><?= $pnt['formatted_date'] ?></td>
                      <td class="tabular-nums u-font-bold u-text-body u-color-botanical-forest-900"><?= number_format((float)$pnt['value'], 2, ',', '.') ?></td>
                      <td class="tabular-nums">
                        <?php if ($pnt['delta_previous'] === null): ?>
                          <span class="u-color-neutral-gray-500">Baseline inicial</span>
                        <?php else: ?>
                          <span class="u-font-semibold <?= $pnt['delta_previous'] < 0 ? 'ds-delta--down' : 'ds-delta--up' ?>">
                            <?= ($pnt['delta_previous'] > 0 ? '+' : '') . number_format((float)$pnt['delta_previous'], 2, ',', '.') ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="tabular-nums">
                        <?php if ($pnt['delta_previous_pct'] === null): ?>
                          —
                        <?php else: ?>
                          <span class="<?= $pnt['delta_previous_pct'] < 0 ? 'ds-delta--down' : 'ds-delta--up' ?>">
                            <?= ($pnt['delta_previous_pct'] > 0 ? '+' : '') . number_format((float)$pnt['delta_previous_pct'], 2, ',', '.') . '%' ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php 
                          $isSh = false;
                          foreach ($assessments as $aItem) {
                              if ((int)$aItem['id'] === (int)$pnt['assessment_id']) {
                                  $isSh = $aItem['is_shared'];
                                  break;
                              }
                          }
                        ?>
                        <?php if ($isSh): ?>
                          <span class="badge badge-success">✓ Visível no app</span>
                        <?php else: ?>
                          <span class="badge badge-secondary u-bg-neutral-gray-200 u-color-neutral-gray-600">Privado</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <form action="/pacientes/<?= $patient['id'] ?>/antropometria/<?= $pnt['assessment_id'] ?>/compartilhar" method="POST" class="u-inline">
                          <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                          <input type="hidden" name="is_shared" value="<?= $isSh ? '0' : '1' ?>">
                          <button type="submit" class="btn btn-secondary u-text-caption u-py-0 u-px-2 u-minh-touch">
                            <?= $isSh ? 'Revogar do Paciente' : 'Liberar ao Paciente' ?>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Seção: Comparador Clínico de Avaliações -->
        <div class="card u-mb-6">
          <div class="card__header u-flex u-justify-between u-items-center u-wrap u-gap-3">
            <div>
              <h3>Comparativo clínico entre avaliações</h3>
              <p class="u-color-neutral-gray-500 u-m-0 u-text-compact">Selecione duas avaliações para analisar deltas absolutos, percentuais e evolução corporal.</p>
            </div>
            
            <?php if (count($assessments) >= 2): ?>
              <form method="GET" action="/pacientes/<?= $patient['id'] ?>/antropometria" class="u-flex u-gap-2 u-items-center u-wrap">
                <input type="hidden" name="metric" value="<?= htmlspecialchars($metric) ?>">
                <label for="eval_a_select" id="label_eval_a" class="sr-only">Avaliação baseline</label>
                <select id="eval_a_select" name="eval_a" aria-labelledby="label_eval_a" aria-label="Avaliação baseline para comparação" class="u-py-1 u-px-2 u-border u-bc-border-input u-radius-input u-text-compact">
                  <?php foreach ($assessments as $opt): ?>
                    <option value="<?= $opt['id'] ?>" <?= $evalAId === (int)$opt['id'] ? 'selected' : '' ?>>
                      Baseline: <?= date('d/m/Y', strtotime($opt['assessed_at'])) ?> (<?= htmlspecialchars(LabelMap::num($opt['weight_kg'] ?? null, 1)) ?> kg)
                    </option>
                  <?php endforeach; ?>
                </select>

                <span class="u-font-semibold u-color-neutral-gray-500" aria-hidden="true">vs</span>

                <label for="eval_b_select" id="label_eval_b" class="sr-only">Avaliação comparada</label>
                <select id="eval_b_select" name="eval_b" aria-labelledby="label_eval_b" aria-label="Avaliação comparada para evolução" class="u-py-1 u-px-2 u-border u-bc-border-input u-radius-input u-text-compact">
                  <?php foreach ($assessments as $opt): ?>
                    <option value="<?= $opt['id'] ?>" <?= $evalBId === (int)$opt['id'] ? 'selected' : '' ?>>
                      Comparar: <?= date('d/m/Y', strtotime($opt['assessed_at'])) ?> (<?= htmlspecialchars(LabelMap::num($opt['weight_kg'] ?? null, 1)) ?> kg)
                    </option>
                  <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-secondary u-minh-touch" aria-label="Comparar avaliações selecionadas">Comparar</button>
              </form>
            <?php endif; ?>
          </div>

          <?php if (!$comparison): ?>
            <p class="u-color-neutral-gray-500 u-text-center u-p-6">
              <?= count($assessments) < 2 ? 'São necessárias pelo menos duas avaliações para realizar o comparativo clínico.' : 'Selecione duas avaliações distintas para comparar.' ?>
            </p>
          <?php else: ?>
            <div class="u-bg-neutral-gray-50 u-border u-radius-input u-py-3 u-px-4 u-mb-4 u-flex u-justify-between u-items-center u-wrap u-gap-2">
              <div>
                <strong>Período:</strong> <?= $comparison['baseline']['formatted_date'] ?> → <?= $comparison['followup']['formatted_date'] ?>
                <span class="u-color-neutral-gray-500 u-ml-2">(<?= $comparison['days_between'] ?> dias decorridos)</span>
              </div>
              <div class="u-text-compact u-color-neutral-gray-600">
                Avaliadores: <?= htmlspecialchars($comparison['baseline']['evaluator']) ?> e <?= htmlspecialchars($comparison['followup']['evaluator']) ?>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Indicador / métrica</th>
                    <th class="tabular-nums">Baseline (<?= $comparison['baseline']['formatted_date'] ?>)</th>
                    <th class="tabular-nums">Follow-up (<?= $comparison['followup']['formatted_date'] ?>)</th>
                    <th class="tabular-nums">Delta absoluto</th>
                    <th class="tabular-nums">Delta percentual</th>
                    <th>Tendência</th>
                    <th>Status do Dado</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($comparison['metrics'] as $mKey => $mRow): ?>
                    <tr>
                      <td class="u-font-semibold u-color-botanical-forest-900"><?= htmlspecialchars($mRow['label']) ?></td>
                      <td class="tabular-nums">
                        <?= $mRow['initial_value'] !== null ? "{$mRow['initial_value']} {$mRow['unit']}" : '<span class="u-color-neutral-gray-400">—</span>' ?>
                      </td>
                      <td class="tabular-nums">
                        <?= $mRow['final_value'] !== null ? "{$mRow['final_value']} {$mRow['unit']}" : '<span class="u-color-neutral-gray-400">—</span>' ?>
                      </td>
                      <td class="tabular-nums">
                        <?php if ($mRow['delta_abs'] === null): ?>
                          <span class="u-color-neutral-gray-400">—</span>
                        <?php else: ?>
                          <strong class="<?= $mRow['delta_abs'] < 0 ? 'ds-delta--down' : ($mRow['delta_abs'] > 0 ? 'ds-delta--up' : 'ds-delta--flat') ?>">
                            <?= $mRow['delta_abs'] > 0 ? "+{$mRow['delta_abs']}" : $mRow['delta_abs'] ?> <?= $mRow['unit'] ?>
                          </strong>
                        <?php endif; ?>
                      </td>
                      <td class="tabular-nums">
                        <?php if ($mRow['delta_pct'] === null): ?>
                          <span class="u-color-neutral-gray-400">—</span>
                        <?php else: ?>
                          <strong class="<?= $mRow['delta_pct'] < 0 ? 'ds-delta--down' : ($mRow['delta_pct'] > 0 ? 'ds-delta--up' : 'ds-delta--flat') ?>">
                            <?= $mRow['delta_pct'] > 0 ? "+{$mRow['delta_pct']}%" : "{$mRow['delta_pct']}%" ?>
                          </strong>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($mRow['trend'] === 'decrease'): ?>
                          <span class="u-color-semantic-success u-font-bold">↓ Redução</span>
                        <?php elseif ($mRow['trend'] === 'increase'): ?>
                          <span class="u-color-botanical-copper-600 u-font-bold">↑ Aumento</span>
                        <?php elseif ($mRow['trend'] === 'stable'): ?>
                          <span class="u-color-neutral-gray-500">↔ Estável</span>
                        <?php else: ?>
                          <span class="u-color-neutral-gray-400">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($mRow['status'] === 'evaluated'): ?>
                          <span class="badge badge-success u-text-caption">Consistente</span>
                        <?php elseif ($mRow['status'] === 'missing_in_baseline'): ?>
                          <span class="badge badge-warning u-text-caption">Não medido no início</span>
                        <?php elseif ($mRow['status'] === 'missing_in_followup'): ?>
                          <span class="badge badge-warning u-text-caption">Não medido no follow-up</span>
                        <?php else: ?>
                          <span class="badge badge-secondary u-text-caption">Não medido</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <?php /* Modal Nova Avaliação Antropométrica Unificada (NI2-524) */ ?>
        <?php
          $patientSex = \ClyoNutri\Domain\Anthropometry\SkinfoldService::sexFromPatient($patient['gender'] ?? null);
          $patientAge = \ClyoNutri\Domain\Anthropometry\SkinfoldService::ageAt($patient['birth_date'] ?? null, date('Y-m-d'));
          $skinfoldEligibility = \ClyoNutri\Domain\Anthropometry\SkinfoldProtocolCatalog::eligibleFor($patientSex, $patientAge);
        ?>
        <div id="anthroModal" data-dialog="modal" class="modal-overlay" data-call-self="closeAnthroModal">
          <div class="cn-dialog__panel modal-dialog u-maxw-prose">
            <div class="modal-header">
              <h3>Nova avaliação antropométrica unificada</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closeAnthroModal" aria-label="Fechar modal">✕</button>
            </div>
            <?= \ClyoNutri\Application\View\ClinicalContext::render(Connection::getInstance(),(int)$patient['id']) ?>
            <form method="POST" action="/pacientes/<?= $patient['id'] ?>/antropometria" data-testid="unified-anthropometry-form">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="patient_id" value="<?= $patient['id'] ?>">

              <?php /* Abas do Fluxo Único (NI2-524: rascunho preservado ao navegar entre abas) */ ?>
              <div role="tablist" aria-label="Etapas da avaliação antropométrica" class="u-flex u-gap-1 u-pt-2 u-pb-0 u-px-5 u-border-b u-wrap">
                <button type="button" role="tab" aria-selected="true" aria-controls="anthro-panel-basico" data-anthro-tab="basico" class="btn btn-secondary u-text-compact u-py-1 u-px-3">1. Básico</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="anthro-panel-circunferencias" data-anthro-tab="circunferencias" class="btn btn-outline u-text-compact u-py-1 u-px-3">2. Circunferências (14)</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="anthro-panel-dobras" data-anthro-tab="dobras" class="btn btn-outline u-text-compact u-py-1 u-px-3">3. Dobras Cutâneas</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="anthro-panel-bioimpedancia" data-anthro-tab="bioimpedancia" class="btn btn-outline u-text-compact u-py-1 u-px-3">4. Bioimpedância / Composição</button>
              </div>

              <div class="modal-body u-maxh-dialog u-overflow-y-auto">
                <!-- ABA 1: BÁSICO -->
                <div id="anthro-panel-basico" role="tabpanel" data-anthro-panel="basico">
                  <div class="u-grid u-cols-2 u-gap-4">
                    <div class="form-group">
                      <label class="form-label" for="ant_weight">Peso corporal (kg) *</label>
                      <input type="number" step="0.1" min="10" max="400" id="ant_weight" name="weight_kg" class="form-control" placeholder="Ex: 72.5" required autofocus>
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_height">Altura (cm ou m) *</label>
                      <input type="number" step="any" min="0.5" max="260" id="ant_height" name="height_cm" class="form-control" placeholder="Ex: 175 (ou 1,75)" required>
                    </div>
                  </div>

                  <div class="form-group">
                    <label class="form-label" for="ant_date">Data da avaliação</label>
                    <input type="datetime-local" id="ant_date" name="assessed_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                  </div>

                  <div class="form-group">
                    <label class="form-label" for="ant_notes">Anotações clínicas e metas</label>
                    <textarea id="ant_notes" name="notes" class="form-control u-minh-field" placeholder="Observações do protocolo, balança utilizada ou conduta..."></textarea>
                  </div>

                  <div class="u-mt-3 u-p-3 u-bg-neutral-gray-50 u-border u-radius-input">
                    <label class="u-flex u-items-center u-gap-2 u-pointer u-text-compact u-color-botanical-forest-900 u-font-medium">
                      <input type="checkbox" name="is_shared" value="1" class="ds-check">
                      Liberar imediatamente para o paciente visualizar no aplicativo
                    </label>
                  </div>
                </div>

                <!-- ABA 2: CIRCUNFERÊNCIAS (14 MEDIDAS) -->
                <div id="anthro-panel-circunferencias" role="tabpanel" data-anthro-panel="circunferencias" hidden>
                  <h4 class="u-text-body u-mt-0 u-mb-2 u-color-botanical-forest-900">Circunferências Corporais Ampliadas (14 pontos em cm)</h4>
                  <div class="u-grid u-cols-auto-sm u-gap-3">
                    <?php foreach (\ClyoNutri\Domain\Anthropometry\AnthropometryService::CIRCUMFERENCE_FIELDS as $cField => $cLabel): ?>
                      <div class="form-group u-mb-1">
                        <label class="form-label" for="ant_<?= $cField ?>"><?= htmlspecialchars($cLabel) ?> (cm)</label>
                        <input type="number" step="0.1" min="10" max="250" id="ant_<?= $cField ?>" name="<?= $cField ?>" class="form-control" placeholder="cm">
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- ABA 3: DOBRAS CUTÂNEAS (DERIVADO COM PROVENIÊNCIA) -->
                <div id="anthro-panel-dobras" role="tabpanel" data-anthro-panel="dobras" hidden>
                  <h4 class="u-text-body u-mt-0 u-mb-2 u-color-botanical-forest-900">Dobras Cutâneas por Protocolo Validado (Estimativa Derivada)</h4>
                  <p>Antes de selecionar um protocolo, confirme os parâmetros registrados: sexo para a equação <strong><?= $patientSex==='male'?'Masculino':($patientSex==='female'?'Feminino':'Não informado') ?></strong> e idade <strong><?= $patientAge!==null?(int)$patientAge.' anos':'Não informada' ?></strong>. Eles não representam identidade de gênero nem dispensam a avaliação da população de referência.</p>
                  <?php if(!$patientSex || $patientAge===null): ?><p role="note">Para verificar a elegibilidade, confirme o parâmetro da equação e a idade. <a href="/pacientes/<?= (int)$patient['id'] ?>/antropometria/dobras" target="_blank" rel="noopener">Conferir parâmetros e protocolos em outra aba</a>. Salve esta avaliação antes de sair. Sem elegibilidade, o cálculo permanece indisponível.</p><?php endif; ?>
                  <p class="u-text-compact u-color-neutral-gray-600 u-mb-3">
                    O percentual de gordura calculado por dobras cutâneas é salvo com trilha de proveniência própria (equação, densidade corporal e soma em mm) e <strong>não sobrescreve</strong> a gordura medida por bioimpedância ou informada manualmente.
                  </p>
                  <div class="form-group">
                    <label class="form-label" for="ant_skinfold_protocol">Protocolo de dobras cutâneas (elegibilidade por sexo e idade)</label>
                    <select id="ant_skinfold_protocol" name="skinfold_protocol" class="form-control">
                      <option value="">Não registrar dobras nesta avaliação</option>
                      <?php foreach ($skinfoldEligibility as $el): ?>
                        <option value="<?= htmlspecialchars($el['code']) ?>" <?= !$el['eligible'] ? 'disabled' : '' ?>>
                          <?= htmlspecialchars($el['name']) ?> <?= !$el['eligible'] ? '— Inelegível: ' . htmlspecialchars((string)$el['reason']) : '(Elegível)' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="u-grid u-cols-auto-sm u-gap-3">
                    <?php foreach (\ClyoNutri\Domain\Anthropometry\SkinfoldProtocolCatalog::SITES as $siteCode => $siteMeta): ?>
                      <div class="form-group u-mb-1">
                        <label class="form-label" for="ant_sf_<?= $siteCode ?>"><?= htmlspecialchars($siteMeta['label']) ?> (mm)</label>
                        <input type="number" step="0.1" min="1" max="80" id="ant_sf_<?= $siteCode ?>" name="readings[<?= $siteCode ?>][]" class="form-control" placeholder="mm">
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- ABA 4: BIOIMPEDÂNCIA / COMPOSIÇÃO INFORMADA -->
                <div id="anthro-panel-bioimpedancia" role="tabpanel" data-anthro-panel="bioimpedancia" hidden>
                  <h4 class="u-text-body u-mt-0 u-mb-2 u-color-botanical-forest-900">Composição Corporal Informada / Bioimpedância (BIA)</h4>
                  <div class="u-grid u-cols-2 u-gap-4">
                    <div class="form-group">
                      <label class="form-label" for="ant_fat_source">Origem da medida</label>
                      <select id="ant_fat_source" name="body_fat_source" class="form-control">
                        <option value="manual">Informado manualmente</option>
                        <option value="bioimpedance">Bioimpedância (BIA)</option>
                      </select>
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_bia_device">Equipamento / Balança BIA</label>
                      <input type="text" id="ant_bia_device" name="bioimpedance_device" maxlength="120" class="form-control" placeholder="Ex: InBody 270 / Omron HBF-514">
                    </div>
                  </div>
                  <div class="u-grid u-cols-3 u-gap-3">
                    <div class="form-group">
                      <label class="form-label" for="ant_fat">% Gordura corporal</label>
                      <input type="number" step="0.1" min="1" max="75" id="ant_fat" name="body_fat_pct" class="form-control" placeholder="Ex: 18.5">
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_muscle">% Massa muscular</label>
                      <input type="number" step="0.1" min="5" max="85" id="ant_muscle" name="muscle_mass_pct" class="form-control" placeholder="Ex: 42.0">
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_visceral">Gordura visceral (nível)</label>
                      <input type="number" step="0.5" min="1" max="60" id="ant_visceral" name="visceral_fat_level" class="form-control" placeholder="Ex: 6">
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_tbw">% Água corporal total</label>
                      <input type="number" step="0.1" min="20" max="85" id="ant_tbw" name="total_body_water_pct" class="form-control" placeholder="Ex: 55.2">
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_bone">Massa óssea (kg)</label>
                      <input type="number" step="0.1" min="0.5" max="15" id="ant_bone" name="bone_mass_kg" class="form-control" placeholder="Ex: 2.8">
                    </div>
                    <div class="form-group">
                      <label class="form-label" for="ant_phase">Ângulo de fase (°)</label>
                      <input type="number" step="0.1" min="1" max="15" id="ant_phase" name="phase_angle_deg" class="form-control" placeholder="Ex: 6.4">
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-footer">
                <button data-dialog-close type="button" class="btn btn-secondary" data-call="closeAnthroModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar avaliação antropométrica</button>
              </div>
            </form>
          </div>
        </div>

        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          function openAnthroModal() {
            document.getElementById('anthroModal').classList.add('active');
            document.getElementById('ant_weight').focus();
          }
          function closeAnthroModal() {
            document.getElementById('anthroModal').classList.remove('active');
          }
          document.querySelectorAll('[data-anthro-tab]').forEach(function (tabBtn) {
            tabBtn.addEventListener('click', function () {
              var target = tabBtn.getAttribute('data-anthro-tab');
              document.querySelectorAll('[data-anthro-tab]').forEach(function (b) {
                var active = b.getAttribute('data-anthro-tab') === target;
                b.setAttribute('aria-selected', active ? 'true' : 'false');
                b.className = active ? 'btn btn-secondary' : 'btn btn-outline';
              });
              document.querySelectorAll('[data-anthro-panel]').forEach(function (p) {
                p.hidden = p.getAttribute('data-anthro-panel') !== target;
              });
            });
          });
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function saveAnthropometry(array $params): void
    {
        if (!$this->canEditPlanItems(Connection::getInstance())) { http_response_code(403); echo 'Avaliação não autorizada.'; return; }
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            $patientId = (int)($params['id'] ?? $_POST['patient_id'] ?? 0);
            header("Location: /pacientes/{$patientId}/antropometria?error=csrf");
            exit;
        }

        $patientId = (int)($params['id'] ?? $_POST['patient_id'] ?? 0);
        if ($patientId <= 0) {
            header("Location: /pacientes?error=paciente_invalido");
            exit;
        }
        // NI2-602: o paciente do formulário precisa ser o mesmo da rota. Divergência = formulário montado para
        // outro paciente; nada é gravado e o profissional volta ao paciente indicado no formulário.
        if (isset($_POST['patient_id']) && (string)$_POST['patient_id'] !== '' && (int)$_POST['patient_id'] !== $patientId) {
            error_log('[antropometria] paciente do formulário diverge da rota: rota=' . $patientId . ' form=' . (int)$_POST['patient_id']);
            http_response_code(409);
            echo 'Esta avaliação não foi salva: o formulário pertence a outro paciente. Recarregue a página do paciente e tente de novo.';
            return;
        }

        if (!(new PatientRepository())->findById($patientId)) { http_response_code(404); echo 'Paciente não encontrado.'; return; }
        $profId = $this->clinicalAuthorProfessionalId();
        if ($profId === null) {
            http_response_code(403);
            echo self::SEM_CRN_MSG;
            return;
        }
        \ClyoNutri\Domain\Clinical\FormRecovery::remember($patientId,'antropometria',$_POST);
        $userId = TenantContext::getUserId();
        $service = new AnthropometryService();

        try {
            $saved = $service->recordAssessment($patientId, $profId, $_POST, $userId);
            \ClyoNutri\Domain\Clinical\FormRecovery::clear($patientId,'antropometria');
            header("Location: /pacientes/{$patientId}/antropometria?success=" . (!empty($saved['duplicate']) ? 'avaliacao_ja_registrada' : 'avaliacao_registrada'));
            exit;
        } catch (InvalidAnthropometricDataException $e) {
            header("Location: /pacientes/{$patientId}/antropometria?error=dados_invalidos&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            error_log('[antropometria] falha ao salvar: ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
            header("Location: /pacientes/{$patientId}/antropometria?error=erro_interno&msg=" . urlencode('Não foi possível salvar. O preenchimento foi mantido nesta sessão por uma hora. Tente novamente.'));
            exit;
        }
    }

    public function shareAnthropometry(array $params): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        $patientId = (int)($params['id'] ?? 0);
        $evalId = (int)($params['evalId'] ?? 0);

        if (!Csrf::validate($token)) {
            header("Location: /pacientes/{$patientId}/antropometria?error=csrf");
            exit;
        }

        $isShared = !empty($_POST['is_shared']) && $_POST['is_shared'] === '1';
        $reason = !empty($_POST['reason']) ? trim((string)$_POST['reason']) : ($isShared ? 'Liberado pelo nutricionista' : 'Revogado pelo nutricionista');
        $userId = TenantContext::getUserId();

        $service = new AnthropometryService();
        try {
            $service->shareAssessment($evalId, $isShared, $userId, $reason);
            header("Location: /pacientes/{$patientId}/antropometria?success=compartilhamento_atualizado");
            exit;
        } catch (\Throwable $e) {
            header("Location: /pacientes/{$patientId}/antropometria?error=falha_compartilhar&msg=" . urlencode($this->userFacingError('antropometria compartilhar', $e)));
            exit;
        }
    }

    public function compareAnthropometry(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $evalAId = (int)($_GET['eval_a'] ?? 0);
        $evalBId = (int)($_GET['eval_b'] ?? 0);

        $service = new AnthropometryService();
        try {
            $res = $service->compareAssessments($patientId, $evalAId, $evalBId);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res);
            exit;
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8', true, 400);
            echo json_encode(['error' => $this->userFacingError('antropometria json', $e)]);
            exit;
        }
    }

    /**
     * Tela dedicada do Prontuário Longitudinal Eletrônico com Encadeamento Criptográfico SHA-256 (NI2-60, NI2-63, NI2-64).
     */
    public function patientMedicalRecord(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $patient = $repo->findById($id);

        if (!$patient) {
            header('Location: /pacientes');
            exit;
        }

        \ClyoNutri\Core\Audit\PatientRecordAccessAudit::record($id, 'medical_record'); // CR-NI2-006 / NI2-719
        $title = 'Prontuário · ' . $patient['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();

        $clinicalService = new ClinicalRecordService();
        $integrity = $clinicalService->verifyIntegrity($id);
        $timeline = $clinicalService->getPatientTimeline($id);

        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();
        $clinicalSummary = $anamnesisService->getClinicalSummaryForPrescription($id);

        $auditAccess = Connection::getInstance()->prepare("SELECT 1 FROM clinic_members WHERE clinic_id=? AND user_id=? AND status='active'");
        $auditAccess->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $canSeeAudit = (bool)$auditAccess->fetchColumn();
        // v54.27: link para a consulta de origem só quando ela é desta clínica e deste paciente.
        $consultLinkIds = [];
        try {
            $ap = Connection::getInstance()->prepare('SELECT id FROM appointments WHERE clinic_id = ? AND patient_id = ?');
            $ap->execute([TenantContext::getClinicId(), $id]);
            $consultLinkIds = array_flip(array_map('intval', $ap->fetchAll(\PDO::FETCH_COLUMN)));
        } catch (\Throwable $e) {
            $consultLinkIds = [];
        }
        ob_start();
        // v54.25 (NI2-220 I9/I10): tela no design system, em views/clinical/medical_record.php.
        require __DIR__ . '/../../../views/clinical/medical_record.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function savePatientMedicalRecord(array $params): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        $patientId = (int)($params['id'] ?? 0);

        if (!Csrf::validate($token)) {
            header("Location: /pacientes/{$patientId}/prontuario?error=csrf");
            exit;
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $text = trim((string)($_POST['content_text'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'observacao'));

        if ($title === '' || $text === '') {
            header("Location: /pacientes/{$patientId}/prontuario?error=campos_obrigatorios&msg=" . urlencode("Título e conteúdo são obrigatórios."));
            exit;
        }

        $profId = $this->clinicalAuthorProfessionalId();
        if ($profId === null) {
            header("Location: /pacientes/{$patientId}/prontuario?error=sem_crn&msg=" . urlencode(self::SEM_CRN_MSG));
            exit;
        }
        $clinicalService = new ClinicalRecordService();

        try {
            $clinicalService->addClinicalObservation($patientId, $profId, $title, $text, $category);
            header("Location: /pacientes/{$patientId}/prontuario?success=registro_adicionado");
            exit;
        } catch (\InvalidArgumentException $e) {
            // Regra de negócio com texto escrito para a pessoa (limite de tamanho, campo vazio).
            header("Location: /pacientes/{$patientId}/prontuario?error=dados_invalidos&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            $ref = $this->logClinicalFailure('prontuario.registrar', $e);
            header("Location: /pacientes/{$patientId}/prontuario?error=falha&ref={$ref}");
            exit;
        }
    }

    public function revisePatientMedicalRecord(array $params): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        $patientId = (int)($params['id'] ?? 0);
        $recId = (int)($params['recId'] ?? 0);

        if (!Csrf::validate($token)) {
            header("Location: /pacientes/{$patientId}/prontuario?error=csrf");
            exit;
        }

        $reason = trim((string)($_POST['revision_reason'] ?? ''));
        $revisedText = trim((string)($_POST['revised_text'] ?? ''));

        if (mb_strlen($reason, 'UTF-8') < 10) {
            header("Location: /pacientes/{$patientId}/prontuario?error=motivo_curto&msg=" . urlencode("A justificativa de retificação deve conter no mínimo 10 caracteres."));
            exit;
        }

        $profId = $this->clinicalAuthorProfessionalId();
        if ($profId === null) {
            header("Location: /pacientes/{$patientId}/prontuario?error=sem_crn&msg=" . urlencode(self::SEM_CRN_MSG));
            exit;
        }
        $clinicalService = new ClinicalRecordService();

        try {
            $revised = [
                'type' => 'revised_note',
                'text' => $revisedText,
                'revised_by' => $profId,
            ];
            // v54.27: registro da sala de teleconsulta tem anotações e metas; o serviço mantém esse formato.
            if (array_key_exists('revised_goals', $_POST)) {
                $revised['goals'] = trim((string)$_POST['revised_goals']);
            }
            $clinicalService->reviseRecord($recId, $patientId, $profId, $reason, $revised);
            header("Location: /pacientes/{$patientId}/prontuario?success=registro_retificado");
            exit;
        } catch (\InvalidArgumentException $e) {
            header("Location: /pacientes/{$patientId}/prontuario?error=falha&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            $ref = $this->logClinicalFailure('prontuario.retificar', $e);
            header("Location: /pacientes/{$patientId}/prontuario?error=falha&ref={$ref}");
            exit;
        }
    }

    /** Plano alimentar para impressão (documento do paciente). */
    public function printMealPlan(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        try {
            $doc = (new \ClyoNutri\Domain\MealPlan\MealPlanPrintService())->build(
                $planId,
                (int)TenantContext::getUserId(),
                ($_GET['nutricional'] ?? '') === '1'
            );
        } catch (\ClyoNutri\Core\Security\TenantSecurityException | \InvalidArgumentException $e) {
            http_response_code(404);
            echo 'Plano alimentar não encontrado.';
            return;
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
            header('X-Frame-Options: DENY');
        }
        require __DIR__ . '/../../../views/meal_plans/print.php';
    }

    /**
     * Lista de compras interativa e categorizada por gôndolas (NI2-116).
     */
    public function shoppingList(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $days = isset($_GET['dias']) ? max(1, min(90, (int)$_GET['dias'])) : 7;

        try {
            $db = Connection::getInstance();
            $service = new \ClyoNutri\Domain\ShoppingList\ShoppingListService($db);
            // No painel profissional, permitimos preview de rascunhos com badge indicativo
            $list = $service->generateForPlan($planId, $days, ['require_published' => false]);
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(403);
            echo 'Acesso não autorizado ao plano desta clínica.';
            return;
        } catch (\InvalidArgumentException $e) {
            http_response_code(404);
            echo htmlspecialchars($e->getMessage());
            return;
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
        }
        require __DIR__ . '/../../../views/meal_plans/shopping_list.php';
    }

    /**
     * Prontuário para impressão (A4 / salvar em PDF pelo navegador).
     * Rota sob /pacientes: perfis não clínicos (ex.: secretaria) já são barrados pela autorização web.
     */
    public function printMedicalRecord(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $userId = (int)TenantContext::getUserId();
        try {
            $doc = (new \ClyoNutri\Domain\Clinical\MedicalRecordPrintService())->build(
                $patientId,
                $userId,
                isset($_GET['de']) ? (string)$_GET['de'] : null,
                isset($_GET['ate']) ? (string)$_GET['ate'] : null,
                ($_GET['historico'] ?? '') === '1'
            );
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="UTF-8"><title>Prontuário</title><body class="u-maxw-form u-my-16 u-mx-auto u-py-0 u-px-4">'
                . '<p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="/pacientes/' . $patientId . '/prontuario/imprimir">Voltar</a></p></body></html>';
            return;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(404);
            echo 'Paciente não encontrado.';
            return;
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
            header('X-Frame-Options: DENY');
        }
        \ClyoNutri\Core\Audit\PatientRecordAccessAudit::record($patientId, 'medical_record_print'); // CR-NI2-006
        require __DIR__ . '/../../../views/patients/medical_record_print.php';
    }

    public function exportMedicalRecord(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        // NI2-220 (I6): a exportação registra quem de fato exportou. Sem cadastro profissional com CRN ativo
        // nesta clínica não há exportação (antes caía no primeiro profissional da clínica ou no id 1).
        $profId = $this->clinicalAuthorProfessionalId();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($profId === null) {
            http_response_code(403);
            echo json_encode(['error' => self::SEM_CRN_MSG], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $clinicalService = new ClinicalRecordService();

        try {
            $export = $clinicalService->exportRecordTimelineSafe($patientId, $profId);
            \ClyoNutri\Core\Audit\PatientRecordAccessAudit::record($patientId, 'medical_record_export'); // CR-NI2-006
            header('Content-Disposition: attachment; filename="prontuario_paciente_' . $patientId . '_' . date('Ymd_His') . '.json"');
            echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(404);
            echo json_encode(['error' => 'Paciente não encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            // NI2-220 (I8): detalhe técnico vai para o log; a pessoa recebe mensagem e código de referência.
            $ref = $this->logClinicalFailure('prontuario.exportar', $e);
            http_response_code($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Exportação bloqueada') ? 409 : 500);
            echo json_encode(['error' => $e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Exportação bloqueada')
                ? 'Exportação bloqueada: a verificação do histórico encontrou divergência. Fale com o suporte informando o código.'
                : 'Não foi possível exportar o prontuário agora. Tente novamente.', 'correlation_id' => $ref], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Tela dedicada de Anamnese Nutricional Estruturada e Histórico Versionado (NI2-61, NI2-62, NI2-64).
     */
    public function patientAnamnesis(array $params): void
    {
        if ($this->anamnesisProfessional() === null) {
            return;
        }
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $patient = $repo->findById($id);

        if (!$patient) {
            $this->anamnesisError(404, 'Paciente ou documento não encontrado.');
            return;
        }

        $title = 'Anamnese · ' . $patient['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();

        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();
        $selectedVersion = null;
        $revisionFrom = null;
        $compareData = null;
        try {
            // Canonical public contract is ?version=N; keep old bookmarks functional.
            foreach (['version', 'view_version', 'revision_from', 'v1', 'v2'] as $key) {
                if (isset($_GET[$key]) && (!is_string($_GET[$key]) || !preg_match('/^[1-9][0-9]{0,8}$/D', $_GET[$key]))) {
                    $this->anamnesisError(400, 'Versão inválida. Informe um número inteiro positivo.');
                    return;
                }
            }
            if (isset($_GET['view_version'])) {
                if (isset($_GET['version']) && $_GET['version'] !== $_GET['view_version']) {
                    $this->anamnesisError(400, 'Seleção de versão ambígua.');
                    return;
                }
                $query = $_GET;
                $query['version'] = $query['view_version'];
                unset($query['view_version']);
                header('Location: /pacientes/' . $id . '/anamnese?' . http_build_query($query));
                return;
            }
            if (isset($_GET['version'], $_GET['revision_from'])) {
                $this->anamnesisError(400, 'Selecione leitura histórica ou nova revisão.');
                return;
            }
            $latest = $anamnesisService->getLatestAnamnesis($id);
            $history = $anamnesisService->getAnamnesisHistory($id);
            if (isset($_GET['version'])) {
                $selectedVersion = $anamnesisService->getAnamnesisVersion($id, (int)$_GET['version']);
                if (!$selectedVersion) {
                    $this->anamnesisError(404, 'Versão de anamnese não encontrada.');
                    return;
                }
            }
            if (isset($_GET['revision_from'])) {
                $revisionFrom = $anamnesisService->getAnamnesisVersion($id, (int)$_GET['revision_from']);
                if (!$revisionFrom) {
                    $this->anamnesisError(404, 'Versão de origem da revisão não encontrada.');
                    return;
                }
                $latest = $revisionFrom; // Prefill a separate append-only revision, never edit the record.
            }
            if (isset($_GET['v1']) || isset($_GET['v2'])) {
                if (!isset($_GET['v1'], $_GET['v2'])) {
                    $this->anamnesisError(400, 'Informe as duas versões para comparação.');
                    return;
                }
                try {
                    $compareData = $anamnesisService->compareVersions($id, (int)$_GET['v1'], (int)$_GET['v2']);
                } catch (\InvalidArgumentException $e) {
                    $this->anamnesisError(404, 'Uma das versões de comparação não foi encontrada.');
                    return;
                }
            }
        } catch (\PDOException $e) {
            $this->anamnesisError(503, 'Não foi possível carregar a anamnese. Tente novamente.');
            return;
        }

        ob_start();
        // v54.25 (NI2-220 I9/B2/B3): tela no design system, em views/anamnesis/form_page.php.
        require __DIR__ . '/../../../views/anamnesis/form_page.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private function anamnesisProfessional(): ?int
    {
        // NI2-220 (I4): mesma regra de autoria do prontuário, da antropometria e da consulta (ClinicalAuthor).
        $id = $this->clinicalAuthorProfessionalId();
        if ($id === null) {
            $this->anamnesisError(403, 'Acesso clínico não autorizado. A anamnese é registrada e consultada por nutricionista com CRN ativo nesta clínica.');
            return null;
        }
        return $id;
    }

    private function anamnesisError(int $status, string $message): void
    {
        http_response_code($status);
        $title = 'Anamnese indisponível';
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        ?>
        <div class="op-message op-message--error card" role="alert">
          <h1>Anamnese indisponível</h1>
          <p><?= htmlspecialchars($message) ?></p>
          <a href="/pacientes" class="btn btn-secondary">Voltar aos pacientes</a>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function savePatientAnamnesis(array $params): void
    {
        $profId = $this->anamnesisProfessional();
        if ($profId === null) {
            return;
        }
        $token = $_POST['_csrf_token'] ?? '';
        $patientId = (int)($params['id'] ?? 0);

        if (!Csrf::validate($token)) {
            header("Location: /pacientes/{$patientId}/anamnese?error=csrf");
            exit;
        }

        if (isset($_GET['version']) || isset($_GET['view_version']) || isset($_POST['version']) || isset($_POST['view_version'])) {
            $this->anamnesisError(405, 'A visualização histórica é somente leitura. Use a ação de criar nova revisão.');
            return;
        }

        if (!(new PatientRepository())->findById($patientId)) {
            // NI2-24 (M2): mesma resposta para paciente de outra clínica e inexistente (sem oráculo de existência);
            // a tentativa cruzada fica na auditoria (CrossTenantAccessAuditor).
            $this->anamnesisError(404, 'Paciente não encontrado.');
            return;
        }
        \ClyoNutri\Domain\Clinical\FormRecovery::remember($patientId,'anamnese',$_POST);
        $isDraft = ($_POST['save_mode'] ?? '') === 'draft';
        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();

        // NI2-220 (I2): a conferência de concorrência é obrigatória no servidor. Sem a revisão lida na abertura do
        // formulário e a chave da gravação, nada é gravado (antes, omitir os campos pulava a checagem).
        $wfRevision = $_POST['_workflow_revision'] ?? null;
        $wfKey = $_POST['_final_request_key'] ?? null;
        if (!is_string($wfRevision) || !is_string($wfKey) || $wfRevision === '' || $wfKey === '') {
            header("Location: /pacientes/{$patientId}/anamnese?error=conflict&msg=" . urlencode('Não foi possível confirmar qual versão da anamnese estava aberta. Nada foi gravado; recarregue a página e tente de novo.'));
            exit;
        }
        unset($_SESSION['anamnesis_field_errors'][$patientId]);

        try {
            (new \ClyoNutri\Domain\Clinical\WorkflowWrites(Connection::getInstance()))->write('anamnesis',$patientId,0,$wfKey,$wfRevision,$_POST,function()use($anamnesisService,$patientId,$profId,$isDraft){$saved=$anamnesisService->saveAnamnesis($patientId,$profId,$_POST,$isDraft);return ['resource_id'=>$patientId,'record_id'=>(int)$saved['id']];});
            \ClyoNutri\Domain\Clinical\FormRecovery::clear($patientId,'anamnese');
            $successParam = $isDraft ? 'rascunho_salvo' : 'anamnese_concluida';
            header("Location: /pacientes/{$patientId}/anamnese?success={$successParam}");
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            $this->anamnesisError(403, 'Acesso ao documento não autorizado.');
            return;
        } catch (\ClyoNutri\Domain\Clinical\WorkflowConflict $e) {
            header('Location: /pacientes/'.$patientId.'/anamnese?error=conflict&msg='.urlencode($e->getMessage())); return;
        } catch (\PDOException $e) {
            $this->anamnesisError(503, 'Não foi possível salvar a nova revisão. O preenchimento foi mantido nesta sessão por uma hora. Volte à coleta atual e tente novamente.');
            return;
        } catch (\ClyoNutri\Domain\Clinical\ClinicalInputException $e) {
            // NI2-220 (B3): erros por campo voltam para a tela; nada foi gravado.
            $_SESSION['anamnesis_field_errors'][$patientId] = $e->fieldErrors();
            header("Location: /pacientes/{$patientId}/anamnese?error=dados_invalidos&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\InvalidArgumentException $e) {
            header("Location: /pacientes/{$patientId}/anamnese?error=dados_invalidos&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            $ref = $this->logClinicalFailure('anamnese.salvar', $e);
            header("Location: /pacientes/{$patientId}/anamnese?error=falha&ref={$ref}&msg=" . urlencode('Não foi possível salvar. O preenchimento foi mantido nesta sessão por uma hora. Tente novamente.'));
            exit;
        }
    }

    public function prescriptionSummary(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        // NI2-220 (I5): resumo clínico só para quem pode atuar clinicamente nesta clínica (mesma regra da anamnese).
        if ($this->clinicalAuthorProfessionalId() === null) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso clínico não autorizado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();

        try {
            $summary = $anamnesisService->getClinicalSummaryForPrescription($patientId);
            echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(404);
            echo json_encode(['error' => 'Paciente não encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            $ref = $this->logClinicalFailure('anamnese.resumo', $e);
            http_response_code(500);
            echo json_encode(['error' => 'Não foi possível montar o resumo clínico agora.', 'correlation_id' => $ref], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * NI2-233: profissional (CRN) do PRÓPRIO usuário logado, membro ativo da clínica atual. Usado nas gravações
     * clínicas (prontuário, retificação, antropometria): sem cadastro profissional não há autoria possível, e o
     * registro NÃO pode cair no CRN de outra pessoa (o fallback de getActiveProfessionalId atribuía a autoria ao
     * primeiro profissional da clínica ou ao id 1).
     */
    private function clinicalAuthorProfessionalId(): ?int
    {
        // NI2-220 (I4): papel na clínica + papel global + CRN preenchido + clínica ativa (ClinicalAuthor).
        return \ClyoNutri\Domain\Clinical\ClinicalAuthor::resolve(Connection::getInstance(), (int)TenantContext::getClinicId(), (int)TenantContext::getUserId());
    }

    private const SEM_CRN_MSG = \ClyoNutri\Domain\Clinical\ClinicalAuthor::SEM_CRN_MSG;

    /** NI2-707: próximo horário livre (jornada, feriados, ocupação e intervalos) nos próximos 60 dias, formato Y-m-dTH:i. */
    private function nextValidSlot(int $duration): ?string
    {
        try {
            $svc = new AppointmentService();
            $prof = $this->getActiveProfessionalId();
            $tz = new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
            $now = new \DateTimeImmutable('now', $tz);
            for ($i = 0; $i < 60; $i++) {
                $d = $now->modify("+{$i} days")->format('Y-m-d');
                $prev = $svc->availabilityPreview($prof, $d, max(15, min(240, $duration ?: 60)));
                foreach ($prev['times'] ?? [] as $t) {
                    if ($i === 0 && $t <= $now->format('H:i')) continue;
                    return $d . 'T' . $t;
                }
            }
        } catch (\Throwable $e) {
            error_log('[agenda] próximo horário indisponível: ' . get_class($e));
        }
        return null;
    }

    private function getActiveProfessionalId(): int
    {
        $db = Connection::getInstance();
        $userId = TenantContext::getUserId();
        $stmt = $db->prepare("SELECT id FROM professionals WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $profId = $stmt->fetchColumn();
        if ($profId) {
            return (int)$profId;
        }

        $stmtFirst = $db->prepare("
            SELECT p.id FROM professionals p
            JOIN clinic_members cm ON cm.user_id = p.user_id
            WHERE cm.clinic_id = :cid LIMIT 1
        ");
        $stmtFirst->execute(['cid' => TenantContext::getClinicId()]);
        $first = $stmtFirst->fetchColumn();
        return $first ? (int)$first : 1;
    }

    public function saveAppointment(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /agenda?error=csrf');
            exit;
        }

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $scheduledAt = is_string($_POST['scheduled_at'] ?? null) ? trim($_POST['scheduled_at']) : '';
        $duration = (int)($_POST['duration_minutes'] ?? 60);
        $type = trim((string)($_POST['type'] ?? 'first_visit'));
        $notes = !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null;
        $allowConflict = !empty($_POST['allow_conflict']);

        if ($patientId <= 0) {
            header('Location: /agenda?error=campos_obrigatorios');
            exit;
        }

        $service = new AppointmentService();
        $profId = $this->getActiveProfessionalId();

        $sameDayBlocked = false;
        try {
            $scheduledAt = \ClyoNutri\Domain\Appointment\AppointmentScheduleInput::fromArray($_POST);
            $_POST['scheduled_at'] = $scheduledAt;
            $appointmentDate = date('Y-m-d', strtotime($scheduledAt));

            // NI2-591: Alerta quando o paciente já tem consulta no mesmo dia
            // O encaixe (sobreposição de horário) não confirma "mesmo dia": são decisões diferentes.
            $allowSameDay = !empty($_POST['allow_same_day']);
            if (!$allowSameDay) {
                $sameDayApp = $service->findPatientAppointmentOnDate(
                    \ClyoNutri\Core\Auth\TenantContext::getClinicId(),
                    $patientId,
                    $appointmentDate
                );
                if ($sameDayApp !== null) {
                    $existingTime = date('H:i', strtotime($sameDayApp['scheduled_at']));
                    $sameDayBlocked = true;
                    throw new AppointmentConflictException("Este paciente já tem consulta neste dia às {$existingTime}. Para manter as duas, marque \"Confirmar mesmo com outra consulta do paciente no mesmo dia\".");
                }
            }
            $service->schedule(
                $patientId,
                $profId,
                $scheduledAt,
                $type,
                $duration,
                $notes,
                $allowConflict
            );
            $targetDate = date('Y-m-d', strtotime($scheduledAt));
            // NI2-707: agendado a partir do perfil do paciente, volta para o perfil.
            if (($_POST['return_to'] ?? '') === 'patient' && $patientId > 0) {
                header("Location: /pacientes/{$patientId}?success=agendado");
                exit;
            }
            header("Location: /agenda?date={$targetDate}&success=agendado");
            exit;
        } catch (AppointmentConflictException $e) {
            // UX-05 (v14): conflito mantém o formulário aberto, preenchido e com o horário destacado.
            $targetDate = \ClyoNutri\Domain\Appointment\AppointmentScheduleInput::recoveryDate($_POST);
            $this->rememberAgendaForm('new', $e->getMessage(), empty($sameDayBlocked));
            if (!empty($sameDayBlocked)) $_SESSION['agenda_form_recovery']['same_day'] = true;
            header("Location: /agenda?date={$targetDate}&novo=1&error=conflito#card-novo-agendamento");
            exit;
        } catch (\Throwable $e) {
            $targetDate = \ClyoNutri\Domain\Appointment\AppointmentScheduleInput::recoveryDate($_POST);
            $this->rememberAgendaForm('new', $this->userFacingError('agenda: novo agendamento', $e), false);
            header("Location: /agenda?date={$targetDate}&novo=1&error=falha#card-novo-agendamento");
            exit;
        }
    }

    /** UX-04/05 (v14): guarda o preenchimento do agendamento na sessão (uma leitura, até 1 h). */
    private function rememberAgendaForm(string $scope, string $message, bool $conflict): void
    {
        $values = [];
        foreach (['patient_id', 'scheduled_at', 'scheduled_date', 'scheduled_time', 'duration_minutes', 'type', 'notes', 'reschedule_reason', 'allow_same_day', 'return_to'] as $k) {
            if (isset($_POST[$k]) && is_scalar($_POST[$k])) {
                $values[$k] = mb_substr((string)$_POST[$k], 0, 2000);
            }
        }
        $_SESSION['agenda_form_recovery'] = ['scope' => $scope, 'values' => $values, 'error' => $message, 'conflict' => $conflict, 'at' => time()];
    }

    /** Lê (e consome) o preenchimento guardado para este escopo. */
    private function takeAgendaForm(string $scope): ?array
    {
        $saved = $_SESSION['agenda_form_recovery'] ?? null;
        if (!is_array($saved) || ($saved['scope'] ?? '') !== $scope || time() - (int)($saved['at'] ?? 0) > 3600) {
            return null;
        }
        unset($_SESSION['agenda_form_recovery']);
        return $saved;
    }

    /** UX-04 (v14): GET /agenda/{id}/reagendar — formulário pré-preenchido; envia para /agenda/{id}/editar. */
    public function rescheduleAppointmentForm(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $service = new AppointmentService();
        $app = $service->getAppointment($id);
        if (!$app) {
            header('Location: /agenda?error=falha&msg=' . urlencode('Consulta não encontrada.'));
            exit;
        }
        $reschedulable = in_array($app['status'], [AppointmentService::STATUS_SCHEDULED, AppointmentService::STATUS_CONFIRMED], true);
        $recovered = $this->takeAgendaForm('reschedule:' . $id);
        $v = $recovered['values'] ?? [];
        $scheduledValue = $v['scheduled_at'] ?? date('Y-m-d\TH:i', strtotime((string)$app['scheduled_at']));
        $durationValue = (int)($v['duration_minutes'] ?? $app['duration_minutes'] ?? 60);
        $reasonValue = (string)($v['reschedule_reason'] ?? '');
        $from = (string)($_GET['de'] ?? '');
        $backUrl = $from === 'paciente' ? '/pacientes/' . (int)$app['patient_id'] : '/agenda?date=' . substr((string)$app['scheduled_at'], 0, 10);

        $title = 'Reagendar consulta · ' . ($app['patient_name'] ?? 'Paciente');
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();
        ob_start();
        ?>
        <div class="u-text-compact u-mb-1"><a href="<?= htmlspecialchars($backUrl) ?>" class="u-color-botanical-copper-600">← Voltar</a></div>
        <h1>Reagendar consulta</h1>
        <p class="u-color-neutral-gray-500"><?= htmlspecialchars($app['patient_name']) ?> • <?= htmlspecialchars(LabelMap::appointmentType($app['type'] ?? null)) ?> • horário atual: <strong data-testid="current-slot"><?= date('d/m/Y H:i', strtotime((string)$app['scheduled_at'])) ?></strong></p>
        <?php if (!$reschedulable): ?>
          <div class="op-message op-message--error card u-accent u-accent-semantic-error" role="alert">Consultas com status <strong><?= htmlspecialchars(LabelMap::appointmentStatus($app['status'])) ?></strong> não podem ser reagendadas. Para um novo atendimento, use <a href="/agenda?novo=1&amp;paciente=<?= (int)$app['patient_id'] ?>">Nova consulta</a>.</div>
        <?php else: ?>
        <div class="card u-accent-top u-accent-brand-copper-500 u-maxw-prose" id="card-reagendar">
          <?php if ($recovered): ?>
            <div class="op-message op-message--error u-mb-3" role="alert" data-testid="agenda-form-error">
              <strong><?= !empty($recovered['conflict']) ? 'Horário indisponível.' : 'Não foi possível reagendar.' ?></strong> <?= htmlspecialchars((string)$recovered['error']) ?> Seus dados foram mantidos; escolha outro horário<?= !empty($recovered['conflict']) ? ' ou marque o encaixe' : '' ?>.
            </div>
          <?php endif; ?>
          <form action="/agenda/<?= (int)$app['id'] ?>/editar" method="POST" <?= $recovered ? 'data-start-dirty' : '' ?>>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="origin" value="reagendar">
            <input type="hidden" name="de" value="<?= htmlspecialchars($from === 'paciente' ? 'paciente' : 'agenda') ?>">
            <div class="u-grid u-cols-auto-md u-gap-4 u-mb-4">
              <?php $scheduleFieldPrefix='reschedule-appointment';$scheduleFieldValue=(string)$scheduledValue;$scheduleFieldValues=$v;$scheduleFieldConflict=!empty($recovered['conflict']);require __DIR__.'/../../../views/agenda/_schedule_fields.php'; ?>
              <div>
                <label for="rs-duration" class="u-block u-font-semibold u-text-compact u-mb-1">Duração</label>
                <select class="form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input" id="rs-duration" name="duration_minutes">
                  <?php $choices = array_unique([30, 45, 60, 90, 120, $durationValue]); sort($choices); foreach ($choices as $m): ?>
                    <option value="<?= (int)$m ?>" <?= (int)$m === $durationValue ? 'selected' : '' ?>><?= (int)$m ?> minutos</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="u-mb-4">
              <label for="rs-reason" class="u-block u-font-semibold u-text-compact u-mb-1">Motivo do reagendamento (opcional, fica na trilha da consulta)</label>
              <input id="rs-reason" type="text" name="reschedule_reason" maxlength="300" value="<?= htmlspecialchars($reasonValue) ?>" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
            </div>
            <p class="u-text-compact u-color-neutral-gray-500">A consulta continua a mesma (mesmo paciente, tipo, histórico e vínculos). Se for teleconsulta, o convite anterior deixa de valer e um novo convite deve ser enviado ao paciente.</p>
            <div class="u-flex u-justify-between u-items-center u-border-t u-pt-3">
              <label class="u-flex u-items-center u-gap-2 u-text-compact u-color-neutral-gray-500"><input type="checkbox" name="allow_conflict" value="1"> Permitir sobreposição de horário (encaixe)</label>
              <div class="u-flex u-gap-2">
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" data-testid="reschedule-submit">Confirmar reagendamento</button>
              </div>
            </div>
          </form>
          <p class="u-text-compact u-mt-2"><a href="/ajuda/artigo/como-reagendar-uma-consulta">Como reagendar uma consulta</a></p>
        </div>
        <?php endif; ?>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function editAppointment(array $params): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /agenda?error=csrf');
            exit;
        }

        $id = (int)($params['id'] ?? $_POST['appointment_id'] ?? 0);
        $allowConflict = !empty($_POST['allow_conflict']);

        $service = new AppointmentService();
        $isReschedule = ($_POST['origin'] ?? '') === 'reagendar';
        $back = "/agenda/{$id}/reagendar" . (($_POST['de'] ?? '') === 'paciente' ? '?de=paciente' : '');
        try {
            if ($isReschedule || isset($_POST['scheduled_date']) || isset($_POST['scheduled_time'])) $_POST['scheduled_at'] = \ClyoNutri\Domain\Appointment\AppointmentScheduleInput::fromArray($_POST);
            $data = $_POST;
            if ($isReschedule) {
                // UX-04 (v14): reagendar só altera horário/duração (+ motivo na trilha), apenas de consulta agendada/confirmada e para o futuro.
                $current = $service->getAppointment($id);
                if (!$current || !in_array($current['status'], [AppointmentService::STATUS_SCHEDULED, AppointmentService::STATUS_CONFIRMED], true)) {
                    throw new \InvalidArgumentException('Só é possível reagendar consultas agendadas ou confirmadas.');
                }
                $newTs = strtotime(str_replace('T', ' ', (string)($_POST['scheduled_at'] ?? '')));
                if ($newTs === false) {
                    throw new \InvalidArgumentException('Informe o novo dia e horário.');
                }
                if ($newTs < time()) {
                    throw new \InvalidArgumentException('O novo horário já passou. Escolha um horário futuro.');
                }
                $data = array_intersect_key($_POST, array_flip(['scheduled_at', 'duration_minutes', 'reschedule_reason']));
            }
            $updated = $service->edit($id, $data, $allowConflict);
            $targetDate = date('Y-m-d', strtotime($updated['scheduled_at']));
            if ($isReschedule && ($_POST['de'] ?? '') === 'paciente') {
                header('Location: /pacientes/' . (int)$updated['patient_id'] . '?success=reagendado');
                exit;
            }
            header("Location: /agenda?date={$targetDate}&success=" . ($isReschedule ? 'reagendado' : 'editado'));
            exit;
        } catch (AppointmentConflictException $e) {
            if ($isReschedule) {
                $this->rememberAgendaForm('reschedule:' . $id, $e->getMessage(), true);
                header("Location: {$back}");
                exit;
            }
            header("Location: /agenda?error=conflito&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            if ($isReschedule) {
                $this->rememberAgendaForm('reschedule:' . $id, $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Tente novamente.', false);
                header("Location: {$back}");
                exit;
            }
            header("Location: /agenda?error=falha&msg=" . urlencode($this->userFacingError('agenda', $e)));
            exit;
        }
    }

    public function updateAppointmentStatus(array $params): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /agenda?error=csrf');
            exit;
        }

        $id = (int)($params['id'] ?? $_POST['appointment_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $notes = !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null;

        $service = new AppointmentService();
        try {
            // UX-07 (v14): início antecipado (> 15 min antes do horário marcado) exige confirmação explícita.
            if ($newStatus === AppointmentService::STATUS_IN_PROGRESS) {
                $current = $service->getAppointment($id);
                if ($current && AppointmentService::isEarlyStart((string)$current['scheduled_at'])) {
                    if (empty($_POST['confirm_early'])) {
                        header("Location: /consulta/{$id}?inicio_antecipado=1");
                        exit;
                    }
                    $notes = trim(($notes ?? '') . ' Início antecipado confirmado (agendada para ' . date('d/m/Y H:i', strtotime((string)$current['scheduled_at'])) . ').');
                }
            }
            $service->updateStatus($id, $newStatus, $notes);

            if ($newStatus === AppointmentService::STATUS_IN_PROGRESS) {
                header("Location: /consulta/{$id}");
                exit;
            }

            header("Location: /agenda?status_updated=1");
            exit;
        } catch (InvalidAppointmentTransitionException $e) {
            header("Location: /agenda?error=transicao_invalida&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            header("Location: /agenda?error=falha&msg=" . urlencode($this->userFacingError('agenda', $e)));
            exit;
        }
    }

    /** NI2-705: POST /pacientes/{id}/iniciar-agora — atendimento imediato (sem agendamento prévio). */
    public function startCareNow(array $params): void
    {
        $pid = (int)($params['id'] ?? 0);
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            header("Location: /pacientes/{$pid}?error=csrf");
            exit;
        }
        $clinicId = (int)TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $db = Connection::getInstance();
        $st = $db->prepare('SELECT id FROM professionals WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $profId = (int)($st->fetchColumn() ?: 0);
        if ($profId <= 0) {
            header("Location: /pacientes/{$pid}?error=sem_profissional");
            exit;
        }
        try {
            $r = (new AppointmentService())->startNow($pid, $profId, $userId);
        } catch (\Throwable $e) {
            $this->logClinicalFailure('consulta.iniciar_agora', $e);
            header("Location: /pacientes/{$pid}?error=iniciar_agora");
            exit;
        }
        header('Location: /consulta/' . (int)$r['id']);
        exit;
    }

    /** NI2-712: GET /pacientes/{id}/preparar — painel Preparar consulta (≠ anamnese). */
    public function prepareConsultation(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $profile = $repo->getProfile360($id);
        if (!$profile) {
            header('Location: /pacientes');
            exit;
        }
        $p = $profile['patient'];
        $title = 'Preparar consulta · ' . $p['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();
        $clinicId = (int)TenantContext::getClinicId();
        $db = Connection::getInstance();
        $careStep = (new AppointmentService())->careStepForPatient($id);
        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();
        $latestAnamnesis = $anamnesisService->getEffectiveAnamnesis($id);
        $clinicalSummary = $anamnesisService->getClinicalSummaryForPrescription($id);
        $activePlan = null;
        if (!empty($profile['meal_plans'])) {
            foreach ($profile['meal_plans'] as $mp) {
                if (($mp['status'] ?? '') === 'active') { $activePlan = $mp; break; }
            }
            if (!$activePlan) $activePlan = $profile['meal_plans'][0];
        }
        $assessments = (new AnthropometryService())->getEvolution($id)['assessments'] ?? [];
        $pendingExamCount = 0;
        try {
            $pe = $db->prepare("SELECT COUNT(*) FROM exam_requests WHERE clinic_id = ? AND patient_id = ? AND status IN ('draft','review')");
            $pe->execute([$clinicId, $id]);
            $pendingExamCount = (int)$pe->fetchColumn();
        } catch (\Throwable $e) { $pendingExamCount = 0; }
        $lastCompleted = null;
        try {
            $lc = $db->prepare("SELECT id, scheduled_at, type, status FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status = 'completed' ORDER BY COALESCE(completed_at, scheduled_at) DESC, id DESC LIMIT 1");
            $lc->execute([$clinicId, $id]);
            $lastCompleted = $lc->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { $lastCompleted = null; }
        ob_start();
        echo \ClyoNutri\Application\View\PatientHeader::render($db, $id, 'resumo', $p, $careStep, $clinicalSummary, $activePlan, true);
        require __DIR__ . '/../../../views/patients/prepare.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /** NI2-711: GET /pacientes/{id}/plano — aba Plano com rota própria. */
    public function patientPlansTab(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $profile = $repo->getProfile360($id);
        if (!$profile) {
            header('Location: /pacientes');
            exit;
        }
        $p = $profile['patient'];
        $title = 'Plano · ' . $p['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();
        $db = Connection::getInstance();
        $careStep = (new AppointmentService())->careStepForPatient($id);
        $clinicalSummary = (new \ClyoNutri\Domain\Anamnesis\AnamnesisService())->getClinicalSummaryForPrescription($id);
        $activePlan = null;
        if (!empty($profile['meal_plans'])) {
            foreach ($profile['meal_plans'] as $mp) {
                if (($mp['status'] ?? '') === 'active') { $activePlan = $mp; break; }
            }
            if (!$activePlan) $activePlan = $profile['meal_plans'][0];
        }
        ob_start();
        echo \ClyoNutri\Application\View\PatientHeader::render($db, $id, 'plano', $p, $careStep, $clinicalSummary, $activePlan, true);
        require __DIR__ . '/../../../views/patients/plans.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /** NI2-711: GET /pacientes/{id}/documentos — aba Documentos com rota própria. */
    public function patientDocumentsTab(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $repo = new PatientRepository();
        $profile = $repo->getProfile360($id);
        if (!$profile) {
            header('Location: /pacientes');
            exit;
        }
        $p = $profile['patient'];
        $title = 'Documentos · ' . $p['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();
        $db = Connection::getInstance();
        $careStep = (new AppointmentService())->careStepForPatient($id);
        $clinicalSummary = (new \ClyoNutri\Domain\Anamnesis\AnamnesisService())->getClinicalSummaryForPrescription($id);
        $activePlan = null;
        if (!empty($profile['meal_plans'])) {
            foreach ($profile['meal_plans'] as $mp) {
                if (($mp['status'] ?? '') === 'active') { $activePlan = $mp; break; }
            }
            if (!$activePlan) $activePlan = $profile['meal_plans'][0];
        }
        ob_start();
        echo \ClyoNutri\Application\View\PatientHeader::render($db, $id, 'documentos', $p, $careStep, $clinicalSummary, $activePlan, true);
        require __DIR__ . '/../../../views/patients/documents.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /** NI2-706: etapas do atendimento que ainda pedem revisão (Revisão pendente / Em andamento). */
    private function pendingReviewSteps(int $clinicId, int $patientId, int $appointmentId): array
    {
        try {
            $snap = (new \ClyoNutri\Domain\Appointment\CareProgress(Connection::getInstance()))->snapshot($clinicId, $patientId, $appointmentId);
        } catch (\Throwable $e) {
            error_log('[consulta] etapas indisponíveis para o gate de conclusão: ' . get_class($e));
            return [];
        }
        $out = [];
        foreach ($snap['steps'] ?? [] as $step) {
            if (in_array($step['state'] ?? '', ['review', 'in_progress'], true)) {
                $out[] = ['key' => (string)$step['key'], 'label' => (string)$step['label'], 'state' => (string)$step['state'],
                    'state_label' => \ClyoNutri\Domain\Appointment\CareWorkflow::LABELS[$step['state']] ?? '', 'url' => (string)($step['url'] ?? '')];
            }
        }
        return $out;
    }

    public function consultationRoom(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $service = new AppointmentService();
        $app = $service->getAppointment($id);

        if (!$app) {
            header('Location: /agenda');
            exit;
        }

        // UX-07 (v14): abrir a tela NÃO inicia mais a consulta. Agendada/confirmada mostra um painel de
        // início explícito (POST /agenda/{id}/status); início antecipado pede confirmação.
        $timeline = AppointmentService::executionTimeline($app);
        $canStart = in_array($app['status'], [AppointmentService::STATUS_SCHEDULED, AppointmentService::STATUS_CONFIRMED], true);
        $isEarly = $canStart && AppointmentService::isEarlyStart((string)$app['scheduled_at']);
        $inProgress = $app['status'] === AppointmentService::STATUS_IN_PROGRESS;

        // UX-03 (v14/v16): rascunho por consulta + usuário, restaurado ao retomar ou após falha no POST de conclusão.
        $clinicId = (int)TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $draftScope = "{$clinicId}:{$userId}:{$id}";
        $draft = ['evolution_summary' => null, 'weight_kg' => null, 'waist_cm' => null, 'version' => 0, 'updated_at_utc' => null];
        if ($inProgress) {
            try {
                $draft = (new \ClyoNutri\Domain\Appointment\ConsultationDraftService())->get($id, $userId, $clinicId);
            } catch (\Throwable $e) {
                error_log('[consulta] rascunho indisponível: ' . get_class($e));
            }
        }
        $draftRecoveredFromPost = false;
        if (isset($_SESSION['consultation_form_recovery'][$id]) && is_array($_SESSION['consultation_form_recovery'][$id])) {
            $rec = $_SESSION['consultation_form_recovery'][$id];
            unset($_SESSION['consultation_form_recovery'][$id]);
            if (
                (int)($rec['clinic_id'] ?? 0) === $clinicId
                && (int)($rec['user_id'] ?? 0) === $userId
                && (time() - (int)($rec['saved_at'] ?? 0)) <= 900
            ) {
                foreach (['evolution_summary', 'weight_kg', 'waist_cm'] as $fKey) {
                    if (array_key_exists($fKey, $rec) && $rec[$fKey] !== null && $rec[$fKey] !== '') {
                        $draft[$fKey] = (string)$rec[$fKey];
                    }
                }
                $draftRecoveredFromPost = true;
            }
        }
        $draftSavedLocal = !empty($draft['updated_at_utc'])
            ? (new \DateTimeImmutable((string)$draft['updated_at_utc'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y \à\s H:i')
            : null;

        $pendingReview = $inProgress ? $this->pendingReviewSteps($clinicId, (int)$app['patient_id'], $id) : [];
        $repo = new PatientRepository();
        $profile = $repo->getProfile360((int)$app['patient_id']);
        $p = $profile['patient'] ?? [];

        $title = "Consulta #{$id} — " . ($app['patient_name'] ?? 'Paciente');
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();

        ob_start();
        // v54.25 (NI2-220 I9): marcação no arquivo de view, no design system.
        require __DIR__ . '/../../../views/consultation/room.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /** UX-03 (v14): GET JSON do rascunho da consulta. */
    public function consultationDraftGet(array $params): void
    {
        $this->consultationDraftJson(fn() => (new \ClyoNutri\Domain\Appointment\ConsultationDraftService())
            ->get((int)($params['id'] ?? 0), (int)TenantContext::getUserId(), (int)TenantContext::getClinicId()));
    }

    /** UX-03 (v14): POST JSON (X-CSRF-Token) — salvamento automático com versão. */
    public function consultationDraftSave(array $params): void
    {
        $this->consultationDraftJson(function () use ($params) {
            if (!Csrf::validate(isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : null)) {
                http_response_code(403);
                return ['error' => 'Sessão da página expirada. Recarregue a página (o texto digitado continua na tela).'];
            }
            $input = json_decode((string)file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];
            $result = (new \ClyoNutri\Domain\Appointment\ConsultationDraftService())->save(
                (int)($params['id'] ?? 0), (int)TenantContext::getUserId(), (int)TenantContext::getClinicId(), (int)($input['version'] ?? 0), $input
            );
            if ($result['status'] === 'conflict') {
                http_response_code(409);
            }
            return $result;
        });
    }

    private function consultationDraftJson(callable $work): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            echo json_encode($work(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['status' => 'invalid', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\PDOException $e) {
            $cid = bin2hex(random_bytes(8));
            $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
            error_log(sprintf(
                '[consulta] rascunho persistência indisponível correlation_id=%s clinic_id=%d user_id=%d sqlstate=%s',
                $cid,
                (int)TenantContext::getClinicId(),
                (int)TenantContext::getUserId(),
                $sqlState
            ));
            http_response_code(503);
            echo json_encode([
                'status' => 'error',
                'error' => 'Não foi possível salvar o rascunho agora (persistência temporariamente indisponível).',
                'correlation_id' => $cid,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $cid = bin2hex(random_bytes(8));
            error_log(sprintf(
                '[consulta] rascunho falhou correlation_id=%s clinic_id=%d user_id=%d exception=%s',
                $cid,
                (int)TenantContext::getClinicId(),
                (int)TenantContext::getUserId(),
                get_class($e)
            ));
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'error' => 'Não foi possível salvar o rascunho agora.',
                'correlation_id' => $cid,
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function completeConsultation(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $clinicId = (int)TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $summary = is_string($_POST['evolution_summary'] ?? null) ? trim($_POST['evolution_summary']) : '';
        $weightRaw = is_string($_POST['weight_kg'] ?? null) ? trim($_POST['weight_kg']) : '';
        $waistRaw = is_string($_POST['waist_cm'] ?? null) ? trim($_POST['waist_cm']) : '';

        if ($id > 0 && ($summary !== '' || $weightRaw !== '' || $waistRaw !== '')) {
            $_SESSION['consultation_form_recovery'][$id] = [
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'appointment_id' => $id,
                'evolution_summary' => $summary,
                'weight_kg' => $weightRaw,
                'waist_cm' => $waistRaw,
                'saved_at' => time(),
            ];
        }

        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header($id > 0 ? "Location: /consulta/{$id}?error=csrf" : 'Location: /agenda?error=csrf');
            exit;
        }

        $service = new AppointmentService();
        $app = $service->getAppointment($id);
        if (!$app) {
            unset($_SESSION['consultation_form_recovery'][$id]);
            header('Location: /agenda');
            exit;
        }

        // Garante que qualquer texto enviado no POST (mesmo antes do debounce de 1,5s do autosave) fique salvo no rascunho do servidor
        if (($app['status'] ?? '') === AppointmentService::STATUS_IN_PROGRESS && ($summary !== '' || $weightRaw !== '' || $waistRaw !== '')) {
            try {
                $draftSvc = new \ClyoNutri\Domain\Appointment\ConsultationDraftService();
                $curDraft = $draftSvc->get($id, $userId, $clinicId);
                $draftSvc->save($id, $userId, $clinicId, (int)($curDraft['version'] ?? 0), [
                    'evolution_summary' => $summary !== '' ? $summary : (string)($curDraft['evolution_summary'] ?? ''),
                    'weight_kg' => $weightRaw !== '' ? $weightRaw : (string)($curDraft['weight_kg'] ?? ''),
                    'waist_cm' => $waistRaw !== '' ? $waistRaw : (string)($curDraft['waist_cm'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                error_log('[consulta] backup pré-conclusão não persistido no banco: ' . get_class($e));
            }
        }

        if ($summary === '') {
            header("Location: /consulta/{$id}?error=resumo_obrigatorio");
            exit;
        }

        // NI2-220 (B1): autor é quem conclui, com CRN ativo nesta clínica; sem isso nada é gravado e o rascunho fica.
        $authorId = \ClyoNutri\Domain\Clinical\ClinicalAuthor::resolve(Connection::getInstance(), $clinicId, $userId);
        if ($authorId === null) {
            header("Location: /consulta/{$id}?error=sem_crn");
            exit;
        }

        // NI2-220 (B3): peso e cintura fora da faixa são recusados com erro no campo, antes do registro imutável.
        try {
            $details = AppointmentService::validatedConsultationDetails(['weight_kg' => $weightRaw, 'waist_cm' => $waistRaw]);
        } catch (\ClyoNutri\Domain\Clinical\ClinicalInputException $e) {
            $_SESSION['consultation_field_errors'][$id] = $e->fieldErrors();
            header("Location: /consulta/{$id}?error=medidas_invalidas");
            exit;
        }

        // NI2-706: etapas em "Revisão pendente" (ou em rascunho) exigem confirmação explícita, auditada.
        $pendingReview = $this->pendingReviewSteps($clinicId, (int)$app['patient_id'], $id);
        if ($pendingReview !== []) {
            $ackKeys = trim((string)($_POST['pending_review_keys'] ?? ''));
            $curKeys = implode(',', array_column($pendingReview, 'key'));
            if (($_POST['confirm_pending_review'] ?? '') !== '1' || $ackKeys !== $curKeys) {
                header("Location: /consulta/{$id}?error=revisao_pendente#cr-pending-review");
                exit;
            }
        }

        try {
            $result = $service->completeConsultation($id, $summary, $details, $userId, $authorId);
        } catch (\ClyoNutri\Domain\Clinical\ClinicalInputException $e) {
            $_SESSION['consultation_field_errors'][$id] = $e->fieldErrors();
            header("Location: /consulta/{$id}?error=medidas_invalidas");
            exit;
        } catch (\InvalidArgumentException | \DomainException $e) {
            // Mensagens de regra de negócio, escritas para a pessoa (ex.: consulta já encerrada).
            $_SESSION['consultation_error_msg'][$id] = $e->getMessage();
            header("Location: /consulta/{$id}?error=regra");
            exit;
        } catch (\Throwable $e) {
            $cid = $this->logClinicalFailure('consulta.concluir', $e);
            header("Location: /consulta/{$id}?error=falha&ref={$cid}");
            exit;
        }

        // Peso informado e estatura anterior conhecida: grava também a avaliação, com o MESMO autor da evolução.
        $measuresWarning = false;
        if (!empty($details['weight_kg'])) {
            try {
                $db = Connection::getInstance();
                $hStmt = $db->prepare('SELECT height_cm FROM anthropometric_assessments WHERE clinic_id = ? AND patient_id = ? AND height_cm > 0 ORDER BY assessed_at DESC, id DESC LIMIT 1');
                $hStmt->execute([$clinicId, (int)$app['patient_id']]);
                $lastHeight = $hStmt->fetchColumn();
                if ($lastHeight !== false && (float)$lastHeight > 0) {
                    (new AnthropometryService($db))->recordAssessment(
                        (int)$app['patient_id'],
                        $authorId,
                        [
                            'weight_kg' => (string)$details['weight_kg'],
                            'height_cm' => (string)$lastHeight,
                            'waist_cm' => isset($details['waist_cm']) ? (string)$details['waist_cm'] : '',
                            'notes' => "Registrado na conclusão da consulta #{$id}",
                        ],
                        $userId
                    );
                }
            } catch (\Throwable $e) {
                $this->logClinicalFailure('consulta.antropometria', $e);
                $measuresWarning = true;
            }
        }

        if ($pendingReview !== []) {
            try {
                (new \ClyoNutri\Core\Audit\AuditLogger())->log('CONSULTATION_CONCLUDED_WITH_PENDING_REVIEW', 'appointment', (string)$id, [
                    'patient_id' => (int)$app['patient_id'],
                    'pending' => array_map(static fn($s) => ['key' => $s['key'], 'label' => $s['label'], 'state' => $s['state']], $pendingReview),
                    'confirmed_by' => $userId,
                ]);
            } catch (\Throwable $e) {
                error_log('[consulta] auditoria de revisão pendente não gravada: ' . get_class($e));
            }
        }

        unset($_SESSION['consultation_form_recovery'][$id], $_SESSION['consultation_field_errors'][$id]);
        try {
            (new \ClyoNutri\Domain\Appointment\ConsultationDraftService())->discard($id, $userId, $clinicId);
        } catch (\Throwable $e) {
            error_log('[consulta] rascunho não apagado: ' . get_class($e));
        }

        header("Location: /pacientes/{$app['patient_id']}?success=consulta_concluida&hash=" . substr($result['record_hash'], 0, 8) . ($measuresWarning ? '&aviso=medidas' : ''));
        exit;
    }

    /**
     * NI2-220 (I8): registra a falha técnica no log (classe, arquivo e linha; nunca dado clínico) e devolve um
     * código curto para a pessoa citar ao suporte. A tela mostra só mensagem amigável.
     */
    /**
     * NI2-687: mensagem de erro que pode ir para a tela. Regras de negócio (InvalidArgument, Domain,
     * Runtime do domínio, tenant) já trazem texto pensado para a pessoa; falha técnica (PDO, Error do
     * PHP, JSON) nunca vai para a tela: vai para o log com código de correlação.
     */
    private function userFacingError(string $where, \Throwable $e): string
    {
        $technical = $e instanceof \PDOException || $e instanceof \Error || $e instanceof \ErrorException || $e instanceof \JsonException;
        if (!$technical && trim($e->getMessage()) !== '') {
            return $e->getMessage();
        }
        $cid = $this->logClinicalFailure($where, $e);
        return 'Não foi possível concluir agora. Tente de novo; se continuar, informe ao suporte o código ' . $cid . '.';
    }

    private function logClinicalFailure(string $where, \Throwable $e): string
    {
        $cid = bin2hex(random_bytes(6));
        error_log(sprintf(
            '[clinico] %s falhou correlation_id=%s clinic_id=%d user_id=%d exception=%s em %s:%d',
            $where,
            $cid,
            (int)TenantContext::getClinicId(),
            (int)TenantContext::getUserId(),
            get_class($e),
            basename($e->getFile()),
            $e->getLine()
        ));
        return $cid;
    }

    public function agenda(): void
    {
        // NI2-567 (v53): prévia de disponibilidade enquanto se preenche (restaurada na v54).
        // NI2-591: com patient_id, informa também se o paciente já tem consulta na data escolhida.
        if(isset($_GET['availability_preview'])){
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
            try{
                $date=$_GET['date']??'';$time=$_GET['time']??null;$duration=$_GET['duration']??'60';$pid=$_GET['patient_id']??'';
                if(!is_string($date)||($time!==null&&!is_string($time))||!is_string($duration)||!ctype_digit($duration)||!is_string($pid)||($pid!==''&&!ctype_digit($pid)))throw new \InvalidArgumentException('Informe data, horário e duração válidos.');
                $preview=(new AppointmentService())->availabilityPreview($this->getActiveProfessionalId(),$date,(int)$duration,$time);
                $preview['same_day_time']=null;
                if($pid!==''&&(int)$pid>0){
                    \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic((int)$pid,TenantContext::getClinicId(),Connection::getInstance());
                    $same=(new AppointmentService())->findPatientAppointmentOnDate(TenantContext::getClinicId(),(int)$pid,$date);
                    if($same)$preview['same_day_time']=date('H:i',strtotime((string)$same['scheduled_at']));
                }
                echo json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }catch(\InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
            catch(\Throwable $e){http_response_code(503);echo json_encode(['error'=>'Não foi possível conferir a disponibilidade. Os dados do formulário foram mantidos.']);}
            return;
        }
        $appointmentService = new AppointmentService();
        $patientRepo = new PatientRepository();
        $patients = $patientRepo->listAll();

        $currentDate = $_GET['date'] ?? $appointmentService->todayDate();
        try {
            if (!is_string($currentDate)) {
                throw new \InvalidArgumentException('Data inválida.');
            }
            (new \ClyoNutri\Core\Time\ClinicCalendar())->day($currentDate);
        } catch (\InvalidArgumentException $e) {
            $this->scheduleError(400, 'Data inválida. Selecione uma data existente no formato AAAA-MM-DD.', 'agenda');
            return;
        }
        $rawView = $_GET['view'] ?? 'day';
        $view = in_array($rawView, ['day', 'week', 'month'], true) ? $rawView : 'day';

        // NI2-413 (UX-01) / NI2-527: passo de navegação depende da visão (mês na mensal, 7 dias na semanal, 1 na diária)
        $todayDate = $appointmentService->todayDate();
        $nav = \ClyoNutri\Core\Time\AgendaNavigation::forView($view, $currentDate, $todayDate);
        $prevDate = $nav['prev'];
        $nextDate = $nav['next'];

        try {
            $monthData = null;
            if ($view === 'month') {
                $monthData = $appointmentService->listByMonth($currentDate);
                $weekData = null;
                $appointments = [];
            } elseif ($view === 'week') {
                $weekData = $appointmentService->listByWeek($currentDate);
                $appointments = [];
            } else {
                $appointments = $appointmentService->listByDay($currentDate);
                $weekData = null;
            }
        } catch (\PDOException $e) {
            $this->scheduleError(503, 'Não foi possível carregar a agenda. Tente novamente.', 'agenda');
            return;
        }

        $agendaRangeFrom = $view === 'month' ? $monthData['start_date'] : ($view === 'week' ? $weekData['start_date'] : $currentDate);
        $agendaRangeTo = $view === 'month' ? $monthData['end_date'] : ($view === 'week' ? $weekData['end_date'] : $currentDate);
        $agendaCalendarRules = (new \ClyoNutri\Domain\Booking\BookingCalendarService(Connection::getInstance()))->agendaRules(TenantContext::getClinicId(), substr($agendaRangeFrom, 0, 10), substr($agendaRangeTo, 0, 10));

        $title = 'Agenda';
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();

        $appointmentPreferences=null;
        try {
            $appointmentPreferences=(new AppointmentService())->practicePreferences($this->getActiveProfessionalId());
        } catch (\Throwable $e) {
            $appointmentPreferences = [];
        }
        $defaultDuration=(int)($appointmentPreferences['default_duration_minutes']??60);
        $dayAvailability=null;
        if($view==='day') {
            try {
                $dayAvailability=$appointmentService->availabilityPreview($this->getActiveProfessionalId(),$currentDate,$defaultDuration);
            } catch (\Throwable $e) {
                $dayAvailability=null;
            }
        }
        $agendaBookingSettings = null;
        try {
            $agendaBookingSettings = (new BookingService(Connection::getInstance()))->getSettings(TenantContext::getClinicId());
        } catch (\Throwable $e) {
            $agendaBookingSettings = [];
        }
        // NI2-612: um aviso só, o mais específico (horários a confirmar, sem dias ou sem horário livre em 14 dias).
        $agendaBookingNotice = null;
        $activeProfId = $this->getActiveProfessionalId();
        if (!empty($agendaBookingSettings['is_enabled']) && $activeProfId > 0) {
            try {
                $agendaBookingState = (new \ClyoNutri\Domain\Booking\BookingSetupState(Connection::getInstance()))->forProfessional(TenantContext::getClinicId(), $activeProfId);
                $agendaBookingNotice = \ClyoNutri\Domain\Booking\BookingSetupState::message($agendaBookingState);
            } catch (\Throwable $e) {
                $agendaBookingNotice = null;
            }
        }
        $agendaNoSlotsIn14Days = $agendaBookingNotice !== null;
        $agendaSubmissionId = bin2hex(random_bytes(16));
        $_SESSION['patient_submissions'][$agendaSubmissionId] = 'pending';
        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['error']) && !(($_GET['novo'] ?? '') === '1' && empty($_GET['msg']))): ?>
          <div role="alert" class="op-message op-message--error u-mb-4">
            <strong>Erro:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na operação.' ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['success'])): ?>
          <div role="status" class="op-message op-message--success u-mb-4">
            <?= $_GET['success'] === 'agendado' ? 'Consulta agendada.' : ($_GET['success'] === 'reagendado' ? 'Consulta reagendada. O horário anterior e o novo ficam registrados na trilha da consulta.' : 'Consulta atualizada.') ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['status_updated'])): ?>
          <div class="op-message op-message--success u-mb-4">
            Status da consulta atualizado. A mudança fica registrada no histórico da consulta.
          </div>
        <?php endif; ?>

        <?php $pendingChangeRequests = (new \ClyoNutri\Domain\Appointment\PatientAppointmentService())->countPendingForClinic(); ?>
        <?php if ($pendingChangeRequests > 0): ?>
          <div class="op-message op-message--info card u-accent u-accent-semantic-warning u-mb-4" role="status" data-testid="agenda-change-requests">
            <strong><?= $pendingChangeRequests === 1 ? '1 pedido de remarcação' : $pendingChangeRequests . ' pedidos de remarcação' ?></strong> feito<?= $pendingChangeRequests === 1 ? '' : 's' ?> pela paciente no app aguardando resposta. <?php if (\ClyoNutri\Core\Security\ClinicalAuthorization::isClinical(\ClyoNutri\Core\Auth\AuthContext::get()?->toArray() ?? [])): ?><a href="/agenda/pedidos-remarcacao">Responder pedidos</a><?php else: ?>O nutricionista responde em Pedidos de remarcação.<?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($agendaNoSlotsIn14Days): ?>
          <div class="op-message op-message--error card u-mb-4" role="alert" data-testid="agenda-no-slots-alert">
            <strong>Aviso de autoagendamento:</strong> <?= htmlspecialchars((string)$agendaBookingNotice) ?> <a href="<?= htmlspecialchars(\ClyoNutri\Domain\Booking\BookingSetupState::ACTION_URL) ?>" class="u-color-semantic-warning-strong u-font-semibold u-underline">Configurar horários de atendimento em Configurações →</a>
          </div>
        <?php endif; ?>

        <!-- Cabeçalho & Controles da Agenda -->
        <?php if ($view === 'week' && is_array($weekData)):
          $weekTotal = (int)($weekData['total_appointments'] ?? 0);
          $agendaTz = getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo';
          $weekTitleFormatted = 'Semana de ' . date('d/m', strtotime($weekData['start_date'])) . ' a ' . date('d/m/Y', strtotime($weekData['end_date']));
        ?>
        <h1 class="sr-only">Agenda de Atendimentos</h1>
        <div class="card agenda-week-toolbar">
          <div class="agenda-week-toolbar__left">
            <div class="agenda-nav-pill" role="group" aria-label="Navegação da semana">
              <a href="/agenda?view=week&amp;date=<?= $prevDate ?>" class="agenda-nav-pill__arrow" aria-label="<?= htmlspecialchars($nav['prev_label']) ?>" title="<?= htmlspecialchars($nav['prev_label']) ?>"><span aria-hidden="true">‹</span></a>
              <a href="/agenda?view=week&amp;date=<?= $todayDate ?>" class="agenda-nav-pill__today" aria-label="<?= htmlspecialchars($nav['today_label']) ?>" <?= $nav['is_current'] ? 'aria-current="date"' : '' ?>>Hoje</a>
              <a href="/agenda?view=week&amp;date=<?= $nextDate ?>" class="agenda-nav-pill__arrow" aria-label="<?= htmlspecialchars($nav['next_label']) ?>" title="<?= htmlspecialchars($nav['next_label']) ?>"><span aria-hidden="true">›</span></a>
            </div>
            <div class="agenda-week-toolbar__heading">
              <h2 class="agenda-week-toolbar__title"><?= htmlspecialchars($weekTitleFormatted) ?></h2>
              <p class="agenda-week-toolbar__subtitle"><?= $weekTotal ?> <?= $weekTotal === 1 ? 'atendimento' : 'atendimentos' ?> no período · horários em <?= htmlspecialchars($agendaTz) ?></p>
            </div>
            <span class="sr-only" role="status" id="agenda-period"><?= htmlspecialchars($nav['period_label']) ?></span>
            <label class="sr-only" for="agenda-date-picker">Ir para a data</label>
            <input type="date" id="agenda-date-picker" value="<?= $currentDate ?>" data-nav-prefix="/agenda?view=week&date=" class="sr-only">
          </div>

          <div class="agenda-controls-layout agenda-week-toolbar__right">
            <div class="agenda-view-segmented" role="group" aria-label="Alternar visão da agenda">
              <a href="/agenda?view=day&amp;date=<?= $currentDate ?>" class="agenda-view-segmented__item <?= $view === 'day' ? 'is-active' : '' ?>">Dia</a>
              <a href="/agenda?view=week&amp;date=<?= $currentDate ?>" class="agenda-view-segmented__item <?= $view === 'week' ? 'is-active' : '' ?>" aria-current="page">Semana</a>
              <a href="/agenda?view=month&amp;date=<?= $currentDate ?>" data-testid="agenda-view-month" class="agenda-view-segmented__item <?= $view === 'month' ? 'is-active' : '' ?>">Mês</a>
            </div>

            <a href="/configuracoes?tab=agenda&amp;secao=feriados" class="btn btn-secondary agenda-week-toolbar__block-btn" title="Cadastrar bloqueio de horário ou feriado">
              + Bloquear Horário
            </a>
            <a href="/configuracoes?tab=agenda" class="sr-only" data-testid="agenda-to-settings-btn">Horários &amp; Regras</a>

            <button type="button" aria-controls="card-novo-agendamento" data-toggle-dialog="card-novo-agendamento" class="btn btn-primary agenda-week-toolbar__new-btn">
              + Novo Atendimento
            </button>
          </div>
        </div>
        <?php $bookingUiState=(new \ClyoNutri\Domain\Booking\BookingSetupState(Connection::getInstance()))->forProfessional(TenantContext::getClinicId(),$activeProfId); ?>
        <p class="op-help sr-only" data-booking-state="<?= htmlspecialchars($bookingUiState) ?>">Agendamento online: <strong><?= htmlspecialchars(\ClyoNutri\Domain\Booking\BookingSetupState::label($bookingUiState)) ?></strong></p>
        <?php else: ?>
        <div class="u-flex u-justify-between u-items-center u-mb-6 u-wrap u-gap-4">
          <div>
            <h1>Agenda de Atendimentos</h1>
            <p class="agenda-header-desc u-color-neutral-gray-500">
              Gestão da jornada clínica: agendamento, confirmação, ciclo da consulta e execução.
            </p>
          </div>

          <div class="agenda-controls-layout u-flex u-gap-2 u-items-center u-wrap">
            <!-- Navegação por Data -->
            <div class="u-inline-flex u-border u-bc-neutral-gray-300 u-radius-input u-overflow-hidden u-bg-neutral-white">
              <a href="/agenda?view=<?= $view ?>&date=<?= $prevDate ?>" class="btn btn-secondary u-border-0 u-radius-0 u-minh-touch u-py-0 u-px-3" aria-label="<?= htmlspecialchars($nav['prev_label']) ?>" title="<?= htmlspecialchars($nav['prev_label']) ?>"><span aria-hidden="true">←</span></a>
              <a href="/agenda?view=<?= $view ?>&date=<?= $todayDate ?>" class="btn btn-secondary u-border-0 u-radius-0 u-minh-touch u-py-0 u-px-3 u-font-semibold" aria-label="<?= htmlspecialchars($nav['today_label']) ?>" <?= $nav['is_current'] ? 'aria-current="date"' : '' ?>>Hoje</a>
              <a href="/agenda?view=<?= $view ?>&date=<?= $nextDate ?>" class="btn btn-secondary u-border-0 u-radius-0 u-minh-touch u-py-0 u-px-3" aria-label="<?= htmlspecialchars($nav['next_label']) ?>" title="<?= htmlspecialchars($nav['next_label']) ?>"><span aria-hidden="true">→</span></a>
            </div>
            <span class="sr-only" role="status" id="agenda-period"><?= htmlspecialchars($nav['period_label']) ?></span>

            <label class="sr-only" for="agenda-date-picker">Ir para a data</label>
            <input type="date" id="agenda-date-picker" value="<?= $currentDate ?>" data-nav-prefix="/agenda?view=<?= $view ?>&date=" class="u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact u-bg-neutral-white">

            <?php /* Alternância de Visão Dia / Semana / Mês (NI2-527) */ ?>
            <div class="u-inline-flex u-border u-bc-neutral-gray-300 u-radius-input u-overflow-hidden u-bg-neutral-white">
              <a href="/agenda?view=day&date=<?= $currentDate ?>" class="btn <?= $view === 'day' ? 'btn-primary' : 'btn-secondary' ?> u-border-0 u-radius-0 u-minh-touch">Visão diária</a>
              <a href="/agenda?view=week&date=<?= $currentDate ?>" class="btn <?= $view === 'week' ? 'btn-primary' : 'btn-secondary' ?> u-border-0 u-radius-0 u-minh-touch">Visão semanal</a>
              <a href="/agenda?view=month&date=<?= $currentDate ?>" data-testid="agenda-view-month" class="btn <?= $view === 'month' ? 'btn-primary' : 'btn-secondary' ?> u-border-0 u-radius-0 u-minh-touch">Visão mensal</a>
            </div>

            <?php /* Atalho 1-clique para Configurações da Agenda (NI2-588) */ ?>
            <a href="/configuracoes?tab=agenda" class="btn btn-secondary u-minh-touch u-inline-flex u-items-center u-gap-1" data-testid="agenda-to-settings-btn" title="Configurar horários de atendimento e regras de agendamento">
              <?= \ClyoNutri\Application\View\Icon::svg('settings', 16) ?> Horários &amp; Regras
            </a>

            <!-- Botão Novo Agendamento -->
            <button type="button" aria-controls="card-novo-agendamento" data-toggle-dialog="card-novo-agendamento" class="btn btn-accent u-minh-touch">
              + Nova Consulta
            </button>
          </div>
        </div>

        <?php $bookingUiState=(new \ClyoNutri\Domain\Booking\BookingSetupState(Connection::getInstance()))->forProfessional(TenantContext::getClinicId(),$activeProfId); ?>
        <p class="op-help" data-booking-state="<?= htmlspecialchars($bookingUiState) ?>">Agendamento online: <strong><?= htmlspecialchars(\ClyoNutri\Domain\Booking\BookingSetupState::label($bookingUiState)) ?></strong></p>
        <p class="agenda-secondary-links"><a class="btn btn-secondary" href="/configuracoes?tab=agenda&amp;secao=feriados">Feriados e bloqueios</a> <a class="btn btn-secondary" href="/configuracoes?tab=agenda">Configurações da agenda</a></p>
        <?php endif; ?>
        <?php if($view==='day'): ?>
          <?php $agendaMarkerDate=$currentDate;$agendaDayRules=$agendaCalendarRules[$currentDate]??[];require __DIR__.'/../../../views/agenda/_calendar_markers.php'; ?>
          <?php if($agendaDayRules): ?><p class="agenda-calendar-note">As exceções do calendário aplicam-se a novas marcações. Consultas já agendadas são mantidas.</p><?php endif; ?>
        <?php endif; ?>
        <!-- Formulário Retrátil de Novo Agendamento -->
        <?php $prefillPatient = (isset($_GET['paciente']) && ctype_digit((string)$_GET['paciente'])) ? (int)$_GET['paciente'] : 0; $openNew = ($_GET['novo'] ?? '') === '1';
          // UX-05 (v14): preenchimento devolvido após conflito/falha.
          $agendaRecovered = $this->takeAgendaForm('new'); $rv = $agendaRecovered['values'] ?? [];
          if ($agendaRecovered) { $openNew = true; $prefillPatient = (int)($rv['patient_id'] ?? $prefillPatient); }
          $newConflict = !empty($agendaRecovered['conflict']);
          $chosenTime = is_string($_GET['time'] ?? null) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $_GET['time']) ? $_GET['time'] : '09:00';
          $newScheduled = $rv['scheduled_at'] ?? ($currentDate . 'T' . $chosenTime);
          if (isset($rv['duration_minutes'])) { $defaultDuration = (int)$rv['duration_minutes']; }
          $newType = (string)($rv['type'] ?? 'first_visit');
          // NI2-707: agendar retorno a partir do perfil: próximo horário válido, tipo Retorno se já houve consulta, e volta ao perfil.
          $returnToPatient = $prefillPatient > 0 && ((($_GET['de'] ?? '') === 'paciente') || (($rv['return_to'] ?? '') === 'patient'));
          $suggestedSlot = null;
          if ($prefillPatient > 0 && !isset($rv['type'])) {
              $hadVisit = Connection::getInstance()->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status = 'completed'");
              $hadVisit->execute([(int)TenantContext::getClinicId(), $prefillPatient]);
              if ((int)$hadVisit->fetchColumn() > 0) { $newType = 'follow_up'; }
          }
          if ($returnToPatient && !$agendaRecovered && !isset($_GET['time'])) {
              $suggestedSlot = $this->nextValidSlot((int)$defaultDuration);
              if ($suggestedSlot) { $newScheduled = $suggestedSlot; }
          } ?>
        <div id="card-novo-agendamento" data-dialog="inline" class="card u-accent-top u-accent-brand-copper-500 u-mb-6<?= $openNew ? '' : ' u-hidden' ?>">
          <div class="card__header">
            <h3>Agendar nova consulta</h3>
            <button type="button" data-dialog-close aria-label="Fechar agendamento" data-hide="card-novo-agendamento" class="u-bg-none u-border-0 u-text-h3 u-pointer">✕</button>
          </div>
          <?php if ($agendaRecovered): ?>
            <div class="op-message op-message--error u-mt-3" role="alert" data-testid="agenda-form-error">
              <strong><?= $newConflict ? 'Horário indisponível.' : 'Não foi possível agendar.' ?></strong> <?= htmlspecialchars((string)$agendaRecovered['error']) ?> Seus dados foram mantidos; ajuste o horário<?= $newConflict ? ' ou marque o encaixe' : '' ?> e confirme de novo.
            </div>
          <?php endif; ?>
          <form action="/agenda" method="POST" data-availability-preview data-agenda-new class="u-mt-4" <?= $agendaRecovered ? 'data-start-dirty' : '' ?>>
            <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
            <?php if ($returnToPatient): ?><input type="hidden" name="return_to" value="patient"><?php endif; ?>
            <?php if ($suggestedSlot): ?><p class="op-message op-message--info u-mb-3" data-testid="agenda-suggested-slot">Sugerido o próximo horário livre: <?= date('d/m/Y \\à\\s H:i', strtotime(str_replace('T', ' ', $suggestedSlot))) ?>. Ajuste se preferir.</p><?php endif; ?>
            <div class="u-grid u-cols-auto-md u-gap-4 u-mb-4">
              <div class="u-relative">
                <div class="u-flex u-justify-between u-items-baseline u-mb-1">
                  <label for="appointment-patient-search" class="u-font-semibold u-text-compact">Paciente *</label>
                  <button type="button" id="btn-inline-new-patient" class="btn-link u-text-compact u-color-botanical-copper-600 u-bg-none u-border-0 u-pointer u-underline u-p-0" data-call="openPatientModal">+ novo paciente</button>
                </div>
                <input type="text" id="appointment-patient-search" placeholder="Digite para buscar paciente (mín. 3 letras)..." autocomplete="off" class="form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-mb-1" aria-autocomplete="list" aria-controls="appointment-patient-matches">
                <div id="appointment-patient-matches" role="listbox" class="ds-combobox-list u-hidden"></div>
                <label for="dc11-patient_id" class="visually-hidden">Paciente selecionado (obrigatório)</label><select id="dc11-patient_id" name="patient_id" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <option value="">Selecione o paciente...</option>
                  <?php foreach ($patients as $pt): ?>
                    <option value="<?= $pt['id'] ?>"<?= ((int)$pt['id'] === $prefillPatient) ? ' selected' : '' ?>><?= htmlspecialchars($pt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div id="patient-same-day-warning" class="op-message op-message--error u-hidden u-mt-2 u-text-compact u-hidden" role="alert"></div>
              </div>

              <?php $scheduleFieldPrefix='new-appointment';$scheduleFieldValue=(string)$newScheduled;$scheduleFieldValues=$rv;$scheduleFieldConflict=$newConflict;require __DIR__.'/../../../views/agenda/_schedule_fields.php'; ?>

              <div>
                <label for="dc13-duration_minutes" class="u-block u-font-semibold u-text-compact u-mb-1">Duração</label>
                <select id="dc13-duration_minutes" name="duration_minutes" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <?php $durationChoices=array_unique([30,45,60,90,120,$defaultDuration]);sort($durationChoices);foreach($durationChoices as $minutes): ?>
                  <option value="<?= $minutes ?>" <?= $minutes===$defaultDuration?'selected':'' ?>><?= $minutes ?> minutos<?= $minutes===$defaultDuration?' (Padrão)':'' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label for="dc14-type" class="u-block u-font-semibold u-text-compact u-mb-1">Tipo de Atendimento</label>
                <select id="dc14-type" name="type" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <option value="first_visit" <?= $newType === 'first_visit' ? 'selected' : '' ?>>Primeira consulta / anamnese</option>
                  <option value="follow_up" <?= $newType === 'follow_up' ? 'selected' : '' ?>>Retorno / acompanhamento</option>
                  <option value="telehealth" <?= $newType === 'telehealth' ? 'selected' : '' ?>>Teleconsulta</option>
                </select>
              </div>
            </div>

            <div class="op-message op-message--info cn-policy-summary" data-availability-feedback role="status" aria-live="polite">Selecione data, horário e duração para conferir a disponibilidade. A confirmação final é feita ao agendar.</div>
            <script src="/assets/js/agenda-availability.js?v=v54-30" defer></script>
            <div class="u-mb-4">
              <label for="dc15-notes" class="u-block u-font-semibold u-text-compact u-mb-1">Observações</label>
              <textarea id="dc15-notes" name="notes" rows="2" placeholder="Queixas iniciais, encaminhamentos ou solicitações pré-consulta..." class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"><?= htmlspecialchars((string)($rv['notes'] ?? '')) ?></textarea>
            </div>

            <div class="u-flex u-justify-between u-items-center u-border-t u-pt-3 u-wrap u-gap-2">
              <div class="u-flex u-flex-col u-gap-1">
                <label class="u-flex u-items-center u-gap-2 u-text-compact u-color-neutral-gray-500 u-pointer">
                  <input type="checkbox" name="allow_conflict" value="1">
                  <span>Permitir sobreposição de horário (encaixe)</span>
                </label>
                <?php $sameDayVisible = !empty($agendaRecovered['same_day']) || !empty($rv['allow_same_day']); ?>
                <label id="label-allow-same-day" class="u-items-center u-gap-2 u-text-compact u-color-semantic-warning-strong u-pointer <?= $sameDayVisible ? 'u-flex' : 'u-hidden' ?>">
                  <input type="checkbox" name="allow_same_day" id="input_allow_same_day" value="1" <?= !empty($rv['allow_same_day']) ? 'checked' : '' ?>>
                  <span>Confirmar mesmo com outra consulta do paciente no mesmo dia</span>
                </label>
              </div>

              <div class="u-flex u-gap-2">
                <button type="button" data-dialog-close data-hide="card-novo-agendamento" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar agendamento</button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($view === 'day'): ?>
          <!-- Visão Diária da Agenda -->
          <div class="card">
            <div class="card__header">
              <h3>Consultas do Dia (<?= date('d/m/Y', strtotime($currentDate)) ?>)</h3>
              <span class="u-text-compact u-color-neutral-gray-500"><?= count($appointments) ?> agendamento(s)</span>
            </div>

            <?php if (!empty($dayAvailability['times'])): ?>
              <div class="agenda-quick-slots u-py-2 u-px-4 u-bg-neutral-gray-50 u-border-b u-flex u-items-center u-gap-2 u-wrap">
                <span class="u-text-compact u-font-semibold u-color-neutral-gray-600">Grade de horários livres:</span>
                <?php foreach (array_slice($dayAvailability['times'], 0, 8) as $freeTime): ?>
                  <button type="button" class="btn btn-secondary btn-sm agenda-slot-btn u-text-caption u-py-0 u-px-2 u-minh-touch u-radius-input u-inline-flex u-items-center u-gap-1" data-slot-time="<?= $freeTime ?>"><?= \ClyoNutri\Application\View\Icon::svg('clock', 12) ?> <?= $freeTime ?></button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="table-responsive agenda-day-wrap">
              <table class="table agenda-day-table">
                <thead>
                  <tr>
                    <th>Horário</th>
                    <th>Paciente</th>
                    <th>Contato</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th class="u-text-right">Ações da Consulta</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($appointments)): ?>
                    <tr>
                      <td colspan="6" class="u-text-center u-color-neutral-gray-500 u-p-8">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="u-mt-0 u-mb-2 u-mx-auto u-block u-color-neutral-gray-400"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        Nenhuma consulta agendada para este dia. <?= htmlspecialchars($dayAvailability['message'] ?? 'Horários disponíveis para novos pacientes.') ?>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php
                      // v49: prévias de confirmação do dia numa consulta só (antes era uma por consulta).
                      $confPreviews = [];
                      $confIds = [];
                      foreach ($appointments as $ap) {
                          if (in_array($ap['status'] ?? '', ['scheduled', 'confirmed'], true)) {
                              $confIds[] = (int)$ap['id'];
                          }
                      }
                      try {
                          $confPreviews = $appointmentService->previewConfirmationMessages($confIds);
                      } catch (\Throwable $e) {
                          error_log('[ClyoNutri] agenda: prévias de confirmação indisponíveis: ' . $e->getMessage());
                      }
                    ?>
                    <?php foreach ($appointments as $a): ?>
                      <tr>
                        <td class="tabular-nums u-font-bold u-color-botanical-forest-900">
                          <?= date('H:i', strtotime($a['scheduled_at'])) ?>
                          <small class="u-block u-text-caption u-font-regular u-color-neutral-gray-500"><?= $a['duration_minutes'] ?> min</small>
                        </td>
                        <td>
                          <a href="/pacientes/<?= $a['patient_id'] ?>" class="u-font-semibold u-color-botanical-forest-900 u-no-underline">
                            <?= htmlspecialchars($a['patient_name']) ?>
                          </a>
                        </td>
                        <td><?= htmlspecialchars($a['patient_phone'] ?? '—') ?></td>
                        <td><?= htmlspecialchars(LabelMap::appointmentType($a['type'])) ?></td>
                        <td>
                          <?= LabelMap::appointmentStatusBadge($a['status']) ?>
                        </td>
                        <td class="u-text-right">
                          <div class="u-inline-flex u-gap-1 u-justify-end">
                            <?php if ($a['status'] === 'scheduled'): ?>
                              <!-- Confirmar -->
                              <form action="/agenda/<?= $a['id'] ?>/status" method="POST" class="u-inline">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="status" value="confirmed">
                                <button type="submit" class="btn btn-secondary u-text-caption u-py-1 u-px-2 u-minh-touch">Confirmar</button>
                              </form>
                              <!-- Iniciar -->
                              <a href="/consulta/<?= $a['id'] ?>" class="btn btn-primary u-text-caption u-py-1 u-px-2 u-minh-touch">Iniciar</a>
                            <?php elseif ($a['status'] === 'confirmed'): ?>
                              <!-- Iniciar -->
                              <a href="/consulta/<?= $a['id'] ?>" class="btn btn-primary u-text-caption u-py-1 u-px-2 u-minh-touch">Iniciar atendimento</a>
                            <?php elseif ($a['status'] === 'in_progress'): ?>
                              <a href="/consulta/<?= $a['id'] ?>" class="btn btn-accent u-text-caption u-py-1 u-px-2 u-minh-touch">Continuar atendimento</a>
                            <?php elseif ($a['status'] === 'completed'): ?>
                              <a href="/pacientes/<?= $a['patient_id'] ?>" class="btn btn-secondary u-text-caption u-py-1 u-px-2 u-minh-touch">Prontuário ↗</a>
                            <?php endif; ?>

                            <?php /* Menu de Estados da Consulta ("Mais ações ▾") */ ?>
                            <?php if ($a['status'] === 'scheduled' || $a['status'] === 'confirmed'): ?>
                              <details class="agenda-actions-dropdown u-inline-block u-relative" data-more-actions>
                                <summary class="btn btn-secondary u-text-caption u-py-1 u-px-2 u-minh-touch u-pointer u-list-none" data-testid="agenda-more-actions">Mais ações ▾</summary>
                                <div class="agenda-actions-menu ds-menu-panel" data-more-actions-menu role="menu">
                                  <a href="/agenda/<?= (int)$a['id'] ?>/reagendar" class="agenda-menu-item u-minh-touch u-flex u-items-center u-py-1 u-px-3 u-text-caption u-color-neutral-ink-900 u-no-underline" data-testid="agenda-reschedule">Reagendar</a>
                                  <form action="/agenda/<?= $a['id'] ?>/status" method="POST" class="u-m-0" data-confirm="Deseja marcar como Não Compareceu?">
                                    <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="status" value="no_show">
                                    <button type="submit" class="agenda-menu-item u-minh-touch u-flex u-items-center u-w-full u-text-left u-py-1 u-px-3 u-text-caption u-color-semantic-error u-bg-none u-border-0 u-pointer" title="Não compareceu">Não compareceu</button>
                                  </form>
                                  <form action="/agenda/<?= $a['id'] ?>/status" method="POST" class="u-m-0" data-confirm="Confirmar cancelamento da consulta?">
                                    <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="agenda-menu-item u-minh-touch u-flex u-items-center u-w-full u-text-left u-py-1 u-px-3 u-text-caption u-color-neutral-gray-500 u-bg-none u-border-0 u-pointer" title="Cancelar consulta">Cancelar</button>
                                  </form>
                                </div>
                              </details>
                            <?php endif; ?>
                          </div>
                          <?php $confPrev = $confPreviews[(int)$a['id']] ?? null; ?>
                          <?php if (($a['status'] === 'scheduled' || $a['status'] === 'confirmed') && $confPrev === null): ?>
                            <div data-testid="confirmation-preview-unavailable-<?= (int)$a['id'] ?>" class="u-mt-1 u-text-caption u-color-neutral-gray-500">Prévia de confirmação indisponível para esta consulta.</div>
                          <?php elseif ($a['status'] === 'scheduled' || $a['status'] === 'confirmed'): ?>
                            <details data-testid="confirmation-preview-<?= (int)$a['id'] ?>" class="u-mt-1 u-text-left u-inline-block u-maxw-narrow">
                              <summary class="u-pointer u-text-caption u-color-botanical-forest-900 u-font-semibold u-text-right">Prévia e envio de confirmação</summary>
                              <div class="u-mt-1 u-py-2 u-px-3 u-bg-neutral-gray-50 u-border u-radius-input u-text-caption u-color-neutral-gray-700">
                                <div><strong>Destinatário:</strong> <?= htmlspecialchars($confPrev['recipient_email'] ?? 'Sem e-mail validado') ?></div>
                                <div><strong>Canal:</strong> <?= htmlspecialchars($confPrev['channel']) ?> · <strong>Fuso:</strong> <?= htmlspecialchars($confPrev['timezone']) ?></div>
                                <div class="u-mt-1 u-py-1 u-px-2 u-bg-neutral-white u-border u-radius-input"><?= htmlspecialchars($confPrev['body_preview']) ?></div>
                                <?php if (!$confPrev['can_send']): ?>
                                  <div data-testid="confirmation-blocked-reason" class="u-mt-1 u-color-semantic-error u-font-semibold"><?= htmlspecialchars((string)$confPrev['blocked_reason']) ?></div>
                                <?php else: ?>
                                  <form action="/agenda/<?= (int)$a['id'] ?>/confirmacao-autorizada" method="POST" class="u-mt-2">
                                    <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                    <button type="submit" class="btn btn-sm btn-primary" data-testid="btn-send-authorized-confirmation">Enviar confirmação autorizada</button>
                                  </form>
                                <?php endif; ?>
                              </div>
                            </details>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php elseif ($view === 'month' && is_array($monthData)): ?>
          <section class="card" data-testid="agenda-month-view" aria-label="Calendário mensal">
            <div class="card__header"><h3><?= htmlspecialchars($nav['period_label']) ?></h3><span><?= (int)$monthData['total_appointments'] ?> consulta(s) neste mês</span></div>
            <p class="agenda-month-list-label">Lista do mês por data</p>
            <div class="agenda-month-grid">
              <?php foreach(['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'] as $weekday): ?><div class="agenda-month-weekday" aria-hidden="true"><?= $weekday ?></div><?php endforeach; ?>
              <?php $monthStart=new \DateTimeImmutable($monthData['start_date']);for($blank=1;$blank<(int)$monthStart->format('N');$blank++): ?><div class="agenda-month-filler" aria-hidden="true"></div><?php endfor; ?>
              <?php foreach($monthData['days'] as $mDayDate=>$mDayApps): $agendaDayRules=$agendaCalendarRules[$mDayDate]??[];$holidayDay=(bool)array_filter($agendaDayRules,static fn($rule)=>$rule['kind']==='holiday'); ?>
              <article class="agenda-month-day <?= $holidayDay?'is-holiday':'' ?> <?= $mDayDate===$todayDate?'is-today':'' ?>" data-testid="agenda-month-day-<?= htmlspecialchars($mDayDate) ?>">
                <h4><a href="/agenda?view=day&amp;date=<?= htmlspecialchars($mDayDate) ?>" aria-label="Abrir <?= date('d/m/Y',strtotime($mDayDate)) ?>"><?= ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][(int)date('w',strtotime($mDayDate))] ?> <?= date('d/m',strtotime($mDayDate)) ?><?= $mDayDate===$todayDate?' · Hoje':'' ?></a></h4>
                <?php $agendaMarkerDate=$mDayDate;require __DIR__.'/../../../views/agenda/_calendar_markers.php'; ?>
                <?php if(!$mDayApps): ?><p class="agenda-calendar-note">Sem consultas</p><?php endif; ?>
                <?php foreach($mDayApps as $mApp): ?><a class="agenda-month-appointment" href="/consulta/<?= (int)$mApp['id'] ?>"><strong><?= date('H:i',strtotime($mApp['scheduled_at'])) ?></strong> <?= htmlspecialchars($mApp['patient_name']) ?></a><?php endforeach; ?>
              </article>
              <?php endforeach; ?>
              <?php for($blank=(int)(new \DateTimeImmutable($monthData['end_date']))->format('N');$blank<7;$blank++): ?><div class="agenda-month-filler" aria-hidden="true"></div><?php endfor; ?>
            </div>
          </section>
        <?php else: ?>
          <?php require __DIR__ . '/../../../views/agenda/_week_timeline.php'; ?>
        <?php endif; ?>

        <?php /* Modal Cadastrar Paciente na Agenda */ ?>
        <div id="patientModal" data-dialog="modal" class="modal-overlay" data-call-self="closePatientModal">
          <div class="cn-dialog__panel modal-dialog">
            <div class="modal-header">
              <h3>Novo paciente</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closePatientModal" aria-label="Fechar modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <form method="POST" action="/pacientes">
              <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
              <input type="hidden" name="_submission_id" value="<?= $agendaSubmissionId ?>">
              <input type="hidden" name="return_to" value="/agenda?date=<?= $currentDate ?>&novo=1">
              <div class="modal-body">
                <div class="form-group">
                  <label class="form-label" for="p_name">Nome completo *</label>
                  <input type="text" id="p_name" name="name" class="form-control" placeholder="Ex: Juliana Mendes" required autofocus>
                </div>

                <div class="form-group">
                  <label class="form-label" for="p_email">E-mail</label>
                  <input type="email" id="p_email" name="email" class="form-control" placeholder="paciente@exemplo.com.br">
                </div>

                <div class="u-grid u-cols-2 u-gap-4">
                  <div class="form-group">
                    <label class="form-label" for="p_phone">Telefone / WhatsApp</label>
                    <input type="tel" id="p_phone" name="phone" class="form-control" placeholder="(11) 98765-4321">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="p_birth">Data de nascimento</label>
                    <input type="date" id="p_birth" name="birth_date" class="form-control">
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="p_gender">Sexo registrado para referências clínicas (opcional)</label>
                  <select id="p_gender" name="gender" class="form-control">
                    <option value="">Selecione...</option>
                    <option value="Feminino">Feminino</option>
                    <option value="Masculino">Masculino</option>
                    <option value="Outro">Outro / Não informado</option>
                  </select>
                </div>
              </div>
              <div class="modal-footer u-flex u-justify-end u-gap-2 u-py-3 u-px-4 u-border-t">
                <button type="button" data-dialog-close class="btn btn-secondary" data-call="closePatientModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Cadastrar paciente</button>
              </div>
            </form>
          </div>
        </div>

        <script src="/assets/js/agenda-form.js?v=v54-30" defer></script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function previewAppointmentConfirmation(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $appointmentId = (int)($params['id'] ?? 0);
        try {
            $preview = (new AppointmentService())->previewConfirmationMessage($appointmentId);
            echo json_encode(['data' => $preview], JSON_UNESCAPED_UNICODE);
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri] prévia de confirmação da consulta ' . $appointmentId . ': ' . $e->getMessage());
            http_response_code(422);
            echo json_encode(['error' => 'Não foi possível montar a prévia agora. Tente de novo.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function sendAuthorizedAppointmentConfirmation(array $params): void
    {
        $appointmentId = (int)($params['id'] ?? 0);
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /agenda?error=csrf');
            exit;
        }
        try {
            $res = (new AppointmentService())->sendAuthorizedConfirmation($appointmentId, TenantContext::getUserId());
            $dateParam = substr((string)($res['preview']['scheduled_at'] ?? ''), 0, 10);
            header('Location: /agenda?date=' . urlencode($dateParam) . '&success=confirmacao_enviada');
            exit;
        } catch (\InvalidArgumentException $e) {
            header('Location: /agenda?error=falha&msg=' . urlencode($this->userFacingError('agenda confirmação', $e)));
            exit;
        } catch (\Throwable $e) {
            error_log('[ClyoNutri] envio de confirmação da consulta ' . $appointmentId . ': ' . $e->getMessage());
            header('Location: /agenda?error=falha&msg=' . urlencode('Não foi possível enviar a confirmação agora. Tente de novo.'));
            exit;
        }
    }


    public function mealPlans(): void
    {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $clinicId = TenantContext::getClinicId();
        $stmt = $db->prepare("
            SELECT mp.*, p.name AS patient_name, u.name AS professional_name
            FROM meal_plans mp
            LEFT JOIN patients p ON p.id = mp.patient_id AND p.clinic_id = mp.clinic_id
            LEFT JOIN professionals pr ON pr.id = mp.professional_id
            LEFT JOIN users u ON u.id = pr.user_id
            WHERE mp.clinic_id = :clinic_id
              AND (:status_filter = '' OR mp.status = :status_filter_eq)
              AND (:patient_filter = 0 OR (mp.patient_id = :patient_filter_eq AND p.clinic_id = mp.clinic_id))
            ORDER BY mp.id DESC
        ");
        // Filtros opcionais (lista branca + inteiro) usados pelo link "Ver todos" do Dashboard.
        $planStatusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['draft', 'published', 'archived'], true) ? $_GET['status'] : '';
        $planPatientFilter = max(0, (int)filter_var($_GET['paciente'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]));
        // Tipos explícitos: no SQLite, um inteiro ligado como texto ('0') não é igual ao literal 0.
        $stmt->bindValue(':clinic_id', $clinicId, \PDO::PARAM_INT);
        $stmt->bindValue(':status_filter', $planStatusFilter, \PDO::PARAM_STR);
        $stmt->bindValue(':status_filter_eq', $planStatusFilter, \PDO::PARAM_STR);
        $stmt->bindValue(':patient_filter', $planPatientFilter, \PDO::PARAM_INT);
        $stmt->bindValue(':patient_filter_eq', $planPatientFilter, \PDO::PARAM_INT);
        $stmt->execute();
        $plans = $stmt->fetchAll();
        $planFilterLabels = ['draft' => 'Rascunhos', 'published' => 'Publicados', 'archived' => 'Arquivados'];

        $patStmt = $db->prepare("SELECT id, name FROM patients WHERE clinic_id = :clinic_id AND status = 'active' ORDER BY name ASC");
        $patStmt->execute(['clinic_id' => $clinicId]);
        $patients = $patStmt->fetchAll();
        // NI2-519 (v42): modelos ativos da clínica (e globais) para começar um plano novo.
        try {
            $planTemplates = (new \ClyoNutri\Domain\MealPlan\MealPlanTemplateService($db))->listForClinic(false);
        } catch (\Throwable $e) {
            error_log('NI2-519 list templates failed: ' . $e->getMessage());
            $planTemplates = [];
        }

        $title = 'Planos alimentares';
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica Clyo Nutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        ob_start();
        ?>
        <div class="ds-page-header" data-testid="plans-page-header">
          <div>
            <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-m-0">Planos alimentares</h1>
            <p class="u-color-neutral-gray-500 u-mt-1">Monte refeições, alimentos e substituições e acompanhe os totais. Cada publicação gera uma nova versão; as anteriores ficam no histórico.</p>
          </div>
          <div class="ds-page-actions">
            <a href="/modelos" class="btn btn-outline" data-testid="plan-templates-link">Modelos de plano</a>
            <button type="button" class="btn btn-primary" data-show="modal-new-plan" aria-haspopup="dialog">
              + Novo plano alimentar
            </button>
          </div>
        </div>

        <?php if ($error): ?>
          <div role="alert" class="op-message op-message--error u-mb-4" data-testid="page-error-message">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <?php if ($feedback): ?>
          <div role="status" class="op-message op-message--success u-mb-4">
            <?= htmlspecialchars($feedback) ?>
          </div>
        <?php endif; ?>

        <nav class="plan-filters" aria-label="Filtrar planos por status" data-testid="plan-status-filter">
          <?php foreach (['' => 'Todos'] + $planFilterLabels as $fKey => $fLabel): ?>
            <a href="/planos<?= $fKey !== '' ? '?status=' . $fKey : '' ?>" class="plan-filters__item<?= $planStatusFilter === $fKey && $planPatientFilter === 0 ? ' is-active' : '' ?>"<?= $planStatusFilter === $fKey && $planPatientFilter === 0 ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($fLabel) ?></a>
          <?php endforeach; ?>
          <?php if ($planPatientFilter > 0): ?>
            <span class="plan-filters__note">Filtrado por paciente<?= !empty($plans) && $plans[0]['patient_name'] !== null ? ': ' . htmlspecialchars($plans[0]['patient_name']) : '' ?> · <a href="/planos<?= $planStatusFilter !== '' ? '?status=' . $planStatusFilter : '' ?>">limpar filtro de paciente</a></span>
          <?php endif; ?>
        </nav>

        <div class="card">
          <div class="table-responsive">
            <table class="table u-w-full u-collapse">
              <thead>
                <tr class="u-border-b u-text-left">
                  <th class="u-p-3">Título do Plano</th>
                  <th class="u-p-3">Paciente</th>
                  <th class="u-p-3">Versão</th>
                  <th class="u-p-3">Status</th>
                  <th class="u-p-3">Energia</th>
                  <th class="u-p-3">Última atualização</th>
                  <th class="u-p-3 u-text-right">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($plans)): ?>
                  <tr><td colspan="7" class="u-text-center u-color-neutral-gray-500 u-p-6"><?= ($planStatusFilter !== '' || $planPatientFilter > 0) ? 'Nenhum plano encontrado para este filtro.' : 'Nenhum plano alimentar cadastrado ainda.' ?></td></tr>
                <?php else: ?>
                  <?php foreach ($plans as $pl): ?>
                    <tr class="u-border-b u-bc-neutral-gray-100">
                      <td class="u-p-3 u-font-semibold u-color-botanical-forest-900">
                        <?= htmlspecialchars($pl['title']) ?>
                        <?php if (!empty($pl['parent_plan_id'])): ?>
                          <span class="u-text-caption u-color-neutral-gray-500 u-block u-font-regular">(Derivado de #<?= (int)$pl['parent_plan_id'] ?>)</span>
                        <?php endif; ?>
                      </td>
                      <td class="u-p-3"><?= $pl['patient_name'] !== null ? htmlspecialchars($pl['patient_name']) : '<span class="u-color-neutral-gray-500" data-testid="plan-no-patient">Sem paciente (atribuir depois)</span>' ?></td>
                      <td class="tabular-nums u-p-3">v<?= (int)$pl['version'] ?></td>
                      <td class="u-p-3">
                        <?= LabelMap::mealPlanStatusBadge($pl['status'] ?? '') ?>
                      </td>
                      <td class="tabular-nums u-p-3"><?= number_format((float)($pl['daily_energy_kcal'] ?? 0), 0, ',', '.') ?> kcal</td>
                      <td class="u-p-3 u-color-neutral-gray-500 u-text-compact"><?= date('d/m/Y H:i', strtotime($pl['updated_at'] ?? $pl['created_at'])) ?></td>
                      <td class="u-p-3 u-text-right u-nowrap">
                        <a href="/planos/<?= $pl['id'] ?>" class="btn btn-sm btn-outline u-no-underline u-py-1 u-px-2 u-text-compact u-mr-1">
                          <?= $pl['status'] === 'draft' ? 'Editar' : 'Visualizar' ?>
                        </a>
                        <form method="POST" action="/planos/<?= $pl['id'] ?>/duplicar" class="u-inline" data-confirm="Deseja duplicar este plano para um novo rascunho?">
                          <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                          <button type="submit" class="btn btn-sm btn-secondary u-py-1 u-px-2 u-text-compact">Duplicar</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Modal Novo Plano -->
        <div id="modal-new-plan" data-dialog="modal" class="modal-overlay">
          <div class="cn-dialog__panel u-bg-neutral-white u-radius-card u-w-full u-maxw-form u-p-6 u-shadow-lg">
            <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-4">Novo plano alimentar</h2>
            <form method="POST" action="/planos/novo">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <div class="u-mb-4">
                <label for="dc16-patient_id" class="u-block u-font-semibold u-mb-1 u-text-compact">Paciente (opcional)</label>
                <select id="dc16-patient_id" name="patient_id" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <option value="">Sem paciente por enquanto (atribuo depois)</option>
                  <?php foreach ($patients as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="u-color-neutral-gray-500 u-text-caption u-block u-mt-1">Sem paciente, o plano fica como rascunho de teste. Para publicar, atribua um paciente: a conferência de alergias usa a anamnese dele.</small>
              </div>
              <div class="u-mb-4">
                <label for="dc17-title" class="u-block u-font-semibold u-mb-1 u-text-compact">Título da Prescrição *</label>
                <input id="dc17-title" type="text" name="title" required value="<?= htmlspecialchars('Plano alimentar de ' . date('d/m/Y')) ?>" placeholder="Ex: Plano Hipertrofia Fase 1" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              </div>
              <?php if ($planTemplates !== []): ?>
              <div class="u-mb-4">
                <label for="new-plan-template" class="u-block u-font-semibold u-mb-1 u-text-compact">Começar de um modelo (opcional)</label>
                <select id="new-plan-template" name="template_id" data-testid="new-plan-template" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <option value="">Não, começar do zero</option>
                  <?php foreach ($planTemplates as $pt): ?>
                    <option value="<?= (int)$pt['id'] ?>"<?= !empty($pt['is_clinic_default']) ? ' selected' : '' ?>><?= htmlspecialchars($pt['title']) ?> (<?= htmlspecialchars($pt['category']) ?>, <?= (int)$pt['meal_count'] ?> <?= (int)$pt['meal_count'] === 1 ? 'refeição' : 'refeições' ?>)<?= !empty($pt['is_clinic_default']) ? ' · padrão da clínica' : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="u-color-neutral-gray-500 u-text-caption u-block u-mt-1">Com um modelo, as refeições e os alimentos dele entram no plano e a opção das 6 refeições padrão é ignorada. Confira as quantidades para este paciente.</small>
              </div>
              <?php endif; ?>
              <fieldset class="u-mt-0 u-mb-4 u-mx-0 u-border u-bc-botanical-sage-200 u-radius-input u-py-2 u-px-3" data-testid="new-plan-method">
                <legend class="u-font-semibold u-text-compact u-py-0 u-px-1">Como prescrever</legend>
                <?php foreach (\ClyoNutri\Domain\MealPlan\MealPlanTemplateService::METHODS as $mk => $ml): ?>
                <label class="u-flex u-gap-2 u-items-start u-text-compact u-my-1 u-mx-0 u-minh-touch">
                  <input type="radio" name="plan_method" value="<?= $mk ?>"<?= $mk === 'alimentos' ? ' checked' : '' ?> class="u-mt-1">
                  <span><strong><?= htmlspecialchars($ml) ?></strong> · <?= htmlspecialchars(['alimentos' => 'quantidades em gramas ou medidas, com cálculo de nutrientes.', 'equivalentes' => 'trocas por grupo, usando as regras de equivalência da clínica.', 'qualitativo' => 'orientações escritas por refeição, sem quantidades obrigatórias.'][$mk]) ?></span>
                </label>
                <?php endforeach; ?>
                <small class="u-color-neutral-gray-500 u-text-caption u-block u-mt-1">Com um modelo escolhido, vale o método salvo no modelo.</small>
              </fieldset>
              <div class="u-mb-4">
                <label class="u-flex u-gap-2 u-items-start u-text-compact">
                  <input type="checkbox" name="quick_meals" value="1" checked class="u-mt-1" data-testid="quick-meals">
                  <span>Começar com 6 refeições padrão (café da manhã, colação, almoço, lanche da tarde, jantar e ceia). Você pode renomear, mudar o horário ou remover.</span>
                </label>
              </div>
              <div class="u-mb-5">
                <label for="dc18-notes" class="u-block u-font-semibold u-mb-1 u-text-compact">Orientações gerais do plano (opcional)</label>
                <textarea id="dc18-notes" name="notes" rows="3" placeholder="Recomendações clínicas, horários preferenciais, hidratação..." class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
              </div>
              <div class="u-flex u-justify-end u-gap-2">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-plan">Cancelar</button>
                <button type="submit" class="btn btn-primary">Iniciar prescrição</button>
              </div>
            </form>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function createMealPlan(): void
    {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        // O autor (e a assinatura/CRN da impressão) é sempre o profissional logado; sem fallback (code review NI2-9, A1).
        $professionalId = $this->prescriberProfessionalId($db);
        if ($professionalId === null) {
            $this->denyPrescription();
            return;
        }
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        // NI2-514: paciente opcional; título padrão com a data; 6 refeições padrão sob demanda.
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            $title = 'Plano alimentar de ' . date('d/m/Y');
        }
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
        $quickMeals = !empty($_POST['quick_meals']) ? \ClyoNutri\Domain\MealPlan\MealPlanService::DEFAULT_MEALS : [];
        $templateId = (int)($_POST['template_id'] ?? 0);

        // NI2-519 (v42): começar de um modelo; as refeições do modelo substituem as 6 padrão.
        if ($templateId > 0) {
            try {
                $r = (new \ClyoNutri\Domain\MealPlan\MealPlanTemplateService($db))->createPlanFromTemplate($templateId, $professionalId, $patientId > 0 ? $patientId : null, $title, $notes);
                $_SESSION['feedback_message'] = \ClyoNutri\Application\Controllers\MealPlanTemplateController::usedMessage($r)
                    . ($patientId > 0 ? '' : ' O plano está sem paciente: atribua antes de publicar.');
                header("Location: /planos/" . (int)$r['plan_id']);
                exit;
            } catch (\InvalidArgumentException | \DomainException | \ClyoNutri\Core\Security\TenantSecurityException $e) {
                $_SESSION['error_message'] = 'Não foi possível usar o modelo: ' . $e->getMessage();
            } catch (\Throwable $e) {
                error_log('NI2-519 create from template failed: ' . $e->getMessage());
                $_SESSION['error_message'] = 'Não foi possível criar o plano a partir do modelo. Nada foi criado. Tente novamente.';
            }
            header("Location: /planos");
            exit;
        }

        try {
            $created = $service->createPlan($patientId > 0 ? $patientId : null, $professionalId, $title, $notes, $quickMeals);
            $method = (string)($_POST['plan_method'] ?? 'alimentos');
            if ($method !== 'alimentos' && isset(\ClyoNutri\Domain\MealPlan\MealPlanTemplateService::METHODS[$method])) {
                $service->setPlanMethod((int)$created['plan_id'], $method);
            }
            $_SESSION['feedback_message'] = $patientId > 0
                ? "Plano alimentar '{$title}' criado. Agora é só montar as refeições."
                : "Plano alimentar '{$title}' criado sem paciente. Monte a prescrição e atribua o paciente antes de publicar.";
            header("Location: /planos/" . $created['plan_id']);
            exit;
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao criar plano: " . $this->userFacingError('Erro ao criar plano', $e);
            header("Location: /planos");
            exit;
        }
    }

    public function mealPlanEditor(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Você não tem permissão para acessar planos alimentares.';
            return;
        }
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $preview = $service->getPlanPreview($planId);
            $plan = $preview['plan'];
            $clinicalSummary = $plan['clinical_safety_summary'];
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(403);
            echo 'Acesso ao plano não autorizado.';
            return;
        } catch (\Throwable $e) {
            error_log('NI2-312 clinical summary failed: ' . $e->getMessage());
            http_response_code(503);
            echo 'Não foi possível carregar o plano e a avaliação de alergias. <a href="/planos">Tentar novamente</a>';
            return;
        }


        $fStmt = $db->prepare("SELECT id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods WHERE clinic_id IS NULL OR clinic_id = :catalog_clinic ORDER BY name ASC LIMIT 250");
        $fStmt->execute(['catalog_clinic' => TenantContext::getClinicId()]);
        $foodsCatalog = $fStmt->fetchAll();

        $title = "Plano Alimentar: " . htmlspecialchars($plan['title']);
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica Clyo Nutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        $isDraft = ($plan['status'] === 'draft');
        $canEditItems = $this->canEditPlanItems($db);

        // NI2-514: plano sem paciente (rascunho de primeira prescrição) e meta de energia gravada no plano.
        $planHasPatient = !empty($plan['patient_id']);
        $energyService = new \ClyoNutri\Domain\MealPlan\EnergyTargetService($db);
        $energyTarget = $plan['energy_target'] ?? null;
        $energySuggest = ($isDraft && $planHasPatient) ? $energyService->suggestInputs($planId) : ['sex' => null, 'age_years' => null, 'weight_kg' => null, 'height_cm' => null, 'ffm_kg' => null, 'source_text' => ''];
        $energyCompare = $energyTarget ? $energyService->compare($plan) : null;
        $energyOld = is_array($_SESSION['energy_form_old'] ?? null) ? $_SESSION['energy_form_old'] : [];
        unset($_SESSION['energy_form_old']);
        $assignablePatients = [];
        if ($isDraft && !$planHasPatient) {
            $apStmt = $db->prepare("SELECT id, name FROM patients WHERE clinic_id = :c AND status = 'active' ORDER BY name ASC LIMIT 500");
            $apStmt->execute(['c' => TenantContext::getClinicId()]);
            $assignablePatients = $apStmt->fetchAll();
        }

        // NI2-600: Contagem de versões publicadas e verificação de itens no plano para ações contextuais
        $diffService = new \ClyoNutri\Domain\MealPlan\MealPlanVersionDiffService($db);
        $publishedVersionsCount = $planHasPatient ? count($diffService->listPublishedVersions((int)$plan['patient_id'])) : 0;
        $hasPlanItems = false;
        if (!empty($plan['meals'])) {
            foreach ($plan['meals'] as $m) {
                if (!empty($m['items'])) {
                    $hasPlanItems = true;
                    break;
                }
            }
        }

        ob_start();
        ?>
        <?php if ($planHasPatient): ?><?= \ClyoNutri\Application\View\PatientHeader::render(Connection::getInstance(), (int)$plan['patient_id'], 'plano') // NI2-629/661 ?><?php endif; ?>
        <div class="u-flex u-justify-between u-items-start u-mb-6 u-wrap u-gap-4">
          <div>
            <div class="u-flex u-items-center u-gap-2 u-mb-1">
              <a href="/planos" class="u-no-underline u-color-neutral-gray-600 u-text-compact">← Voltar para lista</a>
              <span class="badge ds-pill <?= $isDraft ? 'ds-pill--warning' : 'ds-pill--success' ?>">
                <?= $isDraft ? 'Salvo como rascunho' : 'PUBLICADO (versão ' . (int)$plan['version'] . ', somente leitura)' ?>
              </span>
            </div>
            <?php if ($planHasPatient): ?><p><a class="btn btn-secondary btn-sm" href="/planos/<?= (int)$planId ?>/sugestoes-dieteticas">Sugestões por comorbidade</a></p><?php endif; ?>
            <?php if ($planHasPatient): ?><h2 class="patient-page-title"><?= htmlspecialchars($plan['title']) ?></h2><?php else: ?><h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-m-0"><?= htmlspecialchars($plan['title']) ?></h1><?php endif; ?>
            <p class="u-color-neutral-gray-500 u-mt-1">Paciente: <?= $planHasPatient ? '<strong>' . htmlspecialchars((string)$plan['patient_name']) . '</strong>' : '<strong data-testid="plan-patient-pending">a definir</strong>' ?> | Responsável: <?= htmlspecialchars($plan['professional_name']) ?></p>
          </div>
          <div class="u-flex u-gap-2 u-items-center u-wrap">
            <?php if ($hasPlanItems || !$isDraft): ?>
            <a href="/planos/<?= (int)$planId ?>/imprimir" target="_blank" rel="noopener" class="btn btn-outline u-text-compact">Imprimir plano</a>
            <?php else: ?>
            <span class="btn btn-outline u-text-compact u-disabled" aria-disabled="true" data-testid="plan-print-disabled" title="Adicione alimentos para imprimir">Imprimir plano</span>
            <?php endif; ?>

            <details class="plan-actions-dropdown u-inline-block u-relative">
              <summary class="btn btn-outline u-text-compact u-pointer u-list-none" data-testid="plan-more-actions">Mais ações ▾</summary>
              <div class="plan-actions-menu ds-menu-panel ds-menu-panel--wide">
                <?php if ($planHasPatient): ?>
                  <a class="plan-menu-item u-minh-touch u-flex u-items-center u-py-2 u-px-4 u-text-compact u-color-neutral-ink-900 u-no-underline" href="/pacientes/<?= (int)$plan['patient_id'] ?>/prescricoes?plano=<?= (int)$planId ?>">Manipulados e suplementação</a>
                <?php endif; ?>
                <?php if ($planHasPatient && $publishedVersionsCount >= 2): ?>
                  <a href="/pacientes/<?= (int)$plan['patient_id'] ?>/planos/comparar" class="plan-menu-item u-minh-touch u-flex u-items-center u-gap-2 u-py-2 u-px-4 u-text-compact u-color-neutral-ink-900 u-no-underline" data-testid="compare-plan-versions-link">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="8 21 3 21 3 16"/><line x1="20" y1="4" x2="3" y2="21"/></svg>
                    <span>Comparar versões publicadas</span>
                  </a>
                <?php endif; ?>
                <?php if ($hasPlanItems): ?>
                  <a href="/planos/<?= (int)$planId ?>/lista-compras" target="_blank" rel="noopener" class="plan-menu-item u-minh-touch u-flex u-items-center u-gap-2 u-py-2 u-px-4 u-text-compact u-color-neutral-ink-900 u-no-underline">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    <span>Lista de compras</span>
                  </a>
                <?php endif; ?>
                <?php if ($canEditItems): ?>
                  <form method="POST" action="/planos/<?= $planId ?>/duplicar" data-confirm="Deseja criar um novo rascunho a partir deste plano?" class="u-m-0">
                    <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                    <button type="submit" class="plan-menu-item u-minh-touch u-flex u-items-center u-w-full u-text-left u-py-2 u-px-4 u-text-compact u-color-neutral-ink-900 u-bg-none u-border-0 u-pointer"><?= $isDraft ? 'Duplicar como Rascunho' : 'Criar nova versão para editar' ?></button>
                  </form>
                  <?php if (!empty($plan['meals'])): ?>
                    <button type="button" class="plan-menu-item u-minh-touch u-flex u-items-center u-w-full u-text-left u-py-2 u-px-4 u-text-compact u-color-neutral-ink-900 u-bg-none u-border-0 u-pointer" data-show="modal-save-template" data-testid="save-as-template">Salvar como modelo</button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </details>

            <?php if ($isDraft && $planHasPatient): ?>
              <button class="btn btn-primary" data-show="modal-publish-plan">
                Revisar e publicar
              </button>
            <?php elseif ($isDraft): ?>
              <button type="button" class="btn btn-primary" disabled aria-disabled="true" data-testid="publish-needs-patient" title="Atribua um paciente para publicar">Publicar (falta o paciente)</button>
            <?php endif; ?>
          </div>
        </div>

        <?php /* NI2-521 (v42): as áreas marcadas com data-live-swap são trocadas sem recarregar a página (plan-live.js). */ ?>
        <div id="plan-live-status" class="sr-only" aria-live="polite" data-testid="plan-live-status"></div>
        <div id="plan-live-top" data-live-swap>
        <?php if ($error): ?>
          <div class="op-message op-message--error u-mb-4" role="alert" data-plan-flash="error">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <?php if ($feedback): ?>
          <div class="op-message op-message--info u-mb-4" role="status" data-plan-flash="ok">
            <?= htmlspecialchars($feedback) ?>
          </div>
        <?php endif; ?>


        <?php if (!empty($clinicalSummary['draft_in_progress'])): ?>
        <p data-testid="plan-anamnesis-draft" class="op-message op-message--warning">Há anamnese em rascunho. O contexto clínico acima identifica a versão concluída vigente e as pendências; revise antes de publicar.</p>
        <?php endif; ?>

        <?php if (!$planHasPatient): ?>
          <div class="op-message op-message--info u-accent u-accent-botanical-copper-600 u-mb-4" role="status" data-testid="allergy-pending">
            Plano sem paciente: as alergias ainda não foram avaliadas. A ausência de informação não confirma ausência de alergias. Atribua um paciente para que a anamnese dele seja conferida antes da publicação.
            <?php if ($isDraft && $canEditItems): ?>
              <form method="POST" action="/planos/<?= (int)$planId ?>/paciente" data-testid="assign-patient-form" class="u-flex u-gap-2 u-items-end u-wrap u-mt-2">
                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                <div class="u-flex-1 u-minw-lg">
                  <label for="assign-patient-id" class="u-block u-font-semibold u-mb-1 u-text-compact">Atribuir paciente</label>
                  <select id="assign-patient-id" name="patient_id" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                    <option value="">Selecione o paciente...</option>
                    <?php foreach ($assignablePatients as $ap): ?>
                      <option value="<?= (int)$ap['id'] ?>"><?= htmlspecialchars($ap['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary">Atribuir ao plano</button>
              </form>
            <?php endif; ?>
          </div>
        <?php elseif ($clinicalSummary['allergy_pending']): ?>
          <div class="op-message op-message--info u-accent u-accent-botanical-copper-600 u-mb-4" role="status" data-testid="allergy-pending">
            Avaliação de alergias pendente<?= $clinicalSummary['is_draft'] ? ': a anamnese está em rascunho' : '' ?>. A ausência de informação não confirma ausência de alergias.
            <a href="/pacientes/<?= (int)$plan['patient_id'] ?>/anamnese">Revisar anamnese</a>
          </div>
        <?php endif; ?>

        <!-- Alertas de Segurança Clínica e Alérgenos -->
        <?php if (!empty($preview['allergen_safety']['has_conflict'])): ?>
          <div class="op-message op-message--critical" role="alert">
            <strong class="u-block u-mb-1">Atenção clínica: conflitos alergênicos detectados com a anamnese!</strong>
            <ul class="u-m-0 u-pl-5 u-text-compact">
              <?php foreach ($preview['allergen_safety']['conflicts'] as $conf): ?>
                <li>Alimento <strong><?= htmlspecialchars($conf['food_name']) ?></strong> possui grupo alergênico registrado: <em><?= htmlspecialchars(LabelMap::allergen($conf['allergen_group'])) ?></em>.</li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <?php if (!empty($preview['allergen_safety']['unverified_foods']) || !empty($preview['allergen_safety']['unmapped_allergy_text'])): ?>
          <div class="op-message op-message--info u-bg-botanical-copper-100 u-color-botanical-copper-700 u-p-4 u-radius-input u-mb-5 u-border u-bc-botanical-copper-100" role="status" data-testid="allergen-unverified">
            <strong class="u-block u-mb-1">Alergia registrada: confira os alimentos abaixo</strong>
            <?php if (!empty($preview['allergen_safety']['unmapped_allergy_text'])): ?>
              <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact">A alergia descrita na anamnese não corresponde a um grupo alergênico conhecido, então nenhum alimento pôde ser conferido automaticamente.</p>
            <?php endif; ?>
            <?php if (!empty($preview['allergen_safety']['unverified_foods'])): ?>
              <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact">Estes alimentos não têm informação de alérgenos no catálogo. A falta de informação não confirma que são seguros:</p>
              <ul class="u-m-0 u-pl-5 u-text-compact">
                <?php foreach ($preview['allergen_safety']['unverified_foods'] as $uf): ?>
                  <li><?= htmlspecialchars($uf['food_name']) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <p class="u-mt-1 u-mb-0 u-mx-0 u-text-compact">Para publicar, será preciso confirmar e justificar a prescrição.</p>
          </div>
        <?php endif; ?>
        <?php if (!empty($preview['estimated_weights'])): ?>
          <div role="note" data-testid="estimated-weights" class="u-bg-neutral-gray-50 u-color-neutral-gray-700 u-py-3 u-px-4 u-radius-input u-mb-5 u-border u-text-compact">
            <strong>Peso estimado em <?= count($preview['estimated_weights']) ?> item(ns):</strong> a medida caseira usada não está cadastrada para o alimento, então o cálculo usa um peso padrão (ex.: 1 unidade = 100 g). Prefira gramas ou uma medida cadastrada para o alimento.
          </div>
        <?php endif; ?>

        <!-- Dashboard de Totais Nutricionais Consolidados -->
        <?php
          // NI2-74: nutrientes sem análise na TACO (NA, *, em branco) entram como zero; o total avisa quais alimentos faltam.
          $unknownByNutrient = [];
          foreach ($plan['meals'] ?? [] as $um) {
              foreach ($um['items'] ?? [] as $ui) {
                  foreach (\ClyoNutri\Domain\Nutrition\NutritionCalculator::unknownNutrients($ui['food_nutrient_flags'] ?? null) as $un) {
                      $unknownByNutrient[$un][$ui['food_name']] = true;
                  }
              }
          }
          $unknownNote = static function (string $n) use ($unknownByNutrient): string {
              if (empty($unknownByNutrient[$n])) return '';
              $names = array_keys($unknownByNutrient[$n]);
              return '<div data-testid="unknown-' . $n . '" class="u-text-caption u-color-semantic-warning-strong u-mt-1" title="' . htmlspecialchars(implode(', ', $names), ENT_QUOTES) . '">Incompleto: ' . count($names) . (count($names) === 1 ? ' alimento sem análise' : ' alimentos sem análise') . '</div>';
          };
        ?>
        <div class="u-grid u-cols-auto-sm u-gap-4 u-mb-6">
          <div class="card u-p-4 u-text-center u-accent u-accent-semantic-info">
            <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold u-uppercase">Energia diária</div>
            <div class="u-text-h2 u-font-bold u-color-neutral-ink-900 u-mt-1"><?= number_format((float)$plan['daily_energy_kcal'], 0, ',', '.') ?> <span class="u-text-compact u-font-regular">kcal</span></div><?= $unknownNote('energy_kcal') ?>
          </div>
          <div class="card u-p-4 u-text-center u-accent u-accent-semantic-success">
            <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold u-uppercase">Carboidratos</div>
            <div class="u-text-h2 u-font-bold u-color-neutral-ink-900 u-mt-1"><?= number_format((float)$plan['carbohydrate_g'], 1, ',', '.') ?> <span class="u-text-compact u-font-regular">g</span></div><?= $unknownNote('carbohydrate_g') ?>
          </div>
          <div class="card u-p-4 u-text-center u-accent u-accent-semantic-error">
            <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold u-uppercase">Proteínas</div>
            <div class="u-text-h2 u-font-bold u-color-neutral-ink-900 u-mt-1"><?= number_format((float)$plan['protein_g'], 1, ',', '.') ?> <span class="u-text-compact u-font-regular">g</span></div><?= $unknownNote('protein_g') ?>
          </div>
          <div class="card u-p-4 u-text-center u-accent u-accent-semantic-warning">
            <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold u-uppercase">Lipídeos</div>
            <div class="u-text-h2 u-font-bold u-color-neutral-ink-900 u-mt-1"><?= number_format((float)$plan['lipid_g'], 1, ',', '.') ?> <span class="u-text-compact u-font-regular">g</span></div><?= $unknownNote('lipid_g') ?>
          </div>
          <div class="card u-p-4 u-text-center u-accent u-accent-semantic-info">
            <div class="u-text-compact u-color-neutral-gray-500 u-font-semibold u-uppercase">Fibras</div>
            <div class="u-text-h2 u-font-bold u-color-neutral-ink-900 u-mt-1"><?= number_format((float)$plan['fiber_g'], 1, ',', '.') ?> <span class="u-text-compact u-font-regular">g</span></div><?= $unknownNote('fiber_g') ?>
          </div>
        </div>

        <?php if ($unknownByNutrient): ?>
          <div role="note" data-testid="unknown-nutrients" class="op-message op-message--warning u-mt-0 u-mb-5 u-text-compact">
            <strong>Totais incompletos:</strong> a TACO não traz análise de
            <?= htmlspecialchars(implode('; ', array_map(static fn($n, $foods) => ['energy_kcal' => 'energia', 'protein_g' => 'proteína', 'carbohydrate_g' => 'carboidratos', 'lipid_g' => 'lipídios', 'fiber_g' => 'fibra'][$n] . ' para ' . implode(', ', array_keys($foods)), array_keys($unknownByNutrient), $unknownByNutrient))) ?>.
            Esses valores entram como zero.
          </div>
        <?php endif; ?>
        <?php if (!empty($plan['notes'])): ?>
          <div class="card u-p-4 u-mb-6 u-bg-neutral-gray-50">
            <strong class="u-color-botanical-forest-900 u-text-compact">Orientações gerais do plano:</strong>
            <p class="u-mt-1 u-mb-0 u-mx-0 u-color-neutral-gray-600 u-text-compact u-pre-line"><?= htmlspecialchars($plan['notes']) ?></p>
          </div>
        <?php endif; ?>

        </div>

        <?php (static function (array $v): void { extract($v, EXTR_SKIP); require __DIR__ . '/../../../views/meal_plans/_energy_card.php'; })(compact('planId', 'plan', 'isDraft', 'canEditItems', 'csrfToken', 'energyTarget', 'energySuggest', 'energyCompare', 'energyOld')); ?>

        <?php if ($isDraft && $canEditItems) require __DIR__ . '/../../../views/meal_plans/_quick_editor.php'; ?>
        <div id="plan-live-meals" data-live-swap>
        <?php
          // NI2-521 (v42): barra fixa com o total do plano e a meta gravada. Tolerância de adequação pendente de revisão clínica.
          // NI2-519 (v43): sem meta própria, a barra compara com a meta de referência do modelo (se o modelo trouxe uma).
          $refTarget = isset($plan['reference_target_json']) && $plan['reference_target_json'] !== null ? json_decode((string)$plan['reference_target_json'], true) : null;
          $refTarget = is_array($refTarget) && is_numeric($refTarget['vet_kcal'] ?? null) ? $refTarget : null;
          $usingRefTarget = !is_array($energyTarget) && $refTarget !== null;
          $planMethod = \ClyoNutri\Domain\MealPlan\MealPlanTemplateService::methodOf($plan['plan_method'] ?? null);
          $totalsBar = \ClyoNutri\Domain\MealPlan\PlanTotalsBar::build($plan, is_array($energyTarget) ? $energyTarget : $refTarget);
          $tbColor = ['ok' => 'ds-tone-ok', 'acima' => 'ds-tone-copper', 'abaixo' => 'ds-tone-copper', 'sem-meta' => 'ds-delta--flat'];
          $tbStatus = ['ok' => 'dentro da tolerância', 'acima' => 'acima', 'abaixo' => 'abaixo', 'sem-meta' => ''];
        ?>
        <?php if ($refTarget !== null): ?>
        <div class="card u-py-3 u-px-4 u-mb-3 u-bg-botanical-cream-50 u-border u-bc-botanical-sage-200 u-text-compact" data-testid="template-reference-target">
          <strong class="u-color-botanical-forest-900">Meta de referência do modelo</strong>
          <span class="tabular-nums"> · <?= number_format((float)$refTarget['vet_kcal'], 0, ',', '.') ?> kcal<?php foreach (['protein_g' => 'P', 'carbohydrate_g' => 'C', 'lipid_g' => 'G'] as $rk => $rl): ?><?php if (is_numeric($refTarget[$rk] ?? null)): ?> · <?= $rl ?> <?= number_format((float)$refTarget[$rk], 0, ',', '.') ?> g<?php endif; ?><?php endforeach; ?></span>
          <span class="u-block u-color-neutral-gray-600 u-mt-0"><?= htmlspecialchars((string)($refTarget['source'] ?? 'Referência do modelo.')) ?><?= $usingRefTarget ? ' A barra abaixo usa esta referência até você calcular a meta do paciente.' : ' A barra abaixo usa a meta calculada deste plano.' ?></span>
        </div>
        <?php endif; ?>
        <?php if ($planMethod !== 'alimentos'): ?>
        <p data-testid="plan-method-label" class="u-text-compact u-color-neutral-gray-600 u-mt-0 u-mb-2 u-mx-0">Método: <strong><?= htmlspecialchars(\ClyoNutri\Domain\MealPlan\MealPlanTemplateService::METHODS[$planMethod]) ?></strong><?= $planMethod === 'qualitativo' ? '. Os totais abaixo valem só para os alimentos quantificados; a orientação escrita de cada refeição é o que o paciente recebe.' : '. As trocas usam as regras de equivalência da clínica (Alimentos > Regras de equivalência).' ?></p>
        <?php endif; ?>
        <div id="plan-totals-bar" data-testid="plan-totals-bar" role="group" aria-label="Totais do plano em relação à meta" class="ds-sticky-summary u-flex u-wrap u-items-center u-gap-4 u-py-2 u-px-4 u-mb-4 u-text-compact u-shadow-sm<?= $planMethod === 'qualitativo' ? ' ds-is-muted' : '' ?>">
          <?php foreach ($totalsBar['rows'] as $tbKey => $tbRow): ?>
            <span data-testid="totals-bar-<?= htmlspecialchars($tbKey) ?>" data-status="<?= htmlspecialchars($tbRow['status']) ?>" class="tabular-nums">
              <strong class="u-color-botanical-forest-900"><?= htmlspecialchars($tbRow['label']) ?></strong>
              <?= htmlspecialchars($tbRow['text']) ?>
              <?php if ($tbStatus[$tbRow['status']] !== ''): ?><span class="u-font-semibold <?= $tbColor[$tbRow['status']] ?>"><?= htmlspecialchars($tbStatus[$tbRow['status']]) ?></span><?php endif; ?>
            </span>
          <?php endforeach; ?>
          <span data-testid="totals-bar-fiber" class="tabular-nums"><strong class="u-color-botanical-forest-900">Fibras</strong> <?= number_format((float)$totalsBar['fiber_g'], 1, ',', '.') ?> g</span>
          <?php if (!$totalsBar['has_target']): ?>
            <span class="u-color-neutral-gray-600 u-text-compact">Sem meta gravada: calcule em “Energia e metas do plano” para ver a adequação.</span>
          <?php elseif ($usingRefTarget): ?>
            <span data-testid="totals-bar-reference" class="u-color-neutral-gray-600 u-text-compact">Comparando com a meta de referência do modelo.</span>
          <?php endif; ?>
        </div>

        <!-- Refeições e Alimentos -->
        <div class="u-flex u-justify-between u-items-center u-mb-4">
          <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-m-0">Refeições do Dia</h2>
          <?php if ($isDraft): ?>
            <button class="btn btn-sm btn-outline" data-show="modal-add-meal">
              + Adicionar Refeição
            </button>
          <?php endif; ?>
        </div>

        <?php if (empty($plan['meals'])): ?>
          <div class="card u-p-8 u-text-center u-color-neutral-gray-500">
            <p class="u-mb-3">Nenhuma refeição adicionada a esta prescrição.</p>
            <?php if ($isDraft): ?>
              <button class="btn btn-primary" data-show="modal-add-meal">Adicionar primeira refeição</button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($plan['meals'] as $m): ?>
            <div class="card u-mb-5 u-overflow-hidden u-border">
              <!-- Cabeçalho da Refeição -->
              <div class="u-py-3 u-px-5 u-bg-neutral-gray-50 u-border-b u-flex u-justify-between u-items-center u-wrap u-gap-2">
                <div class="u-flex u-items-center u-gap-3">
                  <span class="u-font-bold u-text-card-title u-color-botanical-forest-900"><?= htmlspecialchars($m['name']) ?></span>
                  <?php if (!empty($m['time_of_day'])): ?>
                    <span class="u-bg-neutral-gray-200 u-color-neutral-gray-700 u-text-compact u-py-0 u-px-2 u-radius-input u-font-semibold u-inline-flex u-items-center u-gap-1"><?= \ClyoNutri\Application\View\Icon::svg('alarm-clock', 14) ?> <?= htmlspecialchars($m['time_of_day']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="u-flex u-items-center u-gap-3">
                  <span class="u-text-compact u-color-neutral-gray-500">
                    <strong><?= number_format((float)$m['totals']['energy_kcal'], 0, ',', '.') ?> kcal</strong> | P: <?= number_format((float)$m['totals']['protein_g'], 1, ',', '.') ?> g | C: <?= number_format((float)$m['totals']['carbohydrate_g'], 1, ',', '.') ?> g | G: <?= number_format((float)$m['totals']['lipid_g'], 1, ',', '.') ?> g
                  </span>
                  <?php if ($isDraft): ?>
                    <button class="btn btn-sm btn-outline u-py-1 u-px-2 u-text-compact" data-call="openAddItemModal" data-args="[<?= $m['id'] ?>, <?= htmlspecialchars(json_encode((string)$m['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]">+ Alimento</button>
                    <form method="POST" action="/planos/<?= $planId ?>/refeicoes/<?= $m['id'] ?>/remover" data-live-plan class="u-inline" data-confirm="Deseja excluir esta refeição e seus itens?">
                      <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                      <button type="submit" class="btn btn-sm u-color-semantic-error u-minw-touch u-minh-touch u-bg-none u-border-0 u-pointer u-text-compact">Excluir</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <?php if (!empty($m['notes'])): ?>
                <div class="u-py-2 u-px-5 u-bg-neutral-gray-100 u-text-compact u-color-neutral-gray-600 u-border-b">
                  <em>Orientações: <?= htmlspecialchars($m['notes']) ?></em>
                </div>
              <?php endif; ?>

              <?php if ($isDraft && $canEditItems): ?>
                <div class="u-py-2 u-px-5 u-bg-neutral-gray-50 u-border-b u-text-compact">
                  <details data-testid="edit-plan-meal-<?= (int)$m['id'] ?>">
                    <summary class="u-pointer u-color-botanical-forest-900 u-font-semibold">Editar refeição ou orientação</summary>
                    <form method="POST" action="/planos/<?= $planId ?>/refeicoes/<?= (int)$m['id'] ?>/editar" class="u-mt-2" data-busy-text="Salvando…" data-live-plan>
                      <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                      <div class="u-grid u-cols-auto-sm u-gap-2 u-mb-2">
                        <div class="form-group u-m-0">
                          <label class="form-label" for="meal-name-<?= (int)$m['id'] ?>">Nome da refeição</label>
                          <input class="form-control" id="meal-name-<?= (int)$m['id'] ?>" name="name" type="text" required value="<?= htmlspecialchars((string)$m['name'], ENT_QUOTES) ?>">
                        </div>
                        <div class="form-group u-m-0">
                          <label class="form-label" for="meal-time-<?= (int)$m['id'] ?>">Horário (HH:MM)</label>
                          <input class="form-control" id="meal-time-<?= (int)$m['id'] ?>" name="time_of_day" type="text" pattern="^([01]\d|2[0-3]):[0-5]\d$" placeholder="Ex: 08:00" value="<?= htmlspecialchars($m['time_of_day'] !== null ? substr((string)$m['time_of_day'], 0, 5) : '', ENT_QUOTES) ?>">
                        </div>
                      </div>
                      <div class="form-group u-mb-2">
                        <label class="form-label" for="meal-notes-<?= (int)$m['id'] ?>">Orientações da refeição</label>
                        <textarea class="form-control" id="meal-notes-<?= (int)$m['id'] ?>" name="notes" rows="2" placeholder="Orientações gerais ou descrição qualitativa da refeição"><?= htmlspecialchars((string)($m['notes'] ?? ''), ENT_QUOTES) ?></textarea>
                      </div>
                      <button type="submit" class="btn btn-sm btn-primary">Salvar refeição</button>
                      <button type="button" class="btn btn-sm btn-secondary" data-reset-close-details>Cancelar</button>
                    </form>
                  </details>
                </div>
              <?php endif; ?>

              <!-- Lista de Itens -->
              <div class="u-p-0">
                <?php if (empty($m['items'])): ?>
                  <div class="u-p-5 u-text-center u-color-neutral-gray-500 u-text-compact">
                    Nenhum alimento cadastrado nesta refeição.
                  </div>
                <?php else: ?>
                  <table class="meal-plan-items u-w-full u-collapse u-text-compact">
                     <tbody>
                      <?php foreach ($m['items'] as $it): ?>
                        <tr data-plan-item="<?= (int)$it['id'] ?>" class="u-border-b u-bc-neutral-gray-100">
                          <td class="u-py-3 u-px-5 u-w-40">
                            <strong class="u-color-botanical-forest-900"><?= htmlspecialchars($it['food_name']) ?></strong>
                            <?php if (!empty($it['notes'])): ?>
                              <span class="u-block u-text-compact u-color-neutral-gray-500"><?= htmlspecialchars($it['notes']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($it['allergen_alert']['requires_attention'])): ?>
                              <span data-testid="item-allergen-badge" class="ds-pill u-mt-1 <?= ($it['allergen_alert']['status'] ?? '') === 'conflict' ? 'ds-pill--error ds-pill--critical' : 'ds-pill--warning' ?>" data-severity="<?= ($it['allergen_alert']['status'] ?? '') === 'conflict' ? 'critical' : 'warning' ?>">
                                <?= htmlspecialchars((string)($it['allergen_alert']['message'] ?? 'Atenção: verifique alérgenos')) ?>
                              </span>
                            <?php endif; ?>
                            <?php if (!empty($it['allergen_ack_reason'])): ?>
                              <span data-testid="item-allergen-ack" class="u-block u-text-caption u-color-semantic-warning-strong u-mt-0">Justificativa clínica: <?= htmlspecialchars((string)$it['allergen_ack_reason']) ?></span>
                            <?php endif; ?>
                          </td>
                          <td class="u-py-3 u-px-5 u-font-semibold u-color-neutral-gray-700 u-w-20">
                            <?= \ClyoNutri\Domain\Nutrition\QuantityFormatter::amount($it['amount']) ?> <?= htmlspecialchars($it['measure_unit']) ?>
                            <?php
                              $itUnit = trim((string)$it['measure_unit']);
                              $itIsG = (bool)preg_match('/^(g|gramas?)$/i', $itUnit);
                              $itGrams = null;
                              if (!$itIsG) {
                                  if (isset($it['weight_grams']) && (float)$it['weight_grams'] > 0) {
                                      $itGrams = round((float)$it['weight_grams']);
                                  } elseif (preg_match('/\((\d+(?:[.,]\d+)?)\s*g\)/i', $itUnit, $gm)) {
                                      $singleG = (float)str_replace(',', '.', $gm[1]);
                                      if ((float)$it['amount'] != 1.0) {
                                          $itGrams = round((float)$it['amount'] * $singleG);
                                      }
                                  }
                              }
                            ?>
                            <?php if ($itGrams !== null): ?>
                              <span class="u-block u-text-compact u-font-regular u-color-neutral-gray-500 u-mt-0"<?= !empty($it['weight_is_estimated']) ? ' title="Medida caseira genérica: não há medida cadastrada para este alimento."' : '' ?>>(~<?= number_format($itGrams, 0, ',', '.') ?>g<?= !empty($it['weight_is_estimated']) ? ', peso estimado' : '' ?>)</span>
                            <?php endif; ?>
                          </td>
                          <td class="u-py-3 u-px-5 u-color-neutral-gray-500 u-w-25 u-text-compact">
                            <?= number_format((float)$it['energy_kcal'], 0, ',', '.') ?> kcal <span class="u-text-caption">(P:<?= number_format((float)$it['protein_g'], 1, ',', '.') ?> C:<?= number_format((float)$it['carbohydrate_g'], 1, ',', '.') ?> G:<?= number_format((float)$it['lipid_g'], 1, ',', '.') ?>)</span>
                          </td>
                          <td class="u-py-3 u-px-5 u-text-right u-w-15">
                            <?php if ($isDraft): ?>
                              <button class="btn btn-sm btn-outline u-py-0 u-px-1 u-text-caption u-mr-1" data-call="openAddSubModal" data-args="[<?= $it['id'] ?>, <?= htmlspecialchars(json_encode((string)$it['food_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(round((float)$it['energy_kcal'], 1)), ENT_QUOTES) ?>]">
                                + Substituição
                              </button>
                              <form method="POST" action="/planos/<?= $planId ?>/itens/<?= $it['id'] ?>/remover" class="u-inline" data-confirm="Remover este alimento?" data-live-plan>
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="u-color-semantic-error u-minw-touch u-minh-touch u-bg-none u-border-0 u-pointer u-text-compact">✕</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>

                        <?php if ($isDraft && $canEditItems): ?>
                        <tr><td colspan="4" class="u-py-3 u-px-4">
                          <details data-testid="edit-plan-item-<?= (int)$it['id'] ?>">
                            <summary class="u-pointer u-color-botanical-forest-900 u-font-semibold">Editar porção e orientação</summary>
                            <form method="POST" action="/planos/<?= $planId ?>/itens/<?= (int)$it['id'] ?>/editar" class="u-mt-3" data-busy-text="Salvando…" data-live-plan data-unit-scope data-food-id="<?= (int)$it['food_id'] ?>">
                              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                              <input type="hidden" name="revision" value="<?= MealPlanService::itemRevision($it) ?>">
                              <div class="u-grid u-cols-auto-sm u-gap-3">
                                <div class="form-group"><label class="form-label" for="item-amount-<?= (int)$it['id'] ?>">Quantidade</label><input class="form-control" id="item-amount-<?= (int)$it['id'] ?>" name="amount" type="number" min="0.01" step="0.01" required value="<?= htmlspecialchars((string)(float)$it['amount'], ENT_QUOTES) ?>"></div>
                                <div class="form-group"><label class="form-label" for="item-unit-<?= (int)$it['id'] ?>">Unidade ou medida caseira</label><input class="form-control" id="item-unit-<?= (int)$it['id'] ?>" name="measure_unit" required value="<?= htmlspecialchars($it['measure_unit'], ENT_QUOTES) ?>"></div>
                              </div>
                              <div class="form-group"><label class="form-label" for="item-notes-<?= (int)$it['id'] ?>">Orientação</label><textarea class="form-control" id="item-notes-<?= (int)$it['id'] ?>" name="notes"><?= htmlspecialchars($it['notes'] ?? '', ENT_QUOTES) ?></textarea></div>
                              <?= UnitCheckView::block('item-confirm-' . (int)$it['id']) ?>
                              <button type="submit" class="btn btn-primary">Salvar alterações</button>
                              <button type="button" class="btn btn-secondary" data-reset-close-details>Cancelar</button>
                            </form>
                          </details>
                        </td></tr>
                        <?php endif; ?>

                        <!-- Substituições Vinculadas (Opções Alternativas - Não Cumulativas) -->
                        <?php if (!empty($it['substitutions'])): ?>
                          <?php foreach ($it['substitutions'] as $sub): ?>
                            <tr class="u-bg-neutral-gray-50 u-border-b u-border-dashed">
                              <td class="u-pt-2 u-pr-5 u-pb-2 u-pl-10">
                                <div class="u-flex u-items-center u-gap-1">
                                  <span class="u-color-semantic-warning-strong u-text-compact u-font-semibold">↳ Opção alternativa:</span>
                                  <span class="u-color-neutral-gray-700"><?= htmlspecialchars($sub['food_name']) ?></span>
                                </div>
                                <?php if (!empty($sub['notes'])): ?>
                                  <span class="u-block u-text-caption u-color-neutral-gray-500 u-ml-5"><?= htmlspecialchars($sub['notes']) ?></span>
                                <?php endif; ?>
                              </td>
                              <td class="u-py-2 u-px-5 u-text-compact u-color-neutral-gray-700">
                                <?= \ClyoNutri\Domain\Nutrition\QuantityFormatter::amount($sub['amount']) ?> <?= htmlspecialchars($sub['measure_unit']) ?>
                                <?php
                                  $subUnit = trim((string)$sub['measure_unit']);
                                  $subIsG = (bool)preg_match('/^(g|gramas?)$/i', $subUnit);
                                  $subGrams = null;
                                  if (!$subIsG) {
                                      if (isset($sub['weight_grams']) && (float)$sub['weight_grams'] > 0) {
                                          $subGrams = round((float)$sub['weight_grams']);
                                      } elseif (preg_match('/\((\d+(?:[.,]\d+)?)\s*g\)/i', $subUnit, $gm)) {
                                          $singleG = (float)str_replace(',', '.', $gm[1]);
                                          if ((float)$sub['amount'] != 1.0) {
                                              $subGrams = round((float)$sub['amount'] * $singleG);
                                          }
                                      }
                                  }
                                ?>
                                <?php if ($subGrams !== null): ?>
                                  <span class="u-block u-text-caption u-color-neutral-gray-500 u-mt-0"<?= !empty($sub['weight_is_estimated']) ? ' title="Medida caseira genérica: não há medida cadastrada para este alimento."' : '' ?>>(~<?= number_format($subGrams, 0, ',', '.') ?>g<?= !empty($sub['weight_is_estimated']) ? ', peso estimado' : '' ?>)</span>
                                <?php endif; ?>
                              </td>
                              <td class="u-py-2 u-px-5 u-text-compact u-color-neutral-gray-500">
                                <?= number_format((float)$sub['energy_kcal'], 0, ',', '.') ?> kcal (P:<?= number_format((float)$sub['protein_g'], 1, ',', '.') ?> C:<?= number_format((float)$sub['carbohydrate_g'], 1, ',', '.') ?> G:<?= number_format((float)$sub['lipid_g'], 1, ',', '.') ?>)
                                <?php $subDelta = \ClyoNutri\Domain\MealPlan\SubstitutionComparison::label(\ClyoNutri\Domain\MealPlan\SubstitutionComparison::delta($it, $sub)); ?>
                                <?php if ($subDelta !== ''): ?>
                                  <span data-testid="sub-delta" class="u-block u-text-caption u-color-neutral-gray-600 u-mt-0" title="Diferença em relação ao alimento principal desta linha.">Em relação ao principal: <?= htmlspecialchars($subDelta) ?></span>
                                <?php endif; ?>
                                <?php
                                  // NI2-519/520 (v43): veredito só com regra da clínica; sem regra, nunca diz "equivalente".
                                  $eqSvc ??= new \ClyoNutri\Domain\Food\FoodEquivalenceService($db);
                                  $eqV = $eqSvc->evaluate(['category' => $it['food_category'] ?? null] + $it, ['category' => $sub['food_category'] ?? null] + $sub);
                                  $eqColor = ['equivalente' => 'ds-tone-ok', 'fora_da_tolerancia' => 'ds-tone-copper', 'grupos_diferentes' => 'ds-tone-copper', 'sem_regra' => 'ds-delta--flat'][$eqV['verdict']];
                                ?>
                                <span data-testid="sub-equivalence" data-verdict="<?= htmlspecialchars($eqV['verdict']) ?>" class="u-block u-text-caption u-font-semibold <?= $eqColor ?>">
                                  <?= htmlspecialchars($eqV['label']) ?><?php if ($eqV['rule'] !== null): ?> (<?= htmlspecialchars($eqV['rule']['name']) ?><?= $eqV['energy_diff_pct'] !== null ? ', energia ' . number_format((float)$eqV['energy_diff_pct'], 1, ',', '.') . '% de diferença' : '' ?>)<?php if ($eqV['review_pending']): ?> · regra pendente de revisão<?php endif; ?><?php else: ?>: a clínica não tem regra para <?= htmlspecialchars($eqV['item_group'] ?? 'este grupo') ?><?php endif; ?>
                                </span>
                              </td>
                              <td class="u-py-2 u-px-5 u-text-right">
                                <?php if ($isDraft && $canEditItems): ?>
                                  <details data-testid="edit-plan-sub-<?= (int)$sub['id'] ?>" class="u-inline-block u-text-left u-mr-2">
                                    <summary class="u-pointer u-color-botanical-forest-900 u-text-caption u-font-semibold">Editar</summary>
                                    <form method="POST" action="/planos/<?= $planId ?>/substituicoes/<?= (int)$sub['id'] ?>/editar" class="u-mt-2 u-p-2 u-bg-neutral-white u-border u-radius-input u-minw-lg" data-busy-text="Salvando…" data-live-plan data-unit-scope data-food-id="<?= (int)$sub['food_id'] ?>">
                                      <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                      <div class="u-grid u-cols-2 u-gap-2 u-mb-1">
                                        <div>
                                          <label class="form-label u-text-caption" for="sub-amount-<?= (int)$sub['id'] ?>">Quantidade</label>
                                          <input class="form-control" id="sub-amount-<?= (int)$sub['id'] ?>" name="amount" type="number" min="0.01" step="0.01" required value="<?= htmlspecialchars((string)(float)$sub['amount'], ENT_QUOTES) ?>">
                                        </div>
                                        <div>
                                          <label class="form-label u-text-caption" for="sub-unit-<?= (int)$sub['id'] ?>">Unidade</label>
                                          <input class="form-control" id="sub-unit-<?= (int)$sub['id'] ?>" name="measure_unit" required value="<?= htmlspecialchars((string)$sub['measure_unit'], ENT_QUOTES) ?>">
                                        </div>
                                      </div>
                                      <div class="u-mb-1">
                                        <label class="form-label u-text-caption" for="sub-notes-<?= (int)$sub['id'] ?>">Orientação</label>
                                        <input class="form-control" id="sub-notes-<?= (int)$sub['id'] ?>" name="notes" type="text" value="<?= htmlspecialchars((string)($sub['notes'] ?? ''), ENT_QUOTES) ?>">
                                      </div>
                                      <?= UnitCheckView::block('sub-confirm-' . (int)$sub['id']) ?>
                                      <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                                      <button type="button" class="btn btn-sm btn-secondary" data-reset-close-details>Cancelar</button>
                                    </form>
                                  </details>
                                <?php endif; ?>
                                <?php if ($isDraft): ?>
                                  <form method="POST" action="/planos/<?= $planId ?>/substituicoes/<?= $sub['id'] ?>/remover" class="u-inline" data-confirm="Remover substituição?" data-live-plan>
                                    <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                    <button type="submit" class="u-color-neutral-gray-600 u-bg-none u-border-0 u-pointer u-text-caption">Remover</button>
                                  </form>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>

                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- Modal Adicionar Refeição -->
        <div id="modal-add-meal" data-dialog="modal" class="modal-overlay">
          <div class="cn-dialog__panel u-bg-neutral-white u-radius-card u-w-full u-maxw-form u-p-6 u-shadow-lg">
            <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-4">Adicionar refeição</h2>
            <form method="POST" action="/planos/<?= $planId ?>/refeicoes" data-live-plan>
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <?php
                // NI2-444: uma lista só alimenta os chips e as sugestões do campo (datalist).
                $quickMeals = [
                    ['Café da Manhã', '07:30', 'coffee'], ['Colação', '10:00', 'apple'], ['Almoço', '12:30', 'utensils'],
                    ['Lanche da Tarde', '16:00', 'sandwich'], ['Pré-Treino', '17:30', 'zap'], ['Pós-Treino', '19:00', 'dumbbell'],
                    ['Jantar', '20:00', 'soup'], ['Ceia', '22:00', 'moon'],
                ];
              ?>
              <?php /* Sugestões rápidas pré-selecionáveis (NI2-444) */ ?>
              <div class="u-mb-4">
                <p id="quick-meal-label" class="u-font-semibold u-mt-0 u-mb-1 u-mx-0 u-text-compact u-color-neutral-gray-700">Sugestões rápidas (clique para preencher):</p>
                <div role="group" aria-labelledby="quick-meal-label" class="u-flex u-wrap u-gap-1" id="quick-meal-suggestions">
                  <?php foreach ($quickMeals as [$qmName, $qmTime, $qmIcon]): ?>
                  <button type="button" class="btn btn-sm btn-outline quick-meal-btn u-py-1 u-px-2 u-text-compact u-radius-modal" data-meal="<?= htmlspecialchars($qmName, ENT_QUOTES) ?>" data-time="<?= $qmTime ?>"><?= \ClyoNutri\Application\View\Icon::svg($qmIcon, 14) ?> <?= htmlspecialchars($qmName) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="u-mb-4">
                <label for="dc19-name" class="u-block u-font-semibold u-mb-1 u-text-compact">Nome da Refeição *</label>
                <input id="dc19-name" type="text" name="name" required list="common-meals-list" placeholder="Ex: Café da Manhã, Almoço, Ceia ou digite uma personalizada" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                <datalist id="common-meals-list">
                  <?php foreach ($quickMeals as [$qmName]): ?><option value="<?= htmlspecialchars($qmName, ENT_QUOTES) ?>"><?php endforeach; ?>
                </datalist>
                <small class="u-color-neutral-gray-500 u-text-caption u-block u-mt-1">Você pode escolher uma sugestão acima ou digitar qualquer nome personalizado livremente.</small>
              </div>
              <div class="u-mb-4">
                <label for="dc20-time_of_day" class="u-block u-font-semibold u-mb-1 u-text-compact">Horário Sugerido (HH:MM)</label>
                <input id="dc20-time_of_day" type="text" name="time_of_day" placeholder="Ex: 08:00" pattern="^([01]\d|2[0-3]):[0-5]\d$" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              </div>
              <div class="u-mb-5">
                <label for="dc21-notes" class="u-block u-font-semibold u-mb-1 u-text-compact">Orientações clínicas da refeição</label>
                <textarea id="dc21-notes" name="notes" rows="2" placeholder="Ex: Ingerir 30 min antes do treino, mastigar devagar..." class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
              </div>
              <div class="u-flex u-justify-end u-gap-2">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-add-meal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar refeição</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Adicionar Alimento -->
        <div id="modal-add-item" data-dialog="modal" class="food-dialog-overlay modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-add-item-title">
          <div class="cn-dialog__panel food-dialog-panel">
            <h2 id="modal-add-item-title" class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-1">Adicionar alimento</h2>
            <p id="add-item-meal-name" class="u-color-neutral-gray-500 u-text-compact u-mb-4"></p>
            <?php if($planHasPatient): ?><?= \ClyoNutri\Application\View\ClinicalContext::renderData(Connection::getInstance(),$planClinicalContext??=\ClyoNutri\Application\View\ClinicalContext::read(Connection::getInstance(),(int)$plan['patient_id'])) ?><?php endif; ?>
            <form method="POST" action="/planos/<?= $planId ?>/itens" data-unit-scope data-live-plan>
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="meal_id" id="add-item-meal-id" value="">
              <div class="u-mb-4">
                <div data-food-picker data-plan-id="<?= (int)$planId ?>">
                <label class="form-label" for="item-food-search">Buscar alimento</label>
                <input class="form-control" id="item-food-search" data-food-query type="search" maxlength="80" placeholder="Ex.: ovo, arroz integral, banana">
                <p class="op-message op-message--info" role="status" aria-live="polite">Busque e selecione um alimento para definir a porção.</p>
                <label class="form-label" data-food-results-label for="item-food-select">Resultados da busca</label>
                <select id="item-food-select" name="food_id" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <option value="">Selecione o alimento...</option>
                  <?php foreach ($foodsCatalog as $fc): ?>
                    <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['name']) ?> (<?= number_format((float)$fc['energy_kcal'], 0, ',', '.') ?> kcal/100g)</option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary" data-food-next hidden>Mais resultados</button>
                <button type="button" class="btn btn-secondary" data-food-retry hidden>Tentar busca novamente</button>
                </div>
                <div data-allergen-item-alert data-testid="allergen-item-alert" hidden class="op-message op-message--warning u-mt-2 u-text-compact"></div>
              </div>
              <div class="u-grid u-cols-2 u-gap-3 u-mb-4">
                <div>
                  <label for="dc22-amount" class="u-block u-font-semibold u-mb-1 u-text-compact">Quantidade *</label>
                  <input id="dc22-amount" type="number" step="0.01" min="0.01" name="amount" required placeholder="Ex: 100" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                </div>
                <div>
                  <label for="dc23-measure_unit" class="u-block u-font-semibold u-mb-1 u-text-compact">Unidade de Medida *</label>
                  <select id="dc23-measure_unit" name="measure_unit" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                    <?= UnitCheckView::unitOptions() ?>
                  </select>
                </div>
              </div>
              <?= UnitCheckView::block('item-confirm-estimated') ?>
              <div class="u-mb-5">
                <label for="dc24-notes" class="u-block u-font-semibold u-mb-1 u-text-compact">Observação / orientação do item</label>
                <input id="dc24-notes" type="text" name="notes" placeholder="Ex: Cozido sem sal, grelhado" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              </div>
              <div class="u-flex u-justify-end u-gap-2">
                <button type="button" class="btn btn-outline" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Adicionar ao Plano</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Adicionar Substituição -->
        <div id="modal-add-sub" data-dialog="modal" class="food-dialog-overlay modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-add-sub-title">
          <div class="cn-dialog__panel food-dialog-panel">
            <h2 id="modal-add-sub-title" class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-1">Adicionar substituição</h2>
            <p id="add-sub-item-name" class="u-color-neutral-gray-500 u-text-compact u-mb-4"></p>
            <?php if($planHasPatient): ?><?= \ClyoNutri\Application\View\ClinicalContext::renderData(Connection::getInstance(),$planClinicalContext??=\ClyoNutri\Application\View\ClinicalContext::read(Connection::getInstance(),(int)$plan['patient_id'])) ?><?php endif; ?>
            <form method="POST" action="/planos/<?= $planId ?>/substituicoes" data-unit-scope data-live-plan>
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="item_id" id="add-sub-item-id" value="">
              <div class="eq-suggestions" id="add-sub-suggestions" data-testid="eq-suggestions" aria-live="polite" hidden></div>
              <div class="u-mb-4">
                <div data-food-picker data-plan-id="<?= (int)$planId ?>">
                <label class="form-label" for="sub-food-search">Buscar alimento</label>
                <input class="form-control" id="sub-food-search" data-food-query type="search" maxlength="80" placeholder="Ex.: ovo, arroz integral, banana">
                <p class="op-message op-message--info" role="status" aria-live="polite">Busque e selecione um alimento para definir a porção.</p>
                <label class="form-label" data-food-results-label for="sub-food-select">Resultados da busca</label>
                <select id="sub-food-select" name="food_id" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                  <option value="">Selecione o alimento alternativo...</option>
                  <?php foreach ($foodsCatalog as $fc): ?>
                    <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary" data-food-next hidden>Mais resultados</button>
                <button type="button" class="btn btn-secondary" data-food-retry hidden>Tentar busca novamente</button>
                </div>
                <div data-allergen-item-alert data-testid="allergen-sub-alert" hidden class="op-message op-message--warning u-mt-2 u-text-compact"></div>
              </div>
              <div class="u-grid u-cols-2 u-gap-3 u-mb-4">
                <div>
                  <label for="dc25-amount" class="u-block u-font-semibold u-mb-1 u-text-compact">Quantidade equivalente *</label>
                  <input id="dc25-amount" type="number" step="0.01" min="0.01" name="amount" required placeholder="Ex: 80" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                </div>
                <div>
                  <label for="dc26-measure_unit" class="u-block u-font-semibold u-mb-1 u-text-compact">Unidade de Medida *</label>
                  <select id="dc26-measure_unit" name="measure_unit" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
                    <?= UnitCheckView::unitOptions() ?>
                  </select>
                </div>
              </div>
              <div class="same-energy-hint" data-same-energy-hint data-testid="same-energy-hint" hidden>
                <p data-same-energy-text class="u-mt-0 u-mb-1 u-mx-0"></p>
                <button type="button" class="btn btn-sm btn-outline" data-same-energy-fill>Preencher esta quantidade em gramas</button>
              </div>
              <?= UnitCheckView::block('sub-confirm-estimated') ?>
              <div class="u-mb-5">
                <label for="dc27-notes" class="u-block u-font-semibold u-mb-1 u-text-compact">Orientação da Substituição</label>
                <input id="dc27-notes" type="text" name="notes" placeholder="Ex: Em dias de treino, consumir cozido" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              </div>
              <div class="u-flex u-justify-end u-gap-2">
                <button type="button" class="btn btn-outline" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar opção</button>
              </div>
            </form>
          </div>
        </div>

        <?php if ($canEditItems && !empty($plan['meals'])): ?>
        <?php /* NI2-519 (v42): salvar a estrutura deste plano como modelo da clínica */ ?>
        <div id="modal-save-template" data-dialog="modal" class="modal-overlay">
          <div class="cn-dialog__panel u-bg-neutral-white u-radius-card u-w-full u-maxw-form u-p-6 u-shadow-lg">
            <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-2">Salvar como modelo</h2>
            <p class="u-color-neutral-gray-600 u-text-compact u-leading-body u-mb-4">O modelo guarda refeições, horários, alimentos, quantidades, substituições e o método do plano. Paciente, anamnese e dados de cálculo da meta (peso, altura, fórmula) não vão para o modelo. Este plano não muda.</p>
            <form method="POST" action="/planos/<?= (int)$planId ?>/salvar-como-modelo" data-busy-text="Salvando modelo..." data-testid="save-template-form">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <div class="u-mb-3">
                <label for="tpl-title" class="u-block u-font-semibold u-mb-1 u-text-compact">Nome do modelo *</label>
                <input id="tpl-title" name="template_title" required maxlength="255" value="<?= htmlspecialchars((string)$plan['title'], ENT_QUOTES) ?>" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              </div>
              <div class="u-mb-3">
                <label for="tpl-category" class="u-block u-font-semibold u-mb-1 u-text-compact">Categoria</label>
                <input id="tpl-category" name="template_category" maxlength="100" placeholder="Ex.: Emagrecimento, Gestante, Vegetariano" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              </div>
              <div class="u-mb-3">
                <label for="tpl-description" class="u-block u-font-semibold u-mb-1 u-text-compact">Descrição para a equipe</label>
                <textarea id="tpl-description" name="template_description" rows="2" maxlength="1000" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
              </div>
              <label class="u-flex u-gap-2 u-items-start u-text-compact u-mb-4">
                <input type="checkbox" name="include_notes" value="1">
                <span>Levar também as orientações das refeições e dos alimentos. Revise antes: elas não podem citar dados deste paciente.</span>
              </label>
              <?php if (!empty($plan['energy_target'])): ?>
              <label class="u-flex u-gap-2 u-items-start u-text-compact u-mb-4 ds-hint-tight">
                <input type="checkbox" name="include_target" value="1" checked data-testid="tpl-include-target">
                <span>Levar a meta como referência (só kcal e macros totais). Quem usar o modelo recalcula para o próprio paciente.</span>
              </label>
              <?php endif; ?>
              <fieldset class="u-mt-0 u-mb-4 u-mx-0 u-border u-bc-botanical-sage-200 u-radius-input u-py-2 u-px-3" data-testid="tpl-visibility">
                <legend class="u-font-semibold u-text-compact u-py-0 u-px-1">Quem vê este modelo</legend>
                <label class="u-flex u-gap-2 u-items-start u-text-compact u-my-1 u-mx-0"><input type="radio" name="visibility" value="clinica" checked class="u-mt-1"><span><strong>Equipe da clínica</strong> · qualquer nutricionista da clínica pode usar.</span></label>
                <label class="u-flex u-gap-2 u-items-start u-text-compact u-my-1 u-mx-0"><input type="radio" name="visibility" value="pessoal" class="u-mt-1"><span><strong>Só eu</strong> · modelo pessoal, fora da lista da equipe.</span></label>
              </fieldset>
              <div class="u-flex u-justify-end u-gap-2">
                <button type="button" class="btn btn-outline" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar modelo</button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($planHasPatient): ?>
        <!-- Modal Publicar Versão -->
        <div id="modal-publish-plan" data-dialog="modal" class="modal-overlay">
          <div id="publish-plan-panel" data-live-swap class="cn-dialog__panel u-bg-neutral-white u-radius-card u-w-full u-maxw-form u-p-6 u-shadow-lg">
            <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-3">Revisar e publicar versão</h2>
            <p class="u-color-neutral-gray-600 u-text-compact u-leading-body u-mb-4">
              Ao publicar, este plano passa a ser a <strong>prescrição ativa</strong> que o paciente vê no aplicativo. Ele vira uma nova versão numerada e não poderá mais ser editado; para mudar depois, crie uma cópia. As versões anteriores continuam no histórico.
            </p>

            <?php if($planHasPatient): ?><?= \ClyoNutri\Application\View\ClinicalContext::renderData(Connection::getInstance(),$planClinicalContext??=\ClyoNutri\Application\View\ClinicalContext::read(Connection::getInstance(),(int)$plan['patient_id'])) ?><?php endif; ?>
            <div data-testid="publication-adequacy-summary" class="u-bg-neutral-gray-50 u-border u-radius-input u-p-3 u-mb-4">
              <div class="u-flex u-justify-between u-items-center u-mb-2">
                <strong class="u-text-compact u-color-neutral-ink-900">Adequação Nutricional & Estrutura</strong>
                <span class="u-text-caption u-color-neutral-gray-500"><?= count($plan['meals']) ?> refeição(ões)</span>
              </div>
              <div class="u-grid u-cols-auto-sm u-gap-2 u-text-compact">
                <div>
                  <span class="u-block u-color-neutral-gray-500">Calorias (VET)</span>
                  <strong class="u-color-neutral-ink-900"><?= number_format((float)$plan['daily_energy_kcal'], 1, ',', '.') ?> kcal</strong>
                  <?php if (!empty($preview['target_comparison']['energy'])): ?>
                    <?php
                      $adqTarget = (float)$preview['target_comparison']['energy']['target'];
                      $adqRatio = $adqTarget > 0 ? round(((float)$plan['daily_energy_kcal'] / $adqTarget) * 100, 1) : 0;
                    ?>
                    <span class="u-block u-text-caption <?= $preview['target_comparison']['energy']['status'] === 'ok' ? 'ds-tone-ok' : 'ds-tone-warn' ?>">
                      Meta: <?= number_format($adqTarget, 1, ',', '.') ?> kcal (<?= $adqRatio ?>%)
                    </span>
                  <?php endif; ?>
                </div>
                <div>
                  <span class="u-block u-color-neutral-gray-500">Proteínas</span>
                  <strong class="u-color-neutral-ink-900"><?= number_format((float)$plan['protein_g'], 1, ',', '.') ?> g</strong>
                </div>
                <div>
                  <span class="u-block u-color-neutral-gray-500">Carboidratos</span>
                  <strong class="u-color-neutral-ink-900"><?= number_format((float)$plan['carbohydrate_g'], 1, ',', '.') ?> g</strong>
                </div>
                <div>
                  <span class="u-block u-color-neutral-gray-500">Lipídios</span>
                  <strong class="u-color-neutral-ink-900"><?= number_format((float)$plan['lipid_g'], 1, ',', '.') ?> g</strong>
                </div>
              </div>
            </div>

            <?php if (!empty($preview['adequacy']['requires_override'])): ?>
              <div data-testid="publication-adequacy-alert" class="op-message op-message--warning u-mb-4 u-text-compact">
                <strong>Alerta de adequação da prescrição:</strong>
                <ul class="u-mt-1 u-mr-0 u-mb-0 u-ml-4 u-p-0">
                  <?php foreach ($preview['adequacy']['alerts'] as $adqAlert): ?>
                    <li><?= htmlspecialchars($adqAlert['message']) ?></li>
                  <?php endforeach; ?>
                </ul>
                <p class="u-mt-1 u-mb-0 u-mx-0">Para prosseguir com o desvio da meta planejada, confirme a autorização clínica e descreva a justificativa abaixo.</p>
              </div>
            <?php endif; ?>

            <?php if (!empty($preview['allergen_safety']['requires_confirmation'])): ?>
              <div class="op-message op-message--warning u-mb-4 u-text-compact">
                <strong><?= !empty($preview['allergen_safety']['has_conflict']) ? 'Conflito de alérgeno' : 'Alérgenos não verificados' ?>:</strong> Para prosseguir, o profissional deve marcar a autorização clínica expressa e justificar abaixo. A justificativa fica registrada na versão e na trilha de auditoria.
              </div>
            <?php endif; ?>

            <div data-testid="publication-allergy-review" class="u-mb-4">
              <strong>Alergias: <?= htmlspecialchars($clinicalSummary['allergy_label']) ?></strong>
              <?php if ($clinicalSummary['allergy_pending']): ?>
              <p>Avaliação pendente. Revise a anamnese antes de publicar; este plano não confirma ausência de alergias.</p>
              <a href="/pacientes/<?= (int)$plan['patient_id'] ?>/anamnese">Revisar anamnese</a>
              <?php endif; ?>
              <?php if ($clinicalSummary['source'] && ($clinicalSummary['source']['kind'] ?? '') === 'cadastro'): ?><p data-testid="summary-source">Origem: <?= htmlspecialchars($clinicalSummary['source']['label']) ?></p><?php elseif ($clinicalSummary['source']): ?><p data-testid="summary-source">Origem: anamnese v<?= (int)$clinicalSummary['source']['version'] ?><?= $clinicalSummary['is_draft'] ? ' (rascunho)' : ' (concluída)' ?> · <?= htmlspecialchars((string)$clinicalSummary['source']['recorded_at']) ?></p><?php endif; ?>
              <?php if (!empty($clinicalSummary['draft_in_progress'])): ?><p data-testid="publication-anamnesis-draft"><?= htmlspecialchars($clinicalSummary['draft_in_progress']['label']) ?>. <a href="/pacientes/<?= (int)$plan['patient_id'] ?>/anamnese">Continuar</a></p><?php endif; ?>
            </div>
            <form method="POST" action="/planos/<?= $planId ?>/publicar">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <?php if (!empty($preview['adequacy']['requires_override'])): ?>
                <div class="u-mb-4">
                  <label class="u-flex u-items-start u-gap-2 u-text-compact u-pointer">
                    <input type="checkbox" name="allow_adequacy_override" value="1" required class="u-mt-1" data-testid="allow-adequacy-override">
                    <span>Confirmo e autorizo clinicamente a publicação com o desvio em relação à meta planejada.</span>
                  </label>
                  <label for="adequacy-override-reason" class="u-block u-font-semibold u-mt-2 u-mb-1 u-mx-0 u-text-compact">Justificativa clínica da adequação *</label>
                  <textarea id="adequacy-override-reason" name="adequacy_override_reason" rows="2" required minlength="10" maxlength="1000" placeholder="Ex.: Prescrição inicial de transição; próximas refeições serão adicionadas no retorno." class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
                </div>
              <?php endif; ?>
              <?php if (!empty($preview['allergen_safety']['requires_confirmation'])): ?>
                <div class="u-mb-4">
                  <label class="u-flex u-items-start u-gap-2 u-text-compact u-pointer">
                    <input type="checkbox" name="allow_allergen_override" value="1" required class="u-mt-1">
                    <span>Confirmo e autorizo clinicamente a prescrição dos alimentos assinalados mesmo com os alertas detectados.</span>
                  </label>
                  <label for="allergen-override-reason" class="u-block u-font-semibold u-mt-2 u-mb-1 u-mx-0 u-text-compact">Justificativa clínica *</label>
                  <textarea id="allergen-override-reason" name="allergen_override_reason" rows="3" required minlength="10" maxlength="1000" placeholder="Ex.: rótulos conferidos com a paciente; produto sem glúten da marca X" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input"></textarea>
                </div>
              <?php endif; ?>

              <div class="u-flex u-justify-end u-gap-2">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-publish-plan">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar e publicar versão</button>
              </div>
            </form>
          </div>
        </div>

        <?php endif; ?>

        <script src="/assets/js/food-picker.js?v=v54-30" defer></script>
        <script src="/assets/js/unit-check.js?v=v54-5" defer></script>
        <script src="/assets/js/quick-plan.js?v=v54-26" defer></script>
        <script src="/assets/js/plan-live.js?v=v54-26" defer></script>
        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
        function openAddItemModal(mealId, mealName) {
          document.getElementById('add-item-meal-id').value = mealId;
          document.getElementById('add-item-meal-name').innerText = 'Refeição: ' + mealName;
          window.openFoodDialog('modal-add-item');
        }
        function openAddSubModal(itemId, foodName, itemKcal) {
          document.getElementById('add-sub-item-id').value = itemId;
          var subForm = document.getElementById('add-sub-item-id').form;
          if (subForm) { subForm.dataset.itemKcal = (typeof itemKcal === 'number' && isFinite(itemKcal)) ? String(itemKcal) : ''; subForm.dispatchEvent(new CustomEvent('clyo:sub-item')); }
          document.getElementById('add-sub-item-name').innerText = 'Substituição para o item: ' + foodName;
          window.openFoodDialog('modal-add-sub');
          loadEqSuggestions(itemId);
        }

        // NI2-519 (v43): trocas sugeridas pela regra de equivalência da clínica. Sem regra, só o aviso.
        function loadEqSuggestions(itemId) {
          var box = document.getElementById('add-sub-suggestions');
          if (!box) return;
          box.hidden = false; box.textContent = 'Buscando trocas pela regra da clínica…';
          fetch('/planos/<?= (int)$planId ?>/itens/' + encodeURIComponent(itemId) + '/equivalentes', { headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) throw Error('falha'); return r.json(); })
            .then(function (d) {
              box.replaceChildren();
              var head = document.createElement('p'); head.style.margin = '0';
              if (d.rule) {
                head.innerHTML = '';
                var b = document.createElement('strong'); b.textContent = 'Trocas pela regra "' + d.rule.name + '"';
                head.append(b, document.createTextNode(' (energia até ' + d.rule.energy_tolerance_pct + '%, macros até ' + d.rule.macro_tolerance_pct + '%). Fonte: ' + d.rule.source_note + (d.rule.review_status !== 'revisada' ? ' Regra pendente de revisão por nutricionista.' : '')));
              } else { head.textContent = d.reason || 'Sem regra de equivalência para este grupo.'; }
              box.append(head);
              if (d.rule && d.reason) { var why = document.createElement('p'); why.style.margin = '4px 0 0'; why.textContent = d.reason; box.append(why); }
              if (d.suggestions && d.suggestions.length) {
                var ul = document.createElement('ul');
                d.suggestions.forEach(function (sg) {
                  var li = document.createElement('li'), t = document.createElement('span'), use = document.createElement('button');
                  t.textContent = sg.name + ' · ' + sg.grams + ' g' + (sg.delta_label ? ' · ' + sg.delta_label : '') + (sg.source_label ? ' · ' + sg.source_label : '');
                  use.type = 'button'; use.className = 'btn btn-sm btn-outline'; use.textContent = 'Usar';
                  use.setAttribute('aria-label', 'Usar ' + sg.name + ', ' + sg.grams + ' gramas');
                  use.addEventListener('click', function () {
                    var picker = document.querySelector('#modal-add-sub [data-food-picker]');
                    if (picker) picker.dispatchEvent(new CustomEvent('clyo:choose-food', { detail: { id: sg.food_id, name: sg.name, energy_kcal: sg.energy_kcal_100g, source_label: sg.source_label } }));
                    var amt = document.getElementById('dc25-amount'), unit = document.getElementById('dc26-measure_unit');
                    if (amt) amt.value = sg.grams; if (unit) { unit.value = 'g'; unit.dispatchEvent(new Event('change', { bubbles: true })); }
                    if (amt) amt.focus();
                  });
                  li.append(t, use); ul.append(li);
                });
                box.append(ul);
              }
            })
            .catch(function () { box.textContent = 'Não foi possível carregar as trocas sugeridas. A busca abaixo continua funcionando.'; });
        }

        document.addEventListener('DOMContentLoaded', function() {
          var quickBtns = document.querySelectorAll('.quick-meal-btn');
          var nameInput = document.getElementById('dc19-name');
          var timeInput = document.getElementById('dc20-time_of_day');

          if (timeInput) {
            timeInput.addEventListener('input', function() { timeInput.dataset.autoFilled = '0'; });
          }

          quickBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
              var mealName = this.getAttribute('data-meal');
              var mealTime = this.getAttribute('data-time');
              if (nameInput) {
                nameInput.value = mealName;
                nameInput.focus();
              }
              // Troca o horário se o campo está vazio ou se ele veio de outro chip;
              // um horário digitado pelo nutricionista é preservado.
              if (timeInput && mealTime && (!timeInput.value || timeInput.dataset.autoFilled === '1')) {
                timeInput.value = mealTime;
                timeInput.dataset.autoFilled = '1';
              }
            });
          });
        });
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function addMealToPlan(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        $name = trim($_POST['name'] ?? '');
        $timeOfDay = !empty($_POST['time_of_day']) ? trim($_POST['time_of_day']) : null;
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

        try {
            $service->addMeal($planId, $name, $timeOfDay, $notes);
            $_SESSION['feedback_message'] = "Refeição '{$name}' adicionada.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao adicionar refeição: " . $this->userFacingError('Erro ao adicionar refeição', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function updateMealInPlan(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $mealId = (int)($params['mealId'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        $name = trim((string)($_POST['name'] ?? ''));
        $timeOfDay = !empty($_POST['time_of_day']) ? trim((string)$_POST['time_of_day']) : null;
        $notes = isset($_POST['notes']) && trim((string)$_POST['notes']) !== '' ? trim((string)$_POST['notes']) : null;

        try {
            $service->updateMeal($mealId, $name, $timeOfDay, $notes, $planId);
            $_SESSION['feedback_message'] = "Refeição '{$name}' atualizada.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao atualizar refeição: " . $this->userFacingError('Erro ao atualizar refeição', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function deleteMealFromPlan(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $mealId = (int)($params['mealId'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $service->deleteMeal($mealId);
            $_SESSION['feedback_message'] = "Refeição removida.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao remover refeição: " . $this->userFacingError('Erro ao remover refeição', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function addItemToPlanMeal(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $mealId = (int)($_POST['meal_id'] ?? 0);
        $foodId = (int)($_POST['food_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $unit = trim($_POST['measure_unit'] ?? 'g');
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $service->addItem(
                $mealId,
                $foodId,
                $amount,
                $unit,
                $notes,
                !empty($_POST['confirm_estimated']),
                !empty($_POST['enforce_allergen_gate']),
                !empty($_POST['confirm_allergen_conflict']),
                is_string($_POST['allergen_ack_reason'] ?? null) ? $_POST['allergen_ack_reason'] : null
            );
            $_SESSION['feedback_message'] = "Alimento adicionado e totais nutricionais recalculados.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao adicionar alimento: " . $this->userFacingError('Erro ao adicionar alimento', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    /** NI2-624: profissional registra que a alergia histórica foi descartada clinicamente (com motivo; nada é apagado). */
    public function dismissAllergyHistory(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            header('Location: /pacientes/' . $id . '?error=csrf');
            exit;
        }
        $db = Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Somente nutricionista da clínica registra revisão de alergia.';
            return;
        }
        $clinicId = (int)TenantContext::getClinicId();
        $group = is_string($_POST['group'] ?? null) ? $_POST['group'] : '';
        $reason = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';
        try {
            $state = (new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($db))->evaluatePatientAllergyState($id); // também confere a clínica
            if (!in_array($group, array_column($state['review_findings'] ?? [], 'group'), true)) {
                throw new \InvalidArgumentException('Esta alergia não está mais em revisão.');
            }
            (new \ClyoNutri\Domain\Clinical\AllergyHistoryReview($db))->dismiss($clinicId, $id, $group, (int)TenantContext::getUserId(), $reason);
            header('Location: /pacientes/' . $id . '?alergia_revisada=1');
        } catch (\InvalidArgumentException $e) {
            header('Location: /pacientes/' . $id . '?error=1&msg=' . rawurlencode($e->getMessage()) . '#alergia-revisao');
        }
        exit;
    }

    private function canEditPlanItems(\PDO $db): bool
    {
        return $this->prescriberProfessionalId($db) !== null;
    }

    /**
     * Profissional prescritor do usuário logado nesta clínica: nutricionista ativo, membro ativo
     * da clínica e com registro em professionals (CRN). Null = não pode prescrever.
     * Única regra de autorização para todas as ações que alteram planos (code review NI2-9, A1).
     */
    private function prescriberProfessionalId(\PDO $db): ?int
    {
        $access = $db->prepare("SELECT pr.id FROM clinic_members cm JOIN users u ON u.id = cm.user_id
            JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active'
            AND cm.role IN ('owner', 'admin', 'nutritionist')
            AND u.status = 'active' AND u.role = 'nutritionist'
            AND pr.crn IS NOT NULL AND TRIM(pr.crn) <> ''
            ORDER BY pr.id LIMIT 1");
        $access->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $id = $access->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function denyPrescription(): void
    {
        http_response_code(403);
        echo 'Você não tem permissão para editar planos alimentares. Apenas nutricionistas com CRN cadastrado na clínica podem prescrever.';
    }

    public function updatePlanItem(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $db = Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Você não tem permissão para editar planos alimentares.';
            return;
        }
        try {
            foreach (['amount', 'measure_unit', 'notes', 'revision'] as $field) {
                if (!isset($_POST[$field]) || !is_string($_POST[$field])) throw new \InvalidArgumentException('Confira os campos do item.');
            }
            if (!is_numeric($_POST['amount']) || $_POST['revision'] === '') throw new \InvalidArgumentException('Informe uma quantidade válida e recarregue o formulário.');
            (new MealPlanService($db))->updateItem((int)($params['itemId'] ?? 0),
                (float)$_POST['amount'], trim($_POST['measure_unit']), trim($_POST['notes']), $_POST['revision'], $planId, !empty($_POST['confirm_estimated']));
            $_SESSION['feedback_message'] = 'Item atualizado e totais nutricionais recalculados. As substituições foram preservadas.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('NI2-310 update item failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível salvar o item. Nenhuma alteração foi gravada. Tente novamente.';
        }
        header("Location: /planos/{$planId}");
        exit;
    }

    public function deleteItemFromPlanMeal(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $itemId = (int)($params['itemId'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $service->deleteItem($itemId);
            $_SESSION['feedback_message'] = "Alimento removido.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao remover alimento: " . $this->userFacingError('Erro ao remover alimento', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function addSubstitutionToPlanItem(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $foodId = (int)($_POST['food_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $unit = trim($_POST['measure_unit'] ?? 'g');
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $service->addSubstitution(
                $itemId,
                $foodId,
                $amount,
                $unit,
                $notes,
                !empty($_POST['confirm_estimated']),
                !empty($_POST['enforce_allergen_gate']),
                !empty($_POST['confirm_allergen_conflict']),
                is_string($_POST['allergen_ack_reason'] ?? null) ? $_POST['allergen_ack_reason'] : null
            );
            $_SESSION['feedback_message'] = "Substituição cadastrada.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao cadastrar substituição: " . $this->userFacingError('Erro ao cadastrar substituição', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function updateSubstitutionInPlan(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $subId = (int)($params['subId'] ?? 0);
        $db = Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Você não tem permissão para editar planos alimentares.';
            return;
        }
        try {
            if (!isset($_POST['amount']) || !is_numeric(str_replace(',', '.', (string)$_POST['amount']))) {
                throw new \InvalidArgumentException('Informe uma quantidade válida para a substituição.');
            }
            $amount = (float)str_replace(',', '.', (string)$_POST['amount']);
            $unit = trim((string)($_POST['measure_unit'] ?? 'g'));
            $notes = isset($_POST['notes']) && is_string($_POST['notes']) ? trim($_POST['notes']) : null;
            (new MealPlanService($db))->updateSubstitution($subId, $amount, $unit, $notes, $planId, !empty($_POST['confirm_estimated']));
            $_SESSION['feedback_message'] = 'Substituição atualizada.';
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $e) {
            $_SESSION['error_message'] = $this->userFacingError('NI2-521 substituição', $e); // PDOException também é RuntimeException
        } catch (\Throwable $e) {
            error_log('NI2-521 update substitution failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível salvar a substituição. Tente novamente.';
        }
        header("Location: /planos/{$planId}");
        exit;
    }

    public function deleteSubstitutionFromPlan(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $subId = (int)($params['subId'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $service->deleteSubstitution($subId);
            $_SESSION['feedback_message'] = "Substituição removida.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao remover substituição: " . $this->userFacingError('Erro ao remover substituição', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function publishMealPlan(array $params): void
    {
        if ($this->prescriberProfessionalId(\ClyoNutri\Core\Database\Connection::getInstance()) === null) {
            $this->denyPrescription();
            return;
        }
        $planId = (int)($params['id'] ?? 0);
        $allowAllergenOverride = !empty($_POST['allow_allergen_override']);
        $overrideReason = is_string($_POST['allergen_override_reason'] ?? null) ? trim($_POST['allergen_override_reason']) : null;
        $allowAdequacyOverride = !empty($_POST['allow_adequacy_override']);
        $adequacyOverrideReason = is_string($_POST['adequacy_override_reason'] ?? null) ? trim($_POST['adequacy_override_reason']) : null;

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $res = $service->publishPlan($planId, $allowAllergenOverride, true, $overrideReason, $allowAdequacyOverride, $adequacyOverrideReason);
            $_SESSION['feedback_message'] = "Plano publicado como versão {$res['version']}! As versões anteriores continuam disponíveis no histórico.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro na publicação: " . $this->userFacingError('Erro na publicação', $e);
        }

        header("Location: /planos/{$planId}");
        exit;
    }

    public function duplicateMealPlan(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Você não tem permissão para editar planos alimentares.';
            return;
        }
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $res = $service->duplicatePlan($planId);
            $_SESSION['feedback_message'] = "Plano duplicado. A cópia é um rascunho novo e pode ser editada à parte.";
            header("Location: /planos/" . $res['plan_id']);
            exit;
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao duplicar plano: " . $this->userFacingError('Erro ao duplicar plano', $e);
            header("Location: /planos/{$planId}");
            exit;
        }
    }

    public function compareMealPlanVersions(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Você não tem permissão para comparar versões de planos alimentares.';
            return;
        }
        $patientRepo = new PatientRepository();
        $patient = $patientRepo->findById($patientId);
        if (!$patient) {
            http_response_code(404);
            echo 'Paciente não encontrado.';
            return;
        }

        $diffSvc = new \ClyoNutri\Domain\MealPlan\MealPlanVersionDiffService($db);
        $versions = $diffSvc->listPublishedVersions($patientId);
        $verA = isset($_GET['v_a']) && ctype_digit((string)$_GET['v_a']) ? (int)$_GET['v_a'] : null;
        $verB = isset($_GET['v_b']) && ctype_digit((string)$_GET['v_b']) ? (int)$_GET['v_b'] : null;

        $diff = null;
        $diffError = null;
        try {
            $diff = $diffSvc->compareVersions($patientId, $verA, $verB);
        } catch (\InvalidArgumentException $e) {
            $diffError = $e->getMessage();
        }

        if (($_GET['format'] ?? '') === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            if ($diffError !== null) {
                http_response_code(422);
                echo json_encode(['error' => $diffError, 'versions_count' => count($versions)], JSON_UNESCAPED_UNICODE);
                return;
            }
            echo json_encode(['data' => $diff, 'versions' => $versions], JSON_UNESCAPED_UNICODE);
            return;
        }

        $title = 'Comparar versões de planos — ' . $patient['name'];
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica Clyo Nutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();

        ob_start();
        require __DIR__ . '/../../../views/meal_plans/compare_versions.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function finance(): void
    {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $clinicId = TenantContext::getClinicId();
        
        $stmt = $db->prepare("
            SELECT r.*, p.name AS patient_name,
                   (SELECT COALESCE(SUM(py.amount_cents), 0) FROM payments py WHERE py.receivable_id = r.id) AS total_paid_cents,
                   (SELECT py.receipt_code FROM payments py WHERE py.receivable_id = r.id ORDER BY py.id DESC LIMIT 1) AS receipt_code
            FROM receivables r
            JOIN patients p ON p.id = r.patient_id
            WHERE r.clinic_id = :clinic_id
            ORDER BY r.due_date DESC, r.id DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $receivables = $stmt->fetchAll();

        // Pacientes da clínica para seleção no novo lançamento
        $patStmt = $db->prepare("
            SELECT id, name, email 
            FROM patients 
            WHERE clinic_id = :clinic_id AND status = 'active'
            ORDER BY name ASC
        ");
        $patStmt->execute(['clinic_id' => $clinicId]);
        $patients = $patStmt->fetchAll();

        // Agendamentos recentes da clínica (opcional para vincular)
        $appStmt = $db->prepare("
            SELECT a.id, a.scheduled_at, p.name AS patient_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
            ORDER BY a.scheduled_at DESC
            LIMIT 30
        ");
        $appStmt->execute(['clinic_id' => $clinicId]);
        $appointments = $appStmt->fetchAll();

        $financeService = new \ClyoNutri\Domain\Finance\FinanceService($db);
        $summary = $financeService->getReceivablesSummary();
        // v54.37: saídas (despesas) e cartões entrada/saída/saldo
        $expenseService = new \ClyoNutri\Domain\Finance\ExpenseService($db);
        $expensesAvailable = $expenseService->available();
        $expenses = $expenseService->list($clinicId);
        $totals = (new \ClyoNutri\Domain\Finance\FinanceDocumentService($db))->totals($clinicId, $summary);
        $entryCategories = \ClyoNutri\Domain\Finance\FinanceService::ENTRY_CATEGORIES;
        $expenseCategories = \ClyoNutri\Domain\Finance\ExpenseService::CATEGORIES;
        $brl = fn(int $c): string => 'R$ ' . number_format($c / 100, 2, ',', '.');

        $title = 'Financeiro';
        $activeNav = 'financeiro';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();

        $feedbackMessage = $_SESSION['finance_success'] ?? $_SESSION['success_message'] ?? null;
        $errorMessage = $_SESSION['finance_error'] ?? $_SESSION['error_message'] ?? null;
        unset($_SESSION['finance_success'], $_SESSION['success_message'], $_SESSION['finance_error'], $_SESSION['error_message']);

        ob_start();
        ?>
        <div class="u-flex u-justify-between u-items-start u-mb-6 u-wrap u-gap-4">
          <div>
            <h1 class="u-color-botanical-forest-900 u-mb-1">Gestão financeira</h1>
            <p class="u-color-neutral-gray-600 u-m-0">Entradas (honorários de pacientes, baixas e recibos) e saídas da clínica (internet, aluguel, etc.).</p>
          </div>
          <div class="u-flex u-items-center u-gap-2 u-wrap">
            <a href="/financeiro/exportar/csv" class="btn btn-outline u-no-underline" data-testid="finance-export-csv">Exportar CSV</a>
            <a href="/financeiro/exportar/pdf" class="btn btn-outline u-no-underline" data-testid="finance-export-pdf">Exportar PDF</a>
            <button type="button" class="btn btn-primary u-inline-flex u-items-center u-gap-2" data-show="modal-novo-recebivel">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              <span>+ Novo lançamento financeiro</span>
            </button>
          </div>
        </div>

        <?php if (!empty($errorMessage)): ?>
          <div role="alert" class="op-message op-message--error u-mb-6">
            <span><?= htmlspecialchars($errorMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($feedbackMessage)): ?>
          <div role="status" class="op-message op-message--success u-mb-6">
            <span><?= htmlspecialchars($feedbackMessage) ?></span>
          </div>
        <?php endif; ?>

        <!-- Cards de Resumo (v54.37: entradas, saídas e saldo) -->
        <div class="u-grid u-cols-auto-md u-gap-4 u-mb-6" data-testid="finance-cards">
          <div class="card u-p-5 u-accent u-accent-semantic-warning">
            <div class="u-text-compact u-font-semibold u-color-neutral-gray-600 u-uppercase u-tracking-wide u-mb-1">A receber</div>
            <div class="tabular-nums u-text-h2 u-font-bold u-color-botanical-forest-900" data-testid="card-pending"><?= $brl($totals['pending_cents']) ?></div>
            <div class="u-text-compact u-color-neutral-gray-500 u-mt-1">Entradas aguardando baixa</div>
          </div>
          <div class="card u-p-5 u-accent u-accent-semantic-success">
            <div class="u-text-compact u-font-semibold u-color-neutral-gray-600 u-uppercase u-tracking-wide u-mb-1">Recebido</div>
            <div class="tabular-nums u-text-h2 u-font-bold u-color-semantic-success" data-testid="card-received"><?= $brl($totals['received_cents']) ?></div>
            <div class="u-text-compact u-color-neutral-gray-500 u-mt-1">Entradas com baixa confirmada</div>
          </div>
          <div class="card u-p-5 u-accent u-accent-brand-copper-500">
            <div class="u-text-compact u-font-semibold u-color-neutral-gray-600 u-uppercase u-tracking-wide u-mb-1">Saídas pagas</div>
            <div class="tabular-nums u-text-h2 u-font-bold u-color-botanical-forest-900" data-testid="card-expenses"><?= $brl($totals['expenses_paid_cents']) ?></div>
            <div class="u-text-compact u-color-neutral-gray-500 u-mt-1"><?= $totals['expenses_pending_cents'] > 0 ? 'Mais ' . $brl($totals['expenses_pending_cents']) . ' a pagar' : 'Despesas da clínica' ?></div>
          </div>
          <div class="card u-p-5 u-accent <?= $totals['balance_cents'] < 0 ? 'u-accent-semantic-warning' : 'u-accent-semantic-success' ?>">
            <div class="u-text-compact u-font-semibold u-color-neutral-gray-600 u-uppercase u-tracking-wide u-mb-1">Saldo</div>
            <div class="tabular-nums u-text-h2 u-font-bold <?= $totals['balance_cents'] < 0 ? 'ds-tone-bad' : 'u-color-botanical-forest-900' ?>" data-testid="card-balance"><?= ($totals['balance_cents'] < 0 ? '− ' : '') . $brl(abs($totals['balance_cents'])) ?></div>
            <div class="u-text-compact u-color-neutral-gray-500 u-mt-1">Recebido − saídas pagas</div>
          </div>
        </div>

        <?php if (empty($receivables) && empty($expenses)): ?>
          <?php /* Estado Vazio Explicativo com Design System Ratificado e CTA Operacional Real (PX-12) */ ?>
          <div class="card u-py-12 u-px-8 u-text-center u-border u-border-dashed u-bc-neutral-gray-300 u-bg-neutral-white u-radius-card">
            <div class="ds-icon-badge ds-icon-badge--lg ds-icon-badge--copper u-mx-auto u-mb-5">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="20" height="14" x="2" y="5" rx="2"></rect>
                <line x1="2" x2="22" y1="10" y2="10"></line>
              </svg>
            </div>
            <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-2">Nenhum recebível financeiro registrado</h2>
            <p class="u-color-neutral-gray-600 u-maxw-prose u-mt-0 u-mb-6 u-mx-auto u-leading-body u-text-body">
              Os recebíveis financeiros representam honorários de consultas clínicas e planos de acompanhamento nutricional. Eles são gerados a partir dos agendamentos na agenda ou registrados manualmente aqui para qualquer paciente da sua clínica.
            </p>
            <div class="u-flex u-justify-center u-items-center u-gap-3 u-wrap u-mb-8">
              <button type="button" class="btn btn-primary" data-show="modal-novo-recebivel">
                + Novo Lançamento Financeiro
              </button>
              <a href="/agenda" class="btn btn-outline u-no-underline">
                Ver agenda de atendimentos &rarr;
              </a>
            </div>
            
            <!-- Guia informativo / Próximos passos operacionais -->
            <div class="u-bg-neutral-gray-50 u-border u-radius-input u-p-5 u-maxw-prose u-my-0 u-mx-auto u-text-left">
              <div class="u-flex u-items-center u-gap-2 u-mb-3 u-font-semibold u-color-botanical-forest-900 u-text-compact">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="u-color-botanical-copper-600"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                Como funciona o fluxo financeiro do Clyo Nutri:
              </div>
              <ul class="u-m-0 u-pl-5 u-color-neutral-gray-600 u-text-compact u-leading-body">
                <li><strong>1. Registro de Honorários:</strong> Cadastre cobranças pontuais ou pacotes vinculando a um paciente cadastrado.</li>
                <li><strong>2. Baixa com Recibo:</strong> Ao receber via PIX, Cartão ou Dinheiro, confirme a baixa para registrar a transação e emitir código de recibo comprobatório.</li>
                <li><strong>3. Integração com Atendimentos:</strong> Vincule o recebível diretamente a uma consulta agendada quando desejar rastreabilidade completa.</li>
                <li><strong>4. Isolamento seguro:</strong> Todos os dados de faturamento e recibos são estritamente isolados e protegidos por clínica.</li>
              </ul>
            </div>
          </div>
        <?php else: ?>
          <?php if (!empty($receivables)): ?>
          <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-3">Entradas</h2>
          <!-- Tabela de Recebíveis com Operações Reais -->
          <div class="card u-p-0 u-overflow-hidden u-mb-6">
            <div class="table-responsive">
              <table class="table u-mb-0">
                <thead class="u-bg-neutral-gray-50">
                  <tr>
                    <th>Descrição</th>
                    <th>Paciente</th>
                    <th class="tabular-nums">Valor</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th class="u-text-right">Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $today = date('Y-m-d');
                  foreach ($receivables as $rc): 
                    $isOverdue = ($rc['status'] === 'pending' && $rc['due_date'] < $today);
                  ?>
                    <tr>
                      <td>
                        <div class="u-font-semibold u-color-botanical-forest-900"><?= htmlspecialchars($rc['description']) ?></div>
                        <?php if (!empty($rc['category'])): ?>
                          <span class="u-text-caption u-color-neutral-gray-500">Entrada · <?= htmlspecialchars((string)$rc['category']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($rc['appointment_id'])): ?>
                          <span class="u-text-caption u-color-neutral-gray-500">Consulta #<?= (int)$rc['appointment_id'] ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($rc['patient_name']) ?></td>
                      <td class="tabular-nums u-font-semibold u-color-botanical-forest-900">
                        R$ <?= number_format($rc['amount_cents'] / 100, 2, ',', '.') ?>
                      </td>
                      <td>
                        <span class="<?= $isOverdue ? 'ds-tone-bad u-font-semibold' : '' ?>">
                          <?= date('d/m/Y', strtotime($rc['due_date'])) ?>
                        </span>
                      </td>
                      <td>
                        <?= LabelMap::receivableStatusBadge($rc['status'], $isOverdue) ?>
                        <?php if ($rc['status'] === 'paid' && !empty($rc['receipt_code'])): ?>
                          <div class="u-text-caption u-color-neutral-gray-500 u-mt-0">
                            Recibo: <a href="/financeiro/recibos/<?= rawurlencode($rc['receipt_code']) ?>" target="_blank" rel="noopener" data-testid="receipt-link"><?= htmlspecialchars($rc['receipt_code']) ?></a>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td class="u-text-right">
                        <?php if (in_array($rc['status'], ['pending', 'partially_paid'], true)): ?>
                          <div class="u-inline-flex u-items-center u-gap-2">
                            <button type="button" class="btn btn-sm btn-outline u-color-semantic-success u-bc-semantic-success"
                                    data-call="openPayModal" data-args="[<?= (int)$rc['id'] ?>, <?= (int)$rc['amount_cents'] ?>, <?= htmlspecialchars(json_encode((string)$rc['description'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]">
                              Dar baixa
                            </button>
                            <form method="POST" action="/financeiro/recebiveis/<?= (int)$rc['id'] ?>/cancelar" class="u-inline" data-confirm="Deseja realmente cancelar este recebível?">
                              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                              <button type="submit" class="btn btn-sm u-color-neutral-gray-500 u-bg-none u-border-0 u-pointer u-underline u-text-compact">
                                Cancelar
                              </button>
                            </form>
                          </div>
                        <?php elseif ($rc['status'] === 'paid'): ?>
                          <span class="u-text-compact u-color-semantic-success u-font-semibold">✓ Quitado</span>
                        <?php else: ?>
                          <span class="u-text-compact u-color-neutral-gray-400">Cancelado</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($expenses)): ?>
          <h2 class="u-text-h3 u-font-bold u-color-botanical-forest-900 u-mb-3">Saídas</h2>
          <div class="card u-p-0 u-overflow-hidden" data-testid="expenses-table">
            <div class="table-responsive">
              <table class="table u-mb-0">
                <thead class="u-bg-neutral-gray-50">
                  <tr><th>Categoria</th><th>Descrição</th><th class="tabular-nums">Valor</th><th>Data</th><th>Status</th><th class="u-text-right">Ações</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($expenses as $ex): ?>
                    <tr>
                      <td><span class="u-font-semibold u-color-botanical-forest-900"><?= htmlspecialchars((string)$ex['category']) ?></span></td>
                      <td><?= htmlspecialchars((string)$ex['description']) ?></td>
                      <td class="tabular-nums u-font-semibold ds-tone-bad">− <?= $brl((int)$ex['amount_cents']) ?></td>
                      <td><?= date('d/m/Y', strtotime((string)$ex['due_date'])) ?></td>
                      <td><?= htmlspecialchars(\ClyoNutri\Domain\Finance\ExpenseService::STATUS[$ex['status']] ?? (string)$ex['status']) ?></td>
                      <td class="u-text-right">
                        <?php if ($ex['status'] !== 'canceled'): ?>
                          <div class="u-inline-flex u-items-center u-gap-2">
                            <?php if ($ex['status'] === 'pending'): ?>
                              <form method="POST" action="/financeiro/saidas/<?= (int)$ex['id'] ?>/pagar" class="u-inline">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="btn btn-sm btn-outline u-color-semantic-success u-bc-semantic-success">Marcar como paga</button>
                              </form>
                            <?php endif; ?>
                            <form method="POST" action="/financeiro/saidas/<?= (int)$ex['id'] ?>/cancelar" class="u-inline" data-confirm="Deseja realmente cancelar esta saída?">
                              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                              <button type="submit" class="btn btn-sm u-color-neutral-gray-500 u-bg-none u-border-0 u-pointer u-underline u-text-compact">Cancelar</button>
                            </form>
                          </div>
                        <?php else: ?>
                          <span class="u-text-compact u-color-neutral-gray-400">Cancelada</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Modal: Novo Lançamento Financeiro -->
        <div id="modal-novo-recebivel" data-dialog="modal" class="modal-overlay">
          <div class="cn-dialog__panel card u-w-full u-maxw-form u-bg-neutral-white u-radius-card u-shadow-lg u-p-6">
            <div class="u-flex u-justify-between u-items-center u-mb-5">
              <h3 class="u-m-0 u-text-card-title u-color-botanical-forest-900">Novo lançamento financeiro</h3>
              <button type="button" data-dialog-close data-hide="modal-novo-recebivel" class="u-bg-none u-border-0 u-text-h2 u-pointer u-color-neutral-gray-400">&times;</button>
            </div>
            
            <form method="POST" action="/financeiro/recebiveis/novo">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <fieldset class="u-mb-4 u-border-0 u-p-0 u-m-0">
                <legend class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Tipo *</legend>
                <div class="u-flex u-gap-4">
                  <label class="u-inline-flex u-items-center u-gap-2"><input type="radio" name="direction" value="entrada" checked data-testid="dir-entrada"> Entrada (recebimento de paciente)</label>
                  <label class="u-inline-flex u-items-center u-gap-2"><input type="radio" name="direction" value="saida" data-testid="dir-saida" <?= $expensesAvailable ? '' : 'disabled' ?>> Saída (despesa da clínica)</label>
                </div>
                <?php if (!$expensesAvailable): ?><div class="u-text-caption u-color-neutral-gray-500 u-mt-1">Saídas ficam disponíveis depois do SQL da v54.37.</div><?php endif; ?>
              </fieldset>

              <div id="fin-saida-fields" hidden>
                <div class="u-mb-4">
                  <label for="exp-category" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Categoria da saída *</label>
                  <select id="exp-category" name="expense_category" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact" disabled>
                    <option value="">Selecione...</option>
                    <?php foreach ($expenseCategories as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="u-mb-4">
                  <label class="u-inline-flex u-items-center u-gap-2 u-text-compact"><input type="checkbox" name="already_paid" value="1" checked disabled id="exp-paid"> Já paga nesta data (desmarque para deixar "a pagar")</label>
                </div>
              </div>

              <div id="fin-entrada-fields">
              <div class="u-mb-4">
                <label for="rcv-category" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Categoria (opcional)</label>
                <select id="rcv-category" name="category" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                  <option value="">Sem categoria</option>
                  <?php foreach ($entryCategories as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="u-mb-4">
                <label for="rcv-patient" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Paciente *</label>
                <?php if (empty($patients)): ?>
                  <div class="op-message op-message--error u-text-compact">
                    Nenhum paciente ativo cadastrado nesta clínica. <a href="/pacientes/novo" class="u-font-semibold u-color-semantic-error-strong u-underline">Cadastre um paciente primeiro</a>.
                  </div>
                <?php else: ?>
                  <select id="rcv-patient" name="patient_id" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                    <option value="">Selecione o paciente...</option>
                    <?php foreach ($patients as $p): ?>
                      <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?><?= !empty($p['email']) ? ' (' . htmlspecialchars($p['email']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>

              </div>

              <div class="u-mb-4">
                <label for="dc28-description" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Descrição do Lançamento *</label>
                <input id="dc28-description" type="text" name="description" required placeholder="Ex: Consulta inicial, Plano trimestral, Internet de novembro" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
              </div>

              <div class="u-grid u-cols-2 u-gap-3 u-mb-4">
                <div>
                  <label for="dc29-amount" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Valor (R$) *</label>
                  <input id="dc29-amount" type="number" step="0.01" min="0.01" name="amount" required placeholder="Ex: 250,00" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                </div>
                <div>
                  <label for="dc30-due_date" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900"><span id="fin-date-label">Data de vencimento</span> *</label>
                  <input id="dc30-due_date" type="date" name="due_date" value="<?= date('Y-m-d') ?>" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                </div>
              </div>

              <?php if (!empty($appointments)): ?>
                <div class="u-mb-5" id="fin-appointment-field">
                  <label for="dc31-appointment_id" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Vincular à consulta (opcional)</label>
                  <select id="dc31-appointment_id" name="appointment_id" class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                    <option value="">Nenhuma consulta vinculada</option>
                    <?php foreach ($appointments as $a): ?>
                      <option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> - <?= htmlspecialchars($a['patient_name']) ?> (<?= date('d/m/Y H:i', strtotime($a['scheduled_at'])) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>

              <div class="u-flex u-justify-end u-gap-3 u-mt-6">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-novo-recebivel">Cancelar</button>
                <button type="submit" id="fin-submit" class="btn btn-primary" data-no-patients="<?= empty($patients) ? '1' : '0' ?>" <?= empty($patients) ? 'disabled' : '' ?>>Salvar lançamento</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal: Dar Baixa / Registrar Pagamento -->
        <div id="modal-dar-baixa" data-dialog="modal" class="modal-overlay">
          <div class="cn-dialog__panel card u-w-full u-maxw-form u-bg-neutral-white u-radius-card u-shadow-lg u-p-6">
            <div class="u-flex u-justify-between u-items-center u-mb-5">
              <h3 class="u-m-0 u-text-card-title u-color-botanical-forest-900">Dar baixa no recebível</h3>
              <button type="button" data-dialog-close data-hide="modal-dar-baixa" class="u-bg-none u-border-0 u-text-h2 u-pointer u-color-neutral-gray-400">&times;</button>
            </div>
            
            <form id="form-dar-baixa" method="POST" action="">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <div class="u-mb-4 u-bg-neutral-gray-50 u-p-3 u-radius-input u-border">
                <div class="u-text-compact u-color-neutral-gray-500 u-uppercase u-font-semibold">Lançamento</div>
                <div id="pay-modal-description" class="u-font-semibold u-color-botanical-forest-900 u-text-body u-mt-0"></div>
              </div>

              <div class="u-mb-4">
                <label for="dc32-payment_method" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Forma de Pagamento *</label>
                <select id="dc32-payment_method" name="payment_method" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
                  <option value="pix">PIX (transferência instantânea)</option>
                  <option value="credit_card">Cartão de Crédito</option>
                  <option value="debit_card">Cartão de Débito</option>
                  <option value="cash">Dinheiro em Espécie</option>
                  <option value="transfer">Transferência Bancária / TED</option>
                </select>
              </div>

              <div class="u-mb-5">
                <label for="pay-modal-amount" class="u-block u-font-semibold u-text-compact u-mb-1 u-color-botanical-forest-900">Valor Recebido (R$) *</label>
                <input id="pay-modal-amount" type="number" step="0.01" min="0.01" name="amount" required class="u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-text-compact">
              </div>

              <div class="u-flex u-justify-end u-gap-3">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-dar-baixa">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-success-solid">Confirmar recebimento</button>
              </div>
            </form>
          </div>
        </div>

        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
        function openPayModal(id, amountCents, description) {
          var form = document.getElementById('form-dar-baixa');
          form.action = '/financeiro/recebiveis/' + id + '/pagar';
          document.getElementById('pay-modal-description').textContent = description;
          document.getElementById('pay-modal-amount').value = (amountCents / 100).toFixed(2);
          document.getElementById('modal-dar-baixa').style.display = 'flex';
        }
        // v54.37: alterna Entrada/Saída no novo lançamento
        (function () {
          var radios = document.querySelectorAll('input[name="direction"]');
          function sync() {
            var saida = !!document.querySelector('input[name="direction"][value="saida"]:checked');
            var ent = document.getElementById('fin-entrada-fields'), sai = document.getElementById('fin-saida-fields');
            ent.hidden = saida; sai.hidden = !saida;
            ent.querySelectorAll('select,input').forEach(function (el) { el.disabled = saida; });
            sai.querySelectorAll('select,input').forEach(function (el) { el.disabled = !saida; });
            var ap = document.getElementById('fin-appointment-field');
            if (ap) { ap.hidden = saida; ap.querySelectorAll('select').forEach(function (el) { el.disabled = saida; }); }
            document.getElementById('exp-category').required = saida;
            var pat = document.getElementById('rcv-patient'); if (pat) pat.required = !saida;
            document.getElementById('fin-date-label').textContent = saida ? 'Data da saída' : 'Data de vencimento';
            var btn = document.getElementById('fin-submit');
            btn.disabled = !saida && btn.getAttribute('data-no-patients') === '1';
          }
          radios.forEach(function (r) { r.addEventListener('change', sync); });
          if (radios.length) sync();
        })();
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function createReceivableAction(): void
    {
        if (($_POST['direction'] ?? 'entrada') === 'saida') {
            $this->createExpenseAction();
            return;
        }
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        $rawAmount = (string)($_POST['amount'] ?? '');
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $appointmentId = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;

        $amountCents = \ClyoNutri\Domain\Finance\Money::parseToCents($rawAmount); // v54.36

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\Finance\FinanceService($db);

        $isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
               || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

        try {
            $category = trim((string)($_POST['category'] ?? ''));
            $created = $service->createReceivable($patientId, $description, $amountCents, $dueDate, $appointmentId, $category !== '' ? $category : null);
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'receivable' => $created]);
                exit;
            }
            $_SESSION['finance_success'] = "Lançamento cadastrado.";
            header("Location: /financeiro");
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            if ($isJson) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $_SESSION['finance_error'] = "Acesso negado: " . $e->getMessage();
            header("Location: /financeiro");
            exit;
        } catch (\Throwable $e) {
            if ($isJson) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $this->userFacingError('financeiro', $e)]);
                exit;
            }
            $_SESSION['finance_error'] = "Erro ao criar lançamento: " . $this->userFacingError('Erro ao criar lançamento', $e);
            header("Location: /financeiro");
            exit;
        }
    }

    /** v54.37: Saída (despesa da clínica, sem paciente). */
    private function createExpenseAction(): void
    {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $isJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
        try {
            $created = (new \ClyoNutri\Domain\Finance\ExpenseService($db))->create(
                (string)($_POST['expense_category'] ?? $_POST['category'] ?? ''),
                (string)($_POST['description'] ?? ''),
                \ClyoNutri\Domain\Finance\Money::parseToCents((string)($_POST['amount'] ?? '')),
                (string)($_POST['due_date'] ?? ''),
                !empty($_POST['already_paid'])
            );
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'expense' => $created]);
                exit;
            }
            $_SESSION['finance_success'] = 'Saída cadastrada.';
        } catch (\Throwable $e) {
            if ($isJson) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $this->userFacingError('financeiro', $e)]);
                exit;
            }
            $_SESSION['finance_error'] = 'Erro ao criar saída: ' . $this->userFacingError('Erro ao criar saída', $e);
        }
        header('Location: /financeiro');
        exit;
    }

    public function payReceivableAction(array $params): void
    {
        $receivableId = (int)($params['id'] ?? $_POST['receivable_id'] ?? 0);
        $method = trim((string)($_POST['payment_method'] ?? 'pix'));
        $rawAmount = (string)($_POST['amount'] ?? '');

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\Finance\FinanceService($db);

        $isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
               || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

        try {
            $clinicId = TenantContext::getClinicId();
            $rec = \ClyoNutri\Core\Security\TenantGuard::assertReceivableBelongsToClinic($receivableId, $clinicId, $db);

            if ($rawAmount !== '') {
                $amountCents = \ClyoNutri\Domain\Finance\Money::parseToCents($rawAmount); // v54.36: "400.00" = R$ 400
            } else {
                $totStmt = $db->prepare("SELECT SUM(amount_cents) FROM payments WHERE receivable_id = :id");
                $totStmt->execute(['id' => $receivableId]);
                $paid = (int)$totStmt->fetchColumn();
                $amountCents = max(0, (int)$rec['amount_cents'] - $paid);
            }

            $res = $service->recordPayment($receivableId, $amountCents, $method);

            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'payment' => $res]);
                exit;
            }
            $valorFormatado = number_format($amountCents / 100, 2, ',', '.');
            $_SESSION['finance_success'] = "Pagamento de R$ {$valorFormatado} registrado. Recibo: {$res['receipt_code']}";
            header("Location: /financeiro");
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            if ($isJson) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $_SESSION['finance_error'] = "Acesso negado: " . $e->getMessage();
            header("Location: /financeiro");
            exit;
        } catch (\Throwable $e) {
            if ($isJson) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $this->userFacingError('financeiro', $e)]);
                exit;
            }
            $_SESSION['finance_error'] = "Erro ao registrar pagamento: " . $this->userFacingError('Erro ao registrar pagamento', $e);
            header("Location: /financeiro");
            exit;
        }
    }

    public function cancelReceivableAction(array $params): void
    {
        $receivableId = (int)($params['id'] ?? $_POST['receivable_id'] ?? 0);
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\Finance\FinanceService($db);

        $isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
               || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

        try {
            $res = $service->cancelReceivable($receivableId);
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'canceled' => $res]);
                exit;
            }
            $_SESSION['finance_success'] = "Lançamento cancelado.";
            header("Location: /financeiro");
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            if ($isJson) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $_SESSION['finance_error'] = "Acesso negado: " . $e->getMessage();
            header("Location: /financeiro");
            exit;
        } catch (\Throwable $e) {
            if ($isJson) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $this->userFacingError('financeiro', $e)]);
                exit;
            }
            $_SESSION['finance_error'] = "Erro ao cancelar recebível: " . $this->userFacingError('Erro ao cancelar recebível', $e);
            header("Location: /financeiro");
            exit;
        }
    }

    public function settings(): void
    {
        (new SettingsController())->show();
    }
    private function scheduleError(int $status, string $message, string $activeNav): void
    {
        http_response_code($status);
        $title = 'Não foi possível carregar a agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        ?>
        <div class="op-message op-message--error card" role="alert">
          <h1>Não foi possível carregar a agenda</h1>
          <p><?= htmlspecialchars($message) ?></p>
          <a class="btn btn-primary" href="/<?= $activeNav ?>">Tentar novamente</a>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function index(): void
    {
        $patientRepo = new PatientRepository();
        $patients = $patientRepo->listAll();

        $appointmentService = new AppointmentService();
        // Resolve the local day once so a request spanning midnight stays consistent.
        $today = $appointmentService->todayDate();
        try {
            $todayAppointments = $appointmentService->listByDay($today);
            $todayStats = $appointmentService->getTodayStats($today);
            $weekStats = $appointmentService->getWeekStats($today);
            $attentionItems = $appointmentService->getAttentionRequired($today);
        } catch (\PDOException $e) {
            $this->scheduleError(503, 'Não foi possível carregar as consultas e pendências. Tente novamente.', 'dashboard');
            return;
        }

        $db = Connection::getInstance();
        $clinicId = TenantContext::getClinicId();

        $draftSummary = new DraftPlanSummary($db);
        // Definição única (card + lista): pacientes com ao menos um plano em rascunho; o total de rascunhos é complementar.
        $draftTotals = $draftSummary->totals($clinicId);
        $draftPatientsCount = $draftTotals['patients'];
        $draftPlansTotal = $draftTotals['draft_plans'];

        // NI2-610: rascunhos de anamnese e de exames e o agendamento online pendente entram na mesma lista,
        // ordenada por prioridade (perigo, aviso, informação).
        $isClinicalActor = \ClyoNutri\Core\Security\ClinicalAuthorization::isClinical(\ClyoNutri\Core\Auth\AuthContext::get()?->toArray() ?? []);
        $attentionItems = \ClyoNutri\Domain\Dashboard\PendingWorkSummary::sortByPriority(array_merge($attentionItems,
            (new \ClyoNutri\Domain\Dashboard\PendingWorkSummary($db))->items($clinicId, $this->getActiveProfessionalId(), $isClinicalActor)));
        // A lista de atenção é limitada (top-N); o restante fica acessível via links "Ver todos".
        $attentionTotal = count($attentionItems);
        $attentionVisible = array_slice($attentionItems, 0, self::DASHBOARD_ATTENTION_LIMIT);
        $attentionHidden = $attentionTotal - count($attentionVisible);

        $engagementService = new EngagementService($db);
        $adherenceMetrics = $engagementService->getClinicAdherenceMetrics($clinicId, 30);

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $onboardingProgress = null;
        $onboardingDone = 0;
        $onboardingPct = 0;
        if ($userId > 0 && $clinicId > 0) {
            $onboardingService = new OnboardingService($db);
            $onboardingProgress = $onboardingService->getOrCreateProgress($userId, $clinicId);
            // "N/5" e "% concluído" derivam da mesma fonte: passos efetivamente concluídos.
            $onboardingDone = min(self::ONBOARDING_TOTAL_STEPS, count(array_unique((array)($onboardingProgress['completed_steps'] ?? []))));
            $onboardingPct = (int)round($onboardingDone / self::ONBOARDING_TOTAL_STEPS * 100);
        }
        // NI2-625: ativação conferida no banco. Aparece enquanto falta item obrigatório; se faltam só primeiros
        // passos, aparece nos 30 dias depois do onboarding (quem não usa plano alimentar não fica com aviso eterno).
        $activation = null; $showActivation = false;
        if ($userId > 0 && $clinicId > 0) {
            try {
                $activation = (new \ClyoNutri\Domain\Onboarding\ActivationChecklist($db))->forUser($clinicId, $userId);
                $recent = !empty($onboardingProgress['updated_at']) && strtotime((string)$onboardingProgress['updated_at']) > time() - 30 * 86400;
                $showActivation = !empty($onboardingProgress['is_completed']) && (!$activation['ready'] || ($activation['done'] < $activation['applicable'] && $recent));
            } catch (\Throwable $e) {
                error_log('NI2-625 dashboard: ' . $e->getMessage());
            }
        }

        $title = 'Visão geral';
        $activeNav = 'dashboard';
        $dashboardV5456Styles = true; // v54.56: carrega dashboard-v54-56.css só no Painel geral
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $arrowIcon = '<svg class="icon-inline" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';
        // NI2-614: estado da assinatura no painel quando o teste terminou. Regra vigente (Configurações > Assinatura):
        // nada é bloqueado e a contratação pelo sistema ainda não está disponível.
        $dashSubscription = null; $dashCanManagePlan = false;
        try {
            // Leitura simples: o painel não cria assinatura nem dispara aviso ao backoffice.
            $dashSubStmt = $db->prepare('SELECT status, trial_ends_at FROM clinic_subscriptions WHERE clinic_id = ? LIMIT 1');
            $dashSubStmt->execute([TenantContext::getClinicId()]);
            $dashSubscription = $dashSubStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($dashSubscription && $dashSubscription['status'] === 'expired') {
                $dashCanManagePlan = (new \ClyoNutri\Domain\Subscription\SubscriptionService($db))->canManageSubscription(TenantContext::getClinicId(), (int)TenantContext::getUserId());
            }
        } catch (\Throwable $e) {
            $dashSubscription = null;
        }

        // v54.56: topo no estilo Talui (saudação, indicadores com comparação real, agenda da semana) e lista acionável logo abaixo.
        $overview = new \ClyoNutri\Domain\Dashboard\DashboardOverview($db);
        // Mesmo nome da barra superior (membro ativo do consultório atual); sem identidade, a saudação fica sem vocativo.
        $dashViewCtx = \ClyoNutri\Core\Auth\ProfessionalViewContext::resolve($db);
        $dashFirstName = ($dashViewCtx['userName'] ?? '') === 'Identidade indisponível' ? '' : \ClyoNutri\Domain\Dashboard\DashboardOverview::firstName($dashViewCtx['userName'] ?? null);
        $dashYesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $dashLastWeekDay = (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
        try {
            $yesterdayStats = $appointmentService->getTodayStats($dashYesterday);
            $prevWeekStats = $appointmentService->getWeekStats($dashLastWeekDay);
            $weekAgenda = $overview->weekAgenda($clinicId, $today);
            $newPatients = $overview->newPatients($clinicId, 30);
            $prevAdherence = $overview->previousAdherence($clinicId, (int)$adherenceMetrics['period_days']);
        } catch (\PDOException $e) {
            $this->scheduleError(503, 'Não foi possível carregar as consultas e pendências. Tente novamente.', 'dashboard');
            return;
        }
        $cmpToday = \ClyoNutri\Domain\Dashboard\DashboardOverview::compareCount((int)$todayStats['total_today'], (int)$yesterdayStats['total_today'], 'vs. ontem');
        $cmpWeek = \ClyoNutri\Domain\Dashboard\DashboardOverview::compareCount((int)$weekStats['total'], (int)$prevWeekStats['total'], 'vs. semana anterior');
        $cmpPatients = \ClyoNutri\Domain\Dashboard\DashboardOverview::compareCount((int)$newPatients['current'], (int)$newPatients['previous'], 'vs. 30 dias anteriores');
        $cmpAdherence = \ClyoNutri\Domain\Dashboard\DashboardOverview::comparePoints($adherenceMetrics['has_data'] ? (float)$adherenceMetrics['average_pct'] : null, $prevAdherence, 'vs. 30 dias anteriores');
        $dashNowTs = strtotime((new \DateTimeImmutable('now', new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo')))->format('Y-m-d H:i:s'));
        $upcomingToday = array_values(array_filter($todayAppointments, static fn($a) => !in_array($a['status'], ['cancelled'], true)));
        $dashCmp = static function (array $c): string {
            return '<span class="dash-trend dash-trend--' . htmlspecialchars($c['tone']) . '" data-testid="dash-trend" data-tone="' . htmlspecialchars($c['tone']) . '">'
                . '<span aria-hidden="true">' . htmlspecialchars($c['label']) . '</span><span class="sr-only">' . htmlspecialchars($c['sr']) . '</span></span>';
        };
        $dashIcon = static function (string $name): string {
            $paths = [
                'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                'week' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h2M14 14h2M8 18h2"/>',
                'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                'chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
                'plan' => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
                'leaf' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
                'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                'check' => '<polyline points="20 6 9 17 4 12"/>',
            ];
            return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
        };

        ob_start();
        ?>
        <div class="dash" data-testid="dashboard-v5456">
        <header class="dash-greeting" data-testid="dash-greeting">
          <div class="dash-greeting__icon" aria-hidden="true"><?= $dashIcon('leaf') ?></div>
          <div>
            <p class="dash-greeting__date" data-testid="dash-date"><?= htmlspecialchars($overview->dateLabel($today)) ?></p>
            <h1 class="dash-greeting__title"><?= htmlspecialchars($overview->greeting()) ?><?= $dashFirstName !== '' ? ', ' . htmlspecialchars($dashFirstName) : '' ?></h1>
            <p class="dash-greeting__subtitle">Painel geral · aqui está o resumo da sua rotina de hoje.</p>
          </div>
          <?php /* v54.57 (UX-110): ações de rotina no topo: buscar paciente por nome e nova consulta */ ?>
          <div class="dash-greeting__actions" data-testid="dash-top-actions">
            <form class="dash-search" method="GET" action="/pacientes" role="search" aria-label="Buscar paciente">
              <label class="sr-only" for="dash-patient-search">Buscar paciente por nome, e-mail ou telefone</label>
              <input type="search" id="dash-patient-search" name="q" class="form-control dash-search__input" placeholder="Buscar paciente" autocomplete="off" maxlength="100" data-testid="dash-patient-search">
              <button type="submit" class="btn btn-outline dash-search__btn">Buscar</button>
            </form>
            <a href="/agenda?novo=1" class="btn btn-primary dash-new-appointment" data-testid="dash-new-appointment">+ Nova consulta</a>
          </div>
        </header>

        <?php if ($dashSubscription && ($dashSubscription['status'] ?? '') === 'expired'): ?>
          <?php $dashTrialEnd = !empty($dashSubscription['trial_ends_at']) ? (new \DateTimeImmutable((string)$dashSubscription['trial_ends_at']))->format('d/m/Y') : null; ?>
          <div class="op-message op-message--info card dashboard-banner" role="status" data-testid="dashboard-trial-expired">
            <div>
              <div class="dashboard-banner__title">Seu período de teste terminou<?= $dashTrialEnd ? ' em ' . htmlspecialchars($dashTrialEnd) : '' ?>.</div>
              <div class="dashboard-banner__text">
                O sistema continua liberado e seus dados seguem salvos. A contratação de um plano pelo sistema ainda não está disponível.
                <?php if ($dashCanManagePlan): ?><a href="/configuracoes/assinatura" class="u-font-semibold u-link">Ver assinatura</a><?php else: ?>Fale com o titular do consultório sobre o plano.<?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($_GET['onboarding']) && $_GET['onboarding'] === 'concluido' && (!$activation || $activation['ready'])): ?>
          <div class="op-message op-message--info card dashboard-banner dashboard-banner--success" role="status">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div>
              <div class="dashboard-banner__title">Consultório ativado. Já dá para agendar e atender.</div>
              <div class="dashboard-banner__text">Suas preferências profissionais e rotinas clínicas foram salvas. Você já pode cadastrar novos pacientes e iniciar consultas.</div>
            </div>
          </div>
        <?php elseif ($showActivation && $activation): $nx = $activation['next']; ?>
          <div class="op-message op-message--info card dashboard-banner dashboard-banner--onboarding" data-testid="activation-banner" role="status">
            <div class="dashboard-banner__body">
              <div class="dashboard-banner__step tabular-nums" aria-hidden="true"><?= (int)$activation['done'] ?>/<?= (int)$activation['applicable'] ?></div>
              <div>
                <div class="dashboard-banner__title"><?= $activation['ready'] ? 'Consultório pronto. Faltam primeiros passos' : 'Ativação do consultório incompleta' ?> (<?= (int)$activation['done'] ?> de <?= (int)$activation['applicable'] ?>)</div>
                <div class="dashboard-banner__text"><?= $nx ? 'Próximo: ' . htmlspecialchars($nx['label']) . '. ' . htmlspecialchars($nx['detail']) : '' ?></div>
              </div>
            </div>
            <a href="/onboarding?passo=5" class="btn <?= $activation['ready'] ? 'btn-secondary' : 'btn-accent' ?> dashboard-banner__cta" data-testid="activation-banner-cta">Ver o que falta <?= $arrowIcon ?></a>
          </div>
        <?php elseif ($onboardingProgress && !$onboardingProgress['is_completed'] && $activation && $activation['ready']): ?>
          <?php /* v54.57 (UX-109): com a prontidão completa, as preferências são opcionais: linha discreta, sem CTA de destaque e sem "% concluído" competindo com o checklist */ ?>
          <p class="dash-optional" data-testid="onboarding-banner" data-variant="optional">
            <span>Personalize suas preferências (opcional) · <span data-testid="onboarding-steps"><?= $onboardingDone ?> de <?= self::ONBOARDING_TOTAL_STEPS ?></span> revisadas<span class="sr-only"> (<span data-testid="onboarding-pct"><?= $onboardingPct ?></span>%)</span></span>
            <a href="/onboarding" class="dash-optional__link">Revisar preferências</a>
          </p>
        <?php elseif ($onboardingProgress && !$onboardingProgress['is_completed']): ?>
          <div class="card dashboard-banner dashboard-banner--onboarding" data-testid="onboarding-banner">
            <div class="dashboard-banner__body">
              <div class="dashboard-banner__step tabular-nums" aria-hidden="true" data-testid="onboarding-steps"><?= $onboardingDone ?>/<?= self::ONBOARDING_TOTAL_STEPS ?></div>
              <div>
                <div class="dashboard-banner__title">
                  Preferências revisadas (<span data-testid="onboarding-pct"><?= $onboardingPct ?></span>% concluído<span class="sr-only">, <?= $onboardingDone ?> de <?= self::ONBOARDING_TOTAL_STEPS ?> passos</span>)
                </div>
                <div class="dashboard-banner__text">
                  Revise estas preferências quando desejar. Este roteiro não determina se o consultório está pronto para atender.
                </div>
              </div>
            </div>
            <a href="/onboarding" class="btn btn-accent dashboard-banner__cta">
              Continuar onboarding
              <?= $arrowIcon ?>
            </a>
          </div>
        <?php endif; ?>

        <?php if ($activation): ?>
          <p class="dash-readiness dash-readiness--<?= $activation['ready'] ? 'ok' : 'pending' ?>" role="status" data-testid="practice-readiness" data-ready="<?= $activation['ready'] ? '1' : '0' ?>">
            <span class="dash-readiness__dot" aria-hidden="true"></span>
            <span>Prontidão para atender: <strong><?= $activation['ready'] ? 'Requisitos aplicáveis atendidos' : 'Há requisitos aplicáveis pendentes' ?></strong> · Checklist <?= (int)$activation['done'] ?> de <?= (int)$activation['applicable'] ?></span>
            <a href="/onboarding?passo=5" class="dash-readiness__link">Ver checklist</a>
          </p>
        <?php endif; ?>

        <?php /* Indicadores com ícone e comparação real com o período anterior (neutro quando não há base) */ ?>
        <div class="dash-metrics" role="region" aria-label="Indicadores da clínica" data-testid="dash-metrics">
          <div class="dash-kpi">
            <div class="dash-kpi__head"><span class="dash-kpi__icon"><?= $dashIcon('calendar') ?></span><span class="dash-kpi__label">Consultas hoje</span></div>
            <div class="dash-kpi__value tabular-nums"><span data-testid="appointments-today-count"><?= (int)$todayStats['total_today'] ?></span></div>
            <div class="dash-kpi__foot"><?= $dashCmp($cmpToday) ?><span class="dash-kpi__note"><?= (int)$todayStats['confirmed'] ?> confirmada(s) · <?= (int)$todayStats['completed'] ?> realizada(s)<?= (int)$todayStats['cancelled'] > 0 ? ' · ' . (int)$todayStats['cancelled'] . ' cancelada(s)' : '' ?></span></div>
          </div>
          <div class="dash-kpi">
            <div class="dash-kpi__head"><span class="dash-kpi__icon"><?= $dashIcon('users') ?></span><span class="dash-kpi__label">Pacientes ativos</span></div>
            <div class="dash-kpi__value tabular-nums"><span data-testid="active-patients-count"><?= count($patients) ?></span></div>
            <div class="dash-kpi__foot"><span class="dash-kpi__note" data-testid="new-patients-30d"><?= (int)$newPatients['current'] ?> novo(s) em 30 dias</span><?= $dashCmp($cmpPatients) ?><a href="/pacientes" class="dash-kpi__link">Ver pacientes</a></div>
          </div>
          <div class="dash-kpi">
            <div class="dash-kpi__head"><span class="dash-kpi__icon"><?= $dashIcon('chart') ?></span><span class="dash-kpi__label">Adesão média</span></div>
            <div class="dash-kpi__value tabular-nums<?= $adherenceMetrics['has_data'] ? '' : ' dash-kpi__value--empty' ?>"><span data-testid="average-adherence-value"><?= htmlspecialchars($adherenceMetrics['formatted_value']) ?></span></div>
            <div class="dash-kpi__foot">
              <?php if ($adherenceMetrics['has_data']): ?>
                <?= $dashCmp($cmpAdherence) ?><span class="dash-kpi__note"><?= (int)$adherenceMetrics['responses_count'] ?> resposta(s) · <?= (int)$adherenceMetrics['responding_patients_count'] ?> de <?= (int)$adherenceMetrics['total_eligible_patients'] ?> paciente(s) · <?= htmlspecialchars($adherenceMetrics['period_label']) ?></span>
              <?php else: ?>
                <span class="dash-trend dash-trend--neutral" data-testid="dash-trend" data-tone="neutral">Sem dados (<?= (int)$adherenceMetrics['period_days'] ?> dias)</span><span class="dash-kpi__note">0 respostas · <?= (int)$adherenceMetrics['total_eligible_patients'] ?> paciente(s) elegíveis</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="dash-kpi<?= $draftPatientsCount > 0 ? ' dash-kpi--accent' : '' ?>" data-testid="dash-kpi-drafts">
            <div class="dash-kpi__head"><span class="dash-kpi__icon"><?= $dashIcon('plan') ?></span><span class="dash-kpi__label">Planos a revisar</span></div>
            <div class="dash-kpi__value tabular-nums"><span data-testid="draft-patients-count"><?= $draftPatientsCount ?></span></div>
            <div class="dash-kpi__foot">
              <span class="dash-trend dash-trend--<?= $draftPatientsCount > 0 ? 'warn' : 'ok' ?>"><?= $draftPatientsCount > 0 ? 'paciente(s) com rascunho' : 'todos publicados' ?></span>
              <?php if ($draftPlansTotal > 0): ?><span class="dash-kpi__note"><span data-testid="draft-plans-total"><?= $draftPlansTotal ?></span> plano(s) em rascunho · <a href="/planos?status=draft">Ver rascunhos</a></span><?php endif; ?>
            </div>
          </div>
        </div>

        <div class="dash-grid">
          <section class="dash-card dash-week" aria-labelledby="dash-week-title" data-testid="dash-week">
            <div class="dash-card__header">
              <h2 id="dash-week-title" class="dash-card__title">Agenda da semana</h2>
              <div class="dash-card__tools">
                <span class="dash-week__range tabular-nums" data-testid="dash-week-range"><?= htmlspecialchars($weekAgenda['label']) ?> · <span data-testid="appointments-week-count"><?= (int)$weekAgenda['total'] ?></span> consulta(s)</span>
                <?= $dashCmp($cmpWeek) ?>
                <a href="/agenda?date=<?= htmlspecialchars($today) ?>&amp;view=week" class="dash-link">Ver agenda completa <?= $arrowIcon ?></a>
              </div>
            </div>
            <ol class="dash-week__days">
              <?php foreach ($weekAgenda['days'] as $wd): ?>
                <li class="dash-day<?= $wd['is_today'] ? ' dash-day--today' : '' ?><?= $wd['is_past'] ? ' dash-day--past' : '' ?>" data-testid="dash-week-day" data-date="<?= htmlspecialchars($wd['date']) ?>" data-count="<?= (int)$wd['count'] ?>"<?= $wd['is_today'] ? ' aria-current="date"' : '' ?>>
                  <div class="dash-day__head">
                    <span class="dash-day__weekday"><abbr title="<?= htmlspecialchars($wd['weekday_long']) ?>"><?= htmlspecialchars($wd['weekday']) ?></abbr></span>
                    <span class="dash-day__date"><?= htmlspecialchars($wd['day_label']) ?><?= $wd['is_today'] ? ' · hoje' : '' ?></span>
                  </div>
                  <?php if (!$wd['items']): ?>
                    <p class="dash-day__empty">Sem consultas</p>
                  <?php else: ?>
                    <ul class="dash-day__list">
                      <?php foreach ($wd['items'] as $wa): $waDone = in_array($wa['status'], ['completed', 'no_show'], true); ?>
                        <li>
                          <a class="dash-appt dash-appt--<?= htmlspecialchars((string)$wa['status']) ?>" data-testid="dash-week-appt" href="<?= $waDone ? '/pacientes/' . (int)$wa['patient_id'] : '/consulta/' . (int)$wa['id'] ?>">
                            <span class="dash-appt__time tabular-nums"><?= htmlspecialchars(substr((string)$wa['scheduled_at'], 11, 5)) ?></span>
                            <span class="dash-appt__name" title="<?= htmlspecialchars((string)$wa['patient_name']) ?>"><?= htmlspecialchars((string)$wa['patient_name']) ?></span>
                            <span class="dash-appt__meta"><?= htmlspecialchars(LabelMap::appointmentType($wa['type'])) ?> · <?= htmlspecialchars(LabelMap::appointmentStatus($wa['status'])) ?></span>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                    <?php if ($wd['hidden'] > 0): ?><a class="dash-day__more" href="/agenda?date=<?= htmlspecialchars($wd['date']) ?>&amp;view=day">+<?= (int)$wd['hidden'] ?> neste dia</a><?php endif; ?>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          </section>

          <section class="dash-card dash-today" aria-labelledby="today-title" data-testid="dash-today">
            <div class="dash-card__header">
              <h2 id="today-title" class="dash-card__title">Consultas de hoje</h2>
              <span class="dash-card__hint" data-testid="dash-today-count"><?= count($upcomingToday) ?> no dia<?= (int)$todayStats['cancelled'] > 0 ? ' · canceladas fora da lista' : '' ?></span>
            </div>
            <?php if (empty($upcomingToday)): ?>
              <div class="dash-empty">
                <p>Nenhuma consulta agendada para hoje.</p>
                <a href="/agenda" class="dash-link">Abrir agenda <?= $arrowIcon ?></a>
              </div>
            <?php else: ?>
              <ul class="dash-today__list">
                <?php foreach ($upcomingToday as $app): $appTs = strtotime((string)$app['scheduled_at']); $appInitials = implode('', array_map(static fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/u', trim((string)$app['patient_name'])) ?: [], 0, 2))); ?>
                  <li class="dash-today__item<?= $app['status'] !== 'completed' && $appTs < $dashNowTs ? ' dash-today__item--late' : '' ?>" data-testid="dash-today-item">
                    <span class="dash-avatar" aria-hidden="true"><?= htmlspecialchars($appInitials ?: 'P') ?></span>
                    <div class="dash-today__info">
                      <a class="dash-today__name" href="/pacientes/<?= (int)$app['patient_id'] ?>"><?= htmlspecialchars($app['patient_name']) ?></a>
                      <span class="dash-today__meta tabular-nums"><?= date('H:i', $appTs) ?> · <?= htmlspecialchars(LabelMap::appointmentType($app['type'])) ?></span>
                      <span class="dash-today__status"><?= LabelMap::appointmentStatusBadge($app['status']) ?></span>
                    </div>
                    <?php if ($app['status'] === 'completed' || $app['status'] === 'no_show'): ?>
                      <a href="/pacientes/<?= (int)$app['patient_id'] ?>" class="btn btn-secondary btn-touch btn-touch--compact" aria-label="Prontuário de <?= htmlspecialchars($app['patient_name']) ?>">Prontuário</a>
                    <?php else: ?>
                      <a href="/consulta/<?= (int)$app['id'] ?>" class="btn btn-primary btn-touch btn-touch--compact" aria-label="Atender <?= htmlspecialchars($app['patient_name']) ?> às <?= date('H:i', $appTs) ?>">Atender</a>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>

        <!-- Seção: Pacientes e Prioridades que Exigem Atenção (v54.56: logo abaixo do topo, 4 itens + "Ver todos") -->
        <section class="dash-card dash-quick" aria-labelledby="quick-actions-title">
          <div class="dash-card__header">
            <h2 id="quick-actions-title" class="dash-card__title">Atendimento nutricional</h2>
          </div>
          <div class="dash-quick__grid">
            <a href="/pacientes?novo=1" class="dash-quick__item" data-testid="quick-new-patient">
              <span class="dash-quick__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
              <span><span class="dash-quick__label">Novo paciente</span><span class="dash-quick__hint">Cadastro e consentimento</span></span>
            </a>
            <a href="/planos" class="dash-quick__item">
              <span class="dash-quick__icon" aria-hidden="true"><?= $dashIcon('plan') ?></span>
              <span><span class="dash-quick__label">Criar plano alimentar</span><span class="dash-quick__hint">Prescrição e modelos</span></span>
            </a>
            <a href="/alimentos" class="dash-quick__item">
              <span class="dash-quick__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
              <span><span class="dash-quick__label">Consultar Tabela TACO</span><span class="dash-quick__hint">Composição dos alimentos</span></span>
            </a>
            <a href="/agenda" class="dash-quick__item">
              <span class="dash-quick__icon" aria-hidden="true"><?= $dashIcon('clock') ?></span>
              <span><span class="dash-quick__label">Agendar consulta</span><span class="dash-quick__hint">Abrir a agenda</span></span>
            </a>
          </div>
        </section>

        <section class="dash-card attention-panel dash-attention" aria-labelledby="attention-title" data-testid="attention-panel">
          <div class="dash-card__header">
            <h2 id="attention-title" class="dash-card__title">Pacientes e prioridades que exigem atenção</h2>
            <span class="attention-panel__count dash-card__hint" data-testid="attention-count"><?= $attentionTotal ?> item(ns) identificado(s)</span>
          </div>
          <?php if (empty($attentionItems)): ?>
            <div class="attention-panel__empty">
              <svg class="icon-inline icon-success" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"/></svg>
              Nenhuma pendência crítica ou falta sem retorno identificada no momento. Todas as rotinas estão regulares.
            </div>
          <?php else: ?>
            <ul class="attention-list dash-attention__list">
              <?php foreach ($attentionVisible as $att): ?>
                <li class="attention-item" data-testid="attention-item" data-type="<?= htmlspecialchars((string)$att['type']) ?>">
                  <div class="attention-item__text">
                    <span class="badge badge-<?= $att['severity'] === 'danger' ? 'danger' : ($att['severity'] === 'warning' ? 'warning' : 'info') ?> attention-item__badge">
                      <?= htmlspecialchars($att['title']) ?>
                    </span>
                    <span class="attention-item__description">
                      <?= htmlspecialchars($att['description']) ?>
                    </span>
                  </div>
                  <a href="<?= htmlspecialchars($att['action_url']) ?>" class="btn btn-secondary btn-touch" data-testid="attention-action-<?= htmlspecialchars((string)$att['type']) ?>">
                    <?= htmlspecialchars($att['action_label']) ?>
                    <?= $arrowIcon ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($attentionHidden > 0): ?>
              <?php /* NI2-610: os demais itens ficam a um clique, sem sair do painel */ ?>
              <details class="attention-more dash-attention__more" data-testid="attention-more">
                <summary class="btn btn-outline btn-touch">Ver todos (<?= (int)$attentionTotal ?>)<span class="sr-only">: mais <?= (int)$attentionHidden ?> item(ns)</span></summary>
                <ul class="attention-list dash-attention__list">
                  <?php foreach (array_slice($attentionItems, count($attentionVisible)) as $att): ?>
                    <li class="attention-item" data-testid="attention-item" data-type="<?= htmlspecialchars((string)$att['type']) ?>">
                      <div class="attention-item__text">
                        <span class="badge badge-<?= $att['severity'] === 'danger' ? 'danger' : ($att['severity'] === 'warning' ? 'warning' : 'info') ?> attention-item__badge"><?= htmlspecialchars($att['title']) ?></span>
                        <span class="attention-item__description"><?= htmlspecialchars($att['description']) ?></span>
                      </div>
                      <a href="<?= htmlspecialchars($att['action_url']) ?>" class="btn btn-secondary btn-touch" data-testid="attention-action-<?= htmlspecialchars((string)$att['type']) ?>"><?= htmlspecialchars($att['action_label']) ?> <?= $arrowIcon ?></a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
              <div class="attention-panel__footer dash-attention__footer">
                <span>Mostrando <?= count($attentionVisible) ?> de <?= $attentionTotal ?>.</span>
                <a href="/agenda" class="btn btn-outline btn-touch">Ver agenda</a>
                <?php if ($draftPatientsCount > 0): ?>
                  <a href="/planos?status=draft" class="btn btn-outline btn-touch" data-testid="attention-see-all">Ver todos os rascunhos (<?= $draftPlansTotal ?>)</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>


        </div>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function patients(): void
    {
        $patientRepo = new PatientRepository();
        $query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        if (!in_array($statusFilter, ['', 'active','inactive','anonymized'], true)) $statusFilter = '';
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $listError = null;
        $today = (new \ClyoNutri\Core\Time\ClinicCalendar())->today();
        $month = substr($today, 5, 2);
        $monthNames = ['01'=>'Janeiro','02'=>'Fevereiro','03'=>'Março','04'=>'Abril','05'=>'Maio','06'=>'Junho','07'=>'Julho','08'=>'Agosto','09'=>'Setembro','10'=>'Outubro','11'=>'Novembro','12'=>'Dezembro'];
        try {
            $listing = $patientRepo->paginate($query, $statusFilter, $page);
            $patients = $listing['patients'];
            $birthdays = $patientRepo->birthdays($today);
        } catch (\Throwable $e) {
            $listError = 'Não foi possível carregar os pacientes. Tente novamente.';
            $listing = ['patients'=>[], 'total'=>0, 'page'=>1, 'pages'=>1]; $patients = []; $birthdays = [];
        }

        $submissionId = bin2hex(random_bytes(16));
        $_SESSION['patient_submissions'] = array_slice($_SESSION['patient_submissions'] ?? [], -20, null, true);
        $_SESSION['patient_submissions'][$submissionId] = 'pending';
        $term = Connection::getInstance()->query("SELECT * FROM consent_terms WHERE purpose_code = 'clinical_care' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
        $patientForm = $_SESSION['patient_form'] ?? [];
        $patientError = $_SESSION['patient_error'] ?? null;
        $createdId = $_SESSION['patient_created_id'] ?? null;
        $createdName = $_SESSION['patient_created_name'] ?? null;
        unset($_SESSION['patient_created_id'], $_SESSION['patient_created_name']);
        $duplicateId = $_SESSION['patient_duplicate_id'] ?? null;
        // NI2-414 (V4-UX-01): o preenchimento devolvido após erro é exibido uma única vez, junto com o erro.
        // Se o usuário descartar, recarregar ou voltar depois, os dados não reaparecem como se fossem estado limpo.
        unset($_SESSION['patient_error'], $_SESSION['patient_duplicate_id'], $_SESSION['patient_form']);
        $title = 'Pacientes';
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <?php if (!empty($_GET['success'])): ?>
          <div class="op-message op-message--success u-mb-4" role="status" data-testid="patient-success-toast">
            <div class="u-font-semibold u-text-body u-mb-1">
              <?= $_GET['success'] === 'atualizado' ? 'Dados do paciente atualizados.' : ($createdName ? 'Paciente ' . htmlspecialchars($createdName) . ' cadastrado com sucesso!' : 'Paciente cadastrado com sucesso!') ?>
            </div>
            <div class="u-text-compact u-color-semantic-success u-mb-2">
              Próximo passo: abra o perfil para agendar ou iniciar o atendimento.
            </div>
            <div class="u-flex u-gap-2 u-items-center u-wrap">
              <?php if ($createdId): ?>
                <a class="btn btn-primary" href="/pacientes/<?= (int)$createdId ?>" data-testid="toast-open-patient-btn">Abrir perfil de <?= htmlspecialchars($createdName ?? 'paciente') ?> →</a>
                <a class="btn btn-secondary" href="/pacientes/<?= (int)$createdId ?>/anamnese">Iniciar anamnese</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($patientError): ?><div class="op-message op-message--error card" role="alert"><?= htmlspecialchars($patientError) ?>
        <?php if ($duplicateId): ?><a href="/pacientes/<?= (int)$duplicateId ?>/editar">Conferir cadastro existente</a><?php endif; ?></div><?php endif; ?>
        <?php if ($listError): ?><div class="op-message op-message--error card" role="alert"><?= htmlspecialchars($listError) ?> <a href="/pacientes">Tentar novamente</a></div><?php endif; ?>
        <div class="ds-page-header" data-testid="patients-page-header">
          <div>
            <h1>Gestão de Pacientes</h1>
            <p class="u-color-neutral-gray-500">Cadastro, histórico de acompanhamento e prontuários.</p>
          </div>
          <div class="ds-page-actions">
            <a href="/pacientes/importar" class="btn btn-outline" data-testid="patient-import-link">Importar planilha</a>
            <button type="button" class="btn btn-primary" aria-haspopup="dialog" aria-controls="patientModal" data-call="openPatientModal">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Cadastrar paciente</span>
          </button>
          </div>
        </div>

        <section class="card" aria-labelledby="patient-birthdays-heading">
          <div class="u-flex u-items-center u-justify-between u-gap-3 u-wrap u-mb-4">
            <h2 id="patient-birthdays-heading" class="u-text-h3 u-m-0">Aniversariantes do mês · <?= $monthNames[$month] ?></h2>
            <span class="badge badge-info"><?= count($birthdays) ?> paciente<?= count($birthdays) === 1 ? '' : 's' ?></span>
          </div>
          <?php if (!$birthdays): ?>
            <p class="u-color-neutral-gray-500 u-m-0">Nenhum paciente com aniversário neste mês. A lista considera as datas de nascimento cadastradas.</p>
          <?php else: ?>
            <ul class="u-list-none u-p-0 u-m-0 u-grid u-cols-auto-md u-gap-3">
              <?php foreach ($birthdays as $birthday): $isToday = substr($birthday['birth_date'], 5) === substr($today, 5); ?>
              <li data-patient-birthday="<?= (int)$birthday['id'] ?>" class="u-border u-bc-neutral-gray-300 u-radius-input u-p-4">
                <div class="u-font-bold u-color-botanical-forest-900 u-mb-2"><?= htmlspecialchars($birthday['name']) ?></div>
                <div class="u-mb-3">
                  <span class="badge badge-<?= $isToday ? 'success' : 'info' ?>"><?= substr($birthday['birth_date'], 8, 2) ?>/<?= $month ?><?= $isToday ? ' · Hoje!' : '' ?></span>
                </div>
                <a class="btn btn-secondary" href="/pacientes/<?= (int)$birthday['id'] ?>" aria-label="Ver perfil de <?= htmlspecialchars($birthday['name'], ENT_QUOTES) ?>">Ver perfil</a>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <div class="card">
          <form method="get" action="/pacientes" class="u-mb-4">
            <label for="patient-search" class="form-label">Buscar paciente por nome, celular ou e-mail</label>
            <div class="u-flex u-gap-2 u-items-center u-wrap">
              <input type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" id="patient-search" class="form-control u-flex-1 u-minw-md" placeholder="Digite o nome, celular ou e-mail" autocomplete="off" aria-controls="patient-list">
              <label for="patient-status">Status</label>
              <select id="patient-status" name="status" class="form-control u-w-auto">
                <?php foreach ([''=>'Todos','active'=>'Ativos','inactive'=>'Inativos','anonymized'=>'Anonimizados'] as $value=>$label): ?>
                <option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary">Buscar</button>
              <a href="/pacientes" class="btn btn-secondary">Limpar</a>
            </div>
            <p id="patient-search-count" class="op-message op-message--info u-text-compact u-color-neutral-gray-500 u-mt-2" role="status" aria-live="polite"><?= (int)$listing['total'] ?> paciente(s) encontrado(s).</p>
          </form>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Nome</th>
                  <th>E-mail</th>
                  <th>Telefone</th>
                  <th>Status</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody id="patient-list">
                <?php if (empty($patients)): ?>
                  <tr id="patient-list-empty"><td colspan="5" class="u-text-center u-color-neutral-gray-500 u-p-6"><?= $query !== '' || $statusFilter !== '' ? 'Nenhum paciente encontrado para estes filtros.' : 'Nenhum paciente cadastrado nesta clínica.' ?></td></tr>
                <?php else: ?>
                  <?php foreach ($patients as $p): ?>
                    <tr data-patient-row>
                      <td class="u-font-semibold u-color-botanical-forest-900">
                        <a href="/pacientes/<?= (int)$p['id'] ?>" class="patient-name-link u-color-botanical-forest-900 u-no-underline u-font-bold" data-testid="patient-row-link-<?= (int)$p['id'] ?>">
                          <?= htmlspecialchars($p['name']) ?>
                        </a>
                      </td>
                      <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($p['phone'] ?? '—') ?></td>
                      <td><span class="badge <?= $p['status'] === 'active' ? 'badge-success' : 'badge-secondary' ?>"><?= ['active'=>'Ativo','inactive'=>'Inativo','anonymized'=>'Anonimizado'][$p['status']] ?? 'Não informado' ?></span></td>
                      <td>
                        <a href="/pacientes/<?= $p['id'] ?>" class="btn btn-secondary u-minh-touch u-py-0 u-px-3 u-text-compact">Ver perfil</a>
                        <a href="/pacientes/<?= $p['id'] ?>/editar" class="btn btn-secondary u-minh-touch u-text-compact">Editar</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                <tr id="patient-search-empty" hidden><td colspan="5" class="u-text-center u-color-neutral-gray-500 u-p-6">Nenhum paciente encontrado para esta busca.</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <nav aria-label="Paginação de pacientes" class="u-flex u-gap-3 u-my-4 u-mx-0 u-items-center">
          <?php if ($listing['page'] > 1): ?><a class="btn btn-secondary" href="/pacientes?<?= htmlspecialchars(http_build_query(['q'=>$query,'status'=>$statusFilter,'page'=>$listing['page']-1])) ?>">Anterior</a><?php endif; ?>
          <span>Página <?= $listing['page'] ?> de <?= $listing['pages'] ?></span>
          <?php if ($listing['page'] < $listing['pages']): ?><a class="btn btn-secondary" href="/pacientes?<?= htmlspecialchars(http_build_query(['q'=>$query,'status'=>$statusFilter,'page'=>$listing['page']+1])) ?>">Próxima</a><?php endif; ?>
        </nav>
        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
        (function(){
          var searchForm = document.querySelector('form[action="/pacientes"][method="get"]');
          if (searchForm) {
            searchForm.addEventListener('submit', function(){
              var btn = this.querySelector('button[type="submit"]');
              if (btn) { btn.disabled = true; btn.textContent = 'Buscando…'; }
            });
          }

          var searchInput = document.getElementById('patient-search');
          var patientRows = document.querySelectorAll('tbody#patient-list tr[data-patient-row]');
          var emptyMsg = document.getElementById('patient-search-empty');
          var countMsg = document.getElementById('patient-search-count');
          var totalRows = patientRows.length;

          if (searchInput && totalRows > 0) {
            searchInput.addEventListener('input', function() {
              var term = (searchInput.value || '').trim().toLowerCase();
              if (term.length >= 3) {
                var visibleCount = 0;
                patientRows.forEach(function(row) {
                  var text = (row.textContent || '').toLowerCase();
                  if (text.indexOf(term) !== -1) {
                    row.style.display = '';
                    visibleCount++;
                  } else {
                    row.style.display = 'none';
                  }
                });
                if (emptyMsg) emptyMsg.hidden = (visibleCount > 0);
                if (countMsg) countMsg.textContent = visibleCount + ' paciente(s) encontrado(s) na listagem.';
              } else if (term.length === 0) {
                patientRows.forEach(function(row) { row.style.display = ''; });
                if (emptyMsg) emptyMsg.hidden = true;
                if (countMsg) countMsg.textContent = totalRows + ' paciente(s) encontrado(s).';
              }
            });
          }
        })();
        </script>

        <!-- Modal Cadastrar Paciente (Design System Premium) -->
        <div id="patientModal" data-dialog="modal" class="modal-overlay" data-call-self="closePatientModal">
          <div class="cn-dialog__panel modal-dialog">
            <div class="modal-header">
              <h3>Novo paciente</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closePatientModal" aria-label="Fechar modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <form method="POST" action="/pacientes"<?= $patientError ? ' data-start-dirty' : '' ?>>
              <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
              <input type="hidden" name="_submission_id" value="<?= $submissionId ?>">
              <div class="modal-body">
                <?php if ($patientError): ?>
                  <div class="op-message op-message--error u-mb-4" role="alert">
                    <strong>Erro:</strong> <?= htmlspecialchars($patientError) ?>
                    <?php if ($duplicateId): ?>
                      <div class="u-mt-2">
                        <a href="/pacientes/<?= (int)$duplicateId ?>" class="btn btn-sm btn-secondary u-color-semantic-error u-underline u-font-semibold">Conferir cadastro existente →</a>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <div class="form-group">
                  <label class="form-label" for="p_name">Nome completo *</label>
                  <input type="text" id="p_name" name="name" class="form-control" placeholder="Ex: Juliana Mendes" required autofocus>
                </div>

                <div class="form-group">
                  <label class="form-label" for="p_email">E-mail</label>
                  <input type="email" id="p_email" name="email" class="form-control" placeholder="paciente@exemplo.com.br">
                </div>

                <div class="u-grid u-cols-2 u-gap-4">
                  <div class="form-group">
                    <label class="form-label" for="p_phone">Telefone / WhatsApp</label>
                    <input type="tel" id="p_phone" name="phone" class="form-control" placeholder="(11) 98765-4321">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="p_birth">Data de nascimento</label>
                    <input type="date" id="p_birth" name="birth_date" class="form-control">
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="p_gender">Sexo registrado para referências clínicas (opcional)</label>
                  <select id="p_gender" name="gender" class="form-control" aria-describedby="p_gender_help">
                    <option value="">Selecione...</option>
                    <option value="Feminino">Feminino</option>
                    <option value="Masculino">Masculino</option>
                    <option value="Outro">Outro / Não informado</option>
                  </select>
                  <small id="p_gender_help" class="u-block u-text-caption u-color-neutral-gray-500 u-mt-1">O parâmetro usado nas equações não define a identidade de gênero. Quando confirmado pelo profissional, pode ser usado na classificação da relação cintura-quadril (RCQ) e na elegibilidade de protocolos de dobras, junto com a idade. Sem os dados exigidos, a classificação ou equação fica indisponível; confirme o parâmetro no contexto da avaliação. Não o deduza da identidade de gênero.</small>
                </div>

                <div class="form-group">
                  <label class="form-label" for="p_allergies">Alergias e intolerâncias alimentares</label>
                  <input type="text" id="p_allergies" name="allergies" class="form-control" placeholder="Ex: amendoim, glúten, leite, frutos do mar...">
                  <label class="u-flex u-items-center u-gap-2 u-text-compact u-mt-1 u-pointer">
                    <input type="checkbox" id="p_allergy_denied" name="allergy_denied" value="1" data-allergy-toggle="p_allergies">
                    <span>Nega alergias alimentares conhecidas</span>
                  </label>
                </div>

                <div class="form-group u-mb-0">
                  <label class="form-label" for="p_notes">Observações iniciais</label>
                  <textarea id="p_notes" name="notes" class="form-control u-minh-field" placeholder="Objetivo clínico, preferências ou observações gerais..."></textarea>
                </div>
                <?php require __DIR__ . '/../../../views/patients/consent-choice.php'; ?>
              </div>
              <div class="modal-footer">
                <button data-dialog-close type="button" class="btn btn-secondary" data-call="closePatientModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar paciente</button>
              </div>
            </form>
          </div>
        </div>

        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          function openPatientModal() {
            document.getElementById('patientModal').classList.add('active');
            document.getElementById('p_name').focus();
          }
          function closePatientModal() {
            document.getElementById('patientModal').classList.remove('active');
          }
          const patientForm = <?= json_encode($patientForm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          Object.entries(patientForm).forEach(([key,value]) => { const input=document.querySelector('#patientModal [name="'+key+'"]'); if(input)input.value=value; });
          <?php if ($patientError || ($_GET['novo'] ?? '') === '1'): ?>openPatientModal();<?php endif; ?>
        </script>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function foods(): void
    {
        $foodService = new FoodCatalogService();
        $foodService->seedTacoReference();

        $query = trim((string)($_GET['q'] ?? ''));
        $category = trim((string)($_GET['category'] ?? ''));
        $source = trim((string)($_GET['source'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPageRaw = (int)($_GET['per_page'] ?? 50);
        $perPage = in_array($perPageRaw, FoodCatalogService::PER_PAGE_ALLOWED, true) ? $perPageRaw : 50;

        $listing = $foodService->paginate([
            'q' => $query,
            'category' => $category,
            'source' => $source,
            'page' => $page,
            'per_page' => $perPage,
        ]);
        $foods = $listing['foods'];
        $categories = $foodService->listCategories();

        $qs = static function (array $overrides = []) use ($listing): string {
            $base = [
                'q' => $listing['q'],
                'category' => $listing['category'],
                'source' => $listing['source'],
                'per_page' => $listing['per_page'],
                'page' => $listing['page'],
            ];
            $merged = array_merge($base, $overrides);
            foreach (['q', 'category', 'source'] as $k) {
                if ($merged[$k] === '' || $merged[$k] === null) {
                    unset($merged[$k]);
                }
            }
            if ((int)$merged['per_page'] === 50) {
                unset($merged['per_page']);
            }
            if ((int)$merged['page'] === 1) {
                unset($merged['page']);
            }
            return http_build_query($merged);
        };

        // v54.41: nutricionista da clínica pode adicionar alimento próprio.
        $canAddFood = \ClyoNutri\Application\Controllers\FoodCatalogController::canManage(\ClyoNutri\Core\Database\Connection::getInstance());
        $foodFeedback = $_SESSION['feedback_message'] ?? null;
        unset($_SESSION['feedback_message']);
        $title = 'Alimentos';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div class="foods-page-header u-flex u-justify-between u-items-start u-mb-6 u-wrap u-gap-4">
          <div>
            <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-mb-1">Tabela de Alimentos (TACO)</h1>
            <p class="u-color-neutral-gray-600 u-m-0 u-text-body">Referência analítica por 100g da Tabela Brasileira de Composição de Alimentos com cálculo proporcional por medida caseira.</p>
          </div>
          <div class="u-flex u-gap-2 u-wrap">
          <?php if ($canAddFood): ?><a href="/alimentos/novo" class="btn btn-primary u-minh-touch u-inline-flex u-items-center u-py-0 u-px-3" data-testid="link-food-create">+ Adicionar alimento</a><?php endif; ?>
          <a href="/medidas-caseiras" class="btn btn-secondary u-minh-touch u-inline-flex u-items-center u-py-0 u-px-3" data-testid="link-clinic-measures">Medidas caseiras da clínica</a>
          <a href="/alimentos/buscas" class="btn btn-secondary u-minh-touch u-inline-flex u-items-center u-py-0 u-px-3" data-testid="link-food-search-report">Buscas e trocas de escolha</a>
          <a href="/alimentos/equivalencias" class="btn btn-secondary u-minh-touch u-inline-flex u-items-center u-py-0 u-px-3" data-testid="link-equivalence-rules">Regras de equivalência</a>
          </div>
        </div>

        <?php if ($foodFeedback): ?><div class="op-message op-message--info u-mb-4" role="status" data-testid="food-create-feedback"><?= htmlspecialchars($foodFeedback) ?></div><?php endif; ?>
        <form method="GET" action="/alimentos" class="foods-filters u-flex u-wrap u-gap-3 u-items-end u-mb-4" data-busy-text="Buscando…">
          <div class="u-flex-1 u-basis-md u-minw-md">
            <label for="food-search-input" class="form-label u-block u-text-compact u-mb-1">Buscar</label>
            <input type="text" id="food-search-input" name="q" value="<?= htmlspecialchars($listing['q']) ?>" placeholder="Alimento ou categoria…" class="form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
          </div>
          <div class="u-flex-0 u-basis-md u-minw-sm">
            <label for="food-category-filter" class="form-label u-block u-text-compact u-mb-1">Grupo TACO</label>
            <select id="food-category-filter" name="category" class="form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              <option value="">Todos os grupos</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $listing['category'] === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="u-flex-0 u-basis-md u-minw-sm">
            <label for="food-source-filter" class="form-label u-block u-text-compact u-mb-1">Fonte</label>
            <select id="food-source-filter" name="source" class="form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              <option value="" <?= $listing['source'] === '' ? 'selected' : '' ?>>Todas</option>
              <option value="TACO" <?= $listing['source'] === 'TACO' ? 'selected' : '' ?>>TACO</option>
              <option value="clinic" <?= $listing['source'] === 'clinic' ? 'selected' : '' ?>>Minha clínica</option>
            </select>
          </div>
          <div class="u-flex-0 u-basis-sm u-minw-sm">
            <label for="food-per-page" class="form-label u-block u-text-compact u-mb-1">Por página</label>
            <select id="food-per-page" name="per_page" class="form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input">
              <?php foreach (FoodCatalogService::PER_PAGE_ALLOWED as $n): ?>
                <option value="<?= $n ?>" <?= (int)$listing['per_page'] === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="u-flex u-gap-2 u-wrap">
            <button type="submit" class="btn btn-primary u-py-2 u-px-4 u-minh-touch">Buscar</button>
            <?php if ($listing['q'] !== '' || $listing['category'] !== '' || $listing['source'] !== '' || (int)$listing['per_page'] !== 50): ?>
              <a href="/alimentos" class="btn btn-outline u-py-2 u-px-3 u-no-underline u-minh-touch u-inline-flex u-items-center">Limpar</a>
            <?php endif; ?>
          </div>
        </form>

        <?php /* Banner Explicativo de Integridade Clínica (PX-16) */ ?>
        <div class="card ds-panel-muted">
          <div class="u-flex u-items-center u-gap-2 u-font-semibold u-color-botanical-forest-900 u-text-compact u-mb-1">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="u-color-botanical-copper-600" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            Diferença entre referência padrão (100g) e medida caseira
          </div>
          <p class="u-m-0 u-color-neutral-gray-600 u-text-compact u-leading-body">
            Os dados laboratoriais oficiais (TACO) são mensurados na <strong>base fixa de 100g</strong>. Na coluna <em>Medida caseira</em>, a quantidade em gramas/ml e a energia resultante são calculadas proporcionalmente para orientar prescrições clínicas seguras sem confusão métrica.
          </p>
        </div>

        <p class="foods-result-summary u-mt-0 u-mb-3 u-mx-0 u-color-neutral-gray-600 u-text-compact" aria-live="polite">
          <?php if ($listing['total'] === 0): ?>
            Nenhum alimento encontrado.
          <?php else: ?>
            Mostrando <?= (int)$listing['from'] ?>–<?= (int)$listing['to'] ?> de <?= (int)$listing['total'] ?>
          <?php endif; ?>
        </p>

        <div class="card u-p-0 u-overflow-hidden">
          <div class="table-responsive">
            <table class="table u-mb-0">
              <thead class="u-bg-neutral-gray-50">
                <tr>
                  <th scope="col" class="u-minw-md">Alimento</th>
                  <th scope="col">Categoria</th>
                  <th scope="col">Medida caseira</th>
                  <th scope="col" class="tabular-nums u-color-botanical-forest-900">Energia na Porção</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Energia (100g)</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Proteína (100g)</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Carboidratos (100g)</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Lipídios (100g)</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($foods)): ?>
                  <tr>
                    <td colspan="8" class="u-text-center u-py-12 u-px-4 u-color-neutral-gray-500">
                      Nenhum alimento encontrado para o termo pesquisado.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($foods as $f):
                    $servingG = (float)($f['serving_size_g'] > 0 ? $f['serving_size_g'] : 100);
                    $energy100 = (float)$f['energy_kcal'];
                    $portionKcal = round(($energy100 * $servingG) / 100, 1);
                    $servingUnit = htmlspecialchars($f['serving_unit'] ?? 'g');
                  ?>
                    <tr>
                      <td class="u-font-semibold u-color-botanical-forest-900"><?= htmlspecialchars($f['name']) ?><?php if (!empty($f['synonym_match'])): ?>
                        <span class="food-synonym-match u-inline-block u-mt-0 u-mr-0 u-mb-0 u-ml-2 u-py-0 u-px-2 u-radius-pill u-text-caption u-font-medium u-color-botanical-forest-900 u-bg-botanical-cream-50 u-border u-bc-botanical-sage-200" data-testid="food-synonym-match" title="O nome não tem o termo buscado; o alimento apareceu pelo nome regional equivalente.">Por sinônimo: <?= htmlspecialchars((string)$f['synonym_match']) ?></span>
                      <?php endif; ?><?php if (!empty($f['taco_code'])): ?>
                        <span class="u-block u-font-regular u-text-caption u-color-neutral-gray-500">TACO nº <?= (int)$f['taco_code'] ?><?= !empty($f['nutrient_flags']) ? ' · ' . htmlspecialchars(str_replace(['protein_g', 'lipid_g', 'carbohydrate_g', 'fiber_g', 'energy_kcal', 'em_branco', ';', '='], ['proteína', 'lipídios', 'carboidratos', 'fibra', 'energia', 'não analisado', ', ', ': '], (string)$f['nutrient_flags'])) : '' ?></span>
                      <?php endif; ?></td>
                      <td><span class="badge badge-info"><?= htmlspecialchars($f['category'] ?? 'Geral') ?></span><?php if (!empty($f['is_custom'])): ?> <span class="badge badge-success" data-testid="food-clinic-badge">Minha clínica</span><?php endif; ?></td>
                      <td>
                        <span class="u-font-medium u-color-neutral-ink-900">
                          <?= $servingG < 100 || $servingUnit !== 'g' ? '1 ' . $servingUnit . ' (' . $servingG . 'g)' : '100g' ?>
                        </span>
                      </td>
                      <td class="tabular-nums u-font-bold u-color-botanical-forest-900">
                        <?= number_format($portionKcal, 1, ',', '.') ?> kcal
                      </td>
                      <td class="tabular-nums u-color-neutral-gray-600"><?= number_format($energy100, 1, ',', '.') ?> kcal</td>
                      <td class="tabular-nums"><?= number_format((float)$f['protein_g'], 1, ',', '.') ?> g</td>
                      <td class="tabular-nums"><?= number_format((float)$f['carbohydrate_g'], 1, ',', '.') ?> g</td>
                      <td class="tabular-nums"><?= number_format((float)$f['lipid_g'], 1, ',', '.') ?> g</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php if ($listing['pages'] > 1 || $listing['total'] > 0): ?>
        <nav class="foods-pagination u-flex u-wrap u-gap-2 u-my-4 u-mx-0 u-items-center u-justify-between" aria-label="Paginação da base de alimentos">
          <span class="u-color-neutral-gray-600 u-text-compact">Página <?= (int)$listing['page'] ?> de <?= (int)$listing['pages'] ?></span>
          <div class="u-flex u-wrap u-gap-1 u-items-center">
            <?php if ($listing['page'] > 1): ?>
              <a class="btn btn-secondary u-minh-touch u-py-0 u-px-3 u-inline-flex u-items-center" href="/alimentos?<?= htmlspecialchars($qs(['page' => 1])) ?>">Primeira</a>
              <a class="btn btn-secondary u-minh-touch u-py-0 u-px-3 u-inline-flex u-items-center" href="/alimentos?<?= htmlspecialchars($qs(['page' => $listing['page'] - 1])) ?>" rel="prev">Anterior</a>
            <?php endif; ?>
            <?php
              $window = 2;
              $startP = max(1, $listing['page'] - $window);
              $endP = min($listing['pages'], $listing['page'] + $window);
              for ($p = $startP; $p <= $endP; $p++):
                $isCurrent = $p === (int)$listing['page'];
            ?>
              <?php if ($isCurrent): ?>
                <span class="btn btn-primary u-minh-touch u-minw-touch u-py-0 u-px-3 u-inline-flex u-items-center u-justify-center u-disabled" aria-current="page"><?= $p ?></span>
              <?php else: ?>
                <a class="btn btn-secondary u-minh-touch u-minw-touch u-py-0 u-px-3 u-inline-flex u-items-center u-justify-center" href="/alimentos?<?= htmlspecialchars($qs(['page' => $p])) ?>"><?= $p ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($listing['page'] < $listing['pages']): ?>
              <a class="btn btn-secondary u-minh-touch u-py-0 u-px-3 u-inline-flex u-items-center" href="/alimentos?<?= htmlspecialchars($qs(['page' => $listing['page'] + 1])) ?>" rel="next">Próxima</a>
              <a class="btn btn-secondary u-minh-touch u-py-0 u-px-3 u-inline-flex u-items-center" href="/alimentos?<?= htmlspecialchars($qs(['page' => $listing['pages']])) ?>">Última</a>
            <?php endif; ?>
          </div>
        </nav>
        <?php endif; ?>

        <p class="taco-attribution u-mt-3 u-mb-0 u-mx-0 u-color-neutral-gray-500 u-text-compact u-leading-body">
          Fonte dos alimentos marcados com nº TACO: NEPA-UNICAMP. <cite>Tabela Brasileira de Composição de Alimentos – TACO</cite>, 4ª ed. revisada e ampliada. Campinas: NEPA-UNICAMP, 2011. Valores por 100 g de parte comestível.
          Tr (traço) e NA (não aplicável) aparecem como 0; "*" (análise em reavaliação na TACO) e "não analisado" também aparecem como 0 e ficam indicados abaixo do nome.
        </p>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

}
