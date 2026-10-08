<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Appointment\AppointmentService;
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

    public function agenda(): void
    {
        $appointmentService = new AppointmentService();
        $appointments = $appointmentService->listByDay(date('Y-m-d'));

        $title = 'Agenda de Atendimentos';
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6);">
          <h1>Agenda do Dia</h1>
          <p style="color: var(--neutral-gray-500);">Gestão de consultas, retornos e sessões de teleatendimento.</p>
        </div>

        <div class="card">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Horário</th>
                  <th>Paciente</th>
                  <th>Telefone</th>
                  <th>Tipo</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($appointments)): ?>
                  <tr><td colspan="5" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhuma consulta agendada para hoje.</td></tr>
                <?php else: ?>
                  <?php foreach ($appointments as $a): ?>
                    <tr>
                      <td class="tabular-nums" style="font-weight: 600;"><?= date('H:i', strtotime($a['scheduled_at'])) ?></td>
                      <td style="font-weight: 600; color: var(--brand-navy-900);"><?= htmlspecialchars($a['patient_name']) ?></td>
                      <td><?= htmlspecialchars($a['patient_phone'] ?? '—') ?></td>
                      <td><?= htmlspecialchars($a['type']) ?></td>
                      <td><span class="badge badge-info"><?= htmlspecialchars($a['status']) ?></span></td>
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

        $title = 'Dashboard';
        $activeNav = 'dashboard';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="margin-bottom: var(--space-6);">
          <h1>Painel Geral</h1>
          <p style="color: var(--neutral-gray-500);">Visão operacional do consultório em tempo real.</p>
        </div>

        <!-- Cards de Métricas -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8);">
          <div class="card" style="margin-bottom: 0;">
            <div style="font-size: 0.875rem; color: var(--neutral-gray-500); font-weight: 600;">CONSULTAS HOJE</div>
            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: var(--space-2) 0;">
              <?= count($todayAppointments) ?>
            </div>
            <span class="badge badge-info">Agenda ativa</span>
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
              88.5%
            </div>
            <span class="badge badge-success">+4.2% este mês</span>
          </div>

          <div class="card" style="margin-bottom: 0;">
            <div style="font-size: 0.875rem; color: var(--neutral-gray-500); font-weight: 600;">PLANOS A REVISAR</div>
            <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-copper-600); margin: var(--space-2) 0;">
              2
            </div>
            <span class="badge badge-warning">Atenção requerida</span>
          </div>
        </div>

        <!-- Grid de Conteúdo -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-6);">
          <div class="card">
            <div class="card__header">
              <h3>Consultas do Dia</h3>
              <a href="/agenda" class="btn btn-secondary" style="min-height: 36px; padding: 0 var(--space-3); font-size: 0.85rem;">Ver agenda</a>
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
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($todayAppointments as $app): ?>
                      <tr>
                        <td class="tabular-nums" style="font-weight: 600;"><?= date('H:i', strtotime($app['scheduled_at'])) ?></td>
                        <td><?= htmlspecialchars($app['patient_name']) ?></td>
                        <td><?= htmlspecialchars($app['type']) ?></td>
                        <td><span class="badge badge-info"><?= htmlspecialchars($app['status']) ?></span></td>
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
