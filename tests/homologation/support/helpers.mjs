// Helpers do gate de homologação (NI2-409). Tudo passa pelo app real (UI/HTTP) e pelo MySQL real.
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import { expect } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(here, '../../..');
export const PHP = process.env.GATE_PHP_BIN || 'php';
export const PASSWORD = 'SenhaForte!2026x';
export const EVIDENCE_DIR = process.env.GATE_EVIDENCE_DIR || path.join(ROOT, 'storage/homologation-local');

let seq = 0;
export const uid = (prefix = 'g') => `${prefix}${Date.now().toString(36)}${(seq++).toString(36)}${Math.random().toString(36).slice(2, 6)}`;

/** Consulta SOMENTE-LEITURA no MySQL do gate (prova de persistência). */
export function db(sql, params = []) {
  const out = execFileSync(PHP, [path.join(ROOT, 'tests/homologation/support/db.php'), sql, JSON.stringify(params)], { encoding: 'utf8' });
  return JSON.parse(out);
}
export const dbOne = (sql, params = []) => db(sql, params)[0] ?? null;

/** Cria usuário de equipe da plataforma pelo script oficial (bin/create_superadmin.php). */
export function createStaff(role = 'superadmin') {
  const email = `${role.replace(/_/g, '.')}.${uid()}@gate.test`;
  const out = execFileSync(PHP, [path.join(ROOT, 'bin/create_superadmin.php'), email, `Equipe ${role}`, `--role=${role}`], { encoding: 'utf8' });
  const password = out.match(/Senha : (\S+)/)?.[1];
  if (!password) throw new Error(`create_superadmin não retornou senha: ${out}`);
  return { email, password, role };
}

export function arrangeOtp(patientId, code, ttlSeconds) {
  execFileSync(PHP, [path.join(ROOT, 'tests/homologation/support/arrange_otp.php'), String(patientId), code, String(ttlSeconds)], { encoding: 'utf8' });
}

/** Screenshot de evidência, nomeado e anexado ao relatório. */
export async function shot(page, testInfo, name) {
  const dir = path.join(EVIDENCE_DIR, 'screenshots');
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, `${testInfo.project.name}__${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  await testInfo.attach(name, { path: file, contentType: 'image/png' });
  return file;
}

/** Cadastro real de nutricionista pela tela /cadastro. */
export async function registerNutritionist(page, { name = 'Nutri Gate', clinic = 'Consultório Gate' } = {}) {
  const email = `nutri.${uid()}@gate.test`;
  await page.goto('/cadastro');
  await page.fill('input[name=name]', name);
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', PASSWORD);
  await page.fill('input[name=clinic_name]', clinic);
  await page.fill('input[name=crn]', String(10000 + Math.floor(Math.random() * 89999)));
  const region = page.locator('[name=crn_region]');
  if (await region.evaluate((e) => e.tagName) === 'SELECT') await region.selectOption({ index: 1 });
  else await region.fill('3');
  await Promise.all([page.waitForURL(/\/(onboarding|dashboard)/), page.locator('form[action="/cadastro"] [type=submit]').click()]);
  const user = dbOne('SELECT u.id, cm.clinic_id FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.email = ?', [email]);
  expect(user, 'cadastro persistido em users/clinic_members').not.toBeNull();
  return { email, password: PASSWORD, name, clinic, userId: Number(user.id), clinicId: Number(user.clinic_id) };
}

export async function login(page, email, password) {
  await page.goto('/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await page.locator('form[action="/login"] [type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
}

export async function logout(page) {
  await page.context().clearCookies();
}

/** Cadastro real de paciente pelo modal de /pacientes. Retorna o id gravado no MySQL. */
export async function createPatient(page, { name, email = `paciente.${uid()}@gate.test`, phone = '11988887777', birth = '1990-05-10' }) {
  await page.goto('/pacientes');
  await page.getByRole('button', { name: 'Cadastrar Paciente' }).click();
  await page.fill('#p_name', name);
  await page.fill('#p_email', email);
  await page.fill('#p_phone', phone);
  await page.fill('#p_birth', birth);
  await page.locator('form[action="/pacientes"][method="POST"] [type=submit], form[action="/pacientes"][method="post"] [type=submit]').click();
  await page.waitForURL(/\/pacientes/);
  const row = dbOne('SELECT id, clinic_id FROM patients WHERE email = ?', [email]);
  expect(row, `paciente ${email} persistido`).not.toBeNull();
  return { id: Number(row.id), clinicId: Number(row.clinic_id), name, email };
}

/** Token de API do profissional pelo endpoint real de login. */
export async function professionalToken(request, email, password) {
  const res = await request.post('/api/v1/auth/professional-login', { data: { email, password } });
  expect(res.status(), await res.text()).toBe(200);
  const body = await res.json();
  return body.token ?? body.access_token;
}

/** Cadastro real via HTTP (form /cadastro com CSRF) → sessão web pronta. */
export async function httpRegister(pwRequest, baseURL, label = 'http') {
  const ctx = await pwRequest.newContext({ baseURL });
  const email = `${label}.${uid()}@gate.test`;
  const csrf = (await (await ctx.get('/cadastro')).text()).match(/name="_csrf_token" value="([^"]+)"/)[1];
  const res = await ctx.post('/cadastro', {
    form: { _csrf_token: csrf, name: `Nutri ${label}`, email, password: PASSWORD, clinic_name: `Consultório ${label}`, crn: '34567', crn_region: '3' },
    maxRedirects: 0,
  });
  expect([302, 303]).toContain(res.status());
  const row = dbOne('SELECT u.id, cm.clinic_id FROM users u JOIN clinic_members cm ON cm.user_id = u.id WHERE u.email = ?', [email]);
  return { ctx, email, password: PASSWORD, userId: Number(row.id), clinicId: Number(row.clinic_id) };
}

/** Cadastro real de paciente via HTTP (form /pacientes com CSRF e _submission_id). */
export async function httpCreatePatient(session, name) {
  const html = await (await session.ctx.get('/pacientes')).text();
  const csrf = html.match(/name="_csrf_token" value="([^"]+)"/)[1];
  const sub = html.match(/name="_submission_id" value="([^"]+)"/)[1];
  const email = `pac.${uid()}@gate.test`;
  const res = await session.ctx.post('/pacientes', {
    form: { _csrf_token: csrf, _submission_id: sub, name, email, phone: '11966665555', birth_date: '1992-07-08', gender: 'male', notes: '' },
    maxRedirects: 0,
  });
  expect([302, 303]).toContain(res.status());
  return { id: Number(dbOne('SELECT id FROM patients WHERE email = ?', [email]).id), email, name };
}
