// NI2-132 — Diário alimentar: teste real por HTTP (paciente e profissional).
// public/index.php em `php -S` (APP_ENV=production) com dados fictícios (tests/support/ni2_132_fixture.php).
//   node tests/ni2_132_food_diary_http_test.cjs                      (SQLite isolado; servidor com 1 worker, porque o SQLite não atende gravações paralelas — o paralelismo real fica por conta do MySQL)
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_132_food_diary_http_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-132-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_132_test_' + Math.random().toString(36).slice(2, 10);
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'),
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null',
  APP_KEY: 'ni2-132-chave-de-teste-ficticia-0001', APP_URL: 'http://app.example.test', NI2_FIXTURE_DIR: dir };
delete env.FOOD_DIARY_RATE_LIMIT_PER_MINUTE;
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 500) : ''}`);
}
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_132_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const sql = (q) => JSON.parse(fx('sql', q));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const LEAK = /(SQLSTATE|PDOException|Stack trace|\.php(:| on line)|#\d+ \/|Uncaught|Fatal error|\/home\/|\/tmp\/)/i;
const PUBLIC_KEYS = ['consumed_at', 'consumed_tz', 'description', 'feedback_from_professional', 'hunger_level', 'id', 'idempotent_replay', 'meal_name', 'patient_id', 'reviewed_at', 'satiety_level', 'symptoms'];
const FORBIDDEN = ['clinic_id', 'idempotency_key', 'request_hash', 'photo_path', 'patient_email'];
const hasForbidden = (o) => FORBIDDEN.filter((k) => JSON.stringify(o).includes('"' + k + '"'));
const pad = (n) => String(n).padStart(2, '0');
const localNow = (offsetMs = 0) => { // horário de São Paulo (UTC-3, sem horário de verão) no formato do banco
  const d = new Date(Date.now() + offsetMs - 3 * 3600 * 1000);
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`;
};
const iso = (offsetMs = 0) => new Date(Date.now() + offsetMs).toISOString();

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fx('prepare');
  const free = async () => { const s = net.createServer(); await new Promise((r) => s.listen(0, '127.0.0.1', r)); const p = s.address().port; await new Promise((r) => s.close(r)); return p; };
  const start = async (extraEnv) => {
    const port = await free();
    const proc = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env: { ...env, PHP_CLI_SERVER_WORKERS: useMysql ? '4' : '1', ...extraEnv }, stdio: 'ignore' });
    const base = 'http://127.0.0.1:' + port;
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
    return { proc, base };
  };
  const main = await start({ FOOD_DIARY_RATE_LIMIT_PER_MINUTE: '1000' });  // limite alto: os testes de validação e paginação não esbarram nele
  const strict = await start({});                                           // limite padrão (30/min), mesmo banco
  const mk = (srv) => async (method, path, { token, body, headers = {}, raw } = {}) => {
    const h = { Accept: 'application/json', ...headers };
    if (token) h.Authorization = 'Bearer ' + token;
    if (body !== undefined || raw !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(srv.base + path, { method, headers: h, body: raw !== undefined ? raw : (body !== undefined ? JSON.stringify(body) : undefined), redirect: 'manual' });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* não JSON */ }
    return { status: res.status, text, json, headers: res.headers };
  };
  const api = mk(main); const apiStrict = mk(strict);
  const D = '/api/v1/patient/food-diary';
  const P = '/api/v1/professional/food-diaries';
  const clean = (r) => !LEAK.test(r.text) && /application\/json/.test(r.headers.get('content-type') || '');
  const loginP = async (email, password) => (await api('POST', '/api/v1/auth/patient-login', { body: { email, password } })).json.token;
  const loginU = async (email) => (await api('POST', '/api/v1/auth/professional-login', { body: { email, password: 'Equipe@2026' } })).json.token;
  const audit = (action) => sql(`SELECT user_id, clinic_id, resource, resource_id, details FROM audit_logs WHERE action = '${action}' ORDER BY id`);
  try {
    const ana = await loginP('ana@example.test', 'PacienteTeste@2026');
    const bruno = await loginP('bruno@example.test', 'PacienteTeste@2026');
    const carla = await loginP('carla@example.test', 'PacienteTeste@2026');
    const nutri1 = await loginU('nutri1@example.test');
    const nutri2 = await loginU('nutri2@example.test');
    check('setup', 'Login de 3 pacientes e 2 profissionais (clínicas 1 e 2)', [ana, bruno, carla, nutri1, nutri2].every((t) => /^[a-f0-9]{64}$/.test(t || '')));
    const rows = (pid) => Number(one(`SELECT COUNT(*) FROM food_diary_entries WHERE patient_id = ${pid}`));
    const rowsAll = () => Number(one('SELECT COUNT(*) FROM food_diary_entries'));

    // ------------------------------------------------------------ A6 — validação (produção não pode dar 500)
    let before = rowsAll();
    let r = await api('POST', D, { token: ana, body: {} });
    check('validação', 'Corpo vazio → 422 (antes: 500)', r.status === 422 && r.json?.code === 'VALIDATION_ERROR' && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, raw: '' });
    check('validação', 'Requisição sem corpo → 422', r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, raw: '{nao-e-json' });
    check('validação', 'JSON malformado → 4xx em JSON, sem vazar detalhe', r.status >= 400 && r.status < 500 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, body: { meal_name: ['x'] } });
    check('validação', 'meal_name em lista → 422 (antes: 500)', r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, body: { meal_name: { a: 1 } } });
    check('validação', 'meal_name em objeto → 422', r.status === 422 && clean(r));
    for (const [label, name] of [['vazio', ''], ['só espaços', '   '], ['101 caracteres', 'a'.repeat(101)], ['com caractere de controle', 'Almoço\u0000x'], ['número', 12]]) {
      r = await api('POST', D, { token: ana, body: { meal_name: name } });
      check('validação', `meal_name ${label} → 422`, r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    }
    r = await api('POST', D, { token: ana, body: { meal_name: 'a'.repeat(100), description: 'x'.repeat(2000), symptoms: 'y'.repeat(1000), hunger_level: 5, satiety_level: 1 } });
    check('validação', 'Nos limites exatos (100/2000/1000, fome 5, saciedade 1) → 201', r.status === 201, { s: r.status, t: r.text.slice(0, 200) });
    for (const [label, body] of [['descrição com 2001', { description: 'x'.repeat(2001) }], ['sintomas com 1001', { symptoms: 'y'.repeat(1001) }], ['descrição em lista', { description: ['a'] }],
      ['fome 0', { hunger_level: 0 }], ['fome 6', { hunger_level: 6 }], ['fome "abc"', { hunger_level: 'abc' }], ['fome 2.5', { hunger_level: 2.5 }], ['saciedade 9', { satiety_level: 9 }]]) {
      r = await api('POST', D, { token: ana, body: { meal_name: 'Almoço', ...body } });
      check('validação', `${label} → 422`, r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    }
    r = await api('POST', D, { token: ana, body: { meal_name: 'Almoço', idempotency_key: 'k'.repeat(65) } });
    check('validação', 'Chave de idempotência com 65 caracteres → 422', r.status === 422 && clean(r));
    r = await api('POST', D, { token: ana, body: { meal_name: 'Almoço', idempotency_key: 12345 } });
    check('validação', 'Chave de idempotência que não é texto → 422', r.status === 422 && clean(r));
    check('validação', 'Nada foi gravado pelas requisições recusadas (só o caso dos limites exatos)', rowsAll() === before + 1, { before, after: rowsAll() });

    // ------------------------------------------------------------ A6/M4 — horário e fuso
    before = rowsAll();
    for (const [label, v] of [['texto livre', 'ontem'], ['data inexistente', '2026-02-30T10:00:00Z'], ['antes de 2000', '1999-12-31T23:59:59Z'], ['ano 0001', '0001-01-01T00:00:00Z'],
      ['10 minutos no futuro', iso(10 * 60 * 1000)], ['no ano 2999', '2999-01-01T00:00:00Z'], ['lista', ['2026-10-01T10:00:00Z']], ['número', 1790000000]]) {
      r = await api('POST', D, { token: ana, body: { meal_name: 'Horário', consumed_at: v } });
      check('horário', `consumed_at ${label} → 422`, r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    }
    check('horário', 'Nenhum horário absurdo foi gravado', rowsAll() === before);
    r = await api('POST', D, { token: ana, body: { meal_name: 'Horário ok', consumed_at: iso(60 * 1000) } });
    check('horário', 'Até 5 minutos no futuro é tolerado (relógio do celular) → 201', r.status === 201, { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, body: { meal_name: 'Fuso inválido', consumed_at: '2026-10-20T08:00:00', timezone: 'Marte/Base' } });
    check('horário', 'timezone que não é IANA → 422', r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, body: { meal_name: 'Manaus', consumed_at: '2026-10-20T08:00:00', timezone: 'America/Manaus' } });
    check('horário', 'Horário local de Manaus (UTC-4) é convertido para o horário da clínica (+1 h) e o fuso fica registrado', r.status === 201 && r.json?.consumed_at === '2026-10-20 09:00:00' && r.json?.consumed_tz === 'America/Manaus', r.json);
    r = await api('POST', D, { token: ana, body: { meal_name: 'UTC', consumed_at: '2026-10-20T15:30:00Z' } });
    check('horário', 'Horário com Z (UTC) 15:30 vira 12:30 no horário da clínica — o app mostra 12:30, não 15:30', r.status === 201 && r.json?.consumed_at === '2026-10-20 12:30:00', r.json);
    r = await api('POST', D, { token: ana, body: { meal_name: 'Offset', consumed_at: '2026-10-20T08:00:00-04:00' } });
    check('horário', 'Offset na própria data (-04:00) é respeitado e guardado em consumed_tz', r.status === 201 && r.json?.consumed_at === '2026-10-20 09:00:00' && r.json?.consumed_tz === '-04:00', r.json);
    r = await api('POST', D, { token: ana, body: { meal_name: 'Sem fuso', consumed_at: '2026-10-20 07:45:00' } });
    check('horário', 'Horário sem fuso é tratado como horário da clínica (comportamento anterior preservado)', r.status === 201 && r.json?.consumed_at === '2026-10-20 07:45:00' && r.json?.consumed_tz === null, r.json);
    r = await api('POST', D, { token: ana, body: { meal_name: 'Agora' } });
    check('horário', 'Sem consumed_at usa o agora da clínica (±2 min)', r.status === 201 && Math.abs(Date.parse(r.json.consumed_at.replace(' ', 'T') + '-03:00') - Date.now()) < 120000, r.json);

    // ------------------------------------------------------------ A6 — caminho da foto
    before = rowsAll();
    r = await api('POST', D, { token: ana, body: { meal_name: 'Com foto', photo_path: '/etc/passwd' } });
    const lastId = r.json?.id;
    check('foto', 'photo_path vindo do cliente é ignorado: /etc/passwd nunca chega ao banco', r.status === 201 && one(`SELECT photo_path FROM food_diary_entries WHERE id = ${Number(lastId)}`) === null && !r.text.includes('passwd'), r.json);
    r = await api('POST', D, { token: ana, body: { meal_name: 'Com foto 2', photo_path: '../../.env' } });
    check('foto', 'photo_path com ../ também não é gravado', r.status === 201 && one(`SELECT COUNT(*) FROM food_diary_entries WHERE photo_path IS NOT NULL`) == 0);
    const svc = JSON.parse(fx('svc-photo'));
    check('foto', 'Serviço recusa caminho absoluto, ../, esquema, barra invertida e byte nulo; aceita caminho relativo seguro e /uploads/diary/',
      ['/etc/passwd', '../../etc/passwd', 'a/../../b.jpg', 'C:\\Windows\\x.jpg', 'http://example.test/x.jpg', 'fotos/a\u0000.jpg', '/uploads/outro/x.jpg'].every((k) => svc[k] === 'recusado') && svc['fotos/ok-1.jpg'] === 'aceito' && svc['/uploads/diary/ok-2.jpg'] === 'aceito', svc);

    // ------------------------------------------------------------ M3 — criação, repetição e conflito
    const key = 'teste-ni2132-' + Date.now();
    const payload = { meal_name: 'Café da manhã', description: 'Pão com ovos (TESTE)', hunger_level: 3, satiety_level: 4, consumed_at: '2026-10-21T08:10:00-03:00' };
    r = await api('POST', D, { token: ana, body: payload, headers: { 'Idempotency-Key': key } });
    const firstId = r.json?.id;
    check('idempotência', 'Criação → 201 com os campos públicos e idempotent_replay=false', r.status === 201 && JSON.stringify(Object.keys(r.json).sort()) === JSON.stringify(PUBLIC_KEYS) && r.json.idempotent_replay === false, { s: r.status, k: Object.keys(r.json || {}) });
    check('idempotência', 'Resposta não expõe clinic_id, idempotency_key, request_hash nem photo_path', hasForbidden(r.json).length === 0, hasForbidden(r.json));
    check('idempotência', 'Cache-Control: no-store', /no-store/.test(r.headers.get('cache-control') || ''));
    const n1 = rows(1);
    r = await api('POST', D, { token: ana, body: payload, headers: { 'Idempotency-Key': key } });
    check('idempotência', 'Mesma chave e mesmo conteúdo → 200, mesmo id, idempotent_replay=true, sem linha nova', r.status === 200 && r.json?.id === firstId && r.json?.idempotent_replay === true && rows(1) === n1, { s: r.status, j: r.json });
    r = await api('POST', D, { token: ana, body: { ...payload, idempotency_key: key } });
    check('idempotência', 'Chave no corpo também é respeitada (mesmo conteúdo → 200)', r.status === 200 && r.json?.id === firstId && rows(1) === n1, { s: r.status, j: r.json });
    r = await api('POST', D, { token: ana, body: { ...payload, meal_name: 'Almoço' }, headers: { 'Idempotency-Key': key } });
    check('idempotência', 'Mesma chave com outra refeição → 409 IDEMPOTENCY_KEY_REUSED', r.status === 409 && r.json?.code === 'IDEMPOTENCY_KEY_REUSED' && clean(r), { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, body: { ...payload, description: 'Outra coisa' }, headers: { 'Idempotency-Key': key } });
    check('idempotência', 'Mesma chave com outra descrição → 409', r.status === 409);
    r = await api('POST', D, { token: ana, body: { ...payload, consumed_at: '2026-10-21T09:10:00-03:00' }, headers: { 'Idempotency-Key': key } });
    check('idempotência', 'Mesma chave com outro horário → 409', r.status === 409);
    r = await api('POST', D, { token: ana, body: { ...payload, hunger_level: 1 }, headers: { 'Idempotency-Key': key } });
    check('idempotência', 'Mesma chave com outra fome → 409', r.status === 409);
    check('idempotência', 'Depois dos 409 o registro original continua igual e único', rows(1) === n1 && one(`SELECT meal_name FROM food_diary_entries WHERE id = ${firstId}`) === 'Café da manhã' && Number(one(`SELECT COUNT(*) FROM food_diary_entries WHERE idempotency_key = '${key}'`)) === 1);
    r = await api('POST', D, { token: bruno, body: { ...payload, meal_name: 'Do Bruno' }, headers: { 'Idempotency-Key': key } });
    check('idempotência', 'A mesma chave em outro paciente é independente (201) e não vaza o registro da Ana', r.status === 201 && r.json?.id !== firstId && r.json?.meal_name === 'Do Bruno', { s: r.status, j: r.json });
    r = await api('POST', D, { token: ana, body: { meal_name: 'Sem horário' }, headers: { 'Idempotency-Key': key + '-sh' } });
    const shId = r.json?.id;
    r = await api('POST', D, { token: ana, body: { meal_name: 'Sem horário' }, headers: { 'Idempotency-Key': key + '-sh' } });
    check('idempotência', 'Reenvio sem consumed_at (o "agora" muda) continua sendo o mesmo conteúdo → 200', r.status === 200 && r.json?.id === shId, { s: r.status, j: r.json });
    const nBefore = rows(1);
    await api('POST', D, { token: ana, body: { meal_name: 'Sem chave' } }); await api('POST', D, { token: ana, body: { meal_name: 'Sem chave' } });
    check('idempotência', 'Sem chave, duas requisições iguais geram dois registros (comportamento esperado)', rows(1) === nBefore + 2);
    const ck = key + '-par';
    const par = await Promise.all([1, 2, 3, 4].map(() => api('POST', D, { token: ana, body: { meal_name: 'Paralelo', hunger_level: 2 }, headers: { 'Idempotency-Key': ck } })));
    check('idempotência', '4 requisições iguais em paralelo → 1 linha, todas 200/201 com o mesmo id', Number(one(`SELECT COUNT(*) FROM food_diary_entries WHERE idempotency_key = '${ck}'`)) === 1 && par.every((x) => [200, 201].includes(x.status)) && new Set(par.map((x) => x.json?.id)).size === 1, par.map((x) => [x.status, x.text.slice(0, 80)]));
    const ck2 = key + '-par2';
    const par2 = await Promise.all(['A', 'B', 'A', 'B'].map((m) => api('POST', D, { token: ana, body: { meal_name: 'Paralelo ' + m }, headers: { 'Idempotency-Key': ck2 } })));
    const okRows = Number(one(`SELECT COUNT(*) FROM food_diary_entries WHERE idempotency_key = '${ck2}'`));
    check('idempotência', 'Em paralelo com conteúdos diferentes: 1 linha, os demais recebem 409 (nunca 500)', okRows === 1 && par2.every((x) => [200, 201, 409].includes(x.status)) && par2.some((x) => x.status === 409), par2.map((x) => [x.status, x.text.slice(0, 80)]));
    fx('legacy', 1, 1, 'legado-1', 'Jantar legado', '2026-10-01 20:00:00', 'Sopa (TESTE)');
    r = await api('POST', D, { token: ana, body: { meal_name: 'Jantar legado', description: 'Sopa (TESTE)', consumed_at: '2026-10-01 20:00:00' }, headers: { 'Idempotency-Key': 'legado-1' } });
    check('idempotência', 'Registro anterior à migração (sem hash): mesmo conteúdo → 200 (comparação campo a campo)', r.status === 200 && r.json?.idempotent_replay === true, { s: r.status, j: r.json });
    r = await api('POST', D, { token: ana, body: { meal_name: 'Jantar legado', description: 'Outra', consumed_at: '2026-10-01 20:00:00' }, headers: { 'Idempotency-Key': 'legado-1' } });
    check('idempotência', 'Registro anterior à migração: conteúdo diferente → 409', r.status === 409);

    // ------------------------------------------------------------ banco sem a migração 54 (deploy do código antes do SQL)
    fx('down54');
    r = await api('POST', D, { token: ana, body: { meal_name: 'Sem migração', consumed_at: '2026-10-22T08:00:00-03:00' }, headers: { 'Idempotency-Key': key + '-m0' } });
    const m0 = r.json?.id;
    check('migração', 'Sem a migração 54: criação continua funcionando (201)', r.status === 201 && m0 > 0, { s: r.status, t: r.text.slice(0, 200) });
    r = await api('POST', D, { token: ana, body: { meal_name: 'Sem migração', consumed_at: '2026-10-22T08:00:00-03:00' }, headers: { 'Idempotency-Key': key + '-m0' } });
    check('migração', 'Sem a migração 54: reenvio igual → 200 e diferente → 409', r.status === 200 && r.json?.id === m0 && (await api('POST', D, { token: ana, body: { meal_name: 'Outra' }, headers: { 'Idempotency-Key': key + '-m0' } })).status === 409);
    r = await api('GET', D, { token: ana });
    check('migração', 'Sem a migração 54: GET do paciente e do profissional respondem 200', r.status === 200 && (await api('GET', P, { token: nutri1 })).status === 200);
    fx('up54');
    check('migração', 'Migração 53 aplicada depois pelo Migrator (idempotente)', Number(one("SELECT COUNT(*) FROM migrations WHERE migration = '2026_10_24_000054_harden_food_diary_entries'")) === 1 && one('SELECT COUNT(request_hash) FROM food_diary_entries') !== null);
    r = await api('POST', D, { token: ana, body: { meal_name: 'Sem migração', consumed_at: '2026-10-22T08:00:00-03:00' }, headers: { 'Idempotency-Key': key + '-m0' } });
    check('migração', 'Registro criado sem a migração continua reconhecido depois dela (hash vazio → comparação por campos)', r.status === 200 && r.json?.id === m0, { s: r.status, j: r.json });

    // ------------------------------------------------------------ M2 — leitura do paciente, paginação
    r = await api('GET', D, { token: ana });
    check('leitura', 'GET do paciente: 200, no-store, formato {entries,next_cursor,has_more}', r.status === 200 && Array.isArray(r.json?.entries) && 'next_cursor' in r.json && typeof r.json.has_more === 'boolean' && /no-store/.test(r.headers.get('cache-control') || ''), r.text.slice(0, 200));
    check('leitura', 'Entradas só com campos públicos (sem clinic_id, idempotency_key, photo_path, request_hash) e com consumed_tz', r.json.entries.length > 0 && hasForbidden(r.json).length === 0 && r.json.entries.every((e) => 'consumed_tz' in e && e.patient_id === 1), hasForbidden(r.json));
    check('leitura', 'A paciente só vê as próprias entradas', !r.text.includes('Do Bruno') && r.json.entries.every((e) => e.patient_id === 1));
    r = await apiStrict('GET', D, { token: carla });
    check('leitura', 'Paciente de outra clínica não vê nada da clínica 1', r.status === 200 && r.json.entries.length === 0);
    fx('seed-many', 2, 1, 120);
    fx('seed-many', 1, 1, 33);
    r = await api('GET', D, { token: bruno });
    check('paginação', 'Sem limit: 30 por página, has_more e next_cursor', r.json?.entries?.length === 30 && r.json.has_more === true && typeof r.json.next_cursor === 'string' && r.json.next_cursor.length > 0, { n: r.json?.entries?.length });
    r = await api('GET', D + '?limit=1000', { token: bruno });
    check('paginação', 'limit=1000 é limitado a 100', r.status === 200 && r.json.entries.length === 100 && r.json.has_more === true, { s: r.status, n: r.json?.entries?.length });
    r = await api('GET', D + '?limit=10', { token: bruno });
    check('paginação', 'limit=10 → 10 entradas', r.json?.entries?.length === 10);
    const all = []; let cursor = null; let pages = 0;
    do {
      r = await api('GET', D + '?limit=7' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : ''), { token: bruno });
      all.push(...r.json.entries.map((e) => e.id)); cursor = r.json.has_more ? r.json.next_cursor : null; pages++;
    } while (cursor && pages < 40);
    const expected = sql('SELECT id FROM food_diary_entries WHERE patient_id = 2 ORDER BY consumed_at DESC, id DESC').map((x) => Number(x.id));
    check('paginação', 'Percorrendo o cursor com limit=7: todas as entradas, sem repetir nem pular (inclui horários empatados), em ordem decrescente', JSON.stringify(all) === JSON.stringify(expected) && new Set(all).size === all.length, { got: all.length, exp: expected.length, pages });
    for (const [label, q] of [['limit=0', 'limit=0'], ['limit=abc', 'limit=abc'], ['cursor inválido', 'cursor=@@@lixo'], ['from inválido', 'from=2026-13-45'], ['to inválido', 'to=ontem']]) {
      r = await api('GET', D + '?' + q, { token: ana });
      check('paginação', `${label} → 422`, r.status === 422 && clean(r), { s: r.status, t: r.text.slice(0, 160) });
    }
    r = await api('GET', D + '?from=2026-09-01&to=2026-09-01', { token: bruno });
    const dayRows = Number(one("SELECT COUNT(*) FROM food_diary_entries WHERE patient_id = 2 AND consumed_at >= '2026-09-01 00:00:00' AND consumed_at < '2026-09-02 00:00:00'"));
    check('paginação', 'Filtro from/to por dia (inclusive)', r.status === 200 && r.json.entries.length === Math.min(30, dayRows) && r.json.entries.every((e) => e.consumed_at.startsWith('2026-09-01')), { dayRows, n: r.json?.entries?.length });

    // ------------------------------------------------------------ A4 — visão do profissional
    r = await api('GET', '/api/v1/professional/food-diaries');
    check('profissional', 'Sem token → 401', r.status === 401 && clean(r));
    r = await api('GET', P, { token: ana });
    check('profissional', 'Token de paciente na rota do profissional → 401/403', [401, 403].includes(r.status), r.status);
    r = await api('GET', P, { token: nutri1 });
    check('profissional', 'Lista: {diaries,next_cursor,has_more,pending_total} sem patient_email, clinic_id, idempotency_key, photo_path', r.status === 200 && Array.isArray(r.json?.diaries) && 'next_cursor' in r.json && 'has_more' in r.json && typeof r.json.pending_total === 'number' && hasForbidden(r.json).length === 0, { s: r.status, f: hasForbidden(r.json), keys: Object.keys(r.json || {}) });
    check('profissional', 'Itens trazem patient_id e patient_name', r.json.diaries.every((d) => d.patient_id > 0 && typeof d.patient_name === 'string' && d.patient_name.length > 0));
    check('profissional', 'Profissional da clínica 1 não vê pacientes da clínica 2', !r.text.includes('Carla'));
    r = await api('GET', P + '?patient_id=1', { token: nutri1 });
    check('profissional', '?patient_id=1 devolve só as entradas da Ana', r.status === 200 && r.json.diaries.length > 0 && r.json.diaries.every((d) => Number(d.patient_id) === 1), { s: r.status, ids: [...new Set((r.json?.diaries || []).map((d) => d.patient_id))] });
    r = await api('GET', P + '?patient_id=2&limit=100', { token: nutri1 });
    check('profissional', '?patient_id=2 devolve só as entradas do Bruno (100 por página)', r.json.diaries.length === 100 && r.json.diaries.every((d) => Number(d.patient_id) === 2));
    await api('POST', D, { token: carla, body: { meal_name: 'Almoço da Carla' } });
    r = await api('GET', P + '?patient_id=3', { token: nutri1 });
    check('profissional', '?patient_id de paciente de outra clínica → 404 (sem revelar que existe)', r.status === 404 && clean(r) && !r.text.includes('Carla'), { s: r.status, t: r.text.slice(0, 160) });
    r = await api('GET', P + '?patient_id=999', { token: nutri1 });
    check('profissional', '?patient_id inexistente → 404', r.status === 404);
    r = await api('GET', P + '?patient_id=abc', { token: nutri1 });
    check('profissional', '?patient_id=abc → 422', r.status === 422);
    r = await api('GET', P + '?patient_id=3', { token: nutri2 });
    check('profissional', 'A clínica 2 vê a Carla', r.status === 200 && r.json.diaries.length === 1 && r.json.diaries[0].meal_name === 'Almoço da Carla');
    const allP = []; cursor = null; pages = 0; let pendingTotalFirst = null;
    do {
      r = await api('GET', P + '?limit=40' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : ''), { token: nutri1 });
      if (pendingTotalFirst === null) pendingTotalFirst = r.json.pending_total;
      allP.push(...r.json.diaries.map((d) => d.id)); cursor = r.json.has_more ? r.json.next_cursor : null; pages++;
    } while (cursor && pages < 30);
    const expP = Number(one('SELECT COUNT(*) FROM food_diary_entries WHERE clinic_id = 1'));
    check('profissional', 'Paginação do profissional percorre todas as entradas da clínica, sem repetir', allP.length === expP && new Set(allP).size === expP, { got: allP.length, exp: expP, pages });
    const pendSql = Number(one("SELECT COUNT(*) FROM food_diary_entries d JOIN patients p ON p.id = d.patient_id AND p.clinic_id = d.clinic_id WHERE d.clinic_id = 1 AND d.feedback_from_professional IS NULL AND p.status <> 'anonymized'"));
    const dash = await api('GET', '/api/v1/professional/dashboard', { token: nutri1 });
    check('profissional', 'Contagem de pendentes do dashboard = pending_total da lista = contagem no banco', dash.status === 200 && dash.json?.pending_diaries_count === pendSql && pendingTotalFirst === pendSql, { dash: dash.json?.pending_diaries_count, list: pendingTotalFirst, sql: pendSql, s: dash.status });
    r = await api('GET', P + '?pending=1&limit=100', { token: nutri1 });
    check('profissional', '?pending=1 devolve só entradas sem feedback', r.status === 200 && r.json.diaries.every((d) => !d.feedback_from_professional) && r.json.pending_total === pendSql);

    // ------------------------------------------------------------ M10 — auditoria da leitura
    const viewed = audit('FOOD_DIARY_VIEWED');
    check('auditoria', 'Leitura do diário pelo profissional gera FOOD_DIARY_VIEWED com usuário e clínica', viewed.length > 0 && viewed.every((a) => Number(a.clinic_id) === 1 || Number(a.clinic_id) === 2) && viewed.some((a) => Number(a.user_id) === 1), viewed.slice(0, 2));
    check('auditoria', 'O registro da leitura de um paciente identifica o paciente (patient_id) e não copia o conteúdo das refeições', viewed.some((a) => /patient_id/.test(a.details || '') || /^\d+$/.test(String(a.resource_id))) && !viewed.some((a) => /Refeição teste|Registro sintético|Pão com ovos/.test(a.details || '')), viewed.slice(0, 3));

    // ------------------------------------------------------------ feedback (B2) e auditoria
    const FB = (id) => `/api/v1/professional/food-diary/${id}/feedback`;
    const target = Number(one(`SELECT id FROM food_diary_entries WHERE patient_id = 1 AND feedback_from_professional IS NULL ORDER BY id LIMIT 1`));
    r = await api('POST', FB(target), { token: nutri1, body: {} });
    check('feedback', 'Feedback sem texto → 422 VALIDATION_ERROR', r.status === 422 && r.json?.code === 'VALIDATION_ERROR' && clean(r), { s: r.status, t: r.text.slice(0, 160) });
    r = await api('POST', FB(target), { token: nutri1, body: { feedback: '    ' } });
    check('feedback', 'Feedback só com espaços → 422', r.status === 422);
    r = await api('POST', FB(target), { token: nutri1, body: { feedback: ['a'] } });
    check('feedback', 'Feedback em lista → 422', r.status === 422 && clean(r));
    r = await api('POST', FB(target), { token: nutri1, body: { feedback: 'x'.repeat(2001) } });
    check('feedback', 'Feedback com 2001 caracteres → 422', r.status === 422 && clean(r));
    r = await api('POST', FB(target), { token: nutri1, body: { feedback: 'á'.repeat(2001) } });
    check('feedback', 'O limite conta caracteres, não bytes (2001 "á" → 422)', r.status === 422);
    check('feedback', 'Nenhuma das recusas gravou feedback', one(`SELECT feedback_from_professional FROM food_diary_entries WHERE id = ${target}`) === null);
    r = await api('POST', FB(Number(one('SELECT id FROM food_diary_entries WHERE clinic_id = 2 LIMIT 1'))), { token: nutri1, body: { feedback: 'Tentativa entre clínicas' } });
    check('feedback', 'Entrada de outra clínica → 404 e nada gravado', r.status === 404 && Number(one("SELECT COUNT(*) FROM food_diary_entries WHERE feedback_from_professional = 'Tentativa entre clínicas'")) === 0);
    r = await api('POST', FB(999999), { token: nutri1, body: { feedback: 'Qualquer' } });
    check('feedback', 'Entrada inexistente → 404', r.status === 404);
    r = await api('POST', FB(target), { token: ana, body: { feedback: 'Eu mesma' } });
    check('feedback', 'Paciente não pode dar feedback (401/403)', [401, 403].includes(r.status));
    r = await api('POST', FB(target), { token: nutri1, body: { feedback: 'á'.repeat(2000) } });
    check('feedback', 'Feedback com exatamente 2000 caracteres → 200 com success, entry_id, feedback, reviewed_at', r.status === 201 || r.status === 200 ? (r.json?.success === true && r.json?.entry_id === target && r.json?.feedback?.length === 2000 && typeof r.json?.reviewed_at === 'string') : false, { s: r.status, t: r.text.slice(0, 160) });
    const rev = one(`SELECT reviewed_at FROM food_diary_entries WHERE id = ${target}`);
    check('feedback', 'reviewed_at é gravado no horário da clínica (±2 min do agora local) e igual ao devolvido', r.json?.reviewed_at === rev && Math.abs(Date.parse(rev.replace(' ', 'T') + '-03:00') - Date.now()) < 120000, { rev, ret: r.json?.reviewed_at, now: localNow() });
    r = await api('POST', FB(target), { token: nutri1, body: { feedback: 'Ótimo, continue assim (TESTE).' } });
    check('feedback', 'Novo feedback na mesma entrada substitui o anterior', r.status < 300 && one(`SELECT feedback_from_professional FROM food_diary_entries WHERE id = ${target}`) === 'Ótimo, continue assim (TESTE).');
    r = await api('GET', D + '?limit=100', { token: ana });
    check('feedback', 'A paciente enxerga o comentário do nutricionista', r.json.entries.some((e) => e.id === target && e.feedback_from_professional === 'Ótimo, continue assim (TESTE).' && e.reviewed_at));
    const given = audit('FOOD_DIARY_FEEDBACK_GIVEN');
    const d0 = JSON.parse(given[0]?.details || '{}'); const d1 = JSON.parse(given[1]?.details || '{}');
    check('auditoria', 'FOOD_DIARY_FEEDBACK_GIVEN gravado nas duas respostas, com patient_id, tamanho e se substituiu o anterior — sem o texto', given.length === 2 && d0.patient_id === 1 && d0.feedback_length === 2000 && d0.replaced_previous === false && d1.replaced_previous === true && !JSON.stringify(given).includes('Ótimo'), given);
    const dash2 = await api('GET', '/api/v1/professional/dashboard', { token: nutri1 });
    check('profissional', 'Depois do feedback o pendente cai em 1 (dashboard e lista)', dash2.json?.pending_diaries_count === pendSql - 1 && (await api('GET', P + '?pending=1', { token: nutri1 })).json?.pending_total === pendSql - 1);

    // ------------------------------------------------------------ M10 — LGPD
    const expo = JSON.parse(fx('export-keys', 1));
    check('LGPD', 'Exportação dos dados da paciente não inclui photo_path, idempotency_key nem request_hash', expo.rows > 0 && !expo.keys.some((k) => ['photo_path', 'idempotency_key', 'request_hash'].includes(k)), expo);
    fx('anonymize', 1);
    const an = sql('SELECT meal_name, description, symptoms, feedback_from_professional, photo_path FROM food_diary_entries WHERE patient_id = 1');
    check('LGPD', 'Anonimização apaga descrição, sintomas, feedback e foto do diário (mantém nome da refeição, horário e fome/saciedade)', an.length > 0 && an.every((x) => x.description === null && x.symptoms === null && x.feedback_from_professional === null && x.photo_path === null) && an.some((x) => x.meal_name), an.slice(0, 2));
    check('LGPD', 'O diário de outro paciente não é afetado', Number(one("SELECT COUNT(*) FROM food_diary_entries WHERE patient_id = 2 AND description IS NOT NULL")) > 0);
    r = await api('GET', P + '?limit=100', { token: nutri1 });
    check('LGPD', 'Paciente anonimizada some da lista do profissional e da contagem de pendentes', !r.json.diaries.some((d) => Number(d.patient_id) === 1) && r.json.pending_total < pendSql - 1);
    const dash3 = await api('GET', '/api/v1/professional/dashboard', { token: nutri1 });
    check('LGPD', 'Dashboard e lista continuam coerentes depois da anonimização', dash3.json?.pending_diaries_count === r.json.pending_total, { d: dash3.json?.pending_diaries_count, l: r.json.pending_total });
    r = await api('GET', D, { token: ana });
    check('LGPD', 'Token da paciente anonimizada deixa de funcionar', r.status === 401);

    // ------------------------------------------------------------ M5 — limite de requisições (30/min, padrão)
    fx('clear-limits');
    const codes = [];
    for (let i = 0; i < 30; i++) codes.push((await apiStrict('POST', D, { token: bruno, body: { meal_name: 'Limite ' + i } })).status);
    r = await apiStrict('POST', D, { token: bruno, body: { meal_name: 'Limite 31' } });
    check('limite', '30 registros por minuto passam; o 31º → 429 RATE_LIMITED com Retry-After', codes.every((c) => c === 201) && r.status === 429 && r.json?.code === 'RATE_LIMITED' && Number(r.headers.get('retry-after')) > 0 && clean(r), { codes: codes.join(','), s: r.status, t: r.text.slice(0, 160) });
    check('limite', 'O registro recusado pelo limite não foi gravado', Number(one("SELECT COUNT(*) FROM food_diary_entries WHERE meal_name = 'Limite 31'")) === 0);
    r = await apiStrict('POST', D, { token: carla, body: { meal_name: 'Carla segue livre' } });
    check('limite', 'O limite é por paciente: outra paciente não é afetada', r.status === 201);
    r = await apiStrict('GET', D, { token: bruno });
    check('limite', 'Leitura (GET) não é limitada', r.status === 200);
    r = await apiStrict('POST', D, { token: bruno, body: { meal_name: 'Limite 31' }, headers: { 'Idempotency-Key': key } });
    check('limite', 'Mesmo bloqueado, um erro de limite não vaza detalhe interno', clean(r));
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    main.proc.kill(); strict.proc.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-132 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
