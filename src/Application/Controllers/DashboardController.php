<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Patient\PatientRepository;

class DashboardController
{
    public function index(): void
    {
        $patientRepo = new PatientRepository();
        $patients = $patientRepo->listAll();

        $appointmentService = new AppointmentService();
        $todayAppointments = $appointmentService->listByDay(date('Y-m-d'));

        $title = 'Dashboard';
        $activeNav = 'dashboard';
        $clinicName = 'Clínica ClyoNutri Demo';
        $userName = 'Dra. Camila Mendes';

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
                ➕ Novo Paciente
              </a>
              <a href="/planos" class="btn btn-accent" style="justify-content: flex-start;">
                🥗 Criar Plano Alimentar
              </a>
              <a href="/alimentos" class="btn btn-secondary" style="justify-content: flex-start;">
                🔍 Consultar Tabela TACO
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
        $clinicName = 'Clínica ClyoNutri Demo';
        $userName = 'Dra. Camila Mendes';

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
          <div>
            <h1>Gestão de Pacientes</h1>
            <p style="color: var(--neutral-gray-500);">Acompanhamento longitudinal, Perfil 360 e prontuários.</p>
          </div>
          <button class="btn btn-primary">➕ Cadastrar Paciente</button>
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
        $clinicName = 'Clínica ClyoNutri Demo';
        $userName = 'Dra. Camila Mendes';

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
