// Jornada da consulta no navegador real: cadastro → paciente → agenda → sala de consulta →
// plano alimentar → convite do app → paciente vê o plano pela API. Cada passo é conferido no MySQL.
import { test, expect } from '@playwright/test';
import { registerNutritionist, createPatient, dbOne, shot, uid } from '../support/helpers.mjs';

test.describe.configure({ mode: 'serial' });

let nutri; let patient; let appointmentId; let planId; let invitationCode;
const planTitle = `Plano Gate ${uid()}`;

test('nutricionista se cadastra e chega ao painel', async ({ page }, testInfo) => {
  nutri = await registerNutritionist(page, { name: 'Carla Consulta', clinic: 'Consultório Jornada' });
  await shot(page, testInfo, '01-onboarding');
  await page.goto('/dashboard');
  await expect(page.locator('main')).toBeVisible();
  await shot(page, testInfo, '02-dashboard');
});

test('cadastra paciente pelo modal e ele aparece na busca', async ({ page }, testInfo) => {
  await page.context().clearCookies();
  await page.goto('/login');
  await page.fill('input[name=email]', nutri.email);
  await page.fill('input[name=password]', nutri.password);
  await page.locator('form[action="/login"] [type=submit]').click();
  patient = await createPatient(page, { name: 'João da Silva Acentuação' });
  expect(patient.clinicId).toBe(nutri.clinicId);
  await page.goto('/pacientes?q=acentuacao');
  await expect(page.getByText('João da Silva Acentuação')).toBeVisible();
  await shot(page, testInfo, '03-paciente-busca');
});

