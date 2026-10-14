// Contrato HTTP + regressão de segurança (OTP, RBAC, isolamento de tenant, CSRF, sessão).
// Tudo via public/index.php real contra MySQL real; negativas conferem o banco depois.
import { test, expect, request as pwRequest } from '@playwright/test';
import { db, dbOne, createStaff, arrangeOtp, uid, PASSWORD } from '../support/helpers.mjs';

const BASE = process.env.GATE_BASE_URL || 'http://127.0.0.1:8180';

/** Sessão web real: GET /login → CSRF → POST /login. */
async function webSession(email, password) {
  const ctx = await pwRequest.newContext({ baseURL: BASE });
  const csrf = await csrfFrom(ctx, '/login');
  const res = await ctx.post('/login', { form: { _csrf_token: csrf, email, password }, maxRedirects: 0 });
  expect([302, 303], `login web de ${email}`).toContain(res.status());
  return ctx;
}
async function csrfFrom(ctx, path) {
  const html = await (await ctx.get(path)).text();
  const m = html.match(/name="_csrf_token" value="([^"]+)"/);
  expect(m, `CSRF presente em ${path}`).not.toBeNull();
  return m[1];
}
/** Cadastro real (form /cadastro) → sessão web autenticada. */
async function registerViaHttp(label) {
  const ctx = await pwRequest.newContext({ baseURL: BASE });
  const email = `api.${label}.${uid()}@gate.test`;
  const csrf = await csrfFrom(ctx, '/cadastro');
  const res = await ctx.post('/cadastro', {
    form: { _csrf_token: csrf, name: `Nutri ${label}`, email, password: PASSWORD, clinic_name: `Consultório ${label}`, crn: '23456', crn_region: '3' },
    maxRedirects: 0,
  });
  expect([302, 303]).toContain(res.status());
  const row = dbOne('SELECT u.id, cm.clinic_id FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.email = ?', [email]);
  expect(row).not.toBeNull();
  return { ctx, email, userId: Number(row.id), clinicId: Number(row.clinic_id) };
}
async function createPatientViaHttp(session, name) {
  const html = await (await session.ctx.get('/pacientes')).text();
  const csrf = html.match(/name="_csrf_token" value="([^"]+)"/)[1];
  const sub = html.match(/name="_submission_id" value="([^"]+)"/)[1];
  const email = `pac.${uid()}@gate.test`;
  const res = await session.ctx.post('/pacientes', {
    form: { _csrf_token: csrf, _submission_id: sub, name, email, phone: '11977776666', birth_date: '1988-02-03', gender: 'female', notes: '' },
    maxRedirects: 0,
  });
  expect([302, 303]).toContain(res.status());
  const row = dbOne('SELECT id FROM patients WHERE email = ? AND clinic_id = ?', [email, session.clinicId]);
  expect(row, 'paciente gravado no consultório certo').not.toBeNull();
  return { id: Number(row.id), email, name };
}

test.describe.configure({ mode: 'serial' });

let A; let B; let patientA; let proTokenA; let proTokenB; let patientAuth;

test('contrato público: /up, ajuda de acesso e 404 em JSON', async ({ request }) => {
  const up = await request.get('/up');
  expect(up.status()).toBe(200);
  const body = await up.json();
  expect(body).toMatchObject({ status: 'up', database: 'connected' });

  const help = await request.get('/api/v1/auth/access-help');
  expect(help.status()).toBe(200);
  expect(help.headers()['cache-control']).toContain('no-store');
  expect(await help.json()).toHaveProperty('support_email');

  const missing = await request.get(`/rota-inexistente-${uid()}`);
  expect(missing.status()).toBe(404);
  expect(missing.headers()['content-type']).toContain('application/json');
});

test('sessão web: cookie HttpOnly + SameSite=Lax e POST sem CSRF bloqueado (403)', async () => {
  A = await registerViaHttp('a');
  const ctx = await pwRequest.newContext({ baseURL: BASE });
  const res = await ctx.get('/login');
  const cookie = res.headers()['set-cookie'] || '';
  expect(cookie).toMatch(/HttpOnly/i);
  expect(cookie).toMatch(/SameSite=Lax/i);

  const noCsrf = await ctx.post('/login', { form: { email: A.email, password: PASSWORD }, maxRedirects: 0 });
  expect(noCsrf.status()).toBe(403);

  const before = Number(dbOne('SELECT COUNT(*) AS n FROM patients WHERE clinic_id = ?', [A.clinicId]).n);
  const forged = await A.ctx.post('/pacientes', { form: { name: 'Sem CSRF', email: `x.${uid()}@gate.test` }, maxRedirects: 0 });
  expect(forged.status()).toBe(403);
  expect(Number(dbOne('SELECT COUNT(*) AS n FROM patients WHERE clinic_id = ?', [A.clinicId]).n)).toBe(before);
});

