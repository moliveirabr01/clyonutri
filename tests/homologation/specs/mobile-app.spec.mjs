// App mobile (build web do Expo) no navegador com viewport de celular, contra a API real.
// Android/iOS nativos NÃO são cobertos aqui: o gate os marca como BLOCKED até haver evidência de dispositivo.
import { test, expect, request as pwRequest } from '@playwright/test';
import { httpRegister, httpCreatePatient, dbOne, shot } from '../support/helpers.mjs';

const API = process.env.GATE_BASE_URL || 'http://127.0.0.1:8180';
test.describe.configure({ mode: 'serial' });

let nutri; let patient; let invitationCode;
const PATIENT_PASSWORD = 'PacienteApp!2026';

test.beforeAll(async () => {
  nutri = await httpRegister(pwRequest, API, 'app');
  patient = await httpCreatePatient(nutri, 'Rafael Mobile Gate');
  const api = await pwRequest.newContext({ baseURL: API });
  const token = (await (await api.post('/api/v1/auth/professional-login', { data: { email: nutri.email, password: nutri.password } })).json()).token;
  const inv = await api.post(`/api/v1/professional/patients/${patient.id}/app-invitation`, { headers: { Authorization: `Bearer ${token}` } });
  expect(inv.status()).toBe(200);
  invitationCode = (await inv.json()).invitation_code;
});

test('paciente ativa o primeiro acesso com o convite pelo app', async ({ page }, testInfo) => {
  await page.goto('/');
  await shot(page, testInfo, '30-app-login');
  await page.getByText('Primeiro acesso · Tenho um convite').click();
  await page.getByLabel('Código do convite').fill(invitationCode);
  await page.getByRole('button', { name: 'Conferir convite' }).click();
  await page.getByLabel('Nome completo').fill('Rafael Mobile Gate');
  await page.getByLabel('E-mail para acessar o app').fill(patient.email);
  await page.getByLabel('Nova senha do paciente').fill(PATIENT_PASSWORD);
  await page.getByLabel('Confirmação da senha').fill(PATIENT_PASSWORD);
  await shot(page, testInfo, '31-app-primeiro-acesso');
  await page.getByRole('button', { name: 'Ativar meu acesso' }).click();
  await expect(page.getByText(/Rafael/).first()).toBeVisible({ timeout: 20_000 });
  const row = dbOne('SELECT password_hash FROM patients WHERE id = ?', [patient.id]);
  expect(row.password_hash, 'senha do paciente gravada (hash)').toMatch(/^\$2y\$/);
  expect(dbOne('SELECT accepted_at FROM patient_app_invitations WHERE patient_id = ? ORDER BY id DESC LIMIT 1', [patient.id]).accepted_at, 'convite consumido').not.toBeNull();
  await shot(page, testInfo, '32-app-home-paciente');
});

test('registro de água no app persiste no MySQL e o logout revoga o token', async ({ page }, testInfo) => {
  await loginPatient(page);
  await page.getByLabel('Adicionar 250 mililitros de água').click();
  await expect.poll(() => Number(dbOne('SELECT COALESCE(SUM(amount_ml),0) AS ml FROM hydration_logs WHERE patient_id = ?', [patient.id]).ml), { message: 'água registrada no banco' }).toBe(250);
  await shot(page, testInfo, '33-app-agua-registrada');

  const activeBefore = Number(dbOne("SELECT COUNT(*) AS n FROM personal_access_tokens WHERE tokenable_type = 'patient' AND tokenable_id = ?", [patient.id]).n);
  await page.getByText('Perfil', { exact: true }).last().click();
  await shot(page, testInfo, '33b-app-perfil');
  await page.getByLabel('Encerrar sessão e revogar token').click();
  await expect(page.getByLabel('Entrar como Paciente')).toBeVisible({ timeout: 15_000 });
  await expect.poll(() => Number(dbOne("SELECT COUNT(*) AS n FROM personal_access_tokens WHERE tokenable_type = 'patient' AND tokenable_id = ?", [patient.id]).n)).toBeLessThan(activeBefore);
});

test('nutricionista entra pelo app e vê o paciente', async ({ page }, testInfo) => {
  await page.goto('/');
  await page.getByLabel('Entrar como Nutricionista').click();
  await page.getByLabel('E-mail do profissional').fill(nutri.email);
  await page.getByLabel('Senha do profissional').fill(nutri.password);
  await page.getByLabel('Entrar', { exact: true }).click();
  await expect(page.getByText('Consultas de Hoje')).toBeVisible({ timeout: 20_000 });
  await shot(page, testInfo, '34-app-home-nutricionista');
  testInfo.annotations.push({ type: 'ux-finding', description: 'App do nutricionista: cartão "Agendadas Hoje" fica sem número quando não há consultas (deveria mostrar 0).' });
  await page.getByText('Pacientes', { exact: true }).last().click();
  await expect(page.getByText('Rafael Mobile Gate').first()).toBeVisible({ timeout: 20_000 });
  await shot(page, testInfo, '35-app-pacientes-nutricionista');
});

async function loginPatient(page) {
  await page.goto('/');
  await page.getByLabel('Entrar como Paciente').click();
  await page.getByLabel('E-mail do paciente').fill(patient.email);
  await page.getByLabel('Senha do paciente').fill(PATIENT_PASSWORD);
  await page.getByLabel('Entrar', { exact: true }).click();
  await expect(page.getByLabel('Adicionar 250 mililitros de água')).toBeVisible({ timeout: 20_000 });
}
