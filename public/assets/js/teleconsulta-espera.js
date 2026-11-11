/* Sala de espera do paciente (NI2-238/247/248). Dados via data-* no <body>; sem script inline. */
(function () {
  'use strict';
  var csrf = document.body.getAttribute('data-tc-csrf');
  var base = '/teleconsulta/sala-espera'; // F05: o convite vai no cookie HttpOnly
  var deviceOk = false, consentOk = false, preview = null, entered = false;

  // v54.27: horário de abertura vindo do servidor (UTC) + diferença entre o relógio do servidor e o do aparelho.
  var body = document.body;
  var opensAt = Date.parse(body.getAttribute('data-tc-opens-at') || '');
  var offset = 0;
  function setServerNow(iso) { var t = Date.parse(iso || ''); if (!isNaN(t)) offset = t - Date.now(); }
  setServerNow(body.getAttribute('data-tc-server-now'));
  var opensLabel = body.getAttribute('data-tc-opens-label') || '';
  function serverNow() { return Date.now() + offset; }
  function isOpen() { return isNaN(opensAt) || serverNow() >= opensAt; }

  function post(path, body) {
    return fetch(base + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Erro'); return j; }); });
  }
  function say(id, text, tone) { var el = document.getElementById(id); el.textContent = text; el.dataset.tone = tone || 'info'; }
  var alertBox = document.getElementById('tc-alert');
  function showAlert(text, tone) {
    document.getElementById('tc-alert-text').textContent = text;
    alertBox.dataset.tone = tone || 'error';
    alertBox.classList.remove('tc-hidden');
    alertBox.scrollIntoView({ block: 'start', behavior: 'smooth' });
    alertBox.focus({ preventScroll: true });
  }
  function hideAlert() { alertBox.classList.add('tc-hidden'); }

  var enterBtn = document.getElementById('tc-enter');
  var lastReady = null;
  function update() {
    if (entered) return;
    var open = isOpen();
    var ready = deviceOk && consentOk;
    enterBtn.disabled = !(open && ready);
    enterBtn.textContent = open ? 'Entrar na sala de espera' : 'A sala abre ' + opensLabel;
    var key = (open ? 'o' : 'c') + (ready ? 'r' : 'n');
    if (key === lastReady) return;
    lastReady = key;
    if (ready && open) say('tc-wait-status', 'Tudo pronto. Toque em "Entrar na sala de espera".', 'ok');
    else if (ready) say('tc-wait-status', 'Tudo pronto. O botão libera sozinho quando a sala abrir.');
    else if (open) say('tc-wait-status', 'Conclua as etapas 1 e 2.');
  }

  // Contagem regressiva (atualiza a tela a cada segundo; leitores de tela não são interrompidos)
  var countEl = document.getElementById('tc-countdown');
  var openingEl = document.getElementById('tc-opening');
  var openingTime = document.getElementById('tc-opening-time');
  function two(n) { return (n < 10 ? '0' : '') + n; }
  var tickTimer = null;
  function tick() {
    clearTimeout(tickTimer);
    if (isOpen()) {
      if (openingEl && !openingEl.classList.contains('is-open')) {
        openingEl.classList.add('is-open');
        openingTime.textContent = 'A sala de espera está aberta';
        countEl.classList.add('tc-hidden');
      }
      update();
      return;
    }
    var left = Math.max(0, Math.ceil((opensAt - serverNow()) / 1000));
    var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), sec = left % 60;
    countEl.textContent = 'Faltam ' + (h > 0 ? h + ' h ' + two(m) + ' min' : (m > 0 ? m + ' min ' + two(sec) + ' s' : sec + ' s'));
    update();
    tickTimer = setTimeout(tick, 1000 - (serverNow() % 1000) + 5);
  }
  tick();

  // Ressincroniza com o servidor de vez em quando (o aparelho pode ter dormido ou o horário mudado).
  function resync() {
    if (entered) return;
    fetch(base + '/status', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.server_now_utc) setServerNow(j.server_now_utc);
      if (j && j.join_opens_at_utc) { var t = Date.parse(j.join_opens_at_utc); if (!isNaN(t)) opensAt = t; }
      tick();
    }).catch(function () {}).then(function () { setTimeout(resync, 60000); });
  }
  setTimeout(resync, 60000);

  function translateError(msg) {
    if (!msg) return 'Erro desconhecido ao acessar dispositivos.';
    if (/Requested device not found/i.test(msg) || /NotFoundError/i.test(msg)) return 'Nenhuma câmera ou microfone encontrado neste aparelho.';
    if (/Permission denied/i.test(msg) || /NotAllowedError/i.test(msg)) return 'Permissão de acesso à câmera/microfone foi negada nas configurações do navegador.';
    if (/NotReadableError/i.test(msg) || /TrackStartError/i.test(msg)) return 'A câmera ou microfone está sendo usado por outro aplicativo.';
    return msg;
  }

  document.getElementById('tc-test').addEventListener('click', function () {
    say('tc-test-status', 'Pedindo acesso à câmera e ao microfone…');
    navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(function (stream) {
      preview = stream;
      document.getElementById('tc-preview').srcObject = stream;
      if (!stream.getAudioTracks().length || !stream.getVideoTracks().length) throw new Error('Câmera ou microfone não encontrado.');
      return post('/dispositivo', { camera_ok: true, microphone_ok: true, mode: 'full', browser: navigator.userAgent });
    }).then(function () {
      deviceOk = true; say('tc-test-status', 'Câmera e microfone funcionando.', 'ok'); update();
    }).catch(function (err) {
      var btnAudio = document.getElementById('tc-audio-only');
      var btnBypass = document.getElementById('tc-bypass');
      if (btnAudio) btnAudio.classList.remove('tc-hidden');
      if (btnBypass) btnBypass.classList.remove('tc-hidden');
      say('tc-test-status', 'Não foi possível usar a câmera: ' + translateError(err.message) + ' Você pode entrar apenas com áudio ou continuar sem câmera abaixo.', 'warn');
    });
  });

  var btnAudio = document.getElementById('tc-audio-only');
  if (btnAudio) {
    btnAudio.addEventListener('click', function () {
      say('tc-test-status', 'Testando microfone para modo somente áudio…');
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        preview = stream;
        // v20: só libera o botão depois que o servidor confirmou o modo (antes liberava mesmo com erro)
        return post('/dispositivo', { camera_ok: false, microphone_ok: true, mode: 'audio_only', browser: navigator.userAgent });
      }, function (err) {
        var e = new Error('Não foi possível usar o microfone: ' + translateError(err && err.message) + ' Use "Continuar sem câmera" para entrar com aviso.');
        e.local = true; throw e;
      }).then(function () {
        deviceOk = true;
        say('tc-test-status', 'Modo somente áudio ativado. O(a) nutricionista será informado(a).', 'ok');
        update();
      }).catch(function (err) {
        deviceOk = false; update();
        var b = document.getElementById('tc-bypass'); if (b) b.classList.remove('tc-hidden');
        say('tc-test-status', err.local ? err.message : 'Não foi possível registrar o modo somente áudio: ' + err.message, 'error');
      });
    });
  }

  var btnBypass = document.getElementById('tc-bypass');
  if (btnBypass) {
    btnBypass.addEventListener('click', function () {
      post('/dispositivo', { camera_ok: false, microphone_ok: false, mode: 'bypass_warning', browser: navigator.userAgent }).then(function () {
        deviceOk = true;
        say('tc-test-status', 'Teste de dispositivo ignorado. Você pode participar sem vídeo.', 'warn');
        update();
      }).catch(function (err) {
        deviceOk = false; update();
        say('tc-test-status', 'Não foi possível registrar a escolha: ' + err.message, 'error');
      });
    });
  }

  document.getElementById('tc-consent').addEventListener('change', function () {
    var box = this;
    if (!box.checked) { consentOk = false; update(); return; }
    post('/consentir', { term_id: Number(document.body.getAttribute('data-tc-term-id')) }).then(function () { consentOk = true; update(); })
      .catch(function (err) { box.checked = false; say('tc-wait-status', err.message, 'error'); showAlert(err.message); });
  });

  document.getElementById('tc-enter').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    hideAlert();
    post('/entrar', {}).then(function () {
      entered = true;
      btn.classList.add('tc-hidden');
      say('tc-wait-status', 'Você está na sala de espera. O(a) nutricionista vai admitir sua entrada. Mantenha esta página aberta.', 'ok');
      poll();
    }).catch(function (err) {
      say('tc-wait-status', err.message, 'warn');
      showAlert(err.message, 'warn');
      lastReady = null;
      // O servidor tem a palavra final: atualiza o relógio e reavalia o botão.
      fetch(base + '/status', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.server_now_utc) setServerNow(j.server_now_utc);
        if (j && j.join_opens_at_utc) { var t = Date.parse(j.join_opens_at_utc); if (!isNaN(t)) opensAt = t; }
      }).catch(function () {}).then(function () { update(); if (!isOpen()) tick(); });
    });
  });

  function poll() {
    fetch(base + '/status', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, body: j }; });
    }).then(function (res) {
      if (!res.ok) { var m = res.body.error || 'A teleconsulta não está mais disponível.'; say('tc-wait-status', m, 'error'); showAlert(m); return; }
      if (res.body.is_admitted) {
        if (preview) preview.getTracks().forEach(function (t) { t.stop(); });
        location.href = '/teleconsulta/sala';
        return;
      }
      setTimeout(poll, 3000);
    }).catch(function () { setTimeout(poll, 6000); });
  }
})();
