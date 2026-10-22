// NI2-96 — métricas de adesão com privacidade + check-in diário da paciente: teste real por HTTP.
// public/index.php em `php -S` (APP_ENV=production), banco isolado (SQLite ou MySQL), dados fictícios
// (tests/support/ni2_96_fixture.php), logins reais (paciente pela API; equipe e plataforma pelo formulário com CSRF).
//   node tests/ni2_96_adherence_analytics_real_test.cjs   [RESULTS_JSON=…]
//   DB_CONNECTION=mysql DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… node tests/ni2_96_adherence_analytics_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-96-');
const useMysql = process.env.DB_CONNECTION === 'mysql';
const mysqlDb = 'ni2_96_test_' + Math.random().toString(36).slice(2, 10);
const APP_KEY = 'ni2-96-chave-de-teste-ficticia-0001';
const env = { ...process.env, DB_CONNECTION: useMysql ? 'mysql' : 'sqlite', DB_DATABASE: useMysql ? mysqlDb : join(dir, 'test.sqlite'),
  APP_ENV: 'production', FORCE_HTTPS: 'false', APP_TIMEZONE: 'America/Sao_Paulo', TZ: 'America/Sao_Paulo', MAIL_DRIVER: 'null', APP_KEY, APP_URL: 'http://app.example.test' };
