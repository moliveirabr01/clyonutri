// Executa o public/assets/js/teleconsulta-webrtc.js REAL em dois pares (nutricionista e paciente),
// com WebRTC do werift e todas as requisições indo ao public/index.php real (SAPI web do php-wasm).
import dgram from 'node:dgram';
import fs from 'node:fs';
import vm from 'node:vm';
import * as werift from 'werift';
import { PHP, PHPRequestHandler, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

// Em sandboxes CI/macOS onde sendto(127.0.0.1) retorna EPERM mas bind(127.0.0.1) é permitido,
// entrega os datagramas UDP STUN/DTLS/SRTP entre os sockets locais vinculados no mesmo processo.
const localUdpSockets = new Map();
const origBind = dgram.Socket.prototype.bind;
const origClose = dgram.Socket.prototype.close;
const origSend = dgram.Socket.prototype.send;
dgram.Socket.prototype.bind = function (...args) {
  this.once('listening', () => { try { localUdpSockets.set(this.address().port, this); } catch {} });
  return origBind.apply(this, args);
};
dgram.Socket.prototype.close = function (...args) {
  try { localUdpSockets.delete(this.address().port); } catch {}
  return origClose.apply(this, args);
};
dgram.Socket.prototype.send = function (buf, port, host, cb) {
  return origSend.call(this, buf, port, host, (err) => {
    if (err && err.code === 'EPERM') {
      const dst = localUdpSockets.get(Number(port));
      if (dst) {
        const srcPort = this.address().port;
        setImmediate(() => dst.emit('message', Buffer.from(buf), { address: '127.0.0.1', family: 'IPv4', port: srcPort, size: buf.length }));
      }
      if (typeof cb === 'function') cb(null);
      return;
    }
    if (typeof cb === 'function') cb(err);
  });
};

const WORK = process.env.WORK || new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
const ids = JSON.parse(process.env.IDS);
let pid = 1;
const makePhp = async () => {
  const p = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: pid++ } }));
  p.mkdir('/work');
  await p.mount('/work', createNodeFsMountHandler(WORK));
  // Sessões em pasta compartilhada entre os processos (como em um servidor real).
  await setPhpIniEntries(p, { 'session.save_path': '/work/storage/sessions', auto_prepend_file: '/work/tests/teleconsulta_navegador/env_teste.php' });
  return p;
};
const php = await makePhp();
// Vários processos PHP, como um servidor real: o cliente envia e consulta sinais em paralelo.
const handler = new PHPRequestHandler({ phpFactory: async () => makePhp(), maxPhpInstances: 4, documentRoot: '/work/public',
  absoluteUrl: 'http://localhost', cookieStore: false,
  getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }) });
const enc = new TextEncoder();

let failures = 0;
const ok = (c, m) => { console.log((c ? '[PASS] ' : '[FAIL] ') + m); if (!c) failures++; };

class Jar {
  constructor() { this.c = {}; }
  async request(method, url, headers = {}, body) {
    const h = { ...headers };
    const ck = Object.entries(this.c).map(([k, v]) => `${k}=${v}`).join('; ');
    if (ck) h.cookie = ck;
    const res = await handler.request({ method, url, headers: h, body: body === undefined ? undefined : enc.encode(body) });
    for (const sc of res.headers['set-cookie'] || []) { const [kv] = sc.split(';'); const i = kv.indexOf('='); this.c[kv.slice(0, i)] = kv.slice(i + 1); }
    return res;
  }
  form(method, url, form) { return this.request(method, url, { 'content-type': 'application/x-www-form-urlencoded' }, new URLSearchParams(form).toString()); }
  // fetch() compatível com o que o script do navegador usa
  fetchFn() {
    return async (url, opts = {}) => {
      const res = await this.request(opts.method || 'GET', url, opts.headers || {}, opts.body);
      const text = res.text;
      return { ok: res.httpStatusCode < 400, status: res.httpStatusCode, json: async () => JSON.parse(text) };
    };
  }
}

