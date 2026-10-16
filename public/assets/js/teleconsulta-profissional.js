/* Sala do nutricionista (NI2-245/247). Dados via data-* no <body>; sem script inline. */
(function () {
  'use strict';
  var body = document.body;
  var sid = body.getAttribute('data-tc-session');
  var csrf = body.getAttribute('data-tc-csrf');
  var call = null;
  var statusEl = document.getElementById('tc-status');
  var labels = { scheduled: 'Agendada', device_checked: 'Dispositivo verificado', waiting_room: 'Na sala de espera', admitted: 'Em atendimento', ended: 'Encerrada', rejected: 'Entrada recusada', cancelled: 'Cancelada', expired: 'Expirada' };

  var copy = document.getElementById('tc-copy-invite');
  if (copy) copy.addEventListener('click', function () {
    var input = document.getElementById('tc-invite-link');
    var done = function () { copy.textContent = 'Copiado'; };
    if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
    else { input.select(); }
  });

  var start = document.getElementById('tc-start');
  if (!start) return; // sessão encerrada: só formulários
  start.addEventListener('click', function () {
    start.disabled = true;
    call = ClyoTeleconsulta.start({
      role: 'professional', csrf: csrf,
      credentialUrl: '/teleconsulta/' + sid + '/midia',
      signalBase: '/teleconsulta/sinal/',
      localVideo: document.getElementById('tc-local'),
      remoteVideo: document.getElementById('tc-remote'),
      dailyContainer: document.getElementById('tc-daily'),
      onStatus: function (text, tone) { statusEl.textContent = text; statusEl.dataset.tone = tone; },
      onAutoplayBlocked: function () {
        var b = document.getElementById('tc-unmute');
        b.classList.remove('tc-hidden'); b.focus();
        b.onclick = function () { document.getElementById('tc-remote').play().then(function () { b.classList.add('tc-hidden'); }); };
      },
      onEnded: function () { location.reload(); },
      onScreenShare: function (on) {
        screenBtn.textContent = on ? 'Parar compartilhamento' : 'Compartilhar tela';
        screenBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        statusEl.textContent = on ? 'Você está compartilhando a tela. O paciente vê a tela, não a sua câmera. Feche prontuários e outras janelas com dados de pacientes.' : 'Compartilhamento de tela encerrado.';
        statusEl.dataset.tone = on ? 'warn' : 'info';
      }
    });
    document.getElementById('tc-mic').disabled = false;
    document.getElementById('tc-cam').disabled = false;
    var screenBtn = document.getElementById('tc-screen');
    if (call.canShareScreen()) { screenBtn.classList.remove('tc-hidden'); screenBtn.disabled = false; }
  });
  var screenBtn = document.getElementById('tc-screen');
  screenBtn.addEventListener('click', function () {
    if (!call) return;
    if (call.screenStream) { call.stopScreenShare(); return; }
    call.startScreenShare().catch(function () {
      statusEl.textContent = 'Compartilhamento de tela cancelado ou não permitido pelo navegador.'; statusEl.dataset.tone = 'warn';
    });
  });
  document.getElementById('tc-mic').addEventListener('click', function () {
    var on = call && call.toggle('audio');
    this.textContent = on ? 'Silenciar microfone' : 'Ativar microfone';
    this.setAttribute('aria-pressed', on ? 'false' : 'true');
  });
  document.getElementById('tc-cam').addEventListener('click', function () {
    var on = call && call.toggle('video');
    this.textContent = on ? 'Desligar câmera' : 'Ligar câmera';
    this.setAttribute('aria-pressed', on ? 'false' : 'true');
  });

  function refresh() {
    ClyoTeleconsulta.json('/teleconsulta/' + sid + '/estado').then(function (st) {
      document.getElementById('tc-session-status').textContent = labels[st.status] || st.status;
      document.getElementById('tc-lobby-actions').classList.toggle('tc-hidden', !st.patient_waiting);
      document.getElementById('tc-lobby-text').textContent = st.patient_waiting ? 'O paciente está aguardando.' : (st.status === 'admitted' ? 'Paciente admitido.' : 'Ninguém aguardando no momento.');
      if (['ended', 'cancelled', 'expired', 'rejected'].indexOf(st.status) !== -1) { location.reload(); return; }
      setTimeout(refresh, 4000);
    }).catch(function () { setTimeout(refresh, 8000); });
  }
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