const mysqlAdmin = (sqlText) => execFileSync('php', ['-r', '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec($argv[1]);', sqlText], { env });
const results = [];
function check(crit, name, ok, detail) {
  results.push({ crit, name, ok: !!ok, detail: ok ? null : (detail === undefined ? null : detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 500) : ''}`);
}
const fx = (...args) => execFileSync('php', [join(root, 'tests/support/ni2_96_fixture.php'), ...args.map(String)], { cwd: root, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const sql = (q) => JSON.parse(fx('sql', q));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date());
const metric = (m, clinic = 1) => sql(`SELECT event_count, unique_patients FROM analytics_daily_metrics WHERE metric = '${m}' AND clinic_id = ${clinic} AND metric_date = '${today()}'`)[0] || { event_count: 0, unique_patients: 0 };
const num = (o) => ({ e: Number(o.event_count), u: Number(o.unique_patients) });
const CHECKIN_KEYS = ['checkin_date', 'energy_level', 'hunger_level', 'id', 'meal_adherence_pct', 'notes'];

(async () => {
  if (useMysql) mysqlAdmin(`CREATE DATABASE ${mysqlDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  fx('prepare');
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const api = async (method, path, { token, body } = {}) => {
    const h = { Accept: 'application/json' };
    if (token) h.Authorization = 'Bearer ' + token;
    if (body !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* */ }
    return { status: res.status, text, json };
  };
  const webUser = () => {
    let cookie = '';
    const req = async (method, path, form) => {
      const res = await fetch(B + path, { method, redirect: 'manual', headers: { Cookie: cookie, ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? new URLSearchParams(form).toString() : undefined });
      for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return { status: res.status, location: res.headers.get('location') || '', text: await res.text() };
    };
    const token = async (path) => { const r = await req('GET', path); const m = r.text.match(/name="_csrf_token" value="([^"]+)"/); return m ? m[1] : ''; };
    const login = async (email, password) => { const t = await token('/login'); return req('POST', '/login', { _csrf_token: t, email, password }); };
    return { req, token, login };
  };
  const strip = (h) => h.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ').replace(/&quot;/g, '"').replace(/\s+/g, ' ');
  try {
    const tok = async (email) => (await api('POST', '/api/v1/auth/patient-login', { body: { email, password: 'PacienteTeste@2026' } })).json.token;
    const ana = await tok('ana@example.test'), bruno = await tok('bruno@example.test'), carla = await tok('carla@example.test');
    const extra = [];
    for (const n of ['dora', 'elisa', 'fabi', 'gabi', 'helo']) extra.push(await tok(n + '@example.test'));
    let r;

    // ------------------------------------------------------------------ Check-in diário (rota real, validação, um por dia)
    r = await api('GET', '/api/v1/patient/home', { token: ana });
    check('checkin', 'Resumo do app traz checkin_today = null antes do primeiro check-in', r.status === 200 && 'checkin_today' in r.json && r.json.checkin_today === null, r.json && r.json.checkin_today);
    const homeViewsAna = 1;
    const bad = [
      [{}, 'sem a resposta principal'], [{ meal_adherence_pct: 33 }, '33% (fora da escala)'], [{ meal_adherence_pct: '50' }, 'texto em vez de número'],
      [{ meal_adherence_pct: 50, energy_level: 6 }, 'energia 6'], [{ meal_adherence_pct: 50, hunger_level: 0 }, 'fome 0'],
      [{ meal_adherence_pct: 50, notes: 'x'.repeat(501) }, 'observação com 501 caracteres'], [{ meal_adherence_pct: 50, notes: 12 }, 'observação que não é texto'],
    ];
    const badRes = [];
    for (const [b] of bad) badRes.push((await api('POST', '/api/v1/patient/checkin', { token: ana, body: b })));
    check('checkin', 'Entradas inválidas → 422 VALIDATION_ERROR com mensagem (7 casos)', badRes.every((x) => x.status === 422 && x.json && x.json.code === 'VALIDATION_ERROR' && x.json.error), badRes.map((x) => [x.status, x.json && x.json.error]));
    check('checkin', 'Nada gravado e nada contado nas métricas pelas inválidas', Number(one('SELECT COUNT(*) FROM checkins WHERE patient_id = 1')) === 0 && num(metric('checkin_submitted')).e === 0);
    check('checkin', 'Sem token → 401', (await api('POST', '/api/v1/patient/checkin', { body: { meal_adherence_pct: 50 } })).status === 401);

    r = await api('POST', '/api/v1/patient/checkin', { token: ana, body: { meal_adherence_pct: 75, energy_level: 4, hunger_level: 2, notes: '  Jantar fora, mas segui o resto  ', checkin_date: '2020-01-01', patient_id: 2 } });
    check('checkin', 'Primeiro envio → 201, created, campos fechados, observação aparada', r.status === 201 && r.json.created === true && JSON.stringify(Object.keys(r.json.checkin).sort()) === JSON.stringify(CHECKIN_KEYS)
      && r.json.checkin.meal_adherence_pct === 75 && r.json.checkin.energy_level === 4 && r.json.checkin.notes === 'Jantar fora, mas segui o resto', r.json);
    check('checkin', 'O dia vem do servidor (data enviada pelo cliente é ignorada) e vai para a própria paciente', r.json.checkin.checkin_date === today()
      && Number(one('SELECT COUNT(*) FROM checkins WHERE patient_id = 2')) === 0 && Number(one("SELECT COUNT(*) FROM checkins WHERE checkin_date = '2020-01-01'")) === 0, r.json.checkin);
    const firstId = r.json.checkin.id;
    r = await api('POST', '/api/v1/patient/checkin', { token: ana, body: { meal_adherence_pct: 50 } });
    check('checkin', 'Segundo envio no mesmo dia corrige o primeiro → 200, created=false, mesma linha', r.status === 200 && r.json.created === false && r.json.checkin.id === firstId
      && r.json.checkin.meal_adherence_pct === 50 && r.json.checkin.energy_level === null && r.json.checkin.notes === null
      && Number(one('SELECT COUNT(*) FROM checkins WHERE patient_id = 1')) === 1, r.json);
    r = await api('GET', '/api/v1/patient/checkin/today', { token: ana });
    const home2 = await api('GET', '/api/v1/patient/home', { token: ana });
    check('checkin', 'GET /checkin/today e o resumo do app mostram o check-in corrigido', r.status === 200 && r.json.checkin.meal_adherence_pct === 50 && home2.json.checkin_today && home2.json.checkin_today.id === firstId);
    const homeViewsAna2 = homeViewsAna + 1;
    const audits = sql("SELECT action, user_id, resource_id, details FROM audit_logs WHERE action IN ('PATIENT_CHECKIN_RECORDED','PATIENT_CHECKIN_UPDATED') ORDER BY id");
    check('checkin', 'Auditado (registrado e corrigido), só com ids: sem % de adesão, energia ou observação', audits.length === 2 && audits[0].action === 'PATIENT_CHECKIN_RECORDED' && audits[1].action === 'PATIENT_CHECKIN_UPDATED'
      && audits.every((a) => a.user_id === null && Number(a.resource_id) === firstId && !/adherence|Jantar|energy|notes/.test(a.details || '')), audits);
    r = await api('POST', '/api/v1/patient/checkin', { token: carla, body: { meal_adherence_pct: 100 } });
    check('isolamento', 'Check-in da Carla fica na clínica 2 e não toca no da Ana', r.status === 201 && Number(one(`SELECT clinic_id FROM checkins WHERE id = ${r.json.checkin.id}`)) === 2
      && Number(one(`SELECT meal_adherence_pct FROM checkins WHERE id = ${firstId}`)) === 50);
    const owner = webUser(); await owner.login('nutri1@example.test', 'Equipe@2026');
    const prof = await owner.req('GET', '/pacientes/1');
    check('checkin', 'Perfil 360 da Ana mostra o check-in do app para a nutricionista', prof.status === 200 && /Check-ins recentes[\s\S]*Adesão: 50%/.test(strip(prof.text)), prof.status);

    // ------------------------------------------------------------------ Coleta agregada
    check('coleta', 'Check-in: 2 envios da Ana contam 2 registros e 1 paciente no dia (clínica 1)', JSON.stringify(num(metric('checkin_submitted'))) === JSON.stringify({ e: 2, u: 1 }), metric('checkin_submitted'));
    check('coleta', 'Plano visto: cada abertura do resumo com plano conta; 1 paciente distinta', JSON.stringify(num(metric('plan_viewed'))) === JSON.stringify({ e: homeViewsAna2, u: 1 }), metric('plan_viewed'));
    await api('GET', '/api/v1/patient/home', { token: bruno });
    check('coleta', 'Paciente sem plano publicado (Bruno) abre o app: não conta como plano visto', num(metric('plan_viewed')).u === 1);
    for (const t of [ana, ...extra]) { r = await api('POST', '/api/v1/patient/hydration', { token: t, body: { amount_ml: 250 } }); }
    await api('POST', '/api/v1/patient/hydration', { token: ana, body: { amount_ml: 300 } });
    check('coleta', 'Hidratação: 7 registros de 6 pacientes no dia', JSON.stringify(num(metric('hydration_logged'))) === JSON.stringify({ e: 7, u: 6 }), metric('hydration_logged'));
    await api('POST', '/api/v1/patient/hydration', { token: ana, body: { amount_ml: 99999 } });
    check('coleta', 'Registro recusado (volume inválido, 422) não conta', num(metric('hydration_logged')).e === 7);
    check('coleta', 'Check-in da Carla conta na clínica 2, não na 1', JSON.stringify(num(metric('checkin_submitted', 2))) === JSON.stringify({ e: 1, u: 1 }) && num(metric('checkin_submitted')).e === 2);

    // ------------------------------------------------------------------ Privacidade do que fica guardado
    const cols = (t) => useMysql ? sql(`SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${t}' ORDER BY ORDINAL_POSITION`).map((x) => x.c)
      : sql(`SELECT name AS c FROM pragma_table_info('${t}')`).map((x) => x.c);
    check('privacidade', 'Agregado sem coluna de paciente ou valor (só data, clínica, métrica e contagens)', JSON.stringify(cols('analytics_daily_metrics')) === JSON.stringify(['id', 'metric_date', 'clinic_id', 'metric', 'event_count', 'unique_patients', 'updated_at']), cols('analytics_daily_metrics'));
    const subj = sql('SELECT subject_hash FROM analytics_daily_subjects');
    const crypto = require('node:crypto');
    const naive = new Set();
    for (const c of [1, 2]) for (const p of [1, 2, 3, 11, 12, 13, 14, 15]) { naive.add(crypto.createHash('sha256').update(`${c}:${p}`).digest('hex')); naive.add(crypto.createHmac('sha256', APP_KEY).update(`${c}:${p}`).digest('hex')); }
    check('privacidade', 'Pseudônimo do dia: só hash de 64 hex, que não sai do id nem da APP_KEY', subj.length === 9 && subj.every((s) => /^[0-9a-f]{64}$/.test(s.subject_hash) && !naive.has(s.subject_hash)), subj.length);
    const salt = sql('SELECT metric_date, salt FROM analytics_daily_salts');
    check('privacidade', 'Um sal aleatório por dia (64 hex), diferente da APP_KEY', salt.length === 1 && /^[0-9a-f]{64}$/.test(salt[0].salt) && salt[0].salt !== APP_KEY, salt.length);
    const dump = JSON.stringify(sql('SELECT * FROM analytics_daily_metrics')) + JSON.stringify(sql('SELECT * FROM analytics_daily_subjects'));
    check('privacidade', 'Nenhum nome, e-mail, observação ou volume nas tabelas de métricas', !/Ana|Fict|example\.test|Jantar|250|300/.test(dump));

    // ------------------------------------------------------------------ Falha da coleta não quebra a paciente; chave de desligar
    fx('break-analytics');
    r = await api('POST', '/api/v1/patient/hydration', { token: ana, body: { amount_ml: 200 } });
    const rc = await api('POST', '/api/v1/patient/checkin', { token: ana, body: { meal_adherence_pct: 75 } });
    fx('fix-analytics');
    check('resiliência', 'Com o armazenamento das métricas quebrado, água e check-in da paciente funcionam (200)', r.status === 200 && rc.status === 200 && Number(one('SELECT COUNT(*) FROM hydration_logs WHERE patient_id = 1')) === 3, [r.status, rc.status]);
    const admin = webUser();
    const lg = await admin.login('plataforma@example.test', 'Plataforma@2026');
    const cfgToken = await admin.token('/admin/configuracoes');
    r = await admin.req('POST', '/admin/configuracoes', { _csrf_token: cfgToken, analytics_adherence_enabled: '0' });
    const before = num(metric('hydration_logged'));
    const h = await api('POST', '/api/v1/patient/hydration', { token: extra[0], body: { amount_ml: 250 } });
    const ck = await api('POST', '/api/v1/patient/checkin', { token: extra[0], body: { meal_adherence_pct: 25 } });
    await api('GET', '/api/v1/patient/home', { token: ana });
    check('chave', 'Desligado em Configurações: a paciente segue registrando, e nada é contado', [h.status, ck.status].join() === '200,201' && JSON.stringify(num(metric('hydration_logged'))) === JSON.stringify(before)
      && num(metric('checkin_submitted')).u === 1 && num(metric('plan_viewed')).e === homeViewsAna2, { lg: lg.location, h: h.status, ck: ck.status, before, now: metric('hydration_logged') });
    check('chave', 'Mudança da chave auditada (ADMIN_SETTINGS_UPDATED com analytics_adherence_enabled)', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'ADMIN_SETTINGS_UPDATED' AND details LIKE '%analytics_adherence_enabled%'")) === 1);
    let page = await admin.req('GET', '/admin/metricas-adesao?dias=7');
    check('chave', 'Painel avisa que a coleta está desligada', page.status === 200 && /data-testid="am-disabled"/.test(page.text), page.status);
    r = await admin.req('POST', '/admin/configuracoes', { _csrf_token: await admin.token('/admin/configuracoes'), analytics_adherence_enabled: '1' });
    await api('POST', '/api/v1/patient/hydration', { token: extra[1], body: { amount_ml: 250 } });
    check('chave', 'Religado: volta a contar', num(metric('hydration_logged')).e === before.e + 1);
    // Mais 4 pacientes enviam check-in (para passar do corte mínimo de 5 na clínica 1).
    for (const t of extra.slice(1)) await api('POST', '/api/v1/patient/checkin', { token: t, body: { meal_adherence_pct: 100 } });
    check('coleta', 'Check-in: 5 pacientes distintas na clínica 1 (uma enviou com a coleta desligada e não conta)', num(metric('checkin_submitted')).u === 5, metric('checkin_submitted'));

    // ------------------------------------------------------------------ Painel da plataforma
    page = await admin.req('GET', '/admin/metricas-adesao?dias=7');
    const txt = strip(page.text);
    check('painel', 'Superadmin abre /admin/metricas-adesao (200) com o período de 7 dias', page.status === 200 && /data-testid="am-period"/.test(page.text) && /aria-current="page"[^>]*>Últimos 7 dias|Últimos 7 dias/.test(page.text), page.status);
    const kpi = (m) => strip((page.text.match(new RegExp(`data-testid="am-kpi-${m}"[\\s\\S]*?</div>`)) || [''])[0]);
    check('painel', 'Hidratação e check-in aparecem com números (6 e 6 pacientes no período, a Carla da clínica 2 entra no total)', /6 no período/.test(kpi('hydration_logged')) && /6 no período/.test(kpi('checkin_submitted')) && /2 clínica/.test(kpi('checkin_submitted')), [kpi('hydration_logged'), kpi('checkin_submitted')]);
    check('painel', 'Plano visto (1 paciente) aparece como "menos de 5" — corte mínimo', /menos de 5/.test(kpi('plan_viewed')) && !/\b1 no período/.test(kpi('plan_viewed')), kpi('plan_viewed'));
    const clinTable = strip((page.text.match(/data-testid="am-clinics"[\s\S]*?<\/table>/) || [''])[0]);
    check('painel', 'Por clínica: a clínica 1 (7 pacientes no app) aparece; a 2 (1 paciente) fica fora e é contada à parte', /Pacientes no app/.test(clinTable) && /data-testid="am-clinics-hidden"[^>]*>\s*1 clínica/.test(page.text)
      && Number(one("SELECT COUNT(*) FROM analytics_daily_metrics WHERE clinic_id = 2")) > 0, clinTable.slice(0, 300));
    check('painel', 'Sem nome, e-mail ou valor de paciente na página', !/Ana Fict|Dora|example\.test|Jantar|300 ml|Adesão: /.test(txt));
    check('painel', 'Menu da plataforma mostra "Métricas de adesão" para quem tem a permissão', /href="\/admin\/metricas-adesao"/.test(page.text));
    const sup = webUser(); await sup.login('suporte@example.test', 'Plataforma@2026');
    r = await sup.req('GET', '/admin/metricas-adesao');
    const supDash = await sup.req('GET', '/admin/dashboard');
    check('painel', 'Suporte (sem analytics.read) não acessa: volta ao painel e não vê o menu', r.status === 302 && /\/admin\/dashboard/.test(r.location) && !/href="\/admin\/metricas-adesao"/.test(supDash.text), [r.status, r.location]);
    check('painel', 'Recusa do suporte auditada (ADMIN_ACCESS_DENIED analytics.read)', Number(one("SELECT COUNT(*) FROM audit_logs WHERE action = 'ADMIN_ACCESS_DENIED' AND details LIKE '%analytics.read%'")) >= 1);
    r = await owner.req('GET', '/admin/metricas-adesao');
    check('painel', 'Dona de clínica (não é da plataforma) não acessa', r.status === 403 || (r.status === 302 && !/metricas-adesao/.test(r.location)), [r.status, r.location]);
    r = await webUser().req('GET', '/admin/metricas-adesao');
    check('painel', 'Sem login → /login', r.status === 302 && /\/login/.test(r.location), [r.status, r.location]);

    // ------------------------------------------------------------------ Retenção
    fx('old-aggregate');
    fx('shift-days', 1);
    const pg = JSON.parse(fx('purge'));
    check('retenção', 'Virada do dia: sal e pseudônimos de ontem são apagados; agregados ficam', Number(one('SELECT COUNT(*) FROM analytics_daily_subjects')) === 0 && Number(one('SELECT COUNT(*) FROM analytics_daily_salts')) === 0
      && Number(one('SELECT COUNT(*) FROM analytics_daily_metrics')) > 0 && pg.subjects > 0 && pg.salts === 1, pg);
    check('retenção', 'Agregado com mais de 24 meses é apagado', pg.aggregates === 1 && Number(one("SELECT COUNT(*) FROM analytics_daily_metrics WHERE event_count = 9 AND unique_patients = 9")) === 0, pg);
    page = await admin.req('GET', '/admin/metricas-adesao?dias=7');
    check('retenção', 'Depois da limpeza o painel continua com os números de ontem', /6 no período/.test(strip((page.text.match(/data-testid="am-kpi-hydration_logged"[\s\S]*?<\/div>/) || [''])[0])));
    await api('POST', '/api/v1/patient/hydration', { token: ana, body: { amount_ml: 250 } });
    check('retenção', 'Novo dia ganha sal novo; a Ana conta de novo como paciente distinta hoje', Number(one('SELECT COUNT(*) FROM analytics_daily_salts')) === 1 && JSON.stringify(num(metric('hydration_logged'))) === JSON.stringify({ e: 1, u: 1 }));
    let cronOut = '';
    try { cronOut = execFileSync('php', ['bin/cron_scheduler.php'], { cwd: root, env, encoding: 'utf8', timeout: 120000 }); } catch (e) { cronOut = String(e.stdout || '') + String(e.stderr || ''); }
    check('retenção', 'O agendador (bin/cron_scheduler.php) executa a limpeza das métricas', /analytics_purge/.test(cronOut), cronOut.slice(-400));
  } catch (e) {
    check('erro', 'Execução sem exceção', false, e.stack);
  } finally {
    server.kill();
    if (useMysql) { try { mysqlAdmin(`DROP DATABASE ${mysqlDb}`); } catch { /* */ } }
    rmSync(dir, { recursive: true, force: true });
  }
  const pass = results.filter((x) => x.ok).length;
  console.log(`\nNI2-96 (${useMysql ? 'MySQL' : 'SQLite'}): ${pass} PASS, ${results.length - pass} FAIL`);
  if (process.env.RESULTS_JSON) writeFileSync(process.env.RESULTS_JSON, JSON.stringify({ driver: useMysql ? 'mysql' : 'sqlite', pass, fail: results.length - pass, results }, null, 1));
  if (pass !== results.length) process.exitCode = 1;
})();
