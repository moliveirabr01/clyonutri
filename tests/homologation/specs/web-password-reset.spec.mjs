// Recuperação de senha por e-mail, ponta a ponta: tela → SMTP real (TLS + AUTH) → link → nova senha.
// O servidor do gate envia pelo cliente SMTP de produção para um SMTP de teste que grava cada mensagem.
import { test, expect, request as pwRequest } from '@playwright/test';
import crypto from 'node:crypto';
import { httpRegister, dbOne, readMails, shot, uid, PASSWORD } from '../support/helpers.mjs';

const BASE = process.env.GATE_BASE_URL || 'http://127.0.0.1:8180';
const NEW_PASSWORD = 'NovaSenha!2026segura';
test.describe.configure({ mode: 'serial' });

let nutri; let link; let rawToken;

const waitMail = async (to, count = 1) => {
  await expect.poll(() => readMails(to).length, { message: `e-mail para ${to}`, timeout: 15_000 }).toBeGreaterThanOrEqual(count);
  return readMails(to).at(-1);
};

test('pedido de recuperação envia e-mail com link válido; o banco guarda só o hash', async ({ page }, testInfo) => {
  nutri = await httpRegister(pwRequest, BASE, 'reset');
  await page.goto('/recuperar-senha');
  await page.fill('input[name=email]', nutri.email);
  await page.locator('form[action="/recuperar-senha"] [type=submit]').click();
  await expect(page.getByText(/Se o e-mail informado estiver cadastrado/)).toBeVisible();
  await shot(page, testInfo, '40-recuperacao-solicitada');

  const mail = await waitMail(nutri.email);
  expect(mail.subject).toBe('Redefinição de senha — ClyoNutri');
  expect(mail.from).toContain('ClyoNutri');
  expect(mail.headers['reply-to']).toBe('suporte@gate.test');
  link = mail.text.match(/https?:\/\/\S+\/recuperar-senha\/[a-f0-9]{64}/)?.[0];
  expect(link, 'link de redefinição no texto do e-mail').toBeTruthy();
  expect(link.startsWith(`${BASE}/recuperar-senha/`), 'link usa APP_URL (nunca o cabeçalho Host)').toBeTruthy();
  expect(mail.html).toContain(link);
  rawToken = link.split('/').pop();

  const stored = dbOne('SELECT token FROM password_resets WHERE LOWER(email) = LOWER(?)', [nutri.email]);
  expect(stored.token, 'token em texto puro não fica no banco').not.toBe(rawToken);
  expect(stored.token).toBe(crypto.createHash('sha256').update(rawToken).digest('hex'));
  expect(Number(dbOne("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'PASSWORD_RESET_EMAIL_SENT' AND details LIKE ?", [`%${nutri.email}%`]).n), 'envio auditado').toBeGreaterThan(0);

  await page.setContent(mail.html);
  await shot(page, testInfo, '41-email-recebido');
});

test('e-mail inexistente recebe a mesma resposta e nenhuma mensagem é enviada', async ({ page }) => {
  const ghost = `ninguem.${uid()}@gate.test`;
  await page.goto('/recuperar-senha');
  await page.fill('input[name=email]', ghost);
  await page.locator('form[action="/recuperar-senha"] [type=submit]').click();
  await expect(page.getByText(/Se o e-mail informado estiver cadastrado/)).toBeVisible();
  await page.waitForTimeout(2500);
  expect(readMails(ghost), 'nenhum e-mail para conta inexistente').toHaveLength(0);
});

test('o link do e-mail redefine a senha; a antiga deixa de valer; o link não serve duas vezes', async ({ page }, testInfo) => {
  await page.goto(link);
  await page.fill('#password', NEW_PASSWORD);
  await page.fill('#password_confirmation', NEW_PASSWORD);
  await shot(page, testInfo, '42-nova-senha');
  await Promise.all([page.waitForURL(/\/login/), page.locator('form [type=submit]').first().click()]);
  await expect(page.getByText(/senha foi redefinida com sucesso/)).toBeVisible();
  await shot(page, testInfo, '43-senha-redefinida');
  expect(Number(dbOne('SELECT COUNT(*) AS n FROM password_resets WHERE LOWER(email) = LOWER(?)', [nutri.email]).n), 'token consumido').toBe(0);

  const ctx = await pwRequest.newContext({ baseURL: BASE });
  const login = async (pw) => {
    const csrf = (await (await ctx.get('/login')).text()).match(/name="_csrf_token" value="([^"]+)"/)[1];
    return (await ctx.post('/login', { form: { _csrf_token: csrf, email: nutri.email, password: pw }, maxRedirects: 0 })).headers()['location'];
  };
  expect(await login(PASSWORD), 'senha antiga recusada').toMatch(/\/login/);
  expect(await login(NEW_PASSWORD), 'nova senha aceita').toMatch(/\/(dashboard|onboarding)/);

  await page.goto(link);
  await expect(page.locator('body')).toContainText(/inválido|expirado/i);
  await shot(page, testInfo, '44-link-reutilizado');
});
