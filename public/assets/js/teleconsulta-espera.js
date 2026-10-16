/* Sala de espera do paciente (NI2-238/247/248). Dados via data-* no <body>; sem script inline. */
(function () {
  'use strict';
  var csrf = document.body.getAttribute('data-tc-csrf');
  var base = '/teleconsulta/sala-espera'; // F05: o convite vai no cookie HttpOnly
  var deviceOk = false, consentOk = false, preview = null;

  function post(path, body) {
    return fetch(base + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Erro'); return j; }); });
  }
  function say(id, text, tone) { var el = document.getElementById(id); el.textContent = text; el.dataset.tone = tone || 'info'; }
  function update() {
    document.getElementById('tc-enter').disabled = !(deviceOk && consentOk);
    if (deviceOk && consentOk) say('tc-wait-status', 'Tudo pronto. Entre quando estiver no horário.');
  }

  document.getElementById('tc-test').addEventListener('click', function () {
    say('tc-test-status', 'Pedindo acesso à câmera e ao microfone…');
    navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(function (stream) {
      preview = stream;
      document.getElementById('tc-preview').srcObject = stream;
      if (!stream.getAudioTracks().length || !stream.getVideoTracks().length) throw new Error('Câmera ou microfone não encontrado.');
      return post('/dispositivo', { camera_ok: true, microphone_ok: true, browser: navigator.userAgent });
    }).then(function () {
      deviceOk = true; say('tc-test-status', 'Câmera e microfone funcionando.', 'ok'); update();
    }).catch(function (err) {
      say('tc-test-status', 'Não foi possível usar câmera/microfone: ' + err.message + ' Verifique as permissões do navegador.', 'error');
    });
  });

  document.getElementById('tc-consent').addEventListener('change', function () {
    var box = this;
    if (!box.checked) { consentOk = false; update(); return; }
    post('/consentir', {}).then(function () { consentOk = true; update(); })
      .catch(function (err) { box.checked = false; say('tc-wait-status', err.message, 'error'); });
  });

  document.getElementById('tc-enter').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    post('/entrar', {}).then(function () {
      btn.classList.add('tc-hidden');
      say('tc-wait-status', 'Você está na sala de espera. O(a) nutricionista vai admitir sua entrada. Mantenha esta página aberta.', 'ok');
      poll();
    }).catch(function (err) { btn.disabled = false; say('tc-wait-status', err.message, 'warn'); });
  });

  function poll() {
    fetch(base + '/status', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, body: j }; });
    }).then(function (res) {
      if (!res.ok) { say('tc-wait-status', res.body.error || 'A teleconsulta não está mais disponível.', 'error'); return; }
      if (res.body.is_admitted) {
        if (preview) preview.getTracks().forEach(function (t) { t.stop(); });
        location.href = '/teleconsulta/sala';
        return;
      }
      setTimeout(poll, 3000);
    }).catch(function () { setTimeout(poll, 6000); });
  }
})();