test('API do profissional: login forte, token obrigatório e token inválido recusado', async ({ request }) => {
  patientA = await createPatientViaHttp(A, 'Paciente Isolada A');

  const wrong = await request.post('/api/v1/auth/professional-login', { data: { email: A.email, password: 'senha-errada' } });
  expect(wrong.status()).toBe(401);

  const ok = await request.post('/api/v1/auth/professional-login', { data: { email: A.email, password: PASSWORD } });
  expect(ok.status()).toBe(200);
  const body = await ok.json();
  expect(body.professional).toMatchObject({ clinic_id: A.clinicId, email: A.email });
  proTokenA = body.token;
  expect(proTokenA).toMatch(/^[A-Za-z0-9]{32,}$/);
  const stored = dbOne('SELECT token FROM personal_access_tokens WHERE tokenable_id = ? ORDER BY id DESC LIMIT 1', [A.userId]);
  expect(stored, 'token persistido').not.toBeNull();
  expect(stored.token).not.toBe(proTokenA); // gravado como hash, nunca em texto puro

  const list = await request.get('/api/v1/professional/patients', { headers: { Authorization: `Bearer ${proTokenA}` } });
  expect(list.status()).toBe(200);
  expect(JSON.stringify(await list.json())).toContain('Paciente Isolada A');

  expect((await request.get('/api/v1/professional/patients')).status()).toBe(401);
  expect((await request.get('/api/v1/professional/patients', { headers: { Authorization: 'Bearer token-invalido-123' } })).status()).toBe(401);
});

test('isolamento de tenant: consultório B não lê nem convida paciente de A (API e web)', async ({ request }) => {
  B = await registerViaHttp('b');
  proTokenB = (await (await request.post('/api/v1/auth/professional-login', { data: { email: B.email, password: PASSWORD } })).json()).token;

  const listB = await request.get('/api/v1/professional/patients', { headers: { Authorization: `Bearer ${proTokenB}` } });
  expect(listB.status()).toBe(200);
  expect(JSON.stringify(await listB.json())).not.toContain('Paciente Isolada A');

  const invite = await request.post(`/api/v1/professional/patients/${patientA.id}/app-invitation`, { headers: { Authorization: `Bearer ${proTokenB}` } });
  expect(invite.status()).toBe(403);
  expect(Number(dbOne('SELECT COUNT(*) AS n FROM patient_app_invitations WHERE patient_id = ?', [patientA.id]).n)).toBe(0);

  const page = await B.ctx.get(`/pacientes/${patientA.id}`, { maxRedirects: 0 });
  expect(page.status()).not.toBe(200);
  const followed = await (await B.ctx.get(`/pacientes/${patientA.id}`)).text();
  expect(followed).not.toContain('Paciente Isolada A');
});

test('convite do app: uso único, cadastro do paciente e token próprio', async ({ request }) => {
  const inv = await request.post(`/api/v1/professional/patients/${patientA.id}/app-invitation`, { headers: { Authorization: `Bearer ${proTokenA}` } });
  expect(inv.status()).toBe(200);
  const { invitation_code } = await inv.json();
  expect(invitation_code).toMatch(/^[a-f0-9]{64}$/i);

  const preview = await request.post('/api/v1/auth/patient-onboarding/preview', { data: { invitation_code } });
  expect(preview.status()).toBe(200);

  const payload = { invitation_code, name: 'Paciente Isolada A', email: patientA.email, phone: '11977776666', password: 'PacienteForte!1', password_confirmation: 'PacienteForte!1' };
  const done = await request.post('/api/v1/auth/patient-onboarding/complete', { data: payload });
  expect(done.status(), await done.text()).toBe(200);
  patientAuth = await done.json();
  expect(patientAuth.token).toBeTruthy();

  const reuse = await request.post('/api/v1/auth/patient-onboarding/complete', { data: payload });
  expect(reuse.status()).toBe(422);

  const home = await request.get('/api/v1/patient/home', { headers: { Authorization: `Bearer ${patientAuth.token}` } });
  expect(home.status()).toBe(200);
  expect((await home.json()).patient_name).toBe('Paciente Isolada A');
});

