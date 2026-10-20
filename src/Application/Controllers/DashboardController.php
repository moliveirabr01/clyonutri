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
        $_SESSION['patient_form'] = array_filter(array_intersect_key($_POST, array_flip(['name','email','phone','birth_date','gender','notes'])), 'is_string');
        try {
        if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Informe um nome válido.');
        foreach (['email','phone','birth_date','gender','notes','consent_choice','consent_term_id'] as $field) if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new \InvalidArgumentException('Confira os campos do cadastro.');
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Informe um e-mail válido.');
        if (!empty($_POST['birth_date'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $_POST['birth_date']);
            if (!$date || $date->format('Y-m-d') !== $_POST['birth_date'] || $date > new \DateTimeImmutable('today')) throw new \InvalidArgumentException('Informe uma data de nascimento válida.');
        }
        if (!empty($_POST['gender']) && !in_array($_POST['gender'], ['Feminino','Masculino','Outro','female','male','other'], true)) throw new \InvalidArgumentException('Selecione um gênero válido.');
        $repo = new PatientRepository();
        $createdPatient = $repo->create([
            'name' => $name,
            'email' => !empty($_POST['email']) ? trim((string)$_POST['email']) : null,
            'phone' => !empty($_POST['phone']) ? trim((string)$_POST['phone']) : null,
            'birth_date' => !empty($_POST['birth_date']) ? $_POST['birth_date'] : null,
            'gender' => !empty($_POST['gender']) ? $_POST['gender'] : null,
            'notes' => !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null,
            'consent_choice' => $_POST['consent_choice'] ?? 'not_collected',
            'consent_term_id' => $_POST['consent_term_id'] ?? null,
        ]);

        $_SESSION['patient_created_id'] = (int)$createdPatient['id'];
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
            foreach (['name', 'email', 'phone', 'birth_date', 'gender', 'notes'] as $field) {
                $data[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
                if ($field !== 'name' && $data[$field] === '') $data[$field] = null;
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
        $title = 'Editar Paciente';
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
            $request = $service->openRequest($id, $type, 'Solicitação registrada pelo profissional na gestão de pacientes.');
            $export = $type === 'export' ? $service->processExport($request['id'], TenantContext::getUserId()) : null;
            $json = $export ? json_encode($export['export_package'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null;
            $db->commit();
            if ($json !== null) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="dados-paciente-'.$id.'.json"');
                header('Cache-Control: no-store');
                echo $json; return;
            }
            header('Location: /pacientes/'.$id.'?success=privacidade');
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
        $consents = (new \ClyoNutri\Domain\Privacy\ConsentManagementService())->getConsentAuditTrail($id);
        $title = 'Perfil do paciente — ' . $p['name'];
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
        $latestAnamnesis = $anamnesisService->getLatestAnamnesis($id);
        $clinicalSummary = $anamnesisService->getClinicalSummaryForPrescription($id);
        $careStep = (new AppointmentService())->careStepForPatient($id);

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'avaliacao_registrada' ? 'Avaliação antropométrica registrada com sucesso no prontuário!' : ($_GET['success'] === 'prontuario_salvo' ? 'Registro gravado com sucesso no prontuário!' : ($_GET['success'] === 'reagendado' ? 'Consulta reagendada. O horário anterior e o novo ficam registrados na trilha da consulta.' : 'Operação realizada com sucesso!')) ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
          <div style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #B91C1C;">
            <strong>Atenção:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na validação dos dados clínicos.' ?>
          </div>
        <?php endif; ?>

        <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
              <a href="/pacientes" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar para Pacientes</a>
            </div>
            <h1><?= htmlspecialchars($p['name']) ?></h1>
            <p style="color: var(--neutral-gray-500);">Perfil do paciente • Histórico de acompanhamento</p>
          </div>
          <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="/chat?patient_id=<?= $p['id'] ?>" class="btn btn-secondary">Conversar via Chat</a>
            <a href="/pacientes/<?= $p['id'] ?>/editar" class="btn btn-secondary">Editar paciente</a>
            <form method="post" action="/pacientes/<?= $p['id'] ?>/convite-app" style="margin:0">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <button class="btn btn-secondary" type="submit">Gerar convite para o app</button>
            </form>
            <a href="/pacientes/<?= $p['id'] ?>/prontuario" class="btn btn-secondary">
              Prontuário ➔
            </a>
            <a href="/pacientes/<?= $p['id'] ?>/anamnese" class="btn btn-secondary">
              Anamnese Estruturada ➔
            </a>
            <a href="/pacientes/<?= $p['id'] ?>/antropometria" class="btn btn-secondary">
              Antropometria ➔
            </a>
            <button type="button" class="btn btn-primary" data-call="openAnthroModal">
              + Nova Avaliação
            </button>
          </div>
        </div>

        <?php
          $stepApp = $careStep['appointment'];
          $stepWhen = $stepApp ? date('d/m/Y', strtotime($stepApp['scheduled_at'])) . ' às ' . date('H:i', strtotime($stepApp['scheduled_at'])) : '';
          // UX-07 (v14): "em andamento" mostra o início real (trilha), separado do horário agendado.
          $stepStarted = null;
          if ($stepApp && $careStep['state'] === 'continue') {
              try {
                  $stepTimeline = AppointmentService::executionTimeline(['scheduled_at' => $stepApp['scheduled_at'], 'history' => (new AppointmentService())->getStatusHistory((int)$stepApp['id'])]);
                  $stepStarted = $stepTimeline['started_at'] ? date('d/m/Y \à\s H:i', strtotime($stepTimeline['started_at'])) : null;
              } catch (\Throwable $e) {
                  $stepStarted = null;
              }
          }
        ?>
        <?php /* NI2-418 (UX-06): próximo passo do atendimento. GET não cria nem altera consulta. */ ?>
        <section class="card cn-next-step" aria-labelledby="proximo-passo-titulo" data-testid="care-next-step" data-state="<?= htmlspecialchars($careStep['state']) ?>">
          <div>
            <h2 id="proximo-passo-titulo" style="font-size: 1.05rem; margin: 0 0 4px;">Próximo passo com <?= htmlspecialchars($p['name']) ?></h2>
            <p style="margin: 0; color: var(--neutral-gray-600, #475569); font-size: 0.9rem;">
              <?php if ($careStep['state'] === 'continue'): ?>Atendimento em andamento<?= $stepStarted ? ' — iniciado em ' . htmlspecialchars($stepStarted) : '' ?> (agendado para <?= htmlspecialchars($stepWhen) ?>). Continue de onde parou: anamnese, avaliação e plano ficam vinculados a este paciente.
              <?php elseif ($careStep['state'] === 'start_today'): ?>Consulta agendada para hoje (<?= htmlspecialchars(date('H:i', strtotime($stepApp['scheduled_at']))) ?>). Ao iniciar, a consulta passa para "em andamento".
              <?php elseif ($careStep['state'] === 'upcoming'): ?>Próxima consulta em <?= htmlspecialchars($stepWhen) ?>. Você pode revisar a anamnese e os planos antes do atendimento.
              <?php else: ?>Nenhuma consulta agendada. Agende o atendimento para iniciar a sequência anamnese → avaliação → plano.
              <?php endif; ?>
            </p>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if ($careStep['state'] === 'continue'): ?>
              <a class="btn btn-primary" href="/consulta/<?= (int)$stepApp['id'] ?>" data-testid="care-continue">Continuar atendimento</a>
            <?php elseif ($careStep['state'] === 'start_today'): ?>
              <form method="POST" action="/agenda/<?= (int)$stepApp['id'] ?>/status" style="margin: 0;" data-no-dirty data-confirm="<?= htmlspecialchars('Iniciar agora o atendimento de ' . $p['name'] . ' (consulta das ' . date('H:i', strtotime($stepApp['scheduled_at'])) . ')?', ENT_QUOTES) ?>">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="status" value="in_progress">
                <button type="submit" class="btn btn-primary" data-testid="care-start">Iniciar atendimento</button>
              </form>
              <a class="btn btn-secondary" href="/agenda/<?= (int)$stepApp['id'] ?>/reagendar?de=paciente" data-testid="care-reschedule">Reagendar</a>
            <?php elseif ($careStep['state'] === 'upcoming'): ?>
              <a class="btn btn-secondary" href="/agenda?view=day&amp;date=<?= htmlspecialchars(substr($stepApp['scheduled_at'], 0, 10)) ?>">Ver na agenda</a>
              <a class="btn btn-secondary" href="/agenda/<?= (int)$stepApp['id'] ?>/reagendar?de=paciente" data-testid="care-reschedule">Reagendar</a>
              <a class="btn btn-primary" href="/pacientes/<?= (int)$p['id'] ?>/anamnese">Revisar anamnese</a>
            <?php else: ?>
              <a class="btn btn-primary" href="/agenda?novo=1&amp;paciente=<?= (int)$p['id'] ?>" data-testid="care-schedule">Agendar atendimento</a>
            <?php endif; ?>
          </div>
        </section>
        <nav aria-label="Seções do perfil">
          <ul class="cn-section-nav">
            <li><a href="#secao-dados">Dados</a></li>
            <li><a href="#secao-prontuario">Prontuário</a></li>
            <li><a href="#secao-anamnese">Anamnese</a></li>
            <li><a href="#secao-planos">Planos</a></li>
            <li><a href="#secao-avaliacoes">Avaliações</a></li>
            <li><a href="#patient-appointments">Consultas</a></li>
            <li><a href="#patient-checkins">Check-ins</a></li>
            <li><a href="#patient-finance">Financeiro</a></li>
            <li><a href="#patient-consents">Privacidade</a></li>
          </ul>
        </nav>

        <div class="cn-split">
          <!-- Coluna 1: Dados Cadastrais -->
          <div class="card" id="secao-dados">
            <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Dados Essenciais</h3>
            <p style="margin-bottom: var(--space-2);"><strong>E-mail:</strong> <?= htmlspecialchars($p['email'] ?? '—') ?></p>
            <p style="margin-bottom: var(--space-2);"><strong>Telefone:</strong> <?= htmlspecialchars($p['phone'] ?? '—') ?></p>
            <p style="margin-bottom: var(--space-2);"><strong>Nascimento:</strong> <?= $p['birth_date'] ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></p>
            <p style="margin-bottom: var(--space-2);"><strong>Gênero:</strong> <?= htmlspecialchars(LabelMap::patientGender($p['gender'] ?? null)) ?></p>
            <?php if (!empty($p['notes'])): ?>
              <div style="margin-top: var(--space-4); padding-top: var(--space-4); border-top: var(--border-default);">
                <strong>Observações:</strong>
                <p style="color: var(--neutral-gray-500); margin-top: 4px;"><?= nl2br(htmlspecialchars($p['notes'])) ?></p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Coluna 2: Prontuário, Anamnese, Avaliações e Planos -->
          <div>
            <!-- Card 1: Prontuário Eletrônico & Evoluções -->
            <div class="card" id="secao-prontuario" style="margin-bottom: var(--space-6);">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div>
                  <h3 style="margin: 0; display: inline-block;">Prontuário</h3>
                  <?php if ($integrity['is_valid'] && (int)$integrity['total_records'] > 0): ?>
                    <span class="badge badge-success" style="font-size: 0.75rem; margin-left: 8px;">✓ <?= !empty($integrity['legacy_records']) ? 'Conteúdo e encadeamento verificados' : 'Integridade dos registros verificada' ?></span>
                  <?php elseif (!$integrity['is_valid']): ?>
                    <span class="badge" style="background: #FEE2E2; color: #DC2626; font-size: 0.75rem; margin-left: 8px;">⚠️ Os registros precisam de verificação</span>
                  <?php endif; ?>
                </div>
                <a href="/pacientes/<?= $p['id'] ?>/prontuario" class="btn btn-sm btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 2px 8px;">
                  Abrir Prontuário Completo ➔
                </a>
              </div>

              <?php if (empty($clinicalRecords)): ?>
                <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">Nenhum registro clínico adicionado ao prontuário até o momento.</p>
              <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                  <?php foreach (array_slice($clinicalRecords, 0, 3) as $cr): ?>
                    <div style="padding: 10px 12px; background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 6px; font-size: 0.875rem;">
                      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <strong style="color: var(--brand-navy-900);"><?= htmlspecialchars($cr['title']) ?></strong>
                        <div style="display: flex; gap: 6px; align-items: center;">
                          <span style="font-size: 0.75rem; color: #64748B;">v<?= $cr['version'] ?> • <?= date('d/m/Y H:i', strtotime($cr['created_at'])) ?></span>
                          <?php if ($cr['is_retracted']): ?>
                            <span class="badge badge-warning" style="font-size: 0.7rem;">Retificado</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div style="color: var(--neutral-gray-600); font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        <?= htmlspecialchars($cr['content']['text'] ?? $cr['content']['evolution_summary'] ?? json_encode($cr['content'], JSON_UNESCAPED_UNICODE)) ?>
                      </div>
                      <div style="margin-top: 4px; font-size: 0.7rem; color: #94A3B8; font-family: monospace;">
                        Responsável: <?= htmlspecialchars($cr['professional_name']) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Card 2: Anamnese Nutricional Vigente & Resumo Crítico -->
            <div class="card" id="secao-anamnese" style="margin-bottom: var(--space-6);">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div>
                  <h3 style="margin: 0; display: inline-block;">Anamnese Nutricional</h3>
                  <?php if ($latestAnamnesis): ?>
                    <span class="badge badge-info" style="font-size: 0.75rem; margin-left: 8px;">
                      v<?= $latestAnamnesis['version'] ?> <?= $latestAnamnesis['is_draft'] ? '(Rascunho)' : '(Vigente)' ?> • <?= date('d/m/Y', strtotime($latestAnamnesis['created_at'])) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div style="display: flex; gap: 6px;">
                  <a href="/pacientes/<?= $p['id'] ?>/anamnese" class="btn btn-sm btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 2px 8px;">
                    <?= $latestAnamnesis ? 'Atualizar / Ver Histórico ➔' : '+ Realizar Anamnese ➔' ?>
                  </a>
                </div>
              </div>

              <?php if (!$latestAnamnesis): ?>
                <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">Nenhuma anamnese cadastrada para este paciente. Clique acima para coletar objetivos e restrições.</p>
              <?php else: ?>
                <!-- Destaques Críticos da Anamnese -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 10px;">
                  <!-- Alergias -->
                  <div style="padding: 8px 12px; border-radius: 6px; background: <?= !empty($latestAnamnesis['allergies']) ? '#FEF2F2' : '#F8FAFC' ?>; border: 1px solid <?= !empty($latestAnamnesis['allergies']) ? '#FCA5A5' : '#E2E8F0' ?>;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: <?= !empty($latestAnamnesis['allergies']) ? '#DC2626' : '#64748B' ?>; display: block;">
                      <?= !empty($latestAnamnesis['allergies']) ? '⚠️ ALERGIAS (CRÍTICO)' : 'ALERGIAS' ?>
                    </span>
                    <span style="font-size: 0.85rem; color: #1E293B;">
                      <?= htmlspecialchars($clinicalSummary['allergy_label']) ?><?= !empty($clinicalSummary['allergies']) ? ': ' . htmlspecialchars($clinicalSummary['allergies']) : '' ?>
                    </span>
                  </div>

                  <!-- Restrições / Intolerâncias -->
                  <div style="padding: 8px 12px; border-radius: 6px; background: <?= !empty($latestAnamnesis['restrictions']) ? '#FFFBEB' : '#F8FAFC' ?>; border: 1px solid <?= !empty($latestAnamnesis['restrictions']) ? '#FCD34D' : '#E2E8F0' ?>;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: <?= !empty($latestAnamnesis['restrictions']) ? '#D97706' : '#64748B' ?>; display: block;">
                      <?= !empty($latestAnamnesis['restrictions']) ? '⚠️ RESTRIÇÕES ALIMENTARES' : 'RESTRIÇÕES' ?>
                    </span>
                    <span style="font-size: 0.85rem; color: #1E293B;">
                      <?= htmlspecialchars(LabelMap::recorded($latestAnamnesis['restrictions'] ?? null)) ?>
                    </span>
                  </div>

                  <!-- Objetivos -->
                  <div style="padding: 8px 12px; border-radius: 6px; background: #F8FAFC; border: 1px solid #E2E8F0;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: #0284C7; display: block;">OBJETIVOS & METAS</span>
                    <span style="font-size: 0.85rem; color: #1E293B;">
                      <?= htmlspecialchars($latestAnamnesis['objectives'] ?? 'Não especificados') ?>
                    </span>
                  </div>
                </div>

                <?php if (!empty($latestAnamnesis['symptoms'])): ?>
                  <div style="padding: 6px 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 0.8rem; margin-top: 6px;">
                    <strong>Sinais & Sintomas:</strong> <?= htmlspecialchars($latestAnamnesis['symptoms']) ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <!-- Card 3: Planos Alimentares -->
            <div class="card" id="secao-planos" style="margin-bottom: var(--space-6);">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0;">Planos Alimentares (<?= count($profile['meal_plans'] ?? []) ?>)</h3>
                <button type="button" class="btn btn-secondary" data-show="modal-new-plan-p360" style="font-size: 0.8rem; padding: 3px 8px;">
                  + Novo Plano
                </button>
              </div>
              <?php if (empty($profile['meal_plans'])): ?>
                <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">Nenhum plano alimentar prescrito ainda para este paciente.</p>
              <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                  <?php foreach ($profile['meal_plans'] as $mp): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border: 1px solid var(--neutral-gray-200); border-radius: 6px;">
                      <div>
                        <strong style="color: var(--brand-navy-900); font-size: 0.95rem;"><?= htmlspecialchars($mp['title']) ?></strong>
                        <span style="font-size: 0.75rem; color: #64748b; margin-left: 6px;">v<?= $mp['version'] ?></span>
                        <span style="margin-left: 6px;"><?= LabelMap::mealPlanStatusBadge($mp['status']) ?></span>
                      </div>
                      <a href="/planos/<?= $mp['id'] ?>" class="btn btn-sm btn-outline" style="font-size: 0.8rem; text-decoration: none; padding: 2px 8px;">
                        Abrir Editor ➔
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Modal Novo Plano para Paciente -->
            <div id="modal-new-plan-p360" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
              <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 480px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
                <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-top: 0; margin-bottom: 8px;">Nova Prescrição Alimentar</h3>
                <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 16px;">Paciente: <strong><?= htmlspecialchars($p['name']) ?></strong></p>
                <form method="POST" action="/planos/novo">
                  <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                  <input type="hidden" name="patient_id" value="<?= $p['id'] ?>">
                  <div style="margin-bottom: 16px;">
                    <label for="dc1-title" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Título do Plano *</label>
                    <input id="dc1-title" type="text" name="title" required placeholder="Ex: Plano Alimentar Fase 1" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  </div>
                  <div style="margin-bottom: 20px;">
                    <label for="dc2-notes" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientações Iniciais (Opcional)</label>
                    <textarea id="dc2-notes" name="notes" rows="3" placeholder="Recomendações clínicas, horários, hidratação..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
                  </div>
                  <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-plan-p360">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Abrir Editor</button>
                  </div>
                </form>
              </div>
            </div>

            <!-- Card de Avaliações Antropométricas com Indicadores OMS -->
            <div class="card" id="secao-avaliacoes">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0;">Avaliações Antropométricas (<?= count($assessments) ?>)</h3>
                <a href="/pacientes/<?= $p['id'] ?>/antropometria" style="font-size: 0.85rem; color: var(--brand-copper-600); font-weight: 600; text-decoration: none;">Análise Completa ➔</a>
              </div>

              <?php if (empty($assessments)): ?>
                <div style="text-align: center; padding: var(--space-6); color: var(--neutral-gray-500);">
                  <p>Nenhuma avaliação antropométrica registrada ainda para este paciente.</p>
                  <button type="button" class="btn btn-secondary" data-call="openAnthroModal" style="margin-top: 8px;">Registrar 1ª Avaliação</button>
                </div>
              <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                  <?php foreach (array_reverse(array_slice($assessments, -5)) as $as): ?>
                    <div style="background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 12px 16px;">
                      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                        <div>
                          <strong style="color: var(--brand-navy-900); font-size: 1rem;"><?= date('d/m/Y', strtotime($as['assessed_at'])) ?></strong>
                          <span style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-left: 8px;">Avaliador: <?= htmlspecialchars($as['professional_name'] ?? 'Nutricionista') ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                          <?php if ($as['is_shared']): ?>
                            <span class="badge badge-success" style="font-size: 0.75rem;">✓ Liberado ao Paciente</span>
                          <?php else: ?>
                            <span class="badge badge-secondary" style="font-size: 0.75rem; background: #E2E8F0; color: #475569;">Privado (Clínico)</span>
                          <?php endif; ?>
                          
                          <form action="/pacientes/<?= $p['id'] ?>/antropometria/<?= $as['id'] ?>/compartilhar" method="POST" style="display: inline;">
                            <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="is_shared" value="<?= $as['is_shared'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 2px 8px; min-height: 26px;">
                              <?= $as['is_shared'] ? 'Revogar' : 'Liberar' ?>
                            </button>
                          </form>
                        </div>
                      </div>

                      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; font-size: 0.875rem;">
                        <div>
                          <span style="color: var(--neutral-gray-500); font-size: 0.75rem; display: block;">PESO & ALTURA</span>
                          <strong class="tabular-nums" style="color: var(--brand-navy-900);"><?= htmlspecialchars(LabelMap::num($as['weight_kg'] ?? null, 1)) ?> kg</strong> / <span class="tabular-nums"><?= htmlspecialchars(LabelMap::num($as['height_cm'] ?? null, 1)) ?> cm</span>
                        </div>
                        <div>
                          <span style="color: var(--neutral-gray-500); font-size: 0.75rem; display: block;">IMC (OMS)</span>
                          <strong class="tabular-nums" style="color: var(--brand-navy-900);"><?= htmlspecialchars(LabelMap::num($as['bmi'] ?? null, 2)) ?></strong>
                          <span class="badge badge-info" style="font-size: 0.7rem; padding: 1px 5px;"><?= $as['bmi_classification'] ?></span>
                        </div>
                        <?php if ($as['whr'] !== null): ?>
                          <div>
                            <span style="color: var(--neutral-gray-500); font-size: 0.75rem; display: block;">RCQ (CINTURA/QUADRIL)</span>
                            <strong class="tabular-nums" style="color: var(--brand-navy-900);"><?= $as['whr'] ?></strong>
                            <span style="font-size: 0.7rem; color: var(--neutral-gray-600); display: block;"><?= $as['whr_classification'] ?></span>
                          </div>
                        <?php endif; ?>
                        <?php if ($as['body_fat_pct'] !== null): ?>
                          <div>
                            <span style="color: var(--neutral-gray-500); font-size: 0.75rem; display: block;">GORDURA CORPORAL</span>
                            <strong class="tabular-nums" style="color: var(--brand-copper-600);"><?= htmlspecialchars(LabelMap::num($as['body_fat_pct'] ?? null, 1)) ?>%</strong>
                            <?php if ($as['fat_mass_kg'] !== null): ?>
                              <span style="font-size: 0.75rem; color: var(--neutral-gray-500);">(<?= $as['fat_mass_kg'] ?> kg)</span>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($as['notes'])): ?>
                        <div style="margin-top: 8px; font-size: 0.8rem; color: var(--neutral-gray-600); background: #fff; padding: 6px 10px; border-radius: 4px; border: 1px solid var(--neutral-gray-200);">
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

        <?php require __DIR__ . '/../../../views/patients/longitudinal.php'; ?>

        <!-- Modal Nova Avaliação Antropométrica -->
        <div id="anthroModal" data-dialog="modal" class="modal-overlay" data-call-self="closeAnthroModal">
          <div class="cn-dialog__panel modal-dialog" style="max-width: 650px;">
            <div class="modal-header">
              <h3>Nova Avaliação Antropométrica</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closeAnthroModal" aria-label="Fechar modal">✕</button>
            </div>
            <form method="POST" action="/pacientes/<?= $p['id'] ?>/antropometria">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="patient_id" value="<?= $p['id'] ?>">

              <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4);">
                  <div class="form-group">
                    <label class="form-label" for="ant_weight">Peso corporal (kg) *</label>
                    <input type="number" step="0.1" min="10" max="400" id="ant_weight" name="weight_kg" class="form-control" placeholder="Ex: 72.5" required autofocus>
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_height">Altura (cm) *</label>
                    <input type="number" step="0.1" min="45" max="250" id="ant_height" name="height_cm" class="form-control" placeholder="Ex: 175.0" required>
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="ant_date">Data da avaliação</label>
                  <input type="datetime-local" id="ant_date" name="assessed_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                </div>

                <h4 style="font-size: 0.95rem; margin-top: var(--space-4); margin-bottom: var(--space-2); color: var(--brand-navy-900);">Circunferências Corporais (cm)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-3);">
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

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: var(--space-3);">
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

                <h4 style="font-size: 0.95rem; margin-top: var(--space-4); margin-bottom: var(--space-2); color: var(--brand-navy-900);">Composição Corporal Informada (Bioimpedância / Dobras)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4);">
                  <div class="form-group">
                    <label class="form-label" for="ant_fat">% Gordura Corporal</label>
                    <input type="number" step="0.1" min="1" max="75" id="ant_fat" name="body_fat_pct" class="form-control" placeholder="Ex: 18.5">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_muscle">% Massa Muscular</label>
                    <input type="number" step="0.1" min="5" max="85" id="ant_muscle" name="muscle_mass_pct" class="form-control" placeholder="Ex: 42.0">
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="ant_notes">Anotações Clínicas & Metas</label>
                  <textarea id="ant_notes" name="notes" class="form-control" style="min-height: 60px;" placeholder="Observações do protocolo, balança utilizada ou conduta..."></textarea>
                </div>

                <div style="margin-top: var(--space-3); padding: 12px; background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 6px;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.875rem; color: var(--brand-navy-900); font-weight: 500;">
                    <input type="checkbox" name="is_shared" value="1" style="width: 16px; height: 16px;"><?php /* UX-02 (v14): liberação ao app é opt-in (padrão privado) */ ?>
                    Liberar imediatamente para o paciente visualizar no aplicativo
                  </label>
                </div>
              </div>

              <div class="modal-footer">
                <button data-dialog-close type="button" class="btn btn-secondary" data-call="closeAnthroModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Avaliação Antropométrica</button>
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

        $title = 'Evolução Antropométrica — ' . $patient['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'avaliacao_registrada' ? 'Avaliação registrada com sucesso!' : ($_GET['success'] === 'avaliacao_ja_registrada' ? 'Esta avaliação já estava registrada; o reenvio não criou duplicata.' : 'Permissão de compartilhamento atualizada com sucesso!') ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
          <div style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #B91C1C;">
            <strong>Atenção:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na operação.' ?>
          </div>
        <?php endif; ?>

        <!-- Cabeçalho -->
        <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
              <a href="/pacientes/<?= $patient['id'] ?>" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar ao perfil do paciente</a>
            </div>
            <h1>Evolução Antropométrica: <?= htmlspecialchars($patient['name']) ?></h1>
            <p style="color: var(--neutral-gray-500);">Evolução dos indicadores, composição corporal e comparação entre avaliações.</p>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a class="btn btn-outline" href="/pacientes/<?= (int)$patient['id'] ?>/antropometria/dobras" data-testid="link-skinfolds">Dobras cutâneas</a>
            <button type="button" class="btn btn-primary" data-call="openAnthroModal">
              + Nova Avaliação
            </button>
          </div>
        </div>

        <!-- KPIs Resumo Clínico -->
        <?php if ($latestEval): ?>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: var(--space-6);">
            <div class="card" style="margin-bottom: 0;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 700;">ÚLTIMO PESO REGISTRADO</div>
              <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: 6px 0;">
                <?= htmlspecialchars(LabelMap::num($latestEval['weight_kg'] ?? null, 1)) ?> kg
              </div>
              <?php if ($firstEval && $firstEval['id'] !== $latestEval['id']): ?>
                <?php $deltaTot = round($latestEval['weight_kg'] - $firstEval['weight_kg'], 1); ?>
                <span style="font-size: 0.8rem; color: <?= $deltaTot < 0 ? 'var(--semantic-success)' : 'var(--brand-copper-600)' ?>; font-weight: 600;">
                  <?= $deltaTot > 0 ? "+{$deltaTot}" : $deltaTot ?> kg desde a 1ª consulta
                </span>
              <?php else: ?>
                <span style="font-size: 0.8rem; color: var(--neutral-gray-500);">Primeira avaliação</span>
              <?php endif; ?>
            </div>

            <div class="card" style="margin-bottom: 0;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 700;">IMC ATUAL (OMS)</div>
              <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: 6px 0;">
                <?= htmlspecialchars(LabelMap::num($latestEval['bmi'] ?? null, 2)) ?>
              </div>
              <span class="badge badge-info"><?= $latestEval['bmi_classification'] ?></span>
            </div>

            <?php if ($latestEval['whr'] !== null): ?>
              <div class="card" style="margin-bottom: 0;">
                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 700;">RCQ (CINTURA / QUADRIL)</div>
                <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: 6px 0;">
                  <?= $latestEval['whr'] ?>
                </div>
                <span class="badge badge-<?= $latestEval['whr_risk'] === 'baixo' ? 'success' : ($latestEval['whr_risk'] === 'moderado' ? 'warning' : 'danger') ?>">
                  <?= $latestEval['whr_classification'] ?>
                </span>
              </div>
            <?php endif; ?>

            <?php if ($latestEval['body_fat_pct'] !== null): ?>
              <div class="card" style="margin-bottom: 0;">
                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 700;">% GORDURA CORPORAL</div>
                <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-copper-600); margin: 6px 0;">
                  <?= htmlspecialchars(LabelMap::num($latestEval['body_fat_pct'] ?? null, 1)) ?>%
                </div>
                <span style="font-size: 0.8rem; color: var(--neutral-gray-500);">
                  <?= $latestEval['fat_mass_kg'] !== null ? "{$latestEval['fat_mass_kg']} kg massa gorda" : 'Informado' ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Alternativa Textual Acessível -->
        <div class="card" style="border-left: 4px solid var(--brand-copper-500); background: #FAF7F2; margin-bottom: var(--space-6);">
          <div style="display: flex; gap: 12px; align-items: center;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-600); flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
              <strong style="color: var(--brand-navy-900); font-size: 0.95rem; display: block;">Resumo Clínico da Evolução (Acessibilidade & Auditoria):</strong>
              <span style="font-size: 0.875rem; color: var(--neutral-ink-900);"><?= htmlspecialchars($evolution['accessible_summary']) ?></span>
            </div>
          </div>
        </div>

        <!-- Seletor de Métrica & Linha do Tempo -->
        <div class="card" style="margin-bottom: var(--space-6);">
          <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <h3>Série Histórica Ponto a Ponto</h3>
            <div style="display: flex; gap: 8px; align-items: center;">
              <label for="dc3-campo" style="font-size: 0.85rem; font-weight: 600; color: var(--neutral-gray-600);">Métrica:</label>
              <select id="dc3-campo" aria-label="Medida da evolução antropométrica" data-nav-prefix="/pacientes/<?= (int)$patient['id'] ?>/antropometria?metric=" style="padding: 6px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.875rem;">
                <option value="weight_kg" <?= $metric === 'weight_kg' ? 'selected' : '' ?>>Peso Corporal (kg)</option>
                <option value="bmi" <?= $metric === 'bmi' ? 'selected' : '' ?>>IMC (kg/m²)</option>
                <option value="waist_cm" <?= $metric === 'waist_cm' ? 'selected' : '' ?>>Cintura (cm)</option>
                <option value="hip_cm" <?= $metric === 'hip_cm' ? 'selected' : '' ?>>Quadril (cm)</option>
                <option value="whr" <?= $metric === 'whr' ? 'selected' : '' ?>>RCQ (Cintura/Quadril)</option>
                <option value="whtr" <?= $metric === 'whtr' ? 'selected' : '' ?>>RCE (Cintura/Estatura)</option>
                <option value="body_fat_pct" <?= $metric === 'body_fat_pct' ? 'selected' : '' ?>>Gordura Corporal (%)</option>
                <option value="muscle_mass_pct" <?= $metric === 'muscle_mass_pct' ? 'selected' : '' ?>>Massa Muscular (%)</option>
              </select>
            </div>
          </div>

          <?php if (empty($series)): ?>
            <p style="color: var(--neutral-gray-500); text-align: center; padding: 24px;">Nenhuma medição encontrada para esta métrica.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>Variação Ponto Anterior</th>
                    <th>Variação %</th>
                    <th>Status Paciente</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($series as $pnt): ?>
                    <tr>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= $pnt['formatted_date'] ?></td>
                      <td class="tabular-nums" style="font-weight: 700; font-size: 1.05rem; color: var(--brand-navy-900);"><?= $pnt['value'] ?></td>
                      <td class="tabular-nums">
                        <?php if ($pnt['delta_previous'] === null): ?>
                          <span style="color: var(--neutral-gray-500);">Baseline inicial</span>
                        <?php else: ?>
                          <span style="font-weight: 600; color: <?= $pnt['delta_previous'] < 0 ? 'var(--semantic-success)' : 'var(--brand-copper-600)' ?>;">
                            <?= $pnt['delta_previous'] > 0 ? "+{$pnt['delta_previous']}" : $pnt['delta_previous'] ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="tabular-nums">
                        <?php if ($pnt['delta_previous_pct'] === null): ?>
                          —
                        <?php else: ?>
                          <span style="color: <?= $pnt['delta_previous_pct'] < 0 ? 'var(--semantic-success)' : 'var(--brand-copper-600)' ?>;">
                            <?= $pnt['delta_previous_pct'] > 0 ? "+{$pnt['delta_previous_pct']}%" : "{$pnt['delta_previous_pct']}%" ?>
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
                          <span class="badge badge-secondary" style="background: #E2E8F0; color: #475569;">Privado</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <form action="/pacientes/<?= $patient['id'] ?>/antropometria/<?= $pnt['assessment_id'] ?>/compartilhar" method="POST" style="display: inline;">
                          <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                          <input type="hidden" name="is_shared" value="<?= $isSh ? '0' : '1' ?>">
                          <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 2px 8px; min-height: 28px;">
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
        <div class="card" style="margin-bottom: var(--space-6);">
          <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
              <h3>Comparativo Clínico Entre Avaliações</h3>
              <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.85rem;">Selecione duas avaliações para analisar deltas absolutos, percentuais e evolução corporal.</p>
            </div>
            
            <?php if (count($assessments) >= 2): ?>
              <form method="GET" action="/pacientes/<?= $patient['id'] ?>/antropometria" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="metric" value="<?= htmlspecialchars($metric) ?>">
                <label for="eval_a_select" id="label_eval_a" class="sr-only">Avaliação Baseline</label>
                <select id="eval_a_select" name="eval_a" aria-labelledby="label_eval_a" aria-label="Avaliação baseline para comparação" style="padding: 6px 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.85rem;">
                  <?php foreach ($assessments as $opt): ?>
                    <option value="<?= $opt['id'] ?>" <?= $evalAId === (int)$opt['id'] ? 'selected' : '' ?>>
                      Baseline: <?= date('d/m/Y', strtotime($opt['assessed_at'])) ?> (<?= htmlspecialchars(LabelMap::num($opt['weight_kg'] ?? null, 1)) ?> kg)
                    </option>
                  <?php endforeach; ?>
                </select>

                <span style="font-weight: 600; color: var(--neutral-gray-500);" aria-hidden="true">vs</span>

                <label for="eval_b_select" id="label_eval_b" class="sr-only">Avaliação Comparada</label>
                <select id="eval_b_select" name="eval_b" aria-labelledby="label_eval_b" aria-label="Avaliação comparada para evolução" style="padding: 6px 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.85rem;">
                  <?php foreach ($assessments as $opt): ?>
                    <option value="<?= $opt['id'] ?>" <?= $evalBId === (int)$opt['id'] ? 'selected' : '' ?>>
                      Comparar: <?= date('d/m/Y', strtotime($opt['assessed_at'])) ?> (<?= htmlspecialchars(LabelMap::num($opt['weight_kg'] ?? null, 1)) ?> kg)
                    </option>
                  <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-secondary" style="min-height: 34px;" aria-label="Comparar avaliações selecionadas">Comparar</button>
              </form>
            <?php endif; ?>
          </div>

          <?php if (!$comparison): ?>
            <p style="color: var(--neutral-gray-500); text-align: center; padding: 24px;">
              <?= count($assessments) < 2 ? 'São necessárias pelo menos duas avaliações para realizar o comparativo clínico.' : 'Selecione duas avaliações distintas para comparar.' ?>
            </p>
          <?php else: ?>
            <div style="background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
              <div>
                <strong>Período:</strong> <?= $comparison['baseline']['formatted_date'] ?> ➔ <?= $comparison['followup']['formatted_date'] ?>
                <span style="color: var(--neutral-gray-500); margin-left: 8px;">(<?= $comparison['days_between'] ?> dias decorridos)</span>
              </div>
              <div style="font-size: 0.85rem; color: var(--neutral-gray-600);">
                Avaliadores: <?= htmlspecialchars($comparison['baseline']['evaluator']) ?> e <?= htmlspecialchars($comparison['followup']['evaluator']) ?>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Indicador / Métrica</th>
                    <th class="tabular-nums">Baseline (<?= $comparison['baseline']['formatted_date'] ?>)</th>
                    <th class="tabular-nums">Follow-up (<?= $comparison['followup']['formatted_date'] ?>)</th>
                    <th class="tabular-nums">Delta Absoluto</th>
                    <th class="tabular-nums">Delta Percentual</th>
                    <th>Tendência</th>
                    <th>Status do Dado</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($comparison['metrics'] as $mKey => $mRow): ?>
                    <tr>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($mRow['label']) ?></td>
                      <td class="tabular-nums">
                        <?= $mRow['initial_value'] !== null ? "{$mRow['initial_value']} {$mRow['unit']}" : '<span style="color: var(--neutral-gray-400);">—</span>' ?>
                      </td>
                      <td class="tabular-nums">
                        <?= $mRow['final_value'] !== null ? "{$mRow['final_value']} {$mRow['unit']}" : '<span style="color: var(--neutral-gray-400);">—</span>' ?>
                      </td>
                      <td class="tabular-nums">
                        <?php if ($mRow['delta_abs'] === null): ?>
                          <span style="color: var(--neutral-gray-400);">—</span>
                        <?php else: ?>
                          <strong style="color: <?= $mRow['delta_abs'] < 0 ? 'var(--semantic-success)' : ($mRow['delta_abs'] > 0 ? 'var(--brand-copper-600)' : 'var(--neutral-gray-600)') ?>;">
                            <?= $mRow['delta_abs'] > 0 ? "+{$mRow['delta_abs']}" : $mRow['delta_abs'] ?> <?= $mRow['unit'] ?>
                          </strong>
                        <?php endif; ?>
                      </td>
                      <td class="tabular-nums">
                        <?php if ($mRow['delta_pct'] === null): ?>
                          <span style="color: var(--neutral-gray-400);">—</span>
                        <?php else: ?>
                          <strong style="color: <?= $mRow['delta_pct'] < 0 ? 'var(--semantic-success)' : ($mRow['delta_pct'] > 0 ? 'var(--brand-copper-600)' : 'var(--neutral-gray-600)') ?>;">
                            <?= $mRow['delta_pct'] > 0 ? "+{$mRow['delta_pct']}%" : "{$mRow['delta_pct']}%" ?>
                          </strong>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($mRow['trend'] === 'decrease'): ?>
                          <span style="color: var(--semantic-success); font-weight: 700;">↓ Redução</span>
                        <?php elseif ($mRow['trend'] === 'increase'): ?>
                          <span style="color: var(--brand-copper-600); font-weight: 700;">↑ Aumento</span>
                        <?php elseif ($mRow['trend'] === 'stable'): ?>
                          <span style="color: var(--neutral-gray-500);">↔ Estável</span>
                        <?php else: ?>
                          <span style="color: var(--neutral-gray-400);">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($mRow['status'] === 'evaluated'): ?>
                          <span class="badge badge-success" style="font-size: 0.7rem;">Consistente</span>
                        <?php elseif ($mRow['status'] === 'missing_in_baseline'): ?>
                          <span class="badge badge-warning" style="font-size: 0.7rem;">Não medido no início</span>
                        <?php elseif ($mRow['status'] === 'missing_in_followup'): ?>
                          <span class="badge badge-warning" style="font-size: 0.7rem;">Não medido no follow-up</span>
                        <?php else: ?>
                          <span class="badge badge-secondary" style="font-size: 0.7rem;">Não medido</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Modal Nova Avaliação Antropométrica -->
        <div id="anthroModal" data-dialog="modal" class="modal-overlay" data-call-self="closeAnthroModal">
          <div class="cn-dialog__panel modal-dialog" style="max-width: 650px;">
            <div class="modal-header">
              <h3>Nova Avaliação Antropométrica</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closeAnthroModal" aria-label="Fechar modal">✕</button>
            </div>
            <form method="POST" action="/pacientes/<?= $patient['id'] ?>/antropometria">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="patient_id" value="<?= $patient['id'] ?>">

              <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4);">
                  <div class="form-group">
                    <label class="form-label" for="ant_weight">Peso corporal (kg) *</label>
                    <input type="number" step="0.1" min="10" max="400" id="ant_weight" name="weight_kg" class="form-control" placeholder="Ex: 72.5" required autofocus>
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_height">Altura (cm) *</label>
                    <input type="number" step="0.1" min="45" max="250" id="ant_height" name="height_cm" class="form-control" placeholder="Ex: 175.0" required>
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="ant_date">Data da avaliação</label>
                  <input type="datetime-local" id="ant_date" name="assessed_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                </div>

                <h4 style="font-size: 0.95rem; margin-top: var(--space-4); margin-bottom: var(--space-2); color: var(--brand-navy-900);">Circunferências Corporais (cm)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-3);">
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

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: var(--space-3);">
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

                <h4 style="font-size: 0.95rem; margin-top: var(--space-4); margin-bottom: var(--space-2); color: var(--brand-navy-900);">Composição Corporal Informada (Bioimpedância / Dobras)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4);">
                  <div class="form-group">
                    <label class="form-label" for="ant_fat">% Gordura Corporal</label>
                    <input type="number" step="0.1" min="1" max="75" id="ant_fat" name="body_fat_pct" class="form-control" placeholder="Ex: 18.5">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="ant_muscle">% Massa Muscular</label>
                    <input type="number" step="0.1" min="5" max="85" id="ant_muscle" name="muscle_mass_pct" class="form-control" placeholder="Ex: 42.0">
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="ant_notes">Anotações Clínicas & Metas</label>
                  <textarea id="ant_notes" name="notes" class="form-control" style="min-height: 60px;" placeholder="Observações do protocolo, balança utilizada ou conduta..."></textarea>
                </div>

                <div style="margin-top: var(--space-3); padding: 12px; background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 6px;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.875rem; color: var(--brand-navy-900); font-weight: 500;">
                    <input type="checkbox" name="is_shared" value="1" style="width: 16px; height: 16px;"><?php /* UX-02 (v14): liberação ao app é opt-in (padrão privado) */ ?>
                    Liberar imediatamente para o paciente visualizar no aplicativo
                  </label>
                </div>
              </div>

              <div class="modal-footer">
                <button data-dialog-close type="button" class="btn btn-secondary" data-call="closeAnthroModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Avaliação Antropométrica</button>
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

        if (!(new PatientRepository())->findById($patientId)) { http_response_code(404); echo 'Paciente não encontrado.'; return; }
        \ClyoNutri\Domain\Clinical\FormRecovery::remember($patientId,'antropometria',$_POST);
        $profId = $this->getActiveProfessionalId();
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
            header("Location: /pacientes/{$patientId}/antropometria?error=falha_compartilhar&msg=" . urlencode($e->getMessage()));
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
            echo json_encode(['error' => $e->getMessage()]);
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

        $title = 'Prontuário Eletrônico — ' . $patient['name'];
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
        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'registro_adicionado' ? 'Evolução clínica registrada no prontuário!' : ($_GET['success'] === 'registro_retificado' ? 'Retificação versionada gravada com justificativa formal!' : 'Operação realizada com sucesso!') ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
          <div style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #B91C1C;">
            <strong>Atenção:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro no processamento do registro clínico.' ?>
          </div>
        <?php endif; ?>

        <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
              <a href="/pacientes/<?= $patient['id'] ?>" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar ao perfil do paciente</a>
            </div>
            <h1>Prontuário Eletrônico & Evolução Clínica</h1>
            <p style="color: var(--neutral-gray-500);">Paciente: <strong><?= htmlspecialchars($patient['name']) ?></strong> • Histórico de registros clínicos</p>
          </div>
          <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="/pacientes/<?= (int)$patient['id'] ?>/prontuario/imprimir" target="_blank" rel="noopener" class="btn btn-secondary">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Imprimir prontuário
            </a>
            <a href="/pacientes/<?= $patient['id'] ?>/prontuario/exportar" target="_blank" class="btn btn-secondary">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Exportar histórico de registros
            </a>
            <a href="/pacientes/<?= $patient['id'] ?>/anamnese" class="btn btn-secondary">
              Ver Anamnese ➔
            </a>
            <button type="button" class="btn btn-primary" data-show="modal-new-record">
              + Nova Evolução / Anotação
            </button>
          </div>
        </div>

        <!-- Barra de Integridade e Autenticidade Clínica -->
        <?php if ((int)($integrity['total_records'] ?? 0) > 0 || !$integrity['is_valid']): ?>
        <div style="background: <?= $integrity['is_valid'] ? '#F0FDF4' : '#FEF2F2' ?>; border: 1px solid <?= $integrity['is_valid'] ? '#BBF7D0' : '#FECACA' ?>; border-radius: 8px; padding: 14px 18px; margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 36px; height: 36px; border-radius: 50%; background: <?= $integrity['is_valid'] ? '#22C55E' : '#EF4444' ?>; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold;">
              <?= $integrity['is_valid'] ? '✓' : '!' ?>
            </div>
            <div>
              <strong style="color: <?= $integrity['is_valid'] ? '#166534' : '#991B1B' ?>; font-size: 0.95rem;">
                <?= $integrity['is_valid'] ? 'Histórico Clínico Autêntico e Protegido Contra Adulteração' : 'Atenção: Integridade dos registros precisa de verificação' ?>
              </strong>
              <div style="font-size: 0.8rem; color: #64748B; margin-top: 2px;">
                Conteúdo e encadeamento dos registros verificados. <?= (int)($integrity['total_records'] ?? 0) ?> versões registradas. As versões anteriores são preservadas.
                <?php if (!empty($integrity['legacy_records'])): ?>Título e justificativa de <?= (int)$integrity['legacy_records'] ?> registro(s) antigo(s) não fazem parte desta verificação.<?php endif; ?>
              </div>
              <?php if ($canSeeAudit && !empty($integrity['latest_hash'])): ?>
                <details style="font-size: 0.75rem; color: #64748B; margin-top: 6px;">
                  <summary style="cursor: pointer; color: var(--brand-copper-600); font-weight: 500;">Ver detalhes técnicos de integridade digital</summary>
                  <div style="margin-top: 4px; font-family: monospace; background: #E2E8F0; padding: 4px 8px; border-radius: 4px; word-break: break-all;">
                    Assinatura raiz da cadeia: <?= htmlspecialchars($integrity['latest_hash']) ?>
                  </div>
                </details>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php endif; ?>

        <!-- Resumo Clínico para Prescrição -->
        <div class="card" style="margin-bottom: var(--space-6); border-left: 4px solid #0284C7;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <h3 style="margin: 0; font-size: 1.05rem; color: var(--brand-navy-900);">Resumo Crítico da Anamnese para Prescrição</h3>
            <?php if ($clinicalSummary['has_data'] && !empty($clinicalSummary['source'])): ?>
              <span style="font-size: 0.75rem; color: #64748B;">
                Fonte: <a href="<?= $clinicalSummary['source']['url'] ?>" style="color: var(--brand-copper-600); font-weight: 600;">Anamnese v<?= $clinicalSummary['source']['version'] ?></a> por <?= htmlspecialchars($clinicalSummary['source']['author']) ?> em <?= date('d/m/Y', strtotime($clinicalSummary['source']['recorded_at'])) ?>
              </span>
            <?php endif; ?>
          </div>

          <?php if (!$clinicalSummary['has_data']): ?>
            <p style="color: #64748B; font-size: 0.875rem; margin: 0;">Nenhum dado clínico registrado na anamnese ainda. Não há dados inferidos.</p>
          <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 10px;">
              <div style="padding: 10px; background: <?= !empty($clinicalSummary['allergies']) ? '#FEF2F2' : '#F8FAFC' ?>; border: 1px solid <?= !empty($clinicalSummary['allergies']) ? '#FCA5A5' : '#E2E8F0' ?>; border-radius: 6px;">
                <span style="font-size: 0.75rem; font-weight: 700; color: <?= !empty($clinicalSummary['allergies']) ? '#DC2626' : '#64748B' ?>; display: block;">ALERGIAS ALIMENTARES</span>
                <span style="font-size: 0.875rem; color: #1E293B;"><?= htmlspecialchars($clinicalSummary['allergy_label']) ?><?= !empty($clinicalSummary['allergies']) ? ': ' . htmlspecialchars($clinicalSummary['allergies']) : '' ?></span>
              </div>
              <div style="padding: 10px; background: <?= !empty($clinicalSummary['restrictions']) ? '#FFFBEB' : '#F8FAFC' ?>; border: 1px solid <?= !empty($clinicalSummary['restrictions']) ? '#FCD34D' : '#E2E8F0' ?>; border-radius: 6px;">
                <span style="font-size: 0.75rem; font-weight: 700; color: <?= !empty($clinicalSummary['restrictions']) ? '#D97706' : '#64748B' ?>; display: block;">RESTRIÇÕES & INTOLERÂNCIAS</span>
                <span style="font-size: 0.875rem; color: #1E293B;"><?= htmlspecialchars(LabelMap::recorded($clinicalSummary['restrictions'] ?? null)) ?></span>
              </div>
              <div style="padding: 10px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px;">
                <span style="font-size: 0.75rem; font-weight: 700; color: #0284C7; display: block;">OBJETIVOS PRINCIPAIS</span>
                <span style="font-size: 0.875rem; color: #1E293B;"><?= htmlspecialchars(LabelMap::recorded($clinicalSummary['objectives'] ?? null)) ?></span>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Linha do Tempo Longitudinal -->
        <div class="card">
          <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0;">Evolução Clínica & Histórico Preservado (<?= count($timeline) ?> Registros)</h3>
            <span style="font-size: 0.8rem; color: #64748B;">Autoria e integridade asseguradas para cada lançamento</span>
          </div>

          <?php if (empty($timeline)): ?>
            <div style="text-align: center; padding: 32px; color: #64748B;">
              <p>Nenhuma entrada clínica registrada ainda neste prontuário.</p>
              <button type="button" class="btn btn-primary" data-show="modal-new-record">
                Registrar Primeira Evolução
              </button>
            </div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 16px;">
              <?php foreach (array_reverse($timeline) as $rec): ?>
                <div style="border: 1px solid <?= $rec['is_retracted'] ? '#FCA5A5' : '#E2E8F0' ?>; background: <?= $rec['is_retracted'] ? '#FFF5F5' : '#FFFFFF' ?>; border-radius: 8px; padding: 16px;">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                    <div>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="badge" style="background: #1E293B; color: #fff; font-size: 0.75rem;">v<?= $rec['version'] ?></span>
                        <h4 style="margin: 0; font-size: 1.05rem; color: var(--brand-navy-900);"><?= htmlspecialchars($rec['title']) ?></h4>
                        <?php if ($rec['is_retracted']): ?>
                          <span class="badge badge-warning" style="font-size: 0.75rem;">Superado / Retificado</span>
                        <?php endif; ?>
                        <?php if ($rec['supersedes_id']): ?>
                          <span class="badge badge-info" style="font-size: 0.75rem;">Retificação do Registro #<?= $rec['supersedes_id'] ?></span>
                        <?php endif; ?>
                      </div>
                      <div style="font-size: 0.8rem; color: #64748B; margin-top: 4px;">
                        Por <strong><?= htmlspecialchars($rec['professional_name']) ?></strong> em <?= date('d/m/Y \à\s H:i', strtotime($rec['created_at'])) ?>
                      </div>
                    </div>
                    <?php if (!$rec['is_retracted']): ?>
                      <button type="button" class="btn btn-sm btn-outline" data-call="openRevisionModal" data-args="[<?= $rec['id'] ?>, <?= htmlspecialchars(json_encode((string)$rec['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]" style="font-size: 0.75rem; padding: 3px 8px;">
                        Retificar Formalmente (v<?= $rec['version'] + 1 ?>)
                      </button>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($rec['revision_reason'])): ?>
                    <div style="background: #FEF3C7; border-left: 3px solid #F59E0B; padding: 8px 12px; border-radius: 4px; font-size: 0.8rem; color: #92400E; margin-bottom: 10px;">
                      <strong>Justificativa da retificação:</strong> <?= htmlspecialchars($rec['revision_reason']) ?>
                    </div>
                  <?php endif; ?>

                  <!-- Conteúdo Clínico Estruturado / SOAP -->
                  <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 12px; font-size: 0.875rem; line-height: 1.6; color: #334155; margin-bottom: 10px;">
                    <?php if (isset($rec['content']['evolution_summary'])): ?>
                      <p style="margin: 0 0 8px 0;"><?= nl2br(htmlspecialchars($rec['content']['evolution_summary'])) ?></p>
                    <?php elseif (isset($rec['content']['text'])): ?>
                      <p style="margin: 0 0 8px 0;"><?= nl2br(htmlspecialchars($rec['content']['text'])) ?></p>
                    <?php else: ?>
                      <pre style="margin: 0; font-size: 0.8rem; font-family: inherit; white-space: pre-wrap;"><?= htmlspecialchars(json_encode($rec['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                    <?php endif; ?>

                    <?php if (!empty($rec['content']['weight_kg'])): ?>
                      <div style="margin-top: 8px; font-size: 0.8rem; color: #0284C7;">
                        <strong>Peso aferido:</strong> <?= htmlspecialchars(LabelMap::num($rec['content']['weight_kg'] ?? null, 1)) ?> kg
                        <?php if (!empty($rec['content']['waist_cm'])): ?>
                          | <strong>Cintura:</strong> <?= htmlspecialchars(LabelMap::num($rec['content']['waist_cm'] ?? null, 1)) ?> cm
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <!-- Autenticidade e Trilha Criptográfica Sob Demanda (PX-13) -->
                  <?php if ($canSeeAudit): ?><details style="margin-top: 6px; font-size: 0.75rem; color: #64748B;">
                    <summary style="cursor: pointer; color: var(--brand-teal-700); font-weight: 500; font-size: 0.72rem; outline: none; user-select: none;">
                      Verificar autenticidade digital (cadeia criptográfica)
                    </summary>
                    <div style="display: flex; gap: 12px; font-size: 0.70rem; color: #64748B; font-family: monospace; background: #F1F5F9; padding: 6px 10px; border-radius: 4px; overflow-x: auto; margin-top: 4px;">
                      <div><strong>Assinatura digital (SHA-256):</strong> <?= htmlspecialchars($rec['record_hash'] ?? '') ?></div>
                      <div><strong>Elo anterior:</strong> <?= !empty($rec['previous_hash']) ? htmlspecialchars($rec['previous_hash']) : 'Registro inicial' ?></div>
                    </div>
                  </details><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Modal Nova Evolução / Observação Clínica -->
        <div id="modal-new-record" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 600px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-top: 0; margin-bottom: 8px;">Adicionar Entrada ao Prontuário</h3>
            <p style="color: #64748B; font-size: 0.85rem; margin-bottom: 16px;">O registro será datado, assinado pelo profissional e preservado com garantia de autenticidade digital.</p>
            <form method="POST" action="/pacientes/<?= $patient['id'] ?>/prontuario">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <div style="margin-bottom: 12px;">
                <label for="dc4-title" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.875rem;">Título da Entrada *</label>
                <input id="dc4-title" type="text" name="title" required placeholder="Ex: Evolução Clínica de Retorno, Intercorrência, Orientação..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 12px;">
                <label for="dc5-category" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.875rem;">Categoria</label>
                <select id="dc5-category" name="category" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="evolucao">Evolução Clínica / Atendimento</option>
                  <option value="observacao">Observação Clínica Geral</option>
                  <option value="retorno">Consulta de Retorno</option>
                  <option value="intercorrencia">Intercorrência Clínica</option>
                </select>
              </div>
              <div style="margin-bottom: 16px;">
                <label for="dc6-content_text" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.875rem;">Conteúdo / Conduta Nutricional (Máx. 10.000 caracteres) *</label>
                <textarea id="dc6-content_text" name="content_text" required rows="6" maxlength="10000" placeholder="Descreva os achados, relato do paciente, metas nutricionais e conduta estabelecida..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-family: inherit; font-size: 0.9rem; line-height: 1.5;"></textarea>
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-record">Cancelar</button>
                <button type="submit" class="btn btn-primary">Assinar & Gravar no Prontuário</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Retificação Formal Versionada -->
        <div id="modal-revise-record" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 600px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-top: 0; margin-bottom: 8px;">Retificação Formal de Registro Clínico</h3>
            <p style="color: #64748B; font-size: 0.85rem; margin-bottom: 16px;">
              Conforme as normas do CFN e integridade médica, o registro anterior será marcado como superado, mas permanecerá preservado no histórico. Uma nova versão será gravada com a justificativa obrigatória.
            </p>
            <form id="form-revise-record" method="POST" action="">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <div style="margin-bottom: 12px;">
                <label for="dc7-revision_reason" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.875rem;">Justificativa Formal da Retificação (Mín. 10 caracteres) *</label>
                <input id="dc7-revision_reason" type="text" name="revision_reason" minlength="10" required placeholder="Ex: Correção de digitação no percentual de gordura acordado..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 16px;">
                <label for="dc8-revised_text" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.875rem;">Novo Texto Corrigido *</label>
                <textarea id="dc8-revised_text" name="revised_text" required rows="6" maxlength="10000" placeholder="Insira o texto retificado e atualizado para este atendimento..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-family: inherit; font-size: 0.9rem; line-height: 1.5;"></textarea>
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-revise-record">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background: #D97706; border-color: #D97706;">Publicar Retificação Versionada</button>
              </div>
            </form>
          </div>
        </div>

        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          function openRevisionModal(recId, title) {
            var form = document.getElementById('form-revise-record');
            form.action = '/pacientes/<?= $patient['id'] ?>/prontuario/' + recId + '/retificar';
            document.getElementById('modal-revise-record').style.display = 'flex';
          }
        </script>
        <?php
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

        $profId = $this->getActiveProfessionalId();
        $clinicalService = new ClinicalRecordService();

        try {
            $clinicalService->addClinicalObservation($patientId, $profId, $title, $text, $category);
            header("Location: /pacientes/{$patientId}/prontuario?success=registro_adicionado");
            exit;
        } catch (\Throwable $e) {
            header("Location: /pacientes/{$patientId}/prontuario?error=falha&msg=" . urlencode($e->getMessage()));
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

        $profId = $this->getActiveProfessionalId();
        $clinicalService = new ClinicalRecordService();

        try {
            $clinicalService->reviseRecord($recId, $patientId, $profId, $reason, [
                'type' => 'revised_note',
                'text' => $revisedText,
                'revised_by' => $profId,
            ]);
            header("Location: /pacientes/{$patientId}/prontuario?success=registro_retificado");
            exit;
        } catch (\Throwable $e) {
            header("Location: /pacientes/{$patientId}/prontuario?error=falha&msg=" . urlencode($e->getMessage()));
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
            echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="UTF-8"><title>Prontuário</title><body style="font-family:Inter,system-ui,sans-serif;max-width:560px;margin:64px auto;padding:0 16px">'
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
        require __DIR__ . '/../../../views/patients/medical_record_print.php';
    }

    public function exportMedicalRecord(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $profId = $this->getActiveProfessionalId();
        $clinicalService = new ClinicalRecordService();

        try {
            $export = $clinicalService->exportRecordTimelineSafe($patientId, $profId);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="prontuario_paciente_' . $patientId . '_' . date('Ymd_His') . '.json"');
            echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8', true, 400);
            echo json_encode(['error' => $e->getMessage()]);
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

        $title = 'Anamnese Nutricional — ' . $patient['name'];
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
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div role="status" aria-live="polite" style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ Anamnese salva. As versões anteriores continuam disponíveis no histórico.
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
          <div role="alert" aria-live="assertive" style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #B91C1C;">
            <strong>Atenção:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro ao salvar a anamnese.' ?>
          </div>
        <?php endif; ?>

        <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
              <a href="/pacientes/<?= $patient['id'] ?>" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar ao perfil do paciente</a>
            </div>
            <h1>Anamnese Nutricional Estruturada</h1>
            <p style="color: var(--neutral-gray-500);">Paciente: <strong><?= htmlspecialchars($patient['name']) ?></strong> • Cada conclusão gera uma nova versão; as anteriores ficam no histórico</p>
          </div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <a href="/pacientes/<?= $patient['id'] ?>/pre-consulta" class="btn btn-secondary" data-testid="link-pre-consulta">
              Pré-consulta
            </a>
            <a href="/pacientes/<?= $patient['id'] ?>/prontuario" class="btn btn-secondary">
              Prontuário ➔
            </a>
            <a href="#historico-versoes" class="btn btn-secondary">
              Ver Histórico (<?= count($history) ?> Versões)
            </a>
          </div>
        </div>

        <!-- Se solicitou comparação de versões -->
        <?php if ($compareData): ?>
          <div class="card" style="margin-bottom: var(--space-6); border-top: 4px solid var(--brand-copper-600);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
              <h3 style="margin: 0;">Comparativo de Versões: v<?= $compareData['v1_meta']['version'] ?> vs v<?= $compareData['v2_meta']['version'] ?></h3>
              <a href="/pacientes/<?= $patient['id'] ?>/anamnese" style="color: #64748B; font-size: 0.85rem; text-decoration: none;">Fechar Comparador ✕</a>
            </div>
            <div style="overflow-x: auto;">
              <table class="table" style="width: 100%; font-size: 0.875rem;">
                <thead>
                  <tr style="background: #F8FAFC;">
                    <th style="width: 25%;">Seção Clínica</th>
                    <th style="width: 37.5%;">v<?= $compareData['v1_meta']['version'] ?> (<?= date('d/m/Y', strtotime($compareData['v1_meta']['created_at'])) ?> por <?= htmlspecialchars($compareData['v1_meta']['professional_name']) ?>)</th>
                    <th style="width: 37.5%;">v<?= $compareData['v2_meta']['version'] ?> (<?= date('d/m/Y', strtotime($compareData['v2_meta']['created_at'])) ?> por <?= htmlspecialchars($compareData['v2_meta']['professional_name']) ?>)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($compareData['diff'] as $fieldKey => $diffRow): ?>
                    <tr style="<?= $diffRow['changed'] ? 'background: #FFFBEB;' : '' ?>">
                      <td>
                        <strong><?= htmlspecialchars($diffRow['label']) ?></strong>
                        <?php if ($diffRow['changed']): ?>
                          <span class="badge badge-warning" style="font-size: 0.65rem; margin-left: 4px;">Alterado</span>
                        <?php endif; ?>
                      </td>
                      <td style="color: #334155; line-height: 1.5;"><?= nl2br(htmlspecialchars((string)($diffRow['v1'] ?? '—'))) ?></td>
                      <td style="color: #334155; line-height: 1.5;"><?= nl2br(htmlspecialchars((string)($diffRow['v2'] ?? '—'))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($selectedVersion): ?>
          <?php require __DIR__ . '/../../../views/anamnesis/version.php'; ?>
        <?php elseif ($compareData): ?>
          <div class="card">
            <p>Comparação somente leitura. As versões permanecem preservadas.</p>
            <a class="btn btn-secondary" href="/pacientes/<?= (int)$patient['id'] ?>/anamnese">Voltar à coleta atual</a>
          </div>
        <?php else: ?>
        <!-- Formulário Estruturado da Anamnese -->
        <div class="card" style="margin-bottom: var(--space-6);">
          <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
              <h3 style="margin: 0;">Coleta & Atualização da Anamnese</h3>
              <p style="color: #64748B; font-size: 0.85rem; margin-top: 4px;">
                Preencha as seções clínicas abaixo. Cada gravação cria uma nova versão preservada sem apagar as anteriores.
              </p>
            </div>
            <?php if ($latest): ?>
              <span class="badge badge-info" style="font-size: 0.8rem;">
                <?= $revisionFrom ? 'Nova revisão baseada em' : 'Última Versão:' ?> v<?= $latest['version'] ?> (<?= date('d/m/Y', strtotime($latest['created_at'])) ?>)
              </span>
            <?php endif; ?>
          </div>

          <form method="POST" action="/pacientes/<?= $patient['id'] ?>/anamnese" id="anamnesis-form" aria-describedby="anamnesis-progress">
            <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
            <?php /* NI2-420 (UX-08): índice acessível das seções; não impõe ordem clínica */ ?>
            <nav class="cn-anamnesis-index" aria-label="Seções da anamnese">
              <p id="anamnesis-progress" role="status" aria-live="polite" style="margin: 0 0 6px; font-size: 0.8rem; color: #475569;"></p>
              <ol>
                <li><a href="#sec-objetivos" data-sec="objectives">Objetivos</a></li>
                <li><a href="#sec-alergias" data-sec="allergy_assessment,allergies">Alergias</a></li>
                <li><a href="#sec-restricoes" data-sec="restrictions">Restrições</a></li>
                <li><a href="#sec-preferencias" data-sec="food_preferences">Preferências</a></li>
                <li><a href="#sec-sintomas" data-sec="symptoms">Sintomas</a></li>
                <li><a href="#sec-rotina" data-sec="routine,habits">Rotina e hábitos</a></li>
                <li><a href="#sec-historico" data-sec="clinical_history,water_intake_ml">Histórico e hidratação</a></li>
                <li><a href="#sec-notas" data-sec="notes">Notas e conduta</a></li>
              </ol>
            </nav>
            <?php if ($revisionFrom): ?>
              <input type="hidden" name="revision_from" value="<?= (int)$revisionFrom['version'] ?>">
              <p role="status">Esta gravação criará uma nova versão. A versão de origem será preservada.</p>
            <?php elseif (!$latest): ?>
              <p>Nenhuma anamnese registrada. Preencha a primeira coleta abaixo.</p>
            <?php endif; ?>

            <!-- 1. Objetivos & Metas -->
            <div class="form-group" id="sec-objetivos" style="margin-bottom: 16px;">
              <label for="anamnesis_objectives" id="label_anamnesis_objectives" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                1. Objetivos & Metas do Paciente
              </label>
              <textarea name="objectives" id="anamnesis_objectives" aria-labelledby="label_anamnesis_objectives" rows="2" placeholder="Ex: Perda de 5kg em 3 meses, controle da glicemia em jejum, hipertrofia muscular..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['objectives'] ?? '') ?></textarea>
            </div>

            <!-- 2. Alergias & Restrições (Crítico para Prescrição) -->
            <fieldset style="border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
              <legend style="font-weight: 700; color: var(--brand-navy-900); padding: 0 8px; font-size: 0.95rem;">Alergias Alimentares & Restrições Clínicas</legend>
              <div class="cn-form-grid">
                <div id="sec-alergias" style="background: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 8px; padding: 14px;">
                  <label for="anamnesis_allergies" id="label_anamnesis_allergies" style="display: block; font-weight: 700; margin-bottom: 4px; font-size: 0.9rem; color: #B91C1C;">
                    ⚠️ 2. Alergias Alimentares Diagnosticadas (Crítico)
                  </label>
                  <label class="form-label" for="allergy-assessment">Avaliação de alergias</label>
                  <select class="form-control" id="allergy-assessment" name="allergy_assessment">
                    <?php foreach (['not_assessed' => 'Não avaliado', 'denied' => 'Nega alergias', 'recorded' => 'Alergia registrada'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= (($latest['allergy_assessment'] ?? 'not_assessed') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                  <textarea id="anamnesis_allergies" name="allergies" aria-labelledby="label_anamnesis_allergies" aria-describedby="anamnesis_allergies_help" rows="3" placeholder="Ex: Amendoim, frutos do mar, proteína do leite de vaca (APLV), ovo..." style="width: 100%; padding: 8px; border: 1px solid #FCA5A5; border-radius: 6px;"><?= htmlspecialchars($latest['allergies'] ?? '') ?></textarea>
                  <span id="anamnesis_allergies_help" style="font-size: 0.75rem; color: #991B1B; display: block; margin-top: 4px;">Utilizado na checagem automática de segurança de prescrição.</span>
                </div>
                <div id="sec-restricoes" style="background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 8px; padding: 14px;">
                  <label for="anamnesis_restrictions" id="label_anamnesis_restrictions" style="display: block; font-weight: 700; margin-bottom: 4px; font-size: 0.9rem; color: #92400E;">
                    ⚠️ 3. Restrições Alimentares & Intolerâncias
                  </label>
                  <textarea id="anamnesis_restrictions" name="restrictions" aria-labelledby="label_anamnesis_restrictions" aria-describedby="anamnesis_restrictions_help" rows="3" placeholder="Ex: Intolerância à lactose, doença celíaca / glúten, dieta vegetariana estrita..." style="width: 100%; padding: 8px; border: 1px solid #FCD34D; border-radius: 6px;"><?= htmlspecialchars($latest['restrictions'] ?? '') ?></textarea>
                  <span id="anamnesis_restrictions_help" style="font-size: 0.75rem; color: #92400E; display: block; margin-top: 4px;">Informações essenciais para formulação do plano alimentar.</span>
                </div>
              </div>
            </fieldset>

            <!-- 3. Preferências & Aversões -->
            <div class="form-group" id="sec-preferencias" style="margin-bottom: 16px;">
              <label for="anamnesis_food_preferences" id="label_anamnesis_food_preferences" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                4. Preferências Alimentares & Aversões
              </label>
              <textarea id="anamnesis_food_preferences" name="food_preferences" aria-labelledby="label_anamnesis_food_preferences" rows="2" placeholder="Alimentos preferidos, alimentos rejeitados, hábitos regionais..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['food_preferences'] ?? '') ?></textarea>
            </div>

            <!-- 4. Sinais & Sintomas Clínicos -->
            <div class="form-group" id="sec-sintomas" style="margin-bottom: 16px;">
              <label for="anamnesis_symptoms" id="label_anamnesis_symptoms" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                5. Sinais & Sintomas Gastrointestinais / Gerais
              </label>
              <textarea id="anamnesis_symptoms" name="symptoms" aria-labelledby="label_anamnesis_symptoms" rows="2" placeholder="Pirose, constipação, distensão abdominal, diarreia, cefaleia, indisposição..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['symptoms'] ?? '') ?></textarea>
            </div>

            <!-- 5. Rotina & Hábitos -->
            <fieldset id="sec-rotina" style="border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
              <legend style="font-weight: 700; color: var(--brand-navy-900); padding: 0 8px; font-size: 0.95rem;">Rotina Diária & Hábitos de Vida</legend>
              <div class="cn-form-grid">
                <div class="form-group">
                  <label for="anamnesis_routine" id="label_anamnesis_routine" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                    6. Rotina Diária & Horários
                  </label>
                  <textarea id="anamnesis_routine" name="routine" aria-labelledby="label_anamnesis_routine" rows="3" placeholder="Horário de acordar, dormir, trabalho, pausas de refeições, atividade física..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['routine'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                  <label for="anamnesis_habits" id="label_anamnesis_habits" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                    7. Hábitos de Vida & Consumo
                  </label>
                  <textarea id="anamnesis_habits" name="habits" aria-labelledby="label_anamnesis_habits" rows="3" placeholder="Consumo de álcool, tabagismo, cafeína, mastigação rápida, beliscos..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['habits'] ?? '') ?></textarea>
                </div>
              </div>
            </fieldset>

            <!-- 6. Histórico Clínico & Hidratação -->
            <fieldset id="sec-historico" style="border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
              <legend style="font-weight: 700; color: var(--brand-navy-900); padding: 0 8px; font-size: 0.95rem;">Histórico Clínico & Meta Hídrica Prescrita</legend>
              <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                <div class="form-group">
                  <label for="anamnesis_clinical_history" id="label_anamnesis_clinical_history" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                    8. Histórico Clínico, Patologias & Medicamentos
                  </label>
                  <textarea id="anamnesis_clinical_history" name="clinical_history" aria-labelledby="label_anamnesis_clinical_history" rows="2" placeholder="Hipertensão, DM2, dislipidemia, cirurgias prévias, histórico familiar, medicamentos atuais..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['clinical_history'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                  <label for="anamnesis_water_intake_ml" id="label_anamnesis_water_intake_ml" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                    9. Meta Hídrica (ml/dia)
                  </label>
                  <input type="number" step="50" min="0" max="10000" id="anamnesis_water_intake_ml" name="water_intake_ml" value="<?= htmlspecialchars((string)($latest['water_intake_ml'] ?? '')) ?>" aria-labelledby="label_anamnesis_water_intake_ml" aria-describedby="anamnesis_water_help" placeholder="Ex: 2500" style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <span id="anamnesis_water_help" style="font-size: 0.75rem; color: var(--neutral-gray-500); display: block; margin-top: 4px;">Meta prescrita em ml exibida na Home do paciente.</span>
                </div>
              </div>
            </fieldset>

            <!-- 7. Anotações Adicionais -->
            <div class="form-group" id="sec-notas" style="margin-bottom: 24px;">
              <label for="anamnesis_notes" id="label_anamnesis_notes" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 0.9rem; color: var(--brand-navy-900);">
                10. Notas & Conduta Inicial do Nutricionista
              </label>
              <textarea id="anamnesis_notes" name="notes" aria-labelledby="label_anamnesis_notes" rows="2" placeholder="Observações sigilosas da consulta ou direcionamentos para a elaboração do cardápio..." style="width: 100%; padding: 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars($latest['notes'] ?? '') ?></textarea>
            </div>

            <?php /* NI2-420 (UX-08) / NI2-419 (UX-07): barra de ações sempre alcançável, com estado de salvamento */ ?>
            <div class="cn-sticky-actions" role="group" aria-label="Salvar anamnese">
              <p class="cn-sticky-actions__status" id="anamnesis-save-status" aria-live="polite">
                <?php if ($latest): ?>Última gravação: v<?= (int)$latest['version'] ?> <?= !empty($latest['is_draft']) ? '(rascunho)' : '(concluída)' ?> em <?= date('d/m/Y H:i', strtotime($latest['created_at'])) ?>.<?php else: ?>Nada salvo ainda para este paciente.<?php endif; ?>
              </p>
              <span id="anamnesis-actions-help" class="sr-only">Salvar rascunho guarda a coleta parcial para continuar depois. Concluir anamnese registra uma nova versão; as versões anteriores continuam no histórico.</span>
              <button type="submit" name="save_mode" value="draft" class="btn btn-outline" style="font-weight: 600;" aria-describedby="anamnesis-actions-help">
                Salvar rascunho
              </button>
              <button type="submit" name="save_mode" value="final" class="btn btn-primary" style="font-size: 1rem; padding: 10px 24px;" aria-describedby="anamnesis-actions-help">
                Concluir anamnese
              </button>
              <p style="flex-basis: 100%; margin: 0; text-align: right; font-size: 0.75rem; color: #64748B;">Concluir registra uma nova versão. As versões anteriores continuam no histórico.</p>
            </div>
          </form>
          <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
          (() => {
            const form = document.getElementById('anamnesis-form'); if (!form) return;
            const progress = document.getElementById('anamnesis-progress'), status = document.getElementById('anamnesis-save-status'), initial = status.textContent.trim();
            const assessLabel = {not_assessed: 'não avaliado', denied: 'nega alergias', recorded: 'alergia registrada'};
            const update = () => {
              let filled = 0; const links = [...form.querySelectorAll('.cn-anamnesis-index a[data-sec]')];
              links.forEach(a => {
                const names = a.dataset.sec.split(','), isAllergy = names.includes('allergy_assessment');
                const has = names.some(n => { const el = form.elements[n]; return el && el.value.trim() !== '' && !(n === 'allergy_assessment' && el.value === 'not_assessed'); });
                a.dataset.filled = String(has); if (has) filled++;
                const base = a.textContent.replace(/ \(.*\)$/, '');
                a.textContent = isAllergy ? base + ' (' + (assessLabel[form.elements.allergy_assessment?.value] || 'não avaliado') + ')' : base;
                a.setAttribute('aria-label', a.textContent + (isAllergy ? '' : (has ? ', com conteúdo' : ', sem conteúdo')));
              });
              progress.textContent = filled + ' de ' + links.length + ' seções com conteúdo. Seção vazia significa "não registrado", não negação.';
              const dirty = window.ClyoForms ? window.ClyoForms.isDirty(form) : false;
              status.textContent = dirty ? 'Alterações não salvas. ' + initial : initial;
            };
            form.addEventListener('input', update); form.addEventListener('change', update); update();
            window.addEventListener('load', update);
          })();
          </script>
        </div>

        <?php endif; ?>

        <!-- Seção: Histórico de Versões da Anamnese -->
        <div id="historico-versoes" class="card">
          <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0;">Histórico Versionado da Anamnese (<?= count($history) ?> Versões)</h3>
            <span style="font-size: 0.8rem; color: #64748B;">As versões anteriores são preservadas</span>
          </div>

          <?php if (empty($history)): ?>
            <p style="color: #64748B; font-size: 0.875rem;">Nenhuma versão histórica anterior registrada.</p>
          <?php else: ?>
            <div style="overflow-x: auto; margin-bottom: 16px;">
              <table class="table" style="width: 100%; font-size: 0.875rem;">
                <thead>
                  <tr style="background: #F8FAFC;">
                    <th>Versão</th>
                    <th>Data / Hora</th>
                    <th>Profissional</th>
                    <th>Status</th>
                    <th>Alergias Relatadas</th>
                    <th>Restrições Relatadas</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $h): ?>
                    <tr>
                      <td><strong>v<?= $h['version'] ?></strong></td>
                      <td><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                      <td><?= htmlspecialchars($h['professional_name']) ?></td>
                      <td>
                        <span class="badge badge-<?= $h['is_draft'] ? 'warning' : 'success' ?>">
                          <?= $h['is_draft'] ? 'Rascunho' : 'Finalizada' ?>
                        </span>
                      </td>
                      <td>
                        <?= !empty($h['allergies']) ? '<span style="color: #DC2626; font-weight: 600;">' . htmlspecialchars(mb_substr($h['allergies'], 0, 30)) . '...</span>' : '<span style="color: #94A3B8;">—</span>' ?>
                      </td>
                      <td>
                        <?= !empty($h['restrictions']) ? '<span style="color: #D97706; font-weight: 600;">' . htmlspecialchars(mb_substr($h['restrictions'], 0, 30)) . '...</span>' : '<span style="color: #94A3B8;">—</span>' ?>
                      </td>
                      <td>
                        <a href="/pacientes/<?= $patient['id'] ?>/anamnese?version=<?= $h['version'] ?>" class="btn btn-sm btn-outline" style="font-size: 0.75rem; padding: 2px 6px;">Visualizar</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Formulário para Comparar 2 Versões -->
            <?php if (count($history) >= 2): ?>
              <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px 16px;">
                <h4 style="margin: 0 0 8px 0; font-size: 0.875rem; color: var(--brand-navy-900);">Comparar Versões Lado a Lado (Diff Clínico)</h4>
                <form method="GET" action="/pacientes/<?= $patient['id'] ?>/anamnese" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                  <div>
                    <label for="compare_version_1" id="label_compare_version_1" style="font-size: 0.8rem; color: #64748B; display: block; margin-bottom: 2px;">Versão A (base):</label>
                    <select id="compare_version_1" name="v1" aria-labelledby="label_compare_version_1" aria-label="Versão A para comparação" style="padding: 6px 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.85rem;">
                      <?php foreach ($history as $h): ?>
                        <option value="<?= $h['version'] ?>">v<?= $h['version'] ?> (<?= date('d/m/Y', strtotime($h['created_at'])) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label for="compare_version_2" id="label_compare_version_2" style="font-size: 0.8rem; color: #64748B; display: block; margin-bottom: 2px;">Versão B (comparada):</label>
                    <select id="compare_version_2" name="v2" aria-labelledby="label_compare_version_2" aria-label="Versão B para comparação" style="padding: 6px 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.85rem;">
                      <?php foreach (array_reverse($history) as $h): ?>
                        <option value="<?= $h['version'] ?>" <?= $h['version'] === $history[0]['version'] ? 'selected' : '' ?>>v<?= $h['version'] ?> (<?= date('d/m/Y', strtotime($h['created_at'])) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="submit" class="btn btn-secondary" style="margin-top: 16px; font-size: 0.85rem;" aria-label="Comparar versões selecionadas">
                    Comparar Versões Selecionadas ➔
                  </button>
                </form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private function anamnesisProfessional(): ?int
    {
        $db = Connection::getInstance();
        $stmt = $db->prepare("SELECT p.id FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = u.id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE u.id = :uid AND cm.clinic_id = :cid
              AND u.status = 'active' AND c.status = 'active' AND cm.status = 'active'
              AND u.role = 'nutritionist' AND cm.role IN ('owner', 'nutritionist') LIMIT 1");
        $stmt->execute(['uid' => TenantContext::getUserId(), 'cid' => TenantContext::getClinicId()]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $this->anamnesisError(403, 'Acesso clínico não autorizado.');
            return null;
        }
        return (int)$id;
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
        <div class="card" role="alert">
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
            $db = Connection::getInstance();
            $patientExists = $db->prepare("SELECT 1 FROM patients WHERE id = ?");
            $patientExists->execute([$patientId]);
            if ($patientExists->fetchColumn()) {
                $this->anamnesisError(403, 'Acesso ao documento não autorizado.');
            } else {
                $this->anamnesisError(404, 'Paciente não encontrado.');
            }
            return;
        }
        \ClyoNutri\Domain\Clinical\FormRecovery::remember($patientId,'anamnese',$_POST);
        $isDraft = ($_POST['save_mode'] ?? '') === 'draft';
        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();

        try {
            $anamnesisService->saveAnamnesis($patientId, $profId, $_POST, $isDraft);
            \ClyoNutri\Domain\Clinical\FormRecovery::clear($patientId,'anamnese');
            header("Location: /pacientes/{$patientId}/anamnese?success=anamnese_salva");
            exit;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            $this->anamnesisError(403, 'Acesso ao documento não autorizado.');
            return;
        } catch (\PDOException $e) {
            $this->anamnesisError(503, 'Não foi possível salvar a nova revisão. O preenchimento foi mantido nesta sessão por uma hora. Volte à coleta atual e tente novamente.');
            return;
        } catch (\InvalidArgumentException $e) {
            header("Location: /pacientes/{$patientId}/anamnese?error=dados_invalidos&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            header("Location: /pacientes/{$patientId}/anamnese?error=falha&msg=" . urlencode('Não foi possível salvar. O preenchimento foi mantido nesta sessão por uma hora. Tente novamente.'));
            exit;
        }
    }

    public function prescriptionSummary(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $anamnesisService = new \ClyoNutri\Domain\Anamnesis\AnamnesisService();

        try {
            $summary = $anamnesisService->getClinicalSummaryForPrescription($patientId);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8', true, 400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
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
        $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
        $duration = (int)($_POST['duration_minutes'] ?? 60);
        $type = trim((string)($_POST['type'] ?? 'first_visit'));
        $notes = !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null;
        $allowConflict = !empty($_POST['allow_conflict']);

        if ($patientId <= 0 || empty($scheduledAt)) {
            header('Location: /agenda?error=campos_obrigatorios');
            exit;
        }

        $service = new AppointmentService();
        $profId = $this->getActiveProfessionalId();

        try {
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
            header("Location: /agenda?date={$targetDate}&success=agendado");
            exit;
        } catch (AppointmentConflictException $e) {
            // UX-05 (v14): conflito mantém o formulário aberto, preenchido e com o horário destacado.
            $targetDate = date('Y-m-d', strtotime($scheduledAt) ?: time());
            $this->rememberAgendaForm('new', $e->getMessage(), true);
            header("Location: /agenda?date={$targetDate}&novo=1&error=conflito#card-novo-agendamento");
            exit;
        } catch (\Throwable $e) {
            $this->rememberAgendaForm('new', $e->getMessage(), false);
            header("Location: /agenda?novo=1&error=falha#card-novo-agendamento");
            exit;
        }
    }

    /** UX-04/05 (v14): guarda o preenchimento do agendamento na sessão (uma leitura, até 1 h). */
    private function rememberAgendaForm(string $scope, string $message, bool $conflict): void
    {
        $values = [];
        foreach (['patient_id', 'scheduled_at', 'duration_minutes', 'type', 'notes', 'reschedule_reason'] as $k) {
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

        $title = 'Reagendar consulta — ' . ($app['patient_name'] ?? 'Paciente');
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();
        ob_start();
        ?>
        <div style="font-size: 0.85rem; margin-bottom: 4px;"><a href="<?= htmlspecialchars($backUrl) ?>" style="color: var(--brand-copper-600);">← Voltar</a></div>
        <h1>Reagendar consulta</h1>
        <p style="color: var(--neutral-gray-500);"><?= htmlspecialchars($app['patient_name']) ?> • <?= htmlspecialchars(LabelMap::appointmentType($app['type'] ?? null)) ?> • horário atual: <strong data-testid="current-slot"><?= date('d/m/Y H:i', strtotime((string)$app['scheduled_at'])) ?></strong></p>
        <?php if (!$reschedulable): ?>
          <div role="alert" class="card" style="border-left: 4px solid #DC2626;">Consultas com status <strong><?= htmlspecialchars(LabelMap::appointmentStatus($app['status'])) ?></strong> não podem ser reagendadas. Para um novo atendimento, use <a href="/agenda?novo=1&amp;paciente=<?= (int)$app['patient_id'] ?>">Nova consulta</a>.</div>
        <?php else: ?>
        <div class="card" id="card-reagendar" style="border-top: 4px solid var(--brand-copper-500); max-width: 720px;">
          <?php if ($recovered): ?>
            <div role="alert" data-testid="agenda-form-error" style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; color: #B91C1C;">
              <strong><?= !empty($recovered['conflict']) ? 'Horário indisponível.' : 'Não foi possível reagendar.' ?></strong> <?= htmlspecialchars((string)$recovered['error']) ?> Seus dados foram mantidos; escolha outro horário<?= !empty($recovered['conflict']) ? ' ou marque o encaixe' : '' ?>.
            </div>
          <?php endif; ?>
          <form action="/agenda/<?= (int)$app['id'] ?>/editar" method="POST" <?= $recovered ? 'data-start-dirty' : '' ?>>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="origin" value="reagendar">
            <input type="hidden" name="de" value="<?= htmlspecialchars($from === 'paciente' ? 'paciente' : 'agenda') ?>">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
              <div>
                <label for="rs-scheduled_at" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Novo dia e horário *</label>
                <input id="rs-scheduled_at" type="datetime-local" name="scheduled_at" required value="<?= htmlspecialchars($scheduledValue) ?>"
                  <?= !empty($recovered['conflict']) ? 'aria-invalid="true" aria-describedby="rs-scheduled_at-erro" autofocus data-conflict-highlight style="width: 100%; padding: 8px 12px; border: 2px solid #DC2626; border-radius: 6px; background: #FEF2F2;"' : 'style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"' ?>>
                <?php if (!empty($recovered['conflict'])): ?><p id="rs-scheduled_at-erro" class="field-error" style="color: #B91C1C; font-size: 0.8rem; margin: 4px 0 0;">Conflita com outra consulta. Escolha outro horário.</p><?php endif; ?>
              </div>
              <div>
                <label for="rs-duration" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Duração</label>
                <select id="rs-duration" name="duration_minutes" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <?php $choices = array_unique([30, 45, 60, 90, 120, $durationValue]); sort($choices); foreach ($choices as $m): ?>
                    <option value="<?= (int)$m ?>" <?= (int)$m === $durationValue ? 'selected' : '' ?>><?= (int)$m ?> minutos</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <label for="rs-reason" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Motivo do reagendamento (opcional, fica na trilha da consulta)</label>
              <input id="rs-reason" type="text" name="reschedule_reason" maxlength="300" value="<?= htmlspecialchars($reasonValue) ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
            </div>
            <p style="font-size: 0.8rem; color: var(--neutral-gray-500);">A consulta continua a mesma (mesmo paciente, tipo, histórico e vínculos). Se for teleconsulta, o convite anterior deixa de valer e um novo convite deve ser enviado ao paciente.</p>
            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--neutral-gray-200); padding-top: 12px;">
              <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #64748B;"><input type="checkbox" name="allow_conflict" value="1"> Permitir sobreposição de horário (encaixe)</label>
              <div style="display: flex; gap: 8px;">
                <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" data-testid="reschedule-submit">Confirmar reagendamento</button>
              </div>
            </div>
          </form>
          <p style="font-size: 0.8rem; margin-top: 10px;"><a href="/ajuda/artigo/como-reagendar-uma-consulta">Como reagendar uma consulta</a></p>
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
            header("Location: /agenda?error=falha&msg=" . urlencode($e->getMessage()));
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
            header("Location: /agenda?error=falha&msg=" . urlencode($e->getMessage()));
            exit;
        }
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

        // UX-03 (v14): rascunho por consulta + usuário, restaurado ao retomar.
        $draft = ['evolution_summary' => null, 'weight_kg' => null, 'waist_cm' => null, 'version' => 0, 'updated_at_utc' => null];
        if ($inProgress) {
            try {
                $draft = (new \ClyoNutri\Domain\Appointment\ConsultationDraftService())->get($id, (int)TenantContext::getUserId(), (int)TenantContext::getClinicId());
            } catch (\Throwable $e) {
                error_log('[consulta] rascunho indisponível: ' . get_class($e));
            }
        }
        $draftSavedLocal = !empty($draft['updated_at_utc'])
            ? (new \DateTimeImmutable((string)$draft['updated_at_utc'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y \à\s H:i')
            : null;

        $repo = new PatientRepository();
        $profile = $repo->getProfile360((int)$app['patient_id']);
        $p = $profile['patient'] ?? [];

        $title = "Consulta #{$id} — " . ($app['patient_name'] ?? 'Paciente');
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
              <a href="/agenda" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar para a Agenda</a>
            </div>
            <h1>Atendimento Clínico — <?= htmlspecialchars($app['patient_name']) ?></h1>
            <p style="color: var(--neutral-gray-500);">
              Tipo: <?= htmlspecialchars(LabelMap::appointmentType($app['type'] ?? null)) ?> • Duração prevista: <?= (int)$app['duration_minutes'] ?> min
            </p>
            <dl class="cn-consult-times" data-testid="consult-times" style="display: flex; gap: 18px; flex-wrap: wrap; margin: 6px 0 0; font-size: 0.875rem;">
              <div><dt style="color: var(--neutral-gray-500); font-size: 0.75rem;">Agendada para</dt><dd style="margin: 0;" data-time="scheduled"><strong><?= date('d/m/Y H:i', strtotime($app['scheduled_at'])) ?></strong></dd></div>
              <div><dt style="color: var(--neutral-gray-500); font-size: 0.75rem;">Iniciada em</dt><dd style="margin: 0;" data-time="started"><strong><?= $timeline['started_at'] ? date('d/m/Y H:i', strtotime($timeline['started_at'])) : 'Não iniciada' ?></strong></dd></div>
              <div><dt style="color: var(--neutral-gray-500); font-size: 0.75rem;">Concluída em</dt><dd style="margin: 0;" data-time="completed"><strong><?= $timeline['completed_at'] ? date('d/m/Y H:i', strtotime($timeline['completed_at'])) : 'Não concluída' ?></strong></dd></div>
            </dl>
          </div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <span class="badge badge-warning" style="font-size: 0.875rem; padding: 6px 12px;">
              <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: #946200; margin-right: 4px;"></span>
              <?= htmlspecialchars(LabelMap::appointmentStatus($app['status'])) ?>
            </span>
            <?php if (\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType($app['type'] ?? null)): /* NI2-221 F19: só consulta online */ ?>
            <a href="/teleconsulta/sessao-agenda/<?= (int)$app['id'] ?>" class="btn btn-primary" style="font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px;">
              <span>Consultório Online</span>
            </a>
            <?php endif; ?>
            <a href="/pacientes/<?= $app['patient_id'] ?>" target="_blank" class="btn btn-secondary" style="font-size: 0.85rem;">
              Ver perfil do paciente ↗
            </a>
          </div>
        </div>

        <div class="consultation-room-layout" style="display: grid; grid-template-columns: 1fr 1.6fr; gap: var(--space-6);">
          <!-- Coluna 1: Contexto Clínico do Paciente -->
          <div style="display: flex; flex-direction: column; gap: var(--space-6);">
            <!-- Card de Dados e Histórico -->
            <div class="card">
              <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Contexto do Paciente</h3>
              <p style="margin-bottom: var(--space-2);"><strong>Telefone:</strong> <?= htmlspecialchars($p['phone'] ?? '—') ?></p>
              <p style="margin-bottom: var(--space-2);"><strong>E-mail:</strong> <?= htmlspecialchars($p['email'] ?? '—') ?></p>
              <p style="margin-bottom: var(--space-2);"><strong>Nascimento:</strong> <?= !empty($p['birth_date']) ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></p>
              <p style="margin-bottom: var(--space-2);"><strong>Gênero:</strong> <?= htmlspecialchars(LabelMap::patientGender($p['gender'] ?? null)) ?></p>

              <?php if (!empty($app['notes'])): ?>
                <div style="margin-top: var(--space-4); padding: var(--space-3); background: #FAF7F2; border-left: 3px solid var(--brand-copper-500); border-radius: 4px;">
                  <strong style="font-size: 0.8125rem; color: var(--brand-navy-900);">Observações do Agendamento:</strong>
                  <p style="font-size: 0.875rem; color: var(--neutral-gray-700); margin-top: 4px;"><?= nl2br(htmlspecialchars($app['notes'])) ?></p>
                </div>
              <?php endif; ?>
            </div>

            <!-- Última Antropometria -->
            <div class="card">
              <div class="card__header">
                <h3>Últimas Medidas</h3>
                <a href="/pacientes/<?= $app['patient_id'] ?>" class="btn btn-secondary" style="font-size: 0.75rem; padding: 2px 8px;">Histórico</a>
              </div>
              <?php if (empty($profile['assessments'])): ?>
                <p style="color: var(--neutral-gray-500); font-size: 0.875rem;">Nenhuma avaliação antropométrica registrada ainda.</p>
              <?php else: ?>
                <?php $latest = $profile['assessments'][0]; ?>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; text-align: center; margin-bottom: 8px;">
                  <div style="background: #F8FAFC; padding: 8px; border-radius: 6px;">
                    <span style="font-size: 0.75rem; color: #64748B;">Peso</span>
                    <strong style="display: block; font-size: 1.1rem; color: var(--brand-navy-900);"><?= htmlspecialchars(LabelMap::num($latest['weight_kg'] ?? null, 1, 'kg')) ?></strong>
                  </div>
                  <div style="background: #F8FAFC; padding: 8px; border-radius: 6px;">
                    <span style="font-size: 0.75rem; color: #64748B;">Altura</span>
                    <strong style="display: block; font-size: 1.1rem; color: var(--brand-navy-900);"><?= htmlspecialchars(LabelMap::num($latest['height_cm'] ?? null, 1, 'cm')) ?></strong>
                  </div>
                  <div style="background: #F8FAFC; padding: 8px; border-radius: 6px;">
                    <span style="font-size: 0.75rem; color: #64748B;">IMC</span>
                    <strong style="display: block; font-size: 1.1rem; color: var(--brand-navy-900);"><?= htmlspecialchars(LabelMap::num($latest['bmi'] ?? null, 2)) ?></strong>
                  </div>
                </div>
                <small style="color: var(--neutral-gray-500);">Registrado em: <?= date('d/m/Y', strtotime($latest['assessed_at'])) ?></small>
              <?php endif; ?>
            </div>

            <!-- Último Plano Alimentar -->
            <div class="card">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="margin: 0;">Prescrição Alimentar</h3>
                <a href="/planos" target="_blank" class="btn btn-secondary" style="font-size: 0.75rem; padding: 2px 8px;">Todos os Planos</a>
              </div>
              <?php if (empty($profile['meal_plans'])): ?>
                <p style="color: var(--neutral-gray-500); font-size: 0.875rem; margin-bottom: 12px;">Nenhum plano prescrito ainda. Inicie a prescrição desta consulta.</p>
                <a href="/pacientes/<?= $app['patient_id'] ?>" target="_blank" class="btn btn-sm btn-primary" style="font-size: 0.8rem; text-decoration: none; display: inline-block;">+ Prescrever Plano no Perfil ↗</a>
              <?php else: ?>
                <?php $plan = $profile['meal_plans'][0]; ?>
                <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                  <strong style="color: var(--brand-navy-900);"><?= htmlspecialchars($plan['title']) ?> (v<?= $plan['version'] ?>)</strong>
                  <?= LabelMap::mealPlanStatusBadge($plan['status'] ?? '') ?>
                </div>
                <a href="/planos/<?= $plan['id'] ?>" target="_blank" class="btn btn-sm btn-outline" style="font-size: 0.8rem; text-decoration: none;">Abrir Editor da Prescrição ↗</a>
              <?php endif; ?>
            </div>
          </div>

          <!-- Coluna 2: Formulário de Execução e Conclusão da Consulta -->
          <div>
            <div class="card">
              <h3 style="margin-top: 0; margin-bottom: var(--space-4); color: var(--brand-navy-900);">
                Evolução Clínica & Conduta do Atendimento
              </h3>
              <p style="color: var(--neutral-gray-500); font-size: 0.875rem; margin-bottom: var(--space-4);">
                Ao concluir a consulta, a evolução será assinada e registrada de forma autêntica no prontuário eletrônico do paciente, garantindo integridade e conformidade com as diretrizes do CFN.
              </p>

              <?php if ($canStart): ?>
              <section class="cn-start-consult" data-testid="consult-start-panel" style="border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 16px; background: <?= $isEarly ? '#FFF7E6' : '#F8FAFC' ?>;">
                <h4 style="margin: 0 0 6px;">Esta consulta ainda não foi iniciada</h4>
                <p style="margin: 0 0 10px; font-size: 0.9rem;">Agendada para <strong><?= date('d/m/Y \à\s H:i', strtotime($app['scheduled_at'])) ?></strong>. Ao iniciar, o horário real de início é registrado na trilha de auditoria.</p>
                <form method="POST" action="/agenda/<?= (int)$app['id'] ?>/status" data-no-dirty style="margin: 0;">
                  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                  <input type="hidden" name="status" value="in_progress">
                  <?php if ($isEarly): ?>
                    <p role="alert" data-testid="early-start-warning" style="margin: 0 0 8px; color: #8A5300; font-weight: 600;">Início antecipado: faltam <?= htmlspecialchars(AppointmentService::humanUntil((string)$app['scheduled_at'])) ?> para o horário marcado.</p>
                    <label style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;"><input type="checkbox" name="confirm_early" value="1" required> Confirmo que quero iniciar antes do horário agendado</label>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary" data-testid="consult-start">Iniciar atendimento agora</button>
                  <a href="/agenda" class="btn btn-secondary">Voltar à agenda</a>
                </form>
              </section>
              <?php elseif (!$inProgress): ?>
              <p data-testid="consult-closed" style="padding: 12px; background: #F8FAFC; border-radius: 8px;">Consulta com status <strong><?= htmlspecialchars(LabelMap::appointmentStatus($app['status'])) ?></strong>; não há anotações em andamento.</p>
              <?php else: ?>
              <form action="/consulta/<?= $app['id'] ?>/concluir" method="POST" data-no-dirty data-consult-draft
                    data-draft-url="/consulta/<?= (int)$app['id'] ?>/rascunho" data-draft-version="<?= (int)$draft['version'] ?>" data-csrf="<?= htmlspecialchars($csrfToken) ?>">
                <p id="consulta-draft-status" role="status" aria-live="polite" data-testid="draft-status" style="font-size: 0.8125rem; color: var(--neutral-gray-600, #475569); margin: 0 0 8px;"><?= $draftSavedLocal ? 'Rascunho recuperado (salvo em ' . htmlspecialchars($draftSavedLocal) . '). Continue de onde parou.' : 'As anotações são salvas automaticamente como rascunho enquanto você digita.' ?></p>
                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

                <div class="form-group" style="margin-bottom: var(--space-4);">
                  <label for="evolution_summary" style="display: block; font-weight: 600; margin-bottom: 6px;">
                    Anotações Clínicas / Resumo da Evolução <span style="color: #DC2626;">*</span>
                  </label>
                  <textarea id="evolution_summary" name="evolution_summary" rows="8" required style="width: 100%; padding: 12px; border: 1px solid var(--neutral-gray-300); border-radius: 8px; font-family: inherit; font-size: 0.9375rem; line-height: 1.5;" placeholder="Descreva os tópicos abordados (anamnese alimentar, queixas gastrointestinais, rotina, metas acordadas e ajustes na prescrição)..."><?= htmlspecialchars((string)($draft['evolution_summary'] ?? '')) ?></textarea>
                </div>

                <div style="background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 16px; margin-bottom: var(--space-6);">
                  <h4 style="font-size: 0.875rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 12px;">
                    Atualização Antropométrica Desta Consulta (Opcional)
                  </h4>
                  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                    <div>
                      <label for="dc9-weight_kg" style="font-size: 0.75rem; color: #64748B; display: block; margin-bottom: 4px;">Peso Atual (kg)</label>
                      <input id="dc9-weight_kg" type="number" step="0.1" name="weight_kg" value="<?= htmlspecialchars((string)($draft['weight_kg'] ?? '')) ?>" placeholder="Ex: 68,5" style="width: 100%; padding: 8px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                    </div>
                    <div>
                      <label for="dc10-waist_cm" style="font-size: 0.75rem; color: #64748B; display: block; margin-bottom: 4px;">Circunf. Cintura (cm)</label>
                      <input id="dc10-waist_cm" type="number" step="0.5" name="waist_cm" value="<?= htmlspecialchars((string)($draft['waist_cm'] ?? '')) ?>" placeholder="Ex: 78,0" style="width: 100%; padding: 8px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                    </div>
                  </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4);">
                  <a href="/agenda" class="btn btn-secondary" data-draft-pause>Pausar e Voltar à Agenda</a>
                  <button type="submit" class="btn btn-primary" style="font-size: 1rem; padding: 10px 24px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Concluir Consulta & Assinar Prontuário
                  </button>
                </div>
              </form>
              <script src="/assets/js/consulta-rascunho.js" defer></script>
              <?php endif; ?>
            </div>

            <!-- Histórico de Transições da Consulta -->
            <?php if (!empty($app['history'])): ?>
              <div class="card" style="margin-top: var(--space-4);">
                <h4 style="font-size: 0.875rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 12px;">
                  Trilha de Auditoria Desta Consulta
                </h4>
                <div style="font-size: 0.8125rem; color: var(--neutral-gray-700);">
                  <?php foreach ($app['history'] as $h): ?>
                    <div style="padding: 6px 0; border-bottom: 1px solid var(--neutral-gray-200); display: flex; justify-content: space-between; align-items: center;">
                      <div>
                        <strong><?= htmlspecialchars(!empty($h['from_status']) ? LabelMap::appointmentStatus($h['from_status']) : 'Criação') ?></strong> ➔ 
                        <?= LabelMap::appointmentStatusBadge($h['to_status'] ?? '') ?>
                        <?php if (!empty($h['notes'])): ?>
                          <span style="color: var(--neutral-gray-500);">— <?= htmlspecialchars($h['notes']) ?></span>
                        <?php endif; ?>
                      </div>
                      <span style="color: var(--neutral-gray-500);"><?= date('H:i:s d/m', strtotime($h['created_at'])) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php
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
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('[consulta] rascunho falhou: ' . get_class($e));
            http_response_code(500);
            echo json_encode(['error' => 'Não foi possível salvar o rascunho agora.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function completeConsultation(array $params): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /agenda?error=csrf');
            exit;
        }

        $id = (int)($params['id'] ?? 0);
        $summary = trim((string)($_POST['evolution_summary'] ?? ''));

        if (empty($summary)) {
            header("Location: /consulta/{$id}?error=resumo_obrigatorio");
            exit;
        }

        $details = [];
        if (!empty($_POST['weight_kg'])) {
            $details['weight_kg'] = (float)$_POST['weight_kg'];
        }
        if (!empty($_POST['waist_cm'])) {
            $details['waist_cm'] = (float)$_POST['waist_cm'];
        }

        $service = new AppointmentService();
        $app = $service->getAppointment($id);
        if (!$app) {
            header('Location: /agenda');
            exit;
        }

        try {
            $result = $service->completeConsultation($id, $summary, $details, TenantContext::getUserId());
            try {
                (new \ClyoNutri\Domain\Appointment\ConsultationDraftService())->discard($id, (int)TenantContext::getUserId(), (int)TenantContext::getClinicId());
            } catch (\Throwable $e) {
                error_log('[consulta] rascunho não apagado: ' . get_class($e));
            }

            // Se registrou peso, salva também na tabela de avaliações do paciente
            if (!empty($_POST['weight_kg'])) {
                $db = Connection::getInstance();
                $stmt = $db->prepare("
                    INSERT INTO assessments (clinic_id, patient_id, professional_id, weight_kg, waist_circumference_cm, assessed_at)
                    VALUES (:cid, :pid, :prid, :weight, :waist, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    'cid' => TenantContext::getClinicId(),
                    'pid' => $app['patient_id'],
                    'prid' => $app['professional_id'],
                    'weight' => (float)$_POST['weight_kg'],
                    'waist' => !empty($_POST['waist_cm']) ? (float)$_POST['waist_cm'] : null,
                ]);
            }

            header("Location: /pacientes/{$app['patient_id']}?success=consulta_concluida&hash=" . substr($result['record_hash'], 0, 8));
            exit;
        } catch (\Throwable $e) {
            header("Location: /consulta/{$id}?error=falha&msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    public function agenda(): void
    {
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
        $view = ($_GET['view'] ?? 'day') === 'week' ? 'week' : 'day';

        // NI2-413 (UX-01): passo de navegação depende da visão (7 dias na semanal, 1 na diária)
        $todayDate = $appointmentService->todayDate();
        $nav = \ClyoNutri\Core\Time\AgendaNavigation::forView($view, $currentDate, $todayDate);
        $prevDate = $nav['prev'];
        $nextDate = $nav['next'];

        try {
            if ($view === 'week') {
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

        $title = 'Agenda de Atendimentos';
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();

        $appointmentPreferences=(new AppointmentService())->practicePreferences($this->getActiveProfessionalId());
        $defaultDuration=(int)($appointmentPreferences['default_duration_minutes']??60);
        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'agendado' ? 'Consulta agendada com sucesso!' : ($_GET['success'] === 'reagendado' ? 'Consulta reagendada. O horário anterior e o novo ficam registrados na trilha da consulta.' : 'Consulta atualizada com sucesso!') ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['status_updated'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ Status da consulta atualizado. A mudança fica registrada no histórico da consulta.
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['error']) && !(($_GET['novo'] ?? '') === '1' && empty($_GET['msg']))): ?>
          <div style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #B91C1C;">
            <strong>Atenção:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na operação.' ?>
          </div>
        <?php endif; ?>

        <!-- Cabeçalho & Controles da Agenda -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <h1>Agenda de Atendimentos</h1>
            <p style="color: var(--neutral-gray-500);">
              Gestão da jornada clínica: agendamento, confirmação, ciclo da consulta e execução.
            </p>
          </div>

          <div class="agenda-controls-layout" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <!-- Navegação por Data -->
            <div style="display: inline-flex; border: 1px solid var(--neutral-gray-300); border-radius: 6px; overflow: hidden; background: #fff;">
              <a href="/agenda?view=<?= $view ?>&date=<?= $prevDate ?>" class="btn btn-secondary" aria-label="<?= htmlspecialchars($nav['prev_label']) ?>" title="<?= htmlspecialchars($nav['prev_label']) ?>" style="border: none; border-radius: 0; min-height: 38px; padding: 0 12px;"><span aria-hidden="true">←</span></a>
              <a href="/agenda?view=<?= $view ?>&date=<?= $todayDate ?>" class="btn btn-secondary" aria-label="<?= htmlspecialchars($nav['today_label']) ?>" <?= $nav['is_current'] ? 'aria-current="date"' : '' ?> style="border: none; border-radius: 0; min-height: 38px; padding: 0 12px; font-weight: 600;">Hoje</a>
              <a href="/agenda?view=<?= $view ?>&date=<?= $nextDate ?>" class="btn btn-secondary" aria-label="<?= htmlspecialchars($nav['next_label']) ?>" title="<?= htmlspecialchars($nav['next_label']) ?>" style="border: none; border-radius: 0; min-height: 38px; padding: 0 12px;"><span aria-hidden="true">→</span></a>
            </div>
            <span class="sr-only" role="status" id="agenda-period"><?= htmlspecialchars($nav['period_label']) ?></span>

            <label class="sr-only" for="agenda-date-picker">Ir para a data</label>
            <input type="date" id="agenda-date-picker" value="<?= $currentDate ?>" data-nav-prefix="/agenda?view=<?= $view ?>&date=" style="padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.875rem; background: #fff;">

            <!-- Alternância de Visão Dia / Semana -->
            <div style="display: inline-flex; border: 1px solid var(--neutral-gray-300); border-radius: 6px; overflow: hidden; background: #fff;">
              <a href="/agenda?view=day&date=<?= $currentDate ?>" class="btn <?= $view === 'day' ? 'btn-primary' : 'btn-secondary' ?>" style="border: none; border-radius: 0; min-height: 38px;">Visão Diária</a>
              <a href="/agenda?view=week&date=<?= $currentDate ?>" class="btn <?= $view === 'week' ? 'btn-primary' : 'btn-secondary' ?>" style="border: none; border-radius: 0; min-height: 38px;">Visão Semanal</a>
            </div>

            <!-- Botão Novo Agendamento -->
            <button type="button" aria-controls="card-novo-agendamento" data-toggle-dialog="card-novo-agendamento" class="btn btn-accent" style="min-height: 38px;">
              + Nova Consulta
            </button>
          </div>
        </div>

        <!-- Formulário Retrátil de Novo Agendamento -->
        <?php $prefillPatient = (isset($_GET['paciente']) && ctype_digit((string)$_GET['paciente'])) ? (int)$_GET['paciente'] : 0; $openNew = ($_GET['novo'] ?? '') === '1';
          // UX-05 (v14): preenchimento devolvido após conflito/falha.
          $agendaRecovered = $this->takeAgendaForm('new'); $rv = $agendaRecovered['values'] ?? [];
          if ($agendaRecovered) { $openNew = true; $prefillPatient = (int)($rv['patient_id'] ?? $prefillPatient); }
          $newConflict = !empty($agendaRecovered['conflict']);
          $newScheduled = $rv['scheduled_at'] ?? ($currentDate . 'T09:00');
          if (isset($rv['duration_minutes'])) { $defaultDuration = (int)$rv['duration_minutes']; }
          $newType = (string)($rv['type'] ?? 'first_visit'); ?>
        <div id="card-novo-agendamento" data-dialog="inline" class="card" style="display: <?= $openNew ? 'block' : 'none' ?>; border-top: 4px solid var(--brand-copper-500); margin-bottom: var(--space-6);">
          <div class="card__header">
            <h3>Agendar Nova Consulta</h3>
            <button type="button" data-dialog-close aria-label="Fechar agendamento" data-hide="card-novo-agendamento" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">✕</button>
          </div>
          <?php if ($agendaRecovered): ?>
            <div role="alert" data-testid="agenda-form-error" style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 10px 14px; border-radius: 6px; margin-top: 12px; color: #B91C1C;">
              <strong><?= $newConflict ? 'Horário indisponível.' : 'Não foi possível agendar.' ?></strong> <?= htmlspecialchars((string)$agendaRecovered['error']) ?> Seus dados foram mantidos; ajuste o horário<?= $newConflict ? ' ou marque o encaixe' : '' ?> e confirme de novo.
            </div>
          <?php endif; ?>
          <form action="/agenda" method="POST" style="margin-top: 16px;" <?= $agendaRecovered ? 'data-start-dirty' : '' ?>>
            <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
              <div>
                <label for="dc11-patient_id" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Paciente *</label>
                <select id="dc11-patient_id" name="patient_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o paciente...</option>
                  <?php foreach ($patients as $pt): ?>
                    <option value="<?= $pt['id'] ?>" <?= ((int)$pt['id'] === $prefillPatient) ? 'selected' : '' ?>><?= htmlspecialchars($pt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label for="dc12-scheduled_at" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Data e Horário *</label>
                <input id="dc12-scheduled_at" type="datetime-local" name="scheduled_at" required value="<?= htmlspecialchars($newScheduled) ?>" <?= $newConflict ? 'aria-invalid="true" aria-describedby="dc12-scheduled_at-erro" autofocus data-conflict-highlight style="width: 100%; padding: 8px 12px; border: 2px solid #DC2626; border-radius: 6px; background: #FEF2F2;"' : 'style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"' ?>>
                <?php if ($newConflict): ?><p id="dc12-scheduled_at-erro" class="field-error" style="color: #B91C1C; font-size: 0.8rem; margin: 4px 0 0;">Conflita com outra consulta. Escolha outro horário.</p><?php endif; ?>
              </div>

              <div>
                <label for="dc13-duration_minutes" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Duração</label>
                <select id="dc13-duration_minutes" name="duration_minutes" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <?php $durationChoices=array_unique([30,45,60,90,120,$defaultDuration]);sort($durationChoices);foreach($durationChoices as $minutes): ?>
                  <option value="<?= $minutes ?>" <?= $minutes===$defaultDuration?'selected':'' ?>><?= $minutes ?> minutos<?= $minutes===$defaultDuration?' (Padrão)':'' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label for="dc14-type" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Tipo de Atendimento</label>
                <select id="dc14-type" name="type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="first_visit" <?= $newType === 'first_visit' ? 'selected' : '' ?>>Primeira Consulta / Anamnese</option>
                  <option value="follow_up" <?= $newType === 'follow_up' ? 'selected' : '' ?>>Retorno / Acompanhamento</option>
                  <option value="telehealth" <?= $newType === 'telehealth' ? 'selected' : '' ?>>Teleconsulta</option>
                </select>
              </div>
            </div>

            <div style="margin-bottom: 16px;">
              <label for="dc15-notes" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Observações</label>
              <textarea id="dc15-notes" name="notes" rows="2" placeholder="Queixas iniciais, encaminhamentos ou solicitações pré-consulta..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"><?= htmlspecialchars((string)($rv['notes'] ?? '')) ?></textarea>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--neutral-gray-200); padding-top: 12px;">
              <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #64748B; cursor: pointer;">
                <input type="checkbox" name="allow_conflict" value="1">
                <span>Permitir sobreposição de horário (encaixe)</span>
              </label>

              <div style="display: flex; gap: 8px;">
                <button type="button" data-dialog-close data-hide="card-novo-agendamento" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar Agendamento</button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($view === 'day'): ?>
          <!-- Visão Diária da Agenda -->
          <div class="card">
            <div class="card__header">
              <h3>Consultas do Dia (<?= date('d/m/Y', strtotime($currentDate)) ?>)</h3>
              <span style="font-size: 0.875rem; color: var(--neutral-gray-500);"><?= count($appointments) ?> agendamento(s)</span>
            </div>

            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Horário</th>
                    <th>Paciente</th>
                    <th>Contato</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th style="text-align: right;">Ações da Consulta</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($appointments)): ?>
                    <tr>
                      <td colspan="6" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-8);">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 8px auto; display: block; color: #94A3B8;"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        Nenhuma consulta agendada para este dia. Horários disponíveis para novos pacientes.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($appointments as $a): ?>
                      <tr>
                        <td class="tabular-nums" style="font-weight: 700; color: var(--brand-navy-900);">
                          <?= date('H:i', strtotime($a['scheduled_at'])) ?>
                          <small style="display: block; font-size: 0.75rem; font-weight: normal; color: var(--neutral-gray-500);"><?= $a['duration_minutes'] ?> min</small>
                        </td>
                        <td>
                          <a href="/pacientes/<?= $a['patient_id'] ?>" style="font-weight: 600; color: var(--brand-navy-900); text-decoration: none;">
                            <?= htmlspecialchars($a['patient_name']) ?>
                          </a>
                        </td>
                        <td><?= htmlspecialchars($a['patient_phone'] ?? '—') ?></td>
                        <td><?= htmlspecialchars(LabelMap::appointmentType($a['type'])) ?></td>
                        <td>
                          <?= LabelMap::appointmentStatusBadge($a['status']) ?>
                        </td>
                        <td style="text-align: right;">
                          <div style="display: inline-flex; gap: 6px; justify-content: flex-end;">
                            <?php if ($a['status'] === 'scheduled'): ?>
                              <!-- Confirmar -->
                              <form action="/agenda/<?= $a['id'] ?>/status" method="POST" style="display: inline;">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="status" value="confirmed">
                                <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 4px 8px; min-height: 30px;">Confirmar</button>
                              </form>
                              <!-- Iniciar -->
                              <a href="/consulta/<?= $a['id'] ?>" class="btn btn-primary" style="font-size: 0.75rem; padding: 4px 10px; min-height: 30px;">Iniciar</a>
                            <?php elseif ($a['status'] === 'confirmed'): ?>
                              <!-- Iniciar -->
                              <a href="/consulta/<?= $a['id'] ?>" class="btn btn-primary" style="font-size: 0.75rem; padding: 4px 10px; min-height: 30px;">Iniciar Atendimento</a>
                            <?php elseif ($a['status'] === 'in_progress'): ?>
                              <a href="/consulta/<?= $a['id'] ?>" class="btn btn-accent" style="font-size: 0.75rem; padding: 4px 10px; min-height: 30px;">Continuar Atendimento</a>
                            <?php elseif ($a['status'] === 'completed'): ?>
                              <a href="/pacientes/<?= $a['patient_id'] ?>" class="btn btn-secondary" style="font-size: 0.75rem; padding: 4px 8px; min-height: 30px;">Prontuário ↗</a>
                            <?php endif; ?>

                            <!-- Menu de Estados da Consulta -->
                            <?php if ($a['status'] === 'scheduled' || $a['status'] === 'confirmed'): ?>
                              <a href="/agenda/<?= (int)$a['id'] ?>/reagendar" class="btn btn-secondary" data-testid="agenda-reschedule" style="font-size: 0.75rem; padding: 4px 8px; min-height: 30px;">Reagendar</a>
                              <form action="/agenda/<?= $a['id'] ?>/status" method="POST" style="display: inline;" data-confirm="Deseja marcar como Não Compareceu?">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="status" value="no_show">
                                <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 4px 8px; min-height: 30px; color: #DC2626;" title="Não compareceu">Não compareceu</button>
                              </form>
                              <form action="/agenda/<?= $a['id'] ?>/status" method="POST" style="display: inline;" data-confirm="Confirmar cancelamento da consulta?">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="status" value="cancelled">
                                <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 4px 8px; min-height: 30px; color: #64748B;" title="Cancelar consulta">Cancelar</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php else: ?>
          <!-- Visão Semanal da Agenda -->
          <div class="card">
            <div class="card__header">
              <h3>Semana de <?= date('d/m', strtotime($weekData['start_date'])) ?> a <?= date('d/m/Y', strtotime($weekData['end_date'])) ?></h3>
              <span style="font-size: 0.875rem; color: var(--neutral-gray-500);"><?= $weekData['total_appointments'] ?> consulta(s) nesta semana</span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 16px;">
              <?php foreach ($weekData['days'] as $dayDate => $dayApps): ?>
                <?php 
                  $isToday = $dayDate === $todayDate;
                  $dayName = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][date('w', strtotime($dayDate))];
                ?>
                <div style="background: <?= $isToday ? '#FFF4ED' : '#F8FAFC' ?>; border: 1px solid <?= $isToday ? 'var(--brand-copper-500)' : 'var(--neutral-gray-200)' ?>; border-radius: 8px; padding: 12px; min-height: 220px; display: flex; flex-direction: column;">
                  <div style="border-bottom: 1px solid var(--neutral-gray-200); padding-bottom: 8px; margin-bottom: 8px; text-align: center;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: <?= $isToday ? 'var(--brand-copper-600)' : 'var(--brand-navy-900)' ?>;"><?= $dayName ?></span>
                    <strong style="display: block; font-size: 1rem; color: var(--brand-navy-900);"><?= date('d/m', strtotime($dayDate)) ?></strong>
                  </div>

                  <div style="flex: 1; display: flex; flex-direction: column; gap: 8px;">
                    <?php if (empty($dayApps)): ?>
                      <span style="font-size: 0.75rem; color: var(--neutral-gray-500); text-align: center; margin-top: 20px;">Livre</span>
                    <?php else: ?>
                      <?php foreach ($dayApps as $dApp): ?>
                        <div style="background: #fff; border: 1px solid var(--neutral-gray-200); border-radius: 6px; padding: 6px 8px; font-size: 0.75rem;">
                          <div style="display: flex; justify-content: space-between; align-items: center; font-weight: 700; color: var(--brand-navy-900);">
                            <span><?= date('H:i', strtotime($dApp['scheduled_at'])) ?></span>
                            <?= LabelMap::appointmentStatusBadge($dApp['status']) ?>
                          </div>
                          <a href="/consulta/<?= $dApp['id'] ?>" style="display: block; color: var(--brand-navy-900); text-decoration: none; font-weight: 600; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= htmlspecialchars($dApp['patient_name']) ?>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }


    public function mealPlans(): void
    {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $clinicId = TenantContext::getClinicId();
        $stmt = $db->prepare("
            SELECT mp.*, p.name AS patient_name, u.name AS professional_name
            FROM meal_plans mp
            JOIN patients p ON p.id = mp.patient_id
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

        $title = 'Planos Alimentares';
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
          <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;">Prescrições e Planos Alimentares</h1>
            <p style="color: var(--neutral-gray-500); margin-top: 4px;">Monte refeições, alimentos e substituições e acompanhe os totais. Cada publicação gera uma nova versão; as anteriores ficam no histórico.</p>
          </div>
          <button class="btn btn-primary" data-show="modal-new-plan">
            + Novo Plano Alimentar
          </button>
        </div>

        <?php if ($feedback): ?>
          <div style="background: #e8f5e9; color: #2e7d32; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #c8e6c9;">
            <?= htmlspecialchars($feedback) ?>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div style="background: #ffebee; color: #c62828; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #ffcdd2;">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <nav class="plan-filters" aria-label="Filtrar planos por status" data-testid="plan-status-filter">
          <?php foreach (['' => 'Todos'] + $planFilterLabels as $fKey => $fLabel): ?>
            <a href="/planos<?= $fKey !== '' ? '?status=' . $fKey : '' ?>" class="plan-filters__item<?= $planStatusFilter === $fKey && $planPatientFilter === 0 ? ' is-active' : '' ?>"<?= $planStatusFilter === $fKey && $planPatientFilter === 0 ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($fLabel) ?></a>
          <?php endforeach; ?>
          <?php if ($planPatientFilter > 0): ?>
            <span class="plan-filters__note">Filtrado por paciente<?= !empty($plans) ? ': ' . htmlspecialchars($plans[0]['patient_name']) : '' ?> · <a href="/planos<?= $planStatusFilter !== '' ? '?status=' . $planStatusFilter : '' ?>">limpar filtro de paciente</a></span>
          <?php endif; ?>
        </nav>

        <div class="card">
          <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse;">
              <thead>
                <tr style="border-bottom: 1px solid var(--neutral-gray-200); text-align: left;">
                  <th style="padding: 12px;">Título do Plano</th>
                  <th style="padding: 12px;">Paciente</th>
                  <th style="padding: 12px;">Versão</th>
                  <th style="padding: 12px;">Status</th>
                  <th style="padding: 12px;">Energia</th>
                  <th style="padding: 12px;">Última Atualização</th>
                  <th style="padding: 12px; text-align: right;">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($plans)): ?>
                  <tr><td colspan="7" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);"><?= ($planStatusFilter !== '' || $planPatientFilter > 0) ? 'Nenhum plano encontrado para este filtro.' : 'Nenhum plano alimentar cadastrado ainda.' ?></td></tr>
                <?php else: ?>
                  <?php foreach ($plans as $pl): ?>
                    <tr style="border-bottom: 1px solid var(--neutral-gray-100);">
                      <td style="padding: 12px; font-weight: 600; color: var(--brand-navy-900);">
                        <?= htmlspecialchars($pl['title']) ?>
                        <?php if (!empty($pl['parent_plan_id'])): ?>
                          <span style="font-size: 0.75rem; color: #64748b; display: block; font-weight: normal;">(Derivado de #<?= (int)$pl['parent_plan_id'] ?>)</span>
                        <?php endif; ?>
                      </td>
                      <td style="padding: 12px;"><?= htmlspecialchars($pl['patient_name']) ?></td>
                      <td style="padding: 12px;" class="tabular-nums">v<?= (int)$pl['version'] ?></td>
                      <td style="padding: 12px;">
                        <?= LabelMap::mealPlanStatusBadge($pl['status'] ?? '') ?>
                      </td>
                      <td style="padding: 12px;" class="tabular-nums"><?= number_format((float)($pl['daily_energy_kcal'] ?? 0), 0, ',', '.') ?> kcal</td>
                      <td style="padding: 12px; color: #64748b; font-size: 0.85rem;"><?= date('d/m/Y H:i', strtotime($pl['updated_at'] ?? $pl['created_at'])) ?></td>
                      <td style="padding: 12px; text-align: right; white-space: nowrap;">
                        <a href="/planos/<?= $pl['id'] ?>" class="btn btn-sm btn-outline" style="text-decoration: none; padding: 4px 10px; font-size: 0.8rem; margin-right: 4px;">
                          <?= $pl['status'] === 'draft' ? 'Editar' : 'Visualizar' ?>
                        </a>
                        <form method="POST" action="/planos/<?= $pl['id'] ?>/duplicar" style="display: inline;" data-confirm="Deseja duplicar este plano para um novo rascunho?">
                          <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                          <button type="submit" class="btn btn-sm btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Duplicar</button>
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
        <div id="modal-new-plan" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 16px;">Novo plano alimentar</h2>
            <form method="POST" action="/planos/novo">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <div style="margin-bottom: 16px;">
                <label for="dc16-patient_id" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Paciente *</label>
                <select id="dc16-patient_id" name="patient_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o paciente...</option>
                  <?php foreach ($patients as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="margin-bottom: 16px;">
                <label for="dc17-title" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Título da Prescrição *</label>
                <input id="dc17-title" type="text" name="title" required placeholder="Ex: Plano Hipertrofia Fase 1" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 20px;">
                <label for="dc18-notes" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientações Gerais do Plano (Opcional)</label>
                <textarea id="dc18-notes" name="notes" rows="3" placeholder="Recomendações clínicas, horários preferenciais, hidratação..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-new-plan">Cancelar</button>
                <button type="submit" class="btn btn-primary">Iniciar Prescrição</button>
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

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

        try {
            $created = $service->createPlan($patientId, $professionalId, $title, $notes);
            $_SESSION['feedback_message'] = "Plano alimentar '{$title}' criado com sucesso!";
            header("Location: /planos/" . $created['plan_id']);
            exit;
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao criar plano: " . $e->getMessage();
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
            echo 'Você não tem permissão para acessar prescrições.';
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
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        $isDraft = ($plan['status'] === 'draft');
        $canEditItems = $this->canEditPlanItems($db);

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
              <a href="/planos" style="text-decoration: none; color: #475569; font-size: 0.9rem;">← Voltar para lista</a>
              <span class="badge" style="background: <?= $isDraft ? '#fef08a' : '#def7ec' ?>; color: <?= $isDraft ? '#854d0e' : '#03543f' ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                <?= $isDraft ? 'RASCUNHO (Edição aberta)' : 'PUBLICADO (versão ' . (int)$plan['version'] . ', somente leitura)' ?>
              </span>
            </div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;"><?= htmlspecialchars($plan['title']) ?></h1>
            <p style="color: var(--neutral-gray-500); margin-top: 4px;">Paciente: <strong><?= htmlspecialchars($plan['patient_name']) ?></strong> | Responsável: <?= htmlspecialchars($plan['professional_name']) ?></p>
          </div>
          <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="/planos/<?= (int)$planId ?>/imprimir" target="_blank" rel="noopener" class="btn btn-outline" style="font-size: 0.875rem;">Imprimir plano</a>
            <?php if ($canEditItems): ?>
            <form method="POST" action="/planos/<?= $planId ?>/duplicar" data-confirm="Deseja criar um novo rascunho a partir deste plano?">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <button type="submit" class="btn btn-outline" style="font-size: 0.875rem;"><?= $isDraft ? 'Duplicar como Rascunho' : 'Criar nova versão para editar' ?></button>
            </form>
            <?php endif; ?>
            <?php if ($isDraft): ?>
              <button class="btn btn-primary" data-show="modal-publish-plan">
                Revisar & Publicar
              </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($feedback): ?>
          <div style="background: #e8f5e9; color: #2e7d32; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #c8e6c9;">
            <?= htmlspecialchars($feedback) ?>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div style="background: #ffebee; color: #c62828; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #ffcdd2;">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <style>
          @media (max-width: 600px) {
            .meal-plan-items, .meal-plan-items tbody { display: block; width: 100%; }
            .meal-plan-items tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .meal-plan-items td { display: block; width: auto !important; padding: var(--space-3) !important; overflow-wrap: anywhere; }
            .meal-plan-items tr:has(details) { display: block; }
            .meal-plan-items input, .meal-plan-items textarea { min-width: 0; max-width: 100%; }
          }
        </style>

        <!-- Resumo Clínico para Prescrição: Dados Críticos com Origem Rastreável -->
        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-left: 4px solid #0284C7; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <strong style="color: var(--brand-navy-900); font-size: 0.95rem;">📋 Resumo Clínico da Anamnese</strong>
            <?php if ($clinicalSummary['has_data'] && !empty($clinicalSummary['source'])): ?>
              <span style="font-size: 0.75rem; color: #64748B;">
                Origem: <a href="<?= $clinicalSummary['source']['url'] ?>" target="_blank" style="color: var(--brand-copper-600); font-weight: 600;">Anamnese v<?= $clinicalSummary['source']['version'] ?> ↗</a> · <?= htmlspecialchars($clinicalSummary['source']['recorded_at']) ?><?= $clinicalSummary['is_draft'] ? ' · Rascunho' : ' · Finalizada' ?>
              </span>
            <?php endif; ?>
          </div>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.85rem;">
            <div>
              <span style="color: <?= !empty($clinicalSummary['allergies']) ? '#DC2626' : '#64748B' ?>; font-weight: 600; display: block; font-size: 0.75rem;">ALERGIAS:</span>
              <span style="color: #1E293B;"><?= htmlspecialchars($clinicalSummary['allergy_label']) ?><?= !empty($clinicalSummary['allergies']) ? ': ' . htmlspecialchars($clinicalSummary['allergies']) : '' ?></span>
            </div>
            <div>
              <span style="color: <?= !empty($clinicalSummary['restrictions']) ? '#D97706' : '#64748B' ?>; font-weight: 600; display: block; font-size: 0.75rem;">RESTRIÇÕES:</span>
              <span style="color: #1E293B;"><?= htmlspecialchars(LabelMap::recorded($clinicalSummary['restrictions'] ?? null)) ?></span>
            </div>
            <div>
              <span style="color: #0369A1; font-weight: 600; display: block; font-size: 0.75rem;">OBJETIVOS:</span>
              <span style="color: #1E293B;"><?= htmlspecialchars(LabelMap::recorded($clinicalSummary['objectives'] ?? null)) ?></span>
            </div>
          </div>
        </div>

        <?php if ($clinicalSummary['allergy_pending']): ?>
          <div role="status" data-testid="allergy-pending" class="card" style="border-left: 4px solid var(--brand-copper-600); margin-bottom: var(--space-4);">
            Avaliação de alergias pendente<?= $clinicalSummary['is_draft'] ? ': a anamnese está em rascunho' : '' ?>. A ausência de informação não confirma ausência de alergias.
            <a href="/pacientes/<?= (int)$plan['patient_id'] ?>/anamnese">Revisar anamnese</a>
          </div>
        <?php endif; ?>

        <!-- Alertas de Segurança Clínica e Alérgenos -->
        <?php if (!empty($preview['allergen_safety']['has_conflict'])): ?>
          <div style="background: #fff3cd; color: #856404; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffeeba;">
            <strong style="display: block; margin-bottom: 6px;">⚠️ Atenção Clínica: Conflitos Alergênicos Detectados com a Anamnese!</strong>
            <ul style="margin: 0; padding-left: 20px; font-size: 0.9rem;">
              <?php foreach ($preview['allergen_safety']['conflicts'] as $conf): ?>
                <li>Alimento <strong><?= htmlspecialchars($conf['food_name']) ?></strong> possui grupo alergênico registrado: <em><?= htmlspecialchars(LabelMap::allergen($conf['allergen_group'])) ?></em>.</li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <?php if (!empty($preview['allergen_safety']['unverified_foods']) || !empty($preview['allergen_safety']['unmapped_allergy_text'])): ?>
          <div role="status" data-testid="allergen-unverified" style="background: #fff7ed; color: #7c2d12; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fed7aa;">
            <strong style="display: block; margin-bottom: 6px;">Alergia registrada: confira os alimentos abaixo</strong>
            <?php if (!empty($preview['allergen_safety']['unmapped_allergy_text'])): ?>
              <p style="margin: 0 0 6px; font-size: 0.9rem;">A alergia descrita na anamnese não corresponde a um grupo alergênico conhecido, então nenhum alimento pôde ser conferido automaticamente.</p>
            <?php endif; ?>
            <?php if (!empty($preview['allergen_safety']['unverified_foods'])): ?>
              <p style="margin: 0 0 6px; font-size: 0.9rem;">Estes alimentos não têm informação de alérgenos no catálogo. A falta de informação não confirma que são seguros:</p>
              <ul style="margin: 0; padding-left: 20px; font-size: 0.9rem;">
                <?php foreach ($preview['allergen_safety']['unverified_foods'] as $uf): ?>
                  <li><?= htmlspecialchars($uf['food_name']) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <p style="margin: 6px 0 0; font-size: 0.85rem;">Para publicar, será preciso confirmar e justificar a prescrição.</p>
          </div>
        <?php endif; ?>
        <?php if (!empty($preview['estimated_weights'])): ?>
          <div role="note" data-testid="estimated-weights" style="background: #f8fafc; color: #334155; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; font-size: 0.85rem;">
            <strong>Peso estimado em <?= count($preview['estimated_weights']) ?> item(ns):</strong> a medida caseira usada não está cadastrada para o alimento, então o cálculo usa um peso padrão (ex.: 1 unidade = 100 g). Prefira gramas ou uma medida cadastrada para o alimento.
          </div>
        <?php endif; ?>

        <!-- Dashboard de Totais Nutricionais Consolidados -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px;">
          <div class="card" style="padding: 16px; text-align: center; border-left: 4px solid #3b82f6;">
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Energia Diária</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-top: 4px;"><?= number_format((float)$plan['daily_energy_kcal'], 0, ',', '.') ?> <span style="font-size: 0.9rem; font-weight: normal;">kcal</span></div>
          </div>
          <div class="card" style="padding: 16px; text-align: center; border-left: 4px solid #10b981;">
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Carboidratos</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-top: 4px;"><?= number_format((float)$plan['carbohydrate_g'], 1, ',', '.') ?> <span style="font-size: 0.9rem; font-weight: normal;">g</span></div>
          </div>
          <div class="card" style="padding: 16px; text-align: center; border-left: 4px solid #ef4444;">
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Proteínas</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-top: 4px;"><?= number_format((float)$plan['protein_g'], 1, ',', '.') ?> <span style="font-size: 0.9rem; font-weight: normal;">g</span></div>
          </div>
          <div class="card" style="padding: 16px; text-align: center; border-left: 4px solid #f59e0b;">
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Lipídeos</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-top: 4px;"><?= number_format((float)$plan['lipid_g'], 1, ',', '.') ?> <span style="font-size: 0.9rem; font-weight: normal;">g</span></div>
          </div>
          <div class="card" style="padding: 16px; text-align: center; border-left: 4px solid #8b5cf6;">
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Fibras</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-top: 4px;"><?= number_format((float)$plan['fiber_g'], 1, ',', '.') ?> <span style="font-size: 0.9rem; font-weight: normal;">g</span></div>
          </div>
        </div>

        <?php if (!empty($plan['notes'])): ?>
          <div class="card" style="padding: 16px; margin-bottom: 24px; background: #f8fafc;">
            <strong style="color: var(--brand-navy-900); font-size: 0.9rem;">Orientações Gerais do Plano:</strong>
            <p style="margin: 4px 0 0 0; color: #475569; font-size: 0.9rem; white-space: pre-line;"><?= htmlspecialchars($plan['notes']) ?></p>
          </div>
        <?php endif; ?>

        <!-- Refeições e Alimentos -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;">Refeições do Dia</h2>
          <?php if ($isDraft): ?>
            <button class="btn btn-sm btn-outline" data-show="modal-add-meal">
              + Adicionar Refeição
            </button>
          <?php endif; ?>
        </div>

        <?php if (empty($plan['meals'])): ?>
          <div class="card" style="padding: 32px; text-align: center; color: #64748b;">
            <p style="margin-bottom: 12px;">Nenhuma refeição adicionada a esta prescrição.</p>
            <?php if ($isDraft): ?>
              <button class="btn btn-primary" data-show="modal-add-meal">Adicionar Primeira Refeição</button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($plan['meals'] as $m): ?>
            <div class="card" style="margin-bottom: 20px; overflow: hidden; border: 1px solid var(--neutral-gray-200);">
              <!-- Cabeçalho da Refeição -->
              <div style="padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid var(--neutral-gray-200); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                  <span style="font-weight: 700; font-size: 1.1rem; color: var(--brand-navy-900);"><?= htmlspecialchars($m['name']) ?></span>
                  <?php if (!empty($m['time_of_day'])): ?>
                    <span style="background: #e2e8f0; color: #334155; font-size: 0.8rem; padding: 2px 8px; border-radius: 4px; font-weight: 600;">⏰ <?= htmlspecialchars($m['time_of_day']) ?></span>
                  <?php endif; ?>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                  <span style="font-size: 0.85rem; color: #64748b;">
                    <strong><?= number_format((float)$m['totals']['energy_kcal'], 0, ',', '.') ?> kcal</strong> | P: <?= number_format((float)$m['totals']['protein_g'], 1, ',', '.') ?> g | C: <?= number_format((float)$m['totals']['carbohydrate_g'], 1, ',', '.') ?> g | G: <?= number_format((float)$m['totals']['lipid_g'], 1, ',', '.') ?> g
                  </span>
                  <?php if ($isDraft): ?>
                    <button class="btn btn-sm btn-outline" data-call="openAddItemModal" data-args="[<?= $m['id'] ?>, <?= htmlspecialchars(json_encode((string)$m['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]" style="padding: 4px 8px; font-size: 0.8rem;">+ Alimento</button>
                    <form method="POST" action="/planos/<?= $planId ?>/refeicoes/<?= $m['id'] ?>/remover" style="display: inline;" data-confirm="Deseja excluir esta refeição e seus itens?">
                      <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                      <button type="submit" class="btn btn-sm" style="color: #B91C1C; min-width: 28px; min-height: 28px; background: none; border: none; cursor: pointer; font-size: 0.85rem;">Excluir</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <?php if (!empty($m['notes'])): ?>
                <div style="padding: 8px 20px; background: #f1f5f9; font-size: 0.85rem; color: #475569; border-bottom: 1px solid #e2e8f0;">
                  <em>Orientações: <?= htmlspecialchars($m['notes']) ?></em>
                </div>
              <?php endif; ?>

              <!-- Lista de Itens -->
              <div style="padding: 0;">
                <?php if (empty($m['items'])): ?>
                  <div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 0.9rem;">
                    Nenhum alimento cadastrado nesta refeição.
                  </div>
                <?php else: ?>
                  <table class="meal-plan-items" style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                     <tbody>
                      <?php foreach ($m['items'] as $it): ?>
                        <tr style="border-bottom: 1px solid var(--neutral-gray-100);">
                          <td style="padding: 12px 20px; width: 40%;">
                            <strong style="color: var(--brand-navy-900);"><?= htmlspecialchars($it['food_name']) ?></strong>
                            <?php if (!empty($it['notes'])): ?>
                              <span style="display: block; font-size: 0.8rem; color: #64748b;"><?= htmlspecialchars($it['notes']) ?></span>
                            <?php endif; ?>
                          </td>
                          <td style="padding: 12px 20px; font-weight: 600; color: #334155; width: 20%;">
                            <?= number_format((float)$it['amount'], 0, ',', '.') ?> <?= htmlspecialchars($it['measure_unit']) ?>
                          </td>
                          <td style="padding: 12px 20px; color: #64748b; width: 25%; font-size: 0.85rem;">
                            <?= number_format((float)$it['energy_kcal'], 0, ',', '.') ?> kcal <span style="font-size: 0.75rem;">(P:<?= number_format((float)$it['protein_g'], 1, ',', '.') ?> C:<?= number_format((float)$it['carbohydrate_g'], 1, ',', '.') ?> G:<?= number_format((float)$it['lipid_g'], 1, ',', '.') ?>)</span>
                          </td>
                          <td style="padding: 12px 20px; text-align: right; width: 15%;">
                            <?php if ($isDraft): ?>
                              <button class="btn btn-sm btn-outline" data-call="openAddSubModal" data-args="[<?= $it['id'] ?>, <?= htmlspecialchars(json_encode((string)$it['food_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]" style="padding: 2px 6px; font-size: 0.75rem; margin-right: 4px;">
                                + Substituição
                              </button>
                              <form method="POST" action="/planos/<?= $planId ?>/itens/<?= $it['id'] ?>/remover" style="display: inline;" data-confirm="Remover este alimento?">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" style="color: #B91C1C; min-width: 28px; min-height: 28px; background: none; border: none; cursor: pointer; font-size: 0.8rem;">✕</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>

                        <?php if ($isDraft && $canEditItems): ?>
                        <tr><td colspan="4" style="padding: var(--space-3) var(--space-4);">
                          <details data-testid="edit-plan-item-<?= (int)$it['id'] ?>">
                            <summary style="cursor: pointer; color: var(--brand-navy-900); font-weight: 600;">Editar porção e orientação</summary>
                            <form method="POST" action="/planos/<?= $planId ?>/itens/<?= (int)$it['id'] ?>/editar" style="margin-top: var(--space-3);" data-busy-text="Salvando…">
                              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                              <input type="hidden" name="revision" value="<?= MealPlanService::itemRevision($it) ?>">
                              <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-3);">
                                <div class="form-group"><label class="form-label" for="item-amount-<?= (int)$it['id'] ?>">Quantidade</label><input class="form-control" id="item-amount-<?= (int)$it['id'] ?>" name="amount" type="number" min="0.001" step="any" required value="<?= htmlspecialchars((string)$it['amount'], ENT_QUOTES) ?>"></div>
                                <div class="form-group"><label class="form-label" for="item-unit-<?= (int)$it['id'] ?>">Unidade ou medida caseira</label><input class="form-control" id="item-unit-<?= (int)$it['id'] ?>" name="measure_unit" required value="<?= htmlspecialchars($it['measure_unit'], ENT_QUOTES) ?>"></div>
                              </div>
                              <div class="form-group"><label class="form-label" for="item-notes-<?= (int)$it['id'] ?>">Orientação</label><textarea class="form-control" id="item-notes-<?= (int)$it['id'] ?>" name="notes"><?= htmlspecialchars($it['notes'] ?? '', ENT_QUOTES) ?></textarea></div>
                              <button type="submit" class="btn btn-primary">Salvar alterações</button>
                              <button type="button" class="btn btn-secondary" data-reset-close-details>Cancelar</button>
                            </form>
                          </details>
                        </td></tr>
                        <?php endif; ?>

                        <!-- Substituições Vinculadas (Opções Alternativas - Não Cumulativas) -->
                        <?php if (!empty($it['substitutions'])): ?>
                          <?php foreach ($it['substitutions'] as $sub): ?>
                            <tr style="background: #fafaf9; border-bottom: 1px dashed #e7e5e4;">
                              <td style="padding: 8px 20px 8px 40px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                  <span style="color: #92400E; font-size: 0.8rem; font-weight: 600;">↳ Opção Alternativa:</span>
                                  <span style="color: #44403c;"><?= htmlspecialchars($sub['food_name']) ?></span>
                                </div>
                                <?php if (!empty($sub['notes'])): ?>
                                  <span style="display: block; font-size: 0.75rem; color: #78716c; margin-left: 20px;"><?= htmlspecialchars($sub['notes']) ?></span>
                                <?php endif; ?>
                              </td>
                              <td style="padding: 8px 20px; font-size: 0.85rem; color: #44403c;">
                                <?= number_format((float)$sub['amount'], 0, ',', '.') ?> <?= htmlspecialchars($sub['measure_unit']) ?>
                              </td>
                              <td style="padding: 8px 20px; font-size: 0.8rem; color: #78716c;">
                                <?= number_format((float)$sub['energy_kcal'], 0, ',', '.') ?> kcal (P:<?= number_format((float)$sub['protein_g'], 1, ',', '.') ?> C:<?= number_format((float)$sub['carbohydrate_g'], 1, ',', '.') ?> G:<?= number_format((float)$sub['lipid_g'], 1, ',', '.') ?>)
                              </td>
                              <td style="padding: 8px 20px; text-align: right;">
                                <?php if ($isDraft): ?>
                                  <form method="POST" action="/planos/<?= $planId ?>/substituicoes/<?= $sub['id'] ?>/remover" style="display: inline;" data-confirm="Remover substituição?">
                                    <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                    <button type="submit" style="color: #57534E; background: none; border: none; cursor: pointer; font-size: 0.75rem;">Remover</button>
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

        <!-- Modal Adicionar Refeição -->
        <div id="modal-add-meal" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 480px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 16px;">Adicionar Refeição</h2>
            <form method="POST" action="/planos/<?= $planId ?>/refeicoes">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <div style="margin-bottom: 16px;">
                <label for="dc19-name" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Nome da Refeição *</label>
                <input id="dc19-name" type="text" name="name" required placeholder="Ex: Café da Manhã, Almoço, Ceia" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 16px;">
                <label for="dc20-time_of_day" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Horário Sugerido (HH:MM)</label>
                <input id="dc20-time_of_day" type="text" name="time_of_day" placeholder="Ex: 08:00" pattern="^([01]\d|2[0-3]):[0-5]\d$" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 20px;">
                <label for="dc21-notes" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientações Clínicas da Refeição</label>
                <textarea id="dc21-notes" name="notes" rows="2" placeholder="Ex: Ingerir 30 min antes do treino, mastigar devagar..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-add-meal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Refeição</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Adicionar Alimento -->
        <div id="modal-add-item" data-dialog="modal" class="food-dialog-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-add-item-title" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel food-dialog-panel">
            <h2 id="modal-add-item-title" style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 4px;">Adicionar alimento</h2>
            <p id="add-item-meal-name" style="color: #64748b; font-size: 0.85rem; margin-bottom: 16px;"></p>
            <form method="POST" action="/planos/<?= $planId ?>/itens">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="meal_id" id="add-item-meal-id" value="">
              <div style="margin-bottom: 16px;">
                <div data-food-picker>
                <label class="form-label" for="item-food-search">Buscar alimento</label>
                <input class="form-control" id="item-food-search" data-food-query type="search" maxlength="80" placeholder="Ex.: ovo, arroz integral, banana">
                <p role="status" aria-live="polite">Busque e selecione um alimento para definir a porção.</p>
                <label class="form-label" data-food-results-label for="item-food-select">Resultados da busca</label>
                <select id="item-food-select" name="food_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o alimento...</option>
                  <?php foreach ($foodsCatalog as $fc): ?>
                    <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['name']) ?> (<?= number_format((float)$fc['energy_kcal'], 0, ',', '.') ?> kcal/100g)</option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary" data-food-next hidden>Mais resultados</button>
                <button type="button" class="btn btn-secondary" data-food-retry hidden>Tentar busca novamente</button>
                </div>
              </div>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                  <label for="dc22-amount" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Quantidade *</label>
                  <input id="dc22-amount" type="number" step="0.1" min="0.1" name="amount" required placeholder="Ex: 100" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                </div>
                <div>
                  <label for="dc23-measure_unit" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Unidade de Medida *</label>
                  <select id="dc23-measure_unit" name="measure_unit" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                    <option value="g">g (gramas)</option>
                    <option value="ml">ml (mililitros)</option>
                    <option value="colher de sopa">colher de sopa</option>
                    <option value="colher de sobremesa">colher de sobremesa</option>
                    <option value="unidade média">unidade média</option>
                    <option value="xícara">xícara</option>
                    <option value="fatia">fatia</option>
                  </select>
                </div>
              </div>
              <div style="margin-bottom: 20px;">
                <label for="dc24-notes" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Observação / Orientação do Item</label>
                <input id="dc24-notes" type="text" name="notes" placeholder="Ex: Cozido sem sal, grelhado" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Adicionar ao Plano</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Adicionar Substituição -->
        <div id="modal-add-sub" data-dialog="modal" class="food-dialog-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-add-sub-title" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel food-dialog-panel">
            <h2 id="modal-add-sub-title" style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 4px;">Adicionar substituição</h2>
            <p id="add-sub-item-name" style="color: #64748b; font-size: 0.85rem; margin-bottom: 16px;"></p>
            <form method="POST" action="/planos/<?= $planId ?>/substituicoes">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="item_id" id="add-sub-item-id" value="">
              <div style="margin-bottom: 16px;">
                <div data-food-picker>
                <label class="form-label" for="sub-food-search">Buscar alimento</label>
                <input class="form-control" id="sub-food-search" data-food-query type="search" maxlength="80" placeholder="Ex.: ovo, arroz integral, banana">
                <p role="status" aria-live="polite">Busque e selecione um alimento para definir a porção.</p>
                <label class="form-label" data-food-results-label for="sub-food-select">Resultados da busca</label>
                <select id="sub-food-select" name="food_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o alimento alternativo...</option>
                  <?php foreach ($foodsCatalog as $fc): ?>
                    <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary" data-food-next hidden>Mais resultados</button>
                <button type="button" class="btn btn-secondary" data-food-retry hidden>Tentar busca novamente</button>
                </div>
              </div>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                  <label for="dc25-amount" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Quantidade Equivalente *</label>
                  <input id="dc25-amount" type="number" step="0.1" min="0.1" name="amount" required placeholder="Ex: 80" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                </div>
                <div>
                  <label for="dc26-measure_unit" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Unidade de Medida *</label>
                  <select id="dc26-measure_unit" name="measure_unit" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                    <option value="g">g (gramas)</option>
                    <option value="ml">ml (mililitros)</option>
                    <option value="colher de sopa">colher de sopa</option>
                    <option value="colher de sobremesa">colher de sobremesa</option>
                    <option value="unidade média">unidade média</option>
                    <option value="xícara">xícara</option>
                    <option value="fatia">fatia</option>
                  </select>
                </div>
              </div>
              <div style="margin-bottom: 20px;">
                <label for="dc27-notes" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientação da Substituição</label>
                <input id="dc27-notes" type="text" name="notes" placeholder="Ex: Em dias de treino, consumir cozido" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Opção</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Publicar Versão -->
        <div id="modal-publish-plan" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div class="cn-dialog__panel" style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 12px;">Revisar e Publicar Versão</h2>
            <p style="color: #475569; font-size: 0.9rem; line-height: 1.5; margin-bottom: 16px;">
              Ao publicar, este plano passa a ser a <strong>prescrição ativa</strong> que o paciente vê no aplicativo. Ele vira uma nova versão numerada e não poderá mais ser editado; para mudar depois, crie uma cópia. As versões anteriores continuam no histórico.
            </p>

            <?php if (!empty($preview['allergen_safety']['requires_confirmation'])): ?>
              <div style="background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 0.85rem; border: 1px solid #ffeeba;">
                <strong>⚠️ <?= !empty($preview['allergen_safety']['has_conflict']) ? 'Conflito de Alérgeno' : 'Alérgenos não verificados' ?>:</strong> Para prosseguir, o profissional deve marcar a autorização clínica expressa e justificar abaixo. A justificativa fica registrada na versão e na trilha de auditoria.
              </div>
            <?php endif; ?>

            <div data-testid="publication-allergy-review" style="margin-bottom: var(--space-4);">
              <strong>Alergias: <?= htmlspecialchars($clinicalSummary['allergy_label']) ?></strong>
              <?php if ($clinicalSummary['allergy_pending']): ?>
              <p>Avaliação pendente. Revise a anamnese antes de publicar; este plano não confirma ausência de alergias.</p>
              <a href="/pacientes/<?= (int)$plan['patient_id'] ?>/anamnese">Revisar anamnese</a>
              <?php endif; ?>
              <?php if ($clinicalSummary['source']): ?><p>Origem: anamnese v<?= (int)$clinicalSummary['source']['version'] ?> · <?= htmlspecialchars($clinicalSummary['source']['recorded_at']) ?></p><?php endif; ?>
            </div>
            <form method="POST" action="/planos/<?= $planId ?>/publicar">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
              <?php if (!empty($preview['allergen_safety']['requires_confirmation'])): ?>
                <div style="margin-bottom: 16px;">
                  <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                    <input type="checkbox" name="allow_allergen_override" value="1" required style="margin-top: 3px;">
                    <span>Confirmo e autorizo clinicamente a prescrição dos alimentos assinalados mesmo com os alertas detectados.</span>
                  </label>
                  <label for="allergen-override-reason" style="display: block; font-weight: 600; margin: 10px 0 6px; font-size: 0.85rem;">Justificativa clínica *</label>
                  <textarea id="allergen-override-reason" name="allergen_override_reason" rows="3" required minlength="10" maxlength="1000" placeholder="Ex.: rótulos conferidos com a paciente; produto sem glúten da marca X" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
                </div>
              <?php endif; ?>

              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-publish-plan">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar e Publicar Versão</button>
              </div>
            </form>
          </div>
        </div>

        <script src="/assets/js/food-picker.js" defer></script>
        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">
        function openAddItemModal(mealId, mealName) {
          document.getElementById('add-item-meal-id').value = mealId;
          document.getElementById('add-item-meal-name').innerText = 'Refeição: ' + mealName;
          window.openFoodDialog('modal-add-item');
        }
        function openAddSubModal(itemId, foodName) {
          document.getElementById('add-sub-item-id').value = itemId;
          document.getElementById('add-sub-item-name').innerText = 'Substituição para o item: ' + foodName;
          window.openFoodDialog('modal-add-sub');
        }
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
            $_SESSION['feedback_message'] = "Refeição '{$name}' adicionada com sucesso!";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao adicionar refeição: " . $e->getMessage();
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
            $_SESSION['feedback_message'] = "Refeição removida com sucesso!";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao remover refeição: " . $e->getMessage();
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
            $service->addItem($mealId, $foodId, $amount, $unit, $notes);
            $_SESSION['feedback_message'] = "Alimento adicionado e totais nutricionais recalculados!";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao adicionar alimento: " . $e->getMessage();
        }

        header("Location: /planos/{$planId}");
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
        echo 'Você não tem permissão para editar prescrições. Apenas nutricionistas com CRN cadastrado na clínica podem prescrever.';
    }

    public function updatePlanItem(array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $db = Connection::getInstance();
        if (!$this->canEditPlanItems($db)) {
            http_response_code(403);
            echo 'Você não tem permissão para editar prescrições.';
            return;
        }
        try {
            foreach (['amount', 'measure_unit', 'notes', 'revision'] as $field) {
                if (!isset($_POST[$field]) || !is_string($_POST[$field])) throw new \InvalidArgumentException('Confira os campos do item.');
            }
            if (!is_numeric($_POST['amount']) || $_POST['revision'] === '') throw new \InvalidArgumentException('Informe uma quantidade válida e recarregue o formulário.');
            (new MealPlanService($db))->updateItem((int)($params['itemId'] ?? 0),
                (float)$_POST['amount'], trim($_POST['measure_unit']), trim($_POST['notes']), $_POST['revision'], $planId);
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
            $_SESSION['feedback_message'] = "Alimento removido com sucesso!";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao remover alimento: " . $e->getMessage();
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
            $service->addSubstitution($itemId, $foodId, $amount, $unit, $notes);
            $_SESSION['feedback_message'] = "Opção de substituição cadastrada com sucesso!";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao cadastrar substituição: " . $e->getMessage();
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
            $_SESSION['feedback_message'] = "Substituição removida com sucesso!";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao remover substituição: " . $e->getMessage();
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

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $res = $service->publishPlan($planId, $allowAllergenOverride, true, $overrideReason);
            $_SESSION['feedback_message'] = "Plano alimentar publicado com sucesso como Versão {$res['version']}! As versões anteriores continuam disponíveis no histórico.";
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro na publicação: " . $e->getMessage();
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
            echo 'Você não tem permissão para editar prescrições.';
            return;
        }
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $res = $service->duplicatePlan($planId);
            $_SESSION['feedback_message'] = "Plano duplicado com sucesso em um novo rascunho independente!";
            header("Location: /planos/" . $res['plan_id']);
            exit;
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = "Erro ao duplicar plano: " . $e->getMessage();
            header("Location: /planos/{$planId}");
            exit;
        }
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

        $title = 'Gestão Financeira & Honorários';
        $activeNav = 'financeiro';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

        $feedbackMessage = $_SESSION['finance_success'] ?? $_SESSION['success_message'] ?? null;
        $errorMessage = $_SESSION['finance_error'] ?? $_SESSION['error_message'] ?? null;
        unset($_SESSION['finance_success'], $_SESSION['success_message'], $_SESSION['finance_error'], $_SESSION['error_message']);

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <h1 style="color: var(--brand-navy-900); font-family: var(--font-display, serif); margin-bottom: 4px;">Gestão Financeira</h1>
            <p style="color: var(--neutral-gray-600); margin: 0;">Recebíveis de consultas, controle de honorários, baixas e emissão de recibos.</p>
          </div>
          <div>
            <button type="button" class="btn btn-primary" data-show="modal-novo-recebivel" style="display: inline-flex; align-items: center; gap: 8px;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              <span>+ Novo Lançamento Financeiro</span>
            </button>
          </div>
        </div>

        <?php if (!empty($feedbackMessage)): ?>
          <div class="alert alert-success" style="margin-bottom: var(--space-6); padding: 12px 16px; border-radius: 8px; background: #ecfdf5; border: 1px solid #10b981; color: #065f46; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?= htmlspecialchars($feedbackMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
          <div class="alert alert-danger" style="margin-bottom: var(--space-6); padding: 12px 16px; border-radius: 8px; background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; display: flex; align-items: center; gap: 10px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errorMessage) ?></span>
          </div>
        <?php endif; ?>

        <!-- Cards de Resumo de Indicadores Financeiros -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: var(--space-6);">
          <div class="card" style="padding: 20px; border-left: 4px solid var(--semantic-warning, #f59e0b);">
            <div style="font-size: 0.85rem; font-weight: 600; color: var(--neutral-gray-600); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Total a Receber (Pendente)</div>
            <div class="tabular-nums" style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900);">
              R$ <?= number_format($summary['total_pending_cents'] / 100, 2, ',', '.') ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-top: 4px;">Aguardando baixa de pagamento</div>
          </div>

          <div class="card" style="padding: 20px; border-left: 4px solid var(--semantic-success, #10b981);">
            <div style="font-size: 0.85rem; font-weight: 600; color: var(--neutral-gray-600); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Total Recebido (Quitado)</div>
            <div class="tabular-nums" style="font-size: 1.5rem; font-weight: 700; color: var(--semantic-success, #10b981);">
              R$ <?= number_format($summary['total_paid_cents'] / 100, 2, ',', '.') ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-top: 4px;">Baixas confirmadas no sistema</div>
          </div>

          <div class="card" style="padding: 20px; border-left: 4px solid var(--brand-copper-500, #E67A3F);">
            <div style="font-size: 0.85rem; font-weight: 600; color: var(--neutral-gray-600); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Total de Lançamentos</div>
            <div class="tabular-nums" style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900);">
              <?= (int)$summary['total_count'] ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--neutral-gray-500); margin-top: 4px;">Honorários cadastrados nesta clínica</div>
          </div>
        </div>

        <?php if (empty($receivables)): ?>
          <!-- Estado Vazio Explicativo com Design System Ratificado e CTA Operacional Real (PX-12) -->
          <div class="card" style="padding: 48px 32px; text-align: center; border: 1.5px dashed var(--neutral-gray-300); background: #ffffff; border-radius: var(--radius-lg, 12px);">
            <div style="width: 64px; height: 64px; margin: 0 auto 20px; border-radius: 50%; background: rgba(230, 122, 63, 0.12); display: flex; align-items: center; justify-content: center; color: var(--brand-copper-600);">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="20" height="14" x="2" y="5" rx="2"></rect>
                <line x1="2" x2="22" y1="10" y2="10"></line>
              </svg>
            </div>
            <h2 style="font-size: 1.35rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 8px;">Nenhum recebível financeiro registrado</h2>
            <p style="color: var(--neutral-gray-600); max-width: 640px; margin: 0 auto 24px; line-height: 1.6; font-size: 0.95rem;">
              Os recebíveis financeiros representam honorários de consultas clínicas e planos de acompanhamento nutricional. Eles são gerados a partir dos agendamentos na agenda ou registrados manualmente aqui para qualquer paciente da sua clínica.
            </p>
            <div style="display: flex; justify-content: center; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 32px;">
              <button type="button" class="btn btn-primary" data-show="modal-novo-recebivel">
                + Novo Lançamento Financeiro
              </button>
              <a href="/agenda" class="btn btn-outline" style="text-decoration: none;">
                Ver Agenda de Atendimentos &rarr;
              </a>
            </div>
            
            <!-- Guia informativo / Próximos passos operacionais -->
            <div style="background: var(--neutral-gray-50); border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 20px; max-width: 680px; margin: 0 auto; text-align: left;">
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-weight: 600; color: var(--brand-navy-900); font-size: 0.9rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-600);"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                Como funciona o fluxo financeiro do ClyoNutri:
              </div>
              <ul style="margin: 0; padding-left: 20px; color: var(--neutral-gray-600); font-size: 0.875rem; line-height: 1.6;">
                <li><strong>1. Registro de Honorários:</strong> Cadastre cobranças pontuais ou pacotes vinculando a um paciente cadastrado.</li>
                <li><strong>2. Baixa com Recibo:</strong> Ao receber via PIX, Cartão ou Dinheiro, confirme a baixa para registrar a transação e emitir código de recibo comprobatório.</li>
                <li><strong>3. Integração com Atendimentos:</strong> Vincule o recebível diretamente a uma consulta agendada quando desejar rastreabilidade completa.</li>
                <li><strong>4. Isolamento Seguro:</strong> Todos os dados de faturamento e recibos são estritamente isolados e protegidos por clínica.</li>
              </ul>
            </div>
          </div>
        <?php else: ?>
          <!-- Tabela de Recebíveis com Operações Reais -->
          <div class="card" style="padding: 0; overflow: hidden;">
            <div class="table-responsive">
              <table class="table" style="margin-bottom: 0;">
                <thead style="background: var(--neutral-gray-50);">
                  <tr>
                    <th>Descrição</th>
                    <th>Paciente</th>
                    <th class="tabular-nums">Valor</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th style="text-align: right;">Ações</th>
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
                        <div style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($rc['description']) ?></div>
                        <?php if (!empty($rc['appointment_id'])): ?>
                          <span style="font-size: 0.75rem; color: var(--neutral-gray-500);">Consulta #<?= (int)$rc['appointment_id'] ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($rc['patient_name']) ?></td>
                      <td class="tabular-nums" style="font-weight: 600; color: var(--brand-navy-900);">
                        R$ <?= number_format($rc['amount_cents'] / 100, 2, ',', '.') ?>
                      </td>
                      <td>
                        <span style="<?= $isOverdue ? 'color: var(--semantic-error); font-weight: 600;' : '' ?>">
                          <?= date('d/m/Y', strtotime($rc['due_date'])) ?>
                        </span>
                      </td>
                      <td>
                        <?= LabelMap::receivableStatusBadge($rc['status'], $isOverdue) ?>
                        <?php if ($rc['status'] === 'paid' && !empty($rc['receipt_code'])): ?>
                          <div style="font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 2px;">
                            Recibo: <?= htmlspecialchars($rc['receipt_code']) ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td style="text-align: right;">
                        <?php if (in_array($rc['status'], ['pending', 'partially_paid'], true)): ?>
                          <div style="display: inline-flex; align-items: center; gap: 8px;">
                            <button type="button" class="btn btn-sm btn-outline" style="color: var(--semantic-success); border-color: var(--semantic-success);"
                                    data-call="openPayModal" data-args="[<?= (int)$rc['id'] ?>, <?= (int)$rc['amount_cents'] ?>, <?= htmlspecialchars(json_encode((string)$rc['description'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES) ?>]">
                              Dar Baixa
                            </button>
                            <form method="POST" action="/financeiro/recebiveis/<?= (int)$rc['id'] ?>/cancelar" style="display: inline;" data-confirm="Deseja realmente cancelar este recebível?">
                              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                              <button type="submit" class="btn btn-sm" style="color: var(--neutral-gray-500); background: transparent; border: none; cursor: pointer; text-decoration: underline; font-size: 0.8rem;">
                                Cancelar
                              </button>
                            </form>
                          </div>
                        <?php elseif ($rc['status'] === 'paid'): ?>
                          <span style="font-size: 0.85rem; color: var(--semantic-success); font-weight: 600;">✓ Quitado</span>
                        <?php else: ?>
                          <span style="font-size: 0.85rem; color: var(--neutral-gray-400);">Cancelado</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <!-- Modal: Novo Lançamento Financeiro -->
        <div id="modal-novo-recebivel" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px;">
          <div class="cn-dialog__panel card" style="width: 100%; max-width: 520px; background: #ffffff; border-radius: 12px; box-shadow: var(--shadow-xl, 0 20px 25px -5px rgba(0,0,0,0.1)); padding: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
              <h3 style="margin: 0; font-size: 1.2rem; color: var(--brand-navy-900);">Novo Lançamento Financeiro</h3>
              <button type="button" data-dialog-close data-hide="modal-novo-recebivel" style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--neutral-gray-400);">&times;</button>
            </div>
            
            <form method="POST" action="/financeiro/recebiveis/novo">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <div style="margin-bottom: 16px;">
                <label for="rcv-patient" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Paciente *</label>
                <?php if (empty($patients)): ?>
                  <div style="padding: 10px; background: #fef2f2; border: 1px solid #ef4444; border-radius: 6px; font-size: 0.85rem; color: #991b1b;">
                    Nenhum paciente ativo cadastrado nesta clínica. <a href="/pacientes/novo" style="font-weight: 600; color: #991b1b; text-decoration: underline;">Cadastre um paciente primeiro</a>.
                  </div>
                <?php else: ?>
                  <select id="rcv-patient" name="patient_id" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
                    <option value="">Selecione o paciente...</option>
                    <?php foreach ($patients as $p): ?>
                      <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?><?= !empty($p['email']) ? ' (' . htmlspecialchars($p['email']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>

              <div style="margin-bottom: 16px;">
                <label for="dc28-description" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Descrição do Lançamento *</label>
                <input id="dc28-description" type="text" name="description" required placeholder="Ex: Consulta Nutricional Inicial, Plano Trimestral" style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                  <label for="dc29-amount" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Valor (R$) *</label>
                  <input id="dc29-amount" type="number" step="0.01" min="0.01" name="amount" required placeholder="Ex: 250,00" style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
                </div>
                <div>
                  <label for="dc30-due_date" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Data de Vencimento *</label>
                  <input id="dc30-due_date" type="date" name="due_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
                </div>
              </div>

              <?php if (!empty($appointments)): ?>
                <div style="margin-bottom: 20px;">
                  <label for="dc31-appointment_id" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Vincular à Consulta (Opcional)</label>
                  <select id="dc31-appointment_id" name="appointment_id" style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
                    <option value="">Nenhuma consulta vinculada</option>
                    <?php foreach ($appointments as $a): ?>
                      <option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> - <?= htmlspecialchars($a['patient_name']) ?> (<?= date('d/m/Y H:i', strtotime($a['scheduled_at'])) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>

              <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-novo-recebivel">Cancelar</button>
                <button type="submit" class="btn btn-primary" <?= empty($patients) ? 'disabled' : '' ?>>Salvar Lançamento</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal: Dar Baixa / Registrar Pagamento -->
        <div id="modal-dar-baixa" data-dialog="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px;">
          <div class="cn-dialog__panel card" style="width: 100%; max-width: 480px; background: #ffffff; border-radius: 12px; box-shadow: var(--shadow-xl, 0 20px 25px -5px rgba(0,0,0,0.1)); padding: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
              <h3 style="margin: 0; font-size: 1.2rem; color: var(--brand-navy-900);">Dar Baixa no Recebível</h3>
              <button type="button" data-dialog-close data-hide="modal-dar-baixa" style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--neutral-gray-400);">&times;</button>
            </div>
            
            <form id="form-dar-baixa" method="POST" action="">
              <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

              <div style="margin-bottom: 16px; background: var(--neutral-gray-50); padding: 12px; border-radius: 6px; border: 1px solid var(--neutral-gray-200);">
                <div style="font-size: 0.8rem; color: var(--neutral-gray-500); text-transform: uppercase; font-weight: 600;">Lançamento</div>
                <div id="pay-modal-description" style="font-weight: 600; color: var(--brand-navy-900); font-size: 1rem; margin-top: 2px;"></div>
              </div>

              <div style="margin-bottom: 16px;">
                <label for="dc32-payment_method" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Forma de Pagamento *</label>
                <select id="dc32-payment_method" name="payment_method" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
                  <option value="pix">PIX (Transferência Instantânea)</option>
                  <option value="credit_card">Cartão de Crédito</option>
                  <option value="debit_card">Cartão de Débito</option>
                  <option value="cash">Dinheiro em Espécie</option>
                  <option value="transfer">Transferência Bancária / TED</option>
                </select>
              </div>

              <div style="margin-bottom: 20px;">
                <label for="pay-modal-amount" style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 6px; color: var(--brand-navy-900);">Valor Recebido (R$) *</label>
                <input id="pay-modal-amount" type="number" step="0.01" min="0.01" name="amount" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.9rem;">
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" data-dialog-close class="btn btn-outline" data-hide="modal-dar-baixa">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background: var(--semantic-success, #10b981); border-color: var(--semantic-success, #10b981);">Confirmar Recebimento</button>
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
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function createReceivableAction(): void
    {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        $rawAmount = (string)($_POST['amount'] ?? '');
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $appointmentId = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;

        $cleanAmount = str_replace(['R$', ' ', '.'], '', $rawAmount);
        $cleanAmount = str_replace(',', '.', $cleanAmount);
        $amountCents = (int)round(((float)$cleanAmount) * 100);

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\Finance\FinanceService($db);

        $isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
               || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

        try {
            $created = $service->createReceivable($patientId, $description, $amountCents, $dueDate, $appointmentId);
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'receivable' => $created]);
                exit;
            }
            $_SESSION['finance_success'] = "Lançamento financeiro cadastrado com sucesso!";
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
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $_SESSION['finance_error'] = "Erro ao criar lançamento: " . $e->getMessage();
            header("Location: /financeiro");
            exit;
        }
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
                $cleanAmount = str_replace(['R$', ' ', '.'], '', $rawAmount);
                $cleanAmount = str_replace(',', '.', $cleanAmount);
                $amountCents = (int)round(((float)$cleanAmount) * 100);
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
            $_SESSION['finance_success'] = "Pagamento de R$ {$valorFormatado} registrado com sucesso! Recibo: {$res['receipt_code']}";
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
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $_SESSION['finance_error'] = "Erro ao registrar pagamento: " . $e->getMessage();
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
            $_SESSION['finance_success'] = "Lançamento financeiro cancelado com sucesso.";
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
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $_SESSION['finance_error'] = "Erro ao cancelar recebível: " . $e->getMessage();
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
        <div class="card" role="alert">
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

        $title = 'Visão geral';
        $activeNav = 'dashboard';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $arrowIcon = '<svg class="icon-inline" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';

        ob_start();
        ?>
        <div class="dashboard-intro">
          <h1>Painel Geral</h1>
          <p class="dashboard-intro__subtitle">Visão operacional e prioridades clínicas do dia.</p>
        </div>

        <?php if (!empty($_GET['onboarding']) && $_GET['onboarding'] === 'concluido'): ?>
          <div class="card dashboard-banner dashboard-banner--success" role="status">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div>
              <div class="dashboard-banner__title">Consultório ativado com sucesso!</div>
              <div class="dashboard-banner__text">Suas preferências profissionais e rotinas clínicas foram salvas. Você já pode cadastrar novos pacientes e iniciar consultas.</div>
            </div>
          </div>
        <?php elseif ($onboardingProgress && !$onboardingProgress['is_completed']): ?>
          <div class="card dashboard-banner dashboard-banner--onboarding" data-testid="onboarding-banner">
            <div class="dashboard-banner__body">
              <div class="dashboard-banner__step tabular-nums" aria-hidden="true" data-testid="onboarding-steps"><?= $onboardingDone ?>/<?= self::ONBOARDING_TOTAL_STEPS ?></div>
              <div>
                <div class="dashboard-banner__title">
                  Configuração do seu consultório em andamento (<span data-testid="onboarding-pct"><?= $onboardingPct ?></span>% concluído<span class="sr-only">, <?= $onboardingDone ?> de <?= self::ONBOARDING_TOTAL_STEPS ?> passos</span>)
                </div>
                <div class="dashboard-banner__text">
                  Complete os <?= self::ONBOARDING_TOTAL_STEPS ?> passos para personalizar totalmente suas preferências de atendimento, especialidades e seu primeiro paciente.
                </div>
              </div>
            </div>
            <a href="/onboarding" class="btn btn-accent dashboard-banner__cta">
              Continuar Onboarding
              <?= $arrowIcon ?>
            </a>
          </div>
        <?php endif; ?>

        <!-- Seção: Pacientes e Prioridades que Exigem Atenção -->
        <section class="card attention-panel" aria-labelledby="attention-title" data-testid="attention-panel">
          <div class="card__header">
            <h2 id="attention-title" class="section-title">Pacientes & Prioridades que Exigem Atenção</h2>
            <span class="attention-panel__count" data-testid="attention-count"><?= $attentionTotal ?> item(ns) identificado(s)</span>
          </div>
          <?php if (empty($attentionItems)): ?>
            <div class="attention-panel__empty">
              <svg class="icon-inline icon-success" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"/></svg>
              Nenhuma pendência crítica ou falta sem retorno identificada no momento. Todas as rotinas estão regulares.
            </div>
          <?php else: ?>
            <ul class="attention-list">
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
                  <a href="<?= htmlspecialchars($att['action_url']) ?>" class="btn btn-secondary btn-touch">
                    <?= htmlspecialchars($att['action_label']) ?>
                    <?= $arrowIcon ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($attentionHidden > 0): ?>
              <div class="attention-panel__footer">
                <span>Mostrando <?= count($attentionVisible) ?> de <?= $attentionTotal ?>.</span>
                <a href="/agenda" class="btn btn-outline btn-touch">Ver agenda</a>
                <?php if ($draftPatientsCount > 0): ?>
                  <a href="/planos?status=draft" class="btn btn-outline btn-touch" data-testid="attention-see-all">Ver todos os rascunhos (<?= $draftPlansTotal ?>)</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <?php /* Cards de Métricas Consolidadas (NI2-421: depois das prioridades) */ ?>
        <div class="dashboard-metrics cn-metrics-5" role="region" aria-label="Indicadores da clínica">
          <div class="card metric-card">
            <div class="metric-card__label">CONSULTAS HOJE</div>
            <div class="metric-card__value tabular-nums">
              <span data-testid="appointments-today-count"><?= (int)$todayStats['total_today'] ?></span>
            </div>
            <span class="badge badge-info"><?= (int)$todayStats['confirmed'] ?> confirmada(s) • <?= (int)$todayStats['completed'] ?> realizada(s)</span>
          </div>

          <div class="card metric-card">
            <div class="metric-card__label">CONSULTAS NA SEMANA</div>
            <div class="metric-card__value tabular-nums">
              <span data-testid="appointments-week-count"><?= (int)$weekStats['total'] ?></span>
            </div>
            <span class="badge badge-info"><?= htmlspecialchars((string)$weekStats['start']) ?> a <?= htmlspecialchars((string)$weekStats['end']) ?></span>
            <div class="metric-card__note">Canceladas não incluídas</div>
          </div>

          <div class="card metric-card">
            <div class="metric-card__label">PACIENTES ATIVOS</div>
            <div class="metric-card__value tabular-nums">
              <span data-testid="active-patients-count"><?= count($patients) ?></span>
            </div>
            <a href="/pacientes" class="metric-card__link">Ver pacientes</a>
          </div>

          <div class="card metric-card">
            <div class="metric-card__label">ADESÃO MÉDIA</div>
            <div class="metric-card__value tabular-nums">
              <span data-testid="average-adherence-value"><?= htmlspecialchars($adherenceMetrics['formatted_value']) ?></span>
            </div>
            <?php if ($adherenceMetrics['has_data']): ?>
              <span class="badge badge-success"><?= (int)$adherenceMetrics['responses_count'] ?> resposta(s) • <?= htmlspecialchars($adherenceMetrics['period_label']) ?></span>
              <div class="metric-card__note">
                <?= (int)$adherenceMetrics['responding_patients_count'] ?> de <?= (int)$adherenceMetrics['total_eligible_patients'] ?> paciente(s) elegíveis responderam
              </div>
            <?php else: ?>
              <span class="badge badge-neutral">Sem dados (<?= (int)$adherenceMetrics['period_days'] ?> dias)</span>
              <div class="metric-card__note">
                0 respostas • <?= (int)$adherenceMetrics['total_eligible_patients'] ?> paciente(s) elegíveis
              </div>
            <?php endif; ?>
          </div>

          <div class="card metric-card">
            <div class="metric-card__label">PLANOS A REVISAR</div>
            <div class="metric-card__value metric-card__value--accent tabular-nums">
              <span data-testid="draft-patients-count"><?= $draftPatientsCount ?></span>
            </div>
            <span class="badge badge-<?= $draftPatientsCount > 0 ? 'warning' : 'success' ?>">
              <?= $draftPatientsCount > 0 ? 'Paciente(s) com rascunho' : 'Todos publicados' ?>
            </span>
            <?php if ($draftPlansTotal > 0): ?>
              <div class="metric-card__note"><span data-testid="draft-plans-total"><?= $draftPlansTotal ?></span> plano(s) em rascunho no total · <a href="/planos?status=draft">Ver rascunhos</a></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Grid de Conteúdo Principal -->
        <div class="dashboard-main-grid">
          <section class="card" aria-labelledby="today-title">
            <div class="card__header">
              <h2 id="today-title" class="section-title">Consultas do Dia</h2>
              <a href="/agenda" class="btn btn-secondary btn-touch">Ver agenda completa</a>
            </div>
            <?php if (empty($todayAppointments)): ?>
              <p class="dashboard-empty">Nenhuma consulta agendada para hoje. Horários livres disponíveis.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table">
                  <thead>
                    <tr>
                      <th scope="col">Horário</th>
                      <th scope="col">Paciente</th>
                      <th scope="col">Tipo</th>
                      <th scope="col">Status</th>
                      <th scope="col" class="text-right">Ação</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($todayAppointments as $app): ?>
                      <tr>
                        <td class="tabular-nums dashboard-table__time"><?= date('H:i', strtotime($app['scheduled_at'])) ?></td>
                        <td class="dashboard-table__patient">
                          <a href="/pacientes/<?= (int)$app['patient_id'] ?>">
                            <?= htmlspecialchars($app['patient_name']) ?>
                          </a>
                        </td>
                        <td><?= htmlspecialchars(LabelMap::appointmentType($app['type'])) ?></td>
                        <td>
                          <?= LabelMap::appointmentStatusBadge($app['status']) ?>
                        </td>
                        <td class="text-right">
                          <?php if ($app['status'] === 'completed'): ?>
                            <a href="/pacientes/<?= (int)$app['patient_id'] ?>" class="btn btn-secondary btn-touch btn-touch--compact">Prontuário</a>
                          <?php else: ?>
                            <a href="/consulta/<?= (int)$app['id'] ?>" class="btn btn-primary btn-touch btn-touch--compact">Atender</a>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="card" aria-labelledby="quick-actions-title">
            <div class="card__header">
              <h2 id="quick-actions-title" class="section-title">Ações Rápidas</h2>
            </div>
            <div class="quick-actions">
              <a href="/pacientes?novo=1" class="btn btn-primary quick-actions__item" data-testid="quick-new-patient">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Novo Paciente</span>
              </a>
              <a href="/planos" class="btn btn-accent quick-actions__item">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                <span>Criar Plano Alimentar</span>
              </a>
              <a href="/alimentos" class="btn btn-secondary quick-actions__item">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <span>Consultar Tabela TACO</span>
              </a>
            </div>
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
        unset($_SESSION['patient_created_id']);
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
          <div style="background-color: var(--semantic-success-light); color: var(--semantic-success); padding: var(--space-3) var(--space-4); border-radius: var(--radius-input); margin-bottom: var(--space-4); border: 1px solid rgba(24, 121, 78, 0.2);">
            ✓ <?= $_GET['success'] === 'atualizado' ? 'Dados do paciente atualizados com sucesso!' : 'Paciente cadastrado. Próximo passo: abra o perfil para agendar ou iniciar o atendimento.' ?>
            <?php if ($createdId): ?><a class="btn btn-secondary" href="/pacientes/<?= (int)$createdId ?>/anamnese">Iniciar anamnese</a><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($patientError): ?><div role="alert" class="card"><?= htmlspecialchars($patientError) ?>
        <?php if ($duplicateId): ?><a href="/pacientes/<?= (int)$duplicateId ?>/editar">Conferir cadastro existente</a><?php endif; ?></div><?php endif; ?>
        <?php if ($listError): ?><div role="alert" class="card"><?= htmlspecialchars($listError) ?> <a href="/pacientes">Tentar novamente</a></div><?php endif; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
          <div>
            <h1>Gestão de Pacientes</h1>
            <p style="color: var(--neutral-gray-500);">Cadastro, histórico de acompanhamento e prontuários.</p>
          </div>
          <button type="button" class="btn btn-primary" aria-haspopup="dialog" aria-controls="patientModal" data-call="openPatientModal">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Cadastrar Paciente</span>
          </button>
        </div>

        <section class="card" aria-labelledby="patient-birthdays-heading">
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
            <h2 id="patient-birthdays-heading" style="font-size: 1.25rem; margin: 0;">Aniversariantes do mês · <?= $monthNames[$month] ?></h2>
            <span class="badge badge-info"><?= count($birthdays) ?> paciente<?= count($birthdays) === 1 ? '' : 's' ?></span>
          </div>
          <?php if (!$birthdays): ?>
            <p style="color: var(--neutral-gray-500); margin: 0;">Nenhum paciente com aniversário neste mês. A lista considera as datas de nascimento cadastradas.</p>
          <?php else: ?>
            <ul style="list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 12px;">
              <?php foreach ($birthdays as $birthday): $isToday = substr($birthday['birth_date'], 5) === substr($today, 5); ?>
              <li data-patient-birthday="<?= (int)$birthday['id'] ?>" style="border: 1px solid var(--neutral-gray-300); border-radius: 8px; padding: 16px;">
                <div style="font-weight: 700; color: var(--brand-navy-900); margin-bottom: 8px;"><?= htmlspecialchars($birthday['name']) ?></div>
                <div style="margin-bottom: 12px;">
                  <span class="badge badge-<?= $isToday ? 'success' : 'info' ?>"><?= substr($birthday['birth_date'], 8, 2) ?>/<?= $month ?><?= $isToday ? ' · Hoje!' : '' ?></span>
                </div>
                <a class="btn btn-secondary" href="/pacientes/<?= (int)$birthday['id'] ?>" aria-label="Ver perfil de <?= htmlspecialchars($birthday['name'], ENT_QUOTES) ?>">Ver perfil</a>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <div class="card">
          <form method="get" action="/pacientes" style="margin-bottom: var(--space-4);">
            <label for="patient-search" class="form-label">Buscar paciente por nome, celular ou e-mail</label>
            <div style="display: flex; gap: var(--space-2); align-items: center; flex-wrap: wrap;">
              <input type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" id="patient-search" class="form-control" placeholder="Digite o nome, celular ou e-mail" autocomplete="off" aria-controls="patient-list" style="flex: 1; min-width: 180px;">
              <label for="patient-status">Status</label>
              <select id="patient-status" name="status" class="form-control" style="width:auto">
                <?php foreach ([''=>'Todos','active'=>'Ativos','inactive'=>'Inativos','anonymized'=>'Anonimizados'] as $value=>$label): ?>
                <option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary">Buscar</button>
              <a href="/pacientes" class="btn btn-secondary">Limpar</a>
            </div>
            <p id="patient-search-count" role="status" aria-live="polite" style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-top: var(--space-2);"><?= (int)$listing['total'] ?> paciente(s) encontrado(s).</p>
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
                  <tr id="patient-list-empty"><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);"><?= $query !== '' || $statusFilter !== '' ? 'Nenhum paciente encontrado para estes filtros.' : 'Nenhum paciente cadastrado nesta clínica.' ?></td></tr>
                <?php else: ?>
                  <?php foreach ($patients as $p): ?>
                    <tr data-patient-row>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($p['name']) ?></td>
                      <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($p['phone'] ?? '—') ?></td>
                      <td><span class="badge <?= $p['status'] === 'active' ? 'badge-success' : 'badge-secondary' ?>"><?= ['active'=>'Ativo','inactive'=>'Inativo','anonymized'=>'Anonimizado'][$p['status']] ?? 'Não informado' ?></span></td>
                      <td>
                        <a href="/pacientes/<?= $p['id'] ?>" class="btn btn-secondary" style="min-height: 32px; padding: 0 var(--space-3); font-size: 0.8rem;">Ver perfil</a>
                        <a href="/pacientes/<?= $p['id'] ?>/editar" class="btn btn-secondary" style="min-height: 32px; font-size: 0.8rem;">Editar</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                <tr id="patient-search-empty" hidden><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhum paciente encontrado para esta busca.</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <nav aria-label="Paginação de pacientes" style="display:flex;gap:12px;margin:16px 0;align-items:center">
          <?php if ($listing['page'] > 1): ?><a class="btn btn-secondary" href="/pacientes?<?= htmlspecialchars(http_build_query(['q'=>$query,'status'=>$statusFilter,'page'=>$listing['page']-1])) ?>">Anterior</a><?php endif; ?>
          <span>Página <?= $listing['page'] ?> de <?= $listing['pages'] ?></span>
          <?php if ($listing['page'] < $listing['pages']): ?><a class="btn btn-secondary" href="/pacientes?<?= htmlspecialchars(http_build_query(['q'=>$query,'status'=>$statusFilter,'page'=>$listing['page']+1])) ?>">Próxima</a><?php endif; ?>
        </nav>
        <script nonce="<?= \ClyoNutri\Core\Http\SecurityHeaders::nonce() ?>">document.querySelector('form[action="/pacientes"][method="get"]').addEventListener('submit', function(){ this.querySelector('button[type="submit"]').disabled=true; this.querySelector('button[type="submit"]').textContent='Buscando…'; });</script>

        <!-- Modal Cadastrar Paciente (Design System Premium) -->
        <div id="patientModal" data-dialog="modal" class="modal-overlay" data-call-self="closePatientModal">
          <div class="cn-dialog__panel modal-dialog">
            <div class="modal-header">
              <h3>Novo Paciente</h3>
              <button type="button" data-dialog-close class="modal-close" data-call="closePatientModal" aria-label="Fechar modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <form method="POST" action="/pacientes"<?= $patientError ? ' data-start-dirty' : '' ?>>
              <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
              <input type="hidden" name="_submission_id" value="<?= $submissionId ?>">
              <div class="modal-body">
                <div class="form-group">
                  <label class="form-label" for="p_name">Nome completo *</label>
                  <input type="text" id="p_name" name="name" class="form-control" placeholder="Ex: Juliana Mendes" required autofocus>
                </div>

                <div class="form-group">
                  <label class="form-label" for="p_email">E-mail</label>
                  <input type="email" id="p_email" name="email" class="form-control" placeholder="paciente@exemplo.com.br">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4);">
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
                  <label class="form-label" for="p_gender">Gênero / Sexo biológico (opcional)</label>
                  <select id="p_gender" name="gender" class="form-control" aria-describedby="p_gender_help">
                    <option value="">Selecione...</option>
                    <option value="Feminino">Feminino</option>
                    <option value="Masculino">Masculino</option>
                    <option value="Outro">Outro / Não informado</option>
                  </select>
                  <small id="p_gender_help" style="display: block; font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 4px;">No ClyoNutri, hoje é usado só para escolher os pontos de corte da relação cintura-quadril (OMS) na avaliação antropométrica; vazio ou &quot;Outro&quot; deixam essa classificação sem faixa. O sistema não deduz este dado de nenhum outro.</small>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label" for="p_notes">Observações iniciais</label>
                  <textarea id="p_notes" name="notes" class="form-control" style="min-height: 70px;" placeholder="Objetivo clínico, alergias ou queixa principal..."></textarea>
                </div>
                <?php require __DIR__ . '/../../../views/patients/consent-choice.php'; ?>
              </div>
              <div class="modal-footer">
                <button data-dialog-close type="button" class="btn btn-secondary" data-call="closePatientModal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Paciente</button>
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

        $title = 'Base de Alimentos';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div class="foods-page-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 4px;">Tabela de Alimentos (TACO)</h1>
            <p style="color: var(--neutral-gray-600); margin: 0; font-size: 0.95rem;">Referência analítica por 100g da Tabela Brasileira de Composição de Alimentos com cálculo proporcional por medida caseira.</p>
          </div>
        </div>

        <form method="GET" action="/alimentos" class="foods-filters" data-busy-text="Buscando…" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: var(--space-4);">
          <div style="flex: 1 1 200px; min-width: 160px;">
            <label for="food-search-input" class="form-label" style="display:block; font-size: 0.8rem; margin-bottom: 4px;">Buscar</label>
            <input type="text" id="food-search-input" name="q" value="<?= htmlspecialchars($listing['q']) ?>" placeholder="Alimento ou categoria…" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
          </div>
          <div style="flex: 0 1 220px; min-width: 140px;">
            <label for="food-category-filter" class="form-label" style="display:block; font-size: 0.8rem; margin-bottom: 4px;">Grupo TACO</label>
            <select id="food-category-filter" name="category" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              <option value="">Todos os grupos</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $listing['category'] === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex: 0 1 160px; min-width: 120px;">
            <label for="food-source-filter" class="form-label" style="display:block; font-size: 0.8rem; margin-bottom: 4px;">Fonte</label>
            <select id="food-source-filter" name="source" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              <option value="" <?= $listing['source'] === '' ? 'selected' : '' ?>>Todas</option>
              <option value="TACO" <?= $listing['source'] === 'TACO' ? 'selected' : '' ?>>TACO</option>
              <option value="clinic" <?= $listing['source'] === 'clinic' ? 'selected' : '' ?>>Minha clínica</option>
            </select>
          </div>
          <div style="flex: 0 1 120px; min-width: 100px;">
            <label for="food-per-page" class="form-label" style="display:block; font-size: 0.8rem; margin-bottom: 4px;">Por página</label>
            <select id="food-per-page" name="per_page" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              <?php foreach (FoodCatalogService::PER_PAGE_ALLOWED as $n): ?>
                <option value="<?= $n ?>" <?= (int)$listing['per_page'] === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary" style="padding: 8px 16px; min-height: 44px;">Buscar</button>
            <?php if ($listing['q'] !== '' || $listing['category'] !== '' || $listing['source'] !== '' || (int)$listing['per_page'] !== 50): ?>
              <a href="/alimentos" class="btn btn-outline" style="padding: 8px 12px; text-decoration: none; min-height: 44px; display: inline-flex; align-items: center;">Limpar</a>
            <?php endif; ?>
          </div>
        </form>

        <!-- Banner Explicativo de Integridade Clínica (PX-16) -->
        <div class="card" style="padding: 16px 20px; background: rgba(15, 39, 71, 0.03); border: 1px solid var(--neutral-gray-200); border-radius: 8px; margin-bottom: 24px;">
          <div style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--brand-navy-900); font-size: 0.9rem; margin-bottom: 4px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-copper-600);" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            Diferença entre Referência Padrão (100g) e Medida Caseira
          </div>
          <p style="margin: 0; color: var(--neutral-gray-600); font-size: 0.875rem; line-height: 1.5;">
            Os dados laboratoriais oficiais (TACO) são mensurados na <strong>base fixa de 100g</strong>. Na coluna <em>Medida Caseira</em>, a quantidade em gramas/ml e a energia resultante são calculadas proporcionalmente para orientar prescrições clínicas seguras sem confusão métrica.
          </p>
        </div>

        <p class="foods-result-summary" style="margin: 0 0 12px; color: var(--neutral-gray-600); font-size: 0.9rem;" aria-live="polite">
          <?php if ($listing['total'] === 0): ?>
            Nenhum alimento encontrado.
          <?php else: ?>
            Mostrando <?= (int)$listing['from'] ?>–<?= (int)$listing['to'] ?> de <?= (int)$listing['total'] ?>
          <?php endif; ?>
        </p>

        <div class="card" style="padding: 0; overflow: hidden;">
          <div class="table-responsive">
            <table class="table" style="margin-bottom: 0;">
              <thead style="background: var(--neutral-gray-50);">
                <tr>
                  <th scope="col" style="min-width: 200px;">Alimento</th>
                  <th scope="col">Categoria</th>
                  <th scope="col">Medida Caseira</th>
                  <th scope="col" class="tabular-nums" style="color: var(--brand-navy-900);">Energia na Porção</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Energia (100g)</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Proteína (100g)</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Carboidratos (100g)</th>
                  <th scope="col" class="tabular-nums" title="Referência Oficial TACO padronizada por 100g">Lipídios (100g)</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($foods)): ?>
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 48px 16px; color: var(--neutral-gray-500);">
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
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($f['name']) ?><?php if (!empty($f['taco_code'])): ?>
                        <span style="display: block; font-weight: 400; font-size: 0.75rem; color: var(--neutral-gray-500);">TACO nº <?= (int)$f['taco_code'] ?><?= !empty($f['nutrient_flags']) ? ' · ' . htmlspecialchars(str_replace(['protein_g', 'lipid_g', 'carbohydrate_g', 'fiber_g', 'energy_kcal', 'em_branco', ';', '='], ['proteína', 'lipídios', 'carboidratos', 'fibra', 'energia', 'não analisado', ', ', ': '], (string)$f['nutrient_flags'])) : '' ?></span>
                      <?php endif; ?></td>
                      <td><span class="badge badge-info"><?= htmlspecialchars($f['category'] ?? 'Geral') ?></span></td>
                      <td>
                        <span style="font-weight: 500; color: var(--neutral-gray-800);">
                          <?= $servingG < 100 || $servingUnit !== 'g' ? '1 ' . $servingUnit . ' (' . $servingG . 'g)' : '100g' ?>
                        </span>
                      </td>
                      <td class="tabular-nums" style="font-weight: 700; color: var(--brand-navy-900);">
                        <?= number_format($portionKcal, 1, ',', '.') ?> kcal
                      </td>
                      <td class="tabular-nums" style="color: var(--neutral-gray-600);"><?= number_format($energy100, 1, ',', '.') ?> kcal</td>
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
        <nav class="foods-pagination" aria-label="Paginação da base de alimentos" style="display:flex; flex-wrap:wrap; gap:8px; margin:16px 0; align-items:center; justify-content:space-between;">
          <span style="color: var(--neutral-gray-600); font-size: 0.9rem;">Página <?= (int)$listing['page'] ?> de <?= (int)$listing['pages'] ?></span>
          <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
            <?php if ($listing['page'] > 1): ?>
              <a class="btn btn-secondary" style="min-height:44px; padding:0 14px; display:inline-flex; align-items:center;" href="/alimentos?<?= htmlspecialchars($qs(['page' => 1])) ?>">Primeira</a>
              <a class="btn btn-secondary" style="min-height:44px; padding:0 14px; display:inline-flex; align-items:center;" href="/alimentos?<?= htmlspecialchars($qs(['page' => $listing['page'] - 1])) ?>" rel="prev">Anterior</a>
            <?php endif; ?>
            <?php
              $window = 2;
              $startP = max(1, $listing['page'] - $window);
              $endP = min($listing['pages'], $listing['page'] + $window);
              for ($p = $startP; $p <= $endP; $p++):
                $isCurrent = $p === (int)$listing['page'];
            ?>
              <?php if ($isCurrent): ?>
                <span class="btn btn-primary" style="min-height:44px; min-width:44px; padding:0 12px; display:inline-flex; align-items:center; justify-content:center; pointer-events:none;" aria-current="page"><?= $p ?></span>
              <?php else: ?>
                <a class="btn btn-secondary" style="min-height:44px; min-width:44px; padding:0 12px; display:inline-flex; align-items:center; justify-content:center;" href="/alimentos?<?= htmlspecialchars($qs(['page' => $p])) ?>"><?= $p ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($listing['page'] < $listing['pages']): ?>
              <a class="btn btn-secondary" style="min-height:44px; padding:0 14px; display:inline-flex; align-items:center;" href="/alimentos?<?= htmlspecialchars($qs(['page' => $listing['page'] + 1])) ?>" rel="next">Próxima</a>
              <a class="btn btn-secondary" style="min-height:44px; padding:0 14px; display:inline-flex; align-items:center;" href="/alimentos?<?= htmlspecialchars($qs(['page' => $listing['pages']])) ?>">Última</a>
            <?php endif; ?>
          </div>
        </nav>
        <?php endif; ?>

        <p class="taco-attribution" style="margin: 12px 0 0; color: var(--neutral-gray-500); font-size: 0.8rem; line-height: 1.5;">
          Fonte dos alimentos marcados com nº TACO: NEPA-UNICAMP. <cite>Tabela Brasileira de Composição de Alimentos – TACO</cite>, 4ª ed. revisada e ampliada. Campinas: NEPA-UNICAMP, 2011. Valores por 100 g de parte comestível.
          Tr (traço) e NA (não aplicável) aparecem como 0; "*" (análise em reavaliação na TACO) e "não analisado" também aparecem como 0 e ficam indicados abaixo do nome.
        </p>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

}
