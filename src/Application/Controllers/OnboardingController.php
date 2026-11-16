<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use PDO;

class OnboardingController
{
    private PDO $db;
    private OnboardingService $onboardingService;

    public function __construct(?PDO $db = null, ?OnboardingService $onboardingService = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->onboardingService = $onboardingService ?? new OnboardingService($this->db);
    }

    private function getAuthInfo(): array
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
        $userName = (string)($_SESSION['user_name'] ?? 'Nutricionista');

        if ($userId <= 0 || $clinicId <= 0) {
            if (!headers_sent()) {
                header('Location: /login');
            }
            exit;
        }

        $access=$this->db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id=u.id WHERE u.id=? AND cm.clinic_id=? AND u.role='nutritionist' AND u.status='active' AND cm.status='active' AND cm.role IN ('owner','admin','nutritionist')");
        $access->execute([$userId,$clinicId]);
        if (!$access->fetchColumn()) { http_response_code(403); echo 'Configuração não autorizada.'; exit; }

        // Buscar ID profissional
        $stmt = $this->db->prepare("SELECT id FROM professionals WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $profId = (int)$stmt->fetchColumn();

        // Buscar Nome da Clínica
        $cStmt = $this->db->prepare("SELECT name FROM clinics WHERE id = :cid LIMIT 1");
        $cStmt->execute(['cid' => $clinicId]);
        $clinicName = (string)($cStmt->fetchColumn() ?: ($_SESSION['clinic_name'] ?? 'Clínica Principal'));

        TenantContext::setClinicId($clinicId);
        TenantContext::set($clinicId, [
            'id' => $userId,
            'name' => $userName,
            'clinic_id' => $clinicId,
        ]);

        return [
            'userId' => $userId,
            'clinicId' => $clinicId,
            'userName' => $userName,
            'clinicName' => $clinicName,
            'profId' => $profId,
        ];
    }

