/* Sala do nutricionista (NI2-245/247; v54.27 vídeo em primeiro lugar). Dados via data-* no <body>; sem script inline. */
(function () {
  'use strict';
  var body = document.body;
  var sid = body.getAttribute('data-tc-session');
  var csrf = body.getAttribute('data-tc-csrf');
  var call = null;
  var $ = function (id) { return document.getElementById(id); };
  var statusEl = $('tc-status');
  var labels = { scheduled: 'Agendada', device_checked: 'Dispositivo verificado', waiting_room: 'Na sala de espera', admitted: 'Em atendimento', in_progress: 'Em atendimento', ended: 'Encerrada', rejected: 'Entrada recusada', cancelled: 'Cancelada', expired: 'Expirada' };
  var terminal = ['ended', 'cancelled', 'expired', 'rejected'];
  var baseTitle = document.title;
  var store = { get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} } };

  function say(text, tone) { if (statusEl) { statusEl.textContent = text; statusEl.dataset.tone = tone || 'info'; } }

  // ------------------------------------------------------------ convite
  var copy = $('tc-copy-invite');
  if (copy) copy.addEventListener('click', function () {
    var input = $('tc-invite-link');
    var done = function () { copy.textContent = 'Copiado'; };
    if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
    else { input.select(); }
  });

  // ------------------------------------------- painel de anotações / foco
  var panel = $('tc-panel');
  var panelToggle = $('tc-panel-toggle');
  var panelClose = $('tc-panel-close');
  var focusBtn = $('tc-focus');
  var wide = window.matchMedia ? window.matchMedia('(min-width: 1280px)') : { matches: true };
  function panelVisible() {
    if (wide.matches) return !body.classList.contains('tc-panel-closed') && !body.classList.contains('tc-focus');
    return body.classList.contains('tc-drawer-open');
  }
  function syncPanel() {
    var open = panelVisible();
    if (panelToggle) panelToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (focusBtn) focusBtn.setAttribute('aria-pressed', body.classList.contains('tc-focus') ? 'true' : 'false');
    if (panel) panel.setAttribute('aria-hidden', open ? 'false' : 'true');
  }
  function setPanel(open, moveFocus) {
    if (wide.matches) {
      body.classList.toggle('tc-panel-closed', !open);
      if (open) body.classList.remove('tc-focus');
    } else {
      body.classList.toggle('tc-drawer-open', open);
    }
    syncPanel();
    if (moveFocus) { if (open && panel) panel.focus(); else if (!open && panelToggle) panelToggle.focus(); }
  }
  if (panelToggle) panelToggle.addEventListener('click', function () { setPanel(!panelVisible(), !wide.matches); });
  if (panelClose) panelClose.addEventListener('click', function () { setPanel(false, true); });
  if (focusBtn) focusBtn.addEventListener('click', function () {
    var on = !body.classList.contains('tc-focus');
    body.classList.toggle('tc-focus', on);
    syncPanel();
    say(on ? 'Modo foco: as anotações ficam escondidas. Toque em "Anotações" para voltar.' : 'Modo foco desligado.');
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !wide.matches && body.classList.contains('tc-drawer-open')) setPanel(false, true);
  });
  if (wide.addEventListener) wide.addEventListener('change', function () { body.classList.remove('tc-drawer-open'); syncPanel(); });
  syncPanel();

  // ------------------------------------------- sala de espera e chegada
  var lobby = $('tc-lobby');
  var lobbyText = $('tc-lobby-text');
  var lobbyActions = $('tc-lobby-actions');
  var arrival = $('tc-arrival');
  var opensAt = Date.parse(body.getAttribute('data-tc-opens-at') || '');
  var serverNow = Date.parse(body.getAttribute('data-tc-server-now') || '');
  var offset = isNaN(serverNow) ? 0 : serverNow - Date.now();
  var opensLabel = body.getAttribute('data-tc-opens-label') || '';
  var wasWaiting = body.getAttribute('data-tc-status') === 'waiting_room';

  function beep() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = beep.ctx || (beep.ctx = new Ctx());
      if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
      [0, 0.25].forEach(function (t) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, ctx.currentTime + t);
        g.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + t + 0.2);
        o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.22);
      });
    } catch (e) {}
  }
  function idleLobbyText(status) {
    if (status === 'admitted' || status === 'in_progress') return 'Paciente admitido.';
    if (opensLabel && !isNaN(opensAt) && Date.now() + offset < opensAt) return 'Ninguém aguardando. A sala do paciente abre às ' + opensLabel + '.';
    return 'Ninguém aguardando no momento.';
  }
  function setWaiting(waiting, status, cue) {
    if (lobby) lobby.classList.toggle('is-waiting', waiting);
    if (lobbyActions) lobbyActions.classList.toggle('tc-hidden', !waiting);
    if (lobbyText) lobbyText.textContent = waiting ? 'O paciente está aguardando para entrar.' : idleLobbyText(status);
    document.title = waiting ? '(1) Paciente aguardando · ' + baseTitle : baseTitle;
    if (waiting && cue) {
      beep();
      if (lobby) { lobby.classList.remove('is-arriving'); void lobby.offsetWidth; lobby.classList.add('is-arriving'); }
      if (arrival) arrival.textContent = 'O paciente chegou à sala de espera. Use "Admitir" para deixá-lo entrar.';
    }
    if (waiting && (!call || call.uiState !== 'connected')) setScreen(call ? 'waiting' : 'lobby');
  }
  if (wasWaiting) document.title = '(1) Paciente aguardando · ' + baseTitle;
  if (lobby) lobby.addEventListener('animationend', function () { lobby.classList.remove('is-arriving'); });

  // Admitir sem recarregar quando o vídeo já está ligado (a recarga derrubaria a chamada).
  var admitForm = $('tc-admit-form');
  if (admitForm && window.fetch && window.FormData) admitForm.addEventListener('submit', function (ev) {
    if (!call || call.stopped) return; // sem chamada ativa: envio normal
    ev.preventDefault();
    var btn = $('tc-admit'); if (btn) btn.disabled = true;
    fetch(admitForm.action, { method: 'POST', body: new FormData(admitForm), credentials: 'same-origin', redirect: 'manual' })
      .then(function () { return ClyoTeleconsulta.json('/teleconsulta/' + sid + '/estado'); })
      .then(function (st) {
        if (st.status !== 'admitted' && st.status !== 'in_progress') { location.reload(); return; }
        $('tc-session-status').textContent = labels[st.status] || st.status;
        wasWaiting = false;
        setWaiting(false, st.status, false);
        if (arrival) arrival.textContent = 'Paciente admitido. Ele entra na chamada em instantes.';
        say('Paciente admitido. Ele entra na chamada em instantes.', 'ok');
      })
      .catch(function () { location.reload(); })
      .then(function () { if (btn) btn.disabled = false; });
  });

  function refresh() {
    ClyoTeleconsulta.json('/teleconsulta/' + sid + '/estado').then(function (st) {
      if (terminal.indexOf(st.status) !== -1) { if (call) call.stop(false); location.reload(); return; }
      $('tc-session-status').textContent = labels[st.status] || st.status;
      var waiting = !!st.patient_waiting;
      setWaiting(waiting, st.status, waiting && !wasWaiting);
      wasWaiting = waiting;
      setTimeout(refresh, 4000);
    }).catch(function () { setTimeout(refresh, 8000); });
  }

  // ------------------------------------------------------------ vídeo
  var start = $('tc-start');
  if (!start) return; // sessão encerrada: só formulários e painel
  var screenArea = $('tc-video-area');
  var chip = $('tc-chip');
  var phTitle = $('tc-placeholder-title');
  var phText = $('tc-placeholder-text');
  var pip = $('tc-pip');
  var micBtn = $('tc-mic'), camBtn = $('tc-cam'), screenBtn = $('tc-screen'), fsBtn = $('tc-fullscreen');
  var stage = $('tc-conteudo');
  var screens = {
    idle: ['Vídeo desligado', 'Aguardando o paciente', 'Ligue sua câmera quando quiser. O vídeo do paciente aparece aqui.'],
    lobby: ['Paciente na sala de espera', 'O paciente está na sala de espera', 'Toque em "Admitir" e depois em "Ligar câmera e entrar".'],
    waiting: ['Aguardando paciente', 'Aguardando a câmera do paciente', 'Assim que ele entrar na chamada, a imagem aparece aqui.'],
    connecting: ['Conectando…', 'Conectando o vídeo', 'Isso costuma levar poucos segundos.'],
    connected: ['Conectado', '', ''],
    unstable: ['Conexão instável', 'Conexão instável', 'Tentando reconectar. Não feche a página.'],
    left: ['Paciente saiu', 'O paciente saiu da chamada', 'Se ele voltar pelo mesmo link, a chamada se reconecta.'],
    failed: ['Sem conexão', 'Não foi possível conectar', 'Veja a mensagem abaixo do vídeo.'],
    ended: ['Encerrada', 'Chamada encerrada', '']
  };
  function setScreen(st) {
    if (!screens) return;
    var s = screens[st] || screens.idle;
    if (screenArea) screenArea.setAttribute('data-state', st);
    if (chip) { chip.setAttribute('data-state', st === 'lobby' ? 'waiting' : st); chip.textContent = s[0]; }
    if (phTitle && s[1]) phTitle.textContent = s[1];
    if (phText) phText.textContent = s[2];
  }
  if (wasWaiting) setScreen('lobby');

  start.addEventListener('click', function () {
    start.disabled = true;
    call = ClyoTeleconsulta.start({
      role: 'professional', csrf: csrf,
      credentialUrl: '/teleconsulta/' + sid + '/midia',
      signalBase: '/teleconsulta/sinal/',
      localVideo: $('tc-local'),
      remoteVideo: $('tc-remote'),
      dailyContainer: $('tc-daily'),
      onStatus: say,
      onState: function (st) {
        setScreen(st);
        if (st === 'failed' && !call.localStream) { start.disabled = false; start.classList.remove('tc-hidden'); }
      },
      onAutoplayBlocked: function () {
        var b = $('tc-unmute');
        b.classList.remove('tc-hidden'); b.focus();
        b.onclick = function () { $('tc-remote').play().then(function () { b.classList.add('tc-hidden'); }); };
      },
      onEnded: function () { location.reload(); },
      onScreenShare: function (on) {
        screenBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        say(on ? 'Você está compartilhando a tela. O paciente vê a tela, não a sua câmera. Feche prontuários e outras janelas com dados de pacientes.' : 'Compartilhamento de tela encerrado.', on ? 'warn' : 'info');
      }
    });
    start.classList.add('tc-hidden');
    if (pip) pip.classList.remove('tc-hidden');
    micBtn.disabled = false; camBtn.disabled = false;
    if (call.canShareScreen()) { screenBtn.classList.remove('tc-hidden'); screenBtn.disabled = false; }
    micBtn.focus();
  });
  screenBtn.addEventListener('click', function () {
    if (!call) return;
    if (call.screenStream) { call.stopScreenShare(); return; }
    call.startScreenShare().catch(function () { say('Compartilhamento de tela cancelado ou não permitido pelo navegador.', 'warn'); });
  });
  micBtn.addEventListener('click', function () {
    if (!call) return;
    var on = call.toggle('audio');
    this.setAttribute('aria-pressed', on ? 'false' : 'true');
    say(on ? 'Microfone ligado.' : 'Microfone silenciado. O paciente não ouve você.', on ? 'info' : 'warn');
  });
  camBtn.addEventListener('click', function () {
    if (!call) return;
    var on = call.toggle('video');
    this.setAttribute('aria-pressed', on ? 'false' : 'true');
    say(on ? 'Câmera ligada.' : 'Câmera pausada. O paciente não vê você.', on ? 'info' : 'warn');
  });

  // Tela cheia (o palco inclui vídeo e controles)
  if (fsBtn && document.fullscreenEnabled && stage && stage.requestFullscreen) {
    fsBtn.classList.remove('tc-hidden');
    fsBtn.addEventListener('click', function () {
      if (document.fullscreenElement) document.exitFullscreen(); else stage.requestFullscreen().catch(function () { say('O navegador não permitiu a tela cheia.', 'warn'); });
    });
    document.addEventListener('fullscreenchange', function () {
      var on = document.fullscreenElement === stage;
      fsBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  // Sua imagem: arrastar para um canto (encaixa no canto mais próximo) ou usar o botão
  var corners = ['br', 'bl', 'tl', 'tr'];
  var cornerNames = { br: 'inferior direito', bl: 'inferior esquerdo', tl: 'superior esquerdo', tr: 'superior direito' };
  function setCorner(c, announce) {
    if (!pip || corners.indexOf(c) === -1) return;
    pip.setAttribute('data-corner', c);
    store.set('tc-pip-corner', c);
    if (announce) say('Sua imagem foi para o canto ' + cornerNames[c] + '.');
  }
  setCorner(store.get('tc-pip-corner') || 'br', false);
  var move = $('tc-pip-move');
  if (move) move.addEventListener('click', function () {
    var cur = pip.getAttribute('data-corner') || 'br';
    setCorner(corners[(corners.indexOf(cur) + 1) % corners.length], true);
  });
  if (pip && screenArea) {
    var dragging = false;
    var quadrant = function (ev) {
      var r = screenArea.getBoundingClientRect();
      var left = ev.clientX < r.left + r.width / 2, top = ev.clientY < r.top + r.height / 2;
      return (top ? 't' : 'b') + (left ? 'l' : 'r');
    };
    pip.addEventListener('pointerdown', function (ev) {
      if (ev.target.closest && ev.target.closest('button')) return;
      dragging = true; pip.classList.add('is-dragging');
      if (pip.setPointerCapture) pip.setPointerCapture(ev.pointerId);
      ev.preventDefault();
    });
    pip.addEventListener('pointermove', function (ev) {
      if (!dragging) return;
      var q = quadrant(ev);
      if (q !== pip.getAttribute('data-corner')) pip.setAttribute('data-corner', q);
    });
    var end = function () { if (!dragging) return; dragging = false; pip.classList.remove('is-dragging'); setCorner(pip.getAttribute('data-corner'), false); };
    pip.addEventListener('pointerup', end);
    pip.addEventListener('pointercancel', end);
  }

  // Finalizar a evolução recarrega a página: avisa se a chamada estiver ligada.
  var evo = $('tc-evolution');
  if (evo) evo.addEventListener('submit', function (ev) {
    if (call && !call.stopped && call.uiState === 'connected' && !window.confirm('Salvar a evolução recarrega esta página e o vídeo cai por alguns segundos. Depois, toque em "Ligar câmera e entrar" para voltar. Salvar agora?')) { ev.preventDefault(); ev.stopImmediatePropagation(); }
  }, true);

  window.ClyoTeleRoom = { setScreen: setScreen };
  setTimeout(refresh, 4000);
})();

/* Rascunho automático da evolução (NI2-249): salva 1,5 s após parar de digitar,
 * com versão para detectar edição em outra aba. O texto nunca é apagado da tela
 * por falha de rede. */
(function () {
  'use strict';
  var form = document.getElementById('tc-evolution');
  if (!form) return;
  var sid = document.body.getAttribute('data-tc-session');
  var csrf = document.body.getAttribute('data-tc-csrf');
  var url = '/teleconsulta/' + sid + '/rascunho';
  var fields = { title: document.getElementById('tc-title'), notes: document.getElementById('tc-notes'), goals: document.getElementById('tc-goals') };
  var statusEl = document.getElementById('tc-draft-status');
  var conflictEl = document.getElementById('tc-draft-conflict');
  var version = 0, timer = null, saving = false, dirty = false, serverCopy = null;

  function say(text, tone) { statusEl.textContent = text; statusEl.dataset.tone = tone || 'info'; }
  function hhmm(utc) { if (!utc) return ''; var d = new Date(utc.replace(' ', 'T') + 'Z'); return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
  function values() { return { title: fields.title.value, notes: fields.notes.value, goals: fields.goals.value }; }
  function fill(d) { if (d.title != null) fields.title.value = d.title; if (d.notes != null) fields.notes.value = d.notes; if (d.goals != null) fields.goals.value = d.goals; }

  function save() {
    if (saving) { timer = setTimeout(save, 800); return; }
    if (!dirty) return;
    saving = true; dirty = false;
    say('Salvando rascunho…');
    var body = values(); body.version = version;
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.status === 409 && res.body.status === 'conflict') {
          serverCopy = res.body.server; version = res.body.version;
          conflictEl.classList.remove('tc-hidden');
          say('Rascunho não salvo: há uma versão mais nova em outro lugar.', 'warn');
          return;
        }
        if (res.status >= 400) throw new Error(res.body.error || 'Erro');
        version = res.body.version;
        say('Rascunho salvo às ' + hhmm(res.body.updated_at_utc) + '.', 'ok');
      })
      .catch(function () { dirty = true; say('Não foi possível salvar o rascunho agora. O texto continua aqui; tentaremos de novo.', 'error'); timer = setTimeout(save, 5000); })
      .then(function () { saving = false; });
  }
  function schedule() { dirty = true; clearTimeout(timer); timer = setTimeout(save, 1500); }
  Object.keys(fields).forEach(function (k) { fields[k].addEventListener('input', schedule); });

  document.getElementById('tc-draft-load').addEventListener('click', function () {
    if (serverCopy) fill(serverCopy);
    conflictEl.classList.add('tc-hidden'); say('Versão salva carregada.', 'ok');
  });
  document.getElementById('tc-draft-keep').addEventListener('click', function () {
    conflictEl.classList.add('tc-hidden'); dirty = true; save(); // grava sobre a versão atual do servidor
  });
  window.addEventListener('beforeunload', function (ev) { if (dirty || saving) { ev.preventDefault(); ev.returnValue = ''; } });
  form.addEventListener('submit', function () { dirty = false; clearTimeout(timer); });

  fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
    version = d.version || 0;
    // Texto devolvido após erro de gravação tem prioridade e é salvo como rascunho.
    if (form.getAttribute('data-has-flash-draft') === '1') { schedule(); return; }
    if (version > 0) { fill(d); say('Rascunho recuperado (salvo às ' + hhmm(d.updated_at_utc) + ').', 'ok'); }
  }).catch(function () {});
})();
