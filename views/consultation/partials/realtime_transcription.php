<?php
/**
 * Assistente de Transcrição Nutricional em Tempo Real (NI2-820).
 * Integração à sala de consulta com modos Ao Vivo e Discreto,
 * consentimento prévio LGPD, segmentação incremental e revisão da minuta.
 */
?>
<section class="card cr-card cn-transcription-card" id="cn-transcription-module" aria-labelledby="cr-stt-title"
         data-appointment-id="<?= (int)$app['id'] ?>"
         data-patient-id="<?= (int)$pid ?>"
         data-csrf="<?= htmlspecialchars($csrfToken) ?>">
  <div class="cr-card__head u-flex u-justify-between u-items-center u-wrap u-gap-2">
    <div>
      <h3 id="cr-stt-title" class="u-m-0 u-flex u-items-center u-gap-2">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="u-color-botanical-green-600">
          <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/>
          <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
          <line x1="12" x2="12" y1="19" y2="22"/>
        </svg>
        Transcrição em Tempo Real & Assistente de Evolução
      </h3>
      <p class="cr-card__lead u-mt-1 u-mb-0">Captura de fala com geração de minuta estruturada para o prontuário. Requer aceite prévio do paciente e aprovação humana explícita.</p>
    </div>
    <div class="u-flex u-items-center u-gap-2">
      <span id="stt-status-badge" class="badge badge-secondary" role="status" aria-live="polite">Aguardando aceite do paciente</span>
    </div>
  </div>

  <!-- 1. ACEITE DO PACIENTE (NI2-830 / SEC-001): quem aceita é o paciente; o profissional só pede -->
  <div id="stt-consent-panel" class="op-message op-message--info u-mt-3" role="region" aria-labelledby="stt-consent-title">
    <strong id="stt-consent-title">Aceite do paciente para a transcrição desta consulta (LGPD)</strong>
    <details class="u-mt-1" data-testid="stt-lgpd-details">
      <summary class="u-text-compact u-pointer">Regras de aceite LGPD e texto que o paciente vai ler (<?= htmlspecialchars(\ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms::VERSION) ?>)</summary>
      <p class="u-text-compact u-mt-1">
        O aceite é feito pelo próprio paciente, no aparelho dele (link de uso único ou QR) ou no portal do paciente. Você não consegue aceitar por ele.
        Sem aceite válido para esta consulta, nenhum áudio é captado. O texto que o paciente lê é a versão <strong><?= htmlspecialchars(\ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms::VERSION) ?></strong>.
      </p>
      <dl class="u-text-compact">
<?php foreach (\ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms::sections() as $__t => $__b): ?>
        <dt class="u-font-bold"><?= htmlspecialchars($__t) ?></dt>
        <dd><?= htmlspecialchars($__b) ?></dd>