    /**
     * Exibe o passo atual do Onboarding Guiado (Design System ClyoNutri)
     */
    public function show(array $params = []): void
    {
        $auth = $this->getAuthInfo();
        $progress = $this->onboardingService->getOrCreateProgress($auth['userId'], $auth['clinicId']);

        // Permitir navegação entre passos concluídos ou ver o passo atual
        $requestedStep = isset($_GET['passo']) ? (int)$_GET['passo'] : $progress['current_step'];
        $step = max(1, min(5, $requestedStep));

        $firstPatient = $progress['first_patient_id'] ? $this->onboardingService->getFirstPatient($auth['userId'], $auth['clinicId']) : null;

        // NI2-625: ativação conferida no banco (perfil, horários, link, primeiro paciente, consulta e plano).
        $activation = (new \ClyoNutri\Domain\Onboarding\ActivationChecklist($this->db))->forUser((int)$auth['clinicId'], (int)$auth['userId']);
        $bookingState = $activation['booking_state'];

        // NI2-850 (UX-006): fonte única de verdade (3 passos) para barra de progresso e contadores,
        // mantendo teto do checklist quando há item obrigatório pendente no passo final.
        $wizardSummary = OnboardingService::wizardSummary($progress);
        $progress['progress_pct'] = (!$activation['ready'] && $step === 5)
            ? (int)$activation['pct']
            : (int)$wizardSummary['pct'];

        // Buscar dados atuais do profissional no banco (pré-preenchidos no cadastro /register)
        $pStmt = $this->db->prepare("SELECT * FROM professionals WHERE user_id = :uid LIMIT 1");
        $pStmt->execute(['uid' => $auth['userId']]);
        $profCurrent = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hasPrefilledCrn = trim((string)($profCurrent['crn'] ?? '')) !== '' && trim((string)($profCurrent['crn_region'] ?? '')) !== '';

        // Se o usuário caiu em ?passo=2 ou ?passo=3 sem querystring explícita e já tem CRN no cadastro, redireciona ao fluxo de 3 passos
        if (!isset($_GET['passo']) && in_array($step, [2, 3], true) && $hasPrefilledCrn) {
            $step = in_array(3, $progress['completed_steps'] ?? [], true) ? 4 : 1;
        }

        $firstNameClean = \ClyoNutri\Domain\Dashboard\DashboardOverview::firstName($auth['userName']);
        if ($firstNameClean === '') {
            $firstNameClean = 'Nutricionista';
        }

        $title = 'Configurar consultório';
        $activeNav = 'dashboard';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        $visualStep = $step <= 3 ? 1 : ($step === 4 ? 2 : 3);

        ob_start();
        ?>
        <div style="max-width: 960px; margin: 0 auto;" data-testid="onboarding-wizard-3steps">
          <!-- Cabeçalho do Onboarding -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 12px;">
            <div>
              <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: var(--neutral-warm-50); color: var(--brand-copper-600); border: 1px solid var(--botanical-sage-200, #D3E5D5); padding: 2px 8px; border-radius: var(--radius-pill); display: inline-block; margin-bottom: 6px;">
                Ativação do Consultório
              </span>
              <h1 style="font-family: var(--font-editorial); font-size: 1.85rem; color: var(--brand-navy-900); margin-bottom: 4px;" data-testid="onboarding-welcome-title">
                Bem-vindo(a) ao Clyo Nutri, <?= htmlspecialchars($firstNameClean) ?>!
              </h1>
              <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.95rem;">
                Configure sua rotina de atendimento em 3 passos rápidos para começar seus atendimentos com tranquilidade.
              </p>
            </div>
            <div>
              <a href="/dashboard" style="color: var(--neutral-gray-500); text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Você pode retomar esta configuração a qualquer momento">
                Continuar depois e ir ao painel →
              </a>
            </div>
          </div>

          <p class="op-message op-message--info" data-testid="practice-readiness" data-ready="<?= $activation['ready'] ? '1' : '0' ?>">Prontidão para atender: <strong><?= $activation['ready'] ? 'Requisitos aplicáveis atendidos' : 'Há requisitos aplicáveis pendentes' ?></strong>. Suas preferências de rotina podem ser ajustadas a qualquer momento.</p>
          <!-- Barra de Progresso e Stepper Visual de 3 Passos (NI2-850 / UX-006) -->
          <div class="card" style="padding: var(--space-5); margin-bottom: var(--space-6);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
              <span style="font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900);" data-testid="onboarding-wizard-count">Progresso de Ativação · Passo <?= $visualStep ?> de 3 · <?= (int)$wizardSummary['done'] ?> de <?= (int)$wizardSummary['total'] ?> concluído(s)</span>
              <span style="font-size: 0.85rem; font-weight: 700; color: var(--brand-copper-600);" data-testid="onboarding-progress-pct"><?= (int)$progress['progress_pct'] ?>% Concluído</span>
            </div>
            <!-- Progress Bar -->
            <div style="height: 8px; background: var(--neutral-gray-200); border-radius: var(--radius-pill); overflow: hidden; margin-bottom: var(--space-5);">
              <div style="height: 100%; width: <?= (int)$progress['progress_pct'] ?>%; background: var(--brand-copper-500); transition: width 300ms ease;"></div>
            </div>

            <!-- Stepper com 3 Etapas -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; text-align: center;" data-testid="onboarding-stepper">
              <?php
              $stepsInfo = [
                  1 => ['title' => 'Como você atende', 'desc' => 'Foco, agenda e valores', 'target_step' => 1, 'done' => $wizardSummary['step_states'][1]],
                  2 => ['title' => 'Primeiro paciente', 'desc' => 'Opcional', 'target_step' => 4, 'done' => $wizardSummary['step_states'][2]],
                  3 => ['title' => 'Pronto para atender', 'desc' => 'Checklist do consultório', 'target_step' => 5, 'done' => $wizardSummary['step_states'][3]],
              ];
              ?>
              <?php foreach ($stepsInfo as $vNum => $sInfo): ?>
                <?php
                $isCompleted = (bool)$sInfo['done'];
                $isCurrent = ($visualStep === $vNum);
                $canNavigate = $isCompleted || $vNum <= $visualStep;
                ?>
                <a href="<?= $canNavigate ? '/onboarding?passo=' . (int)$sInfo['target_step'] : '#' ?>" data-testid="onboarding-step-link-<?= $vNum ?>" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; cursor: <?= $canNavigate ? 'pointer' : 'default' ?>;">
                  <div style="width: 32px; height: 32px; border-radius: var(--radius-pill); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; margin-bottom: 4px; <?= $isCurrent ? 'background: var(--brand-navy-900); color: #fff; box-shadow: 0 0 0 3px rgba(239, 143, 79, 0.4);' : ($isCompleted ? 'background: var(--semantic-success); color: #fff;' : 'background: var(--neutral-gray-200); color: var(--neutral-gray-500);') ?>">
                    <?= $isCompleted ? '✓' : $vNum ?>
                  </div>
                  <strong style="font-size: 0.8rem; color: <?= $isCurrent ? 'var(--brand-navy-900)' : 'var(--neutral-gray-500)' ?>; display: block; line-height: 1.2;">
                    <?= $sInfo['title'] ?>
                  </strong>
                  <span style="font-size: 0.7rem; color: var(--neutral-gray-500);"><?= $sInfo['desc'] ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Conteúdo da Etapa Atual -->
          <div class="card" style="padding: var(--space-6);">
            <form method="POST" action="/onboarding/passo">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="step" value="<?= $step ?>">

              <?php if ($step === 1): ?>
                <input type="hidden" name="combined_wizard" value="1">
                <div class="op-message op-message--info u-mb-5" data-testid="onboarding-trial-notice">
                  <strong>Período de avaliação gratuito ativo:</strong> você não precisa escolher plano pago nem informar cartão agora. Quando quiser consultar planos, acesse <a href="/configuracoes/assinatura">Configurações → Assinatura</a>.
                </div>

                <!-- PASSO 1 de 3: Como você atende (Foco + CRN validado no cadastro + Preferências) -->
                <div style="margin-bottom: var(--space-5);">
                  <h3 style="font-family: var(--font-editorial); font-size: 1.4rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                    Passo 1 de 3: Como você atende?
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Personalize o foco clínico, a duração padrão das consultas e os dias de atendimento.
                  </p>
                </div>

                <?php if ($hasPrefilledCrn): ?>
                  <div class="u-bg-neutral-gray-50 u-border u-radius-input u-py-3 u-px-4 u-mb-5" data-testid="onboarding-crn-prefilled">
                    <div class="u-flex u-justify-between u-items-center u-wrap u-gap-2">
                      <div>
                        <strong class="u-color-botanical-forest-900 u-text-compact">✓ Registro profissional já informado no cadastro:</strong>
                        <span class="badge badge-success u-ml-1"><?= htmlspecialchars($profCurrent['crn_region'] . ' ' . $profCurrent['crn']) ?></span>
                      </div>
                      <details class="u-text-compact">
                        <summary class="u-pointer u-color-botanical-copper-600 u-font-semibold">Editar CRN ou especialidade</summary>
                        <div class="u-grid u-cols-3 u-gap-3 u-mt-3">
                          <div>
                            <label for="ob3-crn" class="form-label">Número do CRN</label>
                            <input id="ob3-crn" type="text" name="crn" value="<?= htmlspecialchars($profCurrent['crn'] ?? '') ?>" class="form-control">
                          </div>
                          <div>
                            <label for="ob4-crn_region" class="form-label">Região do CRN</label>
                            <select id="ob4-crn_region" name="crn_region" class="form-control">
                              <?php for ($r = 1; $r <= 11; $r++): ?>
                                <option value="CRN-<?= $r ?>" <?= (($profCurrent['crn_region'] ?? 'CRN-3') === "CRN-{$r}") ? 'selected' : '' ?>>CRN-<?= $r ?></option>
                              <?php endfor; ?>
                            </select>
                          </div>
                          <div>
                            <label for="ob5-specialty" class="form-label">Especialidade principal</label>
                            <input id="ob5-specialty" type="text" name="specialty" value="<?= htmlspecialchars($profCurrent['specialty'] ?? 'Nutricionista clínico') ?>" class="form-control">
                          </div>
                        </div>
                      </details>
                    </div>
                  </div>
                <?php else: ?>
                  <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                    <div>
                      <label for="ob3-crn" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Número de Registro CRN *</label>
                      <input id="ob3-crn" type="text" name="crn" required value="<?= htmlspecialchars($profCurrent['crn'] ?? '') ?>" placeholder="Ex: 12345" class="form-control">
                    </div>
                    <div>
                      <label for="ob4-crn_region" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Região do CRN *</label>
                      <select id="ob4-crn_region" name="crn_region" required class="form-control">
                        <?php for ($r = 1; $r <= 11; $r++): ?>
                          <option value="CRN-<?= $r ?>" <?= (($profCurrent['crn_region'] ?? 'CRN-3') === "CRN-{$r}") ? 'selected' : '' ?>>CRN-<?= $r ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>
                  </div>
                <?php endif; ?>

                <div style="margin-bottom: var(--space-5);">
                  <p id="ob-grupo-1" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Áreas de especialidade / atuação (selecione uma ou mais):</p>
                  <div role="group" aria-labelledby="ob-grupo-1" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                    <?php
                    $focusOptions = [
                        'Nutrição Clínica Geral' => 'Emagrecimento, saúde e bem-estar',
                        'Nutrição Esportiva' => 'Hipertrofia, endurance e atletas',
                        'Doenças Crônicas' => 'Diabetes, hipertensão e síndrome metabólica',
                        'Nutrição Materno-Infantil' => 'Gestantes, lactantes e pediatria',
                        'Comportamental & Vegetariana' => 'Relação com a comida e dietas baseadas em plantas',
                        'Gastroenterologia Funcional' => 'FODMAPs, disbiose e saúde intestinal',
                    ];
                    $currentFocus = $progress['focus_objectives']['focus_objectives'] ?? ['Nutrição Clínica Geral'];
                    ?>
                    <?php foreach ($focusOptions as $fName => $fDesc): ?>
                      <label style="display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; cursor: pointer;">
                        <input type="checkbox" name="focus_objectives[]" value="<?= htmlspecialchars($fName) ?>" <?= in_array($fName, $currentFocus, true) ? 'checked' : '' ?> style="margin-top: 3px;">
                        <div>
                          <strong style="font-size: 0.88rem; color: var(--brand-navy-900); display: block;"><?= htmlspecialchars($fName) ?></strong>
                          <span style="font-size: 0.78rem; color: var(--neutral-gray-500);"><?= htmlspecialchars($fDesc) ?></span>
                        </div>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

                <?php
                $savedPref = $progress['practice_preferences'] ?? [];
                $currentModalities = $savedPref['modalities'] ?? ['presencial', 'online'];
                $currentDays = $savedPref['working_days'] ?? ['seg', 'ter', 'qua', 'qui', 'sex'];
                ?>
                <div style="margin-bottom: var(--space-5); padding-top: var(--space-4); border-top: 1px solid var(--neutral-gray-200);">
                  <p id="ob-grupo-2" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Modalidades de atendimento:</p>
                  <div role="group" aria-labelledby="ob-grupo-2" style="display: flex; gap: var(--space-4); flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" name="modalities[]" value="presencial" <?= in_array('presencial', $currentModalities, true) ? 'checked' : '' ?>>
                      Consultório presencial
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" name="modalities[]" value="online" <?= in_array('online', $currentModalities, true) ? 'checked' : '' ?>>
                      Teleconsulta / online
                    </label>
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-5);">
                  <div>
                    <label for="ob7-default_duration_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Duração padrão da consulta</label>
                    <select id="ob7-default_duration_minutes" name="default_duration_minutes" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="30" <?= (($savedPref['default_duration_minutes'] ?? 60) === 30) ? 'selected' : '' ?>>30 minutos (Retorno rápido)</option>
                      <option value="45" <?= (($savedPref['default_duration_minutes'] ?? 60) === 45) ? 'selected' : '' ?>>45 minutos</option>
                      <option value="60" <?= (($savedPref['default_duration_minutes'] ?? 60) === 60) ? 'selected' : '' ?>>60 minutos (Recomendado para 1ª consulta)</option>
                      <option value="90" <?= (($savedPref['default_duration_minutes'] ?? 60) === 90) ? 'selected' : '' ?>>90 minutos (Consulta aprofundada)</option>
                    </select>
                  </div>
                  <div>
                    <label for="ob8-default_price" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Valor médio da consulta (R$)</label>
                    <input id="ob8-default_price" type="text" inputmode="decimal" data-decimal-field="price" data-min="0" data-max="10000" name="default_price" value="<?= htmlspecialchars((string)($savedPref['default_price'] ?? '250.00')) ?>" placeholder="250,00" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="margin-bottom: var(--space-6);">
                  <p id="ob-grupo-3" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Dias de atendimento na semana:</p>
                  <div role="group" aria-labelledby="ob-grupo-3" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php
                    $weekDays = ['seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado'];
                    ?>
                    <?php foreach ($weekDays as $wKey => $wLabel): ?>
                      <label style="padding: 8px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.85rem;">
                        <input type="checkbox" name="working_days[]" value="<?= $wKey ?>" <?= in_array($wKey, $currentDays, true) ? 'checked' : '' ?>>
                        <?= $wLabel ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

              <?php elseif ($step === 2): ?>
                <!-- PASSO 2: Dados Profissionais Reais -->
                <?php
                // Buscar dados atuais do profissional no banco
                $pStmt = $this->db->prepare("SELECT * FROM professionals WHERE user_id = :uid LIMIT 1");
                $pStmt->execute(['uid' => $auth['userId']]);
                $profCurrent = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                ?>
                <div style="margin-bottom: var(--space-5);">
                  <h3 style="font-family: var(--font-editorial); font-size: 1.4rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                    Passo 2 de 5: validação do registro profissional (CFN / CRN)
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Os dados abaixo aparecem nos cabeçalhos de receitas, planos alimentares e atestados clínicos emitidos para seus pacientes.
                  </p>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div>
                    <label for="ob3-crn" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Número de Registro CRN *</label>
                    <input id="ob3-crn" type="text" name="crn" required value="<?= htmlspecialchars($profCurrent['crn'] ?? '') ?>" placeholder="Ex: 12345" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                  <div>
                    <label for="ob4-crn_region" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Região do CRN *</label>
                    <select id="ob4-crn_region" name="crn_region" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <?php for ($r = 1; $r <= 11; $r++): ?>
                        <option value="CRN-<?= $r ?>" <?= (($profCurrent['crn_region'] ?? 'CRN-3') === "CRN-{$r}") ? 'selected' : '' ?>>CRN-<?= $r ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                </div>

                <div style="margin-bottom: var(--space-4);">
                  <label for="ob5-specialty" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Especialidade / título principal</label>
                  <input id="ob5-specialty" type="text" name="specialty" value="<?= htmlspecialchars($profCurrent['specialty'] ?? 'Nutricionista clínico') ?>" placeholder="Ex: Nutricionista Clínico e Esportivo - Pós-Graduado" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                </div>

                <div style="margin-bottom: var(--space-6);">
                  <label for="ob6-bio" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Apresentação / minibiografia</label>
                  <textarea id="ob6-bio" name="bio" rows="3" placeholder="Breve resumo da sua metodologia de acompanhamento e formação..." style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);"><?= htmlspecialchars($profCurrent['bio'] ?? '') ?></textarea>
                </div>

