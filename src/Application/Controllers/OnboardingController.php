<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Onboarding\OnboardingService;
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

        $title = 'Configuração do Consultório — Onboarding';
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
              <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: var(--neutral-warm-50); color: var(--brand-copper-600); border: 1px solid #EAD8C7; padding: 2px 8px; border-radius: var(--radius-pill); display: inline-block; margin-bottom: 6px;">
                Ativação do Consultório
              </span>
              <h1 style="font-family: var(--font-editorial); font-size: 1.85rem; color: var(--brand-navy-900); margin-bottom: 4px;">
                Bem-vindo ao ClyoNutri, <?= htmlspecialchars(explode(' ', $auth['userName'])[0]) ?>!
              </h1>
              <p style="color: var(--neutral-gray-500); margin: 0; font-size: 0.95rem;">
                Configure seu consultório em 5 passos rápidos para começar seus atendimentos com segurança e conformidade.
              </p>
            </div>
            <div>
              <a href="/dashboard" style="color: var(--neutral-gray-500); text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Você pode retomar esta configuração a qualquer momento">
                Retomar Depois / Ir ao Dashboard →
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
                  4 => ['title' => '1º Paciente', 'desc' => 'Cadastro real'],
                  5 => ['title' => 'Ativação', 'desc' => 'Pronto para uso'],
              ];
              ?>
              <?php foreach ($stepsInfo as $sNum => $sInfo): ?>
                <?php
                $isCompleted = in_array($sNum, $progress['completed_steps'], true);
                $isCurrent = ($step === $sNum);
                ?>
                <a href="<?= ($isCompleted || $sNum <= $progress['current_step']) ? '/onboarding?passo=' . $sNum : '#' ?>" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; cursor: <?= ($isCompleted || $sNum <= $progress['current_step']) ? 'pointer' : 'default' ?>;">
                  <div style="width: 32px; height: 32px; border-radius: var(--radius-pill); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; margin-bottom: 4px; <?= $isCurrent ? 'background: var(--brand-navy-900); color: #fff; box-shadow: 0 0 0 3px rgba(230, 122, 63, 0.4);' : ($isCompleted ? 'background: var(--semantic-success); color: #fff;' : 'background: var(--neutral-gray-200); color: var(--neutral-gray-500);') ?>">
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
                  <p id="ob-grupo-1" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Áreas de Especialidade / Atuação (Selecione uma ou mais):</p>
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

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-6);">
                  <div>
                    <label for="ob1-monthly_patients_goal" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Meta de Pacientes Ativos / Mês</label>
                    <select id="ob1-monthly_patients_goal" name="monthly_patients_goal" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="15" <?= (($progress['focus_objectives']['monthly_patients_goal'] ?? 20) === 15) ? 'selected' : '' ?>>Até 15 pacientes/mês</option>
                      <option value="30" <?= (($progress['focus_objectives']['monthly_patients_goal'] ?? 20) === 30) ? 'selected' : '' ?>>15 a 30 pacientes/mês</option>
                      <option value="60" <?= (($progress['focus_objectives']['monthly_patients_goal'] ?? 20) === 60) ? 'selected' : '' ?>>30 a 60 pacientes/mês</option>
                      <option value="100" <?= (($progress['focus_objectives']['monthly_patients_goal'] ?? 20) === 100) ? 'selected' : '' ?>>Mais de 60 pacientes/mês</option>
                    </select>
                  </div>
                  <div>
                    <label for="ob2-clinical_experience_years" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Tempo de Atuação Clínica</label>
                    <select id="ob2-clinical_experience_years" name="clinical_experience_years" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="0">Recém-formado / Iniciando consultório</option>
                      <option value="2">1 a 3 anos de experiência</option>
                      <option value="5">3 a 7 anos de experiência</option>
                      <option value="10">Mais de 7 anos de experiência</option>
                    </select>
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
                    Passo 2 de 5: Validação do Registro Profissional (CFN / CRN)
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Os dados abaixo aparecem nos cabeçalhos de receitas, planos alimentares e atestados clínicos emitidos para seus pacientes.
                  </p>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div>
                    <label for="ob3-crn" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Número de Registro CRN *</label>
                    <input id="ob3-crn" type="text" name="crn" required value="<?= htmlspecialchars($profCurrent['crn'] ?? '') ?>" placeholder="Ex: 12345" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                  <div>
                    <label for="ob4-crn_region" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Região do CRN *</label>
                    <select id="ob4-crn_region" name="crn_region" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <?php for ($r = 1; $r <= 11; $r++): ?>
                        <option value="CRN-<?= $r ?>" <?= (($profCurrent['crn_region'] ?? 'CRN-3') === "CRN-{$r}") ? 'selected' : '' ?>>CRN-<?= $r ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                </div>

                <div style="margin-bottom: var(--space-4);">
                  <label for="ob5-specialty" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Especialidade / Título Principal</label>
                  <input id="ob5-specialty" type="text" name="specialty" value="<?= htmlspecialchars($profCurrent['specialty'] ?? 'Nutricionista Clínico') ?>" placeholder="Ex: Nutricionista Clínico e Esportivo - Pós-Graduado" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                </div>

                <div style="margin-bottom: var(--space-6);">
                  <label for="ob6-bio" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Apresentação / Minibiografia</label>
                  <textarea id="ob6-bio" name="bio" rows="3" placeholder="Breve resumo da sua metodologia de acompanhamento e formação..." style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);"><?= htmlspecialchars($profCurrent['bio'] ?? '') ?></textarea>
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
                  <p id="ob-grupo-2" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Modalidades Aceitas:</p>
                  <div role="group" aria-labelledby="ob-grupo-2" style="display: flex; gap: var(--space-4); flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" name="modalities[]" value="presencial" <?= in_array('presencial', $currentModalities, true) ? 'checked' : '' ?>>
                      🏢 Consultório Presencial
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer;">
                      <input type="checkbox" name="modalities[]" value="online" <?= in_array('online', $currentModalities, true) ? 'checked' : '' ?>>
                      💻 Teleconsulta / Online
                    </label>
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-5);">
                  <div>
                    <label for="ob7-default_duration_minutes" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Duração Padrão da Consulta</label>
                    <select id="ob7-default_duration_minutes" name="default_duration_minutes" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="30" <?= (($savedPref['default_duration_minutes'] ?? 60) === 30) ? 'selected' : '' ?>>30 minutos (Retorno rápido)</option>
                      <option value="45" <?= (($savedPref['default_duration_minutes'] ?? 60) === 45) ? 'selected' : '' ?>>45 minutos</option>
                      <option value="60" <?= (($savedPref['default_duration_minutes'] ?? 60) === 60) ? 'selected' : '' ?>>60 minutos (Recomendado para 1ª consulta)</option>
                      <option value="90" <?= (($savedPref['default_duration_minutes'] ?? 60) === 90) ? 'selected' : '' ?>>90 minutos (Consulta aprofundada)</option>
                    </select>
                  </div>
                  <div>
                    <label for="ob8-default_price" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Valor Médio da Consulta (R$)</label>
                    <input id="ob8-default_price" type="number" step="10" name="default_price" value="<?= htmlspecialchars((string)($savedPref['default_price'] ?? '250.00')) ?>" placeholder="250.00" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="margin-bottom: var(--space-6);">
                  <p id="ob-grupo-3" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: var(--space-2);">Dias de Atendimento na Semana:</p>
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
                    Passo 4 de 5: Cadastre o seu Primeiro Paciente
                  </h3>
                  <p style="color: var(--neutral-gray-500); font-size: 0.9rem;">
                    Pode ser um paciente real da sua agenda ou um paciente de teste para explorar os cálculos de anamnese, antropometria e planos.
                  </p>
                </div>

                <?php if ($firstPatient): ?>
                  <div style="background: var(--semantic-success-light); border: 1px solid #BBF7D0; border-radius: var(--radius-card); padding: var(--space-4); margin-bottom: var(--space-5);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                      <div>
                        <strong style="color: var(--semantic-success); font-size: 0.95rem; display: block;">✓ Paciente Cadastrado com Sucesso:</strong>
                        <span style="font-size: 1.1rem; font-weight: 700; color: var(--brand-navy-900);"><?= htmlspecialchars($firstPatient['name']) ?></span>
                        <div style="font-size: 0.85rem; color: var(--neutral-gray-500); margin-top: 2px;">
                          <?= htmlspecialchars($firstPatient['email'] ?? 'Sem e-mail') ?> • <?= htmlspecialchars($firstPatient['phone'] ?? 'Sem telefone') ?>
                        </div>
                      </div>
                      <a href="/pacientes/<?= (int)$firstPatient['id'] ?>" class="btn btn-secondary" style="min-height: 36px; font-size: 0.85rem;" target="_blank">
                        Ver perfil do paciente ↗
                      </a>
                    </div>
                  </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div>
                    <label for="ob9-name" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Nome Completo do Paciente *</label>
                    <input id="ob9-name" type="text" name="name" required value="<?= $firstPatient ? htmlspecialchars($firstPatient['name']) : '' ?>" placeholder="Ex: Roberto da Silva" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div>
                    <label for="ob10-email" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">E-mail do Paciente</label>
                    <input id="ob10-email" type="email" name="email" value="<?= $firstPatient ? htmlspecialchars($firstPatient['email'] ?? '') : '' ?>" placeholder="paciente@exemplo.com.br" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                  <div>
                    <label for="ob11-phone" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">WhatsApp / Telefone</label>
                    <input id="ob11-phone" type="tel" name="phone" value="<?= $firstPatient ? htmlspecialchars($firstPatient['phone'] ?? '') : '' ?>" placeholder="(11) 98888-7777" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                  </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-6);">
                  <div>
                    <label for="ob12-birth_date" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Data de Nascimento</label>
                    <input id="ob12-birth_date" type="date" name="birth_date" max="<?= date('Y-m-d') ?>" value="<?= $firstPatient ? htmlspecialchars($firstPatient['birth_date'] ?? '') : '' ?>" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); font-size: 0.95rem; font-family: var(--font-body);">
                    <span style="display: block; font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 4px;">Opcional. Usado para cálculo de idade e protocolos metabólicos.</span>
                  </div>
                  <div>
                    <label for="ob13-gender" style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--brand-navy-900); margin-bottom: 6px;">Gênero / Sexo Biológico</label>
                    <select id="ob13-gender" name="gender" aria-describedby="ob-gender-help" style="width: 100%; padding: 10px 14px; border: 1px solid var(--neutral-gray-300); border-radius: var(--radius-input); background: #fff; font-family: var(--font-body); font-size: 0.95rem;">
                      <option value="" <?= (!$firstPatient || empty($firstPatient['gender'])) ? 'selected' : '' ?>>Não informado (opcional)</option>
                      <option value="male" <?= ($firstPatient && ($firstPatient['gender'] ?? '') === 'male') ? 'selected' : '' ?>>Masculino</option>
                      <option value="female" <?= ($firstPatient && ($firstPatient['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Feminino</option>
                      <option value="other" <?= ($firstPatient && ($firstPatient['gender'] ?? '') === 'other') ? 'selected' : '' ?>>Outro</option>
                    </select>
                    <span id="ob-gender-help" style="display: block; font-size: 0.75rem; color: var(--neutral-gray-500); margin-top: 4px;">Opcional. Relevante para equações preditivas basais. No ClyoNutri, hoje é usado só para escolher os pontos de corte da relação cintura-quadril (OMS) na avaliação antropométrica; vazio ou &quot;Outro&quot; deixam essa classificação sem faixa. O sistema não deduz este dado de nenhum outro.</span>
                  </div>
                </div>

                <?php $term = $this->db->query("SELECT * FROM consent_terms WHERE purpose_code='clinical_care' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetch(); require __DIR__ . '/../../../views/patients/consent-choice.php'; ?>
              <?php elseif ($step === 5): ?>
                <!-- PASSO 5: Orientação Contextual & Ativação Concluída -->
                <div style="text-align: center; padding: var(--space-4) 0 var(--space-6);">
                  <div style="width: 64px; height: 64px; border-radius: var(--radius-pill); background: var(--semantic-success-light); color: var(--semantic-success); display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto var(--space-4);">
                    ✓
                  </div>
                  <h2 style="font-family: var(--font-editorial); font-size: 2rem; color: var(--brand-navy-900); margin-bottom: 8px;">
                    Parabéns! Seu Consultório Está Ativado!
                  </h2>
                  <p style="color: var(--neutral-gray-500); font-size: 1rem; max-width: 620px; margin: 0 auto var(--space-6);">
                    Sua conta está 100% configurada, suas regras de agendamento estão ativas e seus primeiros registros clínicos foram criados com segurança e governança LGPD.
                  </p>

                  <?php if ($firstPatient): ?>
                    <div style="background: var(--neutral-warm-50); border: 1px solid #EAD8C7; border-radius: var(--radius-card); padding: var(--space-4); margin: 0 auto var(--space-5); max-width: 620px; text-align: left; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                      <div>
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--brand-copper-600);">Primeiro Paciente Vinculado</div>
                        <div style="font-size: 1.05rem; font-weight: 700; color: var(--brand-navy-900);"><?= htmlspecialchars($firstPatient['name']) ?></div>
                        <div style="font-size: 0.85rem; color: var(--neutral-gray-500);"><?= htmlspecialchars($firstPatient['email'] ?? 'Sem e-mail') ?></div>
                      </div>
                      <a href="/pacientes/<?= (int)$firstPatient['id'] ?>" class="btn btn-secondary" style="min-height: 36px; font-size: 0.85rem;" target="_blank">
                        Abrir Prontuário 360 ↗
                      </a>
                    </div>
                  <?php endif; ?>

                  <!-- Cards de Ações Recomendadas -->
                  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: var(--space-4); text-align: left; margin-bottom: var(--space-6);">
                    <div style="background: var(--neutral-warm-50); border: 1px solid #EAD8C7; border-radius: var(--radius-card); padding: var(--space-4);">
                      <div style="font-size: 1.5rem; margin-bottom: 8px;">📅</div>
                      <strong style="color: var(--brand-navy-900); display: block; margin-bottom: 4px;">Agendar Consulta</strong>
                      <p style="color: var(--neutral-gray-500); font-size: 0.85rem; margin-bottom: var(--space-3);">Abra a agenda clínica e marque o primeiro atendimento com detecção anti-conflito.</p>
                      <a href="/agenda" class="btn btn-secondary" style="min-height: 36px; font-size: 0.85rem;">Ir para Agenda →</a>
                    </div>

                    <div style="background: var(--neutral-warm-50); border: 1px solid #EAD8C7; border-radius: var(--radius-card); padding: var(--space-4);">
                      <div style="font-size: 1.5rem; margin-bottom: 8px;">🥗</div>
                      <strong style="color: var(--brand-navy-900); display: block; margin-bottom: 4px;">Prescrever Plano Alimentar</strong>
                      <p style="color: var(--neutral-gray-500); font-size: 0.85rem; margin-bottom: var(--space-3);">Monte dietas com cálculos oficiais TACO, medidas caseiras e histórico de versões publicadas.</p>
                      <a href="/planos" class="btn btn-secondary" style="min-height: 36px; font-size: 0.85rem;">Criar Plano →</a>
                    </div>

                    <div style="background: var(--neutral-warm-50); border: 1px solid #EAD8C7; border-radius: var(--radius-card); padding: var(--space-4);">
                      <div style="font-size: 1.5rem; margin-bottom: 8px;">🍳</div>
                      <strong style="color: var(--brand-navy-900); display: block; margin-bottom: 4px;">Biblioteca de Receitas</strong>
                      <p style="color: var(--neutral-gray-500); font-size: 0.85rem; margin-bottom: var(--space-3);">Explore o catálogo culinário, crie variantes e prescreva receitas personalizadas.</p>
                      <a href="/receitas" class="btn btn-secondary" style="min-height: 36px; font-size: 0.85rem;">Ver Receitas →</a>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <!-- Rodapé de Ações do Formulário -->
              <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--neutral-gray-200); padding-top: var(--space-5);">
                <div>
                  <?php if ($step > 1): ?>
                    <a href="/onboarding?passo=<?= $step - 1 ?>" class="btn btn-secondary">
                      ← Passo Anterior
                    </a>
                  <?php endif; ?>
                </div>

                <div style="display: flex; gap: var(--space-3); align-items: center;">
                  <a href="/dashboard" style="color: var(--neutral-gray-500); text-decoration: none; font-size: 0.85rem;">
                    Pular por enquanto
                  </a>
                  <button type="submit" class="btn btn-accent" style="min-width: 180px;">
                    <?= ($step === 5) ? 'Concluir & Ir ao Dashboard ✓' : 'Salvar & Próximo Passo →' ?>
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
            echo "<br><br><a href='javascript:history.back()'>← Voltar e Corrigir</a>";
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
        ]);
    }
}
