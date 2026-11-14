/**
 * ClyoNutri II — Transcrição em Tempo Real na Consulta (NI2-820).
 * Modos Ao Vivo e Discreto, Consentimento LGPD, Ingestão Incremental e Aprovação da Minuta.
 * NI2-830 (SEC-001): o aceite é do PACIENTE. Aqui o profissional só pede o aceite (link/QR de uso único) e vê o estado real
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
  const linkBox = document.getElementById('stt-consent-link-box');
  const linkInput = document.getElementById('stt-consent-link');
  const btnCopyLink = document.getElementById('btn-copy-consent-link');
  const qrBox = document.getElementById('stt-consent-qr');
  const linkExpiry = document.getElementById('stt-consent-link-expiry');

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

  let mediaStream = null;
  let mediaRecorder = null;
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

  function addSegmentToUI(seq, text, speaker = 'Interlocutor') {
    if (emptyNotice) emptyNotice.hidden = true;
    const div = document.createElement('div');
    div.className = 'stt-segment u-p-1 u-text-compact';
    div.dataset.seq = seq;
    div.innerHTML = `<span class="u-font-bold u-color-botanical-green-700">[#${seq}]</span> ${escapeHtml(text)}`;
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
  let linkTimer = null;
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

  function showLink(link, expiresUtc) {
    if (!linkBox || !linkInput) return;
    linkInput.value = link;
    linkBox.hidden = false;
    if (qrBox) {
      qrBox.innerHTML = '';
      if (typeof QRCode !== 'undefined') {
        try { new QRCode(qrBox, { text: link, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M }); } catch (e) { /* o link continua disponível */ }
      }
    }
    clearInterval(linkTimer);
    const exp = new Date(String(expiresUtc).replace(' ', 'T') + 'Z').getTime();
    const tick = () => {
      const left = Math.max(0, Math.round((exp - Date.now()) / 1000));
      if (linkExpiry) linkExpiry.textContent = left > 0 ? `Link válido por mais ${Math.floor(left / 60)} min ${String(left % 60).padStart(2, '0')} s. Uso único.` : 'Link expirado. Gere outro.';
      if (left <= 0) clearInterval(linkTimer);
    };
    tick();
    linkTimer = setInterval(tick, 1000);
  }
  function hideLink() {
    if (linkBox) linkBox.hidden = true;
    if (linkInput) linkInput.value = '';
    if (qrBox) qrBox.innerHTML = '';
    clearInterval(linkTimer);
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
        hideLink();
        break;
      case 'pending':
        setConsentState('pending', 'Aguardando aceite do paciente. Nenhum áudio é captado até lá.', 'op-message--info');
        setBadge('Aguardando aceite do paciente', 'badge-secondary');
        btnRequestConsent.textContent = 'Gerar novo link (cancela o anterior)';
        break;
      case 'rejected':
        setConsentState('rejected', 'O paciente recusou. A consulta segue sem transcrição.', 'op-message--info');
        setBadge('Paciente recusou a transcrição', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir aceite de novo';
        hideLink();
        break;
      case 'expired':
        setConsentState('expired', 'O pedido de aceite expirou sem resposta do paciente. Nada foi captado. Gere um novo.', 'op-message--info');
        setBadge('Aceite expirado', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir aceite ao paciente';
        hideLink();
        break;
      case 'revoked':
        setConsentState('revoked', `Aceite revogado ${c.revoked_by === 'patient' ? 'pelo paciente' : 'a pedido do paciente'}. A captação foi interrompida.`, 'op-message--info');
        setBadge('Autorização revogada', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir novo aceite ao paciente';
        hideLink();
        break;
      case 'cancelled':
      default:
        setConsentState('none', 'Aguardando aceite do paciente. Nenhum áudio é captado.', 'op-message--info');
        setBadge('Aguardando aceite do paciente', 'badge-secondary');
        btnRequestConsent.textContent = 'Pedir aceite ao paciente';
        hideLink();
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

  // Pedir o aceite ao paciente: gera o link de uso único. O profissional NÃO concede o aceite.
  btnRequestConsent?.addEventListener('click', async () => {
    btnRequestConsent.disabled = true;
    try {
      const res = await api('consent/request', {});
      if (res.link) showLink(res.link, res.consent.expires_at_utc);
      await refreshStatus();
    } catch (e) {
      alert('Não foi possível pedir o aceite: ' + e.message);
    } finally {
      btnRequestConsent.disabled = false;
    }
  });

  btnCopyLink?.addEventListener('click', async () => {
    if (!linkInput || !linkInput.value) return;
    try { await navigator.clipboard.writeText(linkInput.value); btnCopyLink.textContent = 'Link copiado'; }
    catch (e) { linkInput.select(); btnCopyLink.textContent = 'Selecionado: copie com Ctrl+C'; }
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

      // Reconhecimento de fala integrado (Web Speech API quando disponível) ou segmentação por áudio
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
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
                addSegmentToUI(sequenceCounter, transcript);
                await sendSegment(sequenceCounter, transcript);
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
      } else if (mediaStream && window.MediaRecorder) {
        // Fallback por MediaRecorder em pedaços de áudio
        mediaRecorder = new MediaRecorder(mediaStream, { mimeType: 'audio/webm' });
        mediaRecorder.ondataavailable = async (e) => {
          if (e.data && e.data.size > 0 && isCapturing) {
            sequenceCounter++;
            const mockText = `[Segmento de áudio captado #${sequenceCounter}]`;
            addSegmentToUI(sequenceCounter, mockText);
            await sendSegment(sequenceCounter, mockText);
          }
        };
        mediaRecorder.start(4000); // chunk a cada 4 segundos
      }
    } catch (err) {
      console.warn('[stt] Acesso a microfone não disponível:', err);
    }
  }

  function pauseAudioCapture() {
    if (recognition) {
      try { recognition.stop(); } catch (e) {}
    }
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      try { mediaRecorder.pause(); } catch (e) {}
    }
  }

  function resumeAudioCapture() {
    if (recognition) {
      try { recognition.start(); } catch (e) {}
    }
    if (mediaRecorder && mediaRecorder.state === 'paused') {
      try { mediaRecorder.resume(); } catch (e) {}
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

  async function sendSegment(seq, text) {
    try {
      await api('segment', {
        sequence_number: seq,
        text,
        speaker: 'speaker_uncertain',
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
