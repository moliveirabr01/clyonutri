const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const net = require('node:net');
const assert = require('node:assert/strict');
const root = require('node:path').resolve(__dirname, '..');
const dir = mkdtempSync('/tmp/ni2-281-362-');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: dir + '/test.sqlite', APP_SUPPORT_EMAIL: 'support@example.test', APP_ENV: 'local' };
const sql = q => execFileSync('php', ['-r', '$db=new PDO("sqlite:".getenv("DB_DATABASE"));$db->exec($argv[1]);', q], { env });
let server, keep = false;
(async () => {
  execFileSync('php', ['tests/support/ni2_281_fixture.php', 'prepare'], { cwd: root, env });
  const socket = net.createServer(); await new Promise(r => socket.listen(0, '127.0.0.1', r));
  const port = socket.address().port; await new Promise(r => socket.close(r));
  server = spawn('php', ['-d', 'session.save_path=' + dir, '-S', '127.0.0.1:' + port, '-t', 'public', 'public/index.php'], { cwd: root, env, stdio: 'ignore' });
  const base = 'http://127.0.0.1:' + port;
  for (let i = 0; i < 80; i++) { try { await fetch(base + '/health'); break; } catch {} await new Promise(r => setTimeout(r, 50)); }
  async function api(path, body, { token, cookie, origin = 'http://127.0.0.1:8081', web = true } = {}) {
    const response = await fetch(base + path, { method: body ? 'POST' : 'GET', headers: {
      'Content-Type': 'application/json', ...(web ? { 'X-ClyoNutri-Web': '1', Origin: origin } : {}),
      ...(token ? { Authorization: 'Bearer ' + token } : {}), ...(cookie ? { Cookie: cookie } : {}),
    }, body: body ? JSON.stringify(body) : undefined });
    return { status: response.status, data: await response.json(), headers: response.headers };
  }
  const professional = await api('/api/v1/auth/professional-login', { email: 'plan1@example.test', password: 'PlanTeste@2026' });
  assert.equal(professional.status, 200);
  const profCookie = professional.headers.getSetCookie().find(value => value.startsWith('clyonutri_mobile=')).split(';')[0];
  assert.match(professional.headers.get('set-cookie'), /HttpOnly/i);
  assert.match(professional.headers.get('set-cookie'), /SameSite=Strict/i);
  assert.match(professional.headers.get('set-cookie'), /path=\/api\/v1/i);
  assert.equal(professional.headers.get('access-control-allow-origin'), 'http://127.0.0.1:8081');
  assert.equal(professional.headers.get('access-control-allow-credentials'), 'true');
  const profRestore = await api('/api/v1/auth/session', null, { cookie: profCookie });
  assert.equal(profRestore.status, 200, JSON.stringify(profRestore)); assert.equal(profRestore.data.professional.id, 1); assert.equal(profRestore.data.token, '');
  const invitation = await api('/api/v1/professional/patients/1/app-invitation', {}, { token: professional.data.token });
  const patient = await api('/api/v1/auth/patient-onboarding/complete', { invitation_code: invitation.data.invitation_code, name: 'Paciente UX fictício', email: 'ux362@example.test', phone: '21911112222', password: 'AppTeste@2026', password_confirmation: 'AppTeste@2026' });
  assert.equal(patient.status, 200);
  let cookie = patient.headers.getSetCookie().find(value => value.startsWith('clyonutri_mobile=')).split(';')[0];
  const restored = await api('/api/v1/auth/session', null, { cookie });
  assert.equal(restored.status, 200); assert.equal(restored.data.patient.id, 1); assert.equal(restored.data.token, '');
  assert.equal(restored.headers.get('cache-control'), 'no-store');
  sql("UPDATE meal_plans SET status='published',version=2,published_at='2026-10-10 09:00:00' WHERE id=1; DELETE FROM meal_plans WHERE id=3; UPDATE meal_plan_meals SET notes='Consumir antes do treino' WHERE meal_plan_id=1;");
  const home = await api('/api/v1/patient/home', null, { cookie });
  assert.equal(home.status, 200); assert.equal(home.data.active_plan.meals[0].notes, 'Consumir antes do treino');
  assert.match(home.data.active_plan.published_at, /T09:00:00-03:00$/); assert.equal(home.data.active_plan.version, 2);
  const record = await api('/api/v1/patient/food-diary', { meal_name: 'Almoço', description: 'Comi diferente: uma tigela de sopa', consumed_at: new Date().toISOString(), idempotency_key: 'ni362-http-write' }, { cookie });
  assert.ok(record.status < 300, JSON.stringify(record));
  const diary = await api('/api/v1/patient/food-diary', null, { cookie });
  assert.ok(diary.data.entries.some(entry => entry.description === 'Comi diferente: uma tigela de sopa'));
  assert.equal((await api('/api/v1/patient/hydration', { amount_ml: 250 }, { cookie, origin: 'https://evil.example' })).status, 403);
  assert.equal((await api('/api/v1/patient/hydration', { amount_ml: 250 }, { cookie, web: false })).status, 403);
  const evil = await api('/api/v1/auth/session', null, { cookie, origin: 'https://evil.example' });
  assert.equal(evil.headers.get('access-control-allow-origin'), null);
  assert.equal((await api('/api/v1/auth/access-help')).data.support_email, 'support@example.test');
  const logout = await api('/api/v1/auth/patient-logout', {}, { cookie });
  assert.equal(logout.status, 200); assert.match(logout.headers.get('set-cookie'), /expires=Thu, 01 Jan 1970/i);
  assert.equal((await api('/api/v1/auth/session', null, { cookie })).status, 401);
  assert.equal((await api('/api/v1/patient/home', null, { token: patient.data.token })).status, 401);
  const login = await api('/api/v1/auth/patient-login', { email: 'ux362@example.test', password: 'AppTeste@2026' });
  cookie = login.headers.getSetCookie().find(value => value.startsWith('clyonutri_mobile=')).split(';')[0];
  sql("UPDATE personal_access_tokens SET expires_at='2000-01-01 00:00:00' WHERE tokenable_type='patient';");
  assert.equal((await api('/api/v1/auth/session', null, { cookie })).status, 401);
  assert.equal((await api('/api/v1/patient/home', null, { cookie })).status, 401);
  const native = await api('/api/v1/auth/patient-login', { email: 'ux362@example.test', password: 'AppTeste@2026' }, { web: false });
  assert.ok(!native.headers.getSetCookie().some(value => value.startsWith('clyonutri_mobile='))); assert.equal((await api('/api/v1/patient/home', null, { token: native.data.token, web: false })).status, 200);
  console.log('PASS web session restore for both profiles, HttpOnly/Strict cookie, CORS allowlist, CSRF rejection, logout revocation, expiry, native bearer, meal notes/publication offset, persisted diary, configured support.');
  if (process.argv.includes('--serve')) {
    execFileSync('php', ['tests/support/ni2_362_mobile_ui_fixture.php'], { cwd: root, env });
    keep = true; writeFileSync(dir + '/ui-context.json', JSON.stringify({ base, database: env.DB_DATABASE, email: 'ux362@example.test', password: 'AppTeste@2026' }));
    console.log(JSON.stringify({ base, dir, email: 'ux362@example.test', password: 'AppTeste@2026' }));
    await new Promise(() => {});
  }
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => { if (!keep) { server?.kill(); rmSync(dir, { recursive: true, force: true }); } });
