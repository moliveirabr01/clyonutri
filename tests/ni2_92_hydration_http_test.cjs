// NI2-92 — Registrar hidratação: teste real por HTTP.
// public/index.php em `php -S` (APP_ENV=production), SQLite isolado com dados fictícios TESTE (tests/support/ni2_92_fixture.php).
//   node tests/ni2_92_hydration_http_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, readFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-92-');
const RATE = 40;
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'null', TZ: 'America/Sao_Paulo', HYDRATION_RATE_LIMIT_PER_MINUTE: String(RATE) };
let pass = 0, fail = 0;
function check(name, ok, detail) {
  if (ok) pass++; else fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 400) : ''}`);
}
const php = (args) => execFileSync('php', [join(root, 'tests/support/ni2_92_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(php(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const LEAK = /(SQLSTATE|PDOException|Stack trace|\.php(:| on line)|Uncaught|Fatal error|\/home\/|\/tmp\/)/i;
const rows = (pid) => Number(one(`SELECT COUNT(*) FROM hydration_logs WHERE patient_id = ${pid}`));

(async () => {
  php(['prepare']);
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const call = async (method, path, { token, body, headers = {}, raw } = {}) => {
    const h = { Accept: 'application/json', ...headers };
    if (token) h.Authorization = 'Bearer ' + token;
    if (body !== undefined || raw !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: raw !== undefined ? raw : (body !== undefined ? JSON.stringify(body) : undefined), redirect: 'manual' });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* não JSON */ }
    return { status: res.status, text, json, headers: res.headers };
  };
  const login = async (email) => (await call('POST', '/api/v1/auth/patient-login', { body: { email, password: 'PacienteTeste@2026' } })).json?.token;
  const H = '/api/v1/patient/hydration';
  try {
    const ana = await login('ana92@example.test'); const bruno = await login('bruno92@example.test'); const carla = await login('carla92@example.test');
    check('login das três pacientes TESTE', !!(ana && bruno && carla));

    // ---- validação (M2/A1)
    let r = await call('POST', H, { token: ana, raw: '' });
    check('corpo vazio → 422 sem gravar', r.status === 422 && rows(1) === 0, r);
    r = await call('POST', H, { token: ana, body: {} });
    check('sem amount_ml → 422 (não há mais 250 padrão)', r.status === 422 && rows(1) === 0, r);
    r = await call('POST', H, { token: ana, raw: '"5"' });
    check('JSON escalar → 422', r.status === 422, r);
    for (const bad of [-500, 0, 5001, 1e12, 'abc', '1e3', 250.7, true, null, [250]]) {
      r = await call('POST', H, { token: ana, body: { amount_ml: bad } });
      check(`amount_ml ${JSON.stringify(bad)} → 422 VALIDATION_ERROR`, r.status === 422 && r.json?.code === 'VALIDATION_ERROR' && !LEAK.test(r.text), r);
    }
    check('nada gravado pelas recusas', rows(1) === 0);

    // ---- criação + idempotência (A6)
    const key = 'teste-ni292-' + Date.now();
    r = await call('POST', H, { token: ana, body: { amount_ml: 250 }, headers: { 'Idempotency-Key': key } });
    check('cria 250 ml → 200 com total e dia local', r.status === 200 && r.json?.amount_ml === 250 && r.json?.total_water_today === 250 && /-03:00$/.test(r.json?.logged_at || ''), r);
    check('Cache-Control: no-store', /no-store/.test(r.headers.get('cache-control') || ''));
    const firstId = r.json?.id;
    const [x, y] = await Promise.all([1, 2].map(() => call('POST', H, { token: ana, body: { amount_ml: 250 }, headers: { 'Idempotency-Key': key } })));
    check('mesma chave reenviada (2 em paralelo) → mesmo id, 1 linha', x.json?.id === firstId && y.json?.id === firstId && x.json?.idempotent_replay === true && rows(1) === 1, [x.json, y.json]);
    r = await call('POST', H, { token: ana, body: { amount_ml: 250, idempotency_key: key } });
    check('chave no corpo também é respeitada', r.json?.id === firstId && rows(1) === 1, r.json);
    r = await call('POST', H, { token: ana, body: { amount_ml: 900 }, headers: { 'Idempotency-Key': key } });
    check('mesma chave com volume diferente → 409', r.status === 409 && r.json?.code === 'IDEMPOTENCY_KEY_REUSED', r);
    r = await call('POST', H, { token: ana, body: { amount_ml: 250 }, headers: { 'Idempotency-Key': 'curta' } });
    check('chave inválida → 422', r.status === 422 && r.json?.code === 'IDEMPOTENCY_KEY_INVALID', r);

    // ---- horário (M3)
    const fut = new Date(Date.now() + 60 * 60 * 1000).toISOString();
    r = await call('POST', H, { token: ana, body: { amount_ml: 100, logged_at: fut } });
    check('logged_at 1 h no futuro → 422', r.status === 422 && r.json?.code === 'HYDRATION_DATE_IN_FUTURE', r);
    const old = new Date(Date.now() - 9 * 86400 * 1000).toISOString();
    r = await call('POST', H, { token: ana, body: { amount_ml: 100, logged_at: old } });
    check('logged_at 9 dias atrás → 422', r.status === 422 && r.json?.code === 'HYDRATION_DATE_TOO_OLD', r);
    const yest = new Date(Date.now() - 86400 * 1000).toISOString();
    r = await call('POST', H, { token: ana, body: { amount_ml: 300, logged_at: yest } });
    check('logged_at ontem (UTC Z) aceito e convertido para -03:00', r.status === 200 && /-03:00$/.test(r.json?.logged_at || ''), r);
    const yestId = r.json?.id;

    // ---- histórico (A2)
    r = await call('GET', H + '/history?days=1', { token: ana });
    check('histórico: 1 dia por página, hoje primeiro, com cursor', r.status === 200 && r.json?.days?.length === 1 && r.json.days[0].date === r.json.today && r.json.days[0].total_ml === 250 && !!r.json.next_before, r.json);
    check('histórico: meta vem da anamnese finalizada (2000), não do rascunho (3500)', r.json?.goal_ml === 2000, r.json?.goal_ml);
    check('histórico não expõe idempotency_key nem clinic_id', !/idempotency_key|clinic_id/.test(r.text));
    const r2 = await call('GET', H + '/history?days=1&before=' + r.json.next_before, { token: ana });
    check('histórico página 2: ontem com 300 ml', r2.status === 200 && r2.json?.days?.[0]?.total_ml === 300, r2.json);
    r = await call('GET', H + '/history?before=ontem', { token: ana });
    check('histórico com cursor inválido → 422', r.status === 422, r);
    r = await call('GET', '/api/v1/patient/home', { token: ana });
    check('home: water_ml_today = 250 e meta 2000 (finalizada)', r.status === 200 && r.json?.water_ml_today === 250 && r.json?.water_goal_ml === 2000, { t: r.json?.water_ml_today, g: r.json?.water_goal_ml });

    // ---- IDOR (lista/edição/exclusão)
    r = await call('GET', H + '/history', { token: bruno });
    check('Bruno (mesma clínica) não vê registros da Ana no histórico dele', r.status === 200 && (r.json?.days || []).length === 0, r.json);
    for (const [who, tok] of [['Bruno (mesma clínica)', bruno], ['Carla (outra clínica)', carla]]) {
      r = await call('POST', `${H}/${firstId}/update`, { token: tok, body: { amount_ml: 1 } });
      check(`${who} editar registro da Ana → 404`, r.status === 404 && r.json?.code === 'HYDRATION_NOT_FOUND', r);
      r = await call('POST', `${H}/${firstId}/delete`, { token: tok, body: {} });
      check(`${who} excluir registro da Ana → 404`, r.status === 404 && r.json?.code === 'HYDRATION_NOT_FOUND', r);
    }
    check('registro da Ana intacto', Number(one(`SELECT amount_ml FROM hydration_logs WHERE id = ${firstId} AND deleted_at IS NULL`)) === 250);
    r = await call('POST', `${H}/abc/update`, { token: ana, body: { amount_ml: 1 } });
    check('id não numérico → 404 (rota) sem vazar detalhe', r.status === 404 && !LEAK.test(r.text), { s: r.status });

    // ---- CSRF pelo cookie web
    r = await call('POST', H, { headers: { Cookie: 'clyonutri_mobile=' + ana, Origin: 'https://evil.example', 'X-ClyoNutri-Web': '1' }, body: { amount_ml: 200 } });
    check('cookie web com Origin de outro site → 403, nada gravado', r.status === 403 && rows(1) === 2, { s: r.status, rows: rows(1) });

    // ---- edição/exclusão pela própria paciente
    r = await call('POST', `${H}/${firstId}/update`, { token: ana, body: {} });
    check('editar sem campos → 422', r.status === 422, r);
    r = await call('POST', `${H}/${firstId}/update`, { token: ana, body: { amount_ml: 350 } });
    check('Ana corrige 250 → 350', r.status === 200 && r.json?.amount_ml === 350 && r.json?.total_water_today === 350, r);
    r = await call('POST', `${H}/${firstId}/delete`, { token: ana, body: {} });
    check('Ana exclui → total de hoje volta a 0, linha mantida (exclusão lógica)', r.status === 200 && r.json?.total_water_today === 0 && rows(1) === 2, r);
    r = await call('POST', `${H}/${firstId}/delete`, { token: ana, body: {} });
    check('excluir de novo → already_deleted', r.status === 200 && r.json?.already_deleted === true, r);
    check('auditoria: criado, editado e excluído', Number(one(`SELECT COUNT(DISTINCT action) FROM audit_logs WHERE resource_id = '${firstId}' AND action IN ('HYDRATION_LOGGED','HYDRATION_UPDATED','HYDRATION_DELETED')`)) === 3);
    php(['age', String(yestId)]);
    r = await call('POST', `${H}/${yestId}/update`, { token: ana, body: { amount_ml: 100 } });
    check('registro criado há 25 h → 409 janela encerrada', r.status === 409 && r.json?.code === 'HYDRATION_EDIT_WINDOW_CLOSED', r);

    // ---- sem token
    for (const [m, p] of [['POST', H], ['GET', H + '/history'], ['POST', H + '/1/update'], ['POST', H + '/1/delete']]) {
      r = await call(m, p, m === 'POST' ? { body: {} } : {});
      check(`sem token ${m} ${p} → 401`, r.status === 401, r.status);
    }

    // ---- limite de requisições (M1), por paciente
    // Bruno já fez 2 escritas acima (tentativas de IDOR também contam no limite).
    const before = 2; let first429 = null;
    for (let i = 1; i <= RATE + 1; i++) {
      r = await call('POST', H, { token: bruno, body: { amount_ml: 10 } });
      if (r.status === 429) { first429 = i; break; }
    }
    check(`Bruno: ${RATE} escritas/min OK, a ${RATE + 1}ª → 429 RATE_LIMITED com Retry-After`, first429 === RATE + 1 - before && Number(one('SELECT COUNT(*) FROM hydration_logs WHERE patient_id = 2')) === RATE - before && r.json?.code === 'RATE_LIMITED' && r.headers.get('retry-after') === '60', { first429, body: r.json });
    r = await call('POST', H, { token: carla, body: { amount_ml: 10 } });
    check('limite de um paciente não afeta outro', r.status === 200, r);

    // ---- OpenAPI
    const spec = JSON.parse(readFileSync(join(root, 'docs/api/openapi_v1.json'), 'utf8'));
    const post = spec.paths['/patient/hydration']?.post;
    check('OpenAPI: corpo obrigatório, sem default 250, com 409/429', post?.requestBody?.required === true && !JSON.stringify(post).includes('"default":250') && post.responses['409'] && post.responses['429']);
    check('OpenAPI: history/update/delete documentados', ['/patient/hydration/history', '/patient/hydration/{id}/update', '/patient/hydration/{id}/delete'].every((p) => spec.paths[p]));
  } catch (e) {
    check('execução sem exceção', false, String(e && e.stack || e));
  } finally {
    server.kill();
    rmSync(dir, { recursive: true, force: true });
  }
  console.log(`\nNI2-92 HTTP: ${pass} PASS, ${fail} FAIL`);
  process.exit(fail ? 1 : 0);
})();