// Ambiente de navegador mínimo, com WebRTC do werift.
function browserContext(jar, label) {
  const statuses = [];
  const stream = new werift.MediaStream([
    new werift.MediaStreamTrack({ kind: 'audio' }),
    new werift.MediaStreamTrack({ kind: 'video' }),
  ]);
  stream.getTracks = () => stream.tracks || [];
  stream.getAudioTracks = () => stream.getTracks().filter(t => t.kind === 'audio');
  stream.getVideoTracks = () => stream.getTracks().filter(t => t.kind === 'video');
  for (const t of stream.getTracks()) { t.enabled = true; t.stop = t.stop || (() => {}); }
  class PC extends werift.RTCPeerConnection {
    // A VM de teste só tem loopback: força candidato 127.0.0.1 (ajuste do ambiente, não do produto).
    constructor(cfg) { super({ iceServers: (cfg.iceServers || []).flatMap(s => [].concat(s.urls).map(u => ({ urls: u }))), iceAdditionalHostAddresses: ['127.0.0.1'] }); }
    get localDescription() { const d = super.localDescription; return d && { type: d.type, sdp: d.sdp, toJSON() { return { type: d.type, sdp: d.sdp }; } }; }
  }
  const window = { addEventListener() {}, console };
  const ctx = {
    window, console, setTimeout, clearTimeout, Promise, JSON, Error, encodeURIComponent,
    fetch: jar.fetchFn(),
    RTCPeerConnection: PC,
    navigator: { mediaDevices: { getUserMedia: async () => stream, getDisplayMedia: async () => {
      const screen = new werift.MediaStream([new werift.MediaStreamTrack({ kind: 'video' })]);
      screen.getTracks = () => screen.tracks || []; screen.getVideoTracks = () => screen.getTracks();
      for (const t of screen.getTracks()) { t.stop = t.stop || (() => {}); }
      return screen;
    } } },
    document: { createElement() { return {}; }, head: { appendChild() {} } },
  };
  vm.createContext(ctx);
  vm.runInContext(fs.readFileSync(`${WORK}/public/assets/js/teleconsulta-webrtc.js`, 'utf8'), ctx);
  return { lib: ctx.window.ClyoTeleconsulta, statuses, label, remote: { srcObject: null }, local: { srcObject: null } };
}

// --- Preparar sessão até a admissão (via HTTP real) ---
const pro = new Jar(), pat = new Jar();
const loginPage = (await pro.request('GET', '/login')).text;
await pro.form('POST', '/login', { _csrf_token: loginPage.match(/name="_csrf_token" value="([^"]+)"/)[1], email: 'pro@ex.test', password: 'Senha-Forte-2026!' });
const confirmHtml = (await pro.request('GET', `/teleconsulta/sessao-agenda/${ids.appointment}`)).text;
const open = await pro.form('POST', `/teleconsulta/sessao-agenda/${ids.appointment}`, { _csrf: confirmHtml.match(/name="_csrf" value="([^"]+)"/)[1] });
const sid = open.headers.location[0].match(/teleconsulta\/(\d+)/)[1];
const roomHtml = (await pro.request('GET', `/teleconsulta/${sid}/atendimento`)).text;
const invite = roomHtml.match(/sala-espera\/([a-f0-9]{64})/)[1];
const proCsrf = roomHtml.match(/name="_csrf" value="([^"]+)"/)[1];
await pat.request('GET', `/teleconsulta/sala-espera/${invite}`); // F05: troca o link pelo cookie
const waitHtml = (await pat.request('GET', '/teleconsulta/sala-espera')).text;
const patCsrf = waitHtml.match(/data-tc-csrf="([^"]+)"/)[1];
const pj = { 'content-type': 'application/json', 'x-csrf-token': patCsrf };
await pat.request('POST', '/teleconsulta/sala-espera/dispositivo', pj, JSON.stringify({ camera_ok: true, microphone_ok: true }));
await pat.request('POST', '/teleconsulta/sala-espera/consentir', pj, JSON.stringify({ term_id: Number(waitHtml.match(/data-tc-term-id="(\d+)"/)[1]) }));
await pat.request('POST', '/teleconsulta/sala-espera/entrar', pj, '{}');
await pro.form('POST', `/teleconsulta/${sid}/admitir`, { _csrf: proCsrf });

// --- Dois pares executando o script das telas ---
const P = browserContext(pro, 'nutricionista');
const Q = browserContext(pat, 'paciente');
let ended = false;
const mk = (b, role, credentialUrl, csrf) => b.lib.start({
  role, csrf, credentialUrl, signalBase: '/teleconsulta/sinal/',
  localVideo: b.local, remoteVideo: b.remote, dailyContainer: {},
  onStatus: (t) => b.statuses.push(t), onEnded: () => { if (role === 'patient') ended = true; },
  onScreenShare: (on) => { b.screen = on; },
});
const callPro = mk(P, 'professional', `/teleconsulta/${sid}/midia`, proCsrf);
await new Promise(r => setTimeout(r, 1500));
const callPat = mk(Q, 'patient', '/teleconsulta/sala/midia', patCsrf);

