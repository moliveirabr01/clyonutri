// NI2-89 — API versionada do paciente: teste real por HTTP.
// public/index.php em `php -S` (APP_ENV=production), SQLite isolado com dados fictícios (tests/support/ni2_89_fixture.php).
// Critérios: /api/v1; autenticação; autorização por recurso (IDOR); erros padronizados; documentação mínima.
//   node tests/ni2_89_patient_api_real_test.cjs   [RESULTS_JSON=…]
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync, readFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-89-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo' };
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 400) : ''}`);
}
const php = (args) => execFileSync('php', [join(root, 'tests/support/ni2_89_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(php(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const LEAK = /(SQLSTATE|PDOException|Stack trace|\.php(:| on line)|#\d+ \/|Uncaught|Fatal error|\/home\/|\/tmp\/)/i;

(async () => {
  php(['prepare']);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const call = async (method, path, { token, body, headers = {}, raw } = {}) => {
    const h = { Accept: 'application/json', ...headers };
    if (token) h.Authorization = 'Bearer ' + token;
    if (body !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: raw !== undefined ? raw : (body !== undefined ? JSON.stringify(body) : undefined), redirect: 'manual' });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* não JSON */ }
    return { status: res.status, ct: res.headers.get('content-type') || '', text, json };
  };
  const errorShapeOk = (r) => /application\/json/.test(r.ct) && r.json && typeof r.json.error === 'string' && r.json.error.length > 0 && !LEAK.test(r.text);
  const errors = []; // todas as respostas de erro observadas, para o critério de padronização
  const seen = (label, r) => { if (r.status >= 400) errors.push({ label, status: r.status, ct: r.ct, ok: errorShapeOk(r), body: r.text.slice(0, 160), hasCode: !!(r.json && r.json.code), hasRid: !!(r.json && r.json.request_id) }); return r; };
  const login = async (email, password) => seen('login ' + email, await call('POST', '/api/v1/auth/patient-login', { body: { email, password } }));
  try {
    // ------------------------------------------------------------ inventário de rotas
    const index = readFileSync(join(root, 'public/index.php'), 'utf8');
    const routes = [...index.matchAll(/\$router->(get|post|put|patch|delete)\('(\/api\/[^']+)'/g)].map((m) => `${m[1].toUpperCase()} ${m[2]}`)
      .flatMap((r) => r.endsWith('/patient-onboarding/') ? ['preview', 'complete'].map((op) => r + op) : [r]); // rota montada em laço no index.php
    const patientRoutes = routes.filter((r) => /\/api\/v1\/(patient\/|auth\/patient)/.test(r));
    check('versão', 'Rotas do paciente estão todas sob /api/v1', patientRoutes.length > 0 && routes.filter((r) => /patient/.test(r)).every((r) => r.includes('/api/v1/')), routes.filter((r) => /patient/.test(r) && !r.includes('/api/v1/')));
    const appSrc = readFileSync(join(root, 'mobile/src/services/api.ts'), 'utf8');
    const appPaths = [...new Set([...appSrc.matchAll(/['`](\/api\/v1\/(?:patient|auth\/patient)[^'`?$]*)/g)].map((m) => m[1].replace(/\/$/, '')))];
    const known = routes.map((r) => r.split(' ')[1].replace(/\{[^}]+\}/g, 'X'));
    const missing = appPaths.filter((p) => !known.some((k) => k === p || k.startsWith(p + '/')));
    check('versão', 'Toda rota de paciente chamada pelo app existe no servidor', missing.length === 0, missing);

    // ------------------------------------------------------------ autenticação
    const anon = [];
    for (const r of patientRoutes.filter((x) => !/auth\/patient-(login|onboarding)/.test(x))) {
      const [m, p] = r.split(' '); const path = p.replace(/\{[^}]+\}/g, '1');
      const res = seen('sem token ' + r, await call(m, path, m === 'POST' ? { body: {} } : {}));
      anon.push({ r, status: res.status, shape: errorShapeOk(res) });
    }
    check('auth', `Sem token: as ${anon.length} rotas protegidas respondem 401 em JSON`, anon.every((a) => a.status === 401 && a.shape), anon.filter((a) => a.status !== 401 || !a.shape));
    let r = seen('token inválido', await call('GET', '/api/v1/patient/home', { token: 'a'.repeat(64) }));
    check('auth', 'Token inexistente → 401', r.status === 401);
    r = await login('ana@example.test', 'SenhaErrada#1'); const wrong = r.json && r.json.error;
    check('auth', 'Senha errada → 401', r.status === 401);
    r = await login('ninguem@example.test', 'SenhaErrada#1');
    check('auth', 'Conta inexistente recebe a mesma resposta da senha errada', r.status === 401 && r.json && r.json.error === wrong, r.json);
    r = seen('só identificador', await call('POST', '/api/v1/auth/patient-login', { body: { email: 'ana@example.test' } }));
    check('auth', 'Login só com identificador (sem senha/OTP) → 401', r.status === 401);
    r = await login('ana@example.test', 'PacienteTeste@2026'); const ana = r.json && r.json.token;
    check('auth', 'Login válido emite token opaco com expiração', r.status === 200 && /^[a-f0-9]{64}$/.test(ana || '') && !!r.json.expires_at, r.json);
    check('auth', 'Banco guarda só o hash do token', Number(one(`SELECT COUNT(*) FROM personal_access_tokens WHERE token = '${ana}'`)) === 0);
    const bruno = (await login('bruno@example.test', 'PacienteTeste@2026')).json.token;
    const carla = (await login('carla@example.test', 'PacienteTeste@2026')).json.token;
    r = await call('GET', '/api/v1/patient/home', { token: ana });
    check('auth', 'Com token: /home responde 200 com os dados da própria paciente', r.status === 200 && r.json && r.json.patient_name === 'Ana Fictícia', r.text.slice(0, 200));

    // ------------------------------------------------------------ autorização por recurso (IDOR)
    // Diário: Bruno registra; Ana não vê.
    r = await call('POST', '/api/v1/patient/food-diary', { token: bruno, body: { meal_name: 'Almoço', description: 'Almoço do Bruno' } });
    const brunoDiary = Number(one('SELECT COUNT(*) FROM food_diary_entries WHERE patient_id = 2'));
    check('idor', 'Diário: o próprio paciente registra (pré-condição)', r.status === 200 && brunoDiary === 1, { status: r.status, body: r.text.slice(0, 160) });
    r = await call('GET', '/api/v1/patient/food-diary', { token: ana });
    check('idor', 'Diário: a paciente só vê as próprias entradas', r.status === 200 && !(r.json && JSON.stringify(r.json).includes('Almoço do Bruno')), { brunoDiary, status: r.status });
    r = await call('GET', '/api/v1/patient/food-diary', { token: bruno });
    check('idor', 'Diário: o dono vê a própria entrada', r.status === 200 && JSON.stringify(r.json).includes('Almoço do Bruno'), r.status);
    // Home: plano da Ana, não o da Carla.
    r = await call('GET', '/api/v1/patient/home', { token: ana });
    const homeTxt = JSON.stringify(r.json || {});
    check('idor', 'Home traz o plano da própria paciente, nunca o de outra clínica', r.json && r.json.active_plan && r.json.active_plan.title === 'Plano fictício da Ana' && !homeTxt.includes('Carla'), homeTxt.slice(0, 200));
    // Consulta de outro paciente (mesma clínica e outra clínica).
    for (const [id, who] of [[12, 'outro paciente da mesma clínica'], [13, 'paciente de outra clínica']]) {
      r = seen('confirm ' + id, await call('POST', '/api/v1/patient/appointment/confirm', { token: ana, body: { appointment_id: id } }));
      check('idor', `Confirmar consulta de ${who}: recusado e nada muda`, !(r.json && r.json.success === true) && one(`SELECT status FROM appointments WHERE id = ${id}`) === 'scheduled', { status: r.status, body: r.text.slice(0, 160) });
      check('erros', `Confirmar consulta de ${who}: resposta 403/404 em JSON sem detalhe interno`, [403, 404].includes(r.status) && errorShapeOk(r), { status: r.status, ct: r.ct, body: r.text.slice(0, 200) });
      r = seen('cancel ' + id, await call('POST', `/api/v1/patient/appointments/${id}/cancel`, { token: ana, body: {} }));
      check('idor', `Cancelar consulta de ${who}: recusado e nada muda`, r.status >= 400 && one(`SELECT status FROM appointments WHERE id = ${id}`) === 'scheduled', { status: r.status, body: r.text.slice(0, 160) });
    }
    r = await call('POST', '/api/v1/patient/appointment/confirm', { token: ana, body: { appointment_id: 11 } });
    check('idor', 'Confirmar a própria consulta funciona', r.status === 200 && r.json && r.json.success === true && one('SELECT status FROM appointments WHERE id = 11') === 'confirmed', r.text.slice(0, 160));
    // Chat: conversa e anexo do Bruno.
    const nMsg = Number(one('SELECT COUNT(*) FROM chat_messages WHERE conversation_id = 31'));
    r = seen('chat send 31', await call('POST', '/api/v1/patient/chat/send', { token: ana, body: { conversation_id: 31, message: 'Injetada pela Ana' } }));
    check('idor', 'Enviar mensagem na conversa de outro paciente: recusado e nada gravado', r.status >= 400 && Number(one('SELECT COUNT(*) FROM chat_messages WHERE conversation_id = 31')) === nMsg, { status: r.status, body: r.text.slice(0, 160) });
    r = seen('chat send 32', await call('POST', '/api/v1/patient/chat/send', { token: ana, body: { conversation_id: 32, message: 'Injetada pela Ana' } }));
    check('idor', 'Enviar mensagem na conversa de outra clínica: recusado', r.status >= 400 && Number(one("SELECT COUNT(*) FROM chat_messages WHERE message = 'Injetada pela Ana'")) === 0, { status: r.status });
    r = seen('chat read 31', await call('POST', '/api/v1/patient/chat/read', { token: ana, body: { conversation_id: 31 } }));
    check('idor', 'Marcar como lida a conversa de outro paciente → 403 e nada muda', r.status === 403 && one('SELECT read_at FROM chat_messages WHERE id = 41') === null, r.status);
    r = seen('attachment 51', await call('GET', '/api/v1/patient/chat/attachment/51', { token: ana }));
    check('idor', 'Baixar anexo de outro paciente: recusado sem vazar conteúdo', r.status >= 400 && !r.text.includes('conteudo privado'), { status: r.status });
    r = await call('GET', '/api/v1/patient/chat/attachment/51', { token: bruno });
    check('idor', 'O dono do anexo consegue baixá-lo', r.status === 200 && r.text.includes('conteudo privado'), { status: r.status, body: r.text.slice(0, 120) });
    r = await call('GET', '/api/v1/patient/chat/messages', { token: ana });
    check('idor', 'Mensagens: a paciente não recebe mensagens de outras conversas', r.status === 200 && !r.text.includes('privada para'), r.status);
    // Receitas.
    for (const id of [21, 22]) {
      r = seen('recipe ' + id, await call('GET', `/api/v1/patient/recipes/${id}`, { token: ana }));
      check('idor', `Receita ${id === 21 ? 'privada de outra clínica' : 'da biblioteca não prescrita'} → 404`, r.status === 404 && !r.text.includes('Misturar') && !r.text.includes('Assar'), r.status);
    }
    r = seen('favorite 21', await call('POST', '/api/v1/patient/recipes/21/favorite', { token: ana, body: {} }));
    check('idor', 'Favoritar receita de outra clínica → 404 e nada gravado', r.status === 404 && Number(one('SELECT COUNT(*) FROM recipe_patient_favorites WHERE patient_id = 1')) === 0, { status: r.status, body: r.text.slice(0, 120) });
    r = await call('GET', '/api/v1/patient/recipes/21', { token: carla });
    check('idor', 'A paciente com a prescrição vê a receita', r.status === 200 && r.text.includes('Receita privada da cl'), { status: r.status, body: r.text.slice(0, 160) });

    // ------------------------------------------------------------ erros padronizados
    r = seen('rota inexistente', await call('GET', '/api/v1/patient/nao-existe', { token: ana }));
    check('erros', 'Rota inexistente da API → 404 em JSON', r.status === 404 && errorShapeOk(r), { status: r.status, ct: r.ct, body: r.text.slice(0, 160) });
    r = seen('método errado', await call('GET', '/api/v1/patient/hydration', { token: ana }));
    check('erros', 'Método não suportado → 404/405 em JSON', [404, 405].includes(r.status) && errorShapeOk(r), { status: r.status, ct: r.ct, body: r.text.slice(0, 160) });
    r = seen('json malformado', await call('POST', '/api/v1/patient/self-metrics', { token: ana, raw: '{"value": ', headers: { 'Content-Type': 'application/json' } }));
    check('erros', 'JSON malformado → 400 em JSON', r.status === 400 && errorShapeOk(r), { status: r.status, body: r.text.slice(0, 160) });
    r = seen('métrica inválida', await call('POST', '/api/v1/patient/self-metrics', { token: ana, body: { metric_type: 'weight_kg', value: -5 } }));
    check('erros', 'Validação de entrada → 400/422 em JSON', [400, 422].includes(r.status) && errorShapeOk(r), r.status);
    r = seen('hidratação inválida', await call('POST', '/api/v1/patient/hydration', { token: ana, body: { amount_ml: -900 } }));
    check('erros', 'Hidratação negativa é recusada', r.status >= 400 && Number(one('SELECT COUNT(*) FROM hydration_logs WHERE amount_ml < 0')) === 0, { status: r.status, body: r.text.slice(0, 160) });
    r = seen('diário vazio', await call('POST', '/api/v1/patient/food-diary', { token: ana, body: {} }));
    check('erros', 'Diário sem dados → 400/422 em JSON (não 500)', [400, 422].includes(r.status) && errorShapeOk(r), { status: r.status, body: r.text.slice(0, 160) });
    const nDiary = Number(one('SELECT COUNT(*) FROM food_diary_entries'));
    r = seen('diário fome 9', await call('POST', '/api/v1/patient/food-diary', { token: ana, body: { meal_name: 'Lanche', hunger_level: 9 } }));
    check('erros', 'Diário com escala de fome fora de 1–5 → 422', r.status === 422 && errorShapeOk(r), { status: r.status, body: r.text.slice(0, 160) });
    r = seen('diário futuro', await call('POST', '/api/v1/patient/food-diary', { token: ana, body: { meal_name: 'Jantar', consumed_at: '2030-01-01 20:00:00' } }));
    check('erros', 'Diário com refeição no futuro → 422', r.status === 422 && errorShapeOk(r), { status: r.status, body: r.text.slice(0, 160) });
    check('erros', 'Entradas inválidas não gravam nada no diário', Number(one('SELECT COUNT(*) FROM food_diary_entries')) === nDiary);
    r = await call('POST', '/api/v1/patient/food-diary', { token: ana, body: { meal_name: 'Lanche', description: 'Fruta', photo_path: '/etc/passwd' } });
    check('idor', 'Diário: caminho de foto enviado pelo cliente é ignorado', r.status === 200 && one('SELECT photo_path FROM food_diary_entries WHERE patient_id = 1 ORDER BY id DESC LIMIT 1') === null, { status: r.status });
    const bad = errors.filter((e) => !e.ok);
    check('erros', `Todas as ${errors.length} respostas de erro observadas são JSON {error} sem detalhe interno`, bad.length === 0, bad.slice(0, 6));
    const codes = errors.filter((e) => e.ok).length;
    check('erros', 'Todo erro traz "code" legível por máquina e "request_id" para suporte', errors.every((e) => e.hasCode && e.hasRid), errors.filter((e) => !e.hasCode || !e.hasRid).slice(0, 4));

    // ------------------------------------------------------------ sessão por cookie (web) e revogação
    r = seen('cookie origem externa', await call('POST', '/api/v1/patient/hydration', { headers: { Cookie: 'clyonutri_mobile=' + ana, Origin: 'https://evil.example', 'X-Clyonutri-Web': '1' }, body: { amount_ml: 200 } }));
    check('auth', 'Cookie de sessão web com origem externa → 403', r.status === 403, r.status);
    php(['deactivate']); r = await call('GET', '/api/v1/patient/home', { token: ana });
    check('auth', 'Paciente inativada: token deixa de valer (401)', r.status === 401, r.status);
    php(['anonymize']); r = await call('GET', '/api/v1/patient/home', { token: ana });
    check('auth', 'Paciente anonimizada: token deixa de valer (401)', r.status === 401, r.status);
    php(['reactivate']);
    r = await call('POST', '/api/v1/auth/patient-logout', { token: bruno, body: {} });
    const after = await call('GET', '/api/v1/patient/home', { token: bruno });
    check('auth', 'Logout revoga o token (401 depois)', r.status === 200 && after.status === 401, { logout: r.status, after: after.status });
    php(['expire']); r = await call('GET', '/api/v1/patient/home', { token: ana });
    check('auth', 'Token expirado → 401', r.status === 401, r.status);

    // ------------------------------------------------------------ documentação mínima
    const spec = JSON.parse(readFileSync(join(root, 'docs/api/openapi_v1.json'), 'utf8'));
    const base = ((spec.servers || [])[0] || {}).url || '';
    const prefix = base.replace(/^https?:\/\/[^/]+/, '');
    const documented = Object.keys(spec.paths || {}).map((p) => (prefix + p).replace(/\{[^}]+\}/g, 'X'));
    const real = patientRoutes.map((x) => x.split(' ')[1].replace(/\{[^}]+\}/g, 'X'));
    const undocumented = real.filter((p) => !documented.includes(p));
    const phantom = documented.filter((p) => !known.includes(p));
    check('docs', 'OpenAPI documenta as rotas do paciente que existem', undocumented.length === 0, { faltam: undocumented.length, exemplos: undocumented.slice(0, 6) });
    check('docs', 'OpenAPI não descreve rotas que não existem', phantom.length === 0, phantom);
  } catch (e) {
    results.push({ crit: 'erro', name: e.message.split('\n')[0], ok: false });
    console.log('FAIL (interrompido) ' + e.stack);
  } finally {
    server.kill(); rmSync(dir, { recursive: true, force: true });
    if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify(results, null, 1));
  }
  const ok = results.filter((x) => x.ok).length;
  console.log(`\nNI2-89: ${ok} PASS, ${results.length - ok} FAIL`);
  if (ok !== results.length) process.exitCode = 1;
})();