test('login do paciente: identificador sozinho proibido, senha errada e token de paciente sem acesso profissional', async ({ request }) => {
  expect((await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email } })).status()).toBe(401);
  expect((await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, password: 'errada-123' } })).status()).toBe(401);
  const ok = await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, password: 'PacienteForte!1' } });
  expect(ok.status()).toBe(200);
  const token = (await ok.json()).token;

  const asPro = await request.get('/api/v1/professional/patients', { headers: { Authorization: `Bearer ${token}` } });
  expect([401, 403]).toContain(asPro.status());

  const out = await request.post('/api/v1/auth/patient-logout', { headers: { Authorization: `Bearer ${token}` } });
  expect(out.status()).toBe(200);
  expect((await request.get('/api/v1/patient/home', { headers: { Authorization: `Bearer ${token}` } })).status()).toBe(401);
});

test('OTP: formato, código errado, expirado e uso único (consumo atômico)', async ({ request }) => {
  const bad = await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, otp: '12ab' } });
  expect(bad.status()).toBe(400);

  arrangeOtp(patientA.id, '482913', -60);
  expect((await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, otp: '482913' } })).status()).toBe(401);
  expect(dbOne('SELECT otp_code FROM patients WHERE id = ?', [patientA.id]).otp_code, 'OTP expirado é limpo').toBeNull();

  arrangeOtp(patientA.id, '731004', 300);
  expect((await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, otp: '000000' } })).status()).toBe(401);
  const first = await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, otp: '731004' } });
  expect(first.status()).toBe(200);
  expect(dbOne('SELECT otp_code FROM patients WHERE id = ?', [patientA.id]).otp_code, 'OTP consumido').toBeNull();
  expect((await request.post('/api/v1/auth/patient-login', { data: { email: patientA.email, otp: '731004' } })).status()).toBe(401);
});

test('RBAC da plataforma: suporte e auditor não executam ações privilegiadas; nutricionista fora do /admin', async () => {
  const support = createStaff('support_agent');
  const auditor = createStaff('security_auditor');
  const sup = await webSession(support.email, support.password);
  const aud = await webSession(auditor.email, auditor.password);

  expect((await sup.get('/suporte/fila', { maxRedirects: 0 })).status()).toBe(200);

  const supportId = Number(dbOne('SELECT id FROM users WHERE email = ?', [support.email]).id);
  const auditorId = Number(dbOne('SELECT id FROM users WHERE email = ?', [auditor.email]).id);
  const deniedCount = (uid) => Number(dbOne("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'ADMIN_ACCESS_DENIED' AND user_id = ?", [uid]).n);

  // Formulário web: a ação NÃO é executada, volta ao painel (não à tela da ação) e a negação é auditada.
  const statusBefore = dbOne('SELECT status FROM clinics WHERE id = ?', [B.clinicId]).status;
  const csrfSup = await csrfFrom(sup, '/admin/clinicas');
  const suspend = await sup.post(`/admin/clinicas/${B.clinicId}/status`, { form: { _csrf_token: csrfSup, status: 'suspended' }, maxRedirects: 0 });
  expect(suspend.status()).toBe(302);
  expect(suspend.headers()['location']).toBe('/admin/dashboard');
  expect(dbOne('SELECT status FROM clinics WHERE id = ?', [B.clinicId]).status, 'consultório não foi suspenso').toBe(statusBefore);
  expect(deniedCount(supportId), 'negação do suporte auditada').toBeGreaterThan(0);

  // Chamada JSON: 403 explícito.
  const suspendJson = await sup.post(`/admin/clinicas/${B.clinicId}/status`, {
    form: { _csrf_token: csrfSup, status: 'suspended' }, headers: { Accept: 'application/json' }, maxRedirects: 0,
  });
  expect(suspendJson.status()).toBe(403);
  expect(dbOne('SELECT status FROM clinics WHERE id = ?', [B.clinicId]).status).toBe(statusBefore);

  const csrfAud = await csrfFrom(aud, '/admin/usuarios');
  const block = await aud.post(`/admin/usuarios/${B.userId}/status`, { form: { _csrf_token: csrfAud, status: 'suspended' }, headers: { Accept: 'application/json' }, maxRedirects: 0 });
  expect(block.status()).toBe(403);
  expect(dbOne('SELECT status FROM users WHERE id = ?', [B.userId]).status, 'usuário não foi suspenso').toBe('active');
  expect(deniedCount(auditorId), 'negação do auditor auditada').toBeGreaterThan(0);

  // Nutricionista não entra no backoffice.
  const nutriAdmin = await A.ctx.get('/admin/dashboard', { maxRedirects: 0 });
  expect(nutriAdmin.status()).not.toBe(200);
});
