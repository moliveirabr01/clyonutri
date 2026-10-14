// Jornada do backoffice no navegador: superadmin suspende e reativa um consultório.
// Prova: status no MySQL, sessão do consultório derrubada, login bloqueado/liberado e trilha de auditoria.
import { test, expect } from '@playwright/test';
import { registerNutritionist, createStaff, dbOne, shot, login } from '../support/helpers.mjs';

test.describe.configure({ mode: 'serial' });

let admin; let nutri;

test('superadmin entra no backoffice e navega pelas áreas', async ({ browser }, testInfo) => {
  const nutriPage = await (await browser.newContext()).newPage();
  nutri = await registerNutritionist(nutriPage, { name: 'Nutri Backoffice', clinic: `Consultório Backoffice ${Date.now()}` });
  await nutriPage.context().close();

  admin = createStaff('superadmin');
  const page = await (await browser.newContext()).newPage();
  await login(page, admin.email, admin.password);
  await expect(page).toHaveURL(/\/admin/);
  for (const [path, name] of [['/admin/dashboard', '10-admin-dashboard'], ['/admin/clinicas', '11-admin-clinicas'], ['/admin/usuarios', '12-admin-usuarios'], ['/admin/servicedesk', '13-admin-servicedesk'], ['/admin/auditoria', '14-admin-auditoria']]) {
    const res = await page.goto(path);
    expect(res.status(), `${path} responde 200`).toBe(200);
    await shot(page, testInfo, name);
  }
  await page.goto('/admin/clinicas');
  await expect(page.getByText(nutri.clinic).first()).toBeVisible();
  await page.context().close();
});

test('suspender consultório derruba a sessão ativa e bloqueia o login', async ({ browser }, testInfo) => {
  const nutriCtx = await browser.newContext();
  const nutriPage = await nutriCtx.newPage();
  await login(nutriPage, nutri.email, nutri.password);
  await nutriPage.goto('/dashboard');
  await expect(nutriPage).toHaveURL(/\/dashboard/);

  const page = await (await browser.newContext()).newPage();
  page.on('dialog', (d) => d.accept());
  await login(page, admin.email, admin.password);
  await page.goto(`/admin/clinicas?q=${encodeURIComponent(nutri.clinic)}`);
  const form = page.locator(`form[action="/admin/clinicas/${nutri.clinicId}/status"]`).filter({ has: page.locator('input[name=status][value=suspended]') });
  await Promise.all([page.waitForNavigation(), form.getByRole('button', { name: /Suspender/ }).click()]);
  expect(dbOne('SELECT status FROM clinics WHERE id = ?', [nutri.clinicId]).status).toBe('suspended');
  await shot(page, testInfo, '15-clinica-suspensa');

  await nutriPage.goto('/dashboard');
  await expect(nutriPage, 'sessão do consultório suspenso é encerrada').toHaveURL(/\/login/);

  await login(nutriPage, nutri.email, nutri.password);
  await nutriPage.goto('/dashboard');
  await expect(nutriPage, 'login de consultório suspenso não chega ao painel').not.toHaveURL(/\/dashboard/);
  await shot(nutriPage, testInfo, '16-login-bloqueado');

  const audit = dbOne("SELECT details FROM audit_logs WHERE action = 'ADMIN_CLINIC_STATUS_CHANGED' AND resource_id = ? ORDER BY id DESC LIMIT 1", [String(nutri.clinicId)]);
  expect(audit, 'suspensão auditada').not.toBeNull();
  expect(audit.details).toContain('suspended');
  await nutriCtx.close();

  // Reativação restaura o acesso.
  const reactivate = page.locator(`form[action="/admin/clinicas/${nutri.clinicId}/status"]`).filter({ has: page.locator('input[name=status][value=active]') });
  await page.goto(`/admin/clinicas?q=${encodeURIComponent(nutri.clinic)}`);
  await Promise.all([page.waitForNavigation(), reactivate.getByRole('button', { name: /Reativar/ }).click()]);
  expect(dbOne('SELECT status FROM clinics WHERE id = ?', [nutri.clinicId]).status).toBe('active');

  const again = await (await browser.newContext()).newPage();
  await login(again, nutri.email, nutri.password);
  await again.goto('/dashboard');
  await expect(again, 'consultório reativado volta a acessar').toHaveURL(/\/dashboard/);
  await page.goto('/admin/auditoria');
  await expect(page.locator('main')).toContainText(/ADMIN_CLINIC_STATUS_CHANGED|status/i);
  await shot(page, testInfo, '17-auditoria-reativacao');
});
