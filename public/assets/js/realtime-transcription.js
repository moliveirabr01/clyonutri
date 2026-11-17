/**
 * ClyoNutri II — Transcrição em Tempo Real na Consulta (NI2-820).
 * Modos Ao Vivo e Discreto, Consentimento LGPD, Ingestão Incremental e Aprovação da Minuta.
 * NI2-830 (SEC-001): o aceite é do PACIENTE. Aqui o profissional só pede o aceite pelo portal ou por envio direto ao paciente e vê o estado real
 * devolvido pelo servidor; a interface nunca afirma consentimento sem evidência e libera a captação só com aceite válido.
 */
(() => {
  'use strict';

  const root = document.getElementById('cn-transcription-module');
  if (!root) return;

  const appointmentId = root.dataset.appointmentId;
  const patientId = root.dataset.patientId;
  const csrfToken = root.dataset.csrf;

  const badgeStatus = document.getElementById('stt-status-badge');
  const consentPanel = document.getElementById('stt-consent-panel');
  const btnRequestConsent = document.getElementById('btn-request-consent');
  const btnRevokeConsent = document.getElementById('btn-revoke-consent');
  const consentState = document.getElementById('stt-consent-state');


  const controlsPanel = document.getElementById('stt-controls-panel');
  const modeLiveRadio = document.getElementById('mode-live');
  const modeDiscreteRadio = document.getElementById('mode-discrete');
  const btnStart = document.getElementById('btn-start-stt');
  const btnPause = document.getElementById('btn-pause-stt');
  const btnResume = document.getElementById('btn-resume-stt');
  const btnFinish = document.getElementById('btn-finish-stt');

  const liveView = document.getElementById('stt-live-view');
  const discreteView = document.getElementById('stt-discrete-view');
  const emptyNotice = document.getElementById('stt-empty-notice');
  const segmentsList = document.getElementById('stt-segments-list');

  const draftPanel = document.getElementById('stt-draft-review-panel');
  const draftEditor = document.getElementById('stt-evolution-editor');
  const draftBadge = document.getElementById('stt-draft-status-badge');
  const btnApprove = document.getElementById('btn-approve-evolution');
  const btnReopen = document.getElementById('btn-reopen-stt');

  const unsupportedNotice = document.getElementById('stt-unsupported');
  const draftSources = document.getElementById('stt-draft-sources');
  const sourceDetail = document.getElementById('stt-source-detail');
  // NI2-882: sem reconhecimento de fala no navegador (ex.: Firefox) não há transcrição. Nada é captado nem gravado,
  // e nenhum texto substituto ("[Segmento de áudio captado #N]") é enviado como se fosse fala.
  const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  const SPEAKER_LABEL = { patient: 'Paciente', professional: 'Profissional', speaker_uncertain: 'Não identificado' };
  function currentSpeaker() {
    const el = document.querySelector('input[name="stt_speaker"]:checked');
    return el && SPEAKER_LABEL[el.value] ? el.value : 'speaker_uncertain';
  }
  if (!SpeechRecognitionCtor) {
    if (unsupportedNotice) unsupportedNotice.hidden = false;
    if (btnStart) { btnStart.disabled = true; btnStart.setAttribute('aria-describedby', 'stt-unsupported'); }
  }

  let mediaStream = null;
  let recognition = null;
  let currentSession = null;
  let currentMode = 'live';
  let sequenceCounter = 0;
  let isCapturing = false;

  // Chamada de API com CSRF e JSON
  async function api(path, data = null, method = 'POST') {
    const opts = {
      method,
      headers: {
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
    };
    if (data) {
      if (data instanceof FormData) {
        data.append('_csrf_token', csrfToken);
        opts.body = data;
      } else {
        const fd = new FormData();
        fd.append('_csrf_token', csrfToken);
        for (const [k, v] of Object.entries(data)) {
          fd.append(k, v);
        }
        opts.body = fd;
      }
    }
    const res = await fetch(`/api/consulta/${appointmentId}/transcription/${path}`, opts);
    if (!res.ok) {
      const err = await res.json().catch(() => ({ error: 'Falha na comunicação' }));
      const ex = new Error(err.error || `HTTP ${res.status}`);
      ex.status = res.status;
      ex.requiresConsent = !!(err.requires_consent || err.consent_required);
      throw ex;
    }
    return res.json();
  }

  function setBadge(text, cls = 'badge-secondary') {
    if (!badgeStatus) return;
    badgeStatus.textContent = text;
    badgeStatus.className = `badge ${cls}`;
  }

  function addSegmentToUI(seq, text, speaker = 'speaker_uncertain') {
    if (emptyNotice) emptyNotice.hidden = true;
    const div = document.createElement('div');
    div.className = 'stt-segment u-p-1 u-text-compact';
    div.dataset.seq = seq;
    div.dataset.speaker = speaker;
    div.innerHTML = `<span class="u-font-bold u-color-botanical-green-700">[#${seq}] ${escapeHtml(SPEAKER_LABEL[speaker] || SPEAKER_LABEL.speaker_uncertain)}:</span> ${escapeHtml(text)}`;
    segmentsList.appendChild(div);
    segmentsList.parentElement.scrollTop = segmentsList.parentElement.scrollHeight;
  }

  function escapeHtml(str) {
    const p = document.createElement('p');
    p.textContent = str;
    return p.innerHTML;
  }

  function updateModeUI(mode) {
    currentMode = mode;
    if (mode === 'discrete') {
      modeDiscreteRadio.checked = true;
      liveView.hidden = true;
      discreteView.hidden = false;
    } else {
      modeLiveRadio.checked = true;
      liveView.hidden = false;
      discreteView.hidden = true;
    }
  }

  // ---- Estado do aceite do paciente (sempre vindo do servidor) --------------------------------------------------
  let consentPoll = null;
  let activeConsent = false;

  const fmtLocal = (utc) => {
    if (!utc) return '';
    const d = new Date(String(utc).replace(' ', 'T') + 'Z');
    return isNaN(d.getTime()) ? '' : d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  };
  const MECH = { patient_link: 'pelo link', patient_portal: 'pelo portal do paciente' };

  function setConsentState(state, text, cls) {
    if (!consentState) return;
    consentState.dataset.state = state;
    consentState.textContent = text;
    if (cls) consentState.className = `op-message ${cls} u-mt-2`;
  }

  function applyConsent(data) {
    const c = data.consent || { state: 'none' };
    const wasActive = activeConsent;
    activeConsent = !!data.consent_active;
    btnRevokeConsent.hidden = !activeConsent;
    controlsPanel.hidden = !activeConsent;
    btnRequestConsent.hidden = activeConsent;
    switch (activeConsent ? 'accepted' : c.state) {
      case 'accepted':
        setConsentState('accepted', `Paciente aceitou ${MECH[c.mechanism] || ''} às ${fmtLocal(c.accepted_at_utc)} (texto ${c.terms_version}). Válido para esta consulta até ${fmtLocal(c.valid_until_utc)}.`, 'op-message--success');
        setBadge('Aceite do paciente confirmado', 'badge-info');
        break;
      case 'pending':
        setConsentState('pending', 'Aguardando aceite do paciente. Nenhum áudio é captado até lá.', 'op-message--info');
        setBadge('Aguardando aceite do paciente', 'badge-secondary');
        btnRequestConsent.textContent = 'Reenviar pedido de aceite (cancela o anterior)';
        break;
      case 'rejected':
        setConsentState('rejected', 'O paciente recusou. A consulta segue sem transcrição.', 'op-message--info');
        setBadge('Paciente recusou a transcrição', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir aceite de novo';
        break;
      case 'expired':
        setConsentState('expired', 'O pedido de aceite expirou sem resposta do paciente. Nada foi captado. Gere um novo.', 'op-message--info');
        setBadge('Aceite expirado', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir aceite ao paciente';
        break;
      case 'revoked':
        setConsentState('revoked', `Aceite revogado ${c.revoked_by === 'patient' ? 'pelo paciente' : 'a pedido do paciente'}. A captação foi interrompida.`, 'op-message--info');
        setBadge('Autorização revogada', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir novo aceite ao paciente';
        break;
      case 'cancelled':
      default:
        setConsentState('none', 'Aguardando aceite do paciente. Nenhum áudio é captado.', 'op-message--info');
        setBadge('Aguardando aceite do paciente', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir aceite ao paciente';
    }
    if (wasActive && !activeConsent) {
      // Aceite deixou de valer (revogado ou expirado): para a captação local na hora.
      stopAudioCapture();
      isCapturing = false;
      btnPause.hidden = true; btnResume.hidden = true; btnFinish.hidden = true; btnStart.hidden = false;
    }
    // Sonda o servidor enquanto há algo a acompanhar (pedido pendente ou aceite vigente).
    clearInterval(consentPoll);
    if (c.state === 'pending' || activeConsent) consentPoll = setInterval(refreshStatus, 4000);
  }

  async function refreshStatus() {
    try { applyConsent(await api('session', null, 'GET')); } catch (e) { console.warn('[stt] Falha ao atualizar o aceite:', e); }
  }

  // Inicializa e recupera estado existente
  async function initStatus() {
    try {
      const data = await api('session', null, 'GET');
      applyConsent(data);

      if (data.session) {
        currentSession = data.session;
        updateModeUI(data.session.mode || 'live');

        if (data.session.status === 'capturing') {
          setBadge('Captando fala', 'badge-success');
          btnStart.hidden = true;
          btnPause.hidden = false;
          btnResume.hidden = true;
          btnFinish.hidden = false;
          isCapturing = !!data.consent_active;
        } else if (data.session.status === 'paused') {
          setBadge('Captação pausada', 'badge-warning');
          btnStart.hidden = true;
          btnPause.hidden = true;
          btnResume.hidden = false;
          btnFinish.hidden = false;
          isCapturing = false;
        } else if (data.session.status === 'completed') {
          setBadge('Transcrição concluída', 'badge-info');
          btnStart.hidden = false;
          btnPause.hidden = true;
          btnResume.hidden = true;
          btnFinish.hidden = true;
          isCapturing = false;
        }
        if (!data.consent_active) applyConsent(data); // sem aceite válido, o selo volta a refletir o estado real do aceite

        // Recupera segmentos da sessão
        if (data.segments && data.segments.length > 0) {
          segmentsList.innerHTML = '';
          data.segments.forEach(s => {
            sequenceCounter = Math.max(sequenceCounter, parseInt(s.sequence_number, 10));
            addSegmentToUI(s.sequence_number, s.text, s.speaker);
          });
        }
      }

      if (data.draft) {
        showDraftReview(data.draft);
      }
    } catch (e) {
      console.warn('[stt] Falha ao recuperar status inicial:', e);
    }
  }

  // Pedir o aceite ao paciente pelo canal protegido. O profissional NÃO concede o aceite.
  btnRequestConsent?.addEventListener('click', async () => {
    btnRequestConsent.disabled = true;
    try {
      await api('consent/request', {});
      await refreshStatus();
    } catch (e) {
      alert('Não foi possível pedir o aceite: ' + e.message);
    } finally {
      btnRequestConsent.disabled = false;
    }
  });

  btnRevokeConsent?.addEventListener('click', async () => {
    if (!confirm('O paciente pediu para revogar a autorização de transcrição deste atendimento? A captação será interrompida.')) return;
    try {
      await api('consent/revoke');
      stopAudioCapture();
      await refreshStatus();
    } catch (e) {
      alert('Erro ao revogar consentimento: ' + e.message);
    }
  });

  // Alternância de modo Ao Vivo / Discreto
  modeLiveRadio?.addEventListener('change', () => switchMode('live'));
  modeDiscreteRadio?.addEventListener('change', () => switchMode('discrete'));

  async function switchMode(newMode) {
    updateModeUI(newMode);
    if (currentSession && currentSession.id) {
      try {
        await api('mode', { mode: newMode });
      } catch (e) {
        console.warn('[stt] Erro ao sincronizar modo no servidor:', e);
      }
    }
  }

  // Iniciar Transcrição
  btnStart?.addEventListener('click', async () => {
    if (!SpeechRecognitionCtor) {
      if (unsupportedNotice) unsupportedNotice.hidden = false;
      return;
    }
    try {
      setBadge('Iniciando...', 'badge-info');
      const res = await api('start', { mode: currentMode });
      currentSession = res.session;
      isCapturing = true;

      btnStart.hidden = true;
      btnPause.hidden = false;
      btnResume.hidden = true;
      btnFinish.hidden = false;
      setBadge('Captando áudio em tempo real', 'badge-success');

      startAudioCapture();
    } catch (e) {
      setBadge('Falha ao iniciar', 'badge-danger');
      alert('Não foi possível iniciar a transcrição: ' + e.message);
      if (e.requiresConsent) refreshStatus();
    }
  });

  // Pausar
  btnPause?.addEventListener('click', async () => {
    try {
      pauseAudioCapture();
      await api('pause');
      btnPause.hidden = true;
      btnResume.hidden = false;
      setBadge('Captação pausada', 'badge-warning');
      isCapturing = false;
    } catch (e) {
      alert('Erro ao pausar: ' + e.message);
    }
  });

  // Retomar
  btnResume?.addEventListener('click', async () => {
    try {
      await api('resume');
      resumeAudioCapture();
      btnPause.hidden = false;
      btnResume.hidden = true;
      setBadge('Captando fala', 'badge-success');
      isCapturing = true;
    } catch (e) {
      alert('Erro ao retomar: ' + e.message);
    }
  });

  // Finalizar e gerar minuta
  btnFinish?.addEventListener('click', async () => {
    if (!confirm('Deseja encerrar a transcrição e gerar a minuta de evolução para revisão?')) return;
    try {
      stopAudioCapture();
      setBadge('Gerando minuta...', 'badge-info');
      const res = await api('finish');
      currentSession = res.session;
      btnPause.hidden = true;
      btnResume.hidden = true;
      btnFinish.hidden = true;
      btnStart.hidden = false;
      setBadge('Minuta gerada', 'badge-success');
      showDraftReview(res.draft);
    } catch (e) {
      alert('Erro ao finalizar transcrição: ' + e.message);
      setBadge('Erro', 'badge-danger');
    }
  });

  function showDraftReview(draft) {
    if (!draftPanel || !draftEditor) return;
    draftPanel.hidden = false;
    draftEditor.value = draft.full_evolution_text || '';
    renderSources(draft.segment_sources || {});
    if (draft.approval_status === 'approved') {
      draftBadge.textContent = 'Evolução Aprovada e Integrada';
      draftBadge.className = 'badge badge-success';
      btnApprove.disabled = true;
      btnApprove.textContent = '✓ Evolução já Aprovada';
    } else {
      draftBadge.textContent = 'Minuta em Rascunho (Não aprovada)';
      draftBadge.className = 'badge badge-warning';
      btnApprove.disabled = false;
      btnApprove.textContent = '✓ Aprovar e Integrar ao Prontuário';
    }
    draftPanel.scrollIntoView({ behavior: 'smooth' });
  }

  // NI2-882: rastreabilidade — cada frase da minuta aponta para o trecho gravado de origem.
  const TOPIC_LABEL = {
    chief_complaint: 'Queixa principal', nutritional_goals: 'Objetivos', dietary_routine: 'Rotina alimentar',
    reported_anthropometry: 'Medidas informadas', clinical_symptoms: 'Sintomas', adherence_notes: 'Adesão e dificuldades',
    agreed_conduct: 'Conduta acordada', next_appointment_notes: 'Retorno', other_notes: 'Outros relatos',
  };
  function renderSources(sources) {
    if (!draftSources) return;
    draftSources.innerHTML = '';
    if (sourceDetail) sourceDetail.hidden = true;
    let total = 0;
    Object.keys(TOPIC_LABEL).forEach((topic) => {
      const list = Array.isArray(sources[topic]) ? sources[topic] : [];
      if (!list.length) return;
      const h = document.createElement('p');
      h.className = 'u-font-bold u-mt-2 u-mb-1';
      h.textContent = TOPIC_LABEL[topic];
      draftSources.appendChild(h);
      list.forEach((src) => {
        total++;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-link btn-sm u-text-left';
        b.dataset.testid = 'stt-source-sentence';
        b.setAttribute('data-testid', 'stt-source-sentence');
        b.dataset.segmentId = src.id;
        b.textContent = src.text;
        b.addEventListener('click', () => {
          if (!sourceDetail) return;
          const li = segmentsList ? segmentsList.querySelector(`[data-seq="${src.sequence_number}"]`) : null;
          const original = li ? li.textContent.replace(/^\[#\d+\][^:]*:\s*/, '') : src.text;
          sourceDetail.hidden = false;
          sourceDetail.textContent = `Trecho #${src.sequence_number} (${SPEAKER_LABEL[src.speaker] || SPEAKER_LABEL.speaker_uncertain}): "${original}"`;
        });
        draftSources.appendChild(b);
      });
    });
    if (!total) {
      draftSources.textContent = 'Nenhuma frase da consulta entrou na minuta: todos os tópicos estão como "Não mencionado na consulta".';
    }
  }

  // Aprovar e Integrar ao Prontuário
  btnApprove?.addEventListener('click', async () => {
    const text = draftEditor.value.trim();
    if (!text) {
      alert('O texto da evolução não pode estar vazio.');
      return;
    }

    if (!confirm('Confirma a aprovação desta evolução nutricional para inclusão no prontuário oficial?')) return;

    try {
      btnApprove.disabled = true;
      btnApprove.textContent = 'Integrando...';
      const res = await api('draft/approve', { full_evolution_text: text });

      // Atualiza o textarea oficial da consulta na página
      const officialSummary = document.getElementById('evolution_summary');
      if (officialSummary) {
        officialSummary.value = text;
        officialSummary.dispatchEvent(new Event('input', { bubbles: true }));
      }

      draftBadge.textContent = 'Evolução Aprovada e Integrada com Sucesso';
      draftBadge.className = 'badge badge-success';
      btnApprove.textContent = '✓ Aprovado e Integrado ao Prontuário';

      const statusEl = document.getElementById('consulta-draft-status');
      if (statusEl) {
        statusEl.textContent = 'Evolução aprovada via assistente de transcrição e integrada ao rascunho oficial da consulta.';
      }
    } catch (e) {
      btnApprove.disabled = false;
      btnApprove.textContent = '✓ Aprovar e Integrar ao Prontuário';
      alert('Erro ao aprovar e integrar: ' + e.message);
    }
  });

  btnReopen?.addEventListener('click', () => {
    draftPanel.hidden = true;
    controlsPanel.scrollIntoView({ behavior: 'smooth' });
  });

  // Captação de Áudio e Envio Incremental
  async function startAudioCapture() {
    try {
      if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true }).catch(() => null);
      }

      // Reconhecimento de fala do navegador. Sem ele não há transcrição (NI2-882): nada é enviado.
      const SpeechRecognition = SpeechRecognitionCtor;
      if (SpeechRecognition) {
        recognition = new SpeechRecognition();
        recognition.lang = 'pt-BR';
        recognition.continuous = true;
        recognition.interimResults = true;

        recognition.onresult = async (event) => {
          if (!isCapturing) return;
          for (let i = event.resultIndex; i < event.results.length; ++i) {
            if (event.results[i].isFinal) {
              const transcript = event.results[i][0].transcript.trim();
              if (transcript) {
                sequenceCounter++;
                const speaker = currentSpeaker();
                addSegmentToUI(sequenceCounter, transcript, speaker);
                await sendSegment(sequenceCounter, transcript, speaker);
              }
            }
          }
        };

        recognition.onerror = (e) => {
          console.warn('[stt] SpeechRecognition error:', e.error);
        };

        recognition.onend = () => {
          if (isCapturing) {
            try { recognition.start(); } catch (err) {}
          }
        };

        recognition.start();
      } else {
        // Sem reconhecimento de fala: libera o microfone e avisa; nenhum trecho é inventado nem enviado.
        if (mediaStream) { mediaStream.getTracks().forEach(t => t.stop()); mediaStream = null; }
        if (unsupportedNotice) unsupportedNotice.hidden = false;
      }
    } catch (err) {
      console.warn('[stt] Acesso a microfone não disponível:', err);
    }
  }

  function pauseAudioCapture() {
    if (recognition) {
      try { recognition.stop(); } catch (e) {}
    }
  }

  function resumeAudioCapture() {
    if (recognition) {
      try { recognition.start(); } catch (e) {}
    }
  }

  function stopAudioCapture() {
    isCapturing = false;
    pauseAudioCapture();
    if (mediaStream) {
      mediaStream.getTracks().forEach(t => t.stop());
      mediaStream = null;
    }
  }

  async function sendSegment(seq, text, speaker = 'speaker_uncertain') {
    try {
      await api('segment', {
        sequence_number: seq,
        text,
        speaker,
        is_final: 1,
      });
    } catch (e) {
      console.warn('[stt] Erro ao enviar segmento:', e);
      if (e.requiresConsent) {
        // O servidor recusou por falta de aceite válido (revogado, expirado, outro atendimento): para a captação.
        stopAudioCapture();
        refreshStatus();
      }
    }
  }

  // Inicializa o módulo ao carregar
  initStatus();
})();
