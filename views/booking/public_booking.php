<?php
/** @var array $professionals */
/** @var array|null $selectedProf */
/** @var int $profId */
/** @var int $clinicId */
/** @var array $settings */

$nonce = \ClyoNutri\Core\Http\SecurityHeaders::nonce();
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agendar Consulta — Clyo Nutri</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--neutral-gray-50, #F9FAFB);
      color: var(--neutral-ink-900, #1F2937);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .booking-header {
      background: #fff;
      border-bottom: 1px solid var(--neutral-gray-200, #E5E7EB);
      padding: 16px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .booking-header__brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: var(--botanical-forest-900, #0F3E17);
      font-weight: 700;
      font-size: 1.15rem;
    }
    .booking-container {
      max-width: 720px;
      width: 100%;
      margin: 32px auto;
      padding: 0 16px;
      box-sizing: border-box;
      flex: 1;
    }
    .booking-card {
      background: #fff;
      border: 1px solid var(--neutral-gray-200, #E5E7EB);
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .step-indicator {
      display: flex;
      justify-content: space-between;
      margin-bottom: 28px;
      position: relative;
    }
    .step-pill {
      flex: 1;
      text-align: center;
      padding: 8px 4px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--neutral-gray-600, #475467);
      border-bottom: 3px solid var(--neutral-gray-200, #E5E7EB);
    }
    .step-pill.active {
      color: var(--botanical-green-600, #39734A);
      border-bottom-color: var(--botanical-green-600, #39734A);
    }
    .step-pill.completed {
      color: var(--semantic-success, #0A7075);
      border-bottom-color: var(--semantic-success, #0A7075);
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-label {
      display: block;
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--neutral-gray-700, #344054);
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--border-input, #828B9B);
      border-radius: 8px;
      font-size: 0.95rem;
      box-sizing: border-box;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--botanical-green-600, #39734A);
      box-shadow: 0 0 0 3px rgba(57, 115, 74, 0.20);
    }
    .modality-picker {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 20px;
    }
    .modality-card {
      border: 2px solid var(--neutral-gray-200, #E5E7EB);
      border-radius: 12px;
      padding: 16px;
      cursor: pointer;
      text-align: center;
      transition: all 0.2s ease;
    }
    .modality-card:hover {
      border-color: var(--botanical-sage-300, #BFD8C2);
    }
    .modality-card.selected {
      border-color: var(--botanical-green-600, #39734A);
      background: var(--botanical-sage-100, #E5F0E5);
    }
    .slots-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
      gap: 10px;
      margin-top: 14px;
      max-height: 240px;
      overflow-y: auto;
      padding: 4px;
    }
    .slot-btn {
      padding: 10px 8px;
      background: var(--neutral-gray-50, #F9FAFB);
      border: 1px solid var(--neutral-gray-300, #D1D5DB);
      border-radius: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--botanical-forest-900, #0F3E17);
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .slot-btn:hover {
      background: var(--botanical-green-600, #39734A);
      color: #fff;
      border-color: var(--botanical-green-600, #39734A);
    }
    .slot-btn.selected {
      background: var(--botanical-green-600, #39734A);
      color: #fff;
      border-color: var(--botanical-green-600, #39734A);
    }
    .hold-banner {
      background: var(--semantic-warning-light, #FEF3C7);
      border: 1px solid var(--semantic-warning-border, #FEDF89);
      color: var(--semantic-warning-strong, #7A4A00);
      padding: 14px 18px;
      border-radius: 10px;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.9rem;
    }
    .hold-timer {
      font-weight: 700;
      font-size: 1.1rem;
      color: var(--semantic-warning, #946200);
      font-family: monospace;
    }
    .btn-submit {
      width: 100%;
      padding: 14px;
      background: var(--botanical-green-600, #39734A);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .btn-submit:hover {
      background: var(--botanical-green-700, #2F6140);
    }
    .btn-cancel-hold {
      background: none;
      border: 1px solid var(--neutral-gray-300, #D1D5DB);
      color: var(--neutral-gray-600, #475467);
      padding: 8px 12px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 0.85rem;
    }
    .alert-box {
      padding: 14px 16px;
      border-radius: 8px;
      font-size: 0.9rem;
      margin-bottom: 20px;
    }
    .alert-error {
      background: var(--semantic-error-light, #FEE2E2);
      border-left: 4px solid var(--semantic-error, #B42318);
      color: var(--semantic-error-strong, #912018);
    }
    .alert-success {
      background: var(--semantic-success-light, #E3F4F2);
      border-left: 4px solid var(--semantic-success, #0A7075);
      color: var(--semantic-success-text, #0A5F63);
    }
  </style>
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-30">
</head>
<body>
  <header class="booking-header">
    <a href="/agendar" class="booking-header__brand">
      <span style="color: var(--botanical-copper-600, #B04A1B);">Clyo</span>Nutri
      <span style="font-size: 0.8rem; background: var(--botanical-sage-100, #E5F0E5); color: var(--botanical-forest-900, #0F3E17); padding: 2px 8px; border-radius: 9999px; font-weight: 500;">Autoagendamento</span>
    </a>
    <div>
      <a href="/paciente/app" style="color: var(--neutral-gray-600, #475467); text-decoration: none; font-size: 0.9rem; font-weight: 500;">
        Já sou paciente
      </a>
    </div>
  </header>

  <main class="booking-container">
    <div class="booking-card">

      <?php if (!empty($bookingUnavailable)): ?>
        <?php /* NI2-603: agenda online sem horários confirmados. Nada de "escolha outro dia". */ ?>
        <div class="op-message op-message--info alert-box" role="status" data-testid="booking-unavailable" style="text-align: center; padding: 28px 20px;">
          <div style="color: var(--botanical-forest-900, #0F3E17); margin-bottom: 8px;"><?= \ClyoNutri\Application\View\Icon::svg('calendar', 32) ?></div>
          <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 8px; color: var(--botanical-forest-900, #0F3E17);">Agenda online ainda não disponível</h2>
          <p style="margin: 0; color: var(--neutral-gray-600, #475467);">
            <?= htmlspecialchars((string)($selectedProf['name'] ?? 'O profissional')) ?> ainda não liberou horários para agendamento pelo link.
            Fale com o consultório para marcar sua consulta.
          </p>
        </div>
      <?php elseif (!empty($bookingNoSlotsSoon)): ?>
        <div class="op-message op-message--info alert-box" role="status" data-testid="booking-no-slots-soon" style="margin-bottom: 16px;">
          Não há horários livres nos próximos 14 dias. Você pode escolher uma data mais adiante<?= !empty($waitlistEnabled) ? ', entrar na lista de espera' : '' ?> ou falar com o consultório.
        </div>
      <?php endif; ?>

      <div id="booking-wizard" <?= !empty($bookingUnavailable) ? 'hidden' : '' ?>>
      <div class="step-indicator">
        <div class="step-pill active" id="pill-step-1">1. Especialista & Modalidade</div>
        <div class="step-pill" id="pill-step-2">2. Data & Horário</div>
        <div class="step-pill" id="pill-step-3">3. Seus Dados</div>
        <div class="step-pill" id="pill-step-4">4. Confirmação</div>
      </div>

      <div id="booking-error-alert" class="alert-box alert-error" style="display: none;"></div>

      <!-- ETAPA 1: Seleção de Especialista e Modalidade -->
      <section id="section-step-1">
        <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 16px 0; color: var(--botanical-forest-900, #0F3E17);">
          Escolha o profissional e a modalidade
        </h2>

        <div class="form-group">
          <p class="form-label" style="margin-bottom: 6px;">Nutricionista</p>
          <?php $isDuplicateName = strcasecmp(trim((string)$selectedProf['name']), trim((string)$selectedProf['clinic_name'])) === 0; ?>
          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
            <?php if (!empty($selectedProf['logo_path'])): ?>
              <img src="<?= htmlspecialchars((string)$selectedProf['logo_path']) ?>" alt="Logo ou foto" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 1px solid var(--neutral-gray-200, #E5E7EB);">
            <?php else: ?>
              <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--botanical-sage-100, #E5F0E5); color: var(--botanical-forest-900, #0F3E17); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.15rem;">
                <?= htmlspecialchars(mb_substr(trim((string)$selectedProf['name']), 0, 1)) ?>
              </div>
            <?php endif; ?>
            <div>
              <p style="margin: 0; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); font-size: 1.05rem;" data-testid="booking-professional"><?= htmlspecialchars($selectedProf['name']) ?><?php if (!$isDuplicateName && !empty($selectedProf['clinic_name'])): ?> <span style="font-weight: 400; color: var(--neutral-gray-600, #475467);">— <?= htmlspecialchars($selectedProf['clinic_name']) ?></span><?php endif; ?></p>
              <p style="margin: 2px 0 0; font-size: 0.85rem; color: var(--neutral-gray-600, #475467);">
                Consulta de <?= (int)$selectedProf['duration_minutes'] ?> minutos.
                <?php if (!empty($selectedProf['default_price']) && (float)$selectedProf['default_price'] > 0): ?>
                  · <span data-testid="booking-reference-price">Valor de referência: <strong style="color: var(--botanical-forest-900, #0F3E17);">R$ <?= number_format((float)$selectedProf['default_price'], 2, ',', '.') ?></strong></span>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <p style="margin: 2px 0 0; font-size: 0.8rem; color: var(--neutral-gray-500, #667085);">Fuso: <?= htmlspecialchars(\ClyoNutri\Domain\Booking\BookingCalendarService::tz()->getName(),ENT_QUOTES) ?>.</p>
          <select id="select-prof" hidden aria-hidden="true" tabindex="-1"><option value="<?= (int)$selectedProf['id'] ?>" data-clinic="<?= (int)$selectedProf['clinic_id'] ?>" selected></option></select>
        </div>

        <div class="form-label">Modalidade de Atendimento</div>
        <?php $allowPres = !empty($settings['allow_presential']); $allowTele = !empty($settings['allow_telehealth']); ?>
        <div class="modality-picker">
          <?php if ($allowPres): ?>
          <button type="button" aria-pressed="true" class="modality-card selected" id="modality-presential" data-value="presential">
            <div style="margin-bottom: 6px; color: var(--botanical-forest-900);"><?= \ClyoNutri\Application\View\Icon::svg('building-2', 24) ?></div>
            <strong style="color: var(--botanical-forest-900, #0F3E17); display: block;">Presencial</strong>
            <span style="font-size: 0.8rem; color: var(--neutral-gray-600, #475467);">No consultório</span>
            <?php if (!empty($selectedProf['clinic_address'])): ?>
              <span class="presential-address-badge" data-testid="presential-address" style="display: block; margin-top: 6px; font-size: 0.75rem; color: var(--semantic-success-text, #0A5F63); background: var(--semantic-success-light, #E3F4F2); border-radius: 4px; padding: 4px 6px; text-align: center;">
                <?= \ClyoNutri\Application\View\Icon::svg('map-pin', 14) ?> <?= htmlspecialchars($selectedProf['clinic_address']) ?>
              </span>
            <?php endif; ?>
          </button>
          <?php endif; ?>
          <?php if ($allowTele): ?>
          <button type="button" aria-pressed="<?= $allowPres?'false':'true' ?>" class="modality-card<?= $allowPres ? '' : ' selected' ?>" id="modality-telehealth" data-value="telehealth">
            <div style="margin-bottom: 6px; color: var(--botanical-forest-900);"><?= \ClyoNutri\Application\View\Icon::svg('video', 24) ?></div>
            <strong style="color: var(--botanical-forest-900, #0F3E17); display: block;">Teleconsulta</strong>
            <span style="font-size: 0.8rem; color: var(--neutral-gray-600, #475467);">Vídeo sem app</span>
          </button>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="select-service">Tipo de Consulta</label>
          <select id="select-service" class="form-control">
            <option value="first_visit" selected>Primeira consulta</option>
            <option value="follow_up">Retorno / acompanhamento</option>
          </select>
        </div>

        <button type="button" id="btn-to-step-2" class="btn-submit" style="margin-top: 12px;">
          Ver Horários Disponíveis &rarr;
        </button>
      </section>

      <!-- ETAPA 2: Seleção de Data e Slot -->
      <section id="section-step-2" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0; color: var(--botanical-forest-900, #0F3E17);">
            Selecione o dia e o horário
          </h2>
          <button type="button" id="btn-back-to-step-1" class="btn-cancel-hold">&larr; Voltar</button>
        </div>

        <div class="form-group">
          <label class="form-label" for="input-date">Data da Consulta</label>
          <input type="date" id="input-date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
        </div>

        <div id="slots-loading" style="display: none; text-align: center; padding: 24px; color: var(--neutral-gray-600, #475467);">
          Buscando horários disponíveis em tempo real...
        </div>

        <?php if($waitlistEnabled): ?><p><a href="/paciente/lista-espera?prof=<?= (int)$profId ?>">Não encontrou um horário? Entrar ou acompanhar a lista de espera</a></p><?php endif; ?>
        <div id="slots-container" style="margin-top: 16px;">
          <div class="form-label">Horários Livres</div>
          <div id="slots-grid" class="slots-grid"></div>
          <div id="no-slots-msg" style="display: none; padding: 20px; text-align: center; color: var(--neutral-gray-600, #475467); font-size: 0.9rem;">
            Nenhum horário livre nesta data. Por favor, escolha outro dia.
          </div>
        </div>
      </section>

      <!-- ETAPA 3: Reserva Ativa (Hold) e Dados do Paciente -->
      <section id="section-step-3" style="display: none;">
        <div class="hold-banner" id="hold-banner">
          <div>
            <strong><?= \ClyoNutri\Application\View\Icon::svg('lock', 14) ?> Horário reservado para você!</strong><br>
            <span id="hold-summary-text" style="font-size: 0.85rem;"></span>
            <div id="hold-summary-address" style="font-size: 0.8rem; color: var(--botanical-forest-900, #0F3E17); margin-top: 4px; display: none;" data-testid="hold-address"></div>
          </div>
          <div style="text-align: right;">
            <div class="hold-timer" id="hold-timer-display"><?= sprintf('%02d:00', (int)$settings['hold_ttl_minutes']) ?></div>
            <button type="button" id="btn-release-hold" class="btn-cancel-hold" style="margin-top: 4px; padding: 3px 8px; font-size: 0.75rem;">
              Trocar horário
            </button>
          </div>
        </div>

        <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 16px 0; color: var(--botanical-forest-900, #0F3E17);">
          Identificação do Paciente
        </h2>

        <p id="booking-identity-status">Já tem cadastro? <a href="/paciente/app" target="_blank" rel="noopener">Entre no Portal do Paciente</a> e volte a esta página para continuar.</p>
        <form id="form-confirm-booking">
          <div class="form-group">
            <label class="form-label" for="patient-name">Nome Completo *</label>
            <input type="text" id="patient-name" class="form-control" required placeholder="Ex: Maria dos Santos">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label class="form-label" for="patient-email">E-mail *</label>
              <input type="email" id="patient-email" class="form-control" required placeholder="seu@email.com">
            </div>
            <div class="form-group">
              <label class="form-label" for="patient-phone">Celular / WhatsApp *</label>
              <input type="tel" id="patient-phone" class="form-control" required placeholder="(11) 98765-4321">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="patient-notes">Objetivo ou Observações</label>
            <textarea id="patient-notes" class="form-control" rows="2" placeholder="Conte brevemente o motivo da consulta (ex: ganho de massa, reeducação alimentar...)"></textarea>
          </div>

          <div class="form-group" style="margin-bottom: 24px;">
            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.85rem; color: var(--neutral-gray-600, #475467); cursor: pointer;">
              <input type="checkbox" id="terms-accept" required style="margin-top: 2px;">
              <span>Li e aceito o termo &ldquo;<?= htmlspecialchars((string)($consentTerm['title'] ?? 'Assistência clínica')) ?>&rdquo; (versão <?= htmlspecialchars((string)($consentTerm['version'] ?? '')) ?>) e autorizo o uso dos meus dados para o atendimento nutricional. <a href="/privacidade" target="_blank" rel="noopener" style="color: var(--botanical-copper-600, #B04A1B);">Política de privacidade</a>.</span>
            </label>
          </div>

          <button type="submit" id="btn-submit-confirm" class="btn-submit">
            Confirmar Agendamento Agora
          </button>
        </form>
      </section>

      <!-- ETAPA 4: Sucesso e Comprovante -->
      <section id="section-step-4" style="display: none; text-align: center;">
        <div style="margin-bottom: 12px; color: var(--semantic-success);"><?= \ClyoNutri\Application\View\Icon::svg('circle-check', 48) ?></div>
        <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--semantic-success-text, #0A5F63); margin: 0 0 8px 0;">
          Consulta Agendada com Sucesso!
        </h2>
        <p style="color: var(--neutral-gray-600, #475467); font-size: 0.95rem; margin: 0 0 24px 0;">
          Seu agendamento foi registrado. Acompanhe o status e os detalhes no Portal do Paciente.
        </p>

        <div style="background: var(--neutral-gray-50, #F9FAFB); border: 1px solid var(--neutral-gray-200, #E5E7EB); border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px;">
          <div style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Código da Consulta</div>
          <div id="receipt-id" style="font-size: 1.1rem; font-weight: 700; color: var(--botanical-forest-900, #0F3E17); margin-bottom: 12px;"></div>

          <div style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Data e Horário</div>
          <div id="receipt-datetime" style="font-size: 1.05rem; font-weight: 600; color: var(--botanical-forest-900, #0F3E17); margin-bottom: 12px;"></div>

          <div style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Modalidade</div>
          <div id="receipt-modality" style="font-size: 1rem; color: var(--botanical-forest-900, #0F3E17);"></div>

          <div id="receipt-address-box" style="margin-top: 10px; display: none;">
            <div style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Local de Atendimento</div>
            <div id="receipt-address" style="font-size: 0.95rem; color: var(--botanical-forest-900, #0F3E17); font-weight: 600;" data-testid="receipt-address"></div>
          </div>
        </div>

        <div style="display: flex; gap: 12px; justify-content: center;">
          <a href="/paciente/app" class="btn-submit" style="text-decoration: none; display: inline-block; width: auto; padding: 12px 24px;">
            Acessar Portal do Paciente
          </a>
        </div>
      </section>
      </div><!-- /booking-wizard -->

    </div>
  </main>

  <footer style="text-align: center; padding: 20px 16px; color: var(--neutral-gray-600, #475467); font-size: 0.8rem; border-top: 1px solid var(--neutral-gray-200, #E5E7EB); background: #fff;">
    &copy; <?= date('Y') ?> Clyo Nutri • Sistema Operacional do Nutricionista
  </footer>

  <script nonce="<?= $nonce ?>">
    (function() {
      var clinicAddress = <?= json_encode((string)($selectedProf['clinic_address'] ?? ''), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
      var state = {
        profId: <?= (int)$profId ?>,
        clinicId: <?= (int)$clinicId ?>,
        modality: <?= !empty($settings['allow_presential']) ? "'presential'" : "'telehealth'" ?>,
        serviceType: 'first_visit',
        durationMinutes: <?= (int)$selectedProf['duration_minutes'] ?>,
        consentTermId: <?= (int)($consentTerm['id'] ?? 0) ?>,
        visitId: Math.random().toString(36).slice(2, 12),
        selectedDate: document.getElementById('input-date').value,
        selectedSlotTime: null,
        holdToken: null,
        holdExpiresTimestamp: null,
        timerInterval: null
      };

      var errorBox = document.getElementById('booking-error-alert');
      function showError(msg) {
        if (!msg) {
          errorBox.style.display = 'none';
          return;
        }
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth' });
      }

      // Troca de modalidade
      document.querySelectorAll('.modality-card').forEach(function(card) {
        card.addEventListener('click', function() {
          document.querySelectorAll('.modality-card').forEach(function(c) { c.classList.remove('selected');c.setAttribute('aria-pressed','false'); });
          card.classList.add('selected');card.setAttribute('aria-pressed','true');
          state.modality = card.getAttribute('data-value');
        });
      });

      // Troca de profissional
      document.getElementById('select-prof').addEventListener('change', function() {
        state.profId = parseInt(this.value, 10);
        var opt = this.options[this.selectedIndex];
        state.clinicId = parseInt(opt.getAttribute('data-clinic'), 10);
      });

      // Passo 1 -> Passo 2
      document.getElementById('btn-to-step-2').addEventListener('click', function() {
        showError(null);
        var sSelect = document.getElementById('select-service');
        state.serviceType = sSelect.value;
        // A duração é definida pelo profissional; o servidor ignora outro valor.

        document.getElementById('section-step-1').style.display = 'none';
        document.getElementById('section-step-2').style.display = 'block';
        document.getElementById('pill-step-1').className = 'step-pill completed';
        document.getElementById('pill-step-2').className = 'step-pill active';
        loadSlots();
      });

      document.getElementById('btn-back-to-step-1').addEventListener('click', function() {
        showError(null);
        document.getElementById('section-step-2').style.display = 'none';
        document.getElementById('section-step-1').style.display = 'block';
        document.getElementById('pill-step-2').className = 'step-pill';
        document.getElementById('pill-step-1').className = 'step-pill active';
      });

      // Recarregar slots ao mudar data
      document.getElementById('input-date').addEventListener('change', function() {
        state.selectedDate = this.value;
        loadSlots();
      });

      var slotsRequestSeq = 0;
      function loadSlots() {
        // Só a resposta do pedido mais recente é usada: trocar a data rápido não pode misturar
        // horários de dias diferentes nem duplicar botões.
        var requestId = ++slotsRequestSeq;
        var requestedDate = state.selectedDate;
        showError(null);
        var grid = document.getElementById('slots-grid');
        var loading = document.getElementById('slots-loading');
        var empty = document.getElementById('no-slots-msg');

        grid.innerHTML = '';
        loading.style.display = 'block';
        empty.style.display = 'none';

        var url = '/api/v1/booking/slots?clinic_id=' + state.clinicId +
                  '&professional_id=' + state.profId +
                  '&date=' + encodeURIComponent(state.selectedDate) +
                  '&duration=' + state.durationMinutes +
                  '&modality=' + state.modality;

        fetch(url)
          .then(function(res) {
            return res.json().then(function(data) {
              if (!res.ok) { throw new Error(data.error || 'Erro ao carregar horários disponíveis. Tente novamente.'); }
              return data;
            });
          })
          .then(function(data) {
            if (requestId !== slotsRequestSeq) return;
            loading.style.display = 'none';
            grid.innerHTML = '';
            if (!data.available_slots || data.available_slots.length === 0) {
              empty.style.display = 'block';
              return;
            }
            data.available_slots.forEach(function(slot) {
              var btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'slot-btn';
              btn.textContent = slot.time;
              btn.addEventListener('click', function() {
                state.selectedDate = requestedDate;
                createHold(slot.scheduled_at, slot.time);
              });
              grid.appendChild(btn);
            });
          })
          .catch(function(err) {
            if (requestId !== slotsRequestSeq) return;
            loading.style.display = 'none';
            showError(err && err.message ? err.message : 'Erro ao carregar horários disponíveis. Tente novamente.');
          });
      }

      function createHold(scheduledAt, displayTime) {
        showError(null);
        var body = {
          clinic_id: state.clinicId,
          professional_id: state.profId,
          scheduled_at: scheduledAt,
          duration_minutes: state.durationMinutes,
          service_type: state.serviceType,
          modality: state.modality,
          // Mesma chave para o mesmo horário nesta visita: clique duplo ou nova tentativa
          // devolvem a mesma reserva em vez de criar outra.
          idempotency_key: 'h_' + state.visitId + '_' + state.profId + '_' + (state.modality === 'telehealth' ? 't' : 'p') + '_' + (state.serviceType === 'follow_up' ? 'f' : 'i') + '_' + scheduledAt.replace(/[^0-9]/g, '')
        };

        fetch('/api/v1/booking/holds', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        })
        .then(function(res) {
          if (res.status === 409) {
            throw new Error('Este horário acabou de ser reservado por outro paciente. Por favor, escolha outro horário.');
          }
          if (!res.ok) {
            return res.json().then(function(err) { throw new Error(err.error || 'Não foi possível reservar o horário. Tente novamente.'); });
          }
          return res.json();
        })
        .then(function(hold) {
          state.holdToken = hold.hold_token;
          state.selectedSlotTime = displayTime;
          state.holdExpiresTimestamp = Date.now() + (hold.ttl_seconds_remaining * 1000);

          document.getElementById('hold-summary-text').textContent =
            (state.modality === 'telehealth' ? 'Teleconsulta' : 'Presencial') + ' • ' +
            state.selectedDate.split('-').reverse().join('/') + ' às ' + displayTime;

          var holdAddr = document.getElementById('hold-summary-address');
          if (holdAddr) {
            if (state.modality === 'presential' && clinicAddress) {
              holdAddr.textContent = 'Local: ' + clinicAddress;
              holdAddr.style.display = 'block';
            } else {
              holdAddr.style.display = 'none';
            }
          }

          document.getElementById('section-step-2').style.display = 'none';
          document.getElementById('section-step-3').style.display = 'block';
          document.getElementById('pill-step-2').className = 'step-pill completed';
          document.getElementById('pill-step-3').className = 'step-pill active';

          startTimer();
        })
        .catch(function(err) {
          showError(err.message);
          loadSlots();
        });
      }

      function startTimer() {
        if (state.timerInterval) clearInterval(state.timerInterval);
        var display = document.getElementById('hold-timer-display');

        state.timerInterval = setInterval(function() {
          var remaining = Math.max(0, Math.floor((state.holdExpiresTimestamp - Date.now()) / 1000));
          var m = Math.floor(remaining / 60);
          var s = remaining % 60;
          display.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;

          if (remaining <= 0) {
            clearInterval(state.timerInterval);
            showError('Sua reserva temporária expirou. Por favor, selecione o horário novamente.');
            document.getElementById('section-step-3').style.display = 'none';
            document.getElementById('section-step-2').style.display = 'block';
            loadSlots();
          }
        }, 1000);
      }

      // Liberar hold voluntariamente
      document.getElementById('btn-release-hold').addEventListener('click', function() {
        if (!state.holdToken) return;
        fetch('/api/v1/booking/release', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ hold_token: state.holdToken })
        }).finally(function() {
          if (state.timerInterval) clearInterval(state.timerInterval);
          state.holdToken = null;
          document.getElementById('section-step-3').style.display = 'none';
          document.getElementById('section-step-2').style.display = 'block';
          document.getElementById('pill-step-3').className = 'step-pill';
          document.getElementById('pill-step-2').className = 'step-pill active';
          loadSlots();
        });
      });

      var authenticatedBooking = false;
      var contextPromise;
      var identityStatus = document.getElementById('booking-identity-status');
      var anonymousIdentityHtml = identityStatus.innerHTML;
      function applyBookingIdentity(patient) {
        authenticatedBooking = !!patient;
        ['name', 'email', 'phone'].forEach(function(field) {
          var input = document.getElementById('patient-' + field);
          input.readOnly = authenticatedBooking;
          input.required = !authenticatedBooking;
          if (patient) input.value = patient[field] || '';
        });
        if (patient) identityStatus.textContent = 'Você está agendando com seu cadastro do Portal do Paciente.';
        else identityStatus.innerHTML = anonymousIdentityHtml;
      }
      function refreshBookingIdentity() {
        contextPromise = fetch('/api/v1/patient/booking/context', {credentials: 'same-origin', cache: 'no-store'})
          .then(function(res) { return res.ok ? res.json() : null; })
          .then(function(data) { applyBookingIdentity(data && data.patient); })
          .catch(function() { applyBookingIdentity(null); });
        return contextPromise;
      }
      refreshBookingIdentity();
      window.addEventListener('focus', refreshBookingIdentity);

      // Submissão de confirmação
      document.getElementById('form-confirm-booking').addEventListener('submit', function(e) {
        e.preventDefault();
        showError(null);
        var btn = document.getElementById('btn-submit-confirm');
        btn.disabled = true;
        btn.textContent = 'Confirmando agendamento...';

        var body = {
          hold_token: state.holdToken,
          name: document.getElementById('patient-name').value.trim(),
          email: document.getElementById('patient-email').value.trim(),
          phone: document.getElementById('patient-phone').value.trim(),
          notes: document.getElementById('patient-notes').value.trim(),
          terms_accepted: document.getElementById('terms-accept').checked,
          consent_term_id: state.consentTermId
        };

        Promise.resolve(contextPromise).then(function() { return fetch(authenticatedBooking ? '/api/v1/patient/booking/confirm' : '/api/v1/booking/confirm', {
          credentials: 'same-origin',
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-ClyoNutri-Web': '1' },
          body: JSON.stringify(body)
        }); })
        .then(function(res) {
          if (res.status === 410) {
            throw new Error('Sua reserva temporária expirou. Selecione o horário novamente.');
          }
          if (res.status === 409) {
            throw new Error('Houve um conflito na confirmação do horário. Por favor, escolha outro slot.');
          }
          if (!res.ok) {
            return res.json().then(function(err) { throw new Error(err.error || 'Erro ao confirmar agendamento.'); });
          }
          return res.json();
        })
        .then(function(result) {
          if (state.timerInterval) clearInterval(state.timerInterval);

          document.getElementById('receipt-id').textContent = '#' + result.appointment_id;
          document.getElementById('receipt-datetime').textContent =
            state.selectedDate.split('-').reverse().join('/') + ' às ' + state.selectedSlotTime;
          document.getElementById('receipt-modality').textContent =
            state.modality === 'telehealth' ? 'Teleconsulta Online (Consultório Virtual Clyo Nutri)' : 'Presencial no Consultório';

          var recBox = document.getElementById('receipt-address-box');
          var recAddr = document.getElementById('receipt-address');
          if (recBox && recAddr) {
            if (state.modality === 'presential' && clinicAddress) {
              recAddr.textContent = clinicAddress;
              recBox.style.display = 'block';
            } else {
              recBox.style.display = 'none';
            }
          }

          document.getElementById('section-step-3').style.display = 'none';
          document.getElementById('section-step-4').style.display = 'block';
          document.getElementById('pill-step-3').className = 'step-pill completed';
          document.getElementById('pill-step-4').className = 'step-pill completed';
        })
        .catch(function(err) {
          btn.disabled = false;
          btn.textContent = 'Confirmar Agendamento Agora';
          showError(err.message);
        });
      });

    })();
  </script>
</body>
</html>
