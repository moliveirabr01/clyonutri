/* Sala de vídeo do paciente (NI2-245; v54.27 vídeo de ponta a ponta). Dados via data-* no <body>; sem script inline. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var statusEl = $('tc-status');
  var screenArea = $('tc-video-area'), chip = $('tc-chip'), phTitle = $('tc-placeholder-title'), phText = $('tc-placeholder-text');
  var pip = $('tc-pip');
  var stage = $('tc-conteudo');
  var screens = {
    connecting: ['Conectando…', 'Conectando…', 'Mantenha esta página aberta.'],
    waiting: ['Aguardando', 'Aguardando o(a) nutricionista ligar o vídeo', 'Assim que a câmera dele(a) ligar, a imagem aparece aqui.'],
    connected: ['Conectado', '', ''],
    unstable: ['Conexão instável', 'Conexão instável', 'Tentando reconectar. Não feche a página.'],
    left: ['Nutricionista saiu', 'O(a) nutricionista saiu da chamada', 'Se ele(a) voltar, a chamada se reconecta sozinha.'],
    failed: ['Sem conexão', 'Não foi possível conectar', 'Veja a mensagem abaixo do vídeo.'],
    ended: ['Encerrada', 'A teleconsulta foi encerrada', 'Você já pode fechar esta página.']
  };
  function setScreen(st) {
    var s = screens[st] || screens.connecting;
    screenArea.setAttribute('data-state', st);
    chip.setAttribute('data-state', st); chip.textContent = s[0];
    if (s[1]) phTitle.textContent = s[1];
    phText.textContent = s[2];
  }
  function say(text, tone) { statusEl.textContent = text; statusEl.dataset.tone = tone || 'info'; }

  var call = ClyoTeleconsulta.start({
    role: 'patient', csrf: document.body.getAttribute('data-tc-csrf'),
    credentialUrl: '/teleconsulta/sala/midia',
    signalBase: '/teleconsulta/sinal/',
    localVideo: $('tc-local'),
    remoteVideo: $('tc-remote'),
    dailyContainer: $('tc-daily'),
    onStatus: say,
    onState: setScreen,
    onScreenShare: function (on) { say(on ? 'O(a) nutricionista está compartilhando a tela.' : 'Compartilhamento de tela encerrado.', 'info'); },
    onAutoplayBlocked: function () {
      var b = $('tc-unmute');
      b.classList.remove('tc-hidden'); b.focus();
      b.onclick = function () { $('tc-remote').play().then(function () { b.classList.add('tc-hidden'); }); };
    },
    onEnded: function () { setScreen('ended'); say('A teleconsulta foi encerrada. Você já pode fechar esta página.', 'info'); }
  });
  $('tc-mic').addEventListener('click', function () {
    var on = call.toggle('audio'); if (on === null) return;
    this.setAttribute('aria-pressed', on ? 'false' : 'true');
    say(on ? 'Microfone ligado.' : 'Microfone silenciado. O(a) nutricionista não ouve você.', on ? 'info' : 'warn');
  });
  $('tc-cam').addEventListener('click', function () {
    var on = call.toggle('video'); if (on === null) return;
    this.setAttribute('aria-pressed', on ? 'false' : 'true');
    say(on ? 'Câmera ligada.' : 'Câmera pausada. O(a) nutricionista não vê você.', on ? 'info' : 'warn');
  });
  $('tc-leave').addEventListener('click', function () {
    if (!window.confirm('Sair da chamada?')) return;
    call.stop();
    setScreen('ended');
    phTitle.textContent = 'Você saiu da chamada';
    phText.textContent = 'Para voltar, recarregue esta página enquanto a consulta estiver aberta.';
    say('Você saiu da chamada. Para voltar, recarregue esta página enquanto a consulta estiver aberta.');
  });

  var fsBtn = $('tc-fullscreen');
  if (fsBtn && document.fullscreenEnabled && stage.requestFullscreen) {
    fsBtn.classList.remove('tc-hidden');
    fsBtn.addEventListener('click', function () {
      if (document.fullscreenElement) document.exitFullscreen(); else stage.requestFullscreen().catch(function () {});
    });
    document.addEventListener('fullscreenchange', function () {
      var on = document.fullscreenElement === stage;
      fsBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  // Sua imagem: troca de canto pelo botão ou arrastando
  var corners = ['br', 'bl', 'tl', 'tr'];
  function setCorner(c) { if (corners.indexOf(c) === -1) return; pip.setAttribute('data-corner', c); try { localStorage.setItem('tc-pip-corner-p', c); } catch (e) {} }
  try { setCorner(localStorage.getItem('tc-pip-corner-p') || 'br'); } catch (e) {}
  $('tc-pip-move').addEventListener('click', function () { setCorner(corners[(corners.indexOf(pip.getAttribute('data-corner') || 'br') + 1) % 4]); });
  var dragging = false;
  pip.addEventListener('pointerdown', function (ev) {
    if (ev.target.closest && ev.target.closest('button')) return;
    dragging = true; pip.classList.add('is-dragging');
    if (pip.setPointerCapture) pip.setPointerCapture(ev.pointerId);
    ev.preventDefault();
  });
  pip.addEventListener('pointermove', function (ev) {
    if (!dragging) return;
    var r = screenArea.getBoundingClientRect();
    pip.setAttribute('data-corner', (ev.clientY < r.top + r.height / 2 ? 't' : 'b') + (ev.clientX < r.left + r.width / 2 ? 'l' : 'r'));
  });
  var end = function () { if (!dragging) return; dragging = false; pip.classList.remove('is-dragging'); setCorner(pip.getAttribute('data-corner')); };
  pip.addEventListener('pointerup', end);
  pip.addEventListener('pointercancel', end);
})();