              <?php elseif ($step === 3): ?>
                <!-- PASSO 3: Preferências de Atendimento -->
                <?php
                $savedPref = $progress['practice_preferences'] ?? [];
                $currentModalities = $savedPref['modalities'] ?? ['presencial', 'online'];
                $currentDays = $savedPref['working_days'] ?? ['seg', 'ter', 'qua', 'qui', 'sex'];
                ?>
                <div style="margin-bottom: var(--space-5);">
                  <h3 style="font-family: var(--font-editorial); font-size: 1.4rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                    Passo 3 de 5: Como você organiza seus atendimentos?
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Configuração padrão para slots de agenda, teleconsultas e estimativa de honorários.
                  </p>
                </div>

                <div style="margin-bottom: var(--space-5);">
                  <p id="ob-grupo-2" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Modalidades aceitas:</p>
                  <div role="group" aria-labelledby="ob-grupo-2" style="display: flex; gap: var(--space-4); flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" name="modalities[]" value="presencial" <?= in_array('presencial', $currentModalities, true) ? 'checked' : '' ?>>
                      Consultório presencial
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" name="modalities[]" value="online" <?= in_array('online', $currentModalities, true) ? 'checked' : '' ?>>
                      Teleconsulta / online
                    </label>
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-5);">
                  <div>
                    <label for="ob7-default_duration_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Duração padrão da consulta</label>
                    <select id="ob7-default_duration_minutes" name="default_duration_minutes" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="30" <?= (($savedPref['default_duration_minutes'] ?? 60) === 30) ? 'selected' : '' ?>>30 minutos (Retorno rápido)</option>
                      <option value="45" <?= (($savedPref['default_duration_minutes'] ?? 60) === 45) ? 'selected' : '' ?>>45 minutos</option>
                      <option value="60" <?= (($savedPref['default_duration_minutes'] ?? 60) === 60) ? 'selected' : '' ?>>60 minutos (Recomendado para 1ª consulta)</option>
                      <option value="90" <?= (($savedPref['default_duration_minutes'] ?? 60) === 90) ? 'selected' : '' ?>>90 minutos (Consulta aprofundada)</option>
                    </select>
                  </div>
                  <div>
                    <label for="ob8-default_price" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Valor Médio da Consulta (R$)</label>
                    <input id="ob8-default_price" type="number" step="any" min="0" name="default_price" value="<?= htmlspecialchars((string)($savedPref['default_price'] ?? '250.00')) ?>" placeholder="250.00" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="margin-bottom: var(--space-6);">
                  <p id="ob-grupo-3" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Dias de atendimento na semana:</p>
                  <div role="group" aria-labelledby="ob-grupo-3" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php
                    $weekDays = ['seg' => 'Segunda', 'ter' => 'Terça', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sábado'];
                    ?>
                    <?php foreach ($weekDays as $wKey => $wLabel): ?>
                      <label style="padding: 8px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.85rem;">
                        <input type="checkbox" name="working_days[]" value="<?= $wKey ?>" <?= in_array($wKey, $currentDays, true) ? 'checked' : '' ?>>
                        <?= $wLabel ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

              <?php elseif ($step === 4): ?>
                <!-- PASSO 2 de 3 (etapa 4): Criação do Primeiro Paciente Real -->
                <div style="margin-bottom: var(--space-5);">
                  <h3 style="font-family: var(--font-editorial); font-size: 1.4rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                    Passo 2 de 3: Primeiro paciente (opcional)
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Você pode cadastrar seu primeiro paciente agora ou escolher “Cadastrar paciente depois” para continuar. O cadastro ficará disponível na área Pacientes.
                  </p>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem; margin-top: 6px;">
                    Já tem os pacientes numa planilha? Depois de concluir, use <a href="/pacientes/importar" data-testid="onboarding-import-link">Importar pacientes</a> em Pacientes, com prévia antes de gravar.
                  </p>
                </div>

                <?php if ($firstPatient): ?>
                  <div style="background: var(--semantic-success-light); border: 1px solid var(--semantic-success-border, #B7DFDC); border-radius: var(--radius-card); padding: var(--space-4); margin-bottom: var(--space-5);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                      <div>
                        <strong style="color: var(--semantic-success); font-size: 0.95rem; display: block;">✓ Paciente cadastrado com sucesso:</strong>
                        <span style="font-size: 1.1rem; font-weight: 700; color: var(--brand-navy-900);"><?= htmlspecialchars($firstPatient['name']) ?></span>
                        <?php /* NI2-617: resumo sem e-mail e telefone (minimização LGPD); os dados ficam nos campos abaixo, para edição. */ ?>
                      </div>
                      <a href="/pacientes/<?= (int)$firstPatient['id'] ?>" class="btn btn-secondary" style="min-height: 36px; font-size: 0.85rem;" target="_blank">
                        Ver perfil do paciente ↗
                      </a>
                    </div>
                  </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div>
                    <label for="ob9-name" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Nome completo do paciente *</label>
                    <input id="ob9-name" type="text" name="name" required value="<?= $firstPatient ? htmlspecialchars($firstPatient['name']) : '' ?>" placeholder="Ex: Roberto da Silva" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div>
                    <label for="ob10-email" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">E-mail do Paciente</label>
                    <input id="ob10-email" type="email" name="email" value="<?= $firstPatient ? htmlspecialchars($firstPatient['email'] ?? '') : '' ?>" placeholder="paciente@exemplo.com.br" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                  <div>
                    <label for="ob11-phone" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">WhatsApp / telefone</label>
                    <input id="ob11-phone" type="tel" name="phone" value="<?= $firstPatient ? htmlspecialchars($firstPatient['phone'] ?? '') : '' ?>" placeholder="(11) 98888-7777" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-6);">
                  <div>
                    <label for="ob12-birth_date" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Data de Nascimento</label>
                    <input id="ob12-birth_date" type="date" name="birth_date" max="<?= date('Y-m-d') ?>" value="<?= $firstPatient ? htmlspecialchars($firstPatient['birth_date'] ?? '') : '' ?>" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                    <span style="display: block; font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 4px;">Opcional. Usado para cálculo de idade e protocolos metabólicos.</span>
                  </div>
                  <div>
                    <label for="ob13-gender" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Sexo registrado para referências clínicas</label>
                    <select id="ob13-gender" name="gender" aria-describedby="ob-gender-help" style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-input, #828B9B); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="" <?= (!$firstPatient || empty($firstPatient['gender'])) ? 'selected' : '' ?>>Não informado (opcional)</option>
                      <option value="male" <?= ($firstPatient && ($firstPatient['gender'] ?? '') === 'male') ? 'selected' : '' ?>>Masculino</option>
                      <option value="female" <?= ($firstPatient && ($firstPatient['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Feminino</option>
                      <option value="other" <?= ($firstPatient && ($firstPatient['gender'] ?? '') === 'other') ? 'selected' : '' ?>>Outro</option>
                    </select>
                    <span id="ob-gender-help" style="display: block; font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 4px;">Opcional. Relevante para equações preditivas basais. O parâmetro usado nas equações não define a identidade de gênero. Quando confirmado pelo profissional, pode ser usado na classificação da RCQ e na elegibilidade de protocolos de dobras, junto com a idade. Sem os dados exigidos, a classificação ou equação fica indisponível; confirme o parâmetro no contexto da avaliação. Não o deduza da identidade de gênero.</span>
                  </div>
                </div>

                <?php $term = $this->db->query("SELECT * FROM consent_terms WHERE purpose_code='clinical_care' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetch(); require __DIR__ . '/../../../views/patients/consent-choice.php'; ?>
              <?php elseif ($step === 5): ?>
                <!-- PASSO 3 de 3 (etapa 5): Orientação Contextual & Ativação Concluída -->
                <?php
                $applicableItems = array_values(array_filter($activation['items'], static fn($i) => $i['state'] !== 'not_applicable'));
                $notApplicableItems = array_values(array_filter($activation['items'], static fn($i) => $i['state'] === 'not_applicable'));
                ?>
                <div style="text-align: center; padding: var(--space-4) 0 var(--space-6);">
                  <?php if ($activation['ready']): ?><div style="width: 64px; height: 64px; border-radius: var(--radius-pill); background: var(--semantic-success-light); color: var(--semantic-success); display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-4);" aria-hidden="true"><?= \ClyoNutri\Application\View\Icon::svg('circle-check', 32) ?></div><?php endif; ?>
                  <?php $pendReq = array_values(array_filter($activation['items'], static fn($i) => $i['required'] && $i['state'] === 'pending')); ?>
                  <h2 style="font-family: var(--font-editorial); font-size: 2rem; color: var(--brand-navy-900); margin-bottom: 8px;" data-testid="activation-title">
                    <?= $activation['ready'] ? 'Consultório pronto para atender.' : 'Quase pronto: ' . (count($pendReq) === 1 ? 'falta 1 item obrigatório.' : 'faltam ' . count($pendReq) . ' itens obrigatórios.') ?>
                  </h2>
                  <p style="color: var(--neutral-gray-600, #475467); font-size: 1rem; max-width: 620px; margin: 0 auto var(--space-5);">
                    Conferido agora no sistema: <strong data-testid="activation-count"><?= (int)$activation['done'] ?> de <?= (int)$activation['applicable'] ?></strong> itens aplicáveis feitos. Os três últimos são primeiros passos: não impedem começar.
                  </p>
                  <ol class="activation-list" data-testid="activation-checklist">
                    <?php foreach ($applicableItems as $it): $stLabel = ['done' => 'Feito', 'pending' => $it['required'] ? 'Pendente' : 'A fazer', 'attention' => 'Feito, com aviso', 'not_applicable' => 'Não se aplica'][$it['state']]; ?>
                      <li class="activation-item activation-item--<?= htmlspecialchars($it['state']) ?>" data-testid="activation-<?= htmlspecialchars($it['key']) ?>" data-state="<?= htmlspecialchars($it['state']) ?>">
                        <span class="activation-item__mark" aria-hidden="true"><?= \ClyoNutri\Application\View\Icon::svg($it['state'] === 'done' ? 'circle-check' : ($it['state'] === 'attention' ? 'triangle-alert' : ($it['state'] === 'not_applicable' ? 'minus' : 'circle')), 18) ?></span>
                        <span class="activation-item__body">
                          <strong><?= htmlspecialchars($it['label']) ?></strong><?= $it['required'] ? '' : ' <span class="activation-item__tag">primeiro passo</span>' ?>
                          <span class="activation-item__state"><?= $stLabel ?></span>
                          <span class="activation-item__detail"<?= $it['key'] === 'hours' && $it['state'] === 'pending' ? ' data-testid="onboarding-booking-pending" role="alert"' : '' ?>><?= htmlspecialchars($it['detail']) ?></span>
                        </span>
                        <?php if ($it['action_url'] && $it['state'] !== 'done'): ?>
                          <a class="btn <?= $it['state'] === 'pending' && $it['required'] ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="<?= htmlspecialchars($it['action_url']) ?>"<?= $it['key'] === 'hours' ? ' data-testid="onboarding-booking-activate"' : '' ?>><?= htmlspecialchars((string)$it['action_label']) ?></a>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                  <?php if ($notApplicableItems !== []): ?>
                    <details class="u-mt-4" style="max-width: 620px; margin: 16px auto 0; text-align: left;" data-testid="activation-optional-disabled">
                      <summary class="u-pointer u-text-compact u-color-neutral-gray-600 u-font-semibold">Recursos opcionais desativados (<?= count($notApplicableItems) ?> — fora da contagem de <?= (int)$activation['applicable'] ?> itens)</summary>
                      <ul class="activation-list u-mt-2">
                        <?php foreach ($notApplicableItems as $it): ?>
                          <li class="activation-item activation-item--not_applicable" data-testid="activation-<?= htmlspecialchars($it['key']) ?>" data-state="not_applicable">
                            <span class="activation-item__mark" aria-hidden="true"><?= \ClyoNutri\Application\View\Icon::svg('minus', 18) ?></span>
                            <span class="activation-item__body">
                              <strong><?= htmlspecialchars($it['label']) ?></strong>
                              <span class="activation-item__state">Não se aplica</span>
                              <span class="activation-item__detail"><?= htmlspecialchars($it['detail']) ?></span>
                            </span>
                            <?php if ($it['action_url']): ?>
                              <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($it['action_url']) ?>"<?= $it['key'] === 'hours' ? ' data-testid="onboarding-booking-activate"' : '' ?>><?= htmlspecialchars((string)$it['action_label']) ?></a>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  <?php endif; ?>

                  <?php if ($firstPatient): ?>
                    <p style="max-width: 620px; margin: var(--space-4) auto 0; text-align: left;">Primeiro paciente vinculado: <a href="/pacientes/<?= (int)$firstPatient['id'] ?>"><?= htmlspecialchars($firstPatient['name']) ?></a>.</p>
                    <?php /* NI2-617: só o nome; e-mail e telefone não aparecem nesta tela (minimização LGPD). */ ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <!-- Rodapé de Ações do Formulário -->
              <div class="onboarding-footer" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-3); border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-5);">
                <div>
                  <?php if ($step > 1): ?>
                    <?php $prevStepTarget = ($step === 4) ? 1 : ($step - 1); ?>
                    <a href="/onboarding?passo=<?= $prevStepTarget ?>" class="btn btn-secondary">
                      ← Passo Anterior
                    </a>
                  <?php endif; ?>
                </div>

                <div style="display: flex; gap: var(--space-3); align-items: center; flex-wrap: wrap;">
                  <?php if ($step === 4): ?>
                    <button type="submit" name="skip_patient" value="1" formnovalidate class="btn btn-secondary" data-testid="onboarding-skip-patient">Cadastrar paciente depois</button>
                  <?php else: ?>
                  <a href="/dashboard" style="color: var(--neutral-gray-500); text-decoration: none; font-size: 0.85rem;">
                    Pular por enquanto
                  </a>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-accent" style="min-width: min(180px, 100%);">
                    <?= ($step === 5) ? 'Concluir e ir ao painel' : 'Salvar e continuar →' ?>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    /**
     * Processa a submissão de cada passo do Onboarding (POST)
     */
    public function submitStep(array $params = []): void
    {
        $auth = $this->getAuthInfo();

        $csrfToken = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Csrf::validate($csrfToken)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo "Token de segurança CSRF inválido ou expirado.";
            return;
        }

        $step = (int)($_POST['step'] ?? 1);

        try {
            $updated = $this->onboardingService->saveStep($auth['userId'], $auth['clinicId'], $step, $_POST);

            // NI2-850 (UX-006): No fluxo enxuto de 3 passos, o Passo 1 ("Como você atende") já traz o CRN do cadastro
            // e as preferências de atendimento, avançando diretamente para o Passo 2 de 3 (?passo=4, Primeiro paciente).
            if ($step === 1 && !empty($_POST['combined_wizard'])) {
                $pStmt = $this->db->prepare("SELECT crn, crn_region, specialty, bio FROM professionals WHERE user_id = :uid LIMIT 1");
                $pStmt->execute(['uid' => $auth['userId']]);
                $profRow = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $crnVal = trim((string)($_POST['crn'] ?? ($profRow['crn'] ?? '')));
                $regVal = trim((string)($_POST['crn_region'] ?? ($profRow['crn_region'] ?? 'CRN-3')));
                $specVal = trim((string)($_POST['specialty'] ?? ($profRow['specialty'] ?? 'Nutrição Clínica Geral')));
                $bioVal = trim((string)($_POST['bio'] ?? ($profRow['bio'] ?? '')));
                if ($crnVal !== '') {
                    $this->onboardingService->saveStep($auth['userId'], $auth['clinicId'], 2, [
                        'crn' => $crnVal,
                        'crn_region' => $regVal !== '' ? $regVal : 'CRN-3',
                        'specialty' => $specVal !== '' ? $specVal : 'Nutrição Clínica Geral',
                        'bio' => $bioVal,
                    ]);
                }
                $updated = $this->onboardingService->saveStep($auth['userId'], $auth['clinicId'], 3, $_POST);
                if (!headers_sent()) {
                    header('Location: /onboarding?passo=4');
                }
                exit;
            }

            if ($step >= 5 || $updated['is_completed']) {
                if (!headers_sent()) {
                    header('Location: /dashboard?onboarding=concluido');
                }
                exit;
            }

            if (!headers_sent()) {
                header('Location: /onboarding?passo=' . ($step + 1));
            }
            exit;
        } catch (\Throwable $e) {
            if (!headers_sent()) {
                http_response_code(400);
            }
            echo "Erro ao processar etapa #{$step}: " . htmlspecialchars($e->getMessage());
            echo "<br><br><a href='#' data-history-back>← Voltar e Corrigir</a>";
        }
    }

    /**
     * Retorna o status de conclusão do onboarding via API JSON
     */
    public function apiStatus(array $params = []): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $auth = $this->getAuthInfo();

        $progress = $this->onboardingService->getOrCreateProgress($auth['userId'], $auth['clinicId']);
        $wizardSummary = OnboardingService::wizardSummary($progress);
        echo json_encode([
            'is_completed' => $progress['is_completed'],
            'current_step' => $progress['current_step'],
            'completed_steps' => $progress['completed_steps'],
            'progress_pct' => $progress['progress_pct'],
            'wizard' => $wizardSummary,
            'activation' => (new \ClyoNutri\Domain\Onboarding\ActivationChecklist($this->db))->forUser((int)$auth['clinicId'], (int)$auth['userId']), // NI2-625
        ]);
    }
}