const waitFor = async (fn, ms) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (fn()) return true; await new Promise(r => setTimeout(r, 200)); } return false; };
const connected = await waitFor(() => callPro.pc?.connectionState === 'connected' && callPat.pc?.connectionState === 'connected', 20000);
ok(connected, `ICE + DTLS conectados nos dois pares (nutri=${callPro.pc?.connectionState}, paciente=${callPat.pc?.connectionState})`);
ok(!!P.remote.srcObject && !!Q.remote.srcObject, 'cada lado recebeu as faixas remotas (ontrack)');
ok(P.statuses.some(s => s.startsWith('Conectado')) && Q.statuses.some(s => s.startsWith('Conectado')), 'telas exibem "Conectado"');

// Compartilhamento de tela (NI2-430): troca de faixa sem renegociar; paciente é avisado.
ok(callPro.canShareScreen() && !callPat.canShareScreen(), 'só o nutricionista tem a opção de compartilhar tela');
const shared = await callPro.startScreenShare();
const patientSaw = await waitFor(() => Q.screen === true, 15000);
ok(shared && patientSaw && callPro.pc.connectionState === 'connected', 'nutricionista compartilha a tela e o paciente é avisado, sem derrubar a chamada');
await callPro.stopScreenShare();
ok(await waitFor(() => Q.screen === false, 15000), 'fim do compartilhamento chega ao paciente');
const forbidden = await pat.request('POST', `/teleconsulta/sinal/${callPat.cred.room_name}`, { 'content-type': 'application/json', 'x-teleconsulta-credential': callPat.cred.token }, JSON.stringify({ type: 'screen', payload: { on: true } }));
ok(forbidden.httpStatusCode === 403, `paciente não pode anunciar compartilhamento (HTTP ${forbidden.httpStatusCode})`);
const shareEvents = JSON.parse((await php.run({ code: `<?php $d=new PDO('sqlite:/work/storage/teleconsulta_navegador.sqlite'); echo json_encode($d->query("SELECT event_type FROM teleconsultation_events WHERE event_type LIKE 'screen_share%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));` })).text);
ok(JSON.stringify(shareEvents) === '["screen_share_started","screen_share_stopped"]', `auditoria do compartilhamento: ${JSON.stringify(shareEvents)}`);

// Reconexão: paciente recarrega (novo par) e o nutricionista renegocia.
callPat.stop(false);
const Q2 = browserContext(pat, 'paciente-recarregado');
const reloadHtml = (await pat.request('GET', '/teleconsulta/sala')).text;
const patCsrf2 = reloadHtml.match(/data-tc-csrf="([^"]+)"/)[1];
const callPat2 = mk(Q2, 'patient', '/teleconsulta/sala/midia', patCsrf2);
const reconnected = await waitFor(() => callPat2.pc?.connectionState === 'connected' && callPro.pc?.connectionState === 'connected', 60000);
ok(reconnected, 'paciente recarrega a página e a chamada se restabelece');

// Encerramento pelo nutricionista corta o paciente.
await pro.form('POST', `/teleconsulta/${sid}/encerrar`, { _csrf: proCsrf });
const cut = await waitFor(() => callPat2.stopped, 15000);
ok(cut, 'após encerrar, o cliente do paciente perde o acesso e para');
const signals = JSON.parse((await php.run({ code: `<?php $d=new PDO('sqlite:/work/storage/teleconsulta_navegador.sqlite'); echo $d->query('SELECT COUNT(*) FROM teleconsultation_signals')->fetchColumn();` })).text);
ok(signals === 0, `sinalização apagada ao encerrar (${signals} linhas)`);

// Métricas (NI2-432): calculadas da auditoria, sem dados pessoais.
const metrics = JSON.parse((await php.run({ code: `<?php require '/work/vendor/autoload.php'; putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=/work/storage/teleconsulta_navegador.sqlite');
  $to = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
  echo json_encode((new ClyoNutri\\Domain\\Telehealth\\TeleconsultationMetricsService())->summary($to->modify('-1 day'), $to));` })).text);
ok(metrics.admitted === 1 && metrics.media_connected === 1 && metrics.connection_rate === 1 && metrics.screen_shares === 1,
  `métricas refletem a chamada (admitidos=${metrics.admitted}, conectados=${metrics.media_connected}, telas=${metrics.screen_shares})`);
ok(!/@|Paciente HTTP|Nutri HTTP/.test(JSON.stringify(metrics)), 'métricas sem nome ou e-mail');

callPro.stop(false);
console.log(failures ? `\n${failures} FALHA(S) WEBRTC` : '\nCHAMADA WEBRTC P2P ESTABELECIDA PELO SCRIPT DAS TELAS');
setTimeout(() => process.exit(failures ? 1 : 0), 300);
