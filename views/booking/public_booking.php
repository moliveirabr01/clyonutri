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
  <title>Agendar Consulta — ClyoNutri</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #f8fafc;
      color: #1e293b;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .booking-header {
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
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
      color: #0f172a;
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
      border: 1px solid #e2e8f0;
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
      color: #475569;
      border-bottom: 3px solid #e2e8f0;
    }
    .step-pill.active {
      color: #c2410c;
      border-bottom-color: #c2410c;
    }
    .step-pill.completed {
      color: #16a34a;
      border-bottom-color: #16a34a;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-label {
      display: block;
      font-size: 0.875rem;
      font-weight: 600;
      color: #334155;
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.95rem;
      box-sizing: border-box;
    }
    .form-control:focus {
      outline: none;
      border-color: #c2410c;
      box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.15);
    }
    .modality-picker {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 20px;
    }
    .modality-card {
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      cursor: pointer;
      text-align: center;
      transition: all 0.2s ease;
    }
    .modality-card:hover {
      border-color: #fdba74;
    }
    .modality-card.selected {
      border-color: #c2410c;
      background: #fff7ed;
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
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      color: #0f172a;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .slot-btn:hover {
      background: #c2410c;
      color: #fff;
      border-color: #c2410c;
    }
    .slot-btn.selected {
      background: #c2410c;
      color: #fff;
      border-color: #c2410c;
    }
    .hold-banner {
      background: #fef3c7;
      border: 1px solid #fde68a;
      color: #92400e;
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
      color: #b45309;
      font-family: monospace;
    }
    .btn-submit {
      width: 100%;
      padding: 14px;
      background: #c2410c;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .btn-submit:hover {
      background: #9a3412;
    }
    .btn-cancel-hold {
      background: none;
      border: 1px solid #cbd5e1;
      color: #475569;
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
      background: #fef2f2;
      border-left: 4px solid #ef4444;
      color: #991b1b;
    }
    .alert-success {
      background: #f0fdf4;
      border-left: 4px solid #16a34a;
      color: #166534;
    }
  </style>
