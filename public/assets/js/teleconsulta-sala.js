/* Sala de vídeo do paciente (NI2-245). Dados via data-* no <body>; sem script inline. */
(function () {
  'use strict';
  var statusEl = document.getElementById('tc-status');
  var call = ClyoTeleconsulta.start({
    role: 'patient', csrf: document.body.getAttribute('data-tc-csrf'),
    credentialUrl: '/teleconsulta/sala/midia',
    signalBase: '/teleconsulta/sinal/',
    localVideo: document.getElementById('tc-local'),
    remoteVideo: document.getElementById('tc-remote'),
    dailyContainer: document.getElementById('tc-daily'),
    onStatus: function (text, tone) { statusEl.textContent = text; statusEl.dataset.tone = tone; },
    onScreenShare: function (on) {
      statusEl.textContent = on ? 'O(a) nutricionista está compartilhando a tela.' : 'Compartilhamento de tela encerrado.';
      statusEl.dataset.tone = 'info';
    },
    onAutoplayBlocked: function () {
      var b = document.getElementById('tc-unmute');
      b.classList.remove('tc-hidden'); b.focus();
      b.onclick = function () { document.getElementById('tc-remote').play().then(function () { b.classList.add('tc-hidden'); }); };
    },
    onEnded: function () { statusEl.textContent = 'A teleconsulta foi encerrada. Você já pode fechar esta página.'; statusEl.dataset.tone = 'info'; }
  });
  document.getElementById('tc-mic').addEventListener('click', function () {
    var on = call.toggle('audio'); this.textContent = on ? 'Silenciar microfone' : 'Ativar microfone'; this.setAttribute('aria-pressed', on ? 'false' : 'true');
  });
  document.getElementById('tc-cam').addEventListener('click', function () {
    var on = call.toggle('video'); this.textContent = on ? 'Desligar câmera' : 'Ligar câmera'; this.setAttribute('aria-pressed', on ? 'false' : 'true');
  });
  document.getElementById('tc-leave').addEventListener('click', function () {
    if (!window.confirm('Sair da chamada?')) return;
    call.stop();
    statusEl.textContent = 'Você saiu da chamada. Para voltar, recarregue esta página enquanto a consulta estiver aberta.';
  });
})();
