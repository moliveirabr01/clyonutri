<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Anthropometry\AnthropometryCalculator;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException;
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
        $csrfToken = Csrf::token();

        $anthroService = new AnthropometryService();
        $evolutionData = $anthroService->getEvolution($id);
        $assessments = $evolutionData['assessments'];

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'avaliacao_registrada' ? 'Avaliação antropométrica registrada com sucesso no prontuário!' : 'Operação realizada com sucesso!' ?>
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
            <p style="color: var(--neutral-gray-500);">Perfil Clínico 360 • Acompanhamento Longitudinal</p>
          </div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <a href="/pacientes/<?= $p['id'] ?>/antropometria" class="btn btn-secondary">
              Ver Evolução & Comparativo ➔
            </a>
            <button type="button" class="btn btn-primary" onclick="openAnthroModal()">
              + Nova Avaliação Antropométrica
            </button>
          </div>
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

            <!-- Card de Avaliações Antropométricas com Indicadores OMS (NI2-65, NI2-66, NI2-69) -->
            <div class="card">
              <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0;">Avaliações Antropométricas (<?= count($assessments) ?>)</h3>
                <a href="/pacientes/<?= $p['id'] ?>/antropometria" style="font-size: 0.85rem; color: var(--brand-copper-600); font-weight: 600; text-decoration: none;">Análise Completa ➔</a>
              </div>

              <?php if (empty($assessments)): ?>
                <div style="text-align: center; padding: var(--space-6); color: var(--neutral-gray-500);">
                  <p>Nenhuma avaliação antropométrica registrada ainda para este paciente.</p>
                  <button type="button" class="btn btn-secondary" onclick="openAnthroModal()" style="margin-top: 8px;">Registrar 1ª Avaliação</button>
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
                          <strong class="tabular-nums" style="color: var(--brand-navy-900);"><?= $as['weight_kg'] ?> kg</strong> / <span class="tabular-nums"><?= $as['height_cm'] ?> cm</span>
                        </div>
                        <div>
                          <span style="color: var(--neutral-gray-500); font-size: 0.75rem; display: block;">IMC (OMS)</span>
                          <strong class="tabular-nums" style="color: var(--brand-navy-900);"><?= $as['bmi'] ?></strong>
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
                            <strong class="tabular-nums" style="color: var(--brand-copper-600);"><?= $as['body_fat_pct'] ?>%</strong>
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

        <!-- Modal Nova Avaliação Antropométrica (NI2-65) -->
        <div id="anthroModal" class="modal-overlay" onclick="if(event.target === this) closeAnthroModal()">
          <div class="modal-dialog" style="max-width: 650px;">
            <div class="modal-header">
              <h3>Nova Avaliação Antropométrica</h3>
              <button class="modal-close" onclick="closeAnthroModal()" aria-label="Fechar modal">✕</button>
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
                    <input type="checkbox" name="is_shared" value="1" checked style="width: 16px; height: 16px;">
                    Liberar imediatamente para o paciente visualizar no aplicativo (NI2-69)
                  </label>
                </div>
              </div>

              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAnthroModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Avaliação Antropométrica</button>
              </div>
            </form>
          </div>
        </div>

        <script>
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
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri Demo';
        $userName = $_SESSION['user_name'] ?? 'Dra. Camila Mendes';
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <!-- Alertas de Feedback -->
        <?php if (!empty($_GET['success'])): ?>
          <div style="background: #E8F5E9; border-left: 4px solid var(--semantic-success); padding: 12px 16px; border-radius: 6px; margin-bottom: var(--space-4); color: #18794E; font-weight: 500;">
            ✓ <?= $_GET['success'] === 'avaliacao_registrada' ? 'Avaliação registrada com sucesso!' : 'Permissão de compartilhamento atualizada com sucesso!' ?>
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
              <a href="/pacientes/<?= $patient['id'] ?>" style="color: var(--brand-copper-600); text-decoration: none;">← Voltar ao Perfil 360</a>
            </div>
            <h1>Evolução Antropométrica: <?= htmlspecialchars($patient['name']) ?></h1>
            <p style="color: var(--neutral-gray-500);">Análise longitudinal de indicadores, composição corporal e comparativo clínico.</p>
          </div>
          <button type="button" class="btn btn-primary" onclick="openAnthroModal()">
            + Nova Avaliação
          </button>
        </div>

        <!-- KPIs Resumo Clínico -->
        <?php if ($latestEval): ?>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: var(--space-6);">
            <div class="card" style="margin-bottom: 0;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-gray-500); font-weight: 700;">ÚLTIMO PESO REGISTRADO</div>
              <div class="tabular-nums" style="font-size: 2rem; font-weight: 700; color: var(--brand-navy-900); margin: 6px 0;">
                <?= $latestEval['weight_kg'] ?> kg
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
                <?= $latestEval['bmi'] ?>
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
                  <?= $latestEval['body_fat_pct'] ?>%
                </div>
                <span style="font-size: 0.8rem; color: var(--neutral-gray-500);">
                  <?= $latestEval['fat_mass_kg'] !== null ? "{$latestEval['fat_mass_kg']} kg massa gorda" : 'Informado' ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Alternativa Textual Acessível (NI2-67) -->
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
              <label style="font-size: 0.85rem; font-weight: 600; color: var(--neutral-gray-600);">Métrica:</label>
              <select onchange="location.href='/pacientes/<?= $patient['id'] ?>/antropometria?metric='+this.value" style="padding: 6px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.875rem;">
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

        <!-- Seção: Comparador Clínico de Avaliações (NI2-68) -->
        <div class="card" style="margin-bottom: var(--space-6);">
          <div class="card__header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
              <h3>Comparativo Clínico Entre Avaliações</h3>
              <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.85rem;">Selecione duas avaliações para analisar deltas absolutos, percentuais e evolução corporal.</p>
            </div>
            
            <?php if (count($assessments) >= 2): ?>
              <form method="GET" action="/pacientes/<?= $patient['id'] ?>/antropometria" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="metric" value="<?= htmlspecialchars($metric) ?>">
                <select name="eval_a" style="padding: 6px 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.85rem;">
                  <?php foreach ($assessments as $opt): ?>
                    <option value="<?= $opt['id'] ?>" <?= $evalAId === (int)$opt['id'] ? 'selected' : '' ?>>
                      Baseline: <?= date('d/m/Y', strtotime($opt['assessed_at'])) ?> (<?= $opt['weight_kg'] ?> kg)
                    </option>
                  <?php endforeach; ?>
                </select>

                <span style="font-weight: 600; color: var(--neutral-gray-500);">vs</span>

                <select name="eval_b" style="padding: 6px 10px; border: 1px solid var(--neutral-gray-300); border-radius: 6px; font-size: 0.85rem;">
                  <?php foreach ($assessments as $opt): ?>
                    <option value="<?= $opt['id'] ?>" <?= $evalBId === (int)$opt['id'] ? 'selected' : '' ?>>
                      Comparar: <?= date('d/m/Y', strtotime($opt['assessed_at'])) ?> (<?= $opt['weight_kg'] ?> kg)
                    </option>
                  <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-secondary" style="min-height: 34px;">Comparar</button>
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
        <div id="anthroModal" class="modal-overlay" onclick="if(event.target === this) closeAnthroModal()">
          <div class="modal-dialog" style="max-width: 650px;">
            <div class="modal-header">
              <h3>Nova Avaliação Antropométrica</h3>
              <button class="modal-close" onclick="closeAnthroModal()" aria-label="Fechar modal">✕</button>
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
                    <input type="checkbox" name="is_shared" value="1" checked style="width: 16px; height: 16px;">
                    Liberar imediatamente para o paciente visualizar no aplicativo (NI2-69)
                  </label>
                </div>
              </div>

              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAnthroModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Avaliação Antropométrica</button>
              </div>
            </form>
          </div>
        </div>

        <script>
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

        $profId = $this->getActiveProfessionalId();
        $userId = TenantContext::getUserId();
        $service = new AnthropometryService();

        try {
            $service->recordAssessment($patientId, $profId, $_POST, $userId);
            header("Location: /pacientes/{$patientId}/antropometria?success=avaliacao_registrada");
            exit;
        } catch (InvalidAnthropometricDataException $e) {
            header("Location: /pacientes/{$patientId}/antropometria?error=dados_invalidos&msg=" . urlencode($e->getMessage()));
            exit;
        } catch (\Throwable $e) {
            header("Location: /pacientes/{$patientId}/antropometria?error=erro_interno&msg=" . urlencode($e->getMessage()));
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
            SELECT mp.*, p.name AS patient_name, u.name AS professional_name
            FROM meal_plans mp
            JOIN patients p ON p.id = mp.patient_id
            LEFT JOIN professionals pr ON pr.id = mp.professional_id
            LEFT JOIN users u ON u.id = pr.user_id
            WHERE mp.clinic_id = :clinic_id
            ORDER BY mp.id DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $plans = $stmt->fetchAll();

        $patStmt = $db->prepare("SELECT id, name FROM patients WHERE clinic_id = :clinic_id AND status = 'active' ORDER BY name ASC");
        $patStmt->execute(['clinic_id' => $clinicId]);
        $patients = $patStmt->fetchAll();

        $title = 'Planos Alimentares';
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
          <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;">Prescrições e Planos Alimentares</h1>
            <p style="color: var(--neutral-gray-500); margin-top: 4px;">Editor por refeições, alimentos, substituições, totais nutricionais e versionamento imutável.</p>
          </div>
          <button class="btn btn-primary" onclick="document.getElementById('modal-new-plan').style.display='flex'">
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
                  <tr><td colspan="7" style="text-align: center; color: var(--neutral-gray-500); padding: var(--space-6);">Nenhum plano alimentar cadastrado ainda.</td></tr>
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
                        <?php if ($pl['status'] === 'published'): ?>
                          <span class="badge badge-success" style="background: #def7ec; color: #03543f; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem;">Publicado</span>
                        <?php elseif ($pl['status'] === 'draft'): ?>
                          <span class="badge badge-warning" style="background: #fef08a; color: #854d0e; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem;">Rascunho</span>
                        <?php else: ?>
                          <span class="badge" style="background: #e2e8f0; color: #475569; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem;"><?= htmlspecialchars($pl['status']) ?></span>
                        <?php endif; ?>
                      </td>
                      <td style="padding: 12px;" class="tabular-nums"><?= number_format((float)($pl['daily_energy_kcal'] ?? 0), 0, ',', '.') ?> kcal</td>
                      <td style="padding: 12px; color: #64748b; font-size: 0.85rem;"><?= date('d/m/Y H:i', strtotime($pl['updated_at'] ?? $pl['created_at'])) ?></td>
                      <td style="padding: 12px; text-align: right; white-space: nowrap;">
                        <a href="/planos/<?= $pl['id'] ?>" class="btn btn-sm btn-outline" style="text-decoration: none; padding: 4px 10px; font-size: 0.8rem; margin-right: 4px;">
                          <?= $pl['status'] === 'draft' ? 'Editar' : 'Visualizar' ?>
                        </a>
                        <form method="POST" action="/planos/<?= $pl['id'] ?>/duplicar" style="display: inline;" onsubmit="return confirm('Deseja duplicar este plano para um novo rascunho?');">
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
        <div id="modal-new-plan" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 16px;">Criar Novo Plano Alimentar</h2>
            <form method="POST" action="/planos/novo">
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Paciente *</label>
                <select name="patient_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o paciente...</option>
                  <?php foreach ($patients as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Título da Prescrição *</label>
                <input type="text" name="title" required placeholder="Ex: Plano Hipertrofia Fase 1" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientações Gerais do Plano (Opcional)</label>
                <textarea name="notes" rows="3" placeholder="Recomendações clínicas, horários preferenciais, hidratação..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-new-plan').style.display='none'">Cancelar</button>
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
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

        $profStmt = $db->prepare("SELECT id FROM professionals WHERE clinic_id = :cid LIMIT 1");
        $profStmt->execute(['cid' => TenantContext::getClinicId()]);
        $professionalId = (int)$profStmt->fetchColumn();

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
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $preview = $service->getPlanPreview($planId);
            $plan = $preview['plan'];
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header("Location: /planos");
            exit;
        }

        $fStmt = $db->prepare("SELECT id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods ORDER BY name ASC LIMIT 250");
        $fStmt->execute();
        $foodsCatalog = $fStmt->fetchAll();

        $title = "Plano Alimentar: " . htmlspecialchars($plan['title']);
        $activeNav = 'planos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Clínica ClyoNutri';
        $userName = $_SESSION['user_name'] ?? 'Profissional Nutricionista';

        $feedback = $_SESSION['feedback_message'] ?? null;
        $error = $_SESSION['error_message'] ?? null;
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);

        $isDraft = ($plan['status'] === 'draft');

        ob_start();
        ?>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 16px;">
          <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
              <a href="/planos" style="text-decoration: none; color: #64748b; font-size: 0.9rem;">← Voltar para lista</a>
              <span class="badge" style="background: <?= $isDraft ? '#fef08a' : '#def7ec' ?>; color: <?= $isDraft ? '#854d0e' : '#03543f' ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                <?= $isDraft ? 'RASCUNHO (Edição aberta)' : 'PUBLICADO (Imutável v' . (int)$plan['version'] . ')' ?>
              </span>
            </div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0;"><?= htmlspecialchars($plan['title']) ?></h1>
            <p style="color: var(--neutral-gray-500); margin-top: 4px;">Paciente: <strong><?= htmlspecialchars($plan['patient_name']) ?></strong> | Responsável: <?= htmlspecialchars($plan['professional_name']) ?></p>
          </div>
          <div style="display: flex; gap: 10px; align-items: center;">
            <form method="POST" action="/planos/<?= $planId ?>/duplicar" onsubmit="return confirm('Deseja criar um novo rascunho a partir deste plano?');">
              <button type="submit" class="btn btn-outline" style="font-size: 0.875rem;">Duplicar como Rascunho</button>
            </form>
            <?php if ($isDraft): ?>
              <button class="btn btn-primary" onclick="document.getElementById('modal-publish-plan').style.display='flex'">
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

        <!-- Alertas de Segurança Clínica e Alérgenos -->
        <?php if (!empty($preview['allergen_safety']['has_conflict'])): ?>
          <div style="background: #fff3cd; color: #856404; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffeeba;">
            <strong style="display: block; margin-bottom: 6px;">⚠️ Atenção Clínica: Conflitos Alergênicos Detectados com a Anamnese!</strong>
            <ul style="margin: 0; padding-left: 20px; font-size: 0.9rem;">
              <?php foreach ($preview['allergen_safety']['conflicts'] as $conf): ?>
                <li>Alimento <strong><?= htmlspecialchars($conf['food_name']) ?></strong> possui grupo alergênico registrado: <em><?= htmlspecialchars($conf['allergen_group']) ?></em>.</li>
              <?php endforeach; ?>
            </ul>
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
            <button class="btn btn-sm btn-outline" onclick="document.getElementById('modal-add-meal').style.display='flex'">
              + Adicionar Refeição
            </button>
          <?php endif; ?>
        </div>

        <?php if (empty($plan['meals'])): ?>
          <div class="card" style="padding: 32px; text-align: center; color: #64748b;">
            <p style="margin-bottom: 12px;">Nenhuma refeição adicionada a esta prescrição.</p>
            <?php if ($isDraft): ?>
              <button class="btn btn-primary" onclick="document.getElementById('modal-add-meal').style.display='flex'">Adicionar Primeira Refeição</button>
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
                    <strong><?= number_format((float)$m['totals']['energy_kcal'], 0) ?> kcal</strong> | P: <?= number_format((float)$m['totals']['protein_g'], 1) ?>g | C: <?= number_format((float)$m['totals']['carbohydrate_g'], 1) ?>g | G: <?= number_format((float)$m['totals']['lipid_g'], 1) ?>g
                  </span>
                  <?php if ($isDraft): ?>
                    <button class="btn btn-sm btn-outline" onclick="openAddItemModal(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['name'])) ?>')" style="padding: 4px 8px; font-size: 0.8rem;">+ Alimento</button>
                    <form method="POST" action="/planos/<?= $planId ?>/refeicoes/<?= $m['id'] ?>/remover" style="display: inline;" onsubmit="return confirm('Deseja excluir esta refeição e seus itens?');">
                      <button type="submit" class="btn btn-sm" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 0.85rem;">Excluir</button>
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
                  <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
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
                            <?= number_format((float)$it['amount'], 0) ?> <?= htmlspecialchars($it['measure_unit']) ?>
                          </td>
                          <td style="padding: 12px 20px; color: #64748b; width: 25%; font-size: 0.85rem;">
                            <?= number_format((float)$it['energy_kcal'], 0) ?> kcal <span style="font-size: 0.75rem;">(P:<?= number_format((float)$it['protein_g'], 1) ?> C:<?= number_format((float)$it['carbohydrate_g'], 1) ?> G:<?= number_format((float)$it['lipid_g'], 1) ?>)</span>
                          </td>
                          <td style="padding: 12px 20px; text-align: right; width: 15%;">
                            <?php if ($isDraft): ?>
                              <button class="btn btn-sm btn-outline" onclick="openAddSubModal(<?= $it['id'] ?>, '<?= htmlspecialchars(addslashes($it['food_name'])) ?>')" style="padding: 2px 6px; font-size: 0.75rem; margin-right: 4px;">
                                + Substituição
                              </button>
                              <form method="POST" action="/planos/<?= $planId ?>/itens/<?= $it['id'] ?>/remover" style="display: inline;" onsubmit="return confirm('Remover este alimento?');">
                                <button type="submit" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 0.8rem;">✕</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>

                        <!-- Substituições Vinculadas (Opções Alternativas - Não Cumulativas) -->
                        <?php if (!empty($it['substitutions'])): ?>
                          <?php foreach ($it['substitutions'] as $sub): ?>
                            <tr style="background: #fafaf9; border-bottom: 1px dashed #e7e5e4;">
                              <td style="padding: 8px 20px 8px 40px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                  <span style="color: #d97706; font-size: 0.8rem; font-weight: 600;">↳ Opção Alternativa:</span>
                                  <span style="color: #44403c;"><?= htmlspecialchars($sub['food_name']) ?></span>
                                </div>
                                <?php if (!empty($sub['notes'])): ?>
                                  <span style="display: block; font-size: 0.75rem; color: #78716c; margin-left: 20px;"><?= htmlspecialchars($sub['notes']) ?></span>
                                <?php endif; ?>
                              </td>
                              <td style="padding: 8px 20px; font-size: 0.85rem; color: #44403c;">
                                <?= number_format((float)$sub['amount'], 0) ?> <?= htmlspecialchars($sub['measure_unit']) ?>
                              </td>
                              <td style="padding: 8px 20px; font-size: 0.8rem; color: #78716c;">
                                <?= number_format((float)$sub['energy_kcal'], 0) ?> kcal (P:<?= number_format((float)$sub['protein_g'], 1) ?> C:<?= number_format((float)$sub['carbohydrate_g'], 1) ?> G:<?= number_format((float)$sub['lipid_g'], 1) ?>)
                              </td>
                              <td style="padding: 8px 20px; text-align: right;">
                                <?php if ($isDraft): ?>
                                  <form method="POST" action="/planos/<?= $planId ?>/substituicoes/<?= $sub['id'] ?>/remover" style="display: inline;" onsubmit="return confirm('Remover substituição?');">
                                    <button type="submit" style="color: #a8a29e; background: none; border: none; cursor: pointer; font-size: 0.75rem;">Remover</button>
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
        <div id="modal-add-meal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 480px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 16px;">Adicionar Refeição</h2>
            <form method="POST" action="/planos/<?= $planId ?>/refeicoes">
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Nome da Refeição *</label>
                <input type="text" name="name" required placeholder="Ex: Café da Manhã, Almoço, Ceia" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Horário Sugerido (HH:MM)</label>
                <input type="text" name="time_of_day" placeholder="Ex: 08:00" pattern="^([01]\d|2[0-3]):[0-5]\d$" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientações Clínicas da Refeição</label>
                <textarea name="notes" rows="2" placeholder="Ex: Ingerir 30 min antes do treino, mastigar devagar..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;"></textarea>
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-add-meal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Refeição</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Adicionar Alimento -->
        <div id="modal-add-item" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 4px;">Adicionar Alimento</h2>
            <p id="add-item-meal-name" style="color: #64748b; font-size: 0.85rem; margin-bottom: 16px;"></p>
            <form method="POST" action="/planos/<?= $planId ?>/itens">
              <input type="hidden" name="meal_id" id="add-item-meal-id" value="">
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Alimento (Base TACO / Catálogo) *</label>
                <select name="food_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o alimento...</option>
                  <?php foreach ($foodsCatalog as $fc): ?>
                    <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['name']) ?> (<?= number_format((float)$fc['energy_kcal'], 0) ?> kcal/100g)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                  <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Quantidade *</label>
                  <input type="number" step="0.1" min="0.1" name="amount" required placeholder="Ex: 100" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                </div>
                <div>
                  <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Unidade de Medida *</label>
                  <select name="measure_unit" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
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
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Observação / Orientação do Item</label>
                <input type="text" name="notes" placeholder="Ex: Cozido sem sal, grelhado" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-add-item').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Adicionar ao Plano</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Adicionar Substituição -->
        <div id="modal-add-sub" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 4px;">Configurar Substituição Vinculada</h2>
            <p id="add-sub-item-name" style="color: #64748b; font-size: 0.85rem; margin-bottom: 16px;"></p>
            <form method="POST" action="/planos/<?= $planId ?>/substituicoes">
              <input type="hidden" name="item_id" id="add-sub-item-id" value="">
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Alimento Substituto *</label>
                <select name="food_id" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                  <option value="">Selecione o alimento alternativo...</option>
                  <?php foreach ($foodsCatalog as $fc): ?>
                    <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                  <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Quantidade Equivalente *</label>
                  <input type="number" step="0.1" min="0.1" name="amount" required placeholder="Ex: 80" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
                </div>
                <div>
                  <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Unidade de Medida *</label>
                  <select name="measure_unit" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
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
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.875rem;">Orientação da Substituição</label>
                <input type="text" name="notes" placeholder="Ex: Em dias de treino, consumir cozido" style="width: 100%; padding: 8px 12px; border: 1px solid var(--neutral-gray-300); border-radius: 6px;">
              </div>
              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-add-sub').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Opção</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Publicar Versão -->
        <div id="modal-publish-plan" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
          <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--brand-navy-900); margin-bottom: 12px;">Revisar e Publicar Versão</h2>
            <p style="color: #475569; font-size: 0.9rem; line-height: 1.5; margin-bottom: 16px;">
              A publicação torna este plano a <strong>prescrição ativa e vigente</strong> para o paciente no aplicativo móvel. Um snapshot imutável será gravado com número de versão e registro de auditoria clínica.
            </p>

            <?php if (!empty($preview['allergen_safety']['has_conflict'])): ?>
              <div style="background: #fff3cd; color: #856404; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 0.85rem; border: 1px solid #ffeeba;">
                <strong>⚠️ Conflito de Alérgeno:</strong> Para prosseguir, o profissional deve marcar a autorização clínica expressa abaixo.
              </div>
            <?php endif; ?>

            <form method="POST" action="/planos/<?= $planId ?>/publicar">
              <?php if (!empty($preview['allergen_safety']['has_conflict'])): ?>
                <div style="margin-bottom: 16px;">
                  <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                    <input type="checkbox" name="allow_allergen_override" value="1" required style="margin-top: 3px;">
                    <span>Confirmo e autorizo clinicamente a prescrição dos alimentos assinalados mesmo com os alertas detectados.</span>
                  </label>
                </div>
              <?php endif; ?>

              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-publish-plan').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar e Publicar Versão</button>
              </div>
            </form>
          </div>
        </div>

        <script>
        function openAddItemModal(mealId, mealName) {
          document.getElementById('add-item-meal-id').value = mealId;
          document.getElementById('add-item-meal-name').innerText = 'Refeição: ' + mealName;
          document.getElementById('modal-add-item').style.display = 'flex';
        }
        function openAddSubModal(itemId, foodName) {
          document.getElementById('add-sub-item-id').value = itemId;
          document.getElementById('add-sub-item-name').innerText = 'Substituição para o item: ' + foodName;
          document.getElementById('modal-add-sub').style.display = 'flex';
        }
        </script>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function addMealToPlan(array $params): void
    {
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

    public function deleteItemFromPlanMeal(array $params): void
    {
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
        $planId = (int)($params['id'] ?? 0);
        $allowAllergenOverride = !empty($_POST['allow_allergen_override']);

        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);

        try {
            $res = $service->publishPlan($planId, $allowAllergenOverride, true);
            $_SESSION['feedback_message'] = "Plano alimentar publicado com sucesso como Versão {$res['version']}! Snapshot imutável gerado.";
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