</head>
<body>
  <header class="booking-header">
    <a href="/agendar" class="booking-header__brand">
      <span style="color: #c2410c;">Clyo</span>Nutri
      <span style="font-size: 0.8rem; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 9999px; font-weight: 500;">Autoagendamento</span>
    </a>
    <div>
      <a href="/app" style="color: #475569; text-decoration: none; font-size: 0.9rem; font-weight: 500;">
        Já sou paciente
      </a>
    </div>
  </header>

  <main class="booking-container">
    <div class="booking-card">

      <div class="step-indicator">
        <div class="step-pill active" id="pill-step-1">1. Especialista & Modalidade</div>
        <div class="step-pill" id="pill-step-2">2. Data & Horário</div>
        <div class="step-pill" id="pill-step-3">3. Seus Dados</div>
        <div class="step-pill" id="pill-step-4">4. Confirmação</div>
      </div>

      <div id="booking-error-alert" class="alert-box alert-error" style="display: none;"></div>

      <!-- ETAPA 1: Seleção de Especialista e Modalidade -->
      <section id="section-step-1">
        <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 16px 0; color: #0f172a;">
          Escolha o profissional e a modalidade
        </h2>

        <div class="form-group">
          <p class="form-label" style="margin-bottom: 4px;">Nutricionista</p>
          <p style="margin: 0; font-weight: 700; color: #0f172a;" data-testid="booking-professional"><?= htmlspecialchars($selectedProf['name']) ?> <span style="font-weight: 400; color: #475569;">— <?= htmlspecialchars($selectedProf['clinic_name']) ?></span></p>
          <p style="margin: 4px 0 0; font-size: 0.85rem; color: #475569;">Consulta de <?= (int)$selectedProf['duration_minutes'] ?> minutos.</p>
          <select id="select-prof" hidden aria-hidden="true" tabindex="-1"><option value="<?= (int)$selectedProf['id'] ?>" data-clinic="<?= (int)$selectedProf['clinic_id'] ?>" selected></option></select>
        </div>

        <div class="form-label">Modalidade de Atendimento</div>
        <?php $allowPres = !empty($settings['allow_presential']); $allowTele = !empty($settings['allow_telehealth']); ?>
        <div class="modality-picker">
          <?php if ($allowPres): ?>
          <div class="modality-card selected" id="modality-presential" data-value="presential">
            <div style="font-size: 1.5rem; margin-bottom: 6px;">🏢</div>
            <strong style="color: #0f172a; display: block;">Presencial</strong>
            <span style="font-size: 0.8rem; color: #475569;">No consultório</span>
          </div>
          <?php endif; ?>
          <?php if ($allowTele): ?>
          <div class="modality-card<?= $allowPres ? '' : ' selected' ?>" id="modality-telehealth" data-value="telehealth">
            <div style="font-size: 1.5rem; margin-bottom: 6px;">📹</div>
            <strong style="color: #0f172a; display: block;">Teleconsulta</strong>
            <span style="font-size: 0.8rem; color: #475569;">Vídeo sem app</span>
          </div>
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
          <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0; color: #0f172a;">
            Selecione o dia e o horário
          </h2>
          <button type="button" id="btn-back-to-step-1" class="btn-cancel-hold">&larr; Voltar</button>
        </div>

        <div class="form-group">
          <label class="form-label" for="input-date">Data da Consulta</label>
          <input type="date" id="input-date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
        </div>

        <div id="slots-loading" style="display: none; text-align: center; padding: 24px; color: #475569;">
          Buscando horários disponíveis em tempo real...
        </div>

        <div id="slots-container" style="margin-top: 16px;">
          <div class="form-label">Horários Livres</div>
          <div id="slots-grid" class="slots-grid"></div>
          <div id="no-slots-msg" style="display: none; padding: 20px; text-align: center; color: #475569; font-size: 0.9rem;">
            Nenhum horário livre nesta data. Por favor, escolha outro dia.
          </div>
        </div>
      </section>

      <!-- ETAPA 3: Reserva Ativa (Hold) e Dados do Paciente -->
      <section id="section-step-3" style="display: none;">
        <div class="hold-banner" id="hold-banner">
          <div>
            <strong>🔒 Horário reservado para você!</strong><br>
            <span id="hold-summary-text" style="font-size: 0.85rem;"></span>
          </div>
          <div style="text-align: right;">
            <div class="hold-timer" id="hold-timer-display">15:00</div>
            <button type="button" id="btn-release-hold" class="btn-cancel-hold" style="margin-top: 4px; padding: 3px 8px; font-size: 0.75rem;">
              Trocar horário
            </button>
          </div>
        </div>

        <h2 style="font-size: 1.25rem; font-weight: 700; margin: 0 0 16px 0; color: #0f172a;">
          Identificação do Paciente
        </h2>

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
            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.85rem; color: #475569; cursor: pointer;">
              <input type="checkbox" id="terms-accept" required style="margin-top: 2px;">
              <span>Li e aceito o termo &ldquo;<?= htmlspecialchars((string)($consentTerm['title'] ?? 'Assistência clínica')) ?>&rdquo; (versão <?= htmlspecialchars((string)($consentTerm['version'] ?? '')) ?>) e autorizo o uso dos meus dados para o atendimento nutricional. <a href="/privacidade" target="_blank" rel="noopener" style="color: #c2410c;">Política de privacidade</a>.</span>
            </label>
          </div>

          <button type="submit" id="btn-submit-confirm" class="btn-submit">
            Confirmar Agendamento Agora
          </button>
        </form>
      </section>

      <!-- ETAPA 4: Sucesso e Comprovante -->
      <section id="section-step-4" style="display: none; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 12px;">🎉</div>
        <h2 style="font-size: 1.4rem; font-weight: 700; color: #166534; margin: 0 0 8px 0;">
          Consulta Agendada com Sucesso!
        </h2>
        <p style="color: #475569; font-size: 0.95rem; margin: 0 0 24px 0;">
          Enviamos os detalhes da confirmação para o seu e-mail.
        </p>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px;">
          <div style="font-size: 0.85rem; color: #475569; margin-bottom: 4px;">Código da Consulta</div>
          <div id="receipt-id" style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: 12px;"></div>

          <div style="font-size: 0.85rem; color: #475569; margin-bottom: 4px;">Data e Horário</div>
          <div id="receipt-datetime" style="font-size: 1.05rem; font-weight: 600; color: #0f172a; margin-bottom: 12px;"></div>

          <div style="font-size: 0.85rem; color: #475569; margin-bottom: 4px;">Modalidade</div>
          <div id="receipt-modality" style="font-size: 1rem; color: #0f172a;"></div>
        </div>

        <div style="display: flex; gap: 12px; justify-content: center;">
          <a href="/app" class="btn-submit" style="text-decoration: none; display: inline-block; width: auto; padding: 12px 24px;">
            Acessar Portal do Paciente
          </a>
        </div>
      </section>

    </div>
  </main>

  <footer style="text-align: center; padding: 20px 16px; color: #475569; font-size: 0.8rem; border-top: 1px solid #e2e8f0; background: #fff;">
    &copy; <?= date('Y') ?> ClyoNutri • Sistema Operacional do Nutricionista
  </footer>

  <script nonce="<?= $nonce ?>">
    (function() {
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
          document.querySelectorAll('.modality-card').forEach(function(c) { c.classList.remove('selected'); });
          card.classList.add('selected');
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
          idempotency_key: 'h_' + state.visitId + '_' + scheduledAt.replace(/[^0-9]/g, '')
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
            (state.modality === 'telehealth' ? '📹 Teleconsulta' : '🏢 Presencial') + ' • ' +
            state.selectedDate.split('-').reverse().join('/') + ' às ' + displayTime;

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
            showError('Sua reserva temporária de 15 minutos expirou. Por favor, selecione o horário novamente.');
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

        fetch('/api/v1/booking/confirm', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        })
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
            state.modality === 'telehealth' ? '📹 Teleconsulta Online (Consultório Virtual ClyoNutri)' : '🏢 Presencial no Consultório';

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
