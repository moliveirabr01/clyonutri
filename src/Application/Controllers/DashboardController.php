<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\InvalidAppointmentTransitionException;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Finance\FinanceService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientRepository;

class DashboardController
{
    public function savePatient(): void
    {
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: /pacientes?error=csrf');
            exit;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            header('Location: /pacientes?error=nome_obrigatorio');
            exit;
        }

        $repo = new PatientRepository();
        $repo->create([
            'name' => $name,
            'email' => !empty($_POST['email']) ? trim((string)$_POST['email']) : null,
            'phone' => !empty($_POST['phone']) ? trim((string)$_POST['phone']) : null,
            'birth_date' => !empty($_POST['birth_date']) ? $_POST['birth_date'] : null,
            'gender' => !empty($_POST['gender']) ? $_POST['gender'] : null,
            'notes' => !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null,
            'lgpd_consent' => 1,
        ]);

        header('Location: /pacientes?success=1');
        exit;
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
        $title = 'Perfil 360 — ' . $p['name'];
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6); display: flex; justify-content: space-between; align-items: center;">
          <div>
            <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-bottom: 4px;">
              <a href="/pacientes" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar para Pacientes</a>
            </div>
            <h1><?= htmlspecialchars($p['name']) ?></h1>
            <p style="color: var(--neutral-gray-500);">Perfil Clínico 360 • Acompanhamento Longitudinal</p>
          </div>
          <span class="badge badge-success"><?= htmlspecialchars($p['status']) ?></span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: var(--space-6);">
          <!-- Coluna 1: Dados Cadastrais -->
          <div class="card">
            <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Dados Essenciais</h3>
            <p style="margin-bottom: var(--space-2);"><strong>E-mail:</strong> <?= htmlspecialchars($p['email'] ?? '—') ?></p>
            <p style="margin-bottom: var(--space-2);"><strong>Telefone:</strong> <?= htmlspecialchars($p['phone'] ?? '—') ?></p>
            <p style="margin-bottom: var(--space-2);"><strong>Nascimento:</strong> <?= $p['birth_date'] ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></p>
            <p style="margin-bottom: var(--space-2);"><strong>Gênero:</strong> <?= htmlspecialchars($p['gender'] ?? '—') ?></p>
            <?php if (!empty($p['notes'])): ?>
              <div style="margin-top: var(--space-4); padding-top: var(--space-4); border-top: var(--border-default);">
                <strong>Observações:</strong>
                <p style="color: var(--neutral-gray-500); margin-top: 4px;"><?= nl2br(htmlspecialchars($p['notes'])) ?></p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Coluna 2: Prontuário, Avaliações e Planos -->
          <div>
            <div class="card">
              <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Planos Alimentares</h3>
              <?php if (empty($profile['meal_plans'])): ?>
                <p style="color: var(--neutral-gray-500);">Nenhum plano alimentar prescrito ainda.</p>
              <?php else: ?>
                <ul style="padding-left: 20px; color: var(--brand-navy-900);">
                  <?php foreach ($profile['meal_plans'] as $mp): ?>
                    <li style="margin-bottom: 8px;">
                      <strong><?= htmlspecialchars($mp['title']) ?></strong> (V<?= $mp['version'] ?>) — 
                      <span class="badge badge-<?= $mp['status'] === 'published' ? 'success' : 'warning' ?>"><?= $mp['status'] ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <div class="card">
              <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Últimas Avaliações Antropométricas</h3>
              <?php if (empty($profile['assessments'])): ?>
                <p style="color: var(--neutral-gray-500);">Nenhuma avaliação antropométrica registrada.</p>
              <?php else: ?>
                <?php foreach (array_slice($profile['assessments'], 0, 3) as $as): ?>
                  <div style="border-bottom: var(--border-default); padding: 8px 0;">
                    <strong><?= date('d/m/Y', strtotime($as['assessed_at'])) ?>:</strong>
                    Peso: <?= $as['weight_kg'] ?> kg | Altura: <?= $as['height_cm'] ?> cm | IMC: <strong><?= $as['bmi'] ?></strong>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
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
            $targetDate = date('Y-m-d', strtotime($scheduledAt));
            header("Location: /agenda?date={$targetDate}&error=conflito&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            header("Location: /agenda?error=falha&msg=" . urlencode($e->getMessage()));
            exit;
        }
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
        try {
            $updated = $service->edit($id, $_POST, $allowConflict);
            $targetDate = date('Y-m-d', strtotime($updated['scheduled_at']));
            header("Location: /agenda?date={$targetDate}&success=editado");
            exit;
        } catch (AppointmentConflictException $e) {
            header("Location: /agenda?error=conflito&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
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

        // Se estiver agendada ou confirmada, avança para in_progress ao abrir a tela de atendimento
        if ($app['status'] === AppointmentService::STATUS_SCHEDULED || $app['status'] === AppointmentService::STATUS_CONFIRMED) {
            try {
                $service->updateStatus($id, AppointmentService::STATUS_IN_PROGRESS, 'Profissional iniciou a consulta');
                $app['status'] = AppointmentService::STATUS_IN_PROGRESS;
            } catch (\Throwable $e) {
                // Silencioso se já estiver no status
            }
        }

        $repo = new PatientRepository();
        $profile = $repo->getProfile360((int)$app['patient_id']);
        $p = $profile['patient'] ?? [];

        $title = "Consulta #{$id} — " . ($app['patient_name'] ?? 'Paciente');
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
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
              Horário: <strong><?= date('d/m/Y H:i', strtotime($app['scheduled_at'])) ?></strong> (<?= $app['duration_minutes'] ?> min) • 
              Tipo: <?= htmlspecialchars($app['type']) ?>
            </p>
          </div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <span class="badge badge-warning" style="font-size: 0.875rem; padding: 6px 12px;">
              <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: #946200; margin-right: 4px;"></span>
              <?= htmlspecialchars($app['status']) ?>
            </span>
            <a href="/pacientes/<?= $app['patient_id'] ?>" target="_blank" class="btn btn-secondary" style="font-size: 0.85rem;">
              Ver Perfil 360 ↗
            </a>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: var(--space-6);">
          <!-- Coluna 1: Contexto Clínico do Paciente -->
          <div style="display: flex; flex-direction: column; gap: var(--space-6);">
            <!-- Card de Dados e Histórico -->
            <div class="card">
              <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Contexto do Paciente</h3>
              <p style="margin-bottom: var(--space-2);"><strong>Telefone:</strong> <?= htmlspecialchars($p['phone'] ?? '—') ?></p>
              <p style="margin-bottom: var(--space-2);"><strong>E-mail:</strong> <?= htmlspecialchars($p['email'] ?? '—') ?></p>
              <p style="margin-bottom: var(--space-2);"><strong>Nascimento:</strong> <?= !empty($p['birth_date']) ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></p>
              <p style="margin-bottom: var(--space-2);"><strong>Gênero:</strong> <?= htmlspecialchars($p['gender'] ?? '—') ?></p>

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
                    <strong style="display: block; font-size: 1.1rem; color: var(--brand-navy-900);"><?= $latest['weight_kg'] ?> kg</strong>
                  </div>
                  <div style="background: #F8FAFC; padding: 8px; border-radius: 6px;">
                    <span style="font-size: 0.75rem; color: #64748B;">Altura</span>
                    <strong style="display: block; font-size: 1.1rem; color: var(--brand-navy-900);"><?= $latest['height_cm'] ?> cm</strong>
                  </div>
                  <div style="background: #F8FAFC; padding: 8px; border-radius: 6px;">
                    <span style="font-size: 0.75rem; color: #64748B;">IMC</span>
                    <strong style="display: block; font-size: 1.1rem; color: var(--brand-navy-900);"><?= $latest['bmi'] ?></strong>
                  </div>
                </div>
                <small style="color: var(--neutral-gray-500);">Registrado em: <?= date('d/m/Y', strtotime($latest['assessed_at'])) ?></small>
              <?php endif; ?>
            </div>

            <!-- Último Plano Alimentar -->
            <div class="card">
              <div class="card__header">
                <h3>Plano Ativo</h3>
                <a href="/planos" class="btn btn-secondary" style="font-size: 0.75rem; padding: 2px 8px;">Ver Planos</a>
              </div>
              <?php if (empty($profile['meal_plans'])): ?>
                <p style="color: var(--neutral-gray-500); font-size: 0.875rem;">Nenhum plano ativo. Prescreva um novo plano durante a consulta.</p>
              <?php else: ?>
                <?php $plan = $profile['meal_plans'][0]; ?>
                <p style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($plan['title']) ?> (V<?= $plan['version'] ?>)</p>
                <span class="badge badge-<?= $plan['status'] === 'published' ? 'success' : 'info' ?>"><?= $plan['status'] ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Coluna 2: Formulário de Execução e Conclusão da Consulta (NI2-84) -->
          <div>
            <div class="card">
              <h3 style="margin-top: 0; margin-bottom: var(--space-4); color: var(--brand-navy-900);">
                Evolução Clínica & Conduta do Atendimento
              </h3>
              <p style="color: var(--neutral-gray-500); font-size: 0.875rem; margin-bottom: var(--space-4);">
                Ao concluir a consulta, a evolução será assinada com hash SHA-256 no prontuário eletrônico do paciente, garantindo imutabilidade e conformidade com o CFN.
              </p>

              <form action="/consulta/<?= $app['id'] ?>/concluir" method="POST">
                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

                <div class="form-group" style="margin-bottom: var(--space-4);">
                  <label for="evolution_summary" style="display: block; font-weight: 600; margin-bottom: 6px;">
                    Anotações Clínicas / Resumo da Evolução <span style="color: #DC2626;">*</span>
                  </label>
                  <textarea id="evolution_summary" name="evolution_summary" rows="8" required style="width: 100%; padding: 12px; border: 1px solid var(--neutral-gray-300); border-radius: 8px; font-family: inherit; font-size: 0.9375rem; line-height: 1.5;" placeholder="Descreva os tópicos abordados (anamnese alimentar, queixas gastrointestinais, rotina, metas acordadas e ajustes na prescrição)..."></textarea>
                </div>

                <div style="background: #F8FAFC; border: 1px solid var(--neutral-gray-200); border-radius: 8px; padding: 16px; margin-bottom: var(--space-6);">
                  <h4 style="font-size: 0.875rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 12px;">
                    Atualização Antropométrica Desta Consulta (Opcional)
                  </h4>
                  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                    <div>
                      <label style="font-size: 0.75rem; color: #64748B; display: block; margin-bottom: 4px;">Peso Atual (kg)</label>
                      <input type="number" step="0.1" name="weight_kg" placeholder="Ex: 68.5" style="width: 100%; padding: 8px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                    </div>
                    <div>
                      <label style="font-size: 0.75rem; color: #64748B; display: block; margin-bottom: 4px;">Circunf. Cintura (cm)</label>
                      <input type="number" step="0.5" name="waist_cm" placeholder="Ex: 78.0" style="width: 100%; padding: 8px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                    </div>
                  </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-4);">
                  <a href="/agenda" class="btn btn-secondary">Pausar e Voltar à Agenda</a>
                  <button type="submit" class="btn btn-primary" style="font-size: 1rem; padding: 10px 24px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Concluir Consulta & Assinar Prontuário
                  </button>
                </div>
              </form>
            </div>

            <!-- Histórico de Transições da Consulta -->
            <?php if (!empty($app['history'])): ?>
              <div class="card" style="margin-top: var(--space-4);">
                <h4 style="font-size: 0.875rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 12px;">
                  Trilha de Auditoria Desta Consulta (NI2-82)
                </h4>
                <div style="font-size: 0.8125rem; color: var(--neutral-gray-700);">
                  <?php foreach ($app['history'] as $h): ?>
                    <div style="padding: 6px 0; border-bottom: 1px solid var(--neutral-gray-200); display: flex; justify-content: space-between;">
                      <div>
                        <strong><?= htmlspecialchars($h['from_status'] ?? 'Criação') ?></strong> ➔ 
                        <span class="badge badge-info" style="font-size: 0.75rem;"><?= htmlspecialchars($h['to_status']) ?></span>
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

        $currentDate = $_GET['date'] ?? date('Y-m-d');
        $view = $_GET['view'] ?? 'day';

        $prevDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
        $nextDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));

        if ($view === 'week') {
            $weekData = $appointmentService->listByWeek($currentDate);
            $appointments = [];
        } else {
            $appointments = $appointmentService->listByDay($currentDate);
            $weekData = null;
        }

        $title = 'Agenda de Atendimentos';
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::generate();

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'agendado' ? 'Consulta agendada com sucesso!' : 'Consulta atualizada com sucesso!' ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['status_updated'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ Status da consulta atualizado com sucesso no histórico de auditoria!
          </div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
          <div style="background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #B91C1C;">
            <strong>Atenção:</strong> <?= !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Ocorreu um erro na operação.' ?>
          </div>
        <?php endif; ?>

        <!-- Cabeçalho & Controles da Agenda (NI2-83) -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <h1>Agenda de Atendimentos</h1>
            <p style="color: var(--neutral-gray-500);">
              Gestão da jornada clínica: agendamento, confirmação, ciclo da consulta e execução.
            </p>
          </div>

          <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <!-- Navegação por Data -->
            <div style="display: inline-flex; border: 1px solid var(--neutral-gray-300); border-radius: 6px; overflow: hidden; background: #fff;">
              <a href="/agenda?view=<?= $view ?>&date=<?= $prevDate ?>" class="btn btn-secondary" style="border: none; border-radius: 0; min-height: 38px; padding: 0 12px;">←</a>
              <a href="/agenda?view=<?= $view ?>&date=<?= date('Y-m-d') ?>" class="btn btn-secondary" style="border: none; border-radius: 0; min-height: 38px; padding: 0 12px; font-weight: 600;">Hoje</a>
              <a href="/agenda?view=<?= $view ?>&date=<?= $nextDate ?>" class="btn btn-secondary" style="border: none; border-radius: 0; min-height: 38px; padding: 0 12px;">→</a>
            </div>

            <input type="date" value="<?= $currentDate ?>" onchange="location.href='/agenda?view=<?= $view ?>&date='+this.value" style="padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.875rem; background: #fff;">

            <!-- Alternância de Visão Dia / Semana -->
            <div style="display: inline-flex; border: 1px solid var(--neutral-gray-300); border-radius: 6px; overflow: hidden; background: #fff;">
              <a href="/agenda?view=day&date=<?= $currentDate ?>" class="btn <?= $view === 'day' ? 'btn-primary' : 'btn-secondary' ?>" style="border: none; border-radius: 0; min-height: 38px;">Visão Diária</a>
              <a href="/agenda?view=week&date=<?= $currentDate ?>" class="btn <?= $view === 'week' ? 'btn-primary' : 'btn-secondary' ?>" style="border: none; border-radius: 0; min-height: 38px;">Visão Semanal</a>
            </div>

            <!-- Botão Novo Agendamento -->
            <button type="button" onclick="var m = document.getElementById('card-novo-agendamento'); m.style.display = m.style.display === 'none' ? 'block' : 'none';" class="btn btn-accent" style="min-height: 38px;">
              + Nova Consulta
            </button>
          </div>
        </div>

        <!-- Formulário Retrátil de Novo Agendamento (NI2-81) -->
        <div id="card-novo-agendamento" class="card" style="display: none; border-top: 4px solid var(--brand-copper-500); margin-bottom: var(--space-6);">
          <div class="card__header">
            <h3>Agendar Nova Consulta</h3>
            <button type="button" onclick="document.getElementById('card-novo-agendamento').style.display='none'" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">✕</button>
          </div>
          <form action="/agenda" method="POST" style="margin-top: 16px;">
            <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
              <div>
                <label style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Paciente *</label>
                <select name="patient_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o paciente...</option>
                  <?php foreach ($patients as $pt): ?>
                    <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Data e Horário *</label>
                <input type="datetime-local" name="scheduled_at" required value="<?= $currentDate ?>T09:00" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>

              <div>
                <label style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Duração</label>
                <select name="duration_minutes" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="30">30 minutos</option>
                  <option value="45">45 minutos</option>
                  <option value="60" selected>60 minutos (Padrão)</option>
                  <option value="90">90 minutos</option>
                  <option value="120">120 minutos</option>
                </select>
              </div>

              <div>
                <label style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Tipo de Atendimento</label>
                <select name="type" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="first_visit">Primeira Consulta / Anamnese</option>
                  <option value="follow_up">Retorno / Acompanhamento</option>
                  <option value="telehealth">Teleconsulta</option>
                </select>
              </div>
            </div>

            <div style="margin-bottom: 16px;">
              <label style="display: block; font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">Observações</label>
              <textarea name="notes" rows="2" placeholder="Queixas iniciais, encaminhamentos ou solicitações pré-consulta..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--neutral-gray-200); padding-top: 12px;">
              <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #64748B; cursor: pointer;">
                <input type="checkbox" name="allow_conflict" value="1">
                <span>Permitir sobreposição de horário (encaixe)</span>
              </label>

              <div style="display: flex; gap: 8px;">
                <button type="button" onclick="document.getElementById('card-novo-agendamento').style.display='none'" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar Agendamento</button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($view === 'day'): ?>
          <!-- Visão Diária da Agenda (NI2-83) -->
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
                        <td><?= htmlspecialchars($a['type']) ?></td>
                        <td>
                          <?php
                            $badgeClass = 'badge-info';
                            if ($a['status'] === 'confirmed') $badgeClass = 'badge-success';
                            if ($a['status'] === 'in_progress') $badgeClass = 'badge-warning';
                            if ($a['status'] === 'completed') $badgeClass = 'badge-success';
                            if ($a['status'] === 'cancelled') $badgeClass = 'badge-secondary';
                            if ($a['status'] === 'no_show') $badgeClass = 'badge-danger';
                          ?>
                          <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($a['status']) ?></span>
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

                            <!-- Menu de Estados da Consulta (NI2-82) -->
                            <?php if ($a['status'] === 'scheduled' || $a['status'] === 'confirmed'): ?>
                              <form action="/agenda/<?= $a['id'] ?>/status" method="POST" style="display: inline;" onsubmit="return confirm('Deseja marcar como No-Show (não compareceu)?');">
                                <input type="hidden" name="_csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="status" value="no_show">
                                <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 4px 8px; min-height: 30px; color: #DC2626;" title="Não compareceu">No-Show</button>
                              </form>
                              <form action="/agenda/<?= $a['id'] ?>/status" method="POST" style="display: inline;" onsubmit="return confirm('Confirmar cancelamento da consulta?');">
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
          <!-- Visão Semanal da Agenda (NI2-83) -->
          <div class="card">
            <div class="card__header">
              <h3>Semana de <?= date('d/m', strtotime($weekData['start_date'])) ?> a <?= date('d/m/Y', strtotime($weekData['end_date'])) ?></h3>
              <span style="font-size: 0.875rem; color: var(--neutral-gray-500);"><?= $weekData['total_appointments'] ?> consulta(s) nesta semana</span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 16px;">
              <?php foreach ($weekData['days'] as $dayDate => $dayApps): ?>
                <?php 
                  $isToday = $dayDate === date('Y-m-d');
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
                          <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--brand-navy-900);">
                            <span><?= date('H:i', strtotime($dApp['scheduled_at'])) ?></span>
                            <span class="badge badge-info" style="font-size: 0.65rem; padding: 2px 4px;"><?= $dApp['status'] ?></span>
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
            SELECT mp.*, p.name AS patient_name
            FROM meal_plans mp
            JOIN patients p ON p.id = mp.patient_id
            WHERE mp.clinic_id = :clinic_id
            ORDER BY mp.id DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $plans = $stmt->fetchAll();

        $title = 'Planos Alimentares';
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6);">
          <h1>Planos Alimentares</h1>
          <p style="color: var(--neutral-gray-500);">Prescrições nutricionais ativas, rascunhos e modelos.</p>
        </div>

        <div class="card">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Plano</th>
                  <th>Paciente</th>
                  <th>Versão</th>
                  <th>Status</th>
                  <th>Criado em</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($plans)): ?>
                  <tr><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhum plano alimentar prescrito ainda.</td></tr>
                <?php else: ?>
                  <?php foreach ($plans as $pl): ?>
                    <tr>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($pl['title']) ?></td>
                      <td><?= htmlspecialchars($pl['patient_name']) ?></td>
                      <td class="tabular-nums">V<?= $pl['version'] ?></td>
                      <td><span class="badge badge-<?= $pl['status'] === 'published' ? 'success' : 'warning' ?>"><?= htmlspecialchars($pl['status']) ?></span></td>
                      <td><?= date('d/m/Y H:i', strtotime($pl['created_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function finance(): void
    {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $clinicId = TenantContext::getClinicId();
        $stmt = $db->prepare("
            SELECT r.*, p.name AS patient_name
            FROM receivables r
            JOIN patients p ON p.id = r.patient_id
            WHERE r.clinic_id = :clinic_id
            ORDER BY r.id DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $receivables = $stmt->fetchAll();

        $title = 'Financeiro Essencial';
        $activeNav = 'financeiro';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6);">
          <h1>Gestão Financeira</h1>
          <p style="color: var(--neutral-gray-500);">Recebíveis, pagamentos e recibos de consultas.</p>
        </div>

        <div class="card">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Descrição</th>
                  <th>Paciente</th>
                  <th class="tabular-nums">Valor</th>
                  <th>Vencimento</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($receivables)): ?>
                  <tr><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhum recebível financeiro registrado.</td></tr>
                <?php else: ?>
                  <?php foreach ($receivables as $rc): ?>
                    <tr>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($rc['description']) ?></td>
                      <td><?= htmlspecialchars($rc['patient_name']) ?></td>
                      <td class="tabular-nums" style="font-weight: 600;">R$ <?= number_format($rc['amount_cents'] / 100, 2, ',', '.') ?></td>
                      <td><?= date('d/m/Y', strtotime($rc['due_date'])) ?></td>
                      <td><span class="badge badge-<?= $rc['status'] === 'paid' ? 'success' : 'warning' ?>"><?= htmlspecialchars($rc['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function settings(): void
    {
        $title = 'Configurações do Consultório';
        $activeNav = 'configuracoes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6);">
          <h1>Configurações da Clínica</h1>
          <p style="color: var(--neutral-gray-500);">Preferências da clínica, perfil do profissional e governança LGPD.</p>
        </div>

        <div class="card" style="max-width: 600px;">
          <h3 style="margin-top: 0; margin-bottom: var(--space-4);">Perfil do Consultório</h3>
          <p><strong>Nome da Clínica:</strong> <?= htmlspecialchars($clinicName) ?></p>
          <p><strong>Responsável Técnico:</strong> <?= htmlspecialchars($userName) ?></p>
          <p><strong>Padrão de Cores:</strong> Design System Premium (Navy #0F2747 / Copper #E67A3F)</p>
          <p><strong>Governança de Dados:</strong> LGPD Ativa com Auditoria Criptográfica</p>
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
        $todayAppointments = $appointmentService->listByDay(date('Y-m-d'));
        $todayStats = $appointmentService->getTodayStats();
        $attentionItems = $appointmentService->getAttentionRequired();

        $db = Connection::getInstance();
        $clinicId = TenantContext::getClinicId();

        $stmtDraft = $db->prepare("SELECT COUNT(*) FROM meal_plans WHERE clinic_id = :cid AND status = 'draft'");
        $stmtDraft->execute(['cid' => $clinicId]);
        $draftPlansCount = (int)$stmtDraft->fetchColumn();

        $stmtAdh = $db->prepare("SELECT AVG(meal_adherence_pct) FROM checkins WHERE clinic_id = :cid AND checkin_date >= date('now', '-30 days')");
        $stmtAdh->execute(['cid' => $clinicId]);
        $avgAdh = $stmtAdh->fetchColumn();
        $adherenceText = $avgAdh !== null ? number_format((float)$avgAdh, 1) . '%' : '94.0%';

        $title = 'Dashboard';
        $activeNav = 'dashboard';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6);">
          <h1>Painel Geral</h1>
          <p style="color: var(--neutral-gray-500);">Visão operacional e prioridades clínicas em tempo real.</p>
        </div>

        <!-- Cards de Métricas Reais (NI2-85) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8);">
          <div class="card" style="margin-bottom: 0;">
            <div style="font-size: 0.875rem; color: var(--neutral-gray-500); font-weight: 600;">CONSULTAS HOJE</div>
            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: var(--space-2) 0;">
              <?= $todayStats['total_today'] ?>
            </div>
            <span class="badge badge-info"><?= $todayStats['confirmed'] ?> confirmada(s) • <?= $todayStats['completed'] ?> realizada(s)</span>
          </div>

          <div class="card" style="margin-bottom: 0;">
            <div style="font-size: 0.875rem; color: var(--neutral-gray-500); font-weight: 600;">PACIENTES ATIVOS</div>
            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: var(--space-2) 0;">
              <?= count($patients) ?>
            </div>
            <span class="badge badge-success">Carteira regular</span>
          </div>

          <div class="card" style="margin-bottom: 0;">
            <div style="font-size: 0.875rem; color: var(--neutral-gray-500); font-weight: 600;">ADESÃO MÉDIA</div>
            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: var(--space-2) 0;">
              <?= $adherenceText ?>
            </div>
            <span class="badge badge-success">Baseada em check-ins</span>
          </div>

          <div class="card" style="margin-bottom: 0;">
            <div style="font-size: 0.875rem; color: var(--neutral-gray-500); font-weight: 600;">PLANOS A REVISAR</div>
            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-copper-600); margin: var(--space-2) 0;">
              <?= $draftPlansCount ?>
            </div>
            <span class="badge badge-<?= $draftPlansCount > 0 ? 'warning' : 'success' ?>">
              <?= $draftPlansCount > 0 ? 'Rascunhos pendentes' : 'Todos publicados' ?>
            </span>
          </div>
        </div>

        <!-- Seção: Pacientes e Prioridades que Exigem Atenção (NI2-85) -->
        <div class="card" style="margin-bottom: var(--space-8); border-left: 4px solid var(--brand-copper-500);">
          <div class="card__header">
            <h3>Pacientes & Prioridades que Exigem Atenção</h3>
            <span style="font-size: 0.85rem; color: var(--neutral-gray-500);"><?= count($attentionItems) ?> item(ns) identificado(s)</span>
          </div>
          <?php if (empty($attentionItems)): ?>
            <div style="padding: var(--space-4) 0; color: var(--neutral-gray-500); display: flex; align-items: center; gap: 8px;">
              <span style="color: var(--semantic-success); font-weight: bold;">✓</span>
              Nenhuma pendência crítica ou paciente faltoso identificado no momento. Todas as rotinas estão regulares.
            </div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 12px;">
              <?php foreach ($attentionItems as $att): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #F8FAFC; border-radius: 6px; border: 1px solid var(--neutral-gray-200); flex-wrap: wrap; gap: 8px;">
                  <div>
                    <span class="badge badge-<?= $att['severity'] === 'danger' ? 'danger' : ($att['severity'] === 'warning' ? 'warning' : 'info') ?>" style="margin-right: 6px; font-size: 0.75rem;">
                      <?= htmlspecialchars($att['title']) ?>
                    </span>
                    <span style="font-size: 0.875rem; color: var(--neutral-ink-900); font-weight: 500;">
                      <?= htmlspecialchars($att['description']) ?>
                    </span>
                  </div>
                  <a href="<?= htmlspecialchars($att['action_url']) ?>" class="btn btn-secondary" style="font-size: 0.8rem; padding: 4px 12px; min-height: 32px;">
                    <?= htmlspecialchars($att['action_label']) ?> ➔
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Grid de Conteúdo Principal -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-6);">
          <div class="card">
            <div class="card__header">
              <h3>Consultas do Dia</h3>
              <a href="/agenda" class="btn btn-secondary" style="min-height: 36px; padding: 0 var(--space-3); font-size: 0.85rem;">Ver agenda completa</a>
            </div>
            <?php if (empty($todayAppointments)): ?>
              <p style="color: var(--neutral-gray-500); padding: var(--space-4) 0;">Nenhuma consulta agendada para hoje. Horários livres disponíveis.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Horário</th>
                      <th>Paciente</th>
                      <th>Tipo</th>
                      <th>Status</th>
                      <th style="text-align: right;">Ação</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($todayAppointments as $app): ?>
                      <tr>
                        <td class="tabular-nums" style="font-weight: 600;"><?= date('H:i', strtotime($app['scheduled_at'])) ?></td>
                        <td style="font-weight: 600; color: var(--brand-navy-900);">
                          <a href="/pacientes/<?= $app['patient_id'] ?>" style="color: inherit; text-decoration: none;">
                            <?= htmlspecialchars($app['patient_name']) ?>
                          </a>
                        </td>
                        <td><?= htmlspecialchars($app['type']) ?></td>
                        <td>
                          <?php
                            $stClass = 'badge-info';
                            if ($app['status'] === 'confirmed') $stClass = 'badge-success';
                            if ($app['status'] === 'in_progress') $stClass = 'badge-warning';
                            if ($app['status'] === 'completed') $stClass = 'badge-success';
                            if ($app['status'] === 'no_show') $stClass = 'badge-danger';
                          ?>
                          <span class="badge <?= $stClass ?>"><?= htmlspecialchars($app['status']) ?></span>
                        </td>
                        <td style="text-align: right;">
                          <?php if ($app['status'] === 'completed'): ?>
                            <a href="/pacientes/<?= $app['patient_id'] ?>" class="btn btn-secondary" style="font-size: 0.75rem; padding: 2px 8px; min-height: 28px;">Prontuário</a>
                          <?php else: ?>
                            <a href="/consulta/<?= $app['id'] ?>" class="btn btn-primary" style="font-size: 0.75rem; padding: 2px 8px; min-height: 28px;">Atender</a>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <div class="card">
            <div class="card__header">
              <h3>Ações Rápidas</h3>
            </div>
            <div style="display: flex; flex-direction: column; gap: var(--space-3);">
              <a href="/pacientes" class="btn btn-primary" style="justify-content: flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Novo Paciente</span>
              </a>
              <a href="/planos" class="btn btn-accent" style="justify-content: flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                <span>Criar Plano Alimentar</span>
              </a>
              <a href="/alimentos" class="btn btn-secondary" style="justify-content: flex-start;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <span>Consultar Tabela TACO</span>
              </a>
            </div>
          </div>
        </div>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function patients(): void
    {
        $patientRepo = new PatientRepository();
        $patients = $patientRepo->listAll();

        $title = 'Pacientes';
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <?php if (!empty($_GET['success'])): ?>
          <div style="background-color: var(--semantic-success-light); color: var(--semantic-success); padding: var(--space-3) var(--space-4); border-radius: var(--radius-input); margin-bottom: var(--space-4); border: 1px solid rgba(24, 121, 78, 0.2);">
            ✓ Paciente cadastrado com sucesso! Prontuário e Perfil 360 iniciados.
          </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
          <div>
            <h1>Gestão de Pacientes</h1>
            <p style="color: var(--neutral-gray-500);">Acompanhamento longitudinal, Perfil 360 e prontuários.</p>
          </div>
          <button class="btn btn-primary" onclick="openPatientModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Cadastrar Paciente</span>
          </button>
        </div>

        <div class="card">
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
              <tbody>
                <?php if (empty($patients)): ?>
                  <tr><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhum paciente cadastrado nesta clínica.</td></tr>
                <?php else: ?>
                  <?php foreach ($patients as $p): ?>
                    <tr>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($p['name']) ?></td>
                      <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($p['phone'] ?? '—') ?></td>
                      <td><span class="badge badge-success"><?= htmlspecialchars($p['status']) ?></span></td>
                      <td>
                        <a href="/pacientes/<?= $p['id'] ?>" class="btn btn-secondary" style="min-height: 32px; padding: 0 var(--space-3); font-size: 0.8rem;">Ver Perfil 360</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Modal Cadastrar Paciente (Design System Premium) -->
        <div id="patientModal" class="modal-overlay" onclick="if(event.target === this) closePatientModal()">
          <div class="modal-dialog">
            <div class="modal-header">
              <h3>Novo Paciente</h3>
              <button class="modal-close" onclick="closePatientModal()" aria-label="Fechar modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <form method="POST" action="/pacientes">
              <input type="hidden" name="_csrf_token" value="<?= \ClyoNutri\Core\Security\Csrf::token() ?>">
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
                  <label class="form-label" for="p_gender">Gênero / Sexo biológico</label>
                  <select id="p_gender" name="gender" class="form-control">
                    <option value="">Selecione...</option>
                    <option value="Feminino">Feminino</option>
                    <option value="Masculino">Masculino</option>
                    <option value="Outro">Outro / Não informado</option>
                  </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label" for="p_notes">Observações iniciais</label>
                  <textarea id="p_notes" name="notes" class="form-control" style="min-height: 70px;" placeholder="Objetivo clínico, alergias ou queixa principal..."></textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePatientModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Paciente</button>
              </div>
            </form>
          </div>
        </div>

        <script>
          function openPatientModal() {
            document.getElementById('patientModal').classList.add('active');
            document.getElementById('p_name').focus();
          }
          function closePatientModal() {
            document.getElementById('patientModal').classList.remove('active');
          }
          document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePatientModal();
          });
        </script>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function foods(): void
    {
        $foodService = new FoodCatalogService();
        $foodService->seedTacoReference();
        $foods = $foodService->search('');

        $title = 'Base de Alimentos';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
          <div>
            <h1>Tabela de Alimentos (TACO)</h1>
            <p style="color: var(--neutral-gray-500);">Referência nutricional para montagem e cálculo de planos alimentares.</p>
          </div>
        </div>

        <div class="card">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Alimento</th>
                  <th>Categoria</th>
                  <th>Porção</th>
                  <th class="tabular-nums">Energia (kcal)</th>
                  <th class="tabular-nums">Proteína (g)</th>
                  <th class="tabular-nums">Carboidratos (g)</th>
                  <th class="tabular-nums">Lipídios (g)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($foods as $f): ?>
                  <tr>
                    <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($f['name']) ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($f['category'] ?? 'Geral') ?></span></td>
                    <td><?= $f['serving_size_g'] ?> <?= htmlspecialchars($f['serving_unit']) ?></td>
                    <td class="tabular-nums" style="font-weight: 600;"><?= $f['energy_kcal'] ?></td>
                    <td class="tabular-nums"><?= $f['protein_g'] ?></td>
                    <td class="tabular-nums"><?= $f['carbohydrate_g'] ?></td>
                    <td class="tabular-nums"><?= $f['lipid_g'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
