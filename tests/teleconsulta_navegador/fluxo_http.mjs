// Teste HTTP ponta a ponta do Consultório Online através do public/index.php real (SAPI web do php-wasm).
import { PHP, PHPRequestHandler, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const WORK = process.env.WORK || new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
const ids = JSON.parse(process.env.IDS);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/work');
await php.mount('/work', createNodeFsMountHandler(WORK));
await setPhpIniEntries(php, { auto_prepend_file: '/work/tests/teleconsulta_navegador/env_teste.php' });
const handler = new PHPRequestHandler({
  php, documentRoot: '/work/public', absoluteUrl: 'http://localhost', cookieStore: false,
  getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
});

let failures = 0;
const ok = (cond, msg) => { if (cond) console.log('[PASS] ' + msg); else { failures++; console.log('[FAIL] ' + msg); } };
class Browser {
  constructor() { this.cookies = {}; }
  async req(method, url, { form, json, headers = {} } = {}) {
    const h = { ...headers };
    const c = Object.entries(this.cookies).map(([k, v]) => `${k}=${v}`).join('; ');
    if (c) h.cookie = c;
    let body;
    if (form) { h['content-type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
    if (json !== undefined) { h['content-type'] = 'application/json'; body = JSON.stringify(json); }
    const res = await handler.request({ method, url, headers: h, body });
    for (const sc of res.headers['set-cookie'] || []) { const [kv] = sc.split(';'); const i = kv.indexOf('='); this.cookies[kv.slice(0, i)] = kv.slice(i + 1); }
    let data = null; try { data = JSON.parse(res.text); } catch {}
    return { status: res.httpStatusCode, location: (res.headers.location || [])[0] || '', setCookie: res.headers['set-cookie'] || [], referrer: (res.headers['referrer-policy'] || []).join(','), cache: (res.headers['cache-control'] || []).join(','), text: res.text, json: data };
  }
}
const pro = new Browser(), pat = new Browser(), sec = new Browser();
const pat2 = new Browser();
const login = async (b, email) => {
  const page = await b.req('GET', '/login');
  const csrf = (page.text.match(/name="_csrf_token" value="([^"]+)"/) || [])[1];
  return b.req('POST', '/login', { form: { _csrf_token: csrf, email, password: 'Senha-Forte-2026!' } });
};

const l = await login(pro, 'pro@ex.test');
ok(l.status === 302, `login do nutricionista (HTTP ${l.status})`);
await login(sec, 'sec@ex.test');

const secOpen = await sec.req('GET', `/teleconsulta/sessao-agenda/${ids.appointment}`);
ok(secOpen.status === 403, `secretaria não abre o consultório pela agenda (HTTP ${secOpen.status})`);
const confirmPage = await pro.req('GET', `/teleconsulta/sessao-agenda/${ids.appointment}`);
ok(confirmPage.status === 200 && /method="POST"/.test(confirmPage.text), 'GET da agenda só mostra confirmação (sem criar sessão)');
const getCsrf = (confirmPage.text.match(/name="_csrf" value="([^"]+)"/) || [])[1];
const noCsrfOpen = await pro.req('POST', `/teleconsulta/sessao-agenda/${ids.appointment}`, { form: {} });
ok(noCsrfOpen.status === 403, `criar sessão sem CSRF recusado (HTTP ${noCsrfOpen.status})`);
const open = await pro.req('POST', `/teleconsulta/sessao-agenda/${ids.appointment}`, { form: { _csrf: getCsrf } });
const sid = (open.location.match(/\/teleconsulta\/(\d+)\/atendimento/) || [])[1];
ok(!!sid, `criar pela agenda (POST) redireciona para a sala (${open.status} → ${open.location})`);
const again = await pro.req('GET', `/teleconsulta/sessao-agenda/${ids.appointment}`);
ok(again.status === 302 && again.location === `/teleconsulta/${sid}/atendimento`, 'GET com sessão existente apenas redireciona');
const room = await pro.req('GET', `/teleconsulta/${sid}/atendimento`);
const invite = (room.text.match(/\/teleconsulta\/sala-espera\/([a-f0-9]{64})/) || [])[1];
const proCsrf = (room.text.match(/name="_csrf" value="([^"]+)"/) || [])[1];
ok(!/<script>/.test(room.text) && !/on(click|submit)=/.test(room.text), 'sala do nutricionista sem JavaScript inline (CSP script-src self)');
ok(room.status === 200 && !!invite, 'sala do nutricionista mostra o convite uma vez');
const room2 = await pro.req('GET', `/teleconsulta/${sid}/atendimento`);
ok(!/sala-espera\/[a-f0-9]{64}/.test(room2.text), 'convite em claro não reaparece ao recarregar');

const secRoom = await sec.req('GET', `/teleconsulta/${sid}/atendimento`);
ok(secRoom.status === 403, `secretaria bloqueada na sala (HTTP ${secRoom.status})`);
const anon = await pat.req('GET', `/teleconsulta/${sid}/estado`);
ok(anon.status !== 200, `estado do profissional sem login recusado (HTTP ${anon.status})`);

// F05: o link troca o token por cookie HttpOnly e redireciona para a URL limpa.
const exch = await pat.req('GET', `/teleconsulta/sala-espera/${invite}`);
const ck = exch.setCookie.find(c => c.startsWith('clyo_tele=')) || '';
ok(exch.status === 303 && exch.location === '/teleconsulta/sala-espera', `link vira redirect 303 para URL limpa (${exch.status} → ${exch.location})`);
ok(/HttpOnly/i.test(ck) && /SameSite=Lax/i.test(ck) && /Path=\/teleconsulta\//i.test(ck), `cookie do convite HttpOnly, SameSite=Lax, Path=/teleconsulta/ (${ck.replace(/=[a-f0-9]{64}/, '=…')})`);
ok(/no-referrer/i.test(exch.referrer) && /no-store/i.test(exch.cache), `troca do link com Referrer-Policy no-referrer e no-store (${exch.referrer} / ${exch.cache})`);
const noCookie = await pat2.req('GET', '/teleconsulta/sala-espera');
ok(noCookie.status === 403, `URL limpa sem cookie não abre nada (HTTP ${noCookie.status})`);
const wait = await pat.req('GET', '/teleconsulta/sala-espera');
ok(!wait.text.includes(invite), 'token não aparece no HTML da sala de espera');
const patCsrf = (wait.text.match(/data-tc-csrf="([^"]+)"/) || [])[1];
ok(wait.status === 200 && !!patCsrf, 'paciente abre a sala de espera');
const noCsrf = await pat.req('POST', '/teleconsulta/sala-espera/dispositivo', { json: { camera_ok: true, microphone_ok: true } });
ok(noCsrf.status === 403, `ação do paciente sem CSRF recusada (HTTP ${noCsrf.status})`);
const H = { 'x-csrf-token': patCsrf };
ok((await pat.req('POST', '/teleconsulta/sala-espera/dispositivo', { json: { camera_ok: true, microphone_ok: true, browser: 'Chrome/130' }, headers: H })).status === 200, 'teste de dispositivo registrado');
ok((await pat.req('POST', '/teleconsulta/sala-espera/consentir', { json: {}, headers: H })).status === 200, 'consentimento registrado');
ok((await pat.req('POST', '/teleconsulta/sala-espera/entrar', { json: {}, headers: H })).status === 200, 'paciente entra na sala de espera');
const st = await pat.req('GET', '/teleconsulta/sala-espera/status');
ok(st.status === 200 && JSON.stringify(Object.keys(st.json)) === '["status","is_admitted"]', `status do lobby sem sala/credencial: ${st.text}`);
const early = await pat.req('POST', '/teleconsulta/sala/midia', { json: {}, headers: H });
ok(early.status === 409, `credencial do paciente antes da admissão recusada (HTTP ${early.status})`);

// Rascunho da evolução (NI2-249) pela rota real
const d1 = await pro.req('POST', `/teleconsulta/${sid}/rascunho`, { json: { version: 0, title: 'Evolução', notes: 'rascunho sintético' }, headers: { 'x-csrf-token': proCsrf } });
ok(d1.status === 200 && d1.json.version === 1, `rascunho salvo (HTTP ${d1.status})`);
const d2 = await pro.req('POST', `/teleconsulta/${sid}/rascunho`, { json: { version: 0, notes: 'aba antiga' }, headers: { 'x-csrf-token': proCsrf } });
ok(d2.status === 409 && d2.json.server.notes === 'rascunho sintético', `conflito de rascunho devolve 409 (HTTP ${d2.status})`);
const d3 = await pro.req('POST', `/teleconsulta/${sid}/rascunho`, { json: { version: 1, notes: 'x' } });
ok(d3.status === 403, `rascunho sem CSRF recusado (HTTP ${d3.status})`);
const secDraft = await sec.req('GET', `/teleconsulta/${sid}/rascunho`);
ok(secDraft.status === 403, `secretaria não lê o rascunho (HTTP ${secDraft.status})`);

const proState = await pro.req('GET', `/teleconsulta/${sid}/estado`);
ok(proState.json && proState.json.patient_waiting === true, 'painel do nutricionista vê o paciente aguardando');
const adm = await pro.req('POST', `/teleconsulta/${sid}/admitir`, { form: { _csrf: proCsrf } });
ok(adm.status === 302, `admissão (HTTP ${adm.status})`);
const st2 = await pat.req('GET', '/teleconsulta/sala-espera/status');
ok(st2.json && st2.json.is_admitted === true, 'paciente vê que foi admitido');
ok((await pat.req('GET', '/teleconsulta/sala')).status === 200, 'paciente abre a sala de vídeo (URL limpa)');
const legacy = await pat.req('POST', `/teleconsulta/sala-espera/${invite}/entrar`, { json: {}, headers: H });
ok(legacy.status === 404, `rota antiga com token na URL não existe mais (HTTP ${legacy.status})`);

const proCred = await pro.req('POST', `/teleconsulta/${sid}/midia`, { json: {}, headers: { 'x-csrf-token': proCsrf } });
const patCred = await pat.req('POST', '/teleconsulta/sala/midia', { json: {}, headers: H });
ok(proCred.status === 200 && patCred.status === 200, `credenciais emitidas (${proCred.status}/${patCred.status})`);
ok(proCred.json.provider === 'webrtc_p2p' && Array.isArray(proCred.json.ice_servers) && proCred.json.token.split('.').length === 2, 'credencial WebRTC P2P assinada com servidores ICE');
const roomName = proCred.json.room_name;
const sendHello = await pat.req('POST', `/teleconsulta/sinal/${roomName}`, { json: { type: 'hello', payload: {} }, headers: { 'x-teleconsulta-credential': patCred.json.token } });
ok(sendHello.status === 200, `sinal do paciente aceito sem cookie CSRF (HTTP ${sendHello.status})`);
const inbox = await pro.req('GET', `/teleconsulta/sinal/${roomName}?after=0`, { headers: { 'x-teleconsulta-credential': proCred.json.token } });
ok(inbox.json && inbox.json.messages.length === 1 && inbox.json.messages[0].type === 'hello', 'nutricionista recebe o hello');
const forged = await pat.req('GET', `/teleconsulta/sinal/${roomName}?after=0`, { headers: { 'x-teleconsulta-credential': 'x.y' } });
ok(forged.status === 403, `credencial forjada recusada (HTTP ${forged.status})`);

const end = await pro.req('POST', `/teleconsulta/${sid}/encerrar`, { form: { _csrf: proCsrf } });
ok(end.status === 302 && end.location.startsWith(`/consulta/${ids.appointment}`), `encerrar leva à conclusão clínica (${end.location})`);
const after = await pat.req('GET', `/teleconsulta/sinal/${roomName}?after=0`, { headers: { 'x-teleconsulta-credential': patCred.json.token } });
ok(after.status === 403, `sinalização cortada após encerrar (HTTP ${after.status})`);
const link = await pat.req('GET', `/teleconsulta/sala-espera/${invite}`);
ok(link.status === 403 || link.status === 409, `convite inutilizado após encerrar (HTTP ${link.status})`);
const clean = await pat.req('GET', '/teleconsulta/sala-espera');
ok(clean.status === 403 || clean.status === 409, `cookie também perde o acesso após encerrar (HTTP ${clean.status})`);

console.log(failures ? `\n${failures} FALHA(S) HTTP` : '\nFLUXO HTTP DO CONSULTÓRIO ONLINE APROVADO');
process.exit(failures ? 1 : 0);
