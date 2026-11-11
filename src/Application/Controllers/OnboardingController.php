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
        // Substitui o "90%" fixo da NI2-603: o percentual e o "pronto para atender" vêm do checklist.
        $activation = (new \ClyoNutri\Domain\Onboarding\ActivationChecklist($this->db))->forUser((int)$auth['clinicId'], (int)$auth['userId']);
        $bookingState = $activation['booking_state'];
        $progress['progress_pct'] = $activation['pct']; // a barra "Progresso de Ativação" mostra o que está feito de fato

        $subscriptions = new SubscriptionService($this->db);
        $canChoosePlan = $step === 1 && $subscriptions->canChooseOnboardingPlan($auth['clinicId'], $auth['userId']);
        $availablePlans = $canChoosePlan ? $subscriptions->getOnboardingPlans() : [];

        $title = 'Configurar consultório';
        $activeNav = 'dashboard';
        $clinicName = $auth['clinicName'];
        $userName = $auth['userName'];
        $csrfToken = Csrf::token();

        ob_start();
        ?>
        <div style="max-width: 960px; margin: 0 auto;">
          <!-- Cabeçalho do Onboarding -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6); flex-wrap: wrap; gap: 12px;">
            <div>
              <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: var(--neutral-warm-50); color: var(--brand-copper-600); border: 1px solid var(--botanical-sage-200, #D3E5D5); padding: 2px 8px; border-radius: var(--radius-pill); display: inline-block; margin-bottom: 6px;">
                Ativação do Consultório
              </span>
              <h1 style="font-family: var(--font-editorial); font-size: 1.85rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                Bem-vindo ao Clyo Nutri, <?= htmlspecialchars(explode(' ', $auth['userName'])[0]) ?>!
              </h1>
              <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.95rem;">
                Configure seu consultório em 5 passos rápidos para começar seus atendimentos com segurança e conformidade.
              </p>
            </div>
            <div>
              <a href="/dashboard" style="color: var(--neutral-gray-500); text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Você pode retomar esta configuração a qualquer momento">
                Continuar depois e ir ao painel →
              </a>
            </div>
          </div>

          <!-- Barra de Progresso e Stepper Visual (Design System) -->
          <div class="card" style="padding: var(--space-5); margin-bottom: var(--space-6);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
              <span style="font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900);">Progresso de Ativação</span>
              <span style="font-size: 0.85rem; font-weight: 700; color: var(--brand-copper-600);"><?= (int)$progress['progress_pct'] ?>% Concluído</span>
            </div>
            <!-- Progress Bar -->
            <div style="height: 8px; background: var(--neutral-gray-200); border-radius: var(--radius-pill); overflow: hidden; margin-bottom: var(--space-5);">
              <div style="height: 100%; width: <?= (int)$progress['progress_pct'] ?>%; background: var(--brand-copper-500); transition: width 300ms ease;"></div>
            </div>

            <!-- Stepper com 5 Etapas -->
            <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; text-align: center;">
              <?php
              $stepsInfo = [
                  1 => ['title' => 'Boas-vindas', 'desc' => 'Foco clínico'],
                  2 => ['title' => 'Profissional', 'desc' => 'CRN & Registro'],
                  3 => ['title' => 'Atendimento', 'desc' => 'Preferências'],
                  4 => ['title' => '1º Paciente', 'desc' => 'Opcional'],
                  5 => ['title' => 'Ativação', 'desc' => 'Pronto para uso'],
              ];
              ?>
              <?php foreach ($stepsInfo as $sNum => $sInfo): ?>
                <?php
                $isCompleted = in_array($sNum, $progress['completed_steps'], true);
                $isCurrent = ($step === $sNum);
                ?>
                <a href="<?= ($isCompleted || $sNum <= $progress['current_step']) ? '/onboarding?passo=' . $sNum : '#' ?>" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; cursor: <?= ($isCompleted || $sNum <= $progress['current_step']) ? 'pointer' : 'default' ?>;">
                  <div style="width: 32px; height: 32px; border-radius: var(--radius-pill); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; margin-bottom: 4px; <?= $isCurrent ? 'background: var(--brand-navy-900); color: #fff; box-shadow: 0 0 0 3px rgba(239, 143, 79, 0.4);' : ($isCompleted ? 'background: var(--semantic-success); color: #fff;' : 'background: var(--neutral-gray-200); color: var(--neutral-gray-500);') ?>">
                    <?= $isCompleted ? '✓' : $sNum ?>
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
                <?php if ($canChoosePlan): ?>
                  <fieldset style="border: 0; padding: 0; margin: 0 0 var(--space-6);">
                    <legend style="font-family: var(--font-editorial); font-size: 1.4rem;">Escolha seu plano Clyo Nutri</legend>
                    <p id="onboarding-plan-help">Escolha o plano para seu período de teste. Esta seleção não inicia cobrança nem renova o prazo de avaliação.</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                      <?php foreach ($availablePlans as $plan): ?>
                        <label style="display: block; padding: 16px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-card); cursor: pointer;">
                          <input type="radio" name="plan_code" value="<?= htmlspecialchars($plan['code'], ENT_QUOTES, 'UTF-8') ?>" required aria-describedby="onboarding-plan-help" <?= ($progress['focus_objectives']['plan_code'] ?? '') === $plan['code'] ? 'checked' : '' ?>>
                          <strong><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                          <p>R$ <?= number_format((int)$plan['price_cents'] / 100, 2, ',', '.') ?> / <?= $plan['billing_cycle'] === 'yearly' ? 'ano' : 'mês' ?></p>
                          <p><?= (int)$plan['max_patients'] === 0 ? 'Pacientes ilimitados' : (int)$plan['max_patients'] . ' pacientes' ?><br>
                          <?= (int)$plan['max_professionals'] === 0 ? 'Profissionais ilimitados' : (int)$plan['max_professionals'] . ' profissional(is)' ?><br>
                          <?= number_format((int)$plan['storage_mb'] / 1024, 0, ',', '.') ?> GB de armazenamento</p>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <?php if (!$availablePlans): ?><p role="alert">Nenhum plano disponível. Entre em contato com o suporte para continuar.</p><?php endif; ?>
                  </fieldset>
                <?php endif; ?>
                <!-- PASSO 1: Boas-vindas & Objetivos -->
                <div style="margin-bottom: var(--space-5);">
                  <h3 style="font-family: var(--font-editorial); font-size: 1.4rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                    Passo 1 de 5: Qual é o foco principal dos seus atendimentos?
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Personalizaremos as calculadoras, templates de planos alimentares e referências clínicas de acordo com seu público.
                  </p>
                </div>

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
                <!-- PASSO 4: Criação do Primeiro Paciente Real -->
                <div style="margin-bottom: var(--space-5);">
                  <h3 style="font-family: var(--font-editorial); font-size: 1.4rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                    Passo 4 de 5: Primeiro paciente (opcional)
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
                <!-- PASSO 5: Orientação Contextual & Ativação Concluída -->
                <div style="text-align: center; padding: var(--space-4) 0 var(--space-6);">
                  <?php if ($activation['ready']): ?><div style="width: 64px; height: 64px; border-radius: var(--radius-pill); background: var(--semantic-success-light); color: var(--semantic-success); display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-4);" aria-hidden="true"><?= \ClyoNutri\Application\View\Icon::svg('circle-check', 32) ?></div><?php endif; ?>
                  <?php $pendReq = array_values(array_filter($activation['items'], static fn($i) => $i['required'] && $i['state'] === 'pending')); ?>
                  <h2 style="font-family: var(--font-editorial); font-size: 2rem; color: var(--brand-navy-900); margin-bottom: 8px;" data-testid="activation-title">
                    <?= $activation['ready'] ? 'Consultório pronto para atender.' : 'Quase pronto: ' . (count($pendReq) === 1 ? 'falta 1 item obrigatório.' : 'faltam ' . count($pendReq) . ' itens obrigatórios.') ?>
                  </h2>
                  <p style="color: var(--neutral-gray-600, #475467); font-size: 1rem; max-width: 620px; margin: 0 auto var(--space-5);">
                    Conferido agora no sistema: <strong data-testid="activation-count"><?= (int)$activation['done'] ?> de <?= (int)$activation['applicable'] ?></strong> itens feitos. Os três últimos são primeiros passos: não impedem começar.
                  </p>
                  <ol class="activation-list" data-testid="activation-checklist">
                    <?php foreach ($activation['items'] as $it): $stLabel = ['done' => 'Feito', 'pending' => $it['required'] ? 'Pendente' : 'A fazer', 'attention' => 'Feito, com aviso', 'not_applicable' => 'Não se aplica'][$it['state']]; ?>
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
                    <a href="/onboarding?passo=<?= $step - 1 ?>" class="btn btn-secondary">
                      ← Passo Anterior
                    </a>
                  <?php endif; ?>
                </div>

                <div style="display: flex; gap: var(--space-3); align-items: center; flex-wrap: wrap;">
                  <?php if ($step === 4): ?>
                    <button type="submit" name="skip_patient" value="1" formnovalidate class="btn btn-secondary">Cadastrar paciente depois</button>
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
        echo json_encode([
            'is_completed' => $progress['is_completed'],
            'current_step' => $progress['current_step'],
            'completed_steps' => $progress['completed_steps'],
            'progress_pct' => $progress['progress_pct'],
            'activation' => (new \ClyoNutri\Domain\Onboarding\ActivationChecklist($this->db))->forUser((int)$auth['clinicId'], (int)$auth['userId']), // NI2-625
        ]);
    }
}