test('agenda uma consulta para amanhã às 10h', async ({ page }, testInfo) => {
  await loginAgain(page);
  await page.goto('/agenda');
  await page.getByRole('button', { name: '+ Nova Consulta' }).click();
  const form = page.locator('form[action="/agenda"]');
  await form.locator('select[name=patient_id]').selectOption(String(patient.id));
  const when = tomorrowAt(10);
  await form.locator('input[name=scheduled_at]').fill(when);
  await form.locator('select[name=type]').selectOption({ index: 0 });
  await form.locator('[type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
  const row = dbOne('SELECT id, status, scheduled_at FROM appointments WHERE patient_id = ? AND clinic_id = ? ORDER BY id DESC LIMIT 1', [patient.id, nutri.clinicId]);
  expect(row, 'consulta persistida').not.toBeNull();
  expect(row.scheduled_at.slice(0, 16).replace(' ', 'T')).toBe(when);
  appointmentId = Number(row.id);
  await page.goto(`/agenda?view=day&date=${when.slice(0, 10)}`);
  await expect(page.getByRole('cell', { name: /João da Silva Acentuação/ })).toBeVisible();
  await expect(page.getByRole('row', { name: /João da Silva/ }).getByText('Agendada')).toBeVisible();
  await shot(page, testInfo, '04-agenda-dia');
});

test('inicia pela agenda, abre a sala de consulta e conclui o atendimento', async ({ page }, testInfo) => {
  await loginAgain(page);
  await page.goto(`/agenda?view=day&date=${tomorrowAt(10).slice(0, 10)}`);
  await page.getByRole('row', { name: /João da Silva/ }).getByRole('link', { name: 'Iniciar', exact: true }).click();
  await page.waitForURL(new RegExp(`/consulta/${appointmentId}$`));
  expect(dbOne('SELECT status FROM appointments WHERE id = ?', [appointmentId]).status, 'abrir a sala coloca a consulta em andamento').toBe('in_progress');
  await expect(page.getByText('João da Silva Acentuação').first()).toBeVisible();
  await shot(page, testInfo, '05-sala-consulta');
  const conclude = page.locator(`form[action="/consulta/${appointmentId}/concluir"]`);
  await expect(conclude, 'formulário de conclusão presente').toHaveCount(1);
  await conclude.locator('[name=evolution_summary]').fill('Evolução registrada pelo gate de homologação: paciente orientado, retorno em 30 dias.');
  const weight = conclude.locator('[name=weight_kg]');
  if (await weight.count()) await weight.fill('72.4');
  await Promise.all([page.waitForNavigation(), conclude.locator('[type=submit]').first().click()]);
  await expect(page.getByText('Realizada').first()).toBeVisible();
  const row = dbOne('SELECT status FROM appointments WHERE id = ?', [appointmentId]);
  expect(row.status, 'consulta concluída no banco').toBe('completed');
  await shot(page, testInfo, '06-consulta-concluida');
});

test('cria plano alimentar com refeição para o paciente', async ({ page }, testInfo) => {
  await loginAgain(page);
  await page.goto(`/pacientes/${patient.id}`);
  const create = page.locator('form[action="/planos/novo"]').first();
  if (!(await create.isVisible())) {
    await page.getByRole('button', { name: /plano/i }).first().click();
  }
  await create.locator('input[name=title]').fill(planTitle);
  await create.locator('[type=submit]').click();
  await page.waitForURL(/\/planos\/\d+/);
  planId = Number(page.url().match(/\/planos\/(\d+)/)[1]);
  const plan = dbOne('SELECT id, patient_id, clinic_id, title FROM meal_plans WHERE id = ?', [planId]);
  expect(plan).toMatchObject({ title: planTitle });
  expect(Number(plan.patient_id)).toBe(patient.id);
  await shot(page, testInfo, '07-editor-plano');

  const addMeal = page.locator(`form[action="/planos/${planId}/refeicoes"]`).first();
  if (!(await addMeal.isVisible())) await page.getByRole('button', { name: /refei/i }).first().click();
  const nameField = addMeal.locator('input[name=name], input[name=meal_name], input[type=text]').first();
  await nameField.fill('Café da manhã');
  const time = addMeal.locator('input[type=time]');
  if (await time.count()) await time.first().fill('07:30');
  await addMeal.locator('[type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
  const meals = Number(dbOne('SELECT COUNT(*) AS n FROM meal_plan_meals WHERE meal_plan_id = ?', [planId]).n);
  expect(meals, 'refeição persistida').toBeGreaterThan(0);

  // Alimento do catálogo pelo seletor real (busca em /catalogo/alimentos/busca no MySQL).
  await page.getByRole('button', { name: '+ Alimento' }).first().click();
  const dialog = page.locator('#modal-add-item');
  await expect(dialog).toBeVisible();
  await dialog.locator('#item-food-search').fill('arroz integral');
  await expect(dialog.getByText('1 alimento encontrado')).toBeVisible();
  await dialog.getByRole('button', { name: /Arroz integral cozido/ }).or(dialog.getByRole('option', { name: /Arroz integral cozido/ })).filter({ visible: true }).first().click();
  await expect(dialog.locator('#item-food-select')).not.toHaveValue('');
  await dialog.locator('input[name=amount]').fill('120');
  await dialog.locator('select[name=measure_unit]').selectOption({ index: 0 });
  await Promise.all([page.waitForNavigation(), dialog.locator('[type=submit]').click()]);
  const item = dbOne('SELECT i.amount, f.name FROM meal_plan_items i JOIN meal_plan_meals m ON m.id = i.meal_id JOIN foods f ON f.id = i.food_id WHERE m.meal_plan_id = ?', [planId]);
  expect(item, 'alimento prescrito persistido').not.toBeNull();
  expect(item.name).toMatch(/Arroz integral/i);
  await shot(page, testInfo, '08-plano-com-refeicao');

  // Publicação (plano em rascunho não chega ao paciente).
  await page.getByRole('button', { name: /Revisar & Publicar/ }).click();
  const publish = page.locator(`form[action="/planos/${planId}/publicar"]`);
  const override = publish.locator('input[name=allow_allergen_override]');
  if (await override.count()) await override.check();
  await Promise.all([page.waitForNavigation(), publish.getByRole('button', { name: /Confirmar e Publicar/ }).click()]);
  expect(dbOne('SELECT status FROM meal_plans WHERE id = ?', [planId]).status, 'plano publicado').toBe('published');
  await shot(page, testInfo, '08b-plano-publicado');
});

test('gera convite do app pelo perfil do paciente', async ({ page }, testInfo) => {
  await loginAgain(page);
  await page.goto(`/pacientes/${patient.id}`);
  await page.locator(`form[action="/pacientes/${patient.id}/convite-app"] [type=submit]`).click();
  await page.waitForLoadState('domcontentloaded');
  const code = await page.getByLabel('Código do convite').inputValue();
  expect(code, 'código do convite exibido ao nutricionista').toMatch(/^[a-f0-9]{64}$/i);
  invitationCode = code;
  expect(Number(dbOne('SELECT COUNT(*) AS n FROM patient_app_invitations WHERE patient_id = ?', [patient.id]).n), 'convite persistido').toBeGreaterThan(0);
  await shot(page, testInfo, '09-convite-app');
});

test('paciente ativa o app e recebe o plano ativo pela API', async ({ request }) => {
  const done = await request.post('/api/v1/auth/patient-onboarding/complete', {
    data: { invitation_code: invitationCode, name: 'João da Silva Acentuação', email: patient.email, phone: '11988887777', password: 'PacienteForte!2', password_confirmation: 'PacienteForte!2' },
  });
  expect(done.status(), await done.text()).toBe(200);
  const { token } = await done.json();
  const home = await request.get('/api/v1/patient/home', { headers: { Authorization: `Bearer ${token}` } });
  expect(home.status()).toBe(200);
  const body = await home.json();
  expect(body.patient_name).toBe('João da Silva Acentuação');
  expect(JSON.stringify(body.active_plan ?? null), 'plano criado na web chega ao app do paciente').toContain(planTitle);
});

async function loginAgain(page) {
  await page.context().clearCookies();
  await page.goto('/login');
  await page.fill('input[name=email]', nutri.email);
  await page.fill('input[name=password]', nutri.password);
  await page.locator('form[action="/login"] [type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
}

function tomorrowAt(hour) {
  const d = new Date(Date.now() + 24 * 3600 * 1000);
  const fmt = new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' });
  return `${fmt.format(d)}T${String(hour).padStart(2, '0')}:00`;
}
