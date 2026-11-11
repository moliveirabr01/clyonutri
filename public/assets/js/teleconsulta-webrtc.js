/*
 * ClyoNutri — cliente de mídia do Consultório Online (NI2-242/245/251).
 *
 * Provedor padrão: WebRTC nativo ponto a ponto. Áudio/vídeo vão direto entre
 * os navegadores (DTLS-SRTP); o servidor só repassa a sinalização
 * (hello/offer/answer/candidate/bye) e revalida o acesso a cada requisição.
 * O nutricionista sempre faz a offer; o paciente anuncia presença com "hello".
 * Nada é gravado. Sem TURN, algumas redes restritivas não conectam — a tela
 * informa isso em vez de fingir conexão.
 */
(function () {
  'use strict';

  function jsonFetch(url, options) {
    return fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options)).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        if (!res.ok) {
          var err = new Error(body.error || ('HTTP ' + res.status));
          err.status = res.status;
          throw err;
        }
        return body;
      });
    });
  }

  function Call(opts) {
    this.opts = opts;
    this.role = opts.role; // 'professional' | 'patient'
    this.lastSignalId = 0;
    this.pc = null;
    this.localStream = null;
    this.pendingCandidates = [];
    this.stopped = false;
    this.pollDelay = 1000;
    this.failTimer = null;
    this.recoverAttempts = 0;
    this.lastRecoverAt = 0;
  }

  Call.prototype.warn = function (where, err) {
    if (window.console && console.warn) console.warn('[teleconsulta] ' + where + ': ' + (err && err.message ? err.message : err));
  };

  Call.prototype.status = function (text, tone) {
    if (this.opts.onStatus) this.opts.onStatus(text, tone || 'info');
  };

  /** v54.27: estado visual da chamada (connecting|waiting|connected|unstable|left|failed|ended). */
  Call.prototype.state = function (st) {
    if (this.uiState === st) return;
    this.uiState = st;
    if (this.opts.onState) this.opts.onState(st);
  };

  Call.prototype.start = function () {
    var self = this;
    self.status('Preparando câmera e microfone…');
    self.state('connecting');
    return jsonFetch(self.opts.credentialUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': self.opts.csrf }
    }).then(function (cred) {
      self.cred = cred;
      self.signalUrl = self.opts.signalBase + encodeURIComponent(cred.room_name);
      if (cred.provider === 'daily_co') return self.startDaily(cred);
      return self.startP2P(cred);
    }).catch(function (err) {
      self.status(err.status === 503
        ? 'Vídeo indisponível: o provedor de mídia não está configurado.'
        : 'Não foi possível iniciar o vídeo: ' + err.message, 'error');
      self.state('failed');
      throw err;
    });
  };

  // ---------------------------------------------------------------- P2P
  Call.prototype.startP2P = function (cred) {
    var self = this;
    return navigator.mediaDevices.getUserMedia({ audio: true, video: { width: { ideal: 1280 }, height: { ideal: 720 } } })
      .then(function (stream) {
        self.localStream = stream;
        self.opts.localVideo.srcObject = stream;
        self.newPeer();
        if (self.role === 'patient') {
          self.status('Aguardando o nutricionista conectar o vídeo…');
          self.state('waiting');
          // Lê só o que vier depois deste hello: ignora offers de uma conexão anterior (recarga).
          return self.send('hello', {}).then(function (res) {
            if (res && res.id) self.lastSignalId = res.id;
            self.poll();
          });
        }
        self.status('Aguardando a câmera do paciente…');
        self.state('waiting');
        self.poll();
      })
      .catch(function (err) {
        if (err && err.name && /NotAllowed|NotFound|NotReadable/.test(err.name)) {
          self.status('Sem acesso à câmera ou ao microfone. Verifique as permissões do navegador.', 'error');
        }
        throw err;
      });
  };

  Call.prototype.newPeer = function () {
    var self = this;
    if (self.pc) { try { self.pc.close(); } catch (e) {} }
    self.pendingCandidates = [];
    var pc = new RTCPeerConnection({ iceServers: self.cred.ice_servers || [] });
    self.pc = pc;
    self.localStream.getTracks().forEach(function (t) {
      var sendTrack = (t.kind === 'video' && self.screenStream) ? self.screenStream.getVideoTracks()[0] : t;
      pc.addTrack(sendTrack, self.localStream);
    });

    pc.ontrack = function (ev) {
      if (self.pc !== pc) return;
      var video = self.opts.remoteVideo;
      if (video.srcObject !== ev.streams[0]) video.srcObject = ev.streams[0];
      // iOS/Safari podem bloquear áudio sem toque do usuário: oferece um botão em vez de falhar calado.
      if (typeof video.play === 'function') {
        var played = video.play();
        if (played && typeof played.catch === 'function') played.catch(function () { if (self.opts.onAutoplayBlocked) self.opts.onAutoplayBlocked(); });
      }
    };
    pc.onicecandidate = function (ev) {
      if (self.pc !== pc || !ev.candidate) return;
      self.send('candidate', typeof ev.candidate.toJSON === 'function' ? ev.candidate.toJSON() : ev.candidate);
    };
    pc.onconnectionstatechange = function () {
      if (self.pc !== pc || self.stopped) return; // eventos de um par já substituído
      var st = pc.connectionState;
      if (st === 'connected') {
        clearTimeout(self.failTimer);
        self.recoverAttempts = 0;
        self.report('connected');
        self.pollDelay = 2500;
        self.status('Conectado. Áudio e vídeo diretos e criptografados entre vocês.', 'ok');
        self.state('connected');
      } else if (st === 'disconnected') {
        self.report('reconnecting');
        self.status('Conexão instável — tentando reconectar…', 'warn');
        self.state('unstable');
        clearTimeout(self.failTimer);
        self.failTimer = setTimeout(function () { if (pc.connectionState !== 'connected') self.recover(); }, 8000);
      } else if (st === 'failed') {
        self.recover();
      }
    };
    return pc;
  };

  /**
   * Recuperação com intervalo mínimo e limite de tentativas (evita tempestade de
   * renegociação): o nutricionista reinicia o ICE; o paciente se reanuncia.
   */
  Call.prototype.recover = function () {
    if (this.stopped) return;
    var now = Date.now();
    if (now - this.lastRecoverAt < 10000) return;
    this.lastRecoverAt = now;
    this.recoverAttempts++;
    this.report(this.recoverAttempts > 4 ? 'gave_up' : 'failed');
    if (this.recoverAttempts > 4) {
      this.status('Não foi possível conectar o vídeo nesta rede. Tente outra rede (Wi-Fi ↔ 4G) ou outro dispositivo e recarregue a página. Se preferir, combine com o(a) nutricionista outra forma de atendimento.', 'error');
      this.state('failed');
      return;
    }
    this.pollDelay = 1000;
    this.state('unstable');
    this.status(this.cred.has_turn
      ? 'A conexão caiu. Tentando novamente…'
      : 'Não conseguimos ligar o vídeo nesta rede. Tentando de novo — se persistir, troque de rede (Wi-Fi ↔ 4G) ou use outro dispositivo.', 'warn');
    if (this.role === 'professional') {
      this.makeOffer(true);
    } else {
      var self = this;
      this.send('hello', { reconnect: true }).then(function (res) {
        if (res && res.id) self.lastSignalId = Math.max(self.lastSignalId, res.id);
      });
    }
  };

  Call.prototype.makeOffer = function (iceRestart) {
    var self = this;
    var pc = self.pc;
    return pc.createOffer(iceRestart ? { iceRestart: true } : undefined)
      .then(function (offer) { return pc.setLocalDescription(offer); })
      .then(function () { self.send('offer', pc.localDescription.toJSON()); });
  };

  Call.prototype.handle = function (msg) {
    var self = this;
    var pc = self.pc;
    switch (msg.type) {
      case 'hello':
        if (self.role === 'professional') {
          self.newPeer();
          self.status('Paciente conectado à sala. Estabelecendo vídeo…');
          self.state('connecting');
          return self.makeOffer(false);
        }
        return null;
      case 'offer':
        if (self.role !== 'patient') return null;
        if (pc.signalingState !== 'stable' || pc.remoteDescription) self.newPeer();
        pc = self.pc;
        return pc.setRemoteDescription(msg.payload)
          .then(function () { return self.flushCandidates(); })
          .then(function () { return pc.createAnswer(); })
          .then(function (answer) { return pc.setLocalDescription(answer); })
          .then(function () { self.send('answer', pc.localDescription.toJSON()); });
      case 'answer':
        if (self.role !== 'professional' || pc.signalingState !== 'have-local-offer') return null;
        return pc.setRemoteDescription(msg.payload).then(function () { return self.flushCandidates(); });
      case 'candidate':
        if (!pc.remoteDescription) { self.pendingCandidates.push(msg.payload); return null; }
        return pc.addIceCandidate(msg.payload).catch(function () {});
      case 'screen':
        if (self.role === 'patient') {
          if (self.opts.remoteVideo.classList) self.opts.remoteVideo.classList.toggle('is-screen', !!(msg.payload && msg.payload.on));
          if (self.opts.onScreenShare) self.opts.onScreenShare(!!(msg.payload && msg.payload.on));
        }
        return null;
      case 'bye':
        self.opts.remoteVideo.srcObject = null;
        self.status(self.role === 'professional' ? 'O paciente saiu da chamada.' : 'O nutricionista saiu da chamada.', 'warn');
        self.state('left');
        return null;
    }
    return null;
  };

  Call.prototype.flushCandidates = function () {
    var pc = this.pc;
    var list = this.pendingCandidates.splice(0);
    return Promise.all(list.map(function (c) { return pc.addIceCandidate(c).catch(function () {}); }));
  };

  /** Telemetria de conexão (NI2-432): só muda de estado, sem conteúdo. */
  Call.prototype.report = function (state) {
    if (this.lastReported === state || !this.cred || this.cred.provider === 'daily_co') return;
    this.lastReported = state;
    this.send('state', { state: state });
  };

  Call.prototype.send = function (type, payload) {
    var self = this;
    if (self.stopped) return Promise.resolve();
    return jsonFetch(self.signalUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Teleconsulta-Credential': self.cred.token },
      body: JSON.stringify({ type: type, payload: payload })
    }).catch(function (err) { self.warn('envio ' + type, err); self.onAccessError(err); });
  };

  Call.prototype.poll = function () {
    var self = this;
    if (self.stopped) return;
    jsonFetch(self.signalUrl + '?after=' + self.lastSignalId, {
      headers: { 'X-Teleconsulta-Credential': self.cred.token }
    }).then(function (res) {
      var chain = Promise.resolve();
      (res.messages || []).forEach(function (m) {
        self.lastSignalId = Math.max(self.lastSignalId, m.id);
        chain = chain.then(function () { return self.handle(m); }).catch(function (e) { self.warn('sinal ' + m.type, e); });
      });
      return chain;
    }).catch(function (err) {
      self.onAccessError(err);
    }).then(function () {
      if (!self.stopped) setTimeout(function () { self.poll(); }, self.pollDelay);
    });
  };

  Call.prototype.onAccessError = function (err) {
    if (err && (err.status === 403 || err.status === 409)) {
      this.stop(false);
      this.status('A teleconsulta foi encerrada.', 'info');
      this.state('ended');
      if (this.opts.onEnded) this.opts.onEnded();
    } else if (err && err.status === 429) {
      this.pollDelay = 5000;
    }
  };

  // -------------------------------------------------------------- Daily (opcional)
  Call.prototype.startDaily = function (cred) {
    var self = this;
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://unpkg.com/@daily-co/daily-js';
      s.onload = resolve;
      s.onerror = function () { reject(new Error('Falha ao carregar o cliente de vídeo.')); };
      document.head.appendChild(s);
    }).then(function () {
      if (self.opts.dailyContainer) self.opts.dailyContainer.classList.remove('tc-hidden');
      var frame = window.DailyIframe.createFrame(self.opts.dailyContainer, { iframeStyle: { width: '100%', height: '100%', border: '0' } });
      self.daily = frame;
      frame.on('left-meeting', function () { if (self.opts.onEnded) self.opts.onEnded(); });
      return frame.join({ url: cred.room_url, token: cred.token });
    }).then(function () { self.status('Conectado.', 'ok'); self.state('connected'); });
  };

  // ------------------------------------------------------------- controles
  Call.prototype.toggle = function (kind) {
    if (!this.localStream) return null;
    var tracks = kind === 'audio' ? this.localStream.getAudioTracks() : this.localStream.getVideoTracks();
    var enabled = false;
    tracks.forEach(function (t) { t.enabled = !t.enabled; enabled = t.enabled; });
    return enabled;
  };

  /**
   * Compartilhamento de tela (NI2-430, só nutricionista): troca a faixa de vídeo
   * enviada pela da tela, sem renegociar; ao parar (ou o navegador encerrar),
   * volta para a câmera.
   */
  Call.prototype.canShareScreen = function () {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) && this.role === 'professional';
  };

  Call.prototype.videoSender = function () {
    if (!this.pc) return null;
    var senders = this.pc.getSenders();
    for (var i = 0; i < senders.length; i++) if (senders[i].track && senders[i].track.kind === 'video') return senders[i];
    return null;
  };

  Call.prototype.startScreenShare = function () {
    var self = this;
    if (!self.canShareScreen() || self.screenStream) return Promise.resolve(false);
    return navigator.mediaDevices.getDisplayMedia({ video: true, audio: false }).then(function (stream) {
      var track = stream.getVideoTracks()[0];
      var sender = self.videoSender();
      if (!sender || !track) { stream.getTracks().forEach(function (t) { t.stop(); }); return false; }
      self.screenStream = stream;
      track.onended = function () { self.stopScreenShare(); };
      return sender.replaceTrack(track).then(function () {
        self.opts.localVideo.srcObject = stream;
        self.opts.localVideo.classList.add('is-screen');
        self.send('screen', { on: true });
        if (self.opts.onScreenShare) self.opts.onScreenShare(true);
        return true;
      });
    });
  };

  Call.prototype.stopScreenShare = function () {
    var self = this;
    if (!self.screenStream) return Promise.resolve();
    var cam = self.localStream && self.localStream.getVideoTracks()[0];
    self.screenStream.getTracks().forEach(function (t) { t.onended = null; t.stop(); });
    self.screenStream = null;
    var sender = self.videoSender();
    return (sender && cam ? sender.replaceTrack(cam) : Promise.resolve()).then(function () {
      self.opts.localVideo.srcObject = self.localStream;
      self.opts.localVideo.classList.remove('is-screen');
      self.send('screen', { on: false });
      if (self.opts.onScreenShare) self.opts.onScreenShare(false);
    });
  };

  Call.prototype.stop = function (announce) {
    if (this.screenStream) { this.screenStream.getTracks().forEach(function (t) { t.stop(); }); this.screenStream = null; }
    if (this.stopped) return;
    if (announce !== false && this.cred && this.cred.provider !== 'daily_co') this.send('bye', {});
    this.stopped = true;
    clearTimeout(this.failTimer);
    if (this.pc) { try { this.pc.close(); } catch (e) {} }
    if (this.localStream) this.localStream.getTracks().forEach(function (t) { t.stop(); });
    if (this.daily) { try { this.daily.leave(); } catch (e) {} }
  };

  window.ClyoTeleconsulta = {
    start: function (opts) {
      var call = new Call(opts);
      call.start().catch(function () {});
      window.addEventListener('pagehide', function () { call.stop(); });
      return call;
    },
    json: jsonFetch
  };
})();
