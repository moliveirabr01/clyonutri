// NI2-95 — avisos da paciente: teste real por HTTP + cron real.
// public/index.php em `php -S` (APP_ENV=production), SQLite isolado com dados TESTE (tests/support/ni2_95_fixture.php).
//   node tests/ni2_95_notifications_real_test.cjs
'use strict';
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, readFileSync, mkdirSync, existsSync } = require('node:fs');
const { join, resolve } = require('node:path');
const net = require('node:net');

const root = resolve(process.env.APP_ROOT || join(__dirname, '..'));
const dir = mkdtempSync('/tmp/ni2-95-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: join(dir, 'test.sqlite'), APP_ENV: 'production', FORCE_HTTPS: 'false',
  APP_TIMEZONE: 'America/Sao_Paulo', MAIL_DRIVER: 'log', TZ: 'America/Sao_Paulo', APP_URL: 'https://app.example.test' };
let pass = 0; let fail = 0;
function check(crit, name, ok, detail) {
  ok ? pass++ : fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'} [${crit}] ${name}${!ok && detail !== undefined ? ' → ' + JSON.stringify(detail).slice(0, 400) : ''}`);
}
const php = (args) => execFileSync('php', [join(root, 'tests/support/ni2_95_fixture.php'), ...args], { cwd: root, env, encoding: 'utf8' });
const sql = (q) => JSON.parse(php(['sql', q]));
const one = (q) => { const r = sql(q)[0]; return r ? Object.values(r)[0] : null; };
const LEAK = /(SQLSTATE|PDOException|Stack trace|\.php(:| on line)|Uncaught|Fatal error|\/home\/|\/tmp\/)/i;