<?php endforeach; ?>
      </dl>
    </details>
    <p id="stt-consent-state" class="op-message op-message--info u-mt-2 u-text-compact" role="status" aria-live="polite" data-state="none" data-testid="stt-consent-state">Aguardando aceite do paciente. Nenhum áudio é captado.</p>
    <div class="u-flex u-gap-2 u-items-center u-wrap u-mt-2">
      <button type="button" id="btn-request-consent" class="btn btn-secondary btn-sm" style="min-height: 44px;">
        Pedir aceite ao paciente
      </button>
      <button type="button" id="btn-revoke-consent" class="btn btn-outline btn-sm u-color-danger" style="min-height: 44px;" hidden>
        Registrar revogação a pedido do paciente
      </button>
    </div>
    <div id="stt-consent-link-box" class="u-mt-2" hidden>
      <label for="stt-consent-link" class="u-font-bold u-text-compact">Link para o paciente abrir no próprio aparelho:</label>
      <div class="u-flex u-gap-2 u-items-center u-wrap">
        <input type="text" id="stt-consent-link" class="form-control" readonly style="flex: 1 1 280px; min-height: 44px;" data-testid="stt-consent-link">
        <button type="button" id="btn-copy-consent-link" class="btn btn-outline btn-sm" style="min-height: 44px;">Copiar link</button>
      </div>
      <div id="stt-consent-qr" class="u-mt-2" aria-label="QR code do link de aceite"></div>
      <p id="stt-consent-link-expiry" class="u-text-compact u-mt-1" aria-live="off"></p>
      <p class="u-text-compact">Por segurança o link aparece só agora. Para ver de novo, gere outro (o anterior é cancelado).</p>
    </div>
  </div>

  <!-- 2. CONTROLES DA SESSÃO DE TRANSCRIÇÃO (DESBLOQUEADOS APÓS CONSENTIMENTO) -->
  <div id="stt-controls-panel" class="u-mt-3" hidden>
    <div class="u-flex u-justify-between u-items-center u-wrap u-gap-3 u-p-3" style="background: var(--neutral-gray-50, #f8fafc); border-radius: 8px; border: 1px solid var(--neutral-gray-200, #e2e8f0);">
      
      <!-- Seletor de Modo -->
      <fieldset class="u-m-0 u-p-0" style="border: 0;">
        <legend class="u-text-compact u-font-bold u-mb-1">Modo de visualização:</legend>
        <div class="u-flex u-gap-3 u-items-center">
          <label class="cr-radio u-flex u-items-center u-gap-1 u-pointer" style="min-height: 44px;">
            <input type="radio" name="stt_mode" value="live" id="mode-live" checked>
            <span><strong>Ao Vivo</strong> (texto visível)</span>
          </label>
          <label class="cr-radio u-flex u-items-center u-gap-1 u-pointer" style="min-height: 44px;">
            <input type="radio" name="stt_mode" value="discrete" id="mode-discrete">
            <span><strong>Discreto</strong> (texto oculto na tela)</span>
          </label>
        </div>
      </fieldset>

      <!-- Botões de Ação do Áudio -->
      <div class="u-flex u-gap-2 u-items-center u-wrap">
        <button type="button" id="btn-start-stt" class="btn btn-primary" style="min-height: 44px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>
          Iniciar Transcrição
        </button>
        <button type="button" id="btn-pause-stt" class="btn btn-secondary" style="min-height: 44px;" hidden>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
          Pausar
        </button>
        <button type="button" id="btn-resume-stt" class="btn btn-secondary" style="min-height: 44px;" hidden>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          Retomar
        </button>
        <button type="button" id="btn-finish-stt" class="btn btn-outline" style="min-height: 44px;" hidden>
          Finalizar e Gerar Minuta
        </button>
      </div>
    </div>

    <!-- 3. PAINEL DE TEXTO TRANSCRITO -->
    <div id="stt-stream-container" class="u-mt-3 u-p-3" style="border: 1px solid var(--neutral-gray-200, #e2e8f0); border-radius: 8px; min-height: 120px; max-height: 240px; overflow-y: auto; background: var(--neutral-white, #fff);">
      <div id="stt-live-view">
        <p id="stt-empty-notice" class="u-color-neutral-gray-500 u-text-compact u-m-0">Nenhum áudio captado ainda. Clique em "Iniciar Transcrição" para começar.</p>
        <div id="stt-segments-list" class="u-flex u-flex-col u-gap-1"></div>
      </div>
      <div id="stt-discrete-view" hidden>
        <div class="u-flex u-items-center u-gap-2 u-p-2" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #166534;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
          <span class="u-text-compact"><strong>Modo discreto ativo:</strong> A captação e o processamento continuam ativos em segundo plano. O texto está oculto na tela para preservar a privacidade visual do paciente.</span>
        </div>
      </div>
    </div>
  </div>

  <!-- 4. PAINEL DE REVISÃO E APROVAÇÃO HUMANA DA MINUTA -->
  <div id="stt-draft-review-panel" class="u-mt-4 u-p-4" style="background: #fdfcf7; border: 1px solid #e2d9c2; border-radius: 8px;" hidden>
    <div class="u-flex u-justify-between u-items-center u-mb-2">
      <h4 class="u-m-0 u-color-botanical-forest-900 u-flex u-items-center u-gap-2">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        Revisão Clínica Obrigatória da Minuta de Evolução
      </h4>
      <span class="badge badge-warning" id="stt-draft-status-badge">Minuta em Rascunho (Não aprovada)</span>
    </div>
    <p class="u-text-compact u-color-neutral-gray-600 u-mb-3">
      Revise as anotações geradas abaixo. Nenhuma conduta, exame ou dieta foi inventada ou prescrita automaticamente.
      Você pode editar o texto livremente antes de aprovar. A evolução só entra no prontuário após sua confirmação explícita.
    </p>

    <div class="form-group u-mb-3">
      <label for="stt-evolution-editor" class="form-label u-font-bold">Evolução Clínica Consolidada (Editável):</label>
      <textarea id="stt-evolution-editor" class="form-control" rows="10" style="width: 100%; font-family: monospace; font-size: 0.875rem; line-height: 1.5;"></textarea>
    </div>

    <div class="u-flex u-justify-between u-items-center u-wrap u-gap-2">
      <span class="u-text-compact u-color-neutral-gray-500">
        Autoria atribuída ao profissional responsável após aprovação.
      </span>
      <div class="u-flex u-gap-2">
        <button type="button" id="btn-reopen-stt" class="btn btn-secondary btn-sm" style="min-height: 44px;">Continuar gravando</button>
        <button type="button" id="btn-approve-evolution" class="btn btn-primary" style="min-height: 44px;">
          ✓ Aprovar e Integrar ao Prontuário
        </button>
      </div>
    </div>
  </div>
</section>

<script src="/assets/js/qrcode.min.js?v=v54-61" defer></script>
<script src="/assets/js/realtime-transcription.js?v=v54-61" defer></script>