(async () => {
  const prep = JSON.parse(php(['prepare']));
  const sock = net.createServer(); await new Promise((r) => sock.listen(0, '127.0.0.1', r)); const port = sock.address().port; await new Promise((r) => sock.close(r));
  const server = spawn('php', ['-d', 'display_errors=0', '-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public'], { cwd: root, env, stdio: 'ignore' });
  const B = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 100; i++) { try { await fetch(B + '/up'); break; } catch { await new Promise((r) => setTimeout(r, 50)); } }
  const call = async (method, path, { token, body, raw } = {}) => {
    const h = { Accept: 'application/json' };
    if (token) h.Authorization = 'Bearer ' + token;
    if (body !== undefined || raw !== undefined) h['Content-Type'] = 'application/json';
    const res = await fetch(B + path, { method, headers: h, body: raw !== undefined ? raw : (body !== undefined ? JSON.stringify(body) : undefined), redirect: 'manual' });
    const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch { /* não JSON */ }
    return { status: res.status, text, json, cache: res.headers.get('cache-control') || '' };
  };
  const login = async (email) => (await call('POST', '/api/v1/auth/patient-login', { body: { email, password: 'PacienteTeste@2026' } })).json.token;
  try {
    const ana = await login('ana95@example.test');
    const bruno = await login('bruno95@example.test');
    const carla = await login('carla95@example.test');
    check('setup', 'Login das três pacientes TESTE', [ana, bruno, carla].every((t) => /^[a-f0-9]{64}$/.test(t || '')));

    // ------------------------------------------------------------ sem token
    for (const [m, p] of [['GET', '/api/v1/patient/notifications'], ['POST', '/api/v1/patient/notifications/1/read'], ['POST', '/api/v1/patient/notifications/read-all'],
      ['GET', '/api/v1/patient/notification-preferences'], ['POST', '/api/v1/patient/notification-preferences']]) {
      const r = await call(m, p, m === 'POST' ? { body: {} } : {});
      check('auth', `${m} ${p} sem token → 401`, r.status === 401 && r.json && r.json.error, r.status);
    }

    // ------------------------------------------------------------ plano publicado + cron real
    let r = await call('GET', '/api/v1/patient/notifications', { token: ana });
    check('caixa', 'Plano publicado já aparece na caixa da Ana (antes do cron)', r.status === 200 && r.json.items.length === 1 && r.json.items[0].event_type === 'meal_plan.published' && r.json.unread_count === 1, r.json);
    check('caixa', 'Resposta da caixa não fica em cache', /no-store/.test(r.cache));
    check('caixa', 'Aviso sem nome, sem plano e sem calorias', r.json.items.every((i) => !/Ana|kcal|Plano TESTE|Arroz/.test(i.title + i.body)));
    check('fila', 'E-mail do plano ainda está na fila (só o cron entrega)', one("SELECT COUNT(*) AS n FROM notification_deliveries WHERE status = 'queued'") >= 4);
    const out = execFileSync('php', ['bin/cron_scheduler.php'], { cwd: root, env, encoding: 'utf8' });
    check('cron', 'Cron real roda e mostra entregas e lembretes', /Fila\s+: OK \(entregas \d+/.test(out) && /Consultas : 4 lembrete/.test(out), out);
    check('cron', 'Depois do cron: e-mails logged (driver de log), push bloqueado, nada na fila', Number(one("SELECT COUNT(*) AS n FROM notification_deliveries WHERE status = 'queued'")) === 0
      && Number(one("SELECT COUNT(*) AS n FROM notification_deliveries WHERE channel = 'email' AND status = 'logged'")) === 6
      && Number(one("SELECT COUNT(*) AS n FROM notification_deliveries WHERE channel = 'push' AND status <> 'blocked'")) === 0, sql('SELECT channel, status, reason FROM notification_deliveries'));
    const out2 = execFileSync('php', ['bin/cron_scheduler.php'], { cwd: root, env, encoding: 'utf8' });
    check('cron', 'Segundo ciclo não duplica lembrete nem reenvia', /Consultas : 0 lembrete/.test(out2) && Number(one('SELECT COUNT(*) AS n FROM notification_events')) === 6, out2);

    r = await call('GET', '/api/v1/patient/notifications', { token: ana });
    check('caixa', 'Ana vê plano + lembrete da consulta, mais novo primeiro', r.json.items.length === 2 && r.json.items[0].event_type === 'appointment.reminder' && r.json.unread_count === 2, r.json);
    const [d, t] = prep.reminder_at.split(' '); const local = `${d.slice(8, 10)}/${d.slice(5, 7)} às ${t.slice(0, 5)}`;
    check('caixa', 'Lembrete usa o horário local da consulta (cron no fuso da aplicação, não em UTC)', r.json.items[0].body.includes(local), [local, r.json.items[0].body]);
    check('caixa', 'Lembrete traz só data e hora', /consulta marcada em \d{2}\/\d{2} às \d{2}:\d{2}/.test(r.json.items[0].body) && !/Nutri TESTE/.test(r.json.items[0].body), r.json.items[0]);
    const anaIds = r.json.items.map((i) => i.id);
    r = await call('GET', '/api/v1/patient/notifications?limit=1', { token: ana });
    check('caixa', 'Paginação: limit=1 devolve o cursor da próxima página', r.json.items.length === 1 && r.json.next_before_id === anaIds[0], r.json);
    r = await call('GET', `/api/v1/patient/notifications?before_id=${anaIds[0]}`, { token: ana });
    check('caixa', 'before_id traz o restante', r.json.items.length === 1 && r.json.items[0].id === anaIds[1] && r.json.next_before_id === null, r.json);
    r = await call('GET', '/api/v1/patient/notifications?limit=abc&before_id=-1', { token: ana });
    check('caixa', 'Parâmetros inválidos são ignorados (não quebram)', r.status === 200 && r.json.items.length === 2);

    // ------------------------------------------------------------ IDOR
    const carlaId = Number(one("SELECT id FROM notification_events WHERE clinic_id = 2 AND recipient_id = 3 LIMIT 1"));
    const brunoId = Number(one("SELECT id FROM notification_events WHERE clinic_id = 1 AND recipient_id = 2 LIMIT 1"));
    for (const [label, id] of [['da Carla (outra clínica)', carlaId], ['do Bruno (mesma clínica)', brunoId], ['inexistente', 999999]]) {
      r = await call('POST', `/api/v1/patient/notifications/${id}/read`, { token: ana });
      check('idor', `Ana marcar aviso ${label} → 404 sem detalhes`, r.status === 404 && r.json.code === 'NOT_FOUND' && !LEAK.test(r.text), r);
    }
    r = await call('POST', '/api/v1/patient/notifications/abc/read', { token: ana });
    check('idor', 'Id não numérico → 404', r.status === 404);
    check('idor', 'Avisos dos outros continuam não lidos', one(`SELECT read_at FROM notification_events WHERE id = ${carlaId}`) === null && one(`SELECT read_at FROM notification_events WHERE id = ${brunoId}`) === null);
    r = await call('GET', '/api/v1/patient/notifications', { token: carla });
    check('idor', 'Carla só vê os avisos dela', r.json.items.length === 2 && !r.json.items.some((i) => anaIds.includes(i.id)), r.json);

    // ------------------------------------------------------------ lido
    r = await call('POST', `/api/v1/patient/notifications/${anaIds[1]}/read`, { token: ana });
    check('lido', 'Marcar um como lido', r.status === 200 && r.json.read === true && r.json.unread_count === 1, r.json);
    r = await call('POST', '/api/v1/patient/notifications/read-all', { token: ana });
    check('lido', 'Marcar todos como lidos', r.status === 200 && r.json.marked === 1 && r.json.unread_count === 0, r.json);
    r = await call('GET', '/api/v1/patient/notifications', { token: bruno });
    check('lido', 'Caixa do Bruno não foi mexida (2 lembretes)', r.json.unread_count === 2, r.json);

    // ------------------------------------------------------------ preferências
    r = await call('GET', '/api/v1/patient/notification-preferences', { token: ana });
    const cats = r.status === 200 ? Object.fromEntries(r.json.categories.map((c) => [c.category, c])) : {};
    check('pref', 'Padrão: tudo ligado, silêncio desligado, push indisponível', cats.chat && cats.appointments && cats.plan && Object.values(cats).every((c) => c.email_enabled && c.push_enabled)
      && r.json.quiet_hours.enabled === false && r.json.push_available === false && r.json.timezone === 'America/Sao_Paulo', r.json);
    r = await call('POST', '/api/v1/patient/notification-preferences', { token: ana, body: { categories: { appointments: { email_enabled: false } }, quiet_hours: { enabled: true, start: '21:30', end: '07:00' } } });
    const c2 = r.status === 200 ? Object.fromEntries(r.json.categories.map((c) => [c.category, c])) : {};
    check('pref', 'Salvar: desliga e-mail de lembrete e liga silêncio 21:30–07:00', r.status === 200 && c2.appointments.email_enabled === false && c2.plan.email_enabled === true
      && r.json.quiet_hours.enabled === true && r.json.quiet_hours.start === '21:30', r.json);
    check('pref', 'Mudança fica na auditoria', Number(one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'NOTIFICATION_PREFERENCES_UPDATED' AND resource_id = '1'")) === 1);
    for (const [label, body] of [['categoria desconhecida', { categories: { marketing: { email_enabled: false } } }], ['valor não booleano', { categories: { chat: { email_enabled: 'false' } } }],
      ['hora inválida', { quiet_hours: { start: '7h' } }], ['início = fim', { quiet_hours: { enabled: true, start: '22:00', end: '22:00' } }], ['corpo vazio', {}], ['canal inexistente', { categories: { chat: { sms_enabled: true } } }]]) {
      r = await call('POST', '/api/v1/patient/notification-preferences', { token: ana, body });
      check('pref', `Recusa ${label} com 422`, r.status === 422 && r.json.code === 'VALIDATION_ERROR' && typeof r.json.error === 'string' && !LEAK.test(r.text), r);
    }
    r = await call('POST', '/api/v1/patient/notification-preferences', { token: ana, raw: '{quebrado' });
    check('pref', 'JSON quebrado → 422', r.status === 422, r);
    r = await call('GET', '/api/v1/patient/notification-preferences', { token: carla });
    check('pref', 'Preferência da Ana não vaza para a Carla', r.json.quiet_hours.enabled === false && r.json.categories.every((c) => c.email_enabled), r.json);

    // ------------------------------------------------------------ web: avisos do profissional em Configurações
    let cookie = '';
    const web = async (method, path, form) => {
      const res = await fetch(B + path, { method, redirect: 'manual', headers: { Cookie: cookie, ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }, body: form ? new URLSearchParams(form).toString() : undefined });
      for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) { const kv = c.split(';')[0]; if (kv.startsWith('PHPSESSID=')) cookie = kv; }
      return { status: res.status, location: res.headers.get('location') || '', text: await res.text() };
    };
    const csrf = (html) => (html.match(/name="_csrf_token" value="([^"]+)"/) || [])[1] || '';
    let w = await web('GET', '/login');
    w = await web('POST', '/login', { _csrf_token: csrf(w.text), email: 'nutri95a@example.test', password: 'Equipe@2026' });
    w = await web('GET', '/configuracoes');
    check('web', 'Configurações mostram "Seus avisos" com e-mail do chat ligado por padrão', w.status === 200 && /data-testid="notification-settings"/.test(w.text)
      && /name="notify_chat_email" value="1" checked/.test(w.text), w.status + ' ' + w.location);
    w = await web('POST', '/configuracoes', { _csrf_token: csrf(w.text), section: 'notifications', quiet_enabled: '1', quiet_start: '20:00', quiet_end: '08:00' });
    check('web', 'Desligar e-mail do chat e ligar silêncio 20:00–08:00', w.status === 200 && /Avisos atualizados/.test(w.text)
      && Number(one("SELECT email_enabled FROM notification_preferences WHERE recipient_type = 'professional' AND recipient_id = 1 AND category = 'chat'")) === 0
      && one("SELECT start_time || '-' || end_time || '-' || enabled AS q FROM notification_quiet_hours WHERE recipient_type = 'professional' AND recipient_id = 1") === '20:00-08:00-1', w.status);
    w = await web('POST', '/configuracoes', { _csrf_token: csrf(w.text), section: 'notifications', notify_chat_email: '1', quiet_enabled: '1', quiet_start: '25:00', quiet_end: '08:00' });
    check('web', 'Hora inválida: 422 com mensagem e nada muda', w.status === 422 && /HH:MM/.test(w.text)
      && Number(one("SELECT email_enabled FROM notification_preferences WHERE recipient_type = 'professional' AND recipient_id = 1 AND category = 'chat'")) === 0, w.status);
    w = await web('POST', '/configuracoes', { _csrf_token: 'errado', section: 'notifications', notify_chat_email: '1' });
    check('web', 'Sem CSRF válido não salva', w.status !== 200 || !/Avisos atualizados/.test(w.text));
    check('web', 'Mudança do profissional fica na auditoria', Number(one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'NOTIFICATION_PREFERENCES_UPDATED' AND resource = 'professionals'")) === 1);

    // ------------------------------------------------------------ contrato
    const spec = JSON.parse(readFileSync(join(root, 'docs/api/openapi_v1.json'), 'utf8'));
    const need = ['/patient/notifications', '/patient/notifications/read-all', '/patient/notifications/{id}/read', '/patient/notification-preferences'];
    check('openapi', 'Rotas novas documentadas no OpenAPI', need.every((p) => spec.paths[p]) && spec.paths['/patient/notification-preferences'].post && spec.components.schemas.NotificationInbox);
  } catch (e) {
    check('erro', 'Exceção inesperada', false, String(e && e.stack || e));
  } finally {
    server.kill();
    rmSync(dir, { recursive: true, force: true });
  }
  console.log(`\nResultado NI2-95 (HTTP): ${pass} passaram, ${fail} falharam`);
  process.exit(fail ? 1 : 0);
})();
